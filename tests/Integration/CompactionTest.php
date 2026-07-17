<?php

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\rollup\Compactor;
use coyshdigital\craftanalytics\services\GcService;
use coyshdigital\craftanalytics\tests\TestDb;
use coyshdigital\craftanalytics\uniques\Hll;
use yii\db\Query;

beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    $db = TestDb::connection();
    TestDb::dropTables(SchemaBuilder::allTables());
    (new Install(['db' => $db]))->up();

    $this->settings = new Settings(['hourlyWindowDays' => 7]);
    $this->compactor = new Compactor(['db' => $db, 'settings' => $this->settings]);
});

/**
 * Writes an hourly page row with a sketch of $visitors distinct people,
 * drawn from a shared pool so overlap between hours is realistic.
 */
function writeHourlyPage(
    string $date,
    int $hour,
    int $views,
    array $visitorIds,
    int $pathDimId = 1,
    ?int $elementId = null,
): void {
    $sketch = new Hll(12);
    foreach ($visitorIds as $id) {
        $sketch->add(substr(hash('sha256', "visitor:$id"), 0, 16));
    }

    TestDb::connection()->createCommand()->insert(Table::PAGES_ROLLUP, [
        'siteId' => 1,
        'date' => $date,
        'hour' => $hour,
        'pathDimId' => $pathDimId,
        'elementId' => $elementId,
        'views' => $views,
        'uniques' => new yii\db\PdoValue($sketch->serialize(), PDO::PARAM_LOB),
        'entrances' => 1,
        'exits' => 1,
        'bounces' => 0,
    ])->execute();
}

function pageRows(array $where = []): array
{
    return (new Query())->from(Table::PAGES_ROLLUP)->where($where)->orderBy('hour')->all(TestDb::connection());
}

function blobOf(mixed $value): string
{
    return is_resource($value) ? (string)stream_get_contents($value) : (string)$value;
}

test('hourly rows outside the window compact into one daily row', function() {
    $old = '2026-06-01';

    for ($hour = 0; $hour < 24; $hour++) {
        writeHourlyPage($old, $hour, 10, [$hour]);
    }

    expect(pageRows())->toHaveCount(24);

    $this->compactor->run(mktime(12, 0, 0, 7, 16, 2026));

    $rows = pageRows();

    // 24 rows become 1, and every view survives.
    expect($rows)->toHaveCount(1)
        ->and((int)$rows[0]['hour'])->toBe(Compactor::DAILY_HOUR)
        ->and((int)$rows[0]['views'])->toBe(240)
        ->and((int)$rows[0]['entrances'])->toBe(24);
});

test('compaction merges sketches instead of summing them — the whole point', function() {
    $old = '2026-06-01';

    // The same 100 people browsing in all three hours. Summing hourly
    // uniques would say 300 visitors; the truth is 100.
    $visitors = range(1, 100);
    foreach ([9, 10, 11] as $hour) {
        writeHourlyPage($old, $hour, 100, $visitors);
    }

    $this->compactor->run(mktime(12, 0, 0, 7, 16, 2026));

    $rows = pageRows();
    $sketch = Hll::deserialize(blobOf($rows[0]['uniques']));

    expect($rows)->toHaveCount(1)
        ->and((int)$rows[0]['views'])->toBe(300)
        // ~100 (the sketch estimates 101 at this cardinality), and nowhere
        // near the 300 that summing the hourly counts would have produced.
        ->and($sketch->count())->toBeGreaterThan(95)
        ->and($sketch->count())->toBeLessThan(105);
});

test('a merged sketch counts the union across hours', function() {
    $old = '2026-06-01';

    // Three hours, 100 people each, all different: 300 distinct.
    writeHourlyPage($old, 9, 100, range(1, 100));
    writeHourlyPage($old, 10, 100, range(101, 200));
    writeHourlyPage($old, 11, 100, range(201, 300));

    $this->compactor->run(mktime(12, 0, 0, 7, 16, 2026));

    $sketch = Hll::deserialize(blobOf(pageRows()[0]['uniques']));

    expect($sketch->count())->toBeGreaterThan(285)
        ->and($sketch->count())->toBeLessThan(315);
});

