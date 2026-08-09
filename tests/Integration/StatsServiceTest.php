<?php

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\DateRange;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\services\StatsService;
use coyshdigital\craftanalytics\tests\TestDb;
use coyshdigital\craftanalytics\uniques\Hll;
use coyshdigital\craftanalytics\uniques\HllUniqueCounter;

/** 16 July 2026, midday. */
const STATS_NOW = 1784217600;

beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    $db = TestDb::connection();
    TestDb::dropTables(SchemaBuilder::allTables());
    (new Install(['db' => $db]))->up();

    $this->stats = new StatsService([
        'db' => $db,
        'counter' => new HllUniqueCounter(['settings' => new Settings()]),
        'settings' => new Settings(),
    ]);
});

/** @param int[] $visitorIds */
function writePage(string $date, int $views, array $visitorIds, int $hour = -1, int $pathDimId = 1): void
{
    $sketch = new Hll(12);
    foreach ($visitorIds as $id) {
        $sketch->add(substr(hash('sha256', "visitor:$id"), 0, 16));
    }

    TestDb::connection()->createCommand()->insert(Table::PAGES_ROLLUP, [
        'siteId' => 1,
        'date' => $date,
        'hour' => $hour,
        'pathDimId' => $pathDimId,
        'views' => $views,
        'uniques' => new yii\db\PdoValue($sketch->serialize(), PDO::PARAM_LOB),
    ])->execute();
}

test('unique visitors across a range are merged, not summed', function() {
    // The same 500 people, every day for three days.
    $visitors = range(1, 500);
    foreach (['2026-07-14', '2026-07-15', '2026-07-16'] as $date) {
        writePage($date, 800, $visitors);
    }

    $range = DateRange::fromPreset(DateRange::PRESET_7_DAYS, STATS_NOW);
    $totals = $this->stats->totals(1, $range);

    expect($totals['views'])->toBe(2400);

    // Summing the daily figures would report 1,500 visitors. The truth for
    // these sketches is ~500 — and that difference is the entire reason the
    // sketch is mergeable.
    expect($totals['uniques'])->toBeGreaterThan(470)
        ->and($totals['uniques'])->toBeLessThan(530);
});

test('a range with genuinely different visitors each day adds up', function() {
    writePage('2026-07-14', 100, range(1, 200));
    writePage('2026-07-15', 100, range(201, 400));
    writePage('2026-07-16', 100, range(401, 600));

    $totals = $this->stats->totals(1, DateRange::fromPreset(DateRange::PRESET_7_DAYS, STATS_NOW));

    expect($totals['uniques'])->toBeGreaterThan(570)
        ->and($totals['uniques'])->toBeLessThan(630);
});

test('uniques are merged across pages within a day too', function() {
    // One person, two pages: one visitor, two views.
    writePage('2026-07-16', 1, [1], pathDimId: 1);
    writePage('2026-07-16', 1, [1], pathDimId: 2);

    $totals = $this->stats->totals(1, DateRange::fromPreset(DateRange::PRESET_TODAY, STATS_NOW));

    expect($totals['views'])->toBe(2)
        ->and($totals['uniques'])->toBe(1);
});

test('the trend fills quiet days with zero rather than skipping them', function() {
    writePage('2026-07-16', 50, [1, 2, 3]);

    $trend = $this->stats->trend(1, DateRange::fromPreset(DateRange::PRESET_7_DAYS, STATS_NOW));

    // A gap in a line implies missing data; a quiet day is not missing.
    expect($trend['labels'])->toHaveCount(7)
        ->and($trend['views'])->toHaveCount(7)
        ->and($trend['views'][0])->toBe(0)
        ->and($trend['views'][6])->toBe(50);
});

test('nothing recorded reads as zero, not as an error', function() {
    $totals = $this->stats->totals(1, DateRange::fromPreset(DateRange::PRESET_30_DAYS, STATS_NOW));

    expect($totals['views'])->toBe(0)
        ->and($totals['uniques'])->toBe(0)
        ->and($totals['sessions'])->toBe(0)
        ->and($totals['bounceRate'])->toBe(0.0);
});

