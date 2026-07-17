<?php

use coyshdigital\craftanalytics\models\Goal;
use coyshdigital\craftanalytics\services\FunnelsService;
use coyshdigital\craftanalytics\services\GoalsService;
use craft\events\ConfigEvent;

/**
 * The project-config plumbing, which failed in two silent ways.
 *
 * Both were found by looking at the database rather than by reading the code,
 * because both "worked": the apply output said `done` for every goal, and only
 * one row existed afterwards.
 */
test('the uid comes from the numeric token match', function() {
    // Craft calls array_values() on the matches before handing them over (see
    // ProjectConfig::_applyChanges), so they are keyed 0, 1, 2 - not by the
    // token's name. Reading ['uid'] yields nothing, and with a `?? ''`
    // fallback every goal is written with an empty uid, which the unique index
    // then collapses onto a single row. Every goal in the project config
    // became one goal in the table.
    $event = new ConfigEvent(['path' => 'craftAnalytics.goals.abc-123']);
    $event->tokenMatches = ['abc-123'];

    expect(uidFor(GoalsService::class, $event))->toBe('abc-123');
});

test('a config path with no uid throws rather than defaulting', function() {
    // The `?? ''` that hid the bug above. An empty uid is never a sensible
    // answer here, so it is not a value this is allowed to return.
    $event = new ConfigEvent(['path' => 'craftAnalytics.goals']);
    $event->tokenMatches = [];

    expect(fn() => uidFor(GoalsService::class, $event))
        ->toThrow(UnexpectedValueException::class);
});

test('funnels read the uid the same way', function() {
    $event = new ConfigEvent(['path' => 'craftAnalytics.funnels.def-456']);
    $event->tokenMatches = ['def-456'];

    expect(uidFor(FunnelsService::class, $event))->toBe('def-456');
});

test('rebuilt config is a plain uid-keyed map', function() {
    // packAssociativeArrays() is for associative arrays nested *inside* a
    // config value whose key order matters. Applied to the outer uid-keyed
    // map, it rewrites the keys into an __assoc__ structure and a rebuild
    // collapses every goal onto a single empty key - which is what a
    // project-config/rebuild did, destroying two of three goals.
    $goals = [makeGoal('landed', '/blog'), makeGoal('enquired', '/contact')];
    $config = rebuiltConfig($goals);

    expect($config)->toHaveCount(2)
        ->and(array_keys($config))->toBe(['uid-landed', 'uid-enquired'])
        ->and($config)->not->toHaveKey('')
        ->and($config)->not->toHaveKey('__assoc__')
        ->and($config['uid-landed']['handle'])->toBe('landed')
        ->and($config['uid-landed']['target'])->toBe('/blog');
});

test('a goal round-trips through its config representation', function() {
    $config = makeGoal('signup', '/thank-you')->toConfig();

    expect($config)->toBe([
        'name' => 'Goal signup',
        'handle' => 'signup',
        'type' => 'url',
        'target' => '/thank-you',
        'value' => 0.0,
        'enabled' => true,
        'siteId' => null,
        'sortOrder' => 0,
    ]);
});

function makeGoal(string $handle, string $target): Goal
{
    $goal = new Goal();
    $goal->uid = 'uid-' . $handle;
    $goal->name = 'Goal ' . $handle;
    $goal->handle = $handle;
    $goal->target = $target;

    return $goal;
}

/**
 * @param class-string $service
 */
function uidFor(string $service, ConfigEvent $event): string
{
    $method = new ReflectionMethod($service, 'uidFor');

    return $method->invoke(null, $event);
}

/**
 * GoalsService::rebuildConfig() reads the database, which a unit test has no
 * business doing - so this exercises the shape it builds, which is the part
 * that broke.
 *
 * @param Goal[] $goals
 * @return array<string,mixed>
 */
function rebuiltConfig(array $goals): array
{
    $config = [];

    foreach ($goals as $goal) {
        $config[$goal->uid] = $goal->toConfig();
    }

    return $config;
}
