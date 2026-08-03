<?php

namespace coyshdigital\craftanalytics\variables;

use coyshdigital\craftanalytics\charts\ChartData;
use coyshdigital\craftanalytics\helpers\Sparkline;
use coyshdigital\craftanalytics\models\DateRange;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\services\StatsService;
use Craft;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;

/**
 * `craft.craftAnalytics` — the templates' way in.
 *
 * Two audiences share this object. The CP templates use the chart helpers and
 * the stats service; a site's own templates use the reporting methods to
 * build things like "most read this month".
 *
 * Everything here is read-only and aggregate. There is no method that can
 * return anything about an individual, because there is nothing about an
 * individual to return (C6) — so a front-end template calling this cannot
 * leak something the developer didn't realise they were holding.
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
    public function totals(?int $siteId = null, string $preset = DateRange::PRESET_30_DAYS): array
    {
        return $this->stats()->totals($this->siteId($siteId), DateRange::fromPreset($preset));
    }

    /**
     * Views for one entry.
     *
     *     {{ craft.craftAnalytics.views(entry)|number_format }} views
     *
     * Takes an element or an ID, because wherever you want this you usually
     * have the entry to hand already.
     */
    public function views(Entry|int $element, string $preset = DateRange::PRESET_30_DAYS, ?int $siteId = null): int
    {
        $elementId = $element instanceof Entry ? (int)$element->id : $element;

        return $this->stats()->elementStats(
            $this->siteId($siteId),
            $elementId,
            DateRange::fromPreset($preset),
        )['views'];
    }

    /**
     * The most-viewed pages: paths and counts.
     *
     * Use popularEntries() when you want something to render.
     *
     * @return array<int,array<string,mixed>>
     */
    public function popularPages(int $limit = 5, string $preset = DateRange::PRESET_30_DAYS, ?int $siteId = null): array
    {
        return $this->stats()->topPages($this->siteId($siteId), DateRange::fromPreset($preset), $limit);
    }

    /**
     * The most-read entries, as an entry query you can carry on refining.
     *
     *     {% for entry in craft.craftAnalytics.popularEntries(5, '7d').all() %}
     *
     * Comes back in view order and stays that way through `.all()`, so adding
     * `.section('blog')` filters the list without silently re-sorting it by
     * post date.
     *
     * Only entries that still exist come back: a "most read" list is a list
     * of things to click on, and a 404 is not one.
     *
     * @return EntryQuery<int,Entry>
     */
    public function popularEntries(
        int $limit = 5,
        string $preset = DateRange::PRESET_30_DAYS,
        ?int $siteId = null,
    ): EntryQuery {
        $siteId = $this->siteId($siteId);
        $ranked = $this->stats()->topElements($siteId, DateRange::fromPreset($preset), $limit * 4);

        $query = Entry::find()->siteId($siteId);

        if ($ranked === []) {
            // Nothing recorded yet. An empty query is the honest answer and
            // keeps the template's loop working instead of throwing.
            return $query->id(0);
        }

        // Over-fetched above, because some of the most-viewed elements will
        // since have been unpublished — asking for 5 should give 5 where 5
        // exist.
        return $query
            ->id(array_keys($ranked))
            ->fixedOrder(true)
            ->limit($limit);
    }

    /**
     * Whether this visitor has asked not to be tracked, for templates that
     * want to suppress their own consent prompt.
     *
     *     {% if not craft.craftAnalytics.gpcDetected %}{# show the banner #}{% endif %}
     */
    public function gpcDetected(): bool
    {
        $request = Craft::$app->getRequest();

        if (!$request instanceof \craft\web\Request) {
            return false;
        }

        return (string)$request->getHeaders()->get('sec-gpc') === '1';
    }

    // ----------------------------------------------------- CP chart helpers

    /**
     * The payload for the views-and-uniques chart, from a trend() result.
     *
     * Kept here rather than passed in by each controller so that
     * `{% include '_partials/trend.twig' %}` still works anywhere a `trend`
     * variable is in scope, which is how it was already being used.
     *
     * @param array{labels: string[], views: int[], uniques: int[], hourly: bool} $trend
     * @return array<string,mixed>
     */
    public function trendChart(array $trend): array
    {
        return ChartData::trend($trend);
    }

    /**
     * A sparkline path.
     *
     * Still server-rendered SVG, deliberately. The two callers - the entry
     * editor's sidebar and the dashboard widget - render on Craft's own
     * screens, where loading a charting library to draw a 200x30 line would be
     * indefensible. This needs no JavaScript at all, so nothing about it can
     * be broken by a script optimiser, a content security policy, or a
     * cpresources publishing failure.
     *
     * @param int[] $values
     */
    public function sparklinePath(array $values, float $width = 120, float $height = 28): string
    {
        return Sparkline::path($values, $width, $height);
    }

    /**
     * The site being asked about, defaulting to the current one (C8).
     */
    private function siteId(?int $siteId): int
    {
        return $siteId ?? (int)Craft::$app->getSites()->getCurrentSite()->id;
    }
}