test('recent days stay hourly', function() {
    $now = mktime(12, 0, 0, 7, 16, 2026);
    $yesterday = date('Y-m-d', $now - 86400);

    writeHourlyPage($yesterday, 9, 10, [1]);
    writeHourlyPage($yesterday, 10, 10, [2]);

    $this->compactor->run($now);

    // Inside the 7-day window: "when today did people visit" is still a
    // question worth answering.
    expect(pageRows())->toHaveCount(2)
        ->and((int)pageRows()[0]['hour'])->not->toBe(Compactor::DAILY_HOUR);
});

test('separate paths compact to separate daily rows', function() {
    $old = '2026-06-01';

    writeHourlyPage($old, 9, 10, [1], pathDimId: 1);
    writeHourlyPage($old, 10, 10, [2], pathDimId: 1);
    writeHourlyPage($old, 9, 5, [3], pathDimId: 2);

    $this->compactor->run(mktime(12, 0, 0, 7, 16, 2026));

    $rows = pageRows();
    expect($rows)->toHaveCount(2);

    $byPath = [];
    foreach ($rows as $row) {
        $byPath[(int)$row['pathDimId']] = (int)$row['views'];
    }

    expect($byPath[1])->toBe(20)
        ->and($byPath[2])->toBe(5);
});

test('compaction is idempotent', function() {
    $old = '2026-06-01';
    foreach ([9, 10] as $hour) {
        writeHourlyPage($old, $hour, 10, range(1, 50));
    }

    $now = mktime(12, 0, 0, 7, 16, 2026);
    $this->compactor->run($now);
    $first = pageRows()[0];

    // A second pass finds nothing left to compact and changes nothing.
    $this->compactor->run($now);
    $second = pageRows();

    expect($second)->toHaveCount(1)
        ->and((int)$second[0]['views'])->toBe((int)$first['views'])
        ->and(Hll::deserialize(blobOf($second[0]['uniques']))->count())
        ->toBe(Hll::deserialize(blobOf($first['uniques']))->count());
});

test('an interrupted compaction is safe to re-run', function() {
    $old = '2026-06-01';

    // A daily row already exists (a previous pass got that far), and the
    // hourly rows it came from are still present.
    writeHourlyPage($old, 9, 10, range(1, 50));
    writeHourlyPage($old, Compactor::DAILY_HOUR, 999, range(1, 50));

    $this->compactor->run(mktime(12, 0, 0, 7, 16, 2026));

    $rows = pageRows();

    // The hourly rows are the truth; the stale daily row is replaced, not
    // added to — 10, not 1,009.
    expect($rows)->toHaveCount(1)
        ->and((int)$rows[0]['views'])->toBe(10);
});

test('sessions rollups compact too', function() {
    $old = '2026-06-01';
    $db = TestDb::connection();

    foreach ([9, 10] as $hour) {
        $db->createCommand()->insert(Table::SESSIONS_ROLLUP, [
            'siteId' => 1, 'date' => $old, 'hour' => $hour,
            'sessions' => 5, 'bounces' => 2, 'totalDurationMs' => 60000, 'totalPageviews' => 12,
        ])->execute();
    }

    $this->compactor->run(mktime(12, 0, 0, 7, 16, 2026));

    $rows = (new Query())->from(Table::SESSIONS_ROLLUP)->all($db);

    expect($rows)->toHaveCount(1)
        ->and((int)$rows[0]['sessions'])->toBe(10)
        ->and((int)$rows[0]['bounces'])->toBe(4)
        ->and((int)$rows[0]['totalDurationMs'])->toBe(120000)
        ->and((int)$rows[0]['totalPageviews'])->toBe(24);
});

test('GC deletes rollups past the retention window', function() {
    $now = mktime(12, 0, 0, 7, 16, 2026);
    $gc = new GcService([
        'db' => TestDb::connection(),
        'settings' => new Settings(['rollupRetentionMonths' => 3]),
    ]);

    writeHourlyPage('2026-01-01', 9, 10, [1]);  // ~6 months old
    writeHourlyPage('2026-07-15', 9, 10, [2]);  // yesterday

    $result = $gc->run($now);

    expect($result['expiredRollups'])->toBe(1)
        ->and(pageRows())->toHaveCount(1);
});

