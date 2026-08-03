<?php

namespace coyshdigital\craftanalytics\charts;

use Craft;

/**
 * Shapes the JSON payload a chart is drawn from.
 *
 * This is deliberately not a Chart.js configuration builder. Templates emit
 * labels, datasets and a handful of plain options; every Chart.js option name
 * lives in ca-charts.js. Upgrading the library then touches one JavaScript file
 * rather than every template that happens to draw something, and PHP never has
 * to know what `plugins.legend.labels.boxWidth` is called this year.
 *
 * Colours do not appear here at all. A dataset carries a *token* - 'views',
 * 'series-3' - which the browser resolves against the `--ca-*` custom
 * properties in cp.css. That is what keeps the palette in one place.
 *
 * Everything is static and pure, which is the point: the interesting logic is
 * zero-filling, folding and share arithmetic, and all of it is testable without
 * a browser or a database.
 */
final class ChartData
{
    /**
     * The categorical ramp is four series plus a reserved grey for "Other".
     *
     * Not an arbitrary limit: a fifth distinct colour drops the worst-case
     * pairwise CIEDE2000 under simulated colour-vision deficiency from 14.14 to
     * 11.45, and the ramps that score better than that do it with near-black
     * shades that read as one dark smear. The header comment in cp.css records
     * the measurements. Charts fold rather than widen.
     */
    public const MAX_SERIES = 4;

    public const OTHER_TOKEN = 'series-5';

    /**
     * @param string[] $labels
     * @param array<int,array{label: string, data: array<int,int|float>, token?: string}> $datasets
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function line(array $labels, array $datasets, array $options = []): array
    {
        return self::payload('line', $labels, $datasets, $options);
    }

    /**
     * @param string[] $labels
     * @param array<int,array{label: string, data: array<int,int|float>, token?: string}> $datasets
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function stackedArea(array $labels, array $datasets, array $options = []): array
    {
        return self::payload('stackedArea', $labels, $datasets, $options);
    }

    /**
     * @param string[] $labels
     * @param array<int,int|float> $values
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function doughnut(array $labels, array $values, array $options = []): array
    {
        $payload = self::payload('doughnut', $labels, [[
            'label' => $options['label'] ?? '',
            'data' => array_values($values),
        ]], $options);

        // Computed here rather than in the browser so the tooltip and the
        // table's Share column cannot round to different numbers.
        $payload['shares'] = self::shares($values);

        return $payload;
    }

    /**
     * @param string[] $labels
     * @param array<int,array<string,mixed>> $datasets
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function payload(string $type, array $labels, array $datasets, array $options): array
    {
        $prepared = [];

        foreach (array_values($datasets) as $index => $dataset) {
            $prepared[] = [
                'label' => (string)($dataset['label'] ?? ''),
                'data' => array_values($dataset['data'] ?? []),
                'token' => (string)($dataset['token'] ?? self::seriesToken($index)),
                // Opt-in, per series. A wash under one line reads as volume;
                // a wash under four overlapping ones reads as mud.
                'fill' => (bool)($dataset['fill'] ?? false),
            ];
        }

        return [
            'type' => $type,
            'labels' => array_values($labels),
            'datasets' => $prepared,
            // Tooltips format numbers with this, so the canvas and the table
            // beneath it group digits the same way.
            'locale' => Craft::$app->language,
            'options' => array_diff_key($options, ['label' => null]),
        ];
    }

    /**
     * Axis and tooltip forms of the same labels, built together.
     *
     * They were previously derived in two separate places in trend.twig, which
     * is two places that can disagree about what a date is called.
     *
     * @param string[] $labels
     * @return array{axis: string[], full: string[]}
     */
    public static function axisLabels(array $labels, bool $hourly): array
    {
        $axis = [];
        $full = [];

        foreach (array_values($labels) as $label) {
            if ($hourly) {
                // Already "13:00"; there is no shorter honest form.
                $axis[] = $label;
                $full[] = $label;
                continue;
            }

            $time = strtotime($label);

            $axis[] = $time === false ? $label : date('j M', $time);
            $full[] = $time === false ? $label : date('j M Y', $time);
        }

        return ['axis' => $axis, 'full' => $full];
    }

