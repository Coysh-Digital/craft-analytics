<?php

use coyshdigital\craftanalytics\models\DateRange;

/** 16 July 2026, midday. */
const NOW = 1784217600;

function rangeAt(string $preset): DateRange
{
    return DateRange::fromPreset($preset, NOW);
}

test('presets resolve to the right window', function(string $preset, string $from, string $to) {
    $range = rangeAt($preset);

    expect($range->from)->toBe($from)
        ->and($range->to)->toBe($to);
})->with([
    'today' => [DateRange::PRESET_TODAY, '2026-07-16', '2026-07-16'],
    'yesterday' => [DateRange::PRESET_YESTERDAY, '2026-07-15', '2026-07-15'],
    // Inclusive of today, so "last 7 days" really is 7 days, not 8.
    '7 days' => [DateRange::PRESET_7_DAYS, '2026-07-10', '2026-07-16'],
    '30 days' => [DateRange::PRESET_30_DAYS, '2026-06-17', '2026-07-16'],
    '90 days' => [DateRange::PRESET_90_DAYS, '2026-04-18', '2026-07-16'],
]);

test('day counts match the label', function(string $preset, int $days) {
    expect(rangeAt($preset)->days())->toBe($days);
})->with([
    [DateRange::PRESET_TODAY, 1],
    [DateRange::PRESET_7_DAYS, 7],
    [DateRange::PRESET_30_DAYS, 30],
    [DateRange::PRESET_90_DAYS, 90],
]);

test('an unknown preset falls back to 30 days rather than failing', function() {
    $range = DateRange::fromPreset('../../etc/passwd', NOW);

    expect($range->preset)->toBe(DateRange::PRESET_30_DAYS)
        ->and($range->days())->toBe(30);
});

test('the previous period is the same length, immediately before', function() {
    $range = rangeAt(DateRange::PRESET_7_DAYS);
    $previous = $range->previous();

    expect($previous->to)->toBe('2026-07-09')
        ->and($previous->from)->toBe('2026-07-03')
        ->and($previous->days())->toBe($range->days());

    // No overlap — a comparison against a period that includes itself is
    // not a comparison.
    expect($previous->to)->toBeLessThan($range->from);
});

test('only a single day is plotted hourly', function() {
    expect(rangeAt(DateRange::PRESET_TODAY)->isHourly())->toBeTrue()
        ->and(rangeAt(DateRange::PRESET_YESTERDAY)->isHourly())->toBeTrue()
        ->and(rangeAt(DateRange::PRESET_7_DAYS)->isHourly())->toBeFalse();
});

test('every date in the range is listed, so quiet days plot as zero', function() {
    $dates = rangeAt(DateRange::PRESET_7_DAYS)->dates();

    // A gap in a line implies missing data; a quiet Sunday is not missing.
    expect($dates)->toHaveCount(7)
        ->and($dates[0])->toBe('2026-07-10')
        ->and($dates[6])->toBe('2026-07-16');
});

test('a year range spans a year', function() {
    $range = rangeAt(DateRange::PRESET_12_MONTHS);

    expect($range->from)->toBe('2025-07-17')
        ->and($range->to)->toBe('2026-07-16')
        ->and($range->days())->toBe(365);
});

test('a preset carries its own handle as the url param', function() {
    expect(rangeAt(DateRange::PRESET_7_DAYS)->param)->toBe(DateRange::PRESET_7_DAYS)
        ->and(rangeAt(DateRange::PRESET_7_DAYS)->isCustom())->toBeFalse();
});

test('a custom range round-trips through its url param', function() {
    $range = DateRange::fromParam('2026-01-01:2026-03-31', NOW);

    // Everything else depends on this: the param is rebuilt into every link on
    // the page, so a range that does not survive the round trip silently
    // resets itself on the first drill-down.
    expect($range->param)->toBe('2026-01-01:2026-03-31')
        ->and($range->from)->toBe('2026-01-01')
        ->and($range->to)->toBe('2026-03-31')
        ->and($range->preset)->toBe(DateRange::PRESET_CUSTOM)
        ->and($range->isCustom())->toBeTrue()
        ->and($range->days())->toBe(90);
});

