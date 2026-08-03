<?php

use coyshdigital\craftanalytics\charts\ChartData;

test('a series with no row for a day is zero there, not absent', function() {
    // A gap in a line reads as "we lost the data". A zero reads as "nobody
    // came", which is what actually happened.
    $series = ChartData::pivot(
        [
            ['channel' => 'Organic', 'date' => '2026-07-01', 'sessions' => 5],
            ['channel' => 'Organic', 'date' => '2026-07-03', 'sessions' => 7],
        ],
        ['2026-07-01', '2026-07-02', '2026-07-03'],
        'channel',
        'sessions',
    );

    expect($series['Organic'])->toBe([5, 0, 7]);
});

test('every pivoted series is as long as the range, in the range order', function() {
    $dates = ['2026-07-01', '2026-07-02', '2026-07-03'];

    $series = ChartData::pivot(
        [
            ['channel' => 'Direct', 'date' => '2026-07-03', 'sessions' => 1],
            ['channel' => 'Social', 'date' => '2026-07-01', 'sessions' => 2],
        ],
        $dates,
        'channel',
        'sessions',
    );

    expect($series['Direct'])->toBe([0, 0, 1])
        ->and($series['Social'])->toBe([2, 0, 0]);
});

test('pivoting nothing produces nothing rather than a row of empty series', function() {
    expect(ChartData::pivot([], ['2026-07-01'], 'channel', 'sessions'))->toBe([]);
});

test('a row outside the requested range is dropped, not folded into an edge', function() {
    // Silently adding it to the first or last bucket would put traffic on a
    // day it did not happen.
    $series = ChartData::pivot(
        [
            ['channel' => 'Direct', 'date' => '2026-06-30', 'sessions' => 99],
            ['channel' => 'Direct', 'date' => '2026-07-01', 'sessions' => 1],
        ],
        ['2026-07-01', '2026-07-02'],
        'channel',
        'sessions',
    );

    expect($series['Direct'])->toBe([1, 0]);
});

test('two rows for the same key and day are summed', function() {
    $series = ChartData::pivot(
        [
            ['channel' => 'Direct', 'date' => '2026-07-01', 'sessions' => 3],
            ['channel' => 'Direct', 'date' => '2026-07-01', 'sessions' => 4],
        ],
        ['2026-07-01'],
        'channel',
        'sessions',
    );

    expect($series['Direct'])->toBe([7]);
});

test('the smallest series fold into one Other, which sorts last', function() {
    $folded = ChartData::foldToTop([
        'a' => [10, 10],
        'b' => [8, 8],
        'c' => [6, 6],
        'd' => [4, 4],
        'e' => [2, 2],
        'f' => [1, 1],
    ]);

    expect(array_keys($folded))->toBe(['a', 'b', 'c', 'd', 'Other'])
        // e and f, both periods.
        ->and($folded['Other'])->toBe([3, 3]);
});

test('a chart with room for every series does not grow an Other', function() {
    $folded = ChartData::foldToTop(['a' => [1], 'b' => [2], 'c' => [3]]);

    expect(array_keys($folded))->toBe(['c', 'b', 'a'])
        ->and($folded)->not->toHaveKey('Other');
});

test('series are ordered largest first', function() {
    $folded = ChartData::foldToTop(['small' => [1], 'big' => [100], 'mid' => [50]]);

    expect(array_keys($folded))->toBe(['big', 'mid', 'small']);
});

test('series with equal totals keep a stable order', function() {
    // Otherwise the legend and the stacking order can reshuffle between two
    // loads of the same report, which reads as a bug in the data.
    $first = ChartData::foldToTop(['b' => [5], 'a' => [5], 'c' => [5]]);
    $second = ChartData::foldToTop(['c' => [5], 'b' => [5], 'a' => [5]]);

    expect(array_keys($first))->toBe(array_keys($second))
        ->and(array_keys($first))->toBe(['a', 'b', 'c']);
});

test('folding nothing produces nothing', function() {
    expect(ChartData::foldToTop([]))->toBe([]);
});

