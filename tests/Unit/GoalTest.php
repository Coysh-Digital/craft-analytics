<?php

use coyshdigital\craftanalytics\enums\GoalType;
use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\models\Goal;
use coyshdigital\craftanalytics\session\Session;

function goal(string $type, string $target, array $overrides = []): Goal
{
    $goal = new Goal();
    $goal->name = 'Test goal';
    $goal->handle = $overrides['handle'] ?? 'testGoal';
    $goal->type = $type;
    $goal->target = $target;
    $goal->value = $overrides['value'] ?? 0.0;
    $goal->enabled = $overrides['enabled'] ?? true;
    $goal->siteId = $overrides['siteId'] ?? null;

    return $goal;
}

function hit(array $overrides = []): Hit
{
    return new Hit(
        siteId: $overrides['siteId'] ?? 1,
        path: $overrides['path'] ?? '/',
        visitorHash: 'abcdef0123456789',
        sessionKey: 'session-1',
        timestamp: $overrides['timestamp'] ?? 1_700_000_000,
        elementId: $overrides['elementId'] ?? null,
        kind: $overrides['kind'] ?? Hit::KIND_VIEW,
        eventName: $overrides['eventName'] ?? null,
        scrollBucket: $overrides['scrollBucket'] ?? null,
    );
}

function session(array $overrides = []): Session
{
    return new Session(
        siteId: $overrides['siteId'] ?? 1,
        sessionKey: 'session-1',
        visitorHash: 'abcdef0123456789',
        startedAt: $overrides['startedAt'] ?? 1_700_000_000,
        lastSeenAt: $overrides['lastSeenAt'] ?? 1_700_000_000,
        pageviews: $overrides['pageviews'] ?? 1,
        entryPath: '/',
        lastPath: '/',
        goals: $overrides['goals'] ?? [],
        maxScroll: $overrides['maxScroll'] ?? 0,
    );
}

test('a page goal matches its path', function() {
    expect(goal(GoalType::Url->value, '/thank-you')->matchesHit(hit(['path' => '/thank-you'])))->toBeTrue();
});

test('a page goal ignores the query string', function() {
    // /thank-you?ref=email is the same page. A goal that missed it would be
    // quietly wrong in exactly the case somebody cares about — campaign traffic.
    expect(goal(GoalType::Url->value, '/thank-you')->matchesHit(hit(['path' => '/thank-you?ref=email'])))
        ->toBeTrue();
});

test('a page goal matches a wildcard', function(string $path, bool $expected) {
    expect(goal(GoalType::Url->value, '/checkout/*')->matchesHit(hit(['path' => $path])))->toBe($expected);
})->with([
    'a child' => ['/checkout/complete', true],
    'deeper' => ['/checkout/step/2', true],
    'the parent itself' => ['/checkout', false],
    'somewhere else' => ['/basket', false],
]);

test('an event goal matches only its own event', function() {
    $goal = goal(GoalType::Event->value, 'signup');

    expect($goal->matchesHit(hit(['kind' => Hit::KIND_EVENT, 'eventName' => 'signup'])))->toBeTrue()
        ->and($goal->matchesHit(hit(['kind' => Hit::KIND_EVENT, 'eventName' => 'download'])))->toBeFalse();
});

test('an event goal does not match a pageview of the same name', function() {
    // The kind matters: a page called /signup is not the signup event.
    expect(goal(GoalType::Event->value, 'signup')->matchesHit(hit(['path' => '/signup'])))->toBeFalse();
});

test('an entry goal matches the element', function() {
    $goal = goal(GoalType::Element->value, '42');

    expect($goal->matchesHit(hit(['elementId' => 42])))->toBeTrue()
        ->and($goal->matchesHit(hit(['elementId' => 43])))->toBeFalse()
        ->and($goal->matchesHit(hit(['elementId' => null])))->toBeFalse();
});

test('a disabled goal never matches', function() {
    expect(goal(GoalType::Url->value, '/thank-you', ['enabled' => false])->matchesHit(hit(['path' => '/thank-you'])))
        ->toBeFalse();
});

test('a site-scoped goal ignores other sites', function() {
    $goal = goal(GoalType::Url->value, '/thank-you', ['siteId' => 2]);

    expect($goal->matchesHit(hit(['path' => '/thank-you', 'siteId' => 2])))->toBeTrue()
        ->and($goal->matchesHit(hit(['path' => '/thank-you', 'siteId' => 1])))->toBeFalse();
});

test('duration and scroll goals are never decided per hit', function(string $type, string $target) {
    // They cannot be: neither is knowable until the session is over.
    expect(goal($type, $target)->isLive())->toBeFalse()
        ->and(goal($type, $target)->matchesHit(hit()))->toBeFalse();
})->with([
    'duration' => [GoalType::Duration->value, '30'],
    'scroll' => [GoalType::Scroll->value, '75'],
]);

test('a duration goal converts once the session is long enough', function(int $seconds, bool $expected) {
    $session = session(['startedAt' => 1_000, 'lastSeenAt' => 1_000 + $seconds]);

    expect(goal(GoalType::Duration->value, '30')->convertsAtClose($session))->toBe($expected);
})->with([
    'under' => [29, false],
    'exactly' => [30, true],
    'over' => [120, true],
]);

test('a scroll goal converts on the deepest bucket reached', function(int $reached, bool $expected) {
    expect(goal(GoalType::Scroll->value, '75')->convertsAtClose(session(['maxScroll' => $reached])))->toBe($expected);
})->with([
    'halfway' => [50, false],
    'exactly' => [75, true],
    'to the bottom' => [100, true],
    'never scrolled' => [0, false],
]);

test('a page goal is not re-decided at close', function() {
    // It was already decided when the hit arrived; deciding it twice would
    // double-count.
    expect(goal(GoalType::Url->value, '/thank-you')->convertsAtClose(session()))->toBeFalse();
});

test('a goal target that cannot mean anything is rejected', function(string $type, string $target) {
    expect(goal($type, $target)->validate())->toBeFalse();
})->with([
    'a path without a slash' => [GoalType::Url->value, 'thank-you'],
    'a non-numeric entry id' => [GoalType::Element->value, 'my-entry'],
    'a zero duration' => [GoalType::Duration->value, '0'],
    'an arbitrary scroll percentage' => [GoalType::Scroll->value, '63'],
    'a nameless event' => [GoalType::Event->value, '  '],
]);

test('a valid goal of every type passes validation', function(string $type, string $target) {
    expect(goal($type, $target)->validate())->toBeTrue();
})->with([
    'page' => [GoalType::Url->value, '/thank-you'],
    'wildcard page' => [GoalType::Url->value, '/checkout/*'],
    'event' => [GoalType::Event->value, 'signup'],
    'entry' => [GoalType::Element->value, '42'],
    'duration' => [GoalType::Duration->value, '30'],
    'scroll' => [GoalType::Scroll->value, '75'],
]);

test('a session carries goal handles, not the pages that matched them', function() {
    // The C2 property in one assertion: session state is bounded by the number
    // of goals, not by how much the visitor browsed.
    $session = session(['goals' => ['signup', 'purchase'], 'maxScroll' => 75]);
    $round = Session::fromArray($session->toArray());

    expect($round->goals)->toBe(['signup', 'purchase'])
        ->and($round->maxScroll)->toBe(75)
        ->and($session->toArray())->not->toHaveKey('paths');
});
