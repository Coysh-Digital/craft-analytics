<?php

namespace coyshdigital\craftanalytics\session;

use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use yii\base\Component;
use yii\caching\CacheInterface;

/**
 * The ephemeral hot layer that makes session metrics possible without raw
 * hit rows.
 *
 * Sessions live here, and only here, for the length of the inactivity window.
 * When one goes idle the drain folds it into the rollups and deletes it — so
 * bounce rate, session duration and entry/exit pages are all derivable while
 * the database still only ever sees aggregates (C2, C6).
 *
 * Not TTL-driven: an expired cache key cannot be read, so a session left to
 * expire on its own would take its counters with it. Instead records carry a
 * generous safety TTL and a separate per-site index of last-seen times tells
 * the drain what to close.
 *
 * Single writer: in the default spool mode the drain is the only process that
 * touches this, so no locking is needed. The `direct` write driver writes
 * from web requests and can race under concurrency — it is documented as
 * low-traffic only.
 */
class SessionStore extends Component
{
    private const KEY_PREFIX = 'ca:s:';
    private const INDEX_PREFIX = 'ca:sidx:';

    /** Safety net only — closure is driven by the index, not by expiry. */
    private const RECORD_TTL_PADDING = 86400;

    public ?Settings $settings = null;
    public CacheInterface|null $cache = null;

    /**
     * Site IDs to scan when closing idle sessions; defaults to all sites.
     *
     * @var int[]|null
     */
    public ?array $siteIds = null;

    /**
     * Applies one batch's activity to a session, starting one if needed.
     *
     * Idempotent per batch: re-applying the same batch (as happens when the
     * drain is killed before it commits and the spool file is replayed) is a
     * no-op, so pageviews are never counted twice.
     */
    public function apply(SessionDelta $delta, string $batchId): Session
    {
        $session = $this->get($delta->siteId, $delta->sessionKey);

        if ($session !== null && $session->lastBatch === $batchId) {
            return $session;
        }

        if ($session === null || $this->hasExpired($session, $delta->firstSeen)) {
            $session = new Session(
                siteId: $delta->siteId,
                sessionKey: $delta->sessionKey,
                visitorHash: $delta->visitorHash,
                startedAt: $delta->firstSeen,
                lastSeenAt: $delta->lastSeen,
                pageviews: $delta->views,
                entryPath: $delta->entryPath,
                lastPath: $delta->lastPath,
                referrer: $delta->referrer,
                userAgent: $delta->userAgent,
                campaigns: $delta->campaigns,
                countryCode: $delta->countryCode,
                region: $delta->region,
                goals: $delta->goals,
                maxScroll: $delta->maxScroll,
            );
        } else {
            $session->pageviews += $delta->views;
            $session->startedAt = min($session->startedAt, $delta->firstSeen);

            if ($delta->lastSeen >= $session->lastSeenAt) {
                $session->lastSeenAt = $delta->lastSeen;
                $session->lastPath = $delta->lastPath;
            }

            // Later touches extend the session's campaign list rather than
            // replacing it: the attribution model decides who gets credit,
            // not the order they happened to be written.
            foreach ($delta->campaigns as $campaign) {
                if (!in_array($campaign, $session->campaigns, true)) {
                    $session->campaigns[] = $campaign;
                }
            }

            if ($session->countryCode === '' && $delta->countryCode !== '') {
                $session->countryCode = $delta->countryCode;
                $session->region = $delta->region;
            }

            // A goal converts once per session, so a handle already here
            // stays here once — this union is what enforces that.
            foreach ($delta->goals as $handle) {
                if (!in_array($handle, $session->goals, true)) {
                    $session->goals[] = $handle;
                }
            }

            $session->maxScroll = max($session->maxScroll, $delta->maxScroll);
        }

        $session->lastBatch = $batchId;
        $this->save($session);

        return $session;
    }

    public function get(int $siteId, string $sessionKey): ?Session
    {
        $data = $this->cache()->get(self::KEY_PREFIX . $siteId . ':' . $sessionKey);

        return is_array($data) ? Session::fromArray($data) : null;
    }

    public function save(Session $session): void
    {
        $ttl = $this->settings()->sessionWindow + self::RECORD_TTL_PADDING;

        $this->cache()->set(
            self::KEY_PREFIX . $session->siteId . ':' . $session->sessionKey,
            $session->toArray(),
            $ttl,
        );

        $index = $this->index($session->siteId);
        $index[$session->sessionKey] = $session->lastSeenAt;
        $this->saveIndex($session->siteId, $index);
    }

    public function delete(Session $session): void
    {
        $this->cache()->delete(self::KEY_PREFIX . $session->siteId . ':' . $session->sessionKey);

        $index = $this->index($session->siteId);
        unset($index[$session->sessionKey]);
        $this->saveIndex($session->siteId, $index);
    }

    /**
     * Sessions with no activity inside the inactivity window — the drain's
     * cue to fold them into rollups and drop them.
     *
     * @return Session[]
     */
    public function idleSessions(int $now): array
    {
        $cutoff = $now - $this->settings()->sessionWindow;
        $idle = [];

        foreach ($this->siteIds() as $siteId) {
            foreach ($this->index($siteId) as $sessionKey => $lastSeen) {
                if ($lastSeen > $cutoff) {
                    continue;
                }

                $session = $this->get($siteId, $sessionKey);

                if ($session === null) {
                    // Record gone (evicted or TTL'd) — drop the dangling
                    // index entry rather than leaving it to be rescanned.
                    $index = $this->index($siteId);
                    unset($index[$sessionKey]);
                    $this->saveIndex($siteId, $index);
                    continue;
                }

                $idle[] = $session;
            }
        }

        return $idle;
    }

    /**
     * Sessions currently active — this is the whole of "real-time visitors",
     * at zero database cost.
     *
     * @return Session[]
     */
    public function activeSessions(int $siteId, int $now): array
    {
        $cutoff = $now - $this->settings()->sessionWindow;
        $active = [];

        foreach ($this->index($siteId) as $sessionKey => $lastSeen) {
            if ($lastSeen <= $cutoff) {
                continue;
            }

            $session = $this->get($siteId, $sessionKey);

            if ($session !== null && $session->closedByBatch === null) {
                $active[] = $session;
            }
        }

        return $active;
    }

    private function hasExpired(Session $session, int $now): bool
    {
        return $session->closedByBatch !== null
            || $session->lastSeenAt <= $now - $this->settings()->sessionWindow;
    }

    /**
     * @return array<string,int> sessionKey => lastSeen
     */
    private function index(int $siteId): array
    {
        $index = $this->cache()->get(self::INDEX_PREFIX . $siteId);

        /** @var array<string,int> */
        return is_array($index) ? $index : [];
    }

    /**
     * @param array<string,int> $index
     */
    private function saveIndex(int $siteId, array $index): void
    {
        $this->cache()->set(self::INDEX_PREFIX . $siteId, $index);
    }

    /**
     * @return int[]
     */
    private function siteIds(): array
    {
        return $this->siteIds ??= Craft::$app->getSites()->getAllSiteIds();
    }

    private function settings(): Settings
    {
        return $this->settings ??= Plugin::getInstance()->getSettings();
    }

    private function cache(): CacheInterface
    {
        return $this->cache ??= Craft::$app->getCache()
            ?? throw new \RuntimeException('No cache component is available for the session hot layer.');
    }
}
