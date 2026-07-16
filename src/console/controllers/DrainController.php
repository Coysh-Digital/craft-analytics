<?php

namespace coyshdigital\craftanalytics\console\controllers;

use coyshdigital\craftanalytics\write\Drainer;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Drains spooled hits into rollups.
 *
 * Designed to run every minute from cron, or continuously under a process
 * supervisor with --watch:
 *
 *     * * * * * php craft craft-analytics/drain/run
 */
class DrainController extends Controller
{
    /** Keep running, draining every --interval seconds. */
    public bool $watch = false;

    /** Seconds between passes in --watch mode. */
    public int $interval = 60;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['watch', 'interval']);
    }

    /**
     * Drain the spool.
     */
    public function actionRun(): int
    {
        $drainer = new Drainer();

        do {
            $result = $drainer->run();

            if ($result->hits > 0 || $result->closedSessions > 0 || $result->skippedBatches > 0) {
                $this->stdout(sprintf(
                    "Drained %d hit(s) from %d batch(es) into %d bucket(s); closed %d session(s).\n",
                    $result->hits,
                    $result->batches,
                    $result->buckets,
                    $result->closedSessions,
                ), Console::FG_GREEN);
            }

            if ($result->skippedBatches > 0) {
                $this->stdout(sprintf(
                    "Skipped %d already-committed batch(es) left by an interrupted run.\n",
                    $result->skippedBatches,
                ), Console::FG_YELLOW);
            }

            if ($result->malformedLines > 0) {
                $this->stderr(sprintf("Discarded %d malformed line(s).\n", $result->malformedLines), Console::FG_YELLOW);
            }

            if ($this->watch) {
                sleep($this->interval);
            }
        } while ($this->watch);

        return ExitCode::OK;
    }
}
