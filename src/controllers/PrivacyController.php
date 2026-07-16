<?php

namespace coyshdigital\craftanalytics\controllers;

use coyshdigital\craftanalytics\Plugin;
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
                'counts' => $plugin->getPrivacy()->counts(),
                'settings' => $plugin->getSettings(),
                'isPro' => $plugin->is(Plugin::EDITION_PRO),
            ],
        ));
    }
}
