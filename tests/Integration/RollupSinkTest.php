<?php

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\enums\Channel;
use coyshdigital\craftanalytics\enums\DeviceType;
use coyshdigital\craftanalytics\enums\DimensionType;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\rollup\Aggregator;
use coyshdigital\craftanalytics\rollup\DbRollupSink;
use coyshdigital\craftanalytics\rollup\DimensionCapper;
use coyshdigital\craftanalytics\services\ChannelClassifier;
use coyshdigital\craftanalytics\services\DeviceParser;
use coyshdigital\craftanalytics\services\DimensionsService;
use coyshdigital\craftanalytics\session\Session;
use coyshdigital\craftanalytics\tests\TestDb;
use coyshdigital\craftanalytics\uniques\Hll;
use coyshdigital\craftanalytics\uniques\HllUniqueCounter;
use yii\db\Query;

beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    $db = TestDb::connection();
    TestDb::dropTables(coyshdigital\craftanalytics\db\SchemaBuilder::allTables());
    (new Install(['db' => $db]))->up();

    $this->settings = new Settings();
    $this->dimensions = new DimensionsService(['db' => $db]);
    $this->sink = new DbRollupSink([
        'db' => $db,
        'counter' => new HllUniqueCounter(['settings' => $this->settings]),
        'capper' => new DimensionCapper([
            'db' => $db,
            'settings' => $this->settings,
            'dimensions' => $this->dimensions,
        ]),
        'channels' => new ChannelClassifier(),
        'devices' => new DeviceParser(),
    ]);
});

function sinkHit(string $path = '/pricing', string $visitor = 'aaaaaaaaaaaaaaaa', ?int $ts = null): coyshdigital\craftanalytics\ingest\Hit
{
    return new coyshdigital\craftanalytics\ingest\Hit(
        siteId: 1,
        path: $path,
        visitorHash: $visitor,
        sessionKey: 'sess-' . $visitor,
        timestamp: $ts ?? mktime(10, 0, 0, 7, 16, 2026),
        userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        acceptLanguage: 'en-GB',
    );
}

/** @param coyshdigital\craftanalytics\ingest\Hit[] $hits */
function flushHits(object $ctx, array $hits, array $sessions = []): void
{
    $aggregator = new Aggregator(new DateTimeZone('UTC'));
    foreach ($hits as $hit) {
        $aggregator->add($hit);
    }
    $ctx->sink->flush($aggregator->buckets(), $sessions);
}

function pagesRow(array $where = []): ?array
{
    return (new Query())->from(Table::PAGES_ROLLUP)->where($where)->one(TestDb::connection()) ?: null;
}

function readSketchBlob(mixed $value): ?string
{
    if ($value === null || $value === false || $value === '') {
        return null;
    }

    return is_resource($value) ? (string)stream_get_contents($value) : (string)$value;
}

test('page views land in one row per path-hour, however many hits', function() {
    $hits = [];
    for ($i = 0; $i < 200; $i++) {
        $hits[] = sinkHit('/pricing', str_pad((string)$i, 16, '0', STR_PAD_LEFT));
    }

    flushHits($this, $hits);

    $rows = (new Query())->from(Table::PAGES_ROLLUP)->all(TestDb::connection());

    // 200 pageviews by 200 people: one row. This is C2, in the table.
    expect($rows)->toHaveCount(1)
        ->and((int)$rows[0]['views'])->toBe(200);
});

test('draining the same page twice accumulates onto the same row', function() {
    flushHits($this, [sinkHit('/pricing', 'aaaaaaaaaaaaaaaa')]);
    flushHits($this, [sinkHit('/pricing', 'bbbbbbbbbbbbbbbb')]);

    $rows = (new Query())->from(Table::PAGES_ROLLUP)->all(TestDb::connection());

    expect($rows)->toHaveCount(1)
        ->and((int)$rows[0]['views'])->toBe(2);
});

test('the uniques sketch round-trips through the database and counts right', function() {
    $hits = [];
    for ($i = 0; $i < 300; $i++) {
        $hits[] = sinkHit('/pricing', substr(hash('sha256', "v$i"), 0, 16));
    }
    // The same 300 people, viewing twice each.
    flushHits($this, $hits);
    flushHits($this, $hits);

    $row = pagesRow();
    $sketch = Hll::deserialize((string)readSketchBlob($row['uniques']));

    expect((int)$row['views'])->toBe(600)
        // 600 views, 300 people — the sketch survived a binary round-trip on
        // this database and still knows the difference.
        ->and($sketch->count())->toBeGreaterThan(285)
        ->and($sketch->count())->toBeLessThan(315);
});

test('a sketch accumulates across separate flushes rather than resetting', function() {
    flushHits($this, array_map(fn($i) => sinkHit('/p', substr(hash('sha256', "a$i"), 0, 16)), range(1, 100)));
    flushHits($this, array_map(fn($i) => sinkHit('/p', substr(hash('sha256', "b$i"), 0, 16)), range(1, 100)));

    $sketch = Hll::deserialize((string)readSketchBlob(pagesRow()['uniques']));

    expect($sketch->count())->toBeGreaterThan(190)
        ->and($sketch->count())->toBeLessThan(210);
});

