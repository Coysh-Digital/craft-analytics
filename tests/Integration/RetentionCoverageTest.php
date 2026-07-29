<?php

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\services\GcService;
use coyshdigital\craftanalytics\tests\TestDb;
use coyshdigital\craftanalytics\uniques\HllUniqueCounter;
use yii\db\Query;

/**
 * Retention is a promise the Privacy screen makes in writing: "aggregate
 * retention - {months} months". The GC has to keep it for every aggregate
 * table, not for the four it happened to be written with.
 *
 * It didn't. `deleteExpiredRollups()` named pages, sessions, sources and
 * devices; every rollup added after them - campaigns, geo, events, scroll,
 * search, outbound, segments, crawlers, goals, funnel steps - was kept
 * forever. Ten of the fourteen aggregate tables outlived the period the screen
 * quoted, and `search_rollup` is the one that matters most, because a site
 * search term is free text a visitor typed and people search their own name.
 *
 * Same shape of fault as the dimension-reference bug, so the same shape of
 * test: read the real schema, and fail on any date-keyed table that is in
 * neither the expiring list nor the documented exceptions.
 */
beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    TestDb::dropTables(SchemaBuilder::allTables());
    (new Install(['db' => TestDb::connection()]))->up();

    $this->gc = new GcService([
        'db' => TestDb::connection(),
        'settings' => new Settings(['rollupRetentionMonths' => 3]),
        'counter' => new HllUniqueCounter(['settings' => new Settings()]),
    ]);
});

test('every date-keyed table in the schema has a retention rule', function() {
    $db = TestDb::connection();

    $expiring = array_flip(SchemaBuilder::expiringRollups());
    $elsewhere = SchemaBuilder::retainedElsewhere();

    /** @var array<int,string> $unaccounted */
    $unaccounted = [];

    foreach (SchemaBuilder::allTables() as $table) {
        $schema = $db->getTableSchema($table, true);

        // A `date` column is the shape every aggregate rollup shares, and is
        // what deleteExpiredRollups() deletes on.
        if ($schema === null || !in_array('date', $schema->getColumnNames(), true)) {
            continue;
        }

        if (!isset($expiring[$table]) && !isset($elsewhere[$table])) {
            $unaccounted[] = $db->getSchema()->getRawTableName($table);
        }
    }

    expect($unaccounted)->toBe(
        [],
        'These tables are keyed by date but appear in neither '
        . 'SchemaBuilder::expiringRollups() nor retainedElsewhere(), so nothing '
        . 'ever deletes their rows and the retention period the Privacy screen '
        . 'states is not true of them: ' . implode(', ', $unaccounted),
    );
});

test('the expiring list names only tables that exist and are date-keyed', function() {
    $db = TestDb::connection();
    /** @var array<int,string> $wrong */
    $wrong = [];

    foreach (SchemaBuilder::expiringRollups() as $table) {
        $schema = $db->getTableSchema($table, true);

        if ($schema === null || !in_array('date', $schema->getColumnNames(), true)) {
            $wrong[] = $table;
        }
    }

    expect($wrong)->toBe([], 'Not a date-keyed table: ' . implode(', ', $wrong));
});

test('the GC expires every rollup past the retention window', function() {
    $db = TestDb::connection();
    $now = mktime(12, 0, 0, 7, 16, 2026);
    $old = '2020-01-01';

    // One row per expiring table, all far older than the three-month window.
    // Only the key columns are set: the point is the delete, not the counters.
    $rows = [
        Table::PAGES_ROLLUP => ['hour' => 10, 'pathDimId' => 1],
        Table::SESSIONS_ROLLUP => ['hour' => 10],
        Table::SOURCES_ROLLUP => ['hour' => 10, 'channel' => 0, 'refHostDimId' => 0],
        Table::DEVICES_ROLLUP => ['browserDimId' => 1, 'osDimId' => 2],
        Table::CRAWLERS_ROLLUP => ['crawlerDimId' => 1],
        Table::CAMPAIGNS_ROLLUP => ['sourceDimId' => 1],
        Table::GEO_ROLLUP => ['countryCode' => 'GB'],
        Table::EVENTS_ROLLUP => ['hour' => 10, 'eventNameDimId' => 1],
        Table::SCROLL_ROLLUP => ['pathDimId' => 1, 'bucket' => 50],
        Table::SEARCH_ROLLUP => ['termDimId' => 1],
        Table::OUTBOUND_ROLLUP => ['targetHostDimId' => 1],
        Table::SEGMENTS_ROLLUP => ['segmentDimId' => 1],
    ];

    foreach ($rows as $table => $columns) {
        $db->createCommand()->insert($table, $columns + ['siteId' => 1, 'date' => $old])->execute();
    }

    $this->gc->run($now);

    /** @var array<int,string> $survivors */
    $survivors = [];

    foreach (array_keys($rows) as $table) {
        if ((new Query())->from($table)->where(['date' => $old])->exists($db)) {
            $survivors[] = $db->getSchema()->getRawTableName($table);
        }
    }

    expect($survivors)->toBe([], 'Kept past the retention window: ' . implode(', ', $survivors));
});

test('the GC leaves rollups inside the retention window alone', function() {
    $db = TestDb::connection();
    $now = mktime(12, 0, 0, 7, 16, 2026);

    $db->createCommand()->insert(Table::SEARCH_ROLLUP, [
        'siteId' => 1,
        'date' => '2026-07-01',
        'termDimId' => 1,
        'count' => 3,
    ])->execute();

    $this->gc->run($now);

    expect((new Query())->from(Table::SEARCH_ROLLUP)->count('*', $db))->toEqual(1);
});
