<?php

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\services\GcService;
use coyshdigital\craftanalytics\tests\TestDb;
use coyshdigital\craftanalytics\uniques\HllUniqueCounter;

/**
 * The GC deletes dimensions nothing references. It decides "nothing" from
 * SchemaBuilder::dimensionReferences(), so anything missing from that list
 * gets its dimensions deleted underneath it, and the report empties silently
 * the first night the GC runs.
 *
 * That happened. Every Pro rollup - campaigns, geo, events, scroll, search,
 * outbound and crawlers - was added without an entry in the list, and the
 * first GC run over a seeded demo site orphaned 3,149 campaign rows, 6,336
 * geo rows, 21,153 event rows and 3,600 crawler rows in one go. The reports
 * still counted their totals and showed nothing in their tables.
 *
 * So this test doesn't check the list against another list. It reads the
 * actual database schema and fails if a real column is missing, which means
 * adding a rollup and forgetting the GC is no longer something you can do
 * quietly.
 */
beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    TestDb::dropTables(SchemaBuilder::allTables());
    (new Install(['db' => TestDb::connection()]))->up();

    $this->gc = new GcService([
        'db' => TestDb::connection(),
        'settings' => new Settings(),
        'counter' => new HllUniqueCounter(['settings' => new Settings()]),
    ]);
});

test('every dimension column in the schema is known to the GC', function() {
    $db = TestDb::connection();
    $known = [];

    foreach (SchemaBuilder::dimensionReferences() as [$table, $column]) {
        $known[$db->getSchema()->getRawTableName($table) . '.' . $column] = true;
    }

    /** @var array<int,string> $missing */
    $missing = [];

    foreach (SchemaBuilder::allTables() as $table) {
        $rawName = $db->getSchema()->getRawTableName($table);
        $schema = $db->getTableSchema($table, true);

        if ($schema === null) {
            continue;
        }

        foreach ($schema->getColumnNames() as $column) {
            // The convention every dimension foreign key follows.
            if (!str_ends_with($column, 'DimId')) {
                continue;
            }

            if (!isset($known[$rawName . '.' . $column])) {
                $missing[] = $rawName . '.' . $column;
            }
        }
    }

    expect($missing)->toBe(
        [],
        'These columns point at the dimensions table but are not in '
        . 'SchemaBuilder::dimensionReferences(), so the GC will delete the '
        . 'dimensions they use and empty their report: ' . implode(', ', $missing),
    );
});

test('the GC keeps dimensions that a Pro rollup still uses', function() {
    $db = TestDb::connection();

    $db->createCommand()->insert(Table::DIMENSIONS, [
        'type' => 6,
        'valueHash' => str_repeat('a', 16),
        'value' => 'newsletter',
        'firstSeen' => '2026-01-01',
    ])->execute();
    // Read the id back rather than asking for the last insert id, whose
    // sequence naming differs between the two engines.
    $dimId = (int)(new yii\db\Query())
        ->select('id')
        ->from(Table::DIMENSIONS)
        ->where(['value' => 'newsletter'])
        ->scalar($db);

    $db->createCommand()->insert(Table::CAMPAIGNS_ROLLUP, [
        'siteId' => 1,
        'date' => '2026-01-01',
        'sourceDimId' => $dimId,
        'sessions' => 5,
    ])->execute();

    $this->gc->run();

    $survives = (new yii\db\Query())
        ->from(Table::DIMENSIONS)
        ->where(['id' => $dimId])
        ->exists($db);

    expect($survives)->toBeTrue();
});

test('the GC still deletes a dimension nothing references', function() {
    $db = TestDb::connection();

    $db->createCommand()->insert(Table::DIMENSIONS, [
        'type' => 1,
        'valueHash' => str_repeat('b', 16),
        'value' => '/gone',
        'firstSeen' => '2026-01-01',
    ])->execute();

    $this->gc->run();

    $remaining = (new yii\db\Query())->from(Table::DIMENSIONS)->count('*', $db);

    expect((int)$remaining)->toBe(0);
});
