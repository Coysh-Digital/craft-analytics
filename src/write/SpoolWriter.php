<?php

namespace coyshdigital\craftanalytics\write;

use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use yii\base\Component;

/**
 * Default write path: append the hit to a local spool and get out.
 *
 * One `fwrite()` of a few hundred bytes per pageview, versus an upsert per
 * pageview — this is what keeps capture inside the CPU budget (C1) and what
 * lets the drain collapse thousands of hits into a handful of rollup upserts
 * (C2).
 *
 * Concurrency: many FPM workers append to one file. Each hit is written as a
 * single `fwrite` of one line under LOCK_EX, and lines are capped well below
 * any plausible atomic-write limit, so lines never interleave. The drain is
 * the only reader and it renames before reading, so it never races an
 * appender mid-line.
 */
class SpoolWriter extends Component implements WriterInterface
{
    /**
     * Hard ceiling on a single spooled line. A hit that somehow exceeds this
     * (an absurd UA or query string) is dropped rather than risking a
     * torn write.
     */
    public const MAX_LINE_BYTES = 4096;

    public const SPOOL_FILENAME = 'spool.ndjson';

    public ?Settings $settings = null;

    /** Overridable for tests; defaults to @storage/runtime/craft-analytics/spool. */
    public ?string $spoolDir = null;

    public function write(Hit $hit): void
    {
        try {
            $line = $hit->encode() . "\n";

            if (strlen($line) > self::MAX_LINE_BYTES) {
                return;
            }

            $path = $this->spoolPath();

            if ($this->isOverCapacity($path)) {
                return;
            }

            $handle = @fopen($path, 'ab');

            if ($handle === false) {
                return;
            }

            // LOCK_EX + one fwrite: appends from concurrent workers are
            // serialised, so a reader never sees a partial line.
            if (flock($handle, LOCK_EX)) {
                fwrite($handle, $line);
                fflush($handle);
                flock($handle, LOCK_UN);
            }

            fclose($handle);
        } catch (\Throwable $e) {
            // Analytics must never break the page it is measuring.
            Craft::warning('Failed to spool analytics hit: ' . $e->getMessage(), __METHOD__);
        }
    }

    public function spoolDir(): string
    {
        if ($this->spoolDir !== null) {
            return $this->spoolDir;
        }

        $dir = Craft::$app->getPath()->getRuntimePath() . DIRECTORY_SEPARATOR . 'craft-analytics'
            . DIRECTORY_SEPARATOR . 'spool';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $this->spoolDir = $dir;
    }

    public function spoolPath(): string
    {
        return $this->spoolDir() . DIRECTORY_SEPARATOR . self::SPOOL_FILENAME;
    }

    /**
     * Back-pressure: if the drain has stopped running, the spool must not
     * grow until it fills the disk. Past the limit new hits are dropped —
     * losing statistics is survivable, taking the site down is not. The CP
     * utility surfaces this (phase 5).
     */
    private function isOverCapacity(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        clearstatcache(true, $path);
        $size = filesize($path);

        return $size !== false && $size >= $this->settings()->spoolMaxBytes;
    }

    private function settings(): Settings
    {
        return $this->settings ??= Plugin::getInstance()->getSettings();
    }
}