    /**
     * The views-and-uniques payload, from a StatsService::trend() result.
     *
     * The uniques series is dropped entirely - not drawn flat at zero - when
     * there is nothing in it. An hourly range has no per-hour unique counts to
     * merge, and a line pinned along the axis reads as "nobody was unique"
     * rather than "this range cannot answer that".
     *
     * @param array{labels: string[], views: int[], uniques: int[], hourly: bool} $trend
     * @return array<string,mixed>
     */
    public static function trend(array $trend, ?string $viewsLabel = null, ?string $uniquesLabel = null): array
    {
        $labels = self::axisLabels($trend['labels'], $trend['hourly']);
        $hasUniques = $trend['uniques'] !== [] && max($trend['uniques']) > 0;

        $datasets = [[
            'label' => $viewsLabel ?? Craft::t('craft-analytics', 'Pageviews'),
            'data' => $trend['views'],
            'token' => 'views',
            // Views carry a soft wash beneath them, as they did when this was
            // hand-drawn SVG: it reads as volume, and it distinguishes the
            // primary series from the one measured against it.
            'fill' => true,
        ]];

        if ($hasUniques) {
            $datasets[] = [
                'label' => $uniquesLabel ?? Craft::t('craft-analytics', 'Unique visitors'),
                'data' => $trend['uniques'],
                'token' => 'uniques',
            ];
        }

        $payload = self::line($labels['axis'], $datasets);

        // The tooltip and the data table want "16 Jul 2026" where the axis only
        // has room for "16 Jul".
        $payload['full'] = $labels['full'];
        $payload['hasUniques'] = $hasUniques;

        return $payload;
    }

    /**
     * Rows of (key, date, value) into one zero-filled series per key.
     *
     * A key with no row on a given day gets 0 rather than being skipped: a gap
     * in a line reads as missing data, and "nobody arrived from Bing on Sunday"
     * is not missing data.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param string[] $dates
     * @return array<string,array<int,int>> key => values, indexed like $dates
     */
    public static function pivot(
        array $rows,
        array $dates,
        string $keyField,
        string $valueField,
        string $dateField = 'date',
    ): array {
        $dates = array_values($dates);
        $positions = array_flip($dates);
        $series = [];

        foreach ($rows as $row) {
            $key = (string)($row[$keyField] ?? '');
            $date = (string)($row[$dateField] ?? '');

            if (!isset($positions[$date])) {
                // Outside the requested window; the caller asked for $dates.
                continue;
            }

            $series[$key] ??= array_fill(0, count($dates), 0);
            $series[$key][$positions[$date]] += (int)($row[$valueField] ?? 0);
        }

        return $series;
    }

    /**
     * Keeps the largest series and sums the rest into one "Other".
     *
     * Twelve channels stacked on one axis is a smear. Four plus Other is a
     * chart, and Other is honest about what it is hiding.
     *
     * @param array<string,array<int,int>> $series
     * @return array<string,array<int,int>> ordered by total, "Other" last
     */
    public static function foldToTop(array $series, int $keep = self::MAX_SERIES, ?string $otherLabel = null): array
    {
        if ($series === []) {
            return [];
        }

        $totals = array_map(static fn(array $values): int => array_sum($values), $series);

        // Sort by total, then by key, so a tie cannot reorder the legend
        // between two requests for the same data.
        uksort($series, static function(string $a, string $b) use ($totals): int {
            return [$totals[$b], $a] <=> [$totals[$a], $b];
        });

        if (count($series) <= $keep) {
            return $series;
        }

        $kept = array_slice($series, 0, $keep, true);
        $folded = array_slice($series, $keep, null, true);

        $length = count(reset($series) ?: []);
        $other = array_fill(0, $length, 0);

        foreach ($folded as $values) {
            foreach ($values as $i => $value) {
                $other[$i] += $value;
            }
        }

        $kept[$otherLabel ?? Craft::t('craft-analytics', 'Other')] = $other;

        return $kept;
    }

    /**
     * The colour token for the nth series, wrapping within the ramp.
     *
     * Wraps rather than running out: a caller that ignores MAX_SERIES gets a
     * repeated colour, which is a legible chart with an ambiguity, rather than
     * an uncoloured series, which is a broken one.
     */
    public static function seriesToken(int $index): string
    {
        return 'series-' . (($index % self::MAX_SERIES) + 1);
    }

    /**
     * Each value's share of the total, as a percentage.
     *
     * @param array<int,int|float> $values
     * @return array<int,float>
     */
    public static function shares(array $values): array
    {
        $total = array_sum($values);

        if ($total <= 0) {
            return array_fill(0, count($values), 0.0);
        }

        return array_map(static fn($value): float => $value / $total * 100, array_values($values));
    }

    /**
     * Turns pivoted series into datasets, in order, with tokens assigned.
     *
     * @param array<string,array<int,int>> $series
     * @return array<int,array{label: string, data: array<int,int>, token: string}>
     */
    public static function datasets(array $series, ?string $otherLabel = null): array
    {
        $other = $otherLabel ?? Craft::t('craft-analytics', 'Other');
        $datasets = [];
        $index = 0;

        foreach ($series as $label => $values) {
            $datasets[] = [
                'label' => (string)$label,
                'data' => array_values($values),
                // "Other" always takes the reserved neutral, wherever it lands.
                'token' => (string)$label === $other ? self::OTHER_TOKEN : self::seriesToken($index),
            ];
            $index++;
        }

        return $datasets;
    }
}
