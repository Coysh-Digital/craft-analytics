<?php

use coyshdigital\craftanalytics\ingest\CaptureService;
use coyshdigital\craftanalytics\models\Settings;
use craft\elements\Entry;

/**
 * A site can leave whole entry sections out of the analytics: a members area,
 * internal notices. The decision is made on the element Craft matched the
 * request to, so these pin it without a Craft install behind them. The
 * section lookup (UID or handle to id) is supplied directly.
 */
function captureExcluding(array $sectionIds, array $setting = ['members']): CaptureService
{
    $capture = new CaptureService();
    $capture->settings = new Settings(['excludeSections' => $setting]);
    $capture->excludedSectionIds = $sectionIds;

    return $capture;
}

function entryInSection(?int $sectionId): Entry
{
    // Entry's constructor wants a booted Craft app; only sectionId matters here.
    $entry = (new ReflectionClass(Entry::class))->newInstanceWithoutConstructor();
    $entry->sectionId = $sectionId;

    return $entry;
}

test('an entry in an excluded section is excluded', function() {
    expect(captureExcluding([7])->isExcludedElement(entryInSection(7)))->toBeTrue();
});

test('an entry in any other section is still tracked', function() {
    expect(captureExcluding([7])->isExcludedElement(entryInSection(8)))->toBeFalse();
});

test('a request that matched no element is never excluded', function() {
    $capture = captureExcluding([7]);

    expect($capture->isExcludedElement(false))->toBeFalse()
        ->and($capture->isExcludedElement(null))->toBeFalse();
});

test('an entry with no section is never excluded', function() {
    expect(captureExcluding([7])->isExcludedElement(entryInSection(null)))->toBeFalse();
});

test('no excluded sections means nothing is excluded', function() {
    expect(captureExcluding([], [])->isExcludedElement(entryInSection(7)))->toBeFalse();
});