test('bounce rate and averages come from the session rollup', function() {
    TestDb::connection()->createCommand()->insert(Table::SESSIONS_ROLLUP, [
        'siteId' => 1, 'date' => '2026-07-16', 'hour' => -1,
        'sessions' => 200, 'bounces' => 90, 'totalDurationMs' => 200 * 45000, 'totalPageviews' => 480,
    ])->execute();

    $totals = $this->stats->totals(1, DateRange::fromPreset(DateRange::PRESET_TODAY, STATS_NOW));

    expect($totals['sessions'])->toBe(200)
        ->and($totals['bounceRate'])->toBe(45.0)
        ->and($totals['avgDurationMs'])->toBe(45000)
        ->and($totals['avgViewsPerSession'])->toBe(2.4);
});

test('another site’s traffic never leaks into this one', function() {
    writePage('2026-07-16', 100, range(1, 50));

    TestDb::connection()->createCommand()->insert(Table::PAGES_ROLLUP, [
        'siteId' => 2, 'date' => '2026-07-16', 'hour' => -1, 'pathDimId' => 1, 'views' => 9999,
    ])->execute();

    $totals = $this->stats->totals(1, DateRange::fromPreset(DateRange::PRESET_TODAY, STATS_NOW));

    expect($totals['views'])->toBe(100);
});

test('element stats cover only that element', function() {
    $db = TestDb::connection();

    foreach ([['2026-07-15', 30, 42], ['2026-07-16', 20, 42], ['2026-07-16', 999, 43]] as [$date, $views, $elementId]) {
        $db->createCommand()->insert(Table::PAGES_ROLLUP, [
            'siteId' => 1, 'date' => $date, 'hour' => -1,
            'pathDimId' => $elementId, 'elementId' => $elementId, 'views' => $views,
        ])->execute();
    }

    $stats = $this->stats->elementStats(1, 42, DateRange::fromPreset(DateRange::PRESET_7_DAYS, STATS_NOW));

    expect($stats['views'])->toBe(50)
        ->and($stats['series'])->toHaveCount(7)
        ->and($stats['series'][6])->toBe(20);
});

test('views for many elements come back in one query', function() {
    $db = TestDb::connection();

    foreach ([[1, 10], [2, 20], [3, 30]] as [$elementId, $views]) {
        $db->createCommand()->insert(Table::PAGES_ROLLUP, [
            'siteId' => 1, 'date' => '2026-07-16', 'hour' => -1,
            'pathDimId' => $elementId, 'elementId' => $elementId, 'views' => $views,
        ])->execute();
    }

    $views = $this->stats->viewsByElement(1, [1, 2, 3, 99], DateRange::fromPreset(DateRange::PRESET_TODAY, STATS_NOW));

    expect($views)->toBe([1 => 10, 2 => 20, 3 => 30]);
});

test('asking for no elements does not query at all', function() {
    expect($this->stats->viewsByElement(1, [], DateRange::fromPreset(DateRange::PRESET_TODAY, STATS_NOW)))->toBe([]);
});

test('a single day still inside the hourly window is plotted by hour', function() {
    // Dates come from the real clock rather than STATS_NOW: the window is
    // measured against time() inside the compactor, so a fixed date in the past
    // would fall outside it the moment this test got old.
    $today = (new DateTimeImmutable('@' . time()))->format('Y-m-d');

    writePage($today, 5, [1, 2, 3], hour: 9);

    $trend = $this->stats->trend(1, DateRange::custom($today, $today));

    expect($trend['hourly'])->toBeTrue()
        ->and($trend['labels'])->toHaveCount(24)
        ->and($trend['views'][9])->toBe(5);
});

test('a single day past the hourly window is plotted by day, not as 24 zeroes', function() {
    // Compaction folds that day's 24 rows into one at hour = -1, so asking for
    // an hourly axis would read rows that no longer exist and draw a flat line
    // that looks like an outage. Only a custom range can reach this: the today
    // and yesterday presets are always inside the window.
    $old = (new DateTimeImmutable('@' . time()))->modify('-60 days')->format('Y-m-d');

    writePage($old, 42, [1, 2, 3]);

    $trend = $this->stats->trend(1, DateRange::custom($old, $old));

    expect($trend['hourly'])->toBeFalse()
        ->and($trend['labels'])->toBe([$old])
        ->and($trend['views'])->toBe([42]);
});

