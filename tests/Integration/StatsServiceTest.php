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
