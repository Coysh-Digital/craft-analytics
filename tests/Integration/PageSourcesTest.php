<?php

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\enums\Channel;
use coyshdigital\craftanalytics\enums\DimensionType;
use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\DateRange;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\rollup\Aggregator;
use coyshdigital\craftanalytics\rollup\DbRollupSink;
use coyshdigital\craftanalytics\rollup\DimensionCapper;
use coyshdigital\craftanalytics\rollup\ProRollupWriter;
use coyshdigital\craftanalytics\services\ChannelClassifier;
use coyshdigital\craftanalytics\services\DeviceParser;
use coyshdigital\craftanalytics\services\DimensionsService;
use coyshdigital\craftanalytics\services\StatsService;
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

    $this->settings = new Settings();
    $this->dimensions = new DimensionsService(['db' => $db]);
    $this->stats = new StatsService([
        'db' => $db,
        'counter' => new HllUniqueCounter(['settings' => $this->settings]),
    ]);
    $this->sink = new DbRollupSink([
        'db' => $db,
        'settings' => $this->settings,
        'counter' => new HllUniqueCounter(['settings' => $this->settings]),
        'capper' => new DimensionCapper([
            'db' => $db,
            'settings' => $this->settings,
            'dimensions' => $this->dimensions,
        ]),
        'channels' => new ChannelClassifier(),
        'devices' => new DeviceParser(),
        'pro' => new ProRollupWriter(['db' => $db, 'settings' => new Settings(), 'isPro' => false]),
    ]);
});

function sourceHit(
    string $path,
    string $referrer = '',
    string $visitor = 'aaaaaaaaaaaaaaaa',
    string $session = 'sess-a',
    bool $countView = true,
): Hit {
    return new Hit(
        siteId: 1,
        path: $path,
        visitorHash: $visitor,
        sessionKey: $session,
        timestamp: mktime(10, 0, 0, 7, 16, 2026),
        referrer: $referrer,
        countView: $countView,
    );
}

/**
 * Drains hits with an explicit acquisition referrer per session, the way the
 * Drainer does.
 *
 * @param Hit[] $hits
 * @param array<string,string> $acquisition sessionKey => referrer
 */
function flushWithSources(object $ctx, array $hits, array $acquisition = []): void
{
    $aggregator = new Aggregator(new DateTimeZone('UTC'), new Settings());

    foreach ($hits as $hit) {
        $aggregator->add($hit, $acquisition[$hit->sessionKey] ?? $hit->referrer);
    }

    $ctx->sink->flush($aggregator->buckets(), [], $aggregator->interactions);
}

function pageSourceRows(): array
{
    return (new Query())
        ->from(['p' => Table::PAGE_SOURCES_ROLLUP])
        ->leftJoin(['d' => Table::DIMENSIONS], '[[d]].[[id]] = [[p]].[[refHostDimId]]')
        ->select(['p.views', 'p.channel', 'host' => 'd.value', 'p.pathDimId'])
        ->all(TestDb::connection());
}

test('a page records how the visitor reached the site', function() {
    flushWithSources($this, [sourceHit('/pricing', 'https://www.google.com/search?q=x')]);

    $rows = pageSourceRows();

    expect($rows)->toHaveCount(1)
        ->and((int)$rows[0]['views'])->toBe(1)
        ->and((int)$rows[0]['channel'])->toBe(Channel::Search->value)
        ->and($rows[0]['host'])->toBe('www.google.com');
});

test('an interior page is credited to how the visit started, not to the page before it', function() {
    // This is the whole reason the acquisition referrer is threaded through:
    // /pricing was reached by clicking a link on /blog, and the hit's own
    // referrer says so. Classifying that would report the site as its own
    // biggest referrer and answer nobody's question.
    flushWithSources(
        $this,
        [
            sourceHit('/blog', 'https://www.google.com/search?q=x'),
            sourceHit('/pricing', 'https://example.com/blog'),
        ],
        ['sess-a' => 'https://www.google.com/search?q=x'],
    );

    $rows = pageSourceRows();

    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        expect((int)$row['channel'])->toBe(Channel::Search->value)
            ->and($row['host'])->toBe('www.google.com');
    }
});

test('an untagged arrival with no referrer is Direct with no host', function() {
    flushWithSources($this, [sourceHit('/pricing', '')]);

    $rows = pageSourceRows();

    expect($rows)->toHaveCount(1)
        ->and((int)$rows[0]['channel'])->toBe(Channel::Direct->value)
        // 0 means "no referrer host", and must not join to a dimension.
        ->and($rows[0]['host'])->toBeNull();
});

test('many views of the same page from the same source are one row', function() {
    $hits = [];

    for ($i = 0; $i < 50; $i++) {
        $hits[] = sourceHit(
            '/pricing',
            'https://www.google.com/',
            str_pad((string)$i, 16, '0', STR_PAD_LEFT),
            'sess-' . $i,
        );
    }

    flushWithSources($this, $hits);

    $rows = pageSourceRows();

    // Cardinality x time, like every other rollup: 50 views, one row.
    expect($rows)->toHaveCount(1)
        ->and((int)$rows[0]['views'])->toBe(50);
});

test('two sources for the same page stay separate', function() {
    flushWithSources($this, [
        sourceHit('/pricing', 'https://www.google.com/', 'aaaaaaaaaaaaaaaa', 'sess-a'),
        sourceHit('/pricing', 'https://news.ycombinator.com/', 'bbbbbbbbbbbbbbbb', 'sess-b'),
    ]);

    expect(pageSourceRows())->toHaveCount(2);
});

test('a dwell-only beacon does not count its source a second time', function() {
    // countView false is the hybrid-mode dedupe: the server already counted
    // this view, and counting its source again would inflate the referrer.
    flushWithSources($this, [
        sourceHit('/pricing', 'https://www.google.com/'),
        sourceHit('/pricing', 'https://www.google.com/', 'aaaaaaaaaaaaaaaa', 'sess-a', false),
    ]);

    $rows = pageSourceRows();

    expect($rows)->toHaveCount(1)
        ->and((int)$rows[0]['views'])->toBe(1);
});

test('draining twice accumulates onto the same row', function() {
    flushWithSources($this, [sourceHit('/pricing', 'https://www.google.com/')]);
    flushWithSources($this, [sourceHit('/pricing', 'https://www.google.com/', 'bbbbbbbbbbbbbbbb', 'sess-b')]);

    $rows = pageSourceRows();

    expect($rows)->toHaveCount(1)
        ->and((int)$rows[0]['views'])->toBe(2);
});

test('the report reads back what was written, for one page only', function() {
    flushWithSources($this, [
        sourceHit('/pricing', 'https://www.google.com/', 'aaaaaaaaaaaaaaaa', 'sess-a'),
        sourceHit('/blog', 'https://news.ycombinator.com/', 'bbbbbbbbbbbbbbbb', 'sess-b'),
    ]);

    $pricingId = $this->dimensions->getId(DimensionType::Path, '/pricing');
    $sources = $this->stats->pageSources(1, DateRange::fromPreset('30d', mktime(10, 0, 0, 7, 20, 2026)), $pricingId);

    expect($sources)->toHaveCount(1)
        ->and($sources[0]['host'])->toBe('www.google.com')
        ->and($sources[0]['views'])->toBe(1);
});

test('a site with no page-source data yet has no collection date', function() {
    expect($this->stats->pageSourcesSince(1))->toBeNull();
});

test('the collection date is the earliest day recorded', function() {
    flushWithSources($this, [sourceHit('/pricing', 'https://www.google.com/')]);

    expect($this->stats->pageSourcesSince(1))->toBe('2026-07-16');
});
