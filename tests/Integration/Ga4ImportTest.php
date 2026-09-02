<?php

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\enums\Channel;
use coyshdigital\craftanalytics\enums\DeviceType;
use coyshdigital\craftanalytics\enums\DimensionType;
use coyshdigital\craftanalytics\enums\Ga4Dataset;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\rollup\DimensionCapper;
use coyshdigital\craftanalytics\services\DimensionsService;
use coyshdigital\craftanalytics\services\Ga4ImportService;
use coyshdigital\craftanalytics\tests\TestDb;
use coyshdigital\craftanalytics\uniques\Hll;
use coyshdigital\craftanalytics\uniques\HllUniqueCounter;
use coyshdigital\craftanalytics\uniques\UniqueScope;
use yii\db\Query;

beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    $db = TestDb::connection();
    TestDb::dropTables(SchemaBuilder::allTables());
    (new Install(['db' => $db]))->up();

    $this->settings = new Settings();
    $this->dimensions = new DimensionsService(['db' => $db]);
    $this->import = new Ga4ImportService([
        'db' => $db,
        'settings' => $this->settings,
        'counter' => new HllUniqueCounter(['settings' => $this->settings]),
        'capper' => new DimensionCapper([
            'db' => $db,
            'settings' => $this->settings,
            'dimensions' => $this->dimensions,
        ]),
        'isPro' => true,
        // The minimal harness has no elements service to resolve paths against.
        'resolveElements' => false,
    ]);
});

/**
 * A GA4 runReport response, in the dataset's own dimension/metric order (no
 * headers, so the service falls back to that order).
 *
 * @param array<int,array{dims: array<int,string|int>, metrics: array<int,string|int|float>}> $rows
 * @return array<string,mixed>
 */
function ga4Response(array $rows): array
{
    $out = [];

    foreach ($rows as $row) {
        $out[] = [
            'dimensionValues' => array_map(fn($v) => ['value' => (string)$v], $row['dims']),
            'metricValues' => array_map(fn($v) => ['value' => (string)$v], $row['metrics']),
        ];
    }

    return ['rows' => $out, 'rowCount' => count($out)];
}

function ga4ReadSketch(mixed $value): ?string
{
    if ($value === null || $value === false || $value === '') {
        return null;
    }

    return is_resource($value) ? (string)stream_get_contents($value) : (string)$value;
}

test('pages import writes a daily row at hour -1 with seeded uniques', function() {
    $this->import->import(Ga4Dataset::Pages, 1, ga4Response([
        ['dims' => ['20260715', '/pricing'], 'metrics' => ['500', '120', '3600']],
    ]));

    $row = (new Query())->from(Table::PAGES_ROLLUP)->one(TestDb::connection());

    expect($row)->not->toBeNull()
        ->and((int)$row['hour'])->toBe(UniqueScope::HOUR_DAILY)
        ->and((string)$row['date'])->toStartWith('2026-07-15')
        ->and((int)$row['views'])->toBe(500)
        // 3600 seconds of engagement, stored as milliseconds.
        ->and((int)$row['totalDwellMs'])->toBe(3600000);

    // The sketch was seeded to roughly GA4's active-user count.
    $sketch = Hll::deserialize((string)ga4ReadSketch($row['uniques']));
    expect($sketch->count())->toBeGreaterThan(108)
        ->and($sketch->count())->toBeLessThan(132);
});

test('daily totals become a sessions row, bounces from the rate', function() {
    $this->import->import(Ga4Dataset::Totals, 1, ga4Response([
        ['dims' => ['20260715'], 'metrics' => ['1000', '0.4', '7200', '2500', '800']],
    ]));

    $row = (new Query())->from(Table::SESSIONS_ROLLUP)->one(TestDb::connection());

    expect((int)$row['hour'])->toBe(UniqueScope::HOUR_DAILY)
        ->and((int)$row['sessions'])->toBe(1000)
        ->and((int)$row['bounces'])->toBe(400)
        ->and((int)$row['totalDurationMs'])->toBe(7200000)
        ->and((int)$row['totalPageviews'])->toBe(2500);
});

test('sources map GA4 channel groups onto the plugin channels', function() {
    $this->import->import(Ga4Dataset::Sources, 1, ga4Response([
        ['dims' => ['20260715', 'Organic Search', 'google'], 'metrics' => ['300', '0.5']],
        ['dims' => ['20260715', 'Direct', '(direct)'], 'metrics' => ['100', '0.2']],
        ['dims' => ['20260715', 'Email', 'newsletter'], 'metrics' => ['50', '0.1']],
    ]));

    $db = TestDb::connection();
    $search = (new Query())->from(Table::SOURCES_ROLLUP)->where(['channel' => Channel::Search->value])->one($db);
    $direct = (new Query())->from(Table::SOURCES_ROLLUP)->where(['channel' => Channel::Direct->value])->one($db);
    $campaign = (new Query())->from(Table::SOURCES_ROLLUP)->where(['channel' => Channel::Campaign->value])->one($db);

    expect((int)$search['sessions'])->toBe(300)
        ->and((int)$search['bounces'])->toBe(150)
        ->and((int)$search['refHostDimId'])->not->toBe(0)
        // A GA4 placeholder source carries no referrer host.
        ->and((int)$direct['refHostDimId'])->toBe(0)
        // Email is marketing the owner ran, so it lands in Campaign.
        ->and((int)$campaign['sessions'])->toBe(50);
});

