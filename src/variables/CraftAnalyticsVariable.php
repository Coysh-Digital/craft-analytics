<?php

namespace coyshdigital\craftanalytics\variables;

use coyshdigital\craftanalytics\helpers\Chart;
use coyshdigital\craftanalytics\models\DateRange;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\services\StatsService;

/**
 * `craft.craftAnalytics` — the CP templates' access to stats and chart
 * geometry, and (from phase 9) the front-end Twig API.
 */
class CraftAnalyticsVariable
{
    public function stats(): StatsService
    {
        return Plugin::getInstance()->getStats();
    }

    /**
     * @return array{views: int, uniques: int, sessions: int, bounceRate: float, avgDurationMs: int, avgViewsPerSession: float, avgDwellMs: int}
     */
    public function totals(int $siteId, string $preset = DateRange::PRESET_30_DAYS): array
    {
        return $this->stats()->totals($siteId, DateRange::fromPreset($preset));
    }

    /**
     * @param int[] $values
     * @return array{line: string, area: string, points: array<int,array{x: float, y: float}>}
     */
    public function series(array $values, float $width, float $height, int $max): array
    {
        return Chart::series($values, $width, $height, $max);
    }

    /**
     * @return array{max: int, ticks: int[]}
     */
    public function scale(int $peak, int $tickCount = 4): array
    {
        return Chart::scale($peak, $tickCount);
    }

    /**
     * @param int[] $values
     */
    public function sparkline(array $values, float $width = 120, float $height = 28): string
    {
        return Chart::sparkline($values, $width, $height);
    }

    /**
     * @param string[] $labels
     * @return array<int,string|null>
     */
    public function thinLabels(array $labels, int $maxLabels = 8): array
    {
        return Chart::thinLabels($labels, $maxLabels);
    }

    public function shortDate(string $date): string
    {
        return Chart::shortDate($date);
    }
}
