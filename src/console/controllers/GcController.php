<?php

namespace coyshdigital\craftanalytics\console\controllers;

use coyshdigital\craftanalytics\services\GcService;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Compaction and retention.
 *
 * Runs from Craft's GC too, but retention that only happens when someone
 * opens the CP isn't retention — schedule this:
 *
 *     0 4 * * * php craft craft-analytics/gc/run
 */
class GcController extends Controller
{
    /**
     * Compact hourly rows to daily, enforce retention, and prune orphans.
     */
    public function actionRun(): int
    {
        $result = (new GcService())->run();

        $this->stdout("Garbage collection complete.\n", Console::FG_GREEN);
        $this->stdout(sprintf("  compacted days:       %d\n", $result['compactedDays']));
        $this->stdout(sprintf("  expired rollup rows:  %d\n", $result['expiredRollups']));
        $this->stdout(sprintf("  expired unique rows:  %d\n", $result['expiredMembers']));
        $this->stdout(sprintf("  pruned drain markers: %d\n", $result['prunedDrainLog']));
        $this->stdout(sprintf("  orphaned dimensions:  %d\n", $result['orphanedDimensions']));

        return ExitCode::OK;
    }
}
