<?php

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\Campaign;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\rollup\Aggregator;
use coyshdigital\craftanalytics\rollup\DimensionCapper;
use coyshdigital\craftanalytics\rollup\ProRollupWriter;
use coyshdigital\craftanalytics\services\DimensionsService;
use coyshdigital\craftanalytics\services\FunnelsService;
use coyshdigital\craftanalytics\services\GoalsService;
use coyshdigital\craftanalytics\session\Session;
use coyshdigital\craftanalytics\tests\TestDb;
use yii\db\Query;

beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    $db = TestDb::connection();
    TestDb::dropTables(SchemaBuilder::allTables());
    (new Install(['db' => $db]))->up();

    $this->settings = new Settings();
});

function proWriter(object $ctx, array $overrides = []): ProRollupWriter
{
    $db = TestDb::connection();

    return new ProRollupWriter(array_merge([
        'db' => $db,
        'settings' => $ctx->settings,
        'capper' => new DimensionCapper([
            'db' => $db,
            'settings' => $ctx->settings,
            'dimensions' => new DimensionsService(['db' => $db]),
        ]),
        'isPro' => true,
        // Goals and funnels read the same test database as everything else.
        // Both tables are empty here, so no session converts anything - the
        // campaign attribution under test is unaffected either way.
        'goals' => new GoalsService(['db' => $db]),
        'funnels' => new FunnelsService(['db' => $db]),
    ], $overrides));
}

/** @param array<int,Campaign> $campaigns */
function sessionWith(array $campaigns, int $pageviews = 3, string $country = ''): Session
{
    return new Session(
        siteId: 1,
        sessionKey: 'k',
        visitorHash: 'aaaaaaaaaaaaaaaa',
        startedAt: mktime(10, 0, 0, 7, 16, 2026),
        lastSeenAt: mktime(10, 5, 0, 7, 16, 2026),
        pageviews: $pageviews,
        entryPath: '/',
        lastPath: '/pricing',
        campaigns: array_map(static fn(Campaign $c) => $c->toArray(), $campaigns),
        countryCode: $country,
    );
}

function campaignRows(): array
{
    return (new Query())->from(Table::CAMPAIGNS_ROLLUP)->all(TestDb::connection());
}

test('a campaign gets credit when its session closes', function() {
    proWriter($this)->writeSession(sessionWith([new Campaign('newsletter', 'email', 'spring')]), '2026-07-16');

    $rows = campaignRows();

    expect($rows)->toHaveCount(1)
        ->and((float)$rows[0]['sessions'])->toBe(1.0)
        ->and((float)$rows[0]['bounces'])->toBe(0.0);
});

test('nothing is written on Lite', function() {
    // Gated at the service, so no route or event can reach around it.
    proWriter($this, ['isPro' => false])
        ->writeSession(sessionWith([new Campaign('newsletter')]), '2026-07-16');

    expect(campaignRows())->toBeEmpty();
});

test('nothing is written when campaign tracking is off', function() {
    $this->settings->enableCampaigns = false;

    proWriter($this)->writeSession(sessionWith([new Campaign('newsletter')]), '2026-07-16');

    expect(campaignRows())->toBeEmpty();
});

test('last-click credits only the final touch', function() {
    $this->settings->attributionModel = 'last-click';

    proWriter($this)->writeSession(sessionWith([
        new Campaign('google', 'cpc'),
        new Campaign('newsletter', 'email'),
    ]), '2026-07-16');

    $rows = campaignRows();

    // One row, not two: a touch with no credit gets no row.
    expect($rows)->toHaveCount(1)
        ->and((float)$rows[0]['sessions'])->toBe(1.0);

    $source = (new Query())->select('value')->from(Table::DIMENSIONS)
        ->where(['id' => $rows[0]['sourceDimId']])->scalar(TestDb::connection());
    expect($source)->toBe('newsletter');
});

