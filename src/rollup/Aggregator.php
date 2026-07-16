<?php

namespace coyshdigital\craftanalytics\rollup;

use coyshdigital\craftanalytics\ingest\Hit;
use Craft;

/**
 * Collapses a batch of hits into per-(site, date, hour, path) buckets.
 *
 * Pure and in-memory: this is where 10,000 spooled hits become a few dozen
 * rows to write.
 */
final class Aggregator
{
    /** @var array<string,PageBucket> */
    private array $buckets = [];

    private \DateTimeZone $timeZone;

    public function __construct(?\DateTimeZone $timeZone = null)
    {
        $this->timeZone = $timeZone ?? new \DateTimeZone(Craft::$app->getTimeZone());
    }

    public function add(Hit $hit): void
    {
        [$date, $hour] = $this->dateAndHour($hit->timestamp);
        $key = PageBucket::key($hit->siteId, $date, $hour, $hit->path);

        $bucket = $this->buckets[$key] ??= new PageBucket(
            siteId: $hit->siteId,
            date: $date,
            hour: $hour,
            path: $hit->path,
            elementId: $hit->elementId,
        );

        $bucket->add($hit->visitorHash);
    }

    /**
     * @return array<string,PageBucket>
     */
    public function buckets(): array
    {
        return $this->buckets;
    }

    public function bucketCount(): int
    {
        return count($this->buckets);
    }

    /**
     * @return array{0: string, 1: int} date (Y-m-d) and hour, in site time
     */
    private function dateAndHour(int $timestamp): array
    {
        $local = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->timeZone);

        return [$local->format('Y-m-d'), (int)$local->format('G')];
    }
}
