<?php

namespace coyshdigital\craftanalytics\controllers;

use coyshdigital\craftanalytics\assets\ChartsAsset;
use coyshdigital\craftanalytics\assets\CpAsset;
use coyshdigital\craftanalytics\models\DateRange;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\services\StatsService;
use Craft;
use craft\models\Site;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;

/**
 * Shared plumbing for the CP screens: permissions, site scoping and the date
 * range.
 */
abstract class BaseCpController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    /**
     * The site being viewed, honouring the per-site permission model: a user
     * who can only edit one site's content has no business reading another
     * site's traffic (C8).
     */
    protected function currentSite(): Site
    {
        $handle = $this->request->getParam('site');
        $sites = $this->allowedSites();

        if ($sites === []) {
            throw new ForbiddenHttpException('You don’t have permission to view analytics for any site.');
        }

        if ($handle !== null) {
            foreach ($sites as $site) {
                if ($site->handle === $handle) {
                    return $site;
                }
            }

            throw new ForbiddenHttpException('You don’t have permission to view analytics for that site.');
        }

        $current = Craft::$app->getSites()->getCurrentSite();

        foreach ($sites as $site) {
            if ($site->id === $current->id) {
                return $site;
            }
        }

        return $sites[0];
    }

    /**
     * @return Site[]
     */
    protected function allowedSites(): array
    {
        $user = Craft::$app->getUser();
        $sites = Craft::$app->getSites()->getAllSites();

        if ($user->checkPermission(Plugin::PERMISSION_VIEW_ALL_SITES)) {
            return array_values($sites);
        }

        return array_values(array_filter(
            $sites,
            static fn(Site $site) => $user->checkPermission('editSite:' . $site->uid),
        ));
    }

    /**
     * The current site's ID. Non-null by construction: currentSite() only ever
     * returns a saved site.
     */
    protected function siteId(Site $site): int
    {
        return $site->id ?? throw new ForbiddenHttpException('That site has not been saved yet.');
    }

    protected function range(): DateRange
    {
        return DateRange::fromPreset((string)$this->request->getParam('range', DateRange::PRESET_30_DAYS));
    }

    /**
     * Everything every screen's template needs.
     *
     * @return array<string,mixed>
     */
    protected function commonVariables(Site $site, DateRange $range): array
    {
        Craft::$app->getView()->registerAssetBundle(CpAsset::class);

        return [
            'site' => $site,
            'sites' => $this->allowedSites(),
            'range' => $range,
            'ranges' => DateRange::presets(),
            'currentPath' => $this->request->getPathInfo(),
            'currentParams' => ['site' => $site->handle, 'range' => $range->preset],
            'canExport' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_EXPORT),
            'uniquesAccuracy' => $this->stats()->uniquesAccuracy(),
        ];
    }

    /**
     * Loads Chart.js and the chart initialiser.
     *
     * Called from the individual actions that draw something rather than from
     * commonVariables(), deliberately: eight CP screens chart nothing at all,
     * and there is no reason for Crawlers or Privacy to download a charting
     * library. Craft's own entry editor and dashboard must never register this
     * either — the sparklines there stay server-rendered SVG precisely so those
     * screens carry no JavaScript of ours.
     */
    protected function registerCharts(): void
    {
        Craft::$app->getView()->registerAssetBundle(ChartsAsset::class);
    }

    protected function stats(): StatsService
    {
        return Plugin::getInstance()->getStats();
    }

    /**
     * Percentage change against the previous period, or null when there is no
     * previous figure to compare with — showing "+100%" against zero would be
     * arithmetic, not information.
     */
    protected static function delta(float $current, float $previous): ?float
    {
        if ($previous <= 0.0) {
            return null;
        }

        return ($current - $previous) / $previous * 100;
    }
}
