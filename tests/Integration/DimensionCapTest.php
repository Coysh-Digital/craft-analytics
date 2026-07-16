<?php

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\enums\DimensionType;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\rollup\Aggregator;
use coyshdigital\craftanalytics\rollup\DbRollupSink;
use coyshdigital\craftanalytics\rollup\DimensionCapper;
use coyshdigital\craftanalytics\services\ChannelClassifier;
use coyshdigital\craftanalytics\services\DeviceParser;
use coyshdigital\craftanalytics\services\DimensionsService;
use coyshdigital\craftanalytics\tests\TestDb;
use coyshdigital\craftanalytics\uniques\HllUniqueCounter;
use yii\db\Query;

beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    $db = TestDb::connection();
    TestDb::dropTables(SchemaBuilder::allTables());
    (new Install(['db' => $db]))->up();

    // A cap of 5 keeps the test readable; the default is 1,000.
    $this->settings = new Settings(['dimensionCap' => 5]);
    $this->dimensions = new DimensionsService(['db' => $db]);
    $this->capper = new DimensionCapper([
        'db' => $db,
        'settings' => $this->settings,
        'dimensions' => $this->dimensions,
    ]);
    $this->sink = new DbRollupSink([
        'db' => $db,
        'counter' => new HllUniqueCounter(['settings' => new Settings()]),
        'capper' => $this->capper,
        'channels' => new ChannelClassifier(),
        'devices' => new DeviceParser(),
    ]);
});

function drainPaths(object $ctx, array $paths, ?int $ts = null): void
{
    $ts ??= mktime(10, 0, 0, 7, 16, 2026);
    $aggregator = new Aggregator(new DateTimeZone('UTC'));

    foreach ($paths as $i => $path) {
        $aggregator->add(new coyshdigital\craftanalytics\ingest\Hit(
            siteId: 1,
            path: $path,
            visitorHash: str_pad((string)$i, 16, '0', STR_PAD_LEFT),
            sessionKey: 'k' . $i,
            timestamp: $ts,
        ));
    }

    $ctx->sink->flush($aggregator->buckets(), []);
}

function otherDimId(object $ctx): int
{
    return $ctx->capper->otherId(DimensionType::Path);
}

test('values within the cap keep their own dimension', function() {
    drainPaths($this, ['/a', '/b', '/c']);

    $rows = (new Query())->from(Table::PAGES_ROLLUP)->all(TestDb::connection());
    expect($rows)->toHaveCount(3);

    foreach ($rows as $row) {
        expect((int)$row['pathDimId'])->not->toBe(otherDimId($this));
    }
});

test('the tail beyond the cap folds into a single __other__ row', function() {
    // 20 distinct paths, cap of 5: this is a cardinality explosion, and the
    // table must not grow with it.
    drainPaths($this, array_map(fn($i) => "/page-$i", range(1, 20)));

    $rows = (new Query())->from(Table::PAGES_ROLLUP)->all(TestDb::connection());

    // 5 tracked paths + 1 __other__ — not 20.
    expect($rows)->toHaveCount(6);

    $other = (new Query())->from(Table::PAGES_ROLLUP)
        ->where(['pathDimId' => otherDimId($this)])
        ->one(TestDb::connection());

    // Nothing is lost: the 15 folded views are still counted, just not
    // attributed individually.
    expect((int)$other['views'])->toBe(15);
});

test('a capped day still totals every view', function() {
    drainPaths($this, array_map(fn($i) => "/page-$i", range(1, 50)));

    $total = (new Query())->from(Table::PAGES_ROLLUP)->sum('views', TestDb::connection());

    expect((int)$total)->toBe(50);
});

test('a value admitted earlier in the day survives later pressure', function() {
    drainPaths($this, ['/popular']);
    $popularDimId = $this->dimensions->getId(DimensionType::Path, '/popular');

    // The cap fills up with junk after /popular is already being tracked.
    drainPaths($this, array_map(fn($i) => "/junk-$i", range(1, 30)));
    drainPaths($this, ['/popular']);

    $row = (new Query())->from(Table::PAGES_ROLLUP)
        ->where(['pathDimId' => $popularDimId])
        ->one(TestDb::connection());

    // Still its own row, with both views — a page doesn't get demoted
    // mid-day because a crawler turned up.
    expect((int)$row['views'])->toBe(2);
});

test('the cap is per day: a new day starts fresh', function() {
    drainPaths($this, array_map(fn($i) => "/page-$i", range(1, 20)), mktime(10, 0, 0, 7, 16, 2026));
    drainPaths($this, ['/brand-new'], mktime(10, 0, 0, 7, 17, 2026));

    $newDimId = $this->dimensions->getId(DimensionType::Path, '/brand-new');
    $row = (new Query())->from(Table::PAGES_ROLLUP)
        ->where(['date' => '2026-07-17'])
        ->one(TestDb::connection());

    expect((int)$row['pathDimId'])->toBe($newDimId)
        ->and((int)$row['pathDimId'])->not->toBe(otherDimId($this));
});

test('the cap is per site', function() {
    $aggregator = new Aggregator(new DateTimeZone('UTC'));
    foreach (range(1, 20) as $i) {
        $aggregator->add(new coyshdigital\craftanalytics\ingest\Hit(
            siteId: 1, path: "/p$i", visitorHash: str_pad((string)$i, 16, '0', STR_PAD_LEFT),
            sessionKey: 'k', timestamp: mktime(10, 0, 0, 7, 16, 2026),
        ));
    }
    $aggregator->add(new coyshdigital\craftanalytics\ingest\Hit(
        siteId: 2, path: '/site-two-page', visitorHash: 'ffffffffffffffff',
        sessionKey: 'k2', timestamp: mktime(10, 0, 0, 7, 16, 2026),
    ));

    $this->sink->flush($aggregator->buckets(), []);

    $row = (new Query())->from(Table::PAGES_ROLLUP)->where(['siteId' => 2])->one(TestDb::connection());

    // Site 1 exhausting its cap must not spend site 2's (C8).
    expect((int)$row['pathDimId'])->not->toBe(otherDimId($this));
});

test('__other__ does not consume one of the capped slots', function() {
    drainPaths($this, array_map(fn($i) => "/page-$i", range(1, 20)));

    $tracked = (new Query())->from(Table::PAGES_ROLLUP)
        ->where(['not', ['pathDimId' => otherDimId($this)]])
        ->count('*', TestDb::connection());

    expect((int)$tracked)->toBe(5);
});
