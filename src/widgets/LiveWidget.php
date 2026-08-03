<?php

namespace coyshdigital\craftanalytics\widgets;

use coyshdigital\craftanalytics\assets\CpAsset;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use craft\base\Widget;

/**
 * Visitors right now, on Craft's own dashboard.
 *
 * Reads the session hot layer only - the same cache-only source as the
 * Real-time screen - so it costs no database query, and it polls the existing
 * realtime-data endpoint to keep the count live.
 */
class LiveWidget extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('craft-analytics', 'Analytics real-time');
    }

    public static function icon(): ?string
    {
        return 'signal';
    }

    public static function isSelectable(): bool
    {
        return parent::isSelectable()
            && Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_VIEW);
    }

    public function getTitle(): ?string
    {
        return Craft::t('craft-analytics', 'Real-time');
    }

    public function getBodyHtml(): ?string
    {
        if (!Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_VIEW)) {
            return null;
        }

        $site = Craft::$app->getSites()->getCurrentSite();

        if ($site->id === null) {
            return null;
        }

        // CSS only. This widget draws no chart, so it has no business
        // registering ChartsAsset — see OverviewWidget for the reasoning.
        Craft::$app->getView()->registerAssetBundle(CpAsset::class);

        return Craft::$app->getView()->renderTemplate('craft-analytics/_widgets/live.twig', [
            'realtime' => Plugin::getInstance()->getStats()->realtime($site->id),
            'sessionWindow' => Plugin::getInstance()->getSettings()->sessionWindow,
            'site' => $site,
            // Each widget instance owns its DOM ids, so two live widgets on one
            // dashboard don't fight over the same count element.
            'domId' => 'ca-live-widget-' . ($this->id ?? 'new'),
        ]);
    }
}
