<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\models\DateRange;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use craft\helpers\App;
use yii\base\Component;

/**
 * Scheduled summary emails.
 *
 * Sent with Craft's own mailer, configured by the site, to addresses the site
 * chose. No third-party email service, no tracking pixel, no click-through
 * redirector, and nothing leaves the server that the operator didn't ask for
 * (C7). If the site can send a password reset, it can send this.
 *
 * Scheduling is the operator's: `craft-analytics/report/send --period=week`
 * on whatever cron they already run. A plugin that invents its own scheduler
 * is a plugin with a second, worse cron.
 */
class ReportMailer extends Component
{
    /**
     * Builds and sends the summary for one period.
     *
     * @param string $period one of DateRange's presets
     * @return array{sent: int, recipients: string[], skipped: string|null}
     */
    public function send(string $period = DateRange::PRESET_7_DAYS, ?int $siteId = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $recipients = $this->recipients();

        if (!Plugin::getInstance()->is(Plugin::EDITION_PRO)) {
            return ['sent' => 0, 'recipients' => [], 'skipped' => 'Scheduled reports are a Pro feature.'];
        }

        if (!$settings->enableScheduledReports) {
            return ['sent' => 0, 'recipients' => [], 'skipped' => 'Scheduled reports are turned off.'];
        }

        if ($recipients === []) {
            return ['sent' => 0, 'recipients' => [], 'skipped' => 'No recipients are configured.'];
        }

        $site = $siteId !== null
            ? Craft::$app->getSites()->getSiteById($siteId)
            : Craft::$app->getSites()->getPrimarySite();

        if ($site === null || $site->id === null) {
            return ['sent' => 0, 'recipients' => [], 'skipped' => 'That site does not exist.'];
        }

        $data = $this->summary($site->id, $period);
        $subject = Craft::t('craft-analytics', '{site} analytics: {period}', [
            'site' => $site->name,
            'range' => '',
            'period' => $data['range']->label,
        ]);

        $sent = 0;

        foreach ($recipients as $recipient) {
            $message = Craft::$app->getMailer()
                ->compose()
                ->setTo($recipient)
                ->setSubject($subject)
                ->setHtmlBody($this->render('html', $data + ['site' => $site]))
                ->setTextBody($this->render('text', $data + ['site' => $site]));

            if ($message->send()) {
                $sent++;
            } else {
                Craft::warning("Could not send the analytics report to {$recipient}.", __METHOD__);
            }
        }

        return ['sent' => $sent, 'recipients' => $recipients, 'skipped' => null];
    }

    /**
     * Everything the email shows, for one site and period.
     *
     * Includes the previous period's totals, because a number on its own
     * ("4,812 views") tells nobody whether that is good.
     *
     * @return array{range: DateRange, totals: array<string,mixed>, previous: array<string,mixed>, pages: array<int,array<string,mixed>>, sources: array<int,array<string,mixed>>, goals: array<int,array<string,mixed>>, deltas: array<string,float|null>}
     */
    public function summary(int $siteId, string $period): array
    {
        $stats = Plugin::getInstance()->getStats();
        $range = DateRange::fromPreset($period);
        $previousRange = $range->previous();

        $totals = $stats->totals($siteId, $range);
        $previous = $stats->totals($siteId, $previousRange);

        return [
            'range' => $range,
            'totals' => $totals,
            'previous' => $previous,
            'deltas' => [
                'views' => self::delta((float)$totals['views'], (float)$previous['views']),
                'uniques' => self::delta((float)$totals['uniques'], (float)$previous['uniques']),
                'sessions' => self::delta((float)$totals['sessions'], (float)$previous['sessions']),
            ],
            'pages' => array_slice($stats->topPages($siteId, $range, 10), 0, 10),
            'sources' => array_slice($stats->sources($siteId, $range, 10), 0, 10),
            'goals' => Plugin::getInstance()->getConversionStats()->goals($siteId, $range),
        ];
    }

    /**
     * The addresses to send to.
     *
     * @return string[]
     */
    public function recipients(): array
    {
        $configured = Plugin::getInstance()->getSettings()->reportRecipients;
        $recipients = [];

        foreach ($configured as $recipient) {
            $parsed = App::parseEnv($recipient);
            $recipient = is_string($parsed) ? trim($parsed) : '';

            if ($recipient !== '' && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = $recipient;
            }
        }

        return array_values(array_unique($recipients));
    }

    /**
     * @param array<string,mixed> $variables
     */
    private function render(string $format, array $variables): string
    {
        $view = Craft::$app->getView();

        return $view->renderTemplate(
            "craft-analytics/_email/summary-{$format}.twig",
            $variables,
            \craft\web\View::TEMPLATE_MODE_CP,
        );
    }

    private static function delta(float $current, float $previous): ?float
    {
        if ($previous <= 0.0) {
            return null;
        }

        return ($current - $previous) / $previous * 100;
    }
}