test('devices map the category onto the device type', function() {
    $this->import->import(Ga4Dataset::Devices, 1, ga4Response([
        ['dims' => ['20260715', 'Chrome', 'Macintosh', 'desktop'], 'metrics' => ['200']],
        ['dims' => ['20260715', 'Safari', 'iOS', 'mobile'], 'metrics' => ['90']],
    ]));

    $db = TestDb::connection();
    $desktop = (new Query())->from(Table::DEVICES_ROLLUP)->where(['deviceType' => DeviceType::Desktop->value])->one($db);
    $mobile = (new Query())->from(Table::DEVICES_ROLLUP)->where(['deviceType' => DeviceType::Mobile->value])->one($db);

    expect((int)$desktop['sessions'])->toBe(200)
        ->and((int)$mobile['sessions'])->toBe(90);
});

test('campaigns import tagged traffic and skip untagged', function() {
    $this->import->import(Ga4Dataset::Campaigns, 1, ga4Response([
        ['dims' => ['20260715', 'newsletter', 'email', 'july'], 'metrics' => ['80', '0.25']],
        ['dims' => ['20260715', '(direct)', '(none)', '(not set)'], 'metrics' => ['500', '0.5']],
    ]));

    $rows = (new Query())->from(Table::CAMPAIGNS_ROLLUP)->all(TestDb::connection());

    // One row: the tagged campaign. The untagged (direct) traffic belongs in
    // Sources, not here.
    expect($rows)->toHaveCount(1)
        ->and((float)$rows[0]['sessions'])->toBe(80.0)
        ->and((float)$rows[0]['bounces'])->toBe(20.0);

    $source = (new Query())->select('value')->from(Table::DIMENSIONS)
        ->where(['id' => $rows[0]['sourceDimId']])->scalar(TestDb::connection());
    expect($source)->toBe('newsletter');
});

test('geo uses the ISO country code and skips unplaceable traffic', function() {
    $this->import->import(Ga4Dataset::Geo, 1, ga4Response([
        ['dims' => ['20260715', 'GB', 'England'], 'metrics' => ['150']],
        ['dims' => ['20260715', '(not set)', '(not set)'], 'metrics' => ['10']],
    ]));

    $rows = (new Query())->from(Table::GEO_ROLLUP)->all(TestDb::connection());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['countryCode'])->toBe('GB')
        ->and((int)$rows[0]['sessions'])->toBe(150)
        ->and((int)$rows[0]['regionDimId'])->not->toBe(0);
});

test('events import per event per day at hour -1', function() {
    $this->import->import(Ga4Dataset::Events, 1, ga4Response([
        ['dims' => ['20260715', 'sign_up'], 'metrics' => ['42']],
    ]));

    $row = (new Query())->from(Table::EVENTS_ROLLUP)->one(TestDb::connection());

    expect((int)$row['hour'])->toBe(UniqueScope::HOUR_DAILY)
        ->and((int)$row['count'])->toBe(42)
        // No path is imported, so the "no path" key is used.
        ->and((int)$row['pathDimId'])->toBe(0);
});

test('a Pro dataset writes nothing on a Lite install', function() {
    $lite = new Ga4ImportService([
        'db' => TestDb::connection(),
        'settings' => $this->settings,
        'counter' => new HllUniqueCounter(['settings' => $this->settings]),
        'capper' => new DimensionCapper(['db' => TestDb::connection(), 'settings' => $this->settings, 'dimensions' => $this->dimensions]),
        'isPro' => false,
        'resolveElements' => false,
    ]);

    $written = $lite->import(Ga4Dataset::Events, 1, ga4Response([
        ['dims' => ['20260715', 'sign_up'], 'metrics' => ['42']],
    ]));

    expect($written)->toBe(0)
        ->and((new Query())->from(Table::EVENTS_ROLLUP)->count('*', TestDb::connection()))->toEqual(0);
});

test('a day that already has data is skipped', function() {
    // A lived day: totals write a sessions row for 2026-07-15.
    $this->import->import(Ga4Dataset::Totals, 1, ga4Response([
        ['dims' => ['20260715'], 'metrics' => ['10', '0', '0', '10', '10']],
    ]));

    $skip = $this->import->occupiedDates(1, '2026-07-15', '2026-07-15');
    expect($skip)->toHaveKey('2026-07-15');

    // Importing pages for that same day writes nothing.
    $written = $this->import->import(Ga4Dataset::Pages, 1, ga4Response([
        ['dims' => ['20260715', '/pricing'], 'metrics' => ['500', '120', '3600']],
    ]), $skip);

    expect($written)->toBe(0)
        ->and((new Query())->from(Table::PAGES_ROLLUP)->count('*', TestDb::connection()))->toEqual(0);
});