test('Other always takes the reserved neutral, wherever it sits', function() {
    $datasets = ChartData::datasets(ChartData::foldToTop([
        'a' => [10], 'b' => [8], 'c' => [6], 'd' => [4], 'e' => [2],
    ]));

    $other = end($datasets);

    expect($other['label'])->toBe('Other')
        ->and($other['token'])->toBe(ChartData::OTHER_TOKEN);
});

test('series tokens wrap rather than running out', function() {
    // A caller that ignores MAX_SERIES gets a repeated colour - a legible
    // chart with an ambiguity - rather than an uncoloured series.
    expect(ChartData::seriesToken(0))->toBe('series-1')
        ->and(ChartData::seriesToken(3))->toBe('series-4')
        ->and(ChartData::seriesToken(4))->toBe('series-1');
});

test('the categorical ramp is exactly as wide as the validated palette', function() {
    // cp.css records the colour-vision measurements for five colours: four
    // series plus the reserved Other. If this number moves, that claim has to
    // be re-measured, not just re-typed.
    expect(ChartData::MAX_SERIES)->toBe(4)
        ->and(ChartData::OTHER_TOKEN)->toBe('series-5');
});

test('dates get a short axis form and a full tooltip form together', function() {
    $labels = ChartData::axisLabels(['2026-07-16', '2026-07-17'], false);

    expect($labels['axis'])->toBe(['16 Jul', '17 Jul'])
        ->and($labels['full'])->toBe(['16 Jul 2026', '17 Jul 2026']);
});

test('hourly labels are left alone in both forms', function() {
    $labels = ChartData::axisLabels(['00:00', '13:00'], true);

    expect($labels['axis'])->toBe(['00:00', '13:00'])
        ->and($labels['full'])->toBe(['00:00', '13:00']);
});

test('a label that is not a date survives unchanged', function() {
    $labels = ChartData::axisLabels(['not a date'], false);

    expect($labels['axis'])->toBe(['not a date']);
});

test('shares add up to a hundred', function() {
    $shares = ChartData::shares([25, 25, 50]);

    expect(array_sum($shares))->toEqualWithDelta(100.0, 0.0001)
        ->and($shares)->toBe([25.0, 25.0, 50.0]);
});

test('shares of nothing are zero rather than a division by zero', function() {
    expect(ChartData::shares([0, 0]))->toBe([0.0, 0.0])
        ->and(ChartData::shares([]))->toBe([]);
});

test('a payload carries colour tokens and never a colour', function() {
    // The regression test for keeping the palette in one place: the day a hex
    // appears in a payload, it has been duplicated out of cp.css.
    $payload = ChartData::line(['Mon', 'Tue'], [
        ['label' => 'Pageviews', 'data' => [1, 2], 'token' => 'views'],
        ['label' => 'Unique visitors', 'data' => [1, 1]],
    ]);

    expect($payload['type'])->toBe('line')
        ->and($payload['datasets'][0]['token'])->toBe('views')
        ->and($payload['datasets'][1]['token'])->toBe('series-2')
        ->and(json_encode($payload))->not->toMatch('/#[0-9a-fA-F]{6}/');
});

test('a doughnut carries the shares the tooltip will read', function() {
    $payload = ChartData::doughnut(['Desktop', 'Mobile'], [75, 25]);

    expect($payload['type'])->toBe('doughnut')
        ->and($payload['shares'])->toBe([75.0, 25.0])
        ->and($payload['datasets'][0]['data'])->toBe([75, 25]);
});

test('a stacked area is a distinct type the browser can dispatch on', function() {
    $payload = ChartData::stackedArea(['Mon'], [['label' => 'Organic', 'data' => [3]]]);

    expect($payload['type'])->toBe('stackedArea');
});

test('every payload names the locale, so canvas and table format alike', function() {
    $payload = ChartData::line(['Mon'], [['label' => 'Pageviews', 'data' => [1]]]);

    expect($payload['locale'])->toBe(Craft::$app->language);
});
