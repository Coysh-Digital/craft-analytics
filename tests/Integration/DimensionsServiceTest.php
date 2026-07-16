<?php

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\enums\DimensionType;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\services\DimensionsService;
use coyshdigital\craftanalytics\tests\TestDb;

beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    $db = TestDb::connection();
    TestDb::dropTables([Table::DRAIN_LOG, Table::SALTS, Table::DIMENSIONS]);
    (new Install(['db' => $db]))->up();
});

test('get-or-create returns a stable id per (type, value)', function() {
    $service = new DimensionsService(['db' => TestDb::connection()]);

    $a = $service->getOrCreateId(DimensionType::Path, '/pricing');
    $b = $service->getOrCreateId(DimensionType::Path, '/pricing');
    $c = $service->getOrCreateId(DimensionType::Path, '/about');
    $d = $service->getOrCreateId(DimensionType::ReferrerHost, '/pricing');

    expect($b)->toBe($a)
        ->and($c)->not->toBe($a)
        ->and($d)->not->toBe($a); // same value, different type = different dimension
});

test('memoization avoids repeat queries within a process', function() {
    $db = TestDb::connection();
    $service = new DimensionsService(['db' => $db]);

    $id = $service->getOrCreateId(DimensionType::Path, '/memo');

    // Drop the table out from under the service: a memoized hit must not
    // touch the database again.
    $db->createCommand()->dropTable(Table::DIMENSIONS)->execute();

    expect($service->getOrCreateId(DimensionType::Path, '/memo'))->toBe($id);
});

test('a lost insert race resolves to the winner\'s id', function() {
    $db = TestDb::connection();
    $first = new DimensionsService(['db' => $db]);
    $second = new DimensionsService(['db' => $db]); // separate memo, same table

    $winner = $first->getOrCreateId(DimensionType::Path, '/raced');
    $loser = $second->getOrCreateId(DimensionType::Path, '/raced');

    expect($loser)->toBe($winner);

    $count = (int)$db->createCommand(
        'SELECT COUNT(*) FROM ' . Table::DIMENSIONS
    )->queryScalar();
    expect($count)->toBe(1);
});

test('values longer than the column are truncated but still keyed uniquely', function() {
    $service = new DimensionsService(['db' => TestDb::connection()]);

    $longA = '/long/' . str_repeat('a', 600);
    $longB = '/long/' . str_repeat('a', 600); // identical after truncation

    expect($service->getOrCreateId(DimensionType::Path, $longB))
        ->toBe($service->getOrCreateId(DimensionType::Path, $longA));
});
