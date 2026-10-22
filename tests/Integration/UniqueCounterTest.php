<?php

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\tests\TestDb;
use coyshdigital\craftanalytics\uniques\ExactUniqueCounter;
use coyshdigital\craftanalytics\uniques\HllUniqueCounter;
use coyshdigital\craftanalytics\uniques\RedisUniqueCounter;
use coyshdigital\craftanalytics\uniques\UniqueCounterInterface;
use coyshdigital\craftanalytics\uniques\UniqueScope;

/**
 * Every driver must satisfy the same contract, above all: a range is the
 * union of its parts, never the sum.
 */
function hashesFor(int $from, int $to): array
{
    $hashes = [];
    for ($i = $from; $i <= $to; $i++) {
        $hashes[] = substr(hash('sha256', "visitor:$i"), 0, 16);
    }

    return $hashes;
}

function scope(string $date, int $dimId = 1): UniqueScope
{
    return new UniqueScope(UniqueScope::KIND_PAGE, 1, $date, -1, $dimId);
}

beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    TestDb::dropTables(SchemaBuilder::allTables());
    (new Install(['db' => TestDb::connection()]))->up();
});

/**
 * Records through a driver and reads the count back, handling the fact that
 * the sketch driver stores its state on the row while the others own theirs.
 */
function recordAndCount(UniqueCounterInterface $counter, array $scopesToHashes): int
{
    $sketches = [];

    foreach ($scopesToHashes as $entry) {
        /** @var UniqueScope $scope */
        [$scope, $hashes] = $entry;
        $key = $scope->key();
        $sketches[$key] = $counter->record($scope, $hashes, $sketches[$key] ?? null);
    }

    $scopes = array_map(static fn($entry) => $entry[0], $scopesToHashes);

    return $counter->estimate($scopes, array_filter($sketches));
}

test('counts distinct visitors on one day', function(callable $make) {
    $counter = $make();

    if ($counter === null) {
        $this->markTestSkipped('No Redis configured (CRAFT_ANALYTICS_TEST_REDIS_HOST).');
    }

    $count = recordAndCount($counter, [[scope('2026-07-16'), hashesFor(1, 200)]]);

    expect(abs($count - 200) / 200)->toBeLessThan(0.05);
})->with('counters');

test('a visitor recorded repeatedly counts once', function(callable $make) {
    $counter = $make();

    if ($counter === null) {
        $this->markTestSkipped('No Redis configured (CRAFT_ANALYTICS_TEST_REDIS_HOST).');
    }

    $same = array_fill(0, 50, substr(hash('sha256', 'visitor:1'), 0, 16));
    $count = recordAndCount($counter, [[scope('2026-07-16'), $same]]);

    expect($count)->toBe(1);
})->with('counters');

test('a range unions its scopes rather than summing them', function(callable $make) {
    $counter = $make();

    if ($counter === null) {
        $this->markTestSkipped('No Redis configured (CRAFT_ANALYTICS_TEST_REDIS_HOST).');
    }

    // Two days, 150 people each, 100 of whom visited on both. The truth is
    // 200 distinct; summing would claim 300.
    $count = recordAndCount($counter, [
        [scope('2026-07-15'), hashesFor(1, 150)],
        [scope('2026-07-16'), hashesFor(51, 200)],
    ]);

    expect(abs($count - 200) / 200)->toBeLessThan(0.05)
        ->and($count)->toBeLessThan(260);
})->with('counters');

test('separate scopes are counted separately', function(callable $make) {
    $counter = $make();

    if ($counter === null) {
        $this->markTestSkipped('No Redis configured (CRAFT_ANALYTICS_TEST_REDIS_HOST).');
    }

    $pageA = scope('2026-07-16', 1);
    $pageB = scope('2026-07-16', 2);

    $sketchA = $counter->record($pageA, hashesFor(1, 100), null);
    $counter->record($pageB, hashesFor(500, 600), null);

    $countA = $counter->estimate([$pageA], array_filter([$sketchA]));

    expect(abs($countA - 100) / 100)->toBeLessThan(0.05);
})->with('counters');

test('every driver states its accuracy honestly', function() {
    expect((new HllUniqueCounter(['settings' => new Settings(['hllPrecision' => 12])]))->accuracy())->toBe('±1.6%')
        ->and((new HllUniqueCounter(['settings' => new Settings(['hllPrecision' => 14])]))->accuracy())->toBe('±0.8%')
        ->and((new ExactUniqueCounter())->accuracy())->toBe('exact')
        ->and((new RedisUniqueCounter())->accuracy())->toBe('±0.8%');
});

test('the exact driver is the only one that keeps nothing on the rollup row', function() {
    // Redis used to answer false here, and its counters lived only in the
    // Redis database behind Craft's data cache - so a cache clear took every
    // unique figure with it. It now keeps the portable sketch on the row too.
    expect((new HllUniqueCounter())->storesOnRow())->toBeTrue()
        ->and((new ExactUniqueCounter())->storesOnRow())->toBeFalse()
        ->and((new RedisUniqueCounter())->storesOnRow())->toBeTrue();
});

