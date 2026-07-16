<?php

use coyshdigital\craftanalytics\db\Upsert;
use coyshdigital\craftanalytics\tests\TestDb;
use craft\db\Migration;

const UPSERT_TEST_TABLE = '{{%craftanalytics_upsert_test}}';

beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    $db = TestDb::connection();
    TestDb::dropTables([UPSERT_TEST_TABLE]);

    // Shape mirrors a rollup table: composite unique key + counter columns.
    // craft\db\Migration is abstract, so use a throwaway subclass as a
    // cross-database schema builder.
    $migration = new class(['db' => $db]) extends Migration {
    };
    $migration->compact = true;
    $migration->createTable(UPSERT_TEST_TABLE, [
        'id' => $migration->primaryKey(),
        'siteId' => $migration->integer()->notNull(),
        'date' => $migration->date()->notNull(),
        'hour' => $migration->smallInteger()->notNull(),
        'pathDimId' => $migration->integer()->notNull(),
        'views' => $migration->integer()->notNull()->defaultValue(0),
        'bounces' => $migration->integer()->notNull()->defaultValue(0),
    ]);
    $migration->createIndex(null, UPSERT_TEST_TABLE, ['siteId', 'date', 'hour', 'pathDimId'], true);
});

function upsertTestRow(): array
{
    return TestDb::connection()
        ->createCommand('SELECT * FROM ' . UPSERT_TEST_TABLE)
        ->queryAll()[0] ?? [];
}

test('first upsert inserts the row', function() {
    Upsert::counters(TestDb::connection(), UPSERT_TEST_TABLE,
        ['siteId' => 1, 'date' => '2026-07-16', 'hour' => 10, 'pathDimId' => 7],
        ['views' => 3, 'bounces' => 1],
    );

    $row = upsertTestRow();
    expect((int)$row['views'])->toBe(3)
        ->and((int)$row['bounces'])->toBe(1);
});

test('conflicting upsert increments counters instead of duplicating', function() {
    $db = TestDb::connection();
    $keys = ['siteId' => 1, 'date' => '2026-07-16', 'hour' => 10, 'pathDimId' => 7];

    Upsert::counters($db, UPSERT_TEST_TABLE, $keys, ['views' => 3, 'bounces' => 1]);
    Upsert::counters($db, UPSERT_TEST_TABLE, $keys, ['views' => 5, 'bounces' => 0]);

    $count = (int)$db->createCommand('SELECT COUNT(*) FROM ' . UPSERT_TEST_TABLE)->queryScalar();
    $row = upsertTestRow();

    expect($count)->toBe(1)
        ->and((int)$row['views'])->toBe(8)
        ->and((int)$row['bounces'])->toBe(1);
});

test('rows differing in any key column stay separate', function() {
    $db = TestDb::connection();

    Upsert::counters($db, UPSERT_TEST_TABLE,
        ['siteId' => 1, 'date' => '2026-07-16', 'hour' => 10, 'pathDimId' => 7], ['views' => 1, 'bounces' => 0]);
    Upsert::counters($db, UPSERT_TEST_TABLE,
        ['siteId' => 2, 'date' => '2026-07-16', 'hour' => 10, 'pathDimId' => 7], ['views' => 1, 'bounces' => 0]);
    Upsert::counters($db, UPSERT_TEST_TABLE,
        ['siteId' => 1, 'date' => '2026-07-16', 'hour' => 11, 'pathDimId' => 7], ['views' => 1, 'bounces' => 0]);

    $count = (int)$db->createCommand('SELECT COUNT(*) FROM ' . UPSERT_TEST_TABLE)->queryScalar();
    expect($count)->toBe(3);
});

test('batch upsert accumulates within one call and is transactional', function() {
    $db = TestDb::connection();
    $keys = ['siteId' => 1, 'date' => '2026-07-16', 'hour' => 10, 'pathDimId' => 7];

    Upsert::countersBatch($db, UPSERT_TEST_TABLE, [
        ['keys' => $keys, 'counters' => ['views' => 2, 'bounces' => 0]],
        ['keys' => $keys, 'counters' => ['views' => 3, 'bounces' => 2]],
    ]);

    $row = upsertTestRow();
    expect((int)$row['views'])->toBe(5)
        ->and((int)$row['bounces'])->toBe(2);

    // A failing row rolls back the whole batch.
    expect(fn() => Upsert::countersBatch($db, UPSERT_TEST_TABLE, [
        ['keys' => ['siteId' => 9, 'date' => '2026-07-16', 'hour' => 1, 'pathDimId' => 1], 'counters' => ['views' => 1, 'bounces' => 0]],
        ['keys' => ['siteId' => 9, 'date' => 'not-a-date', 'hour' => 1, 'pathDimId' => 1], 'counters' => ['views' => 1, 'bounces' => 0]],
    ]))->toThrow(yii\db\Exception::class);

    $site9 = (int)$db->createCommand(
        'SELECT COUNT(*) FROM ' . UPSERT_TEST_TABLE . ' WHERE ' . $db->quoteColumnName('siteId') . ' = 9'
    )->queryScalar();
    expect($site9)->toBe(0);
});
