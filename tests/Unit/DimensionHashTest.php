<?php

use coyshdigital\craftanalytics\services\DimensionsService;

test('value hash is a 16-char hex string', function() {
    $hash = DimensionsService::valueHash('/some/path');

    expect($hash)->toMatch('/^[0-9a-f]{16}$/');
});

test('value hash is deterministic and collision-averse on simple inputs', function() {
    expect(DimensionsService::valueHash('/a'))
        ->toBe(DimensionsService::valueHash('/a'))
        ->not->toBe(DimensionsService::valueHash('/b'));
});

test('normalize truncates to the storage column length', function() {
    $long = str_repeat('a', 600);

    expect(mb_strlen(DimensionsService::normalize($long)))->toBe(500)
        ->and(DimensionsService::normalize('/short'))->toBe('/short');
});
