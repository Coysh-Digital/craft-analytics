<?php

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\enums\DimensionType;
use coyshdigital\craftanalytics\events\RegisterSegmentsEvent;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\DateRange;
use coyshdigital\craftanalytics\models\SegmentDefinition;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\rollup\DimensionCapper;
use coyshdigital\craftanalytics\rollup\ProRollupWriter;
use coyshdigital\craftanalytics\services\DimensionsService;
use coyshdigital\craftanalytics\services\FunnelsService;
use coyshdigital\craftanalytics\services\GoalsService;
use coyshdigital\craftanalytics\services\SegmentRegistry;
use coyshdigital\craftanalytics\services\StatsService;
use coyshdigital\craftanalytics\session\Session;
use coyshdigital\craftanalytics\tests\TestDb;
use yii\db\Query;

/**
 * Segments, from a closed session to a row.
 *
 * The session grain is the whole design: a visit in a segment is one row a
 * day, however many pages it covered, so a site can segment its traffic
 * without its storage tracking its traffic (C2).
 */
beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    TestDb::dropTables(SchemaBuilder::allTables());
    (new Install(['db' => TestDb::connection()]))->up();

    $this->settings = new Settings();
});

function segmentWriter(object $ctx, bool $isPro = true): ProRollupWriter
{
    $db = TestDb::connection();

    return new ProRollupWriter([
        'db' => $db,
        'settings' => $ctx->settings,
        'capper' => new DimensionCapper([
            'db' => $db,
            'settings' => $ctx->settings,
            'dimensions' => new DimensionsService(['db' => $db]),
        ]),
        'isPro' => $isPro,
        'goals' => new GoalsService(['db' => $db]),
        'funnels' => new FunnelsService(['db' => $db]),
    ]);
}

/** @param array<string,string> $segments */
function segmentedSession(array $segments, int $pageviews = 3, string $key = 'k'): Session
{
    return new Session(
        siteId: 1,
        sessionKey: $key,
        visitorHash: 'aaaaaaaaaaaaaaaa',
        startedAt: mktime(10, 0, 0, 7, 16, 2026),
        lastSeenAt: mktime(10, 5, 0, 7, 16, 2026),
        pageviews: $pageviews,
        entryPath: '/',
        lastPath: '/pricing',
        segments: $segments,
    );
}

/** @return array<int,array<string,mixed>> the rollup, with its values joined back on */
function segmentRows(): array
{
    return (new Query())
        ->select([
            'value' => '[[d]].[[value]]',
            'sessions' => '[[s]].[[sessions]]',
            'views' => '[[s]].[[views]]',
            'bounces' => '[[s]].[[bounces]]',
            'durationMs' => '[[s]].[[durationMs]]',
        ])
        ->from(['s' => Table::SEGMENTS_ROLLUP])
        ->innerJoin(['d' => Table::DIMENSIONS], '[[d]].[[id]] = [[s]].[[segmentDimId]]')
        ->orderBy(['[[d]].[[value]]' => SORT_ASC])
        ->all(TestDb::connection());
}

test('a closed session becomes one row per segment', function() {
    segmentWriter($this)->writeSession(
        segmentedSession(['plan' => 'pro', 'role' => 'member']),
        '2026-07-16',
    );

    $rows = segmentRows();

    expect(array_column($rows, 'value'))->toBe(['plan:pro', 'role:member'])
        ->and((int)$rows[0]['sessions'])->toBe(1)
        ->and((int)$rows[0]['views'])->toBe(3)
        ->and((int)$rows[0]['bounces'])->toBe(0)
        // Five minutes, carried from the session so the report can average it.
        ->and((int)$rows[0]['durationMs'])->toBe(300000);
});

test('a second session in the same segment increments rather than duplicating', function() {
    $writer = segmentWriter($this);
    $writer->writeSession(segmentedSession(['plan' => 'pro'], 3, 'a'), '2026-07-16');
    $writer->writeSession(segmentedSession(['plan' => 'pro'], 2, 'b'), '2026-07-16');

    $rows = segmentRows();

    expect($rows)->toHaveCount(1)
        ->and((int)$rows[0]['sessions'])->toBe(2)
        ->and((int)$rows[0]['views'])->toBe(5);
});

test('a one-page visit counts as a bounce in its segment', function() {
    segmentWriter($this)->writeSession(segmentedSession(['plan' => 'free'], 1), '2026-07-16');

    expect((int)segmentRows()[0]['bounces'])->toBe(1);
});

test('a session with no segments writes nothing', function() {
    // Which is every session on every site that has not written the module
    // code - the ordinary case, and it must cost a query nobody runs.
    segmentWriter($this)->writeSession(segmentedSession([]), '2026-07-16');

    expect(segmentRows())->toBeEmpty();
});

test('nothing is written on Lite', function() {
    segmentWriter($this, isPro: false)->writeSession(
        segmentedSession(['plan' => 'pro']),
        '2026-07-16',
    );

    expect(segmentRows())->toBeEmpty();
});

