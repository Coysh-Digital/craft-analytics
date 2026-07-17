<?php

use coyshdigital\craftanalytics\enums\GoalType;
use coyshdigital\craftanalytics\models\Funnel;
use coyshdigital\craftanalytics\models\Goal;
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

function walked(array $goals, int $seconds = 1_000, int $maxScroll = 0): Session
{
    return new Session(
        siteId: 1,
        sessionKey: 'session-1',
        visitorHash: 'abcdef0123456789',
        startedAt: 1_000,
        lastSeenAt: 1_000 + $seconds,
        pageviews: count($goals),
        entryPath: '/',
        lastPath: '/',
        goals: $goals,
        maxScroll: $maxScroll,
    );
}

/**
 * The goal definitions a funnel's steps name.
 *
 * @param array<string,array{0: GoalType, 1: string}> $defs handle => [type, target]
 * @return array<string,Goal>
 */
function goalDefs(array $defs): array
{
    $goals = [];

    foreach ($defs as $handle => [$type, $target]) {
        $goal = new Goal();
        $goal->name = ucfirst($handle);
        $goal->handle = $handle;
        $goal->type = $type->value;
        $goal->target = $target;
        $goals[$handle] = $goal;
    }

    return $goals;
}

/** The three page goals the ordering tests use. */
function pageGoals(): array
{
    return goalDefs([
        'landed' => [GoalType::Url, '/blog'],
        'basket' => [GoalType::Url, '/basket'],
        'checkout' => [GoalType::Url, '/checkout'],
        'newsletter' => [GoalType::Event, 'newsletter'],
        'chat' => [GoalType::Event, 'chat'],
    ]);
}

$steps = ['landed', 'basket', 'checkout'];

test('a session that walked every step reaches the last one', function() use ($steps) {
    expect(funnel($steps)->reachedStep(walked(['landed', 'basket', 'checkout']), pageGoals()))->toBe(3);
});

test('a session that stopped part-way reaches only that far', function(array $goals, int $expected) use ($steps) {
    expect(funnel($steps)->reachedStep(walked($goals), pageGoals()))->toBe($expected);
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
    expect(funnel($steps)->reachedStep(walked(['checkout', 'landed', 'basket']), pageGoals()))->toBe(2);
});

test('a skipped step stops the count there', function() use ($steps) {
    // They reached checkout, but never the basket, so the funnel is broken at
    // step 2 and step 3 does not count.
    expect(funnel($steps)->reachedStep(walked(['landed', 'checkout']), pageGoals()))->toBe(1);
});

test('unrelated goals in between do not break the sequence', function() use ($steps) {
    expect(funnel($steps)->reachedStep(walked(['landed', 'newsletter', 'basket', 'chat', 'checkout']), pageGoals()))
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

test('a duration step gates the funnel without breaking the order', function() {
    // Duration and scroll are decided when the session ends, so they never
    // appear in the session's ordered goal list. Looking for them there meant
    // any funnel containing one died at that step and reported a completion
    // rate of zero - which is exactly what the seeded demo site showed:
    // 15,751 sessions reached step 1 of "Guide to quote" and not one reached
    // step 2.
    $funnel = funnel(['landed', 'engaged', 'checkout']);
    $goals = pageGoals() + goalDefs(['engaged' => [GoalType::Duration, '60']]);

    // Landed, stayed two minutes, checked out.
    expect($funnel->reachedStep(walked(['landed', 'checkout'], 120), $goals))->toBe(3);

    // Same journey, but they left after 30 seconds: the duration step gates
    // it, and step 3 is not credited.
    expect($funnel->reachedStep(walked(['landed', 'checkout'], 30), $goals))->toBe(1);
});

test('a scroll step gates the funnel too', function() {
    $funnel = funnel(['landed', 'readToBottom']);
    $goals = pageGoals() + goalDefs(['readToBottom' => [GoalType::Scroll, '100']]);

    expect($funnel->reachedStep(walked(['landed'], 60, 100), $goals))->toBe(2)
        ->and($funnel->reachedStep(walked(['landed'], 60, 50), $goals))->toBe(1);
});

test('a step naming a goal that no longer exists stops the walk', function() {
    // Guessing either way would invent a number.
    $funnel = funnel(['landed', 'deletedGoal', 'checkout']);

    expect($funnel->reachedStep(walked(['landed', 'checkout']), pageGoals()))->toBe(1);
});
