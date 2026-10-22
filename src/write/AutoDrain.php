<?php

namespace coyshdigital\craftanalytics\write;

use coyshdigital\craftanalytics\models\Settings;
use Craft;
use yii\caching\CacheInterface;

/**
 * Runs the drain from inside a web request, for installs with no cron.
 *
 * `drain/run` on cron is what turns a spooled hit into a counted one; not
 * every host offers cron, so this rides along on ordinary traffic instead.
 * {@see \coyshdigital\craftanalytics\Plugin::attachAutoDrain()} only calls
 * this after the connection to the visitor is already closed, so it costs
 * that visitor nothing (C1) — it costs a PHP-FPM worker for the duration of
 * the drain, which is why it is throttled to about once a minute and applies
 * at most one slice of the spool per pass rather than a whole backlog inside
 * one request. A real cron entry left in place still wins every race: this
 * only ever fires when the live spool has grown since the last pass, cron's
 * or its own.
 *
 * It used to skip a spool past 2 MB altogether, leaving it "for cron". On a
 * host with no cron - the one this exists for - that was permanent: one
 * traffic spike over the line and nothing was ever drained again, until the
 * spool hit its own ceiling and new hits were dropped. A bounded pass works a
 * backlog off a slice a minute instead.
 *
 * On the `queue` and `direct` drivers there is no spool, but there are still
 * sessions to close: a visit only becomes a session, a bounce, a source and a
 * device once something notices it has gone idle, and only the drain does
 * that. So on those drivers the throttled pass closes idle sessions and
 * nothing else - the part of "no cron needed" that was not true before.
 */
final class AutoDrain
{
    private const CACHE_KEY = 'ca:auto-drain';

    /** How often, at most, one request pays for a drain pass. */
    private const INTERVAL = 60;

    /**
     * Slices applied per pass. One slice is up to Drainer::$chunkHits hits
     * (20,000), a few seconds of a worker at most; the rest of the file waits
     * for the next pass, already claimed and resumable. The slice size itself
     * is never changed here: slice ids derive from it, and a file sliced one
     * way by this pass and another by cron would be counted twice.
     */
    private const MAX_CHUNKS = 1;

    public ?CacheInterface $cache = null;
    public ?Drainer $drainer = null;
    public ?SpoolStatus $status = null;

    public function run(Settings $settings): void
    {
        if (!$settings->autoDrain) {
            return;
        }

        $cache = $this->cache();

        // Best-effort throttle, same posture as RateLimit: if the cache is
        // unavailable this fails open and drains every request rather than
        // never draining at all.
        if ($cache !== null && !$cache->add(self::CACHE_KEY, true, self::INTERVAL)) {
            return;
        }

        // Nothing spooled on the other drivers, but sessions still go idle.
        if ($settings->writeDriver !== Settings::WRITE_DRIVER_SPOOL) {
            $this->drainer()->closeIdle();

            return;
        }

        if ($this->status()->backlogBytes() === 0 && !$this->status()->hasClaimed()) {
            return;
        }

        $drainer = $this->drainer();
        $drainer->maxChunks = self::MAX_CHUNKS;
        $drainer->run();
    }

    private function cache(): ?CacheInterface
    {
        return $this->cache ??= Craft::$app->getCache();
    }

    private function drainer(): Drainer
    {
        return $this->drainer ??= new Drainer();
    }

    private function status(): SpoolStatus
    {
        return $this->status ??= new SpoolStatus();
    }
}
