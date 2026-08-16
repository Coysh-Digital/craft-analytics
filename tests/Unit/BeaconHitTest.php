<?php

use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\rollup\Aggregator;
use coyshdigital\craftanalytics\session\SessionDelta;

function beaconHit(bool $countView, int $dwellMs = 0, string $path = '/pricing'): Hit
{
    return new Hit(
        siteId: 1,
        path: $path,
        visitorHash: 'aaaaaaaaaaaaaaaa',
        sessionKey: 'k',
        timestamp: mktime(10, 0, 0, 7, 16, 2026),
        dwellMs: $dwellMs,
        countView: $countView,
    );
}

test('a hit round-trips its dwell and count flag through the spool', function() {
    $hit = beaconHit(false, 4200);
    $restored = Hit::decode($hit->encode());

    expect($restored->dwellMs)->toBe(4200)
        ->and($restored->countView)->toBeFalse();
});

test('the common case stays compact on the wire', function() {
    // countView is only serialised when false, and dwell only when non-zero,
    // so server-side capture pays nothing for either.
    $encoded = beaconHit(true, 0)->encode();

    expect($encoded)->not->toContain('nv')
        ->and($encoded)->not->toContain('"d"')
        // And a site with no segments pays nothing for those either: the
        // spool is written once per pageview, so every key costs I/O.
        ->and($encoded)->not->toContain('sg');
});

test('segments round-trip through the spool', function() {
    $hit = new Hit(
        siteId: 1,
        path: '/pricing',
        visitorHash: 'aaaaaaaaaaaaaaaa',
        sessionKey: 'k',
        timestamp: mktime(10, 0, 0, 7, 16, 2026),
        segments: ['plan' => 'pro', 'role' => 'member'],
    );

    expect(Hit::decode($hit->encode())->segments)->toBe(['plan' => 'pro', 'role' => 'member']);
});

test('a mangled spool line costs the segments on one hit, not the batch', function() {
    // The spool is a file; a truncated write or a hand-edit should degrade,
    // not throw somewhere in the middle of a drain.
    expect(Hit::fromArray(['si' => 1, 'v' => 'a', 'sg' => 'not-an-array'])->segments)->toBe([])
        ->and(Hit::fromArray(['si' => 1, 'v' => 'a', 'sg' => ['plan' => ['nested']]])->segments)->toBe([]);
});

test('a segment reaches the session it describes', function() {
    $delta = SessionDelta::fromHit(new Hit(
        siteId: 1,
        path: '/',
        visitorHash: 'aaaaaaaaaaaaaaaa',
        sessionKey: 'k',
        timestamp: mktime(10, 0, 0, 7, 16, 2026),
        segments: ['plan' => 'pro'],
    ));

    // A later hit adds a key that was missing, but never overwrites one that
    // is already there: somebody who signs in halfway through is counted as
    // whatever they were when they arrived.
    $delta->add(new Hit(
        siteId: 1,
        path: '/pricing',
        visitorHash: 'aaaaaaaaaaaaaaaa',
        sessionKey: 'k',
        timestamp: mktime(10, 1, 0, 7, 16, 2026),
        segments: ['plan' => 'free', 'role' => 'member'],
    ));

    expect($delta->segments)->toBe(['plan' => 'pro', 'role' => 'member']);
});

test('a dwell-only beacon adds time without adding a view', function() {
    $aggregator = new Aggregator(new DateTimeZone('UTC'), new Settings());

    // What hybrid mode produces: the server counted the view, and the beacon
    // arrives afterwards carrying only how long they stayed.
    $aggregator->add(beaconHit(true));
    $aggregator->add(beaconHit(false, 5000));

    $bucket = array_values($aggregator->buckets())[0];

    expect($bucket->views)->toBe(1)
        ->and($bucket->dwellMs)->toBe(5000);
});

test('a beacon for a cached page counts the view', function() {
    $aggregator = new Aggregator(new DateTimeZone('UTC'), new Settings());

    // No server-side hit at all: PHP never ran, so the beacon is the only
    // record of this pageview.
    $aggregator->add(beaconHit(true, 3000));

    $bucket = array_values($aggregator->buckets())[0];

    expect($bucket->views)->toBe(1)
        ->and($bucket->dwellMs)->toBe(3000);
});

test('dwell accumulates across a bucket while views count separately', function() {
    $aggregator = new Aggregator(new DateTimeZone('UTC'), new Settings());

    $aggregator->add(beaconHit(true));
    $aggregator->add(beaconHit(false, 1000));
    $aggregator->add(beaconHit(true));
    $aggregator->add(beaconHit(false, 2000));

    $bucket = array_values($aggregator->buckets())[0];

    expect($bucket->views)->toBe(2)
        ->and($bucket->dwellMs)->toBe(3000);
});

test('a dwell-only beacon keeps the session alive without inflating pageviews', function() {
    $delta = SessionDelta::fromHit(beaconHit(true));
    $delta->add(beaconHit(false, 5000));

    // Two hits, one pageview: the beacon is the same view being reported on,
    // not a second one.
    expect($delta->views)->toBe(1);
});

test('a session starting from a cached-page beacon still counts its view', function() {
    $delta = SessionDelta::fromHit(beaconHit(true, 3000));

    expect($delta->views)->toBe(1);
});

test('a session built only from dwell beacons has no pageviews', function() {
    // Shouldn't happen in practice (the server hit is spooled first), but it
    // must not invent a view if it does.
    $delta = SessionDelta::fromHit(beaconHit(false, 1000));

    expect($delta->views)->toBe(0);
});

test('event values are clamped at every boundary they cross', function() {
    // The clamp itself: rounding, both signs, and refusal of the non-finite.
    expect(Hit::clampEventValue('49.999'))->toBe(50.0)
        ->and(Hit::clampEventValue(1e20))->toBe(Hit::MAX_EVENT_VALUE)
        ->and(Hit::clampEventValue(-1e20))->toBe(-Hit::MAX_EVENT_VALUE)
        ->and(Hit::clampEventValue('not a number'))->toBeNull()
        // is_numeric admits '1e400', which floats to INF.
        ->and(Hit::clampEventValue('1e400'))->toBeNull()
        ->and(Hit::clampEventValue(null))->toBeNull();
});

test('a spool line cannot smuggle an oversized event value past the clamp', function() {
    // The spool is a file. A line written by hand — or by an older build with
    // a looser clamp — must come back within range, because whatever decodes
    // here is summed straight into a DECIMAL(14,2) column.
    $restored = Hit::fromArray([
        'si' => 1,
        'p' => '/checkout',
        'v' => 'aaaaaaaaaaaaaaaa',
        'k' => 'k',
        't' => mktime(10, 0, 0, 7, 16, 2026),
        'kd' => Hit::KIND_EVENT,
        'en' => 'purchase',
        'ev' => 1e20,
    ]);

    expect($restored->eventValue)->toBe(Hit::MAX_EVENT_VALUE);
});
