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
        $delta->views = 1;

        return $delta;
    }

    /**
     * Folds another hit of the same session in. Hits are not guaranteed to
     * arrive in time order (concurrent workers, clock skew), so entry/exit
     * paths follow timestamps rather than arrival.
     */
    public function add(Hit $hit): void
    {
        $this->views++;

        if ($hit->timestamp < $this->firstSeen) {
            $this->firstSeen = $hit->timestamp;
            $this->entryPath = $hit->path;
        }

        if ($hit->timestamp >= $this->lastSeen) {
            $this->lastSeen = $hit->timestamp;
            $this->lastPath = $hit->path;
        }
    }
}
