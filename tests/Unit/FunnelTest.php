<?php

use coyshdigital\craftanalytics\models\Funnel;
use coyshdigital\craftanalytics\session\Session;

function funnel(array $steps, array $overrides = []): Funnel
{
    $funnel = new Funnel();
    $funnel->name = 'Checkout';
    $funnel->handle = 'checkout';
    $funnel->steps = $steps;
    $funnel->enabled = $overrides['enabled'] ?? true;
    $funnel->siteId = $overrides['siteId'] ?? null;

    return $funnel;
}

function walked(array $goals): Session
{
    return new Session(
        siteId: 1,
        sessionKey: 'session-1',
        visitorHash: 'abcdef0123456789',
        startedAt: 1_000,
        lastSeenAt: 2_000,
        pageviews: count($goals),
        entryPath: '/',
        lastPath: '/',
        goals: $goals,
    );
}

$steps = ['landed', 'basket', 'checkout'];

test('a session that walked every step reaches the last one', function() use ($steps) {
    expect(funnel($steps)->reachedStep(walked(['landed', 'basket', 'checkout'])))->toBe(3);
});

test('a session that stopped part-way reaches only that far', function(array $goals, int $expected) use ($steps) {
    expect(funnel($steps)->reachedStep(walked($goals)))->toBe($expected);
})->with([
    'never started' => [[], 0],
    'landed only' => [['landed'], 1],
    'got to the basket' => [['landed', 'basket'], 2],
    'all the way' => [['landed', 'basket', 'checkout'], 3],
]);

test('an early step 3 does not count if it happened before step 2', function() use ($steps) {
    // They hit checkout first, then landed, then the basket. Landing and
    // basketing did happen in order, so they genuinely reached step 2 — but
    // the checkout came *before* the basket, so it is not this funnel's step
    // 3. Crediting it would turn a broken flow into a healthy-looking one,
    // which is the entire question a funnel exists to answer.
    expect(funnel($steps)->reachedStep(walked(['checkout', 'landed', 'basket'])))->toBe(2);
});

test('a skipped step stops the count there', function() use ($steps) {
    // They reached checkout, but never the basket, so the funnel is broken at
    // step 2 and step 3 does not count.
    expect(funnel($steps)->reachedStep(walked(['landed', 'checkout'])))->toBe(1);
});

test('unrelated goals in between do not break the sequence', function() use ($steps) {
    expect(funnel($steps)->reachedStep(walked(['landed', 'newsletter', 'basket', 'chat', 'checkout'])))
        ->toBe(3);
});

test('a funnel needs at least two steps', function(array $steps, bool $valid) {
    expect(funnel($steps)->validate())->toBe($valid);
})->with([
    'none' => [[], false],
    'one' => [['landed'], false],
    'two' => [['landed', 'basket'], true],
]);

test('a funnel cannot use the same goal twice', function() {
    // The second occurrence could never match: the first consumed it, and
    // nothing after it can find a later one. Better to reject it at the form
    // than to ship a funnel with a permanently empty step.
    expect(funnel(['landed', 'basket', 'landed'])->validate())->toBeFalse();
});
