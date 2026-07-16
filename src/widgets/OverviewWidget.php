<?php

namespace coyshdigital\craftanalytics\widgets;

use coyshdigital\craftanalytics\assets\CpAsset;
use coyshdigital\craftanalytics\models\DateRange;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use craft\base\Widget;

/**
 * The traffic overview, on Craft's own dashboard.
 */
class OverviewWidget extends Widget
{
    public string $range = DateRange::PRESET_7_DAYS;

    public static function displayName(): string
    {
        return Craft::t('craft-analytics', 'Analytics');
    }

    public static function icon(): ?string
    {
        return 'chart-line';
    }

    public static function isSelectable(): bool
    {
        return parent::isSelectable()
            && Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_VIEW);
    }

    public function getTitle(): ?string
    {
        return Craft::t('craft-analytics', 'Analytics');
    }

    public function getSubtitle(): ?string
    {
        return DateRange::presets()[$this->range] ?? null;
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

        $range = DateRange::fromPreset($this->range);
        $stats = Plugin::getInstance()->getStats();

        Craft::$app->getView()->registerAssetBundle(CpAsset::class);

        return Craft::$app->getView()->renderTemplate('craft-analytics/_widgets/overview.twig', [
            'totals' => $stats->totals($site->id, $range),
            'trend' => $stats->trend($site->id, $range),
            'range' => $range,
            'site' => $site,
        ]);
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('craft-analytics/_widgets/overview-settings.twig', [
            'widget' => $this,
            'ranges' => DateRange::presets(),
        ]);
    }

    /**
     * @return array<int,array<int|string,mixed>>
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['range'], 'in', 'range' => array_keys(DateRange::presets())],
        ]);
    }
}