test('first-click credits only the touch that brought them', function() {
    $this->settings->attributionModel = 'first-click';

    proWriter($this)->writeSession(sessionWith([
        new Campaign('google', 'cpc'),
        new Campaign('newsletter', 'email'),
    ]), '2026-07-16');

    $rows = campaignRows();
    $source = (new Query())->select('value')->from(Table::DIMENSIONS)
        ->where(['id' => $rows[0]['sourceDimId']])->scalar(TestDb::connection());

    expect($rows)->toHaveCount(1)
        ->and($source)->toBe('google');
});

test('linear splits one session without inventing sessions', function() {
    $this->settings->attributionModel = 'linear';

    proWriter($this)->writeSession(sessionWith([
        new Campaign('google', 'cpc'),
        new Campaign('newsletter', 'email'),
        new Campaign('twitter', 'social'),
    ]), '2026-07-16');

    $rows = campaignRows();
    $total = array_sum(array_map(static fn(array $r) => (float)$r['sessions'], $rows));

    // Three rows, one session between them. An attribution report claiming
    // three sessions here would be claiming traffic the site never had.
    expect($rows)->toHaveCount(3)
        ->and($total)->toEqualWithDelta(1.0, 0.0001);
});

test('a bounced session bounces its campaigns by the same fraction', function() {
    $this->settings->attributionModel = 'linear';

    proWriter($this)->writeSession(sessionWith([
        new Campaign('google', 'cpc'),
        new Campaign('newsletter', 'email'),
    ], pageviews: 1), '2026-07-16');

    $bounces = array_sum(array_map(static fn(array $r) => (float)$r['bounces'], campaignRows()));

    expect($bounces)->toEqualWithDelta(1.0, 0.0001);
});

test('two sessions on the same campaign share a row', function() {
    $writer = proWriter($this);
    $writer->writeSession(sessionWith([new Campaign('newsletter', 'email')]), '2026-07-16');
    $writer->writeSession(sessionWith([new Campaign('newsletter', 'email')]), '2026-07-16');

    $rows = campaignRows();

    // C2: rows track cardinality, not traffic.
    expect($rows)->toHaveCount(1)
        ->and((float)$rows[0]['sessions'])->toBe(2.0);
});

test('an absent medium is no dimension rather than an empty one', function() {
    proWriter($this)->writeSession(sessionWith([new Campaign('newsletter')]), '2026-07-16');

    $row = campaignRows()[0];

    expect((int)$row['mediumDimId'])->toBe(0)
        ->and((int)$row['campaignDimId'])->toBe(0);
});

test('geo records a country when a session closes', function() {
    $this->settings->enableGeo = true;

    proWriter($this)->writeSession(sessionWith([], country: 'GB'), '2026-07-16');

    $row = (new Query())->from(Table::GEO_ROLLUP)->one(TestDb::connection());

    expect($row['countryCode'])->toBe('GB')
        ->and((int)$row['sessions'])->toBe(1);
});

test('no country means no geo row', function() {
    $this->settings->enableGeo = true;

    proWriter($this)->writeSession(sessionWith([]), '2026-07-16');

    expect((new Query())->from(Table::GEO_ROLLUP)->count('*', TestDb::connection()))->toEqual(0);
});

// ------------------------------------------------------------ interactions

function interactionHit(string $kind, array $extra = []): Hit
{
    return new Hit(
        siteId: 1,
        path: $extra['path'] ?? '/pricing',
        visitorHash: 'aaaaaaaaaaaaaaaa',
        sessionKey: 'k',
        timestamp: mktime(10, 0, 0, 7, 16, 2026),
        kind: $kind,
        eventName: $extra['eventName'] ?? null,
        eventValue: $extra['eventValue'] ?? null,
        target: $extra['target'] ?? null,
        scrollBucket: $extra['scrollBucket'] ?? null,
    );
}

