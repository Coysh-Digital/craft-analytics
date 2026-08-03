<?php

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\enums\DimensionType;
use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\rollup\Aggregator;
use coyshdigital\craftanalytics\rollup\DbRollupSink;
use coyshdigital\craftanalytics\rollup\DimensionCapper;
use coyshdigital\craftanalytics\rollup\ProRollupWriter;
use coyshdigital\craftanalytics\services\ChannelClassifier;
use coyshdigital\craftanalytics\services\DeviceParser;
use coyshdigital\craftanalytics\services\DimensionsService;
use coyshdigital\craftanalytics\tests\TestDb;
use coyshdigital\craftanalytics\uniques\HllUniqueCounter;
use yii\db\Query;

/**
 * The `direct` and `queue` writers apply one hit at a time through HitApplier,
 * rather than through the drain.
 *
 * Both used to collect the interaction buckets - events, scroll depth,
 * outbound clicks, site searches - and then never hand them to the rollup
 * sink, so every one of them was silently discarded on any site not using the
 * default `spool` mode.
 *
 * These reproduce HitApplier's sequence rather than invoking it: it resolves
 * its sink and session store from the plugin instance, which this bootstrap
 * has no room for. So they pin the half that was wrong - that a single-hit
 * aggregation writes every rollup, interactions included - and applyOne()
 * below has to stay in step with HitApplier::apply() for that to keep meaning
 * something.
 */
beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    $db = TestDb::connection();
    TestDb::dropTables(SchemaBuilder::allTables());
    (new Install(['db' => $db]))->up();

    $this->settings = new Settings();
    $this->dimensions = new DimensionsService(['db' => $db]);

    $capper = new DimensionCapper([
        'db' => $db,
        'settings' => $this->settings,
        'dimensions' => $this->dimensions,
    ]);

    $this->sink = new DbRollupSink([
        'db' => $db,
        'settings' => $this->settings,
        'counter' => new HllUniqueCounter(['settings' => $this->settings]),
        'capper' => $capper,
        'channels' => new ChannelClassifier(),
        'devices' => new DeviceParser(),
        // Pro, so the interaction rollups are actually written — and it needs
        // the same capper, or it reaches for the plugin instance.
        'pro' => new ProRollupWriter([
            'db' => $db,
            'settings' => $this->settings,
            'capper' => $capper,
            'isPro' => true,
        ]),
    ]);
});

/**
 * What HitApplier does for one hit: aggregate it, then flush buckets *and*
 * interactions. The session lookup it does first is not modelled here — there
 * is no session, which is the first-hit case, and then the hit's own referrer
 * is the acquisition referrer.
 */
function applyOne(object $ctx, Hit $hit): void
{
    $aggregator = new Aggregator(new DateTimeZone('UTC'), new Settings());
    $aggregator->add($hit, $hit->referrer);

    $ctx->sink->flush($aggregator->buckets(), [], $aggregator->interactions);
}

function directHit(array $overrides = []): Hit
{
    return new Hit(
        siteId: 1,
        path: $overrides['path'] ?? '/pricing',
        visitorHash: 'aaaaaaaaaaaaaaaa',
        sessionKey: 'sess-a',
        timestamp: mktime(10, 0, 0, 7, 16, 2026),
        referrer: $overrides['referrer'] ?? 'https://www.google.com/',
        dwellMs: 0,
        kind: $overrides['kind'] ?? Hit::KIND_VIEW,
        eventName: $overrides['eventName'] ?? null,
        eventValue: $overrides['eventValue'] ?? null,
        target: $overrides['target'] ?? null,
        scrollBucket: $overrides['scrollBucket'] ?? null,
    );
}

test('a single pageview still lands in the pages rollup', function() {
    applyOne($this, directHit());

    expect((new Query())->from(Table::PAGES_ROLLUP)->count('*', TestDb::connection()))->toBe(1);
});

test('an event written one hit at a time is not discarded', function() {
    applyOne($this, directHit(['kind' => Hit::KIND_EVENT, 'eventName' => 'newsletter-signup']));

    $row = (new Query())
        ->from(['e' => Table::EVENTS_ROLLUP])
        ->innerJoin(['d' => Table::DIMENSIONS], '[[d]].[[id]] = [[e]].[[eventNameDimId]]')
        ->select(['name' => 'd.value', 'count' => 'e.count'])
        ->one(TestDb::connection());

    expect($row)->not->toBeFalse()
        ->and($row['name'])->toBe('newsletter-signup')
        ->and((int)$row['count'])->toBe(1);
});

test('scroll depth written one hit at a time is not discarded', function() {
    applyOne($this, directHit(['scrollBucket' => 75]));

    $row = (new Query())->from(Table::SCROLL_ROLLUP)->one(TestDb::connection());

    expect($row)->not->toBeFalse()
        ->and((int)$row['bucket'])->toBe(75);
});

test('an outbound click written one hit at a time is not discarded', function() {
    applyOne($this, directHit([
        'kind' => Hit::KIND_OUTBOUND,
        'target' => 'https://craftcms.com/features',
    ]));

    expect((new Query())->from(Table::OUTBOUND_ROLLUP)->count('*', TestDb::connection()))->toBe(1);
});

test('the page source is recorded one hit at a time too', function() {
    applyOne($this, directHit());

    $row = (new Query())
        ->from(['p' => Table::PAGE_SOURCES_ROLLUP])
        ->leftJoin(['d' => Table::DIMENSIONS], '[[d]].[[id]] = [[p]].[[refHostDimId]]')
        ->select(['host' => 'd.value', 'views' => 'p.views'])
        ->one(TestDb::connection());

    expect($row)->not->toBeFalse()
        ->and($row['host'])->toBe('www.google.com')
        ->and((int)$row['views'])->toBe(1);
});

test('the path a direct-written interaction happened on is kept', function() {
    // The pathDimId on the events rollup is what the per-page screen filters
    // on; losing it here would leave that screen empty for direct-mode sites.
    applyOne($this, directHit(['kind' => Hit::KIND_EVENT, 'eventName' => 'faq-expand', 'path' => '/guides/setup']));

    $pathDimId = $this->dimensions->getId(DimensionType::Path, '/guides/setup');
    $count = (new Query())
        ->from(Table::EVENTS_ROLLUP)
        ->where(['pathDimId' => $pathDimId])
        ->count('*', TestDb::connection());

    expect($pathDimId)->not->toBeNull()
        ->and($count)->toBe(1);
});
