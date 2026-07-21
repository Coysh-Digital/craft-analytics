<?php

namespace coyshdigital\craftanalytics\controllers;

use coyshdigital\craftanalytics\assets\GeoMapAsset;
use coyshdigital\craftanalytics\enums\AttributionModel;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use yii\web\Response;

/**
 * The Pro reports: campaigns, geo, events.
 *
 * Gated at the controller, not in the template — hiding a nav item is not a
 * licence check. Lite gets a plain explanation of what the screen would show,
 * once, without nagging.
 */
class ProReportsController extends BaseCpController
{
    public function actionCampaigns(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
        $settings = Plugin::getInstance()->getSettings();

        return $this->renderProTemplate('campaigns', $site, $range, [
            'title' => 'Campaigns',
            'campaigns' => $this->stats()->campaigns($siteId, $range),
            'model' => AttributionModel::tryFrom($settings->attributionModel) ?? AttributionModel::LastClick,
            'enabled' => $settings->enableCampaigns,
        ]);
    }

    public function actionGeo(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
        $plugin = Plugin::getInstance();
        $countries = $this->stats()->countries($siteId, $range);

        if ($plugin->is(Plugin::EDITION_PRO) && $plugin->getSettings()->enableGeo) {
            Craft::$app->getView()->registerAssetBundle(GeoMapAsset::class);
        }

        return $this->renderProTemplate('geo', $site, $range, [
            'title' => 'Locations',
            'countries' => $countries,
            'countryValues' => array_column($countries, 'sessions', 'country'),
            'regions' => $this->stats()->regions($siteId, $range),
            'enabled' => $plugin->getSettings()->enableGeo,
            'database' => $plugin->getGeo()->databaseInfo(),
            'attribution' => $plugin->getGeo()->attributionNotice(),
            'blockCrawlers' => $plugin->getSettings()->blockCrawlers,
        ]);
    }

    public function actionEvents(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
        $stats = $this->stats();
        $settings = Plugin::getInstance()->getSettings();

        return $this->renderProTemplate('events', $site, $range, [
            'title' => 'Events',
            'events' => $stats->events($siteId, $range),
            'outbound' => $stats->outbound($siteId, $range),
            'searches' => $stats->searches($siteId, $range),
            'scroll' => $stats->scrollDepth($siteId, $range, 20),
            'enabled' => $settings->enableEvents,
            'searchEnabled' => $settings->trackSiteSearch,
        ]);
    }

    /**
     * Whatever the site's own module decided to segment its visitors by.
     *
     * The screen only has a nav item when something is declared, but the
     * route stays reachable — a bookmarked URL should explain itself rather
     * than 404.
     */
    public function actionSegments(): Response
    {
        $site = $this->currentSite();
        $range = $this->range();
        $registry = Plugin::getInstance()->getSegments();

        return $this->renderProTemplate('segments', $site, $range, [
            'title' => 'Segments',
            'segments' => $this->stats()->segments($this->siteId($site), $range),
            'enabled' => $registry->isEnabled(),
            'dimensionCap' => Plugin::getInstance()->getSettings()->dimensionCap,
        ]);
    }

    /**
     * @param array<string,mixed> $variables
     */
    private function renderProTemplate(
        string $screen,
        \craft\models\Site $site,
        \coyshdigital\craftanalytics\models\DateRange $range,
        array $variables,
    ): Response {
        $isPro = Plugin::getInstance()->is(Plugin::EDITION_PRO);

        return $this->renderTemplate('craft-analytics/pro/' . $screen . '.twig', array_merge(
            $this->commonVariables($site, $range),
            ['selectedSubnavItem' => $screen, 'isPro' => $isPro],
            $variables,
        ));
    }
}