function aggregateInteractions(array $hits, ?Settings $settings = null): coyshdigital\craftanalytics\rollup\InteractionBuckets
{
    $aggregator = new Aggregator(new DateTimeZone('UTC'), $settings ?? new Settings());

    foreach ($hits as $hit) {
        $aggregator->add($hit);
    }

    return $aggregator->interactions;
}

test('an event is never counted as a pageview', function() {
    $aggregator = new Aggregator(new DateTimeZone('UTC'), new Settings());
    $aggregator->add(interactionHit(Hit::KIND_EVENT, ['eventName' => 'signup', 'eventValue' => 9.99]));

    // An outbound click is something that happened *on* a page, not another
    // view of it.
    expect($aggregator->buckets())->toBeEmpty()
        ->and($aggregator->interactions->events)->toHaveCount(1);
});

test('events collapse by name and sum their values', function() {
    $interactions = aggregateInteractions([
        interactionHit(Hit::KIND_EVENT, ['eventName' => 'purchase', 'eventValue' => 10.0]),
        interactionHit(Hit::KIND_EVENT, ['eventName' => 'purchase', 'eventValue' => 5.5]),
    ]);

    $event = array_values($interactions->events)[0];

    expect($interactions->events)->toHaveCount(1)
        ->and($event['count'])->toBe(2)
        ->and($event['value'])->toBe(15.5);
});

test('outbound clicks are grouped by destination host', function() {
    $interactions = aggregateInteractions([
        interactionHit(Hit::KIND_OUTBOUND, ['target' => 'https://example.com/a']),
        interactionHit(Hit::KIND_OUTBOUND, ['target' => 'https://example.com/a']),
    ]);

    $outbound = array_values($interactions->outbound)[0];

    expect($outbound['host'])->toBe('example.com')
        ->and($outbound['count'])->toBe(2);
});

test('scroll depth rides the pageview rather than being its own event', function() {
    $aggregator = new Aggregator(new DateTimeZone('UTC'), new Settings());
    $aggregator->add(interactionHit(Hit::KIND_VIEW, ['scrollBucket' => 75]));

    // The pageview is still a pageview, and the depth came along with it —
    // no second request, no second view.
    expect($aggregator->buckets())->toHaveCount(1)
        ->and($aggregator->interactions->scroll)->toHaveCount(1)
        ->and(array_values($aggregator->interactions->scroll)[0]['bucket'])->toBe(75);
});

test('an invented scroll bucket is ignored', function(int $bucket) {
    $interactions = aggregateInteractions([interactionHit(Hit::KIND_VIEW, ['scrollBucket' => $bucket])]);

    expect($interactions->scroll)->toBeEmpty();
})->with([1, 33, 99, 101, -25]);

test('site search is read from the URL, needing no JavaScript', function() {
    $settings = new Settings(['trackSiteSearch' => true, 'siteSearchPath' => '/search', 'siteSearchParam' => 'q']);

    $interactions = aggregateInteractions([
        interactionHit(Hit::KIND_VIEW, ['path' => '/search?q=Running+Shoes']),
        interactionHit(Hit::KIND_VIEW, ['path' => '/search?q=running shoes']),
    ], $settings);

    $search = array_values($interactions->searches)[0];

    // Case-folded and trimmed, so one search stays one search.
    expect($interactions->searches)->toHaveCount(1)
        ->and($search['term'])->toBe('running shoes')
        ->and($search['count'])->toBe(2);
});

test('an ordinary page is not mistaken for a search', function() {
    $settings = new Settings(['trackSiteSearch' => true]);

    $interactions = aggregateInteractions([
        interactionHit(Hit::KIND_VIEW, ['path' => '/pricing?q=hello']),
        interactionHit(Hit::KIND_VIEW, ['path' => '/search']),
    ], $settings);

    expect($interactions->searches)->toBeEmpty();
});

test('searches are not recorded unless asked for', function() {
    $interactions = aggregateInteractions([interactionHit(Hit::KIND_VIEW, ['path' => '/search?q=shoes'])]);

    expect($interactions->searches)->toBeEmpty();
});
