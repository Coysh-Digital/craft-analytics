<?php

use coyshdigital\craftanalytics\helpers\Chart;

test('axis ticks land on numbers people read', function(int $peak, array $expected) {
    // 0/20/40/60, never 0/23/46/69.
    expect(Chart::scale($peak)['ticks'])->toBe($expected);
})->with([
    [100, [0, 20, 40, 60, 80, 100]],
    [8, [0, 2, 4, 6, 8]],
    [420, [0, 100, 200, 300, 400, 500]],
]);

test('the axis does not leave the data stranded at the bottom', function(int $peak) {
    // A peak of 420 under an axis running to 600 wastes a third of the plot.
    $max = Chart::scale($peak)['max'];

    expect($max)->toBeGreaterThanOrEqual($peak)
        ->and($max)->toBeLessThanOrEqual((int)ceil($peak * 1.35));
})->with([100, 420, 600, 1234, 4700, 88000]);

test('an empty chart still has a scale', function() {
    $scale = Chart::scale(0);

    expect($scale['max'])->toBe(1)
        ->and($scale['ticks'])->toBe([0, 1]);
});

test('the scale always reaches the peak', function(int $peak) {
    expect(Chart::scale($peak)['max'])->toBeGreaterThanOrEqual($peak);
})->with([1, 7, 99, 101, 1000, 1234, 999999]);

test('a series becomes a path anchored to the baseline', function() {
    $geom = Chart::series([0, 5, 10], 100, 50, 10);

    expect($geom['line'])->toStartWith('M0 ')
        ->and($geom['points'])->toHaveCount(3)
        // Highest value sits at the top of the plot, lowest at the bottom.
        ->and($geom['points'][2]['y'])->toBeLessThan($geom['points'][0]['y'])
        ->and($geom['area'])->toEndWith('Z');
});

test('an empty series produces nothing rather than a broken path', function() {
    $geom = Chart::series([], 100, 50, 10);

    expect($geom['line'])->toBe('')
        ->and($geom['points'])->toBe([]);
});

test('a single point is centred rather than pinned to the left edge', function() {
    $geom = Chart::series([5], 100, 50, 10);

    expect($geom['points'][0]['x'])->toBe(50.0);
});

test('labels thin out so a long axis cannot collide with itself', function() {
    $labels = array_map(static fn(int $i) => "2026-07-$i", range(1, 90));
    $thinned = Chart::thinLabels($labels, 8);

    $shown = array_filter($thinned, static fn($l) => $l !== null);

    expect($thinned)->toHaveCount(90)
        ->and(count($shown))->toBeLessThanOrEqual(9)
        // The end of the range is always labelled.
        ->and($thinned[89])->not->toBeNull();
});

test('a short axis keeps every label', function() {
    $labels = ['a', 'b', 'c'];

    expect(Chart::thinLabels($labels, 8))->toBe($labels);
});

test('sparkline paths stay inside their box', function() {
    $path = Chart::sparkline([1, 9, 3, 7], 100, 20);

    expect($path)->toStartWith('M0 ')
        ->and($path)->toContain('L');

    preg_match_all('/[ML]([\d.]+) ([\d.]+)/', $path, $matches);

    foreach ($matches[2] as $y) {
        expect((float)$y)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(20.0);
    }
});

test('an empty sparkline renders nothing', function() {
    expect(Chart::sparkline([]))->toBe('');
});
