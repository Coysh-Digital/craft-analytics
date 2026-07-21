<?php

/**
 * The segment registry: what a site declares, and what it therefore cannot
 * accidentally (or maliciously) record.
 *
 * The interesting cases are all refusals. Segments are the one place where a
 * site's own code writes into the dimensions table, and the beacon that
 * carries them is a public, forgeable endpoint - so what gets dropped matters
 * more than what gets through.
 */

use coyshdigital\craftanalytics\events\DefineSegmentsEvent;
use coyshdigital\craftanalytics\events\RegisterSegmentsEvent;
use coyshdigital\craftanalytics\models\SegmentDefinition;
use coyshdigital\craftanalytics\services\SegmentRegistry;
use coyshdigital\craftanalytics\tests\FakeRequest;

/**
 * A registry with the given segments already declared.
 *
 * @param SegmentDefinition[] $segments
 */
function segmentRegistry(array $segments, bool $isPro = true): SegmentRegistry
{
    $registry = new SegmentRegistry(['isPro' => $isPro]);

    $registry->on(
        SegmentRegistry::EVENT_REGISTER_SEGMENTS,
        static function(RegisterSegmentsEvent $event) use ($segments) {
            $event->segments = $segments;
        },
    );

    return $registry;
}

test('a declared segment is kept', function() {
    $registry = segmentRegistry([new SegmentDefinition(key: 'plan')]);

    expect($registry->filter(['plan' => 'pro'], false))->toBe(['plan' => 'pro']);
});

test('an undeclared key is dropped', function() {
    $registry = segmentRegistry([new SegmentDefinition(key: 'plan')]);

    expect($registry->filter(['email' => 'someone@example.com'], false))->toBe([]);
});

test('a value outside the declared list is dropped', function() {
    $registry = segmentRegistry([new SegmentDefinition(key: 'plan', values: ['free', 'pro'])]);

    expect($registry->filter(['plan' => 'enterprise'], false))->toBe([]);
});

test('a declared value keeps the casing it was declared in', function() {
    // So a template writing "Pro" and one writing "pro" are one row, not two,
    // and the report reads the way the site wrote it.
    $registry = segmentRegistry([new SegmentDefinition(key: 'plan', values: ['Free', 'Pro'])]);

    expect($registry->filter(['plan' => 'PRO'], false))->toBe(['plan' => 'Pro']);
});

test('an undeclared value list lower-cases instead', function() {
    $registry = segmentRegistry([new SegmentDefinition(key: 'plan')]);

    expect($registry->filter(['plan' => 'Pro'], false))->toBe(['plan' => 'pro']);
});

test('a long value is truncated rather than refused', function() {
    $registry = segmentRegistry([new SegmentDefinition(key: 'plan')]);
    $filtered = $registry->filter(['plan' => str_repeat('a', 500)], false);

    expect(strlen($filtered['plan']))->toBe(SegmentDefinition::MAX_VALUE_LENGTH);
});

test('an empty value is not a value', function() {
    $registry = segmentRegistry([new SegmentDefinition(key: 'plan')]);

    expect($registry->filter(['plan' => '   '], false))->toBe([]);
});

test('control characters are stripped out', function() {
    $registry = segmentRegistry([new SegmentDefinition(key: 'plan')]);

    expect($registry->filter(['plan' => "pr\x00o\x1f"], false))->toBe(['plan' => 'pro']);
});

test('the beacon cannot set a segment that did not opt into it', function() {
    // The default. A segment the server resolves is one the browser must not
    // be able to overrule, because anyone can post to that endpoint.
    $registry = segmentRegistry([new SegmentDefinition(key: 'plan', fromBeacon: false)]);

    expect($registry->filter(['plan' => 'pro'], true))->toBe([])
        ->and($registry->filter(['plan' => 'pro'], false))->toBe(['plan' => 'pro']);
});

test('the beacon can set one that did', function() {
    $registry = segmentRegistry([new SegmentDefinition(key: 'plan', fromBeacon: true)]);

    expect($registry->filter(['plan' => 'pro'], true))->toBe(['plan' => 'pro']);
});

test('a key that is not a usable key is never registered', function() {
    $registry = segmentRegistry([
        new SegmentDefinition(key: 'Plan With Spaces'),
        // A colon would make the stored `key:value` impossible to split again.
        new SegmentDefinition(key: 'pl:an'),
        new SegmentDefinition(key: str_repeat('a', 33)),
        new SegmentDefinition(key: 'plan'),
    ]);

    expect(array_keys($registry->all()))->toBe(['plan']);
});

test('lite declares nothing, whatever the site asks for', function() {
    // Gated in the registry rather than in the UI, so no route or event can
    // reach past it.
    $registry = segmentRegistry([new SegmentDefinition(key: 'plan')], isPro: false);

    expect($registry->all())->toBe([])
        ->and($registry->isEnabled())->toBeFalse()
        ->and($registry->filter(['plan' => 'pro'], false))->toBe([]);
});

test('no more than five segments ride on one hit', function() {
    $keys = ['a', 'b', 'c', 'd', 'e', 'f', 'g'];
    $registry = segmentRegistry(array_map(
        static fn(string $key) => new SegmentDefinition(key: $key, fromBeacon: true),
        $keys,
    ));

    $resolved = $registry->resolve(
        FakeRequest::make(),
        1,
        array_fill_keys($keys, 'x'),
    );

    // Declaration order, so which five survive is the site's choice rather
    // than PHP's hash ordering.
    expect(array_keys($resolved))->toBe(['a', 'b', 'c', 'd', 'e']);
});

test('the server has the last word over the beacon', function() {
    $registry = segmentRegistry([new SegmentDefinition(key: 'plan', fromBeacon: true)]);

    $registry->on(
        SegmentRegistry::EVENT_DEFINE_SEGMENTS,
        static function(DefineSegmentsEvent $event) {
            $event->segments['plan'] = 'free';
        },
    );

    expect($registry->resolve(FakeRequest::make(), 1, ['plan' => 'pro']))->toBe(['plan' => 'free']);
});

test('a site that declares nothing resolves nothing and never fires', function() {
    $registry = segmentRegistry([]);
    $fired = false;

    $registry->on(SegmentRegistry::EVENT_DEFINE_SEGMENTS, static function() use (&$fired) {
        $fired = true;
    });

    expect($registry->resolve(FakeRequest::make(), 1, ['plan' => 'pro']))->toBe([])
        ->and($fired)->toBeFalse();
});

test('a key and value survive a round trip through the dimension value', function() {
    $stored = SegmentRegistry::dimensionValue('plan', 'pro:with:colons');

    expect($stored)->toBe('plan:pro:with:colons')
        ->and(SegmentRegistry::splitDimensionValue($stored))->toBe(['plan', 'pro:with:colons']);
});

test('a label defaults to a readable version of the key', function() {
    expect((new SegmentDefinition(key: 'account_type'))->getLabel())->toBe('Account type')
        ->and((new SegmentDefinition(key: 'plan', label: 'Plan tier'))->getLabel())->toBe('Plan tier');
});
