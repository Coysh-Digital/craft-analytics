<?php

use coyshdigital\craftanalytics\ingest\NonceRegistry;
use coyshdigital\craftanalytics\models\Settings;
use yii\caching\ArrayCache;

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

test('a recorded nonce is claimable exactly once', function() {
    $registry = registry();
    $nonce = $registry->issue();
    $registry->record($nonce);

    // First claim: this beacon belongs to the pageview PHP counted, so the
    // endpoint must not count it again.
    expect($registry->claim($nonce))->toBeTrue();

    // Second claim: the same nonce arriving again is a cached page being
    // served to somebody else — count it.
    expect($registry->claim($nonce))->toBeFalse();
});

test('an unknown nonce is not claimable — the cached-page case', function() {
    // A nonce baked into cached HTML that we never recorded (PHP never ran
    // for this visitor). Not claimable, so the beacon counts the view. This
    // is the under-count that server-only tracking suffers behind a cache.
    expect(registry()->claim('0123456789abcdef'))->toBeFalse();
});

test('a malformed or forged nonce is rejected without counting as a claim', function(string $nonce) {
    expect(registry()->claim($nonce))->toBeFalse();
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

    $registry->record($a);
    $registry->record($b);

    expect($registry->claim($a))->toBeTrue()
        ->and($registry->claim($b))->toBeTrue();
});

test('an expired nonce falls back to counting the view', function() {
    $cache = new ArrayCache();
    $registry = new NonceRegistry([
        'cache' => $cache,
        'settings' => new Settings(['nonceTtl' => 60]),
    ]);

    $nonce = $registry->issue();
    $registry->record($nonce);

    // Nothing here can distinguish "expired" from "cached page", so an
    // expiry means the view is counted — possibly twice, if the visitor
    // really did sit on a fresh page for longer than nonceTtl. That is the
    // documented trade-off, and why the default matches the session window.
    $cache->flush();

    expect($registry->claim($nonce))->toBeFalse();
});
