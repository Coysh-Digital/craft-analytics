<?php

namespace coyshdigital\craftanalytics\models;

/**
 * A campaign touch, from the `utm_*` parameters on a URL.
 *
 * Only `source` is required — a link tagged `?utm_source=newsletter` and
 * nothing else is a perfectly ordinary campaign, and refusing to record it
 * because it lacks a medium would lose real data.
 */
final class Campaign
{
    /**
     * The UTM parameters, plus the click identifiers ad platforms bolt on.
     *
     * All of them are stripped from the recorded path: they describe how
     * somebody arrived, not which page they arrived at. The click ids are not
     * read as campaigns — they identify an ad click, not a source — they are
     * simply not allowed to fragment the pages report.
     */
    public const PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'utm_id', 'utm_source_platform',
        'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'twclid', 'dclid', 'mc_cid', 'mc_eid',
    ];

    /** Longest a UTM value may be before it's junk or an attack. */
    private const MAX_LENGTH = 200;

    public function __construct(
        public readonly string $source,
        public readonly string $medium = '',
        public readonly string $campaign = '',
        public readonly string $term = '',
        public readonly string $content = '',
    ) {
    }

    /**
     * Reads a campaign from a query string, or null if there isn't one.
     */
    public static function fromQueryString(string $queryString): ?self
    {
        if ($queryString === '') {
            return null;
        }

        parse_str($queryString, $params);

        // A URL that was HTML-entity-encoded before being linked - sometimes
        // more than once - arrives with its separators as `&amp;` rather than
        // `&`, so parse_str reads every parameter after the first as
        // `amp;utm_medium`, `amp;amp;utm_campaign` and so on. The path
        // normaliser was hardened for this and this parser was not, so the
        // campaign behind such a link lost everything but its source.
        $decoded = [];
        foreach ($params as $key => $value) {
            $decoded[preg_replace('/^(?:amp;)+/', '', (string)$key)] = $value;
        }
        $params = $decoded;

        $source = self::clean($params['utm_source'] ?? null);

        // No source, no campaign. Everything else is a refinement of it.
        if ($source === '') {
            return null;
        }

        return new self(
            source: $source,
            medium: self::clean($params['utm_medium'] ?? null),
            campaign: self::clean($params['utm_campaign'] ?? null),
            term: self::clean($params['utm_term'] ?? null),
            content: self::clean($params['utm_content'] ?? null),
        );
    }

    /**
     * @return array<string,string>
     */
    public function toArray(): array
    {
        return [
            's' => $this->source,
            'm' => $this->medium,
            'c' => $this->campaign,
            't' => $this->term,
            'o' => $this->content,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $source = (string)($data['s'] ?? '');

        if ($source === '') {
            return null;
        }

        return new self(
            source: $source,
            medium: (string)($data['m'] ?? ''),
            campaign: (string)($data['c'] ?? ''),
            term: (string)($data['t'] ?? ''),
            content: (string)($data['o'] ?? ''),
        );
    }

    /**
     * Identity for deduplicating touches within a session.
     */
    public function key(): string
    {
        return implode('|', [$this->source, $this->medium, $this->campaign, $this->term, $this->content]);
    }

    private static function clean(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        // Lower-cased because "Newsletter" and "newsletter" are the same
        // campaign, and treating them as two is how reports turn to mush.
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';

        return mb_substr($value, 0, self::MAX_LENGTH);
    }
}
