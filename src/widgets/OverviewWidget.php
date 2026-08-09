<?php

namespace coyshdigital\craftanalytics\widgets;

use coyshdigital\craftanalytics\assets\CpAsset;
use coyshdigital\craftanalytics\helpers\SiteAccess;
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

    /**
     * Which headline figures to show, in the order they appear. Defaults to the
     * three the widget has always shown; the rest are opt-in so an existing
     * widget looks the same after an upgrade.
     *
     * @var string[]
     */
    public array $metrics = ['views', 'uniques', 'bounceRate'];

    /**
     * The figures the widget can show, keyed by the totals() array key. Kept
     * here so the settings screen, the body and the validation rule all read
     * from one list rather than three that drift apart.
     *
     * @return array<string,string> key => label
     */
    public static function metricLabels(): array
    {
        return [
            'views' => Craft::t('craft-analytics', 'Views'),
            'uniques' => Craft::t('craft-analytics', 'Visitors'),
            'sessions' => Craft::t('craft-analytics', 'Sessions'),
            'bounceRate' => Craft::t('craft-analytics', 'Bounce'),
            'avgViewsPerSession' => Craft::t('craft-analytics', 'Pages / session'),
            'avgDurationMs' => Craft::t('craft-analytics', 'Avg. session'),
            'avgDwellMs' => Craft::t('craft-analytics', 'Avg. time on page'),
        ];
    }

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

        // Scoped like every other analytics screen. Checking only the view
        // permission and then reading Craft's current site meant a user
        // restricted to one site could be shown another site's figures,
        // depending on what the CP happened to consider current.
        $site = SiteAccess::current();

        if ($site?->id === null) {
            return null;
        }

        $range = DateRange::fromPreset($this->range);
        $stats = Plugin::getInstance()->getStats();

        // CSS only, and deliberately no ChartsAsset: this renders on Craft's
        // own dashboard beside whatever else the user has put there, and the
        // sparkline below is server-rendered SVG for that reason. Adding
        // Chart.js here for consistency with the report screens would put 68 KB
        // of JavaScript on the dashboard to draw a 300x40 line.
        Craft::$app->getView()->registerAssetBundle(CpAsset::class);

        return Craft::$app->getView()->renderTemplate('craft-analytics/_widgets/overview.twig', [
            'totals' => $stats->totals($site->id, $range),
            'trend' => $stats->trend($site->id, $range),
            'range' => $range,
            'site' => $site,
            'metrics' => $this->metrics,
            'metricLabels' => self::metricLabels(),
        ]);
    }

    public function getSettingsHtml(): ?string
    {
        $options = [];

        foreach (self::metricLabels() as $value => $label) {
            $options[] = ['label' => $label, 'value' => $value];
        }

        return Craft::$app->getView()->renderTemplate('craft-analytics/_widgets/overview-settings.twig', [
            'widget' => $this,
            'ranges' => DateRange::presets(),
            'metricOptions' => $options,
        ]);
    }

    /**
     * @return array<int,array<int|string,mixed>>
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['range'], 'in', 'range' => array_keys(DateRange::presets())],
            [['metrics'], 'each', 'rule' => ['in', 'range' => array_keys(self::metricLabels())]],
        ]);
    }
}
