<?php

namespace coyshdigital\craftanalytics\controllers;

use coyshdigital\craftanalytics\Plugin;
use Craft;
use yii\web\Response;

/**
 * The goals and funnels reports (Pro).
 */
class ConversionsController extends BaseCpController
{
    public function actionGoals(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
        $isPro = Plugin::getInstance()->is(Plugin::EDITION_PRO);

        return $this->renderTemplate('craft-analytics/conversions/goals.twig', array_merge(
            $this->commonVariables($site, $range),
            [
                'title' => Craft::t('craft-analytics', 'Goals'),
                'selectedSubnavItem' => 'goals',
                'isPro' => $isPro,
                'goals' => $isPro
                    ? Plugin::getInstance()->getConversionStats()->goals($siteId, $range)
                    : [],
                'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_SETTINGS),
                'exportKind' => 'goals',
            ],
        ));
    }

    public function actionFunnels(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
        $plugin = Plugin::getInstance();
        $isPro = $plugin->is(Plugin::EDITION_PRO);

        $funnels = $isPro ? $plugin->getFunnels()->enabledForSite($siteId) : [];
        $selected = null;
        $steps = [];

        if ($funnels !== []) {
            $handle = $this->request->getParam('funnel');
            $selected = $handle !== null ? $plugin->getFunnels()->getByHandle((string)$handle) : null;
            $selected ??= $funnels[0];
            $steps = $plugin->getConversionStats()->funnel($siteId, $range, (int)$selected->id);
        }

        return $this->renderTemplate('craft-analytics/conversions/funnels.twig', array_merge(
            $this->commonVariables($site, $range),
            [
                'title' => Craft::t('craft-analytics', 'Funnels'),
                'selectedSubnavItem' => 'funnels',
                'isPro' => $isPro,
                'funnels' => $funnels,
                'selectedFunnel' => $selected,
                'steps' => $steps,
                'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_SETTINGS),
            ],
        ));
    }
}
