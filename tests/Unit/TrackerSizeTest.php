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

test('the tracker reports the pageview as it happens, not on the way out', function() {
    // It used to send one request, on the way out, which meant a cached page
    // was not counted until the visitor left: Real-time could not show anyone
    // still reading, and somebody who closed their laptop was never counted at
    // all. The pageview now goes on load.
    $source = trackerSource();

    // view() is called at the top level, not from an unload handler.
    expect($source)->toContain("\n    view();\n")
        ->and($source)->toContain('function view()');
});

test('the engagement beacon can never count a second view', function() {
    $source = trackerSource();

    // Two requests per pageview: the view, then time-on-page and scroll on
    // the way out. The second is flagged so the endpoint cannot mistake it
    // for another pageview.
    expect(substr_count($source, 'sendBeacon('))->toBe(2)
        ->and($source)->toContain("body.set('e', '1')")
        ->and($source)->toContain('if (leaving) {');
});

test('a page restored from bfcache is reported as a new view', function() {
    $source = trackerSource();

    // PHP did not run, so nobody else will count it. The nonce belongs to the
    // original delivery and must not be reused to claim this one.
    expect($source)->toContain('event.persisted')
        ->and($source)->toContain("nonce = '';");
});

test('the tracker degrades silently on browsers without sendBeacon', function() {
    expect(trackerSource())->toContain('!navigator.sendBeacon');
});
