<?php

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\tests\TestDb;

beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }
});

test('install migration round-trips cleanly', function() {
    $db = TestDb::connection();
    TestDb::dropTables([Table::DRAIN_LOG, Table::SALTS, Table::DIMENSIONS]);

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