test('GC prunes dimensions nothing references any more', function() {
    $db = TestDb::connection();

    $db->createCommand()->insert(Table::DIMENSIONS, [
        'type' => 1, 'valueHash' => str_repeat('a', 16), 'value' => '/referenced', 'firstSeen' => '2026-07-01',
    ])->execute();
    $referencedId = (int)(new Query())->select('id')->from(Table::DIMENSIONS)
        ->where(['value' => '/referenced'])->scalar($db);

    $db->createCommand()->insert(Table::DIMENSIONS, [
        'type' => 1, 'valueHash' => str_repeat('b', 16), 'value' => '/orphan', 'firstSeen' => '2026-07-01',
    ])->execute();

    writeHourlyPage('2026-07-15', 9, 10, [1], pathDimId: $referencedId);

    $result = (new GcService(['db' => $db, 'settings' => new Settings()]))->run(mktime(12, 0, 0, 7, 16, 2026));

    expect($result['orphanedDimensions'])->toBe(1);

    $remaining = (new Query())->select('value')->from(Table::DIMENSIONS)->column($db);
    expect($remaining)->toBe(['/referenced']);
});

test('GC drops unique membership rows once their salt is gone', function() {
    $db = TestDb::connection();
    $now = mktime(12, 0, 0, 7, 16, 2026);

    foreach (['2026-07-01', '2026-07-16'] as $date) {
        $db->createCommand()->insert(Table::UNIQUE_MEMBERS, [
            'scopeKey' => "p:1:$date:-1:1",
            'siteId' => 1,
            'date' => $date,
            'visitorHash' => 'aaaaaaaaaaaaaaaa',
        ])->execute();
    }

    $result = (new GcService(['db' => $db, 'settings' => new Settings()]))->run($now);

    // The old row's hashes were made with a salt that no longer exists, so
    // they can never be matched to anything again.
    expect($result['expiredMembers'])->toBe(1)
        ->and((new Query())->from(Table::UNIQUE_MEMBERS)->count('*', $db))->toEqual(1);
});

test('one path with two different elements compacts to one row', function() {
    // The unique key is (siteId, date, hour, pathDimId) - no elementId. So
    // grouping by element as well produced two rows the index says are one,
    // and the entire GC run died on a duplicate-key error, leaving hourly rows
    // to accumulate forever.
    //
    // A path holds rows with different elementIds whenever an entry is deleted
    // and recreated, or when a URI resolves to an entry one day and to a
    // template-only route the next. Found on a seeded demo site, where one
    // path had ten rows with an element and one without.
    writeHourlyPage('2026-01-05', 9, 10, [1, 2, 3], 1, 194);
    writeHourlyPage('2026-01-05', 10, 5, [3, 4], 1, 194);
    writeHourlyPage('2026-01-05', 11, 2, [5], 1, null);

    $this->compactor->run(strtotime('2026-02-01'));

    $rows = pageRows(['date' => '2026-01-05']);

    expect($rows)->toHaveCount(1)
        ->and((int)$rows[0]['hour'])->toBe(Compactor::DAILY_HOUR)
        ->and((int)$rows[0]['views'])->toBe(17)
        // First non-null wins: a row that knows its entry can be joined to the
        // Content reports, and one that doesn't cannot.
        ->and((int)$rows[0]['elementId'])->toBe(194);
});

test('a page with no element at all still compacts', function() {
    writeHourlyPage('2026-01-06', 9, 4, [1], 2, null);
    writeHourlyPage('2026-01-06', 10, 3, [2], 2, null);

    $this->compactor->run(strtotime('2026-02-01'));

    $rows = pageRows(['date' => '2026-01-06']);

    expect($rows)->toHaveCount(1)
        ->and((int)$rows[0]['views'])->toBe(7)
        ->and($rows[0]['elementId'])->toBeNull();
});
