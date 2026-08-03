<?php

use coyshdigital\craftanalytics\helpers\Sparkline;

test('sparkline paths stay inside their box', function() {
    // Nothing clips an SVG path to its viewBox, so a point above the top or
    // below the baseline silently draws over whatever is beside it.
    $path = Sparkline::path([0, 50, 100, 25], 120, 28);

    preg_match_all('/[ML]([\d.]+) ([\d.]+)/', $path, $matches, PREG_SET_ORDER);

    expect($matches)->toHaveCount(4);

    foreach ($matches as $point) {
        expect((float)$point[2])->toBeGreaterThanOrEqual(0.0)
            ->and((float)$point[2])->toBeLessThanOrEqual(28.0);
    }
});

test('an empty sparkline renders nothing rather than a broken path', function() {
    expect(Sparkline::path([]))->toBe('');
});

test('a single point is centred rather than pinned to the left edge', function() {
    // Pinned left, one day's data reads as the start of a line going nowhere.
    expect(Sparkline::path([5], 100, 28))->toStartWith('M50 ');
});

test('a higher value sits higher up the box', function() {
    $path = Sparkline::path([0, 10], 100, 28);

    preg_match_all('/[ML]([\d.]+) ([\d.]+)/', $path, $matches, PREG_SET_ORDER);

    // SVG y grows downwards, so the larger value has the smaller y.
    expect((float)$matches[1][2])->toBeLessThan((float)$matches[0][2]);
});

test('an all-zero series is a flat line, not a division by zero', function() {
    $path = Sparkline::path([0, 0, 0], 100, 28);

    expect($path)->toBe('M0 27L50 27L100 27');
});