test('different hours and paths get their own rows', function() {
    flushHits($this, [
        sinkHit('/a', 'aaaaaaaaaaaaaaaa', mktime(10, 0, 0, 7, 16, 2026)),
        sinkHit('/b', 'aaaaaaaaaaaaaaaa', mktime(10, 0, 0, 7, 16, 2026)),
        sinkHit('/a', 'aaaaaaaaaaaaaaaa', mktime(11, 0, 0, 7, 16, 2026)),
    ]);

    expect((new Query())->from(Table::PAGES_ROLLUP)->count('*', TestDb::connection()))->toEqual(3);
});

test('a closed session writes session, source, device and entry/exit rows', function() {
    $start = mktime(10, 0, 0, 7, 16, 2026);

    $session = new Session(
        siteId: 1,
        sessionKey: 'k',
        visitorHash: 'aaaaaaaaaaaaaaaa',
        startedAt: $start,
        lastSeenAt: $start + 120,
        pageviews: 3,
        entryPath: '/landing',
        lastPath: '/contact',
        referrer: 'https://www.google.com/search?q=x',
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
    );

    flushHits($this, [], [$session]);

    $db = TestDb::connection();
    $sessionRow = (new Query())->from(Table::SESSIONS_ROLLUP)->one($db);
    $sourceRow = (new Query())->from(Table::SOURCES_ROLLUP)->one($db);
    $deviceRow = (new Query())->from(Table::DEVICES_ROLLUP)->one($db);

    expect((int)$sessionRow['sessions'])->toBe(1)
        ->and((int)$sessionRow['bounces'])->toBe(0)
        ->and((int)$sessionRow['totalPageviews'])->toBe(3)
        ->and((int)$sessionRow['totalDurationMs'])->toBe(120000);

    // Google referrer classified as search, and only the host is stored —
    // never the search terms in the URL.
    expect((int)$sourceRow['channel'])->toBe(Channel::Search->value)
        ->and((int)$sourceRow['sessions'])->toBe(1);

    $host = (new Query())->select('value')->from(Table::DIMENSIONS)
        ->where(['id' => $sourceRow['refHostDimId']])->scalar($db);
    expect($host)->toBe('www.google.com');

    expect((int)$deviceRow['deviceType'])->toBe(DeviceType::Mobile->value)
        ->and((int)$deviceRow['sessions'])->toBe(1);

    // Entry and exit landed on their own pages.
    $landingDimId = $this->dimensions->getId(DimensionType::Path, '/landing');
    $contactDimId = $this->dimensions->getId(DimensionType::Path, '/contact');

    expect((int)pagesRow(['pathDimId' => $landingDimId])['entrances'])->toBe(1)
        ->and((int)pagesRow(['pathDimId' => $contactDimId])['exits'])->toBe(1);
});

test('a one-page session counts as a bounce and is not double-counted as an exit', function() {
    $start = mktime(10, 0, 0, 7, 16, 2026);

    $session = new Session(
        siteId: 1, sessionKey: 'k', visitorHash: 'aaaaaaaaaaaaaaaa',
        startedAt: $start, lastSeenAt: $start, pageviews: 1,
        entryPath: '/only', lastPath: '/only',
    );

    flushHits($this, [], [$session]);

    $row = pagesRow();
    expect((int)$row['entrances'])->toBe(1)
        ->and((int)$row['bounces'])->toBe(1)
        ->and((int)$row['exits'])->toBe(1)
        ->and((new Query())->from(Table::PAGES_ROLLUP)->count('*', TestDb::connection()))->toEqual(1);

    $sessionRow = (new Query())->from(Table::SESSIONS_ROLLUP)->one(TestDb::connection());
    expect((int)$sessionRow['bounces'])->toBe(1);
});

test('direct traffic is recorded without a referrer dimension', function() {
    $start = mktime(10, 0, 0, 7, 16, 2026);

    flushHits($this, [], [new Session(
        siteId: 1, sessionKey: 'k', visitorHash: 'aaaaaaaaaaaaaaaa',
        startedAt: $start, lastSeenAt: $start + 10, pageviews: 2,
        entryPath: '/', lastPath: '/x', referrer: '',
    )]);

    $row = (new Query())->from(Table::SOURCES_ROLLUP)->one(TestDb::connection());

    expect((int)$row['channel'])->toBe(Channel::Direct->value)
        ->and((int)$row['refHostDimId'])->toBe(0);
});

test('the element id is recorded so entry stats need no second lookup', function() {
    $hit = new coyshdigital\craftanalytics\ingest\Hit(
        siteId: 1, path: '/news/story', visitorHash: 'aaaaaaaaaaaaaaaa',
        sessionKey: 'k', timestamp: mktime(10, 0, 0, 7, 16, 2026), elementId: 42,
    );

    flushHits($this, [$hit]);

    expect((int)pagesRow()['elementId'])->toBe(42);
});
