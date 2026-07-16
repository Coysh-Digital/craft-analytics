<?php

namespace coyshdigital\craftanalytics\controllers;

use yii\web\Response;

/**
 * The overview screen: headline numbers, the traffic trend, and the top of
 * each report.
 */
class DashboardController extends BaseCpController
{
    public function actionIndex(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
        $stats = $this->stats();

        $totals = $stats->totals($siteId, $range);
        $previous = $stats->totals($siteId, $range->previous());

        return $this->renderTemplate('craft-analytics/dashboard/index.twig', array_merge(
            $this->commonVariables($site, $range),
            [
                'title' => 'Dashboard',
                'selectedSubnavItem' => 'dashboard',
                'totals' => $totals,
                'deltas' => [
                    'views' => self::delta($totals['views'], $previous['views']),
                    'uniques' => self::delta($totals['uniques'], $previous['uniques']),
                    'sessions' => self::delta($totals['sessions'], $previous['sessions']),
                    'bounceRate' => self::delta($totals['bounceRate'], $previous['bounceRate']),
                    'avgDurationMs' => self::delta($totals['avgDurationMs'], $previous['avgDurationMs']),
                ],
                'trend' => $stats->trend($siteId, $range),
                'topPages' => $stats->topPages($siteId, $range, 8),
                'channels' => $stats->channels($siteId, $range),
                'deviceTypes' => $stats->devices($siteId, $range, 'deviceType'),
                'exportKind' => 'pages',
                'exportParams' => ['site' => $site->handle, 'range' => $range->preset],
            ],
        ));
    }
}
