<?php

use coyshdigital\craftanalytics\models\Settings;

test('default settings validate', function() {
    $settings = new Settings();

    expect($settings->validate())->toBeTrue()
        ->and($settings->trackingMode)->toBe(Settings::TRACKING_MODE_HYBRID)
        ->and($settings->writeDriver)->toBe(Settings::WRITE_DRIVER_SPOOL)
        ->and($settings->honourGpc)->toBeTrue()
        ->and($settings->honourDnt)->toBeFalse();
});

test('unknown tracking mode is rejected', function() {
    $settings = new Settings(['trackingMode' => 'telepathy']);

    expect($settings->validate())->toBeFalse()
        ->and($settings->getErrors('trackingMode'))->not->toBeEmpty();
});

test('unknown write driver is rejected', function() {
    $settings = new Settings(['writeDriver' => 'carrier-pigeon']);

    expect($settings->validate())->toBeFalse();
});

test('session window bounds are enforced', function(int $value, bool $valid) {
    $settings = new Settings(['sessionWindow' => $value]);

    expect($settings->validate())->toBe($valid);
})->with([
    'too short' => [30, false],
    'minimum' => [60, true],
    'default-ish' => [1800, true],
    'maximum' => [14400, true],
    'too long' => [14401, false],
]);

test('rollup retention cannot exceed the 26-month hard cap', function() {
    $settings = new Settings(['rollupRetentionMonths' => 27]);

    expect($settings->validate())->toBeFalse();
});

test('salt rotation interval cannot drop below an hour', function() {
    $settings = new Settings(['saltRotationInterval' => 60]);

    expect($settings->validate())->toBeFalse();
});

test('settings are populated from a config-file style array', function() {
    $settings = new Settings([
        'trackingMode' => 'server',
        'excludePaths' => ['/admin*', '/preview/*'],
        'dimensionCap' => 500,
    ]);

    expect($settings->validate())->toBeTrue()
        ->and($settings->trackingMode)->toBe('server')
        ->and($settings->excludePaths)->toBe(['/admin*', '/preview/*'])
        ->and($settings->dimensionCap)->toBe(500);
});
