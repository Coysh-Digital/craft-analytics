<?php

namespace coyshdigital\craftanalytics\controllers;

use coyshdigital\craftanalytics\helpers\ElementLinks;
use coyshdigital\craftanalytics\Plugin;
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

        return $this->renderTemplate('craft-analytics/dashboard/index.twig', array_merge(
            $this->commonVariables($site, $range),
            [
                'title' => 'Dashboard',
                'selectedSubnavItem' => 'dashboard',
                'isPro' => $isPro,
                'totals' => $totals,
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
                'trend' => $stats->trend($siteId, $range),
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
                'exportParams' => ['site' => $site->handle, 'range' => $range->preset],
            ],
        ));
    }
}