test('fromParam resolves preset handles exactly as fromPreset does', function(string $preset) {
    $viaParam = DateRange::fromParam($preset, NOW);
    $viaPreset = DateRange::fromPreset($preset, NOW);

    expect($viaParam->from)->toBe($viaPreset->from)
        ->and($viaParam->to)->toBe($viaPreset->to)
        ->and($viaParam->preset)->toBe($viaPreset->preset);
})->with(array_keys(DateRange::presets()));

test('a malformed custom range falls back to 30 days rather than failing', function(string $token) {
    $range = DateRange::fromParam($token, NOW);

    expect($range->preset)->toBe(DateRange::PRESET_30_DAYS)
        ->and($range->days())->toBe(30);
})->with([
    'one date only' => ['2026-01-01'],
    'unpadded' => ['2026-1-1:2026-03-31'],
    // createFromFormat rolls this to 3 March unless the round trip catches it.
    'a day that does not exist' => ['2026-02-30:2026-03-01'],
    'a month that does not exist' => ['2026-13-01:2026-13-05'],
    'traversal on the left' => ['../../etc/passwd:2026-01-01'],
    'traversal on the right' => ['2026-01-01:../../etc/passwd'],
    'empty' => [''],
    'the discriminator on its own' => ['custom'],
    'three parts' => ['2026-01-01:2026-03-31:2026-04-01'],
]);

test('dates picked the wrong way round are swapped, not rejected', function() {
    $range = DateRange::fromParam('2026-03-31:2026-01-01', NOW);

    expect($range->from)->toBe('2026-01-01')
        ->and($range->to)->toBe('2026-03-31')
        ->and($range->isCustom())->toBeTrue();
});

test('a range ending in the future is clamped to today', function() {
    // Otherwise dates() emits a label per day to 2099 and trend() runs a
    // uniques query for each one, from a query string.
    $range = DateRange::fromParam('2026-07-01:2099-01-01', NOW);

    expect($range->to)->toBe('2026-07-16')
        ->and($range->from)->toBe('2026-07-01');
});

test('an over-long range is clamped to the maximum span, keeping its end', function() {
    $range = DateRange::fromParam('2020-01-01:2026-07-16', NOW);

    expect($range->days())->toBe(DateRange::MAX_CUSTOM_DAYS)
        ->and($range->to)->toBe('2026-07-16')
        ->and($range->from)->toBe('2025-06-12');
});

test('fromPreset cannot be handed a custom token', function() {
    // The property that keeps the scheduled report and the Overview widget
    // safe: they resolve through fromPreset(), so they are structurally
    // incapable of holding an absolute window that would freeze.
    $range = DateRange::fromPreset('2026-01-01:2026-03-31', NOW);

    expect($range->preset)->toBe(DateRange::PRESET_30_DAYS)
        ->and($range->days())->toBe(30);
});

test('custom is not a preset', function() {
    // presets() is the whitelist for Settings::reportPeriod, the Overview
    // widget and the report console command. If 'custom' ever appears here,
    // all three start accepting a value that resolves to nothing.
    expect(array_keys(DateRange::presets()))->not->toContain(DateRange::PRESET_CUSTOM);
});

test('isValidParam accepts presets and well-formed tokens, and nothing else', function() {
    expect(DateRange::isValidParam(DateRange::PRESET_7_DAYS))->toBeTrue()
        ->and(DateRange::isValidParam('2026-01-01:2026-03-31'))->toBeTrue()
        ->and(DateRange::isValidParam('2026-02-30:2026-03-01'))->toBeFalse()
        ->and(DateRange::isValidParam('custom'))->toBeFalse()
        ->and(DateRange::isValidParam('last week'))->toBeFalse();
});

test('the previous period of a custom range links to its own dates', function() {
    $range = DateRange::fromParam('2026-03-01:2026-03-31', NOW);
    $previous = $range->previous();

    expect($previous->from)->toBe('2026-01-29')
        ->and($previous->to)->toBe('2026-02-28')
        ->and($previous->days())->toBe($range->days())
        ->and($previous->param)->toBe('2026-01-29:2026-02-28')
        ->and($previous->to)->toBeLessThan($range->from);
});

test('a single custom day is still plotted hourly by the model', function() {
    // The model stays pure; whether hourly rows still exist for that day is
    // StatsService's business, not the range's.
    expect(DateRange::fromParam('2026-03-01:2026-03-01', NOW)->isHourly())->toBeTrue();
});
