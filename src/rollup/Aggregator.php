<?php

namespace coyshdigital\craftanalytics\rollup;

use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use Craft;

/**
 * Collapses a batch of hits into per-(site, date, hour, path) buckets.
 *
 * Pure and in-memory: this is where 10,000 spooled hits become a few dozen
 * rows to write.
 */
class Aggregator
{
    /** @var array<string,PageBucket> */
    private array $buckets = [];

    public InteractionBuckets $interactions;

    private \DateTimeZone $timeZone;
    private Settings $settings;

    public function __construct(?\DateTimeZone $timeZone = null, ?Settings $settings = null)
    {
        $this->timeZone = $timeZone ?? new \DateTimeZone(Craft::$app->getTimeZone());
        $this->settings = $settings ?? Plugin::getInstance()->getSettings();
        $this->interactions = new InteractionBuckets();
    }

    public function add(Hit $hit): void
    {
        [$date, $hour] = $this->dateAndHour($hit->timestamp);

        // Crawlers are counted apart from everything else and never touch a
        // page, session or unique-visitor figure - the whole point of
        // recording them is that they are not people.
        if ($hit->kind === Hit::KIND_CRAWLER) {
            $this->interactions->addCrawler($hit, $date);

            return;
        }

        // A Pro interaction is not a pageview and must never be counted as
        // one: an outbound click is something that happened *on* a page.
        if (!$hit->isPageview()) {
            $this->addInteraction($hit, $date, $hour);

            return;
        }

        if ($hit->scrollBucket !== null) {
            $this->interactions->addScroll($hit, $date);
        }

        $this->addSearch($hit, $date);

        $key = PageBucket::key($hit->siteId, $date, $hour, $hit->path);

        $bucket = $this->buckets[$key] ??= new PageBucket(
            siteId: $hit->siteId,
            date: $date,
            hour: $hour,
            path: $hit->path,
            elementId: $hit->elementId,
        );

        $bucket->add($hit->visitorHash, $hit->countView, $hit->dwellMs);
    }

    /**
     * A site search is a pageview of the search page with a term in the URL —
     * detectable server-side, so it needs no JavaScript and works for
     * visitors who have it turned off.
     */
    private function addSearch(Hit $hit, string $date): void
    {
        $settings = $this->settings;

        if (!$settings->trackSiteSearch) {
            return;
        }

        $parts = explode('?', $hit->path, 2);

        if (rtrim($parts[0], '/') !== rtrim($settings->siteSearchPath, '/') || !isset($parts[1])) {
            return;
        }

        parse_str($parts[1], $params);
        $term = $params[$settings->siteSearchParam] ?? null;

        if (!is_string($term)) {
            return;
        }

        // Lower-cased and trimmed: "Shoes" and "shoes " are the same search,
        // and treating them as two makes the report useless.
        $term = mb_substr(mb_strtolower(trim($term)), 0, 200);

        if ($term === '') {
            return;
        }

        // Whether the search found anything is something only the site knows;
        // it can report it with craftAnalytics.event('search:zero-results').
        $this->interactions->addSearch($hit, $date, $term, false);
    }

    private function addInteraction(Hit $hit, string $date, int $hour): void
    {
        match ($hit->kind) {
            Hit::KIND_EVENT => $this->interactions->addEvent($hit, $date, $hour),
            Hit::KIND_OUTBOUND, Hit::KIND_DOWNLOAD => $this->addOutbound($hit, $date),
            default => null,
        };
    }

    private function addOutbound(Hit $hit, string $date): void
    {
        $host = $hit->target !== null ? parse_url($hit->target, PHP_URL_HOST) : null;

        if (!is_string($host) || $host === '') {
            return;
        }

        $this->interactions->addOutbound($hit, $date, strtolower($host));
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