/**
 * Counts the statements a callable issues.
 *
 * The connection has logging and profiling off, so this turns logging on for
 * the duration and counts the one info message Yii writes per query. Only
 * logging, not profiling: profiling emits a begin and an end message under the
 * same category, which would count every statement more than once.
 */
function countQueries(callable $fn): int
{
    $db = TestDb::connection();
    $logger = Yii::getLogger();

    $wasLogging = $db->enableLogging;
    $db->enableLogging = true;
    $start = count($logger->messages);

    try {
        $fn();
    } finally {
        $db->enableLogging = $wasLogging;
    }

    $queries = 0;

    // Category only. Craft logs the SQL through Yii::debug, so the level is
    // TRACE rather than INFO, and with profiling left off there is exactly
    // one message per statement.
    foreach (array_slice($logger->messages, $start) as $message) {
        if (($message[2] ?? '') === 'yii\db\Command::query') {
            $queries++;
        }
    }

    return $queries;
}

test('the query counter counts queries', function() {
    // This file's performance assertions are only worth anything if the
    // counter is not always returning zero, which is exactly what it did
    // while the connection had logging switched off.
    $queries = countQueries(function() {
        TestDb::connection()->createCommand('SELECT 1')->queryScalar();
        TestDb::connection()->createCommand('SELECT 1')->queryScalar();
    });

    expect($queries)->toBe(2);
});

test('a trend over a long range does not query once per day', function() {
    // Three months of daily rows.
    $cursor = new DateTimeImmutable('2026-01-01');
    for ($i = 0; $i < 90; $i++) {
        writePage($cursor->format('Y-m-d'), 10, [$i]);
        $cursor = $cursor->modify('+1 day');
    }

    $range = DateRange::custom('2026-01-01', '2026-03-31', STATS_NOW);

    $queries = countQueries(fn() => $this->stats->trend(1, $range));

    // Was one query per day in the range plus one for the views, so ninety-one
    // here and three hundred and sixty-six for a twelve-month chart, on every
    // dashboard load. The number of statements must not scale with the range.
    expect($queries)->toBeLessThan(5);
});

test('a long trend still reports each day separately', function() {
    writePage('2026-01-01', 10, [1, 2, 3]);
    writePage('2026-01-02', 20, [4, 5]);

    $range = DateRange::custom('2026-01-01', '2026-01-03', STATS_NOW);
    $trend = $this->stats->trend(1, $range);

    expect($trend['labels'])->toBe(['2026-01-01', '2026-01-02', '2026-01-03'])
        ->and($trend['views'])->toBe([10, 20, 0])
        // Each day merged on its own, and a day with nothing in it is zero
        // rather than missing.
        ->and($trend['uniques'][0])->toBeGreaterThan(0)
        ->and($trend['uniques'][2])->toBe(0);
});

test('a range that has finished happening is answered from cache the second time', function() {
    writePage('2026-01-01', 10, [1, 2, 3]);

    $this->stats->cache = new yii\caching\ArrayCache();
    $range = DateRange::custom('2026-01-01', '2026-01-31', STATS_NOW);

    $first = $this->stats->totals(1, $range);
    $queries = countQueries(fn() => $this->stats->totals(1, $range));

    expect($queries)->toBe(0)
        ->and($this->stats->totals(1, $range))->toBe($first);
});

test('a range including today is never cached', function() {
    // The real current date, not a frozen one: whether a range is still being
    // written to is judged against the actual clock, which is the only thing
    // a running site can ask.
    $today = (new DateTimeImmutable('now', new DateTimeZone(Craft::$app->getTimeZone())))->format('Y-m-d');
    writePage($today, 10, [1]);

    $this->stats->cache = new yii\caching\ArrayCache();
    $range = DateRange::custom($today, $today);

    $this->stats->totals(1, $range);
    $queries = countQueries(fn() => $this->stats->totals(1, $range));

    // Today is still being written to while it is being read, and Real-time
    // and the current day are what somebody watches as they happen.
    expect($queries)->toBeGreaterThan(0);
});
