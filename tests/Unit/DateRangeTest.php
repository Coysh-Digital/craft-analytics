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
