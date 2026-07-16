<?php

/**
 * C3: the tracker is ≤ 2 KB gzipped, has no dependencies, and cannot move the
 * page.
 *
 * A size budget only means something if it fails the build, so it lives in
 * the test suite rather than in a document nobody reads.
 */

const TRACKER_PATH = __DIR__ . '/../../src/resources/js/tracker.js';
const BUDGET_BYTES = 2048;

function trackerSource(): string
{
    return (string)file_get_contents(TRACKER_PATH);
}

/** What a browser actually downloads, which is the only size that matters. */
function trackerGzippedBytes(): int
{
    return strlen((string)gzencode(trackerSource(), 9));
}

test('the tracker is within the 2 KB gzipped budget', function() {
    $bytes = trackerGzippedBytes();

    expect($bytes)->toBeLessThanOrEqual(BUDGET_BYTES, sprintf(
        'tracker.js is %d bytes gzipped, over the %d byte budget (C3).',
        $bytes,
        BUDGET_BYTES,
    ));
});

test('the tracker has no dependencies and loads nothing else', function() {
    $source = trackerSource();

    // Zero dependencies is a hard requirement: the tracker must never be a
    // reason a site makes a request it didn't intend (C7).
    expect($source)->not->toContain('import ')
        ->and($source)->not->toContain('require(')
        ->and($source)->not->toContain('//cdn')
        ->and($source)->not->toContain('http://')
        ->and($source)->not->toContain('https://');
});

test('the tracker never touches device storage', function() {
    $source = trackerSource();

    // C4: nothing is read from or written to the visitor's device until
    // consent exists — and in Lite, never.
    expect($source)->not->toContain('localStorage')
        ->and($source)->not->toContain('sessionStorage')
        ->and($source)->not->toContain('document.cookie')
        ->and($source)->not->toContain('indexedDB');
});

test('the tracker cannot write to the page', function() {
    $source = trackerSource();

    // No layout shift is only credible if the script has no way to render
    // anything (C3).
    expect($source)->not->toContain('document.write')
        ->and($source)->not->toContain('innerHTML')
        ->and($source)->not->toContain('createElement')
        ->and($source)->not->toContain('appendChild');
});

test('the tracker sends exactly one request per pageview', function() {
    $source = trackerSource();

    // One sendBeacon call, guarded by a latch that is only released when the
    // page comes back from bfcache — i.e. when it is genuinely a new view.
    expect(substr_count($source, 'sendBeacon('))->toBe(1)
        ->and($source)->toContain('if (sent) {')
        ->and($source)->toContain('event.persisted');
});

test('the tracker degrades silently on browsers without sendBeacon', function() {
    expect(trackerSource())->toContain('!navigator.sendBeacon');
});
