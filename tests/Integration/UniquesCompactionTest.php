<?php

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\DateRange;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\rollup\Compactor;
use coyshdigital\craftanalytics\services\StatsService;
use coyshdigital\craftanalytics\tests\TestDb;
use coyshdigital\craftanalytics\uniques\UniqueCounterInterface;
use coyshdigital\craftanalytics\uniques\UniqueScope;
use yii\db\Query;

/**
 * Compaction must not cost a day its unique visitors.
 *
 * Nightly compaction rewrites 24 hourly rollup rows into one row with
 * `hour = -1`, and every reader builds the scope it asks for **from the stored
 * row**. So from the moment a day compacts, readers ask for `…:-1:…`.
 *
 * The sketch driver survives that because its state is the blob on the row the
 * compactor merges. The other two do not: they recorded under the real hours
 * and have nothing at all under the daily key. `PFCOUNT` on a key Redis has
 * never seen returns 0, and so does a `scopeKey IN (…)` that matches no rows -
 * so every unique figure older than `hourlyWindowDays` (7 by default) silently
 * read zero, on the driver `auto` picks whenever the cache is Redis.
 *
 * The existing driver tests never caught it because they build every scope
 * with `hour = -1`, so record and estimate always agreed. These go through the
 * real cycle instead: record hourly, compact, then read back the way
 * StatsService does.
 */
beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    TestDb::dropTables(SchemaBuilder::allTables());
    (new Install(['db' => TestDb::connection()]))->up();
});

const COMPACTED_DAY = '2026-06-01';
const COMPACTION_NOW = 1784548800; // 2026-07-16 12:00 UTC, well past the window
// A 30-day range that contains the compacted day, read the way a report does.
const READ_NOW = 1781784000; // 2026-06-15 12:00 UTC

function compactionVisitors(int $from, int $to): array
{
    $hashes = [];
    for ($i = $from; $i <= $to; $i++) {
        $hashes[] = substr(hash('sha256', "visitor:$i"), 0, 16);
    }

    return $hashes;
}

/**
 * Records a page bucket the way DbRollupSink does: a rollup row for the hour,
 * plus the driver's own counters under that hour's scope.
 *
 * @param string[] $hashes
 */
function recordHour(UniqueCounterInterface $counter, int $hour, array $hashes, int $pathDimId = 1): void
{
    $db = TestDb::connection();
    $scope = new UniqueScope(UniqueScope::KIND_PAGE, 1, COMPACTED_DAY, $hour, $pathDimId);

    $blob = $counter->record($scope, $hashes, null);

    $db->createCommand()->insert(Table::PAGES_ROLLUP, [
        'siteId' => 1,
        'date' => COMPACTED_DAY,
        'hour' => $hour,
        'pathDimId' => $pathDimId,
        'views' => count($hashes),
        'uniques' => $blob === null ? null : new yii\db\PdoValue($blob, PDO::PARAM_LOB),
    ])->execute();
}

function compactWith(UniqueCounterInterface $counter): void
{
    (new Compactor([
        'db' => TestDb::connection(),
        'settings' => new Settings(['hourlyWindowDays' => 7]),
        'counter' => $counter,
    ]))->run(COMPACTION_NOW);
}

/** Reads uniques back through the real read path, not a hand-built scope. */
function readUniques(UniqueCounterInterface $counter, ?int $pathDimId = null): int
{
    return (new StatsService(['db' => TestDb::connection(), 'counter' => $counter]))
        ->uniquesFor(1, DateRange::fromPreset(DateRange::PRESET_30_DAYS, READ_NOW), $pathDimId);
}

test('a compacted day still counts its unique visitors', function(callable $make) {
    $counter = $make();

    if ($counter === null) {
        $this->markTestSkipped('Driver not configured for this run.');
    }

    // 150 distinct people spread over three hours, with the same 50 coming
    // back in the middle hour - so the honest answer is 150, and summing the
    // hours would say 200.
    recordHour($counter, 9, compactionVisitors(1, 50));
    recordHour($counter, 10, compactionVisitors(1, 50));
    recordHour($counter, 11, compactionVisitors(51, 150));

    expect(readUniques($counter))->toBeGreaterThan(140)->toBeLessThan(160);

    compactWith($counter);

    // One row left, at the daily hour - and the figure has to survive it.
    expect((new Query())->from(Table::PAGES_ROLLUP)->count('*', TestDb::connection()))->toEqual(1);

    expect(readUniques($counter))
        ->toBeGreaterThan(140, 'uniques were lost when the day compacted')
        ->toBeLessThan(160);
})->with('counters');

test('compacting twice does not change the count', function(callable $make) {
    $counter = $make();

    if ($counter === null) {
        $this->markTestSkipped('Driver not configured for this run.');
    }

    recordHour($counter, 9, compactionVisitors(1, 40));
    recordHour($counter, 10, compactionVisitors(20, 60));

    compactWith($counter);
    $first = readUniques($counter);

    // The fold is a union, so re-running it must be a no-op rather than a
    // double-count - which is what makes it safe inside a transaction.
    compactWith($counter);

    expect(readUniques($counter))->toBe($first);
})->with('counters');

test('separate paths keep separate counts through compaction', function(callable $make) {
    $counter = $make();

    if ($counter === null) {
        $this->markTestSkipped('Driver not configured for this run.');
    }

    // Different people on each path. If compaction folded them onto one scope
    // key, both paths would report the union of the two.
    recordHour($counter, 9, compactionVisitors(1, 30), pathDimId: 1);
    recordHour($counter, 10, compactionVisitors(31, 90), pathDimId: 2);

    compactWith($counter);

    expect(readUniques($counter, 1))->toBeGreaterThan(25)->toBeLessThan(36)
        ->and(readUniques($counter, 2))->toBeGreaterThan(54)->toBeLessThan(66);
})->with('counters');
