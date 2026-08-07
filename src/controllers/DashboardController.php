<?php

namespace coyshdigital\craftanalytics\controllers;

use coyshdigital\craftanalytics\charts\Heatmap;
use coyshdigital\craftanalytics\helpers\ElementLinks;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\write\SpoolStatus;
use Craft;
use yii\web\Response;

/**
 * The overview screen: headline numbers, the traffic trend, and the top of
 * each report.
 *
 * The Pro sections are queried only on Pro. On Lite their cards still render,
 * saying what the screen would tell you - a card that vanishes teaches nobody
 * what they are missing, and a card that queries tables its edition cannot
 * read is a query for nothing.
 */
class DashboardController extends BaseCpController
{
    public function actionIndex(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
        $stats = $this->stats();
        $plugin = Plugin::getInstance();
        $isPro = $plugin->is(Plugin::EDITION_PRO);

        $totals = $stats->totals($siteId, $range);
        $previous = $stats->totals($siteId, $range->previous());
        $topPages = $stats->topPages($siteId, $range, 8);

        $this->registerCharts();

        return $this->renderTemplate('craft-analytics/dashboard/index.twig', array_merge(
            $this->commonVariables($site, $range),
            [
                'title' => 'Dashboard',
                'selectedSubnavItem' => 'dashboard',
                'isPro' => $isPro,
                'totals' => $totals,
                // Only worth computing when there's nothing to show: distinguishes
                // "no traffic yet" from "traffic arrived but is stuck in the spool" -
                // the Real-time screen reads a different, always-current source and
                // doesn't need this.
                'emptyStateHint' => $totals['views'] === 0
                    ? self::emptyStateHint($plugin->getSettings())
                    : null,
                'deltas' => [
                    'views' => self::delta($totals['views'], $previous['views']),
                    'uniques' => self::delta($totals['uniques'], $previous['uniques']),
                    'sessions' => self::delta($totals['sessions'], $previous['sessions']),
                    'bounceRate' => self::delta($totals['bounceRate'], $previous['bounceRate']),
                    'avgDurationMs' => self::delta($totals['avgDurationMs'], $previous['avgDurationMs']),
                    'avgViewsPerSession' => self::delta(
                        $totals['avgViewsPerSession'],
                        $previous['avgViewsPerSession'],
                    ),
                ],
                // The live banner reads the session cache only, so it costs no
                // database query - the same source as the Real-time screen.
                'realtime' => $stats->realtime($siteId),
                'sessionWindow' => $plugin->getSettings()->sessionWindow,

                'trend' => $stats->trend($siteId, $range),

                // Always the hourly-retention window, never the selected
                // range - see StatsService::hourOfWeek(). The card says which
                // window it got, so the limit reads as a policy rather than a
                // chart that mysteriously went blank on "Last 90 days".
                'heatmap' => Heatmap::grid($stats->hourOfWeek($siteId, $range)),
                'heatmapFrom' => $stats->hourlyWindowFrom(),
                'hourlyWindowDays' => $plugin->getSettings()->hourlyWindowDays,
                'topPages' => $topPages,
                'editUrls' => ElementLinks::editUrls(array_column($topPages, 'elementId')),
                'channels' => $stats->channels($siteId, $range),
                'deviceTypes' => $stats->devices($siteId, $range, 'deviceType'),

                // Lite, both: knowing your own content model, and knowing what
                // was excluded from your numbers, are not premium concerns.
                'sections' => $plugin->getContentStats()->bySection($siteId, $range, 5),
                'crawlerRequests' => $stats->crawlerRequests($siteId, $range),

                'goals' => $isPro
                    ? array_slice($plugin->getConversionStats()->goals($siteId, $range), 0, 5)
                    : [],
                'campaigns' => $isPro ? $stats->campaigns($siteId, $range, 5) : [],
                'countries' => $isPro ? $stats->countries($siteId, $range, 5) : [],

                'exportKind' => 'pages',
                'exportParams' => ['site' => $site->handle, 'range' => $range->param],
            ],
        ));
    }

    /**
     * "No data" reads very differently depending on why. A spooled install
     * with hits sitting unread is not the same problem as a site nobody has
     * visited yet, and telling them apart here is the only place that
     * information is cheap to get - one `filesize()`, not a query.
     *
     * @return array{title: string, message: string}
     */
    private static function emptyStateHint(Settings $settings): array
    {
        if ($settings->writeDriver === Settings::WRITE_DRIVER_SPOOL && (new SpoolStatus())->hasBacklog()) {
            return [
                'title' => Craft::t('craft-analytics', 'Data is waiting to be counted'),
                'message' => Craft::t(
                    'craft-analytics',
                    'Pageviews have arrived but haven’t been drained into the reports yet. Run '
                    . 'php craft craft-analytics/drain/run, make sure it’s already on your cron, or turn on '
                    . 'the automatic fallback under Settings → How data is written.',
                ),
            ];
        }

        return [
            'title' => Craft::t('craft-analytics', 'No data for this period'),
            'message' => $settings->writeDriver === Settings::WRITE_DRIVER_QUEUE
                ? Craft::t(
                    'craft-analytics',
                    'Once the site has traffic and the queue worker has processed it, it appears here.',
                )
                : Craft::t('craft-analytics', 'Once the site has traffic, it appears here.'),
        ];
    }
}
