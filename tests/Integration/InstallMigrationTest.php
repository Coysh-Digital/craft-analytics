<?php

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\migrations\m260721_120000_segments_and_external_ids;
use coyshdigital\craftanalytics\tests\TestDb;

beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }
});

test('install migration round-trips cleanly', function() {
    $db = TestDb::connection();
    TestDb::dropTables(SchemaBuilder::allTables());

    $migration = new Install(['db' => $db]);

    expect($migration->up())->not->toBeFalse();

    foreach ([Table::DIMENSIONS, Table::SALTS, Table::DRAIN_LOG] as $table) {
        expect($db->getTableSchema($table, true))->not->toBeNull();
    }

    // Unique index enforced: same (type, valueHash) twice must fail.
    $db->createCommand()->insert(Table::DIMENSIONS, [
        'type' => 1, 'valueHash' => str_repeat('a', 16), 'value' => '/x', 'firstSeen' => '2026-07-16',
    ])->execute();

    expect(fn() => $db->createCommand()->insert(Table::DIMENSIONS, [
        'type' => 1, 'valueHash' => str_repeat('a', 16), 'value' => '/x', 'firstSeen' => '2026-07-16',
    ])->execute())->toThrow(yii\db\IntegrityException::class);

    // Same hash under a different type is a different dimension — allowed.
    $db->createCommand()->insert(Table::DIMENSIONS, [
        'type' => 2, 'valueHash' => str_repeat('a', 16), 'value' => '/x', 'firstSeen' => '2026-07-16',
    ])->execute();

    expect($migration->down())->not->toBeFalse();

    foreach ([Table::DIMENSIONS, Table::SALTS, Table::DRAIN_LOG] as $table) {
        expect($db->getTableSchema($table, true))->toBeNull();
    }
});

test('an existing install upgrades to the segments schema without losing anything', function() {
    // The risky half of that migration is the widening, not the new table:
    // it alters an indexed column on a table that holds the only personal
    // data in the plugin, on two different databases.
    $db = TestDb::connection();
    TestDb::dropTables(SchemaBuilder::allTables());
    (new Install(['db' => $db]))->up();

    // Wind the schema back to what a 1.3.x install looks like.
    $before = new m260721_120000_segments_and_external_ids(['db' => $db]);
    $db->createCommand()->dropTable(Table::SEGMENTS_ROLLUP)->execute();
    $db->createCommand()->alterColumn(Table::JOURNEYS, 'visitorId', 'char(32) NOT NULL')->execute();
    $db->createCommand()->alterColumn(Table::CONSENT_LOG, 'visitorId', 'char(32)')->execute();

    $db->createCommand()->insert(Table::CONSENT_LOG, [
        'siteId' => 1,
        'visitorId' => str_repeat('a', 32),
        'state' => 'granted',
        'method' => 'js-api',
        'scope' => 'analytics',
        'policyVersion' => '1',
        'recordedAt' => '2026-07-16 10:00:00',
    ])->execute();

    expect($before->up())->not->toBeFalse();

    // The new table is there...
    expect($db->getTableSchema(Table::SEGMENTS_ROLLUP, true))->not->toBeNull();

    // ...the id already stored is untouched...
    expect((new yii\db\Query())->select('visitorId')->from(Table::CONSENT_LOG)->scalar($db))
        ->toBe(str_repeat('a', 32));

    // ...and an id longer than the old column now fits.
    $db->createCommand()->insert(Table::JOURNEYS, [
        'visitorId' => 'customer:' . str_repeat('9', 40),
        'siteId' => 1,
        'sessionId' => str_repeat('b', 32),
        'sequence' => 0,
        'pathDimId' => 1,
        'occurredAt' => '2026-07-16 10:00:00',
    ])->execute();

    expect((new yii\db\Query())->select('visitorId')->from(Table::JOURNEYS)->scalar($db))
        ->toBe('customer:' . str_repeat('9', 40));
});
