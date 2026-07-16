<?php

namespace coyshdigital\craftanalytics\rollup;

use coyshdigital\craftanalytics\ingest\Hit;

/**
 * The Pro interactions of a batch, collapsed in memory.
 *
 * Same idea as PageBucket: a thousand clicks on the same outbound link in a
 * day become one row, not a thousand (C2).
 */
final class InteractionBuckets
{
    /** @var array<string,array{siteId: int, date: string, hour: int, name: string, path: string, count: int, value: float}> */
    public array $events = [];

    /** @var array<string,array{siteId: int, date: string, path: string, bucket: int, count: int}> */
    public array $scroll = [];

    /** @var array<string,array{siteId: int, date: string, host: string, url: string, path: string, count: int}> */
    public array $outbound = [];

    /** @var array<string,array{siteId: int, date: string, term: string, count: int, zeroResults: int}> */
    public array $searches = [];

    /** @var array<string,array{siteId: int, date: string, name: string, requests: int}> */
    public array $crawlers = [];

    /**
     * A crawler's requests for a day. Daily grain and no path: the question
     * is "is Googlebot here and how hard", not which URLs it liked.
     */
    public function addCrawler(Hit $hit, string $date): void
    {
        $name = $hit->eventName;

        if ($name === null || $name === '') {
            return;
        }

        $key = $hit->siteId . '|' . $date . '|' . $name;

        $this->crawlers[$key] ??= [
            'siteId' => $hit->siteId,
            'date' => $date,
            'name' => $name,
            'requests' => 0,
        ];

        $this->crawlers[$key]['requests']++;
    }

    public function addEvent(Hit $hit, string $date, int $hour): void
    {
        if ($hit->eventName === null || $hit->eventName === '') {
            return;
        }

        $key = implode('|', [$hit->siteId, $date, $hour, $hit->eventName, $hit->path]);

        $this->events[$key] ??= [
            'siteId' => $hit->siteId,
            'date' => $date,
            'hour' => $hour,
            'name' => $hit->eventName,
            'path' => $hit->path,
            'count' => 0,
            'value' => 0.0,
        ];

        $this->events[$key]['count']++;
        $this->events[$key]['value'] += $hit->eventValue ?? 0.0;
    }

    /**
     * Scroll depth is a property of a pageview, so it arrives on the pageview
     * beacon rather than as an event of its own.
     */
    public function addScroll(Hit $hit, string $date): void
    {
        $bucket = $hit->scrollBucket;

        if ($bucket === null || !in_array($bucket, [25, 50, 75, 100], true)) {
            return;
        }

        $key = implode('|', [$hit->siteId, $date, $hit->path, $bucket]);

        $this->scroll[$key] ??= [
            'siteId' => $hit->siteId,
            'date' => $date,
            'path' => $hit->path,
            'bucket' => $bucket,
            'count' => 0,
        ];

        $this->scroll[$key]['count']++;
    }

    public function addOutbound(Hit $hit, string $date, string $host): void
    {
        $key = implode('|', [$hit->siteId, $date, $host, (string)$hit->target, $hit->path]);

        $this->outbound[$key] ??= [
            'siteId' => $hit->siteId,
            'date' => $date,
            'host' => $host,
            'url' => (string)$hit->target,
            'path' => $hit->path,
            'count' => 0,
        ];

        $this->outbound[$key]['count']++;
    }

    public function addSearch(Hit $hit, string $date, string $term, bool $zeroResults): void
    {
        $key = implode('|', [$hit->siteId, $date, $term]);

        $this->searches[$key] ??= [
            'siteId' => $hit->siteId,
            'date' => $date,
            'term' => $term,
            'count' => 0,
            'zeroResults' => 0,
        ];

        $this->searches[$key]['count']++;

        if ($zeroResults) {
            $this->searches[$key]['zeroResults']++;
        }
    }

    public function isEmpty(): bool
    {
        return $this->events === []
            && $this->scroll === []
            && $this->outbound === []
            && $this->searches === []
            && $this->crawlers === [];
    }
}
