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
 * the drain, which is why it is throttled to about once a minute and skips a
 * spool past a modest size rather than draining a large backlog inside one
 * request. A real cron entry left in place still wins every race: this only
 * ever fires when the live spool has grown since the last pass, cron's or
 * its own.
 */
final class AutoDrain
{
    private const CACHE_KEY = 'ca:auto-drain';

    /** How often, at most, one request pays for a drain pass. */
    private const INTERVAL = 60;

    /**
     * Above this, a backlog needs cron's batching, not one request eating it
     * whole — see the OOM risk noted on {@see Drainer::readHits()}. A few
     * thousand hits is comfortably more than a site with no cron option
     * accumulates between requests.
     */
    private const MAX_BYTES = 2097152;

    public ?CacheInterface $cache = null;
    public ?Drainer $drainer = null;
    public ?SpoolStatus $status = null;

    public function run(Settings $settings): void
    {
        if (!$settings->autoDrain || $settings->writeDriver !== Settings::WRITE_DRIVER_SPOOL) {
            return;
        }

        $cache = $this->cache();

        // Best-effort throttle, same posture as RateLimit: if the cache is
        // unavailable this fails open and drains every request rather than
        // never draining at all.
        if ($cache !== null && !$cache->add(self::CACHE_KEY, true, self::INTERVAL)) {
            return;
        }

        $backlog = $this->status()->backlogBytes();

        if ($backlog === 0) {
            return;
        }

        if ($backlog > self::MAX_BYTES) {
            Craft::warning(
                'craft-analytics: the spool is over ' . self::MAX_BYTES . ' bytes with no cron apparently '
                . 'running the drain; skipping the automatic pass rather than draining a large backlog inside '
                . 'one request. Add craft-analytics/drain/run to cron to clear it.',
                __METHOD__,
            );

            return;
        }

        $this->drainer()->run();
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
