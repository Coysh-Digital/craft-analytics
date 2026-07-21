<?php

namespace coyshdigital\craftanalytics\models;

/**
 * One segment a site's own code has declared it will report.
 *
 * Segments are the aggregate half of the extension API: a key and a value
 * attached to a visit — `plan=pro`, `role=member` — counted like a device or
 * a channel and stored the same way. Nothing here identifies anybody; a
 * segment row is a counter, and the visitor it came from is a hash that stops
 * meaning anything when the salt rotates (C5, C6).
 *
 * Declaring one is not ceremony. It is the allowlist the beacon endpoint
 * checks against — that route is anonymous and forgeable by necessity (see
 * BeaconController), so anything it can write has to have been asked for
 * first. It is also where the report gets its heading.
 */
final class SegmentDefinition
{
    /** Longest a key may be, and the only characters allowed in one. */
    private const KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/';

    /** Longest a value may be before it is junk or an attack. */
    public const MAX_VALUE_LENGTH = 100;

    /**
     * @param string $key how the segment is identified in code and stored
     * @param string|null $label how it is headed in the reports; defaults to
     *                           a humanised key
     * @param string[]|null $values the values this segment may take, or null
     *                              to accept any. Declaring them is worth the
     *                              two minutes: an undeclared segment can
     *                              fragment into as many values as the site's
     *                              code has bugs.
     * @param bool $fromBeacon whether the browser may set this one. Off by
     *                         default, because the beacon is forgeable: a
     *                         segment that decides something important should
     *                         be resolved on the server, where the site's own
     *                         code is the only thing that can set it.
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $label = null,
        public readonly ?array $values = null,
        public readonly bool $fromBeacon = false,
    ) {
    }

    public function getLabel(): string
    {
        return $this->label ?? ucfirst(str_replace(['_', '-'], ' ', $this->key));
    }

    /**
     * Whether this key is one we will accept at all.
     *
     * Keys share the dimension value with their value (`plan:pro`), so a key
     * containing a colon would make the two impossible to tell apart again.
     */
    public static function isValidKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * The value as it should be stored, or null if this segment refuses it.
     *
     * A declared value comes back in its declared casing, matched
     * case-insensitively — so the site can write `Pro` in one template and
     * `pro` in another and still get one row rather than two. An undeclared
     * segment has no such list to match against, so its values are
     * lower-cased instead: same protection, less precision, which is the
     * trade you accept by not declaring them.
     */
    public function normalizeValue(string $value): ?string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
        $value = mb_substr($value, 0, self::MAX_VALUE_LENGTH);

        if ($value === '') {
            return null;
        }

        if ($this->values === null) {
            return mb_strtolower($value);
        }

        foreach ($this->values as $declared) {
            if (strcasecmp($declared, $value) === 0) {
                return $declared;
            }
        }

        return null;
    }
}
