<?php

namespace coyshdigital\craftanalytics\controllers;

use coyshdigital\craftanalytics\Plugin;
use craft\models\Site;
use yii\web\Response;

/**
 * The privacy posture panel: what this configuration actually means.
 */
class PrivacyController extends BaseCpController
{
    public function actionIndex(): Response
    {
        $site = $this->currentSite();
        $range = $this->range();
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('craft-analytics/privacy/index.twig', array_merge(
            $this->commonVariables($site, $range),
            [
                'title' => 'Privacy',
                'selectedSubnavItem' => 'privacy',
                'showRanges' => false,
                'canExport' => false,
                'posture' => $plugin->getPrivacy()->posture(),
                // Only the sites this viewer may see, like every other figure
                // on every other screen.
                'counts' => $plugin->getPrivacy()->counts(array_values(array_filter(
                    array_map(static fn(Site $allowed): ?int => $allowed->id, $this->allowedSites()),
                    static fn(?int $id): bool => $id !== null,
                ))),
                'settings' => $plugin->getSettings(),
                'isPro' => $plugin->is(Plugin::EDITION_PRO),
            ],
        ));
    }
}
