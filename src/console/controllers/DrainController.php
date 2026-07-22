<?php

namespace coyshdigital\craftanalytics\console\controllers;

use coyshdigital\craftanalytics\write\Drainer;
use coyshdigital\craftanalytics\write\DrainResult;
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
        $failed = false;

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

            $failed = $this->reportFailures($drainer, $result);

            if ($this->watch) {
                sleep($this->interval);
            }
        } while ($this->watch);

        // Non-zero so cron actually says something. A drain that silently
        // succeeds while dropping batches is how this goes unnoticed for
        // weeks.
        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Retry batches that were quarantined after repeated failures.
     */
    public function actionRetry(): int
    {
        $requeued = (new Drainer())->retryFailed();

        if ($requeued === 0) {
            $this->stdout("No quarantined batches to retry.\n");

            return ExitCode::OK;
        }

        $this->stdout(sprintf("Requeued %d batch(es). Run drain/run to process them.\n", $requeued), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * @return bool whether anything failed
     */
    private function reportFailures(Drainer $drainer, DrainResult $result): bool
    {
        if ($result->failedBatches > 0) {
            $this->stderr(sprintf(
                "%d batch(es) failed. See the logs for the cause.\n",
                $result->failedBatches,
            ), Console::FG_RED);
        }

        if ($result->quarantinedBatches > 0) {
            $this->stderr(sprintf(
                "%d batch(es) were quarantined after repeated failures; their hits are NOT counted.\n",
                $result->quarantinedBatches,
            ), Console::FG_RED);
        }

        // Standing backlog, not just this run's: a batch quarantined last week
        // is still uncounted data sitting on disk.
        $waiting = count($drainer->failedBatches());

        if ($waiting > 0) {
            $this->stderr(sprintf(
                "%d quarantined batch(es) are waiting. Fix the cause, then run craft-analytics/drain/retry.\n",
                $waiting,
            ), Console::FG_RED);
        }

        return $result->failedBatches > 0 || $waiting > 0;
    }
}
