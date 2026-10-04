<?php

use coyshdigital\craftanalytics\helpers\Limits;

test('a limit is clamped at both ends', function() {
    expect(Limits::rows(10))->toBe(10)
        ->and(Limits::rows(200))->toBe(200)
        ->and(Limits::rows(201))->toBe(200)
        ->and(Limits::rows(PHP_INT_MAX))->toBe(200);
});

test('a negative or zero limit is one row, not every row', function() {
    // min() alone let -1 through, and Yii emits no LIMIT clause for a value
    // that is not a run of digits - so `limit: -1` on a public GraphQL schema
    // returned every path row the site had.
    expect(Limits::rows(-1))->toBe(1)
        ->and(Limits::rows(0))->toBe(1)
        ->and(Limits::rows(PHP_INT_MIN))->toBe(1);
});

test('a caller may lower the ceiling but not raise it past the default', function() {
    expect(Limits::rows(50, 10))->toBe(10)
        ->and(Limits::rows(5, 10))->toBe(5);
});
