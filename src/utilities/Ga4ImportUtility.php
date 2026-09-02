<?php

namespace coyshdigital\craftanalytics\utilities;

use coyshdigital\craftanalytics\controllers\Ga4ImportController;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use craft\base\Utility;
use craft\helpers\UrlHelper;

/**
 * Utilities > Import GA4 History.
 *
 * The home for a one-off migration aid: connect to Google, pick a property and
 * a range, and bring the history across. It lives here rather than in the
 * plugin's own reports because that is what it is - a maintenance task run once
 * when a site moves over, not a screen anyone returns to.
 *
 * Available in both editions. The Pro datasets (campaigns, geography, events)
 * are simply hidden on Lite, the same reports being absent there.
 */
class Ga4ImportUtility extends Utility
{
    public static function id(): string
    {
        return 'ga4-import';
    }

    public static function displayName(): string
    {
        return Craft::t('craft-analytics', 'Import GA4 History');
    }

    public static function icon(): ?string
    {
        return '@coyshdigital/craftanalytics/resources/icon-mask.svg';
    }

    public static function contentHtml(): string
    {
        $plugin = Plugin::getInstance();

        return Craft::$app->getView()->renderTemplate('craft-analytics/utilities/ga4-import.twig', [
            'hasCredentials' => $plugin->getGa4Client()->hasCredentials(),
            'connection' => $plugin->getGa4Auth()->state(),
            'redirectUri' => Ga4ImportController::redirectUri(),
            // Every site is a valid destination; this is a settings-level task,
            // not an analytics-viewing one, so it is not scoped to view rights.
            'sites' => Craft::$app->getSites()->getAllSites(),
            'isPro' => $plugin->is(Plugin::EDITION_PRO),
            'settingsUrl' => UrlHelper::cpUrl('settings/plugins/craft-analytics'),
        ]);
    }
}
