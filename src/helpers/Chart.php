<?php

namespace coyshdigital\craftanalytics\helpers;

use craft\helpers\Html;

/**
 * Builds the geometry for the CP's charts.
 *
 * Server-rendered SVG: no charting library, no CDN, nothing to load (C7). The
 * only client-side JS is the hover layer, which is a few lines.
 */
final class Chart
{
    /**
     * Turns a series into an SVG path, plus the point coordinates the hover
     * layer needs.
     *
     * @param int[] $values
     * @return array{line: string, area: string, points: array<int,array{x: float, y: float}>}
     */
    public static function series(array $values, float $width, float $height, int $max, float $padTop = 6.0): array
    {
        $count = count($values);

        if ($count === 0) {
            return ['line' => '', 'area' => '', 'points' => []];
        }

        $max = max(1, $max);
        $usable = $height - $padTop;
        $step = $count > 1 ? $width / ($count - 1) : 0.0;

        $points = [];
        foreach (array_values($values) as $i => $value) {
            $points[] = [
                'x' => $count > 1 ? $i * $step : $width / 2,
                'y' => $padTop + $usable - ($value / $max * $usable),
            ];
        }

        $line = '';
        foreach ($points as $i => $point) {
            $line .= ($i === 0 ? 'M' : 'L') . self::n($point['x']) . ' ' . self::n($point['y']);
        }

        // Closed back along the baseline so the fill sits under the line.
        $area = $line
            . 'L' . self::n($points[count($points) - 1]['x']) . ' ' . self::n($height)
            . 'L' . self::n($points[0]['x']) . ' ' . self::n($height) . 'Z';

        return ['line' => $line, 'area' => $area, 'points' => $points];
    }

    /**
     * Round y-axis ticks — a scale that reads 0/25/50 rather than 0/23/46.
     *
     * @return array{max: int, ticks: int[]}
     */
    public static function scale(int $peak, int $tickCount = 4): array
    {
        if ($peak <= 0) {
            return ['max' => 1, 'ticks' => [0, 1]];
        }

        $rough = $peak / $tickCount;
        $magnitude = 10 ** floor(log10(max($rough, 1)));
        $normalized = $rough / $magnitude;

        // Steps people read easily: 1, 2, 5, 10 × a power of ten. The
        // thresholds are the midpoints between those steps rather than the
        // steps themselves — rounding 1.05 up to 2 would leave a peak of 420
        // sitting under an axis that runs to 600.
        $step = match (true) {
            $normalized <= 1.5 => 1,
            $normalized <= 3 => 2,
            $normalized <= 7 => 5,
            default => 10,
        }
        * $magnitude;

        $max = (int)(ceil($peak / $step) * $step);
        $ticks = [];

        for ($value = 0; $value <= $max; $value += (int)$step) {
            $ticks[] = $value;
        }

        return ['max' => max(1, $max), 'ticks' => $ticks];
    }

    /**
     * A sparkline path for the entry sidebar and index column.
     *
     * @param int[] $values
     */
    public static function sparkline(array $values, float $width = 120, float $height = 28): string
    {
        if ($values === []) {
            return '';
        }

        $max = max(1, max($values));
        $count = count($values);
        $step = $count > 1 ? $width / ($count - 1) : 0.0;
        $path = '';

        foreach (array_values($values) as $i => $value) {
            $x = $count > 1 ? $i * $step : $width / 2;
            $y = $height - ($value / $max * ($height - 2)) - 1;
            $path .= ($i === 0 ? 'M' : 'L') . self::n($x) . ' ' . self::n($y);
        }

        return $path;
    }

    /**
     * Every Nth label, so a 90-day axis doesn't collide with itself.
     *
     * @param string[] $labels
     * @return array<int,string|null> null where a label is skipped
     */
    public static function thinLabels(array $labels, int $maxLabels = 8): array
    {
        $count = count($labels);

        if ($count <= $maxLabels) {
            return $labels;
        }

        $every = (int)ceil($count / $maxLabels);
        $thinned = [];

        foreach (array_values($labels) as $i => $label) {
            // Anchor to the last point so the range's end is always labelled.
            $thinned[] = ($count - 1 - $i) % $every === 0 ? $label : null;
        }

        return $thinned;
    }

    /**
     * Short axis form: "16 Jul" for dates, untouched for anything else.
     */
    public static function shortDate(string $date): string
    {
        $time = strtotime($date);

        return $time === false ? $date : date('j M', $time);
    }

    public static function escape(string $value): string
    {
        return Html::encode($value);
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