test('the daily cap folds the tail into __other__ rather than growing forever', function() {
    // A segment whose values are not declared is the site's code to get wrong,
    // and getting it wrong must cost rows, not the database.
    $this->settings->dimensionCap = 3;
    $writer = segmentWriter($this);

    foreach (range(1, 10) as $i) {
        $writer->writeSession(segmentedSession(['plan' => 'tier' . $i], 1, 'k' . $i), '2026-07-16');
    }

    $values = array_column(segmentRows(), 'value');

    expect($values)->toHaveCount(4)
        ->and($values)->toContain(DimensionType::OTHER_VALUE);

    $other = array_values(array_filter(
        segmentRows(),
        static fn(array $row) => $row['value'] === DimensionType::OTHER_VALUE,
    ));

    // The first three distinct values are kept; the other seven are folded.
    expect((int)$other[0]['sessions'])->toBe(7);
});

test('a value containing a colon still splits back into its key and value', function() {
    segmentWriter($this)->writeSession(
        segmentedSession(['plan' => 'pro:annual']),
        '2026-07-16',
    );

    expect(SegmentRegistry::splitDimensionValue(segmentRows()[0]['value']))
        ->toBe(['plan', 'pro:annual']);
});

/** @param SegmentDefinition[] $declared */
function segmentStats(array $declared): StatsService
{
    $registry = new SegmentRegistry(['isPro' => true]);
    $registry->on(
        SegmentRegistry::EVENT_REGISTER_SEGMENTS,
        static function(RegisterSegmentsEvent $event) use ($declared) {
            $event->segments = $declared;
        },
    );

    return new StatsService(['db' => TestDb::connection(), 'segments' => $registry]);
}

test('the report reads the rows back grouped by key', function() {
    $writer = segmentWriter($this);
    $writer->writeSession(segmentedSession(['plan' => 'pro', 'role' => 'member'], 4, 'a'), '2026-07-16');
    $writer->writeSession(segmentedSession(['plan' => 'pro', 'role' => 'member'], 4, 'b'), '2026-07-16');
    $writer->writeSession(segmentedSession(['plan' => 'free'], 1, 'c'), '2026-07-16');

    $stats = segmentStats([
        new SegmentDefinition(key: 'plan', label: 'Plan tier'),
        new SegmentDefinition(key: 'role'),
    ]);

    $result = $stats->segments(1, DateRange::fromPreset(DateRange::PRESET_7_DAYS, mktime(0, 0, 0, 7, 16, 2026)));

    expect($result['other'])->toBe(0)
        ->and(array_column($result['groups'], 'key'))->toBe(['plan', 'role'])
        ->and($result['groups'][0]['label'])->toBe('Plan tier');

    $plan = $result['groups'][0]['rows'];

    // Busiest first, and each row carries its own derived figures.
    expect(array_column($plan, 'value'))->toBe(['pro', 'free'])
        ->and($plan[0]['sessions'])->toBe(2)
        ->and($plan[0]['views'])->toBe(8)
        ->and($plan[0]['bounceRate'])->toBe(0.0)
        // Averaged per session, not summed: two five-minute visits are a
        // five-minute average, not a ten-minute one.
        ->and($plan[0]['avgDurationMs'])->toBe(300000)
        ->and($plan[1]['bounceRate'])->toBe(100.0);
});

test('a segment nobody declares any more is not shown under a heading we lack', function() {
    // The rows stay in the table; the screen simply has no label for them,
    // and inventing one is how a report starts lying quietly.
    segmentWriter($this)->writeSession(segmentedSession(['plan' => 'pro', 'legacy' => 'x']), '2026-07-16');

    $result = segmentStats([new SegmentDefinition(key: 'plan')])
        ->segments(1, DateRange::fromPreset(DateRange::PRESET_7_DAYS, mktime(0, 0, 0, 7, 16, 2026)));

    expect(array_column($result['groups'], 'key'))->toBe(['plan']);
});

test('a site with nothing declared reads nothing, without querying', function() {
    segmentWriter($this)->writeSession(segmentedSession(['plan' => 'pro']), '2026-07-16');

    expect(segmentStats([])->segments(1, DateRange::fromPreset(DateRange::PRESET_7_DAYS, mktime(0, 0, 0, 7, 16, 2026))))
        ->toBe(['groups' => [], 'other' => 0]);
});

test('the __other__ overflow is reported separately, not under a key', function() {
    $this->settings->dimensionCap = 1;
    $writer = segmentWriter($this);
    $writer->writeSession(segmentedSession(['plan' => 'pro'], 1, 'a'), '2026-07-16');
    $writer->writeSession(segmentedSession(['plan' => 'free'], 1, 'b'), '2026-07-16');

    $result = segmentStats([new SegmentDefinition(key: 'plan')])
        ->segments(1, DateRange::fromPreset(DateRange::PRESET_7_DAYS, mktime(0, 0, 0, 7, 16, 2026)));

    expect($result['other'])->toBe(1)
        ->and(array_column($result['groups'][0]['rows'], 'value'))->toBe(['pro']);
});
