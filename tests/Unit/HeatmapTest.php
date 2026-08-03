<?php

use coyshdigital\craftanalytics\charts\Heatmap;

test('the grid is always seven days by twenty-four hours', function() {
    // Rendered as a table, so a short row would misalign every column header
    // after it rather than simply drawing nothing.
    $grid = Heatmap::grid([]);

    expect($grid['cells'])->toHaveCount(7);

    foreach ($grid['cells'] as $day) {
        expect($day)->toHaveCount(24);
    }
});

test('an empty grid says so rather than looking like a quiet week', function() {
    $grid = Heatmap::grid([]);

    expect($grid['covered'])->toBeFalse()
        ->and($grid['total'])->toBe(0)
        ->and($grid['max'])->toBe(0);
});

test('views land on the right weekday and hour', function() {
    // 2026-07-15 is a Wednesday.
    $grid = Heatmap::grid([['date' => '2026-07-15', 'hour' => 9, 'views' => 42]], 1);

    // Week starts Monday, so Wednesday is the third row.
    expect($grid['cells'][2][9]['views'])->toBe(42)
        ->and($grid['total'])->toBe(42)
        ->and($grid['covered'])->toBeTrue();
});

test('the same weekday across two weeks accumulates', function() {
    // Two consecutive Wednesdays, same hour.
    $grid = Heatmap::grid([
        ['date' => '2026-07-08', 'hour' => 9, 'views' => 10],
        ['date' => '2026-07-15', 'hour' => 9, 'views' => 5],
    ], 1);

    expect($grid['cells'][2][9]['views'])->toBe(15);
});

test('a compacted day is skipped rather than placed at some hour', function() {
    // hour -1 is the compactor's daily row: its whole total sits on one row,
    // so there is no hour to attribute it to. Counting it anywhere would put
    // a day's traffic into a single cell.
    $grid = Heatmap::grid([
        ['date' => '2026-07-15', 'hour' => -1, 'views' => 900],
        ['date' => '2026-07-15', 'hour' => 9, 'views' => 5],
    ]);

    expect($grid['total'])->toBe(5);
});

test('starting the week on Sunday rotates the rows without losing one', function() {
    $rows = [['date' => '2026-07-15', 'hour' => 9, 'views' => 42]];

    $monday = Heatmap::grid($rows, 1);
    $sunday = Heatmap::grid($rows, 0);

    expect($monday['dayIndexes'])->toBe([1, 2, 3, 4, 5, 6, 0])
        ->and($sunday['dayIndexes'])->toBe([0, 1, 2, 3, 4, 5, 6])
        // Wednesday is row 2 from Monday, row 3 from Sunday.
        ->and($monday['cells'][2][9]['views'])->toBe(42)
        ->and($sunday['cells'][3][9]['views'])->toBe(42)
        ->and($monday['total'])->toBe($sunday['total']);
});

test('nothing gets the empty bucket, and the peak gets the darkest', function() {
    expect(Heatmap::bucket(0, 100))->toBe(0)
        ->and(Heatmap::bucket(100, 100))->toBe(Heatmap::BUCKETS);
});

test('any traffic at all is darker than none', function() {
    // Bucket 0 is "nothing happened". One view is not nothing, and rounding it
    // down to the empty shade would make a quiet hour indistinguishable from a
    // dead one.
    expect(Heatmap::bucket(1, 100000))->toBe(1);
});

test('buckets stay inside the palette', function(int $value, int $max) {
    $bucket = Heatmap::bucket($value, $max);

    expect($bucket)->toBeGreaterThanOrEqual(0)
        ->and($bucket)->toBeLessThanOrEqual(Heatmap::BUCKETS);
})->with([[1, 1], [50, 100], [99, 100], [1, 1000000], [7, 13]]);

test('a bucket of nothing out of nothing is not a division by zero', function() {
    expect(Heatmap::bucket(0, 0))->toBe(0)
        ->and(Heatmap::bucket(5, 0))->toBe(0);
});

test('shading is square-rooted so one peak hour does not flatten the rest', function() {
    // Linear shading would put a tenth of the peak in the palest bucket and
    // erase the pattern the heatmap exists to show.
    $tenth = Heatmap::bucket(10, 100);

    expect($tenth)->toBeGreaterThan(1)
        ->and($tenth)->toBeLessThan(Heatmap::BUCKETS);
});

test('an unparseable date is dropped rather than counted as a Thursday', function() {
    // strtotime() returning false must not fall through to weekday 0.
    $grid = Heatmap::grid([['date' => 'not a date', 'hour' => 9, 'views' => 99]]);

    expect($grid['total'])->toBe(0);
});
