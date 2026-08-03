<?php

namespace coyshdigital\craftanalytics\helpers;

/**
 * The one chart the control panel still draws in PHP.
 *
 * Every other chart is Chart.js. These two are not, and the reason is where
 * they render: the entry editor's sidebar and the dashboard widget are Craft's
 * screens, not the plugin's. Loading a charting library onto them to draw a
 * 200x30 line would put ~68 KB of JavaScript on a page the plugin is a guest
 * on, for a graphic with no axes, no legend and no interaction.
 *
 * Being server-rendered also makes these the only two charts that cannot fail:
 * no script to be deferred by an optimiser, blocked by a content security
 * policy, or lost to a cpresources publishing problem.
 *
 * Extracted from the old Chart helper, which Chart.js replaced. This is all of
 * it that was worth keeping.
 */
final class Sparkline
{
    /**
     * An SVG path 'd' string, drawn within the given box.
     *
     * @param int[] $values
     */
    public static function path(array $values, float $width = 120, float $height = 28): string
    {
        if ($values === []) {
            return '';
        }

        $max = max(1, max($values));
        $count = count($values);
        $step = $count > 1 ? $width / ($count - 1) : 0.0;
        $path = '';

        foreach (array_values($values) as $i => $value) {
            // A single point sits in the middle rather than pinned to the left
            // edge, where it would read as the start of a line going nowhere.
            $x = $count > 1 ? $i * $step : $width / 2;
            $y = $height - ($value / $max * ($height - 2)) - 1;
            $path .= ($i === 0 ? 'M' : 'L') . self::n($x) . ' ' . self::n($y);
        }

        return $path;
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
