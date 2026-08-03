<?php

namespace coyshdigital\craftanalytics\controllers;

use coyshdigital\craftanalytics\charts\ChartData;
use coyshdigital\craftanalytics\enums\DimensionType;
use coyshdigital\craftanalytics\helpers\ElementLinks;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use yii\web\NotFoundHttpException;
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

        $compare = $this->comparePaths($pages);
        $compareChart = null;

        if ($compare !== []) {
            $labels = ChartData::axisLabels($range->dates(), false);
            $series = ChartData::pivot(
                $this->stats()->pathsTrend($siteId, $range, $compare),
                $range->dates(),
                'path',
                'views',
            );

            // Ordered by the checkbox order rather than by total, so the
            // legend matches the list the reader just ticked.
            $ordered = [];
            foreach ($compare as $path) {
                $ordered[$path] = $series[$path] ?? array_fill(0, count($range->dates()), 0);
            }

            $compareChart = ChartData::line($labels['axis'], ChartData::datasets($ordered));
            $compareChart['full'] = $labels['full'];

            $this->registerCharts();
        }

        return $this->renderTemplate('craft-analytics/reports/pages.twig', array_merge(
            $commonVariables,
            [
                'title' => 'Pages',
                'selectedSubnavItem' => 'pages',
                'pages' => $pages,
                'compare' => $compare,
                'compareChart' => $compareChart,
                'compareLimit' => ChartData::MAX_SERIES,
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

    /**
     * The paths to plot against each other, from `compare[]`.
     *
     * Validated against the rows already on screen rather than trusted: a
     * request naming an arbitrary path would otherwise answer whether that
     * path exists on a site the user can see the report for, one guess at a
     * time. Capped at the width of the validated colour ramp — beyond that
     * two series share a colour and the chart stops being readable.
     *
     * @param array<int,array{path: string, ...}> $pages
     * @return string[]
     */
    private function comparePaths(array $pages): array
    {
        $requested = $this->request->getParam('compare');

        if (!is_array($requested)) {
            return [];
        }

        $available = array_flip(array_column($pages, 'path'));
        $paths = [];

        foreach ($requested as $path) {
            if (!is_string($path) || !isset($available[$path]) || in_array($path, $paths, true)) {
                continue;
            }

            $paths[] = $path;

            if (count($paths) >= ChartData::MAX_SERIES) {
                break;
            }
        }

        return $paths;
    }

    /**
     * One page's own screen.
     *
     * Reachable from any row of the Pages report, including the ones that
     * never resolved to a Craft element - a template-only route, a search
     * results page - which is the whole reason the Pages table's links could
     * not simply point at the entry editor.
     */
    public function actionPage(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
        $stats = $this->stats();
        $plugin = Plugin::getInstance();
        $isPro = $plugin->is(Plugin::EDITION_PRO);

        $path = (string)$this->request->getRequiredParam('path');

        // getId() looks up without creating, so a path nobody has ever visited
        // 404s rather than quietly minting a dimension and rendering zeros.
        $pathDimId = $plugin->getDimensions()->getId(DimensionType::Path, $path);

        if ($pathDimId === null) {
            throw new NotFoundHttpException('No analytics recorded for that page.');
        }

        $totals = $stats->pageTotals($siteId, $range, $pathDimId, $path);
        $previous = $stats->pageTotals($siteId, $range->previous(), $pathDimId, $path);
        $uniques = $stats->uniquesFor($siteId, $range, $pathDimId);

        $scroll = $stats->scrollDepth($siteId, $range, 1, $pathDimId);
        $commonVariables = $this->commonVariables($site, $range);

        $this->registerCharts();

        return $this->renderTemplate('craft-analytics/reports/page.twig', array_merge(
            $commonVariables,
            [
                'title' => $path,
                'selectedSubnavItem' => 'pages',
                'isPro' => $isPro,
                'path' => $path,
                'totals' => $totals,
                'uniques' => $uniques,
                'deltas' => [
                    'views' => self::delta($totals['views'], $previous['views']),
                    'uniques' => self::delta(
                        $uniques,
                        $stats->uniquesFor($siteId, $range->previous(), $pathDimId),
                    ),
                    'entrances' => self::delta($totals['entrances'], $previous['entrances']),
                    'exits' => self::delta($totals['exits'], $previous['exits']),
                    'bounceRate' => self::delta($totals['bounceRate'], $previous['bounceRate']),
                    'avgDwellMs' => self::delta($totals['avgDwellMs'], $previous['avgDwellMs']),
                ],
                'trend' => $stats->trend($siteId, $range, $pathDimId),
                'sources' => $stats->pageSources($siteId, $range, $pathDimId),
                // Only meaningful while the table is still filling: it cannot
                // be backfilled, so a page with traffic older than this has
                // views here with no recorded source, and the card says so
                // rather than looking like the page had no referrers.
                'sourcesSince' => $stats->pageSourcesSince($siteId),
                'scroll' => $scroll[0] ?? null,
                'events' => $isPro ? $stats->events($siteId, $range, 20, $pathDimId) : [],
                'outbound' => $isPro ? $stats->outbound($siteId, $range, 20, $pathDimId) : [],
                'editUrls' => ElementLinks::editUrls([$totals['elementId']]),
                'exportKind' => 'pages',
                'exportParams' => ['site' => $site->handle, 'range' => $range->preset],
                // The range pills and site switcher rebuild their hrefs from
                // currentParams; without the path riding along, changing the
                // range would land back on the Pages list.
                'currentParams' => array_merge($commonVariables['currentParams'], ['path' => $path]),
            ],
        ));
    }

    public function actionSources(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
        $stats = $this->stats();

        $labels = ChartData::axisLabels($range->dates(), false);
        $series = ChartData::foldToTop(ChartData::pivot(
            $stats->channelTrend($siteId, $range),
            $range->dates(),
            'channel',
            'sessions',
        ));

        $channelChart = ChartData::stackedArea($labels['axis'], ChartData::datasets($series));
        $channelChart['full'] = $labels['full'];

        $this->registerCharts();

        return $this->renderTemplate('craft-analytics/reports/sources.twig', array_merge(
            $this->commonVariables($site, $range),
            [
                'title' => 'Sources',
                'selectedSubnavItem' => 'sources',
                'channels' => $stats->channels($siteId, $range),
                'channelChart' => $channelChart,
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
        $deviceTypes = $stats->devices($siteId, $range, 'deviceType');

        $this->registerCharts();

        return $this->renderTemplate('craft-analytics/reports/devices.twig', array_merge(
            $this->commonVariables($site, $range),
            [
                'title' => 'Devices',
                'selectedSubnavItem' => 'devices',
                'browsers' => $stats->devices($siteId, $range, 'browser'),
                'operatingSystems' => $stats->devices($siteId, $range, 'os'),
                'deviceTypes' => $deviceTypes,
                // Only the device-type split is a whole worth showing as one:
                // there are three or four of them. Browsers and operating
                // systems have a long tail that a doughnut turns into slivers.
                'deviceChart' => ChartData::doughnut(
                    array_column($deviceTypes, 'label'),
                    array_column($deviceTypes, 'sessions'),
                    ['label' => Craft::t('craft-analytics', 'Sessions')],
                ),
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
