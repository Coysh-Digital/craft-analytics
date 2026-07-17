<?php

use coyshdigital\craftanalytics\ingest\NonceRegistry;
use coyshdigital\craftanalytics\models\Settings;
use yii\caching\ArrayCache;

const VISITOR_A = 'aaaaaaaaaaaaaaaa';
const VISITOR_B = 'bbbbbbbbbbbbbbbb';

function registry(?ArrayCache $cache = null): NonceRegistry
{
    return new NonceRegistry([
        'cache' => $cache ?? new ArrayCache(),
        'settings' => new Settings(),
    ]);
}

test('an issued nonce is opaque and unique', function() {
    $registry = registry();
    $seen = [];

    for ($i = 0; $i < 1000; $i++) {
        $nonce = $registry->issue();
        expect($nonce)->toMatch('/^[0-9a-f]{16}$/');
        $seen[$nonce] = true;
    }

    expect($seen)->toHaveCount(1000);
});

test('the visitor PHP rendered for can claim their nonce once', function() {
    $registry = registry();
    $nonce = $registry->issue();
    $registry->record($nonce, VISITOR_A);

    // Their beacon belongs to the pageview PHP counted, so the endpoint must
    // not count it again.
    expect($registry->claim($nonce, VISITOR_A))->toBeTrue();

    // The same beacon arriving twice is a replay, not a second view.
    expect($registry->claim($nonce, VISITOR_A))->toBeFalse();
});

test('another visitor on the same cached page is counted, not swallowed', function() {
    // This is the whole reason the key includes the visitor. A cached page
    // hands the same nonce to everybody. Keyed on the nonce alone, B's beacon
    // claims the record PHP made for A, is told "already counted", and B's
    // view disappears - which is exactly what happened on a Blitz-cached page
    // whose cache had been warmed by something without JavaScript: the first
    // real visitor showed up in Real-time with zero pageviews.
    $registry = registry();
    $nonce = $registry->issue();
    $registry->record($nonce, VISITOR_A);

    expect($registry->claim($nonce, VISITOR_B))->toBeFalse()
        // And A can still claim their own afterwards: B did not consume it.
        ->and($registry->claim($nonce, VISITOR_A))->toBeTrue();
});

test('a nonce nobody ever claims does not swallow the next visitor', function() {
    // PHP rendered the page for a visitor with no JavaScript - a curl, or a
    // cache-warming crawler - so their nonce is never claimed. Everybody
    // served the cached copy must still be counted.
    $registry = registry();
    $nonce = $registry->issue();
    $registry->record($nonce, VISITOR_A);

    expect($registry->claim($nonce, VISITOR_B))->toBeFalse()
        ->and($registry->claim($nonce, 'cccccccccccccccc'))->toBeFalse()
        ->and($registry->claim($nonce, 'dddddddddddddddd'))->toBeFalse();
});

test('an unknown nonce is not claimable — the cached-page case', function() {
    // A nonce baked into cached HTML that we never recorded for this visitor.
    // Not claimable, so the beacon counts the view.
    expect(registry()->claim('0123456789abcdef', VISITOR_A))->toBeFalse();
});

test('a malformed or forged nonce is rejected without counting as a claim', function(string $nonce) {
    expect(registry()->claim($nonce, VISITOR_A))->toBeFalse();
})->with([
    'empty' => '',
    'not hex' => 'zzzzzzzzzzzzzzzz',
    'too short' => 'abc123',
    'too long' => '0123456789abcdef0123456789abcdef',
    'injection attempt' => "abc'; DROP TABLE--",
]);

test('nonces are independent of one another', function() {
    $registry = registry();
    $a = $registry->issue();
    $b = $registry->issue();

    $registry->record($a, VISITOR_A);
    $registry->record($b, VISITOR_A);

    expect($registry->claim($a, VISITOR_A))->toBeTrue()
        ->and($registry->claim($b, VISITOR_A))->toBeTrue();
});

test('an expired nonce falls back to counting the view', function() {
    $cache = new ArrayCache();
    $registry = new NonceRegistry([
        'cache' => $cache,
        'settings' => new Settings(['nonceTtl' => 60]),
    ]);

    $nonce = $registry->issue();
    $registry->record($nonce, VISITOR_A);

    // Nothing here can distinguish "expired" from "cached page", so an expiry
    // means the view is counted - possibly twice, if PHP had already counted
    // it. The beacon now goes on load rather than on the way out, so the gap
    // it has to survive is milliseconds and this is far harder to hit than it
    // was.
    $cache->flush();

    expect($registry->claim($nonce, VISITOR_A))->toBeFalse();
});
