<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\events\DefineSegmentsEvent;
use coyshdigital\craftanalytics\events\RegisterSegmentsEvent;
use coyshdigital\craftanalytics\models\SegmentDefinition;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use craft\web\Request;
use yii\base\Component;

/**
 * The segments a site has declared, and the values it puts on them.
 *
 * Two events, and the order matters: a site declares its segments once
 * (`registerSegments`), then supplies values per request (`defineSegments`).
 * Everything a handler returns passes back through the declarations before it
 * goes anywhere, which is what lets the same code serve both the server-side
 * capture path and the public beacon without the beacon becoming a way to
 * write arbitrary dimensions.
 *
 * A site that declares nothing pays for none of this: the registry is empty,
 * the report never appears, and no hit carries a segment.
 */
class SegmentRegistry extends Component
{
    /**
     * @event RegisterSegmentsEvent Declare the segments this site reports.
     */
    public const EVENT_REGISTER_SEGMENTS = 'registerSegments';

    /**
     * @event DefineSegmentsEvent Put values on them, for one request.
     */
    public const EVENT_DEFINE_SEGMENTS = 'defineSegments';

    /**
     * How many segments one hit may carry.
     *
     * Segments multiply: every one of them is another dimension value per
     * session, so a site reporting a dozen would turn each visit into a dozen
     * rollup rows. Five is more than any real report needs and low enough
     * that a loop with a bug in it cannot quietly become a storage problem
     * (C2).
     */
    public const MAX_PER_HIT = 5;

    /** Edition override; defaults to the plugin's own. Set in tests. */
    public ?bool $isPro = null;

    /** @var array<string,SegmentDefinition>|null key => definition */
    private ?array $definitions = null;

    /**
     * Every declared segment, keyed by its key.
     *
     * Lite declares nothing, whatever a module asks for: segments are a Pro
     * rollup like campaigns and geo, gated here rather than in the UI so no
     * route or event can reach past it.
     *
     * @return array<string,SegmentDefinition>
     */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        if (!$this->isPro()) {
            return $this->definitions = [];
        }

        $event = new RegisterSegmentsEvent();
        $this->trigger(self::EVENT_REGISTER_SEGMENTS, $event);

        $definitions = [];

        foreach ($event->segments as $segment) {
            if (!$segment instanceof SegmentDefinition) {
                continue;
            }

            if (!SegmentDefinition::isValidKey($segment->key)) {
                Craft::warning(
                    "craft-analytics: ignoring segment \"{$segment->key}\" — a key must be "
                    . 'lower-case letters, digits, underscores or hyphens, and at most 32 of them.',
                    __METHOD__,
                );

                continue;
            }

            $definitions[$segment->key] = $segment;
        }

        return $this->definitions = $definitions;
    }

    public function getDefinition(string $key): ?SegmentDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Whether this site reports any segments at all.
     */
    public function isEnabled(): bool
    {
        return $this->all() !== [];
    }

    /**
     * The segments for this request.
     *
     * `$fromBeacon` is whatever the browser claimed, and is trusted least: it
     * is merged underneath the site's own answer, and only for segments
     * declared `fromBeacon`. On a cached page it is all there is, which is the
     * entire reason it exists.
     *
     * @param array<string,mixed> $fromBeacon
     * @return array<string,string>
     */
    public function resolve(Request $request, int $siteId, array $fromBeacon = []): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $values = $this->filter($fromBeacon, true);

        $event = new DefineSegmentsEvent($request, $siteId);
        $this->trigger(self::EVENT_DEFINE_SEGMENTS, $event);

        // The server's answer wins: it knows who is signed in, and the beacon
        // is a POST anyone can forge.
        return $this->cap(array_merge($values, $this->filter($event->segments, false)));
    }

    /**
     * Keeps only what was declared, in the form it was declared in.
     *
     * @param array<string,mixed> $raw
     * @return array<string,string>
     */
    public function filter(array $raw, bool $viaBeacon): array
    {
        $values = [];

        foreach ($raw as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            $definition = $this->getDefinition($key);

            if ($definition === null || ($viaBeacon && !$definition->fromBeacon)) {
                continue;
            }

            $normalized = $definition->normalizeValue((string)$value);

            if ($normalized !== null) {
                $values[$key] = $normalized;
            }
        }

        return $values;
    }

    /**
     * @param array<string,string> $values
     * @return array<string,string>
     */
    private function cap(array $values): array
    {
        if (count($values) <= self::MAX_PER_HIT) {
            return $values;
        }

        // Declaration order, so which ones survive is something the site
        // chose rather than something PHP's hash ordering decided.
        $ordered = [];

        foreach (array_keys($this->all()) as $key) {
            if (isset($values[$key])) {
                $ordered[$key] = $values[$key];
            }
        }

        return array_slice($ordered, 0, self::MAX_PER_HIT, true);
    }

    /**
     * The dimension value a (key, value) pair is stored as.
     *
     * One shared dimension type holds every segment, so the key travels with
     * the value — a key can't contain a colon, so the first one is always the
     * separator.
     */
    public static function dimensionValue(string $key, string $value): string
    {
        return $key . ':' . $value;
    }

    /**
     * Splits a stored dimension value back into its key and value.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitDimensionValue(string $stored): array
    {
        $parts = explode(':', $stored, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    private function isPro(): bool
    {
        return $this->isPro ??= Plugin::getInstance()->is(Plugin::EDITION_PRO);
    }
}
