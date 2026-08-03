<?php

namespace coyshdigital\craftanalytics\charts;

/**
 * The day-of-week x hour-of-day grid.
 *
 * Deliberately not a Chart.js chart. Chart.js has no heatmap, and the
 * alternatives are worse than the thing they replace: a matrix plugin is a
 * second vendored library, a second version to pin and a second licence to
 * track, for one chart; and a scatter plot with large square points breaks on
 * resize by construction, because point radius is in pixels while category
 * spacing is not.
 *
 * What this actually is, is a table - 168 cells with a row header and a column
 * header. Rendering it as one gives keyboard navigation, header associations,
 * printing, text zoom and copy-paste for nothing, none of which a canvas has.
 */
final class Heatmap
{
    /** Shades above "nothing happened", which is bucket 0. */
    public const BUCKETS = 6;

    public const DAYS = 7;

    public const HOURS = 24;

    /**
     * @param array<int,array{date: string, hour: int, views: int}> $rows
     * @param int $weekStartDay 1 for Monday, 0 for Sunday
     * @return array{
     *     cells: array<int,array<int,array{views: int, bucket: int}>>,
     *     dayIndexes: array<int,int>,
     *     max: int,
     *     total: int,
     *     covered: bool
     * }
     */
    public static function grid(array $rows, int $weekStartDay = 1): array
    {
        $totals = array_fill(0, self::DAYS, array_fill(0, self::HOURS, 0));
        $max = 0;
        $total = 0;

        foreach ($rows as $row) {
            $hour = (int)$row['hour'];

            if ($hour < 0 || $hour >= self::HOURS) {
                // A compacted day (-1) has nothing to place on an hourly axis.
                continue;
            }

            $time = strtotime((string)$row['date']);

            if ($time === false) {
                continue;
            }

            $weekday = (int)date('w', $time);
            $views = (int)$row['views'];

            $totals[$weekday][$hour] += $views;
            $total += $views;
            $max = max($max, $totals[$weekday][$hour]);
        }

        // Rotated rather than re-keyed, so the caller can label rows from the
        // same order it iterates them in.
        $dayIndexes = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $dayIndexes[] = ($weekStartDay + $i) % self::DAYS;
        }

        $cells = [];
        foreach ($dayIndexes as $weekday) {
            $row = [];
            for ($hour = 0; $hour < self::HOURS; $hour++) {
                $views = $totals[$weekday][$hour];
                $row[] = ['views' => $views, 'bucket' => self::bucket($views, $max)];
            }
            $cells[] = $row;
        }

        return [
            'cells' => $cells,
            'dayIndexes' => $dayIndexes,
            'max' => $max,
            'total' => $total,
            // False means "there is nothing to draw", which the screen should
            // say in words rather than render as a grey grid that looks like a
            // week when nobody visited.
            'covered' => $total > 0,
        ];
    }

    /**
     * Which shade a cell gets, 0 (nothing) through BUCKETS.
     *
     * Square-rooted for the same reason the Locations map buckets its
     * countries: one dominant hour would otherwise flatten every other cell
     * into the palest shade, and the pattern - which is the entire point of a
     * heatmap - would disappear.
     */
    public static function bucket(int $value, int $max): int
    {
        if ($value <= 0 || $max <= 0) {
            return 0;
        }

        $share = sqrt($value / $max);

        return max(1, (int)round($share * self::BUCKETS));
    }
}