test('the exact driver really is exact', function() {
    $counter = new ExactUniqueCounter(['db' => TestDb::connection(), 'settings' => new Settings()]);

    // Where the sketch says "about 1,000", this says 1,000.
    $count = recordAndCount($counter, [[scope('2026-07-16'), hashesFor(1, 1000)]]);

    expect($count)->toBe(1000);
});

test('the redis driver uses native HyperLogLog', function() {
    $connection = redisConnection();

    if ($connection === null) {
        $this->markTestSkipped('No Redis configured (CRAFT_ANALYTICS_TEST_REDIS_HOST).');
    }

    $counter = new RedisUniqueCounter(['redis' => $connection, 'settings' => new Settings()]);
    $scope = scope('2026-07-16');

    $counter->record($scope, hashesFor(1, 500), null);

    // Redis holds the counter, not the rollup row.
    $type = $connection->executeCommand('TYPE', ['ca:u:' . $scope->key()]);
    expect((string)$type)->toBe('string');

    $ttl = (int)$connection->executeCommand('TTL', ['ca:u:' . $scope->key()]);
    expect($ttl)->toBeGreaterThan(0);

    expect(abs($counter->estimate([$scope]) - 500) / 500)->toBeLessThan(0.05);
});

test('the redis driver also keeps a sketch on the row', function() {
    $connection = redisConnection();

    if ($connection === null) {
        $this->markTestSkipped('No Redis configured (CRAFT_ANALYTICS_TEST_REDIS_HOST).');
    }

    $counter = new RedisUniqueCounter(['redis' => $connection, 'settings' => new Settings()]);
    $scope = scope('2026-07-16');

    $blob = $counter->record($scope, hashesFor(1, 100), null);

    // The same visitors, in the portable encoding, readable without Redis.
    expect($blob)->not->toBeNull();

    $fromRow = (new HllUniqueCounter(['settings' => new Settings()]))->estimate([$scope], [$blob]);
    expect(abs($fromRow - 100) / 100)->toBeLessThan(0.05);

    // And a second write merges into it rather than starting over.
    $blob = $counter->record($scope, hashesFor(101, 200), $blob);
    $fromRow = (new HllUniqueCounter(['settings' => new Settings()]))->estimate([$scope], [$blob]);
    expect(abs($fromRow - 200) / 200)->toBeLessThan(0.05);
});

test('the redis driver survives its keys being flushed', function() {
    $connection = redisConnection();

    if ($connection === null) {
        $this->markTestSkipped('No Redis configured (CRAFT_ANALYTICS_TEST_REDIS_HOST).');
    }

    $counter = new RedisUniqueCounter(['redis' => $connection, 'settings' => new Settings()]);
    $day1 = scope('2026-07-15');
    $day2 = scope('2026-07-16');

    $sketch1 = $counter->record($day1, hashesFor(1, 150), null);
    $sketch2 = $counter->record($day2, hashesFor(51, 200), null);

    $before = $counter->estimate([$day1, $day2], [$sketch1, $sketch2]);
    expect(abs($before - 200) / 200)->toBeLessThan(0.05);

    // What `php craft clear-caches/data` does to the database the cache -
    // and these counters - live in. Twenty-six months of history used to
    // read zero from this moment on.
    $connection->executeCommand('FLUSHDB');
    expect((int)$connection->executeCommand('EXISTS', ['ca:u:' . $day1->key()]))->toBe(0);

    $after = $counter->estimate([$day1, $day2], [$sketch1, $sketch2]);

    // Answered from the rows: still a union, still within the sketch's error.
    expect(abs($after - 200) / 200)->toBeLessThan(0.05)
        ->and($after)->toBeLessThan(260);
});

test('a range with some keys missing is answered entirely from the rows', function() {
    $connection = redisConnection();

    if ($connection === null) {
        $this->markTestSkipped('No Redis configured (CRAFT_ANALYTICS_TEST_REDIS_HOST).');
    }

    $counter = new RedisUniqueCounter(['redis' => $connection, 'settings' => new Settings()]);
    $day1 = scope('2026-07-15');
    $day2 = scope('2026-07-16');

    $sketch1 = $counter->record($day1, hashesFor(1, 150), null);
    $sketch2 = $counter->record($day2, hashesFor(51, 200), null);

    // One key evicted, the other still there. A Redis HLL and a row sketch
    // cannot be merged with each other, so mixing the two per scope would
    // have to sum them - and 150 + 150 is not 200.
    $connection->executeCommand('DEL', ['ca:u:' . $day1->key()]);

    $count = $counter->estimate([$day1, $day2], [$sketch1, $sketch2]);

    expect(abs($count - 200) / 200)->toBeLessThan(0.05)
        ->and($count)->toBeLessThan(260);
});
