<?php

namespace coyshdigital\craftanalytics\rollup;

/**
 * Hits for one (site, date, hour, path), collapsed in memory by the drain.
 *
 * This is the shape of C2: a thousand pageviews of the same page in the same
 * hour become one bucket, and later one upserted row — so rows track
 * cardinality × time, never traffic.
 */
final class PageBucket
{
    public int $views = 0;

    /**
     * Distinct visitor hashes seen in this bucket. Handed to the unique
     * counter in phase 3 (sketch or membership); never persisted per-visitor.
     *
     * @var array<string,true>
     */
    public array $visitorHashes = [];

    public function __construct(
        public readonly int $siteId,
        public readonly string $date,
        public readonly int $hour,
        public readonly string $path,
        public readonly ?int $elementId = null,
    ) {
    }

    public static function key(int $siteId, string $date, int $hour, string $path): string
    {
        return $siteId . '|' . $date . '|' . $hour . '|' . $path;
    }

    public function add(string $visitorHash): void
    {
        $this->views++;
        $this->visitorHashes[$visitorHash] = true;
    }

    public function uniques(): int
    {
        return count($this->visitorHashes);
    }
}
