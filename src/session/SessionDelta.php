<?php

namespace coyshdigital\craftanalytics\session;

use coyshdigital\craftanalytics\ingest\Hit;

/**
 * One batch's worth of activity for a single session.
 *
 * The drain groups a batch's hits by session before touching the hot layer:
 * one cache write per session per batch instead of one per pageview, and a
 * unit that can be applied exactly once (see SessionStore::apply()).
 */
final class SessionDelta
{
    public int $views = 0;
    public int $firstSeen;
    public int $lastSeen;
    public string $entryPath;
    public string $lastPath;

    /**
     * Campaign touches in this batch, in order and deduplicated — a visitor
     * reloading a tagged URL is one touch, not five.
     *
     * @var array<int,array<string,string>>
     */
    public array $campaigns = [];

    public string $countryCode = '';
    public string $region = '';

    public function __construct(
        public readonly int $siteId,
        public readonly string $sessionKey,
        public readonly string $visitorHash,
        public readonly string $referrer,
        public readonly string $userAgent,
        Hit $firstHit,
    ) {
        $this->firstSeen = $firstHit->timestamp;
        $this->lastSeen = $firstHit->timestamp;
        $this->entryPath = $firstHit->path;
        $this->lastPath = $firstHit->path;
        $this->countryCode = $firstHit->countryCode;
        $this->region = $firstHit->region;

        if ($firstHit->campaign !== null) {
            $this->campaigns[] = $firstHit->campaign->toArray();
        }
    }

    public static function fromHit(Hit $hit): self
    {
        $delta = new self(
            siteId: $hit->siteId,
            sessionKey: $hit->sessionKey,
            visitorHash: $hit->visitorHash,
            referrer: $hit->referrer,
            userAgent: $hit->userAgent,
            firstHit: $hit,
        );
        $delta->views = $hit->countView ? 1 : 0;

        return $delta;
    }

    /**
     * Folds another hit of the same session in. Hits are not guaranteed to
     * arrive in time order (concurrent workers, clock skew), so entry/exit
     * paths follow timestamps rather than arrival.
     */
    public function add(Hit $hit): void
    {
        // A beacon reporting dwell for a view the server already counted
        // keeps the session alive without adding a pageview to it.
        if ($hit->countView) {
            $this->views++;
        }

        if ($hit->timestamp < $this->firstSeen) {
            $this->firstSeen = $hit->timestamp;
            $this->entryPath = $hit->path;
        }

        if ($hit->timestamp >= $this->lastSeen) {
            $this->lastSeen = $hit->timestamp;
            $this->lastPath = $hit->path;
        }

        if ($hit->campaign !== null) {
            $key = $hit->campaign->key();
            $seen = array_map(
                static fn(array $c) => implode('|', [$c['s'] ?? '', $c['m'] ?? '', $c['c'] ?? '', $c['t'] ?? '', $c['o'] ?? '']),
                $this->campaigns,
            );

            // The same campaign seen again mid-session is the same touch.
            if (!in_array($key, $seen, true)) {
                $this->campaigns[] = $hit->campaign->toArray();
            }
        }

        // Geo is resolved once, when they arrive.
        if ($this->countryCode === '' && $hit->countryCode !== '') {
            $this->countryCode = $hit->countryCode;
            $this->region = $hit->region;
        }
    }
}
