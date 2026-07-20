<?php

namespace coyshdigital\craftanalytics\controllers;

use coyshdigital\craftanalytics\enums\AttributionModel;
use coyshdigital\craftanalytics\Plugin;
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

        return $this->renderProTemplate('geo', $site, $range, [
            'title' => 'Locations',
            'countries' => $this->stats()->countries($siteId, $range),
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
