<?php

namespace coyshdigital\craftanalytics\console\controllers;

use coyshdigital\craftanalytics\models\DateRange;
use coyshdigital\craftanalytics\Plugin;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Emails the summary report.
 *
 * Scheduling is yours: put this on the cron you already have.
 *
 *     0 8 * * 1  php craft craft-analytics/report/send --period=7d
 *
 * There is deliberately no scheduler in the plugin. A plugin with its own
 * scheduler is a plugin with a second, worse cron, and one more thing to
 * notice has stopped running.
 */
class ReportController extends Controller
{
    /** The period the summary covers: today, yesterday, 7d, 30d, 90d, 12mo. */
    public string $period = DateRange::PRESET_7_DAYS;

    /** Which site to report on. Defaults to the primary site. */
    public ?int $siteId = null;

    /** Print the report to the console instead of emailing it. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['period', 'siteId', 'dryRun']);
    }

    /**
     * Send the summary report.
     */
    public function actionSend(): int
    {
        $mailer = Plugin::getInstance()->getReportMailer();

        if (!array_key_exists($this->period, DateRange::presets())) {
            $this->stderr("“{$this->period}” is not a period. Try: " . implode(', ', array_keys(DateRange::presets())) . "\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        if ($this->dryRun) {
            $siteId = $this->siteId ?? \Craft::$app->getSites()->getPrimarySite()->id;
            $summary = $mailer->summary((int)$siteId, $this->period);

            $this->stdout("{$summary['range']->label} ({$summary['range']->from} to {$summary['range']->to})\n\n", Console::FG_YELLOW);
            $this->stdout(sprintf(
                "  Views      %s\n  Visitors   %s\n  Sessions   %s\n\n",
                number_format($summary['totals']['views']),
                number_format($summary['totals']['uniques']),
                number_format($summary['totals']['sessions']),
            ));

            $recipients = $mailer->recipients();
            $this->stdout('Would send to: ' . ($recipients === [] ? 'nobody (none configured)' : implode(', ', $recipients)) . "\n");

            return ExitCode::OK;
        }

        $result = $mailer->send($this->period, $this->siteId);

        if ($result['skipped'] !== null) {
            $this->stdout($result['skipped'] . "\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout("Sent to {$result['sent']} recipient(s): " . implode(', ', $result['recipients']) . "\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
