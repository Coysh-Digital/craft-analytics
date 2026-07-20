<?php

namespace coyshdigital\craftanalytics\controllers;

use coyshdigital\craftanalytics\helpers\ElementLinks;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use yii\web\Response;

/**
 * The detail screens: pages, sources, devices and crawlers.
 */
class ReportsController extends BaseCpController
{
    /**
     * Crawler activity - what was kept out of every other screen.
     */
    public function actionCrawlers(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
        $settings = Plugin::getInstance()->getSettings();

        return $this->renderTemplate('craft-analytics/reports/crawlers.twig', array_merge(
            $this->commonVariables($site, $range),
            [
                'title' => Craft::t('craft-analytics', 'Crawlers'),
                'selectedSubnavItem' => 'crawlers',
                'crawlers' => $this->stats()->crawlers($siteId, $range),
                'requests' => $this->stats()->crawlerRequests($siteId, $range),
                'humanViews' => $this->stats()->totals($siteId, $range)['views'],
                'blocking' => $settings->blockCrawlers,
                'tracking' => $settings->trackCrawlers,
            ],
        ));
    }

    public function actionPages(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
        $include = trim((string)$this->request->getParam('q', ''));
        $exclude = trim((string)$this->request->getParam('exclude', ''));
        $pages = $this->stats()->topPages($siteId, $range, 200, $include ?: null, $exclude ?: null);
        $commonVariables = $this->commonVariables($site, $range);

        return $this->renderTemplate('craft-analytics/reports/pages.twig', array_merge(
            $commonVariables,
            [
                'title' => 'Pages',
                'selectedSubnavItem' => 'pages',
                'pages' => $pages,
                'editUrls' => ElementLinks::editUrls(array_column($pages, 'elementId')),
                'exportKind' => 'pages',
                'exportParams' => ['site' => $site->handle, 'range' => $range->preset],
                'pathInclude' => $include,
                'pathExclude' => $exclude,
                'dimensionCap' => Plugin::getInstance()->getSettings()->dimensionCap,
                // The range/site links reuse currentParams to build their hrefs
                // - the filter has to ride along or switching range would
                // silently drop it.
                'currentParams' => array_merge($commonVariables['currentParams'], array_filter([
                    'q' => $include !== '' ? $include : null,
                    'exclude' => $exclude !== '' ? $exclude : null,
                ])),
            ],
        ));
    }

    public function actionSources(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
        $stats = $this->stats();

        return $this->renderTemplate('craft-analytics/reports/sources.twig', array_merge(
            $this->commonVariables($site, $range),
            [
                'title' => 'Sources',
                'selectedSubnavItem' => 'sources',
                'channels' => $stats->channels($siteId, $range),
                'referrers' => $stats->sources($siteId, $range, 200),
                'exportKind' => 'sources',
                'exportParams' => ['site' => $site->handle, 'range' => $range->preset],
            ],
        ));
    }

    public function actionDevices(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
        $stats = $this->stats();

        return $this->renderTemplate('craft-analytics/reports/devices.twig', array_merge(
            $this->commonVariables($site, $range),
            [
                'title' => 'Devices',
                'selectedSubnavItem' => 'devices',
                'browsers' => $stats->devices($siteId, $range, 'browser'),
                'operatingSystems' => $stats->devices($siteId, $range, 'os'),
                'deviceTypes' => $stats->devices($siteId, $range, 'deviceType'),
                'exportKind' => 'devices',
                'exportParams' => ['site' => $site->handle, 'range' => $range->preset],
            ],
        ));
    }

    public function actionRealtime(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();

        return $this->renderTemplate('craft-analytics/reports/realtime.twig', array_merge(
            $this->commonVariables($site, $range),
            [
                'title' => 'Real-time',
                'selectedSubnavItem' => 'realtime',
                // "Right now" has no date range and nothing to export; showing
                // either would be furniture that lies about what the screen is.
                'showRanges' => false,
                'canExport' => false,
                'realtime' => $this->stats()->realtime($siteId),
                'sessionWindow' => \coyshdigital\craftanalytics\Plugin::getInstance()->getSettings()->sessionWindow,
            ],
        ));
    }

    /**
     * Polled by the real-time screen. Reads the session hot layer only — no
     * database query, so refreshing it costs nothing.
     */
    public function actionRealtimeData(): Response
    {
        $this->requireAcceptsJson();
        $site = $this->currentSite();
        $siteId = $this->siteId($site);

        return $this->asJson($this->stats()->realtime($siteId));
    }
}
