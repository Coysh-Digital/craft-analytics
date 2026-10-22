<?php

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\DateRange;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\services\GcService;
use coyshdigital\craftanalytics\services\StatsService;
use coyshdigital\craftanalytics\tests\TestDb;
use coyshdigital\craftanalytics\uniques\ExactUniqueCounter;
use coyshdigital\craftanalytics\uniques\UniqueScope;
use yii\db\Query;

/**
 * Garbage collection must not take away what the exact driver still needs.
 *
 * The exact driver keeps nothing on the rollup row: its unique figure is the
 * membership table, and a day is read by COUNT(DISTINCT) over it. GC used to
 * drop those rows two salt rotations after they were written - two days - on
 * the reasoning that hashes under a destroyed salt cannot be matched to
 * anything. Compaction then copied the day's hourly scopes into its daily one
 * after `hourlyWindowDays` (7), found nothing to copy, and every day older than
 * about three days read zero unique visitors.
 *
 * UniquesCompactionTest runs the compactor on its own and so never saw it.
 * These go through GcService::run(), which is what the nightly cron runs.
 */
beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    TestDb::dropTables(SchemaBuilder::allTables());
    (new Install(['db' => TestDb::connection()]))->up();

    $this->counter = new ExactUniqueCounter(['db' => TestDb::connection(), 'settings' => new Settings()]);
});

const GC_EXACT_DAY = '2026-06-01';

/** 04:00 UTC, the default GC hour, $nights after the day in question. */
function gcExactNight(int $nights): int
{
    return (int)strtotime(GC_EXACT_DAY . ' 04:00:00 UTC') + $nights * 86400;
}

function gcExactVisitors(int $from, int $to): array
{
    $hashes = [];
    for ($i = $from; $i <= $to; $i++) {
        $hashes[] = substr(hash('sha256', "visitor:$i"), 0, 16);
    }

    return $hashes;
}

/** Records an hourly page row the way DbRollupSink does on this driver. */
function gcExactRecordHour(ExactUniqueCounter $counter, int $hour, array $hashes): void
{
    $counter->record(new UniqueScope(UniqueScope::KIND_PAGE, 1, GC_EXACT_DAY, $hour, 1), $hashes, null);

    TestDb::connection()->createCommand()->insert(Table::PAGES_ROLLUP, [
        'siteId' => 1,
        'date' => GC_EXACT_DAY,
        'hour' => $hour,
        'pathDimId' => 1,
        'views' => count($hashes),
        'uniques' => null,
    ])->execute();
}

function gcExactRun(ExactUniqueCounter $counter, int $now): array
{
    return (new GcService([
        'db' => TestDb::connection(),
        'settings' => new Settings(['hourlyWindowDays' => 7]),
        'counter' => $counter,
    ]))->run($now);
}

/** Reads the day back through the real report path, as of $now. */
function gcExactRead(ExactUniqueCounter $counter, int $now): int
{
    return (new StatsService(['db' => TestDb::connection(), 'counter' => $counter, 'settings' => new Settings()]))
        ->uniquesFor(1, DateRange::fromPreset(DateRange::PRESET_30_DAYS, $now));
}

test('a day keeps its unique visitors through the nights before it compacts', function() {
    // 150 distinct people over three hours, 50 of them seen twice.
    gcExactRecordHour($this->counter, 9, gcExactVisitors(1, 50));
    gcExactRecordHour($this->counter, 10, gcExactVisitors(1, 50));
    gcExactRecordHour($this->counter, 11, gcExactVisitors(51, 150));

    expect(gcExactRead($this->counter, gcExactNight(0)))->toBe(150);

    // Three nights on: the salt behind these hashes is long gone, and the day
    // is still held at hourly grain. This is where the rows used to vanish.
    gcExactRun($this->counter, gcExactNight(3));

    expect(gcExactRead($this->counter, gcExactNight(3)))
        ->toBe(150, 'the membership rows were deleted before the day compacted');

    // Eight nights on the day compacts to one row, and is still counted.
    gcExactRun($this->counter, gcExactNight(8));

    expect((new Query())->from(Table::PAGES_ROLLUP)->count('*', TestDb::connection()))->toEqual(1)
        ->and(gcExactRead($this->counter, gcExactNight(8)))->toBe(150);
});

test('compaction leaves one membership row per visitor and path, not one per hour', function() {
    gcExactRecordHour($this->counter, 9, gcExactVisitors(1, 50));
    gcExactRecordHour($this->counter, 10, gcExactVisitors(1, 50));
    gcExactRecordHour($this->counter, 11, gcExactVisitors(1, 50));

    expect((new Query())->from(Table::UNIQUE_MEMBERS)->count('*', TestDb::connection()))->toEqual(150);

    gcExactRun($this->counter, gcExactNight(8));

    // The hourly scopes are gone; the daily one holds each visitor once.
    expect((new Query())->from(Table::UNIQUE_MEMBERS)->count('*', TestDb::connection()))->toEqual(50);
});

test('membership rows age out with the rollups they count for', function() {
    gcExactRecordHour($this->counter, 9, gcExactVisitors(1, 50));

    // Past rollupRetentionMonths (26): the day and its members go together.
    $pastRetention = (int)strtotime('2028-09-01 04:00:00 UTC');
    gcExactRun($this->counter, $pastRetention);

    expect((new Query())->from(Table::PAGES_ROLLUP)->count('*', TestDb::connection()))->toEqual(0)
        ->and((new Query())->from(Table::UNIQUE_MEMBERS)->count('*', TestDb::connection()))->toEqual(0);
});
