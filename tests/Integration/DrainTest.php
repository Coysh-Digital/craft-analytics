<?php

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\rollup\GoalMatcher;
use coyshdigital\craftanalytics\rollup\JourneyRecorder;
use coyshdigital\craftanalytics\rollup\NullRollupSink;
use coyshdigital\craftanalytics\rollup\RollupSinkInterface;
use coyshdigital\craftanalytics\session\SessionStore;
use coyshdigital\craftanalytics\tests\TestDb;
use coyshdigital\craftanalytics\write\Drainer;
use coyshdigital\craftanalytics\write\SpoolWriter;
use yii\caching\ArrayCache;

beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    $db = TestDb::connection();
    TestDb::dropTables(SchemaBuilder::allTables());
    (new Install(['db' => $db]))->up();

    $this->spoolDir = sys_get_temp_dir() . '/ca-spool-' . bin2hex(random_bytes(6));
    mkdir($this->spoolDir, 0775, true);

    $this->settings = new Settings();
    $this->cache = new ArrayCache();
    $this->sink = new NullRollupSink();
});

afterEach(function() {
    // Teardown still runs when beforeEach skipped, at which point there is no
    // spool directory to remove and no test to clean up after.
    if (!isset($this->spoolDir)) {
        return;
    }

    foreach (glob($this->spoolDir . '/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->spoolDir);
});

function makeHit(string $path = '/pricing', string $visitor = 'aaaaaaaaaaaaaaaa', ?int $ts = null): Hit
{
    return new Hit(
        siteId: 1,
        path: $path,
        visitorHash: $visitor,
        sessionKey: 'session-' . $visitor,
        timestamp: $ts ?? time(),
        referrer: '',
        userAgent: 'Chrome/126',
        acceptLanguage: 'en-GB',
    );
}

function makeSpool(string $dir, array $hits): SpoolWriter
{
    $writer = new SpoolWriter(['spoolDir' => $dir, 'settings' => new Settings()]);

    foreach ($hits as $hit) {
        $writer->write($hit);
    }

    return $writer;
}

function makeDrainer(object $ctx, ?RollupSinkInterface $sink = null): Drainer
{
    return new Drainer([
        'db' => TestDb::connection(),
        'sink' => $sink ?? $ctx->sink,
        'settings' => $ctx->settings,
        'spool' => new SpoolWriter(['spoolDir' => $ctx->spoolDir, 'settings' => $ctx->settings]),
        'sessions' => new SessionStore([
            'settings' => $ctx->settings,
            'cache' => $ctx->cache,
            'siteIds' => [1],
        ]),
        // Consent is off by default, so the consented layer writes nothing.
        'journeys' => new JourneyRecorder(['settings' => $ctx->settings, 'isPro' => false]),
        // Goals are Pro; on Lite the matcher does nothing at all.
        'goalMatcher' => new GoalMatcher(isPro: false),
    ]);
}

test('a spooled batch drains into aggregated buckets', function() {
    makeSpool($this->spoolDir, [
        makeHit('/pricing', 'aaaaaaaaaaaaaaaa'),
        makeHit('/pricing', 'bbbbbbbbbbbbbbbb'),
        makeHit('/about', 'aaaaaaaaaaaaaaaa'),
    ]);

    $result = makeDrainer($this)->run();

    expect($result->hits)->toBe(3)
        ->and($result->batches)->toBe(1)
        // Three hits, two pages, one hour: two rows to write, not three. This
        // is C2 in miniature.
        ->and($result->buckets)->toBe(2)
        ->and($this->sink->flushedViews)->toBe(3);
});

test('many hits of one page collapse to a single bucket', function() {
    $hits = [];
    for ($i = 0; $i < 500; $i++) {
        $hits[] = makeHit('/pricing', str_pad((string)$i, 16, '0', STR_PAD_LEFT));
    }
    makeSpool($this->spoolDir, $hits);

    $result = makeDrainer($this)->run();

    expect($result->hits)->toBe(500)
        ->and($result->buckets)->toBe(1)
        ->and($this->sink->flushedViews)->toBe(500);
});

test('the spool is emptied, so a second run drains nothing', function() {
    makeSpool($this->spoolDir, [makeHit(), makeHit()]);

    makeDrainer($this)->run();
    $second = makeDrainer($this)->run();

    expect($second->hits)->toBe(0)
        ->and($this->sink->flushedViews)->toBe(2);
});

test('hits spooled while the drain runs are not lost', function() {
    makeSpool($this->spoolDir, [makeHit('/first')]);

    makeDrainer($this)->run();

    // The claim renamed the file; a worker appending afterwards recreates it.
    makeSpool($this->spoolDir, [makeHit('/second')]);
    $second = makeDrainer($this)->run();

    expect($second->hits)->toBe(1)
        ->and($this->sink->flushedViews)->toBe(2);
});

test('a batch interrupted before commit is replayed exactly once', function() {
    // A claimed-but-unfinished file is what a killed drain leaves behind.
    file_put_contents(
        $this->spoolDir . '/spool-deadbeef.processing',
        makeHit('/pricing')->encode() . "\n" . makeHit('/about')->encode() . "\n",
    );

    $result = makeDrainer($this)->run();

    expect($result->hits)->toBe(2)
        ->and($this->sink->flushedViews)->toBe(2)
        ->and(glob($this->spoolDir . '/*.processing'))->toBeEmpty();
});

test('a batch interrupted after commit is dropped, not counted twice', function() {
    $batchId = 'spool-deadbeef';
    $file = $this->spoolDir . '/' . $batchId . '.processing';
    file_put_contents($file, makeHit('/pricing')->encode() . "\n");

    // Exactly the state a crash between commit and cleanup leaves: the batch
    // is recorded as committed, but its file is still on disk.
    TestDb::connection()->createCommand()->insert(Table::DRAIN_LOG, [
        'batchId' => $batchId,
        'driver' => 'spool',
        'committedAt' => gmdate('Y-m-d H:i:s'),
    ])->execute();

    $result = makeDrainer($this)->run();

    expect($result->skippedBatches)->toBe(1)
        ->and($result->hits)->toBe(0)
        ->and($this->sink->flushedViews)->toBe(0)
        ->and(file_exists($file))->toBeFalse();
});

function failingSink(): NullRollupSink
{
    return new class extends NullRollupSink {
        public function flush(
            array $buckets,
            array $closedSessions,
            ?coyshdigital\craftanalytics\rollup\InteractionBuckets $interactions = null,
        ): void {
            throw new \RuntimeException('sink is down');
        }
    };
}

test('a failing sink rolls the batch back and leaves it to retry intact', function() {
    makeSpool($this->spoolDir, [makeHit('/pricing'), makeHit('/about')]);

    // The failure is reported, not thrown: a caller who drains ten batches
    // should not lose the other nine to this one.
    $failed = makeDrainer($this, failingSink())->run();

    expect($failed->failedBatches)->toBe(1)
        ->and($failed->quarantinedBatches)->toBe(0)
        ->and($failed->hits)->toBe(0);

    // Nothing committed, and the claimed file is still there.
    $committed = TestDb::connection()->createCommand('SELECT COUNT(*) FROM ' . Table::DRAIN_LOG)->queryScalar();
    expect((int)$committed)->toBe(0)
        ->and(glob($this->spoolDir . '/*.processing'))->toHaveCount(1);

    // A later run with a working sink picks it up and counts it once.
    $result = makeDrainer($this)->run();
    expect($result->hits)->toBe(2)
        ->and($this->sink->flushedViews)->toBe(2);
});

test('a batch that keeps failing is quarantined instead of blocking the queue', function() {
    makeSpool($this->spoolDir, [makeHit('/pricing')]);

    // Three failures is the allowance; transient faults get their retries.
    for ($i = 0; $i < 3; $i++) {
        $result = makeDrainer($this, failingSink())->run();
        expect($result->failedBatches)->toBe(1);
    }

    expect($result->quarantinedBatches)->toBe(1)
        ->and(glob($this->spoolDir . '/*.processing'))->toBeEmpty()
        ->and(glob($this->spoolDir . '/*.failed'))->toHaveCount(1);

    // The queue is moving again: a later batch commits rather than sitting
    // behind the poisoned one forever.
    makeSpool($this->spoolDir, [makeHit('/about')]);
    $next = makeDrainer($this)->run();

    expect($next->hits)->toBe(1)
        ->and($next->quarantinedBatches)->toBe(0);
});

test('a quarantined batch is parked, not lost, and can be retried', function() {
    makeSpool($this->spoolDir, [makeHit('/pricing'), makeHit('/about')]);

    for ($i = 0; $i < 3; $i++) {
        makeDrainer($this, failingSink())->run();
    }

    $drainer = makeDrainer($this);
    expect($drainer->failedBatches())->toHaveCount(1)
        ->and($drainer->retryFailed())->toBe(1);

    // Once the cause is fixed the hits are counted — none were thrown away.
    $result = makeDrainer($this)->run();

    expect($result->hits)->toBe(2)
        ->and($this->sink->flushedViews)->toBe(2)
        ->and(glob($this->spoolDir . '/*.failed'))->toBeEmpty();
});

test('a transient failure does not count against a later batch', function() {
    makeSpool($this->spoolDir, [makeHit('/pricing')]);

    // Two failures, then success: the allowance resets, so the next batch to
    // stumble is not quarantined on its first failure.
    makeDrainer($this, failingSink())->run();
    makeDrainer($this, failingSink())->run();
    makeDrainer($this)->run();

    expect(glob($this->spoolDir . '/*.attempts'))->toBeEmpty();

    makeSpool($this->spoolDir, [makeHit('/about')]);
    $result = makeDrainer($this, failingSink())->run();

    expect($result->failedBatches)->toBe(1)
        ->and($result->quarantinedBatches)->toBe(0);
});

test('malformed lines are discarded without taking the batch down', function() {
    makeSpool($this->spoolDir, [makeHit('/pricing')]);
    file_put_contents($this->spoolDir . '/spool.ndjson', "not json at all\n{\"si\":0}\n", FILE_APPEND);

    $result = makeDrainer($this)->run();

    expect($result->hits)->toBe(1)
        ->and($result->malformedLines)->toBe(2)
        ->and($this->sink->flushedViews)->toBe(1);
});

test('a hash the sketch cannot take is dropped at the boundary, not mid-commit', function() {
    makeSpool($this->spoolDir, [makeHit('/pricing', 'aaaaaaaaaaaaaaaa')]);

    // Every shape of wrong: too short, too long, right length but not hex.
    // A build with a different HASH_BYTES mints exactly the first of these.
    foreach (['deadbeef', 'aaaaaaaaaaaaaaaaa', 'ZZZZZZZZZZZZZZZZ', ''] as $bad) {
        file_put_contents(
            $this->spoolDir . '/spool.ndjson',
            makeHit('/about', $bad)->encode() . "\n",
            FILE_APPEND,
        );
    }

    $result = makeDrainer($this)->run();

    expect($result->malformedLines)->toBe(4)
        ->and($result->failedBatches)->toBe(0)
        // The good hit still commits — one bad line costs one line.
        ->and($result->hits)->toBe(1)
        ->and($this->sink->flushedViews)->toBe(1);
});

/**
 * The real sink, with the sketch driver — the combination the wedge needed,
 * and the default on any site without Redis.
 */
function sketchSink(Settings $settings): coyshdigital\craftanalytics\rollup\DbRollupSink
{
    $db = TestDb::connection();

    return new coyshdigital\craftanalytics\rollup\DbRollupSink([
        'db' => $db,
        'settings' => $settings,
        'counter' => new coyshdigital\craftanalytics\uniques\HllUniqueCounter(['settings' => $settings]),
        'capper' => new coyshdigital\craftanalytics\rollup\DimensionCapper([
            'db' => $db,
            'settings' => $settings,
            'dimensions' => new coyshdigital\craftanalytics\services\DimensionsService(['db' => $db]),
        ]),
        'channels' => new coyshdigital\craftanalytics\services\ChannelClassifier(),
        'devices' => new coyshdigital\craftanalytics\services\DeviceParser(),
        'pro' => new coyshdigital\craftanalytics\rollup\ProRollupWriter([
            'db' => $db,
            'settings' => $settings,
            'isPro' => false,
        ]),
    ]);
}

test('a poisoned session in the hot layer does not wedge the drain', function() {
    $now = time();
    $sessions = new SessionStore(['settings' => $this->settings, 'cache' => $this->cache, 'siteIds' => [1]]);

    // A session cached by an earlier build, whose hash this build cannot use.
    // It is already durable in the hot layer and every drain re-stages it, so
    // throwing on it inside the commit stopped the pipeline for good — no
    // amount of retrying could clear it.
    $sessions->apply(
        coyshdigital\craftanalytics\session\SessionDelta::fromHit(makeHit('/pricing', 'deadbeef', $now - 7200)),
        'batch-old',
    );

    makeSpool($this->spoolDir, [makeHit('/about', 'bbbbbbbbbbbbbbbb', $now - 7200)]);

    $result = makeDrainer($this, sketchSink($this->settings))->run($now);

    expect($result->failedBatches)->toBe(0)
        ->and($result->hits)->toBe(1)
        // Both sessions close: the poisoned one is folded in, minus only the
        // unique it could not contribute.
        ->and($result->closedSessions)->toBe(2)
        ->and(glob($this->spoolDir . '/*.processing'))->toBeEmpty();

    // The batch committed, and the poison is gone rather than waiting to be
    // re-staged by the next run.
    $committed = TestDb::connection()->createCommand('SELECT COUNT(*) FROM ' . Table::DRAIN_LOG)->queryScalar();
    expect((int)$committed)->toBe(1);

    $second = makeDrainer($this, sketchSink($this->settings))->run($now);

    expect($second->failedBatches)->toBe(0)
        ->and($sessions->activeSessions(1, $now))->toBeEmpty();
});

test('a corrupt sketch on a rollup row is rebuilt rather than replayed forever', function() {
    $now = time();
    $settings = $this->settings;

    // First drain writes a good sketch onto the sessions row.
    makeSpool($this->spoolDir, [makeHit('/pricing', 'aaaaaaaaaaaaaaaa', $now - 7200)]);
    makeDrainer($this, sketchSink($settings))->run($now);

    $db = TestDb::connection();
    $row = (new yii\db\Query())->from(Table::SESSIONS_ROLLUP)->one($db);
    expect($row)->not->toBeNull();

    // A truncated write, an interrupted restore, a precision change — all land
    // here. It is in the database, so clearing the cache cannot fix it.
    $db->createCommand()->update(
        Table::SESSIONS_ROLLUP,
        ['uniques' => new yii\db\PdoValue('garbage', PDO::PARAM_LOB)],
        ['id' => $row['id']],
    )->execute();

    makeSpool($this->spoolDir, [makeHit('/other', 'bbbbbbbbbbbbbbbb', $now - 7200)]);
    $result = makeDrainer($this, sketchSink($settings))->run($now);

    // The row's unique estimate restarts; every other figure keeps counting.
    expect($result->failedBatches)->toBe(0)
        ->and($result->closedSessions)->toBe(1);

    $after = (new yii\db\Query())->from(Table::SESSIONS_ROLLUP)->one($db);
    expect((int)$after['sessions'])->toBe(2);
});

test('sessions are built from a batch and closed once idle', function() {
    $now = time();

    makeSpool($this->spoolDir, [
        makeHit('/pricing', 'aaaaaaaaaaaaaaaa', $now - 7200),
        makeHit('/about', 'aaaaaaaaaaaaaaaa', $now - 7100),
    ]);

    // Both hits are older than the inactivity window, so the session closes
    // in this same run.
    $result = makeDrainer($this)->run($now);

    expect($result->hits)->toBe(2)
        ->and($result->closedSessions)->toBe(1)
        ->and($this->sink->flushedSessions)->toBe(1);
});

test('an active session stays open and is not folded in yet', function() {
    $now = time();
    makeSpool($this->spoolDir, [makeHit('/pricing', 'aaaaaaaaaaaaaaaa', $now)]);

    $result = makeDrainer($this)->run($now);

    expect($result->closedSessions)->toBe(0)
        ->and($this->sink->flushedSessions)->toBe(0);

    // Once the window passes with no further hits, the drain closes it even
    // though there is nothing new to drain.
    $sessions = new SessionStore(['settings' => $this->settings, 'cache' => $this->cache, 'siteIds' => [1]]);
    expect($sessions->activeSessions(1, $now))->toHaveCount(1);

    $later = makeDrainer($this)->run($now + $this->settings->sessionWindow + 1);
    expect($later->closedSessions)->toBe(1);
});

test('replaying a batch does not double-count session pageviews', function() {
    $now = time();
    $batchFile = $this->spoolDir . '/spool-cafebabe.processing';
    $lines = makeHit('/a', 'aaaaaaaaaaaaaaaa', $now)->encode() . "\n"
        . makeHit('/b', 'aaaaaaaaaaaaaaaa', $now)->encode() . "\n";

    file_put_contents($batchFile, $lines);
    makeDrainer($this)->run($now);

    // Same batch id, same hits — the shape of a crash before commit followed
    // by a replay.
    file_put_contents($batchFile, $lines);
    makeDrainer($this)->run($now);

    $sessions = new SessionStore(['settings' => $this->settings, 'cache' => $this->cache, 'siteIds' => [1]]);
    $active = $sessions->activeSessions(1, $now);

    expect($active)->toHaveCount(1)
        ->and($active[0]->pageviews)->toBe(2);
});

test('a session closed by a committed batch is never counted twice', function() {
    $now = time();
    $sessions = new SessionStore(['settings' => $this->settings, 'cache' => $this->cache, 'siteIds' => [1]]);

    // An idle session left marked as closed by a batch that did commit: the
    // crash happened after the commit but before the record was deleted.
    $delta = coyshdigital\craftanalytics\session\SessionDelta::fromHit(
        makeHit('/pricing', 'aaaaaaaaaaaaaaaa', $now - 7200),
    );
    $session = $sessions->apply($delta, 'batch-old');
    $session->closedByBatch = 'batch-old';
    $sessions->save($session);

    TestDb::connection()->createCommand()->insert(Table::DRAIN_LOG, [
        'batchId' => 'batch-old',
        'driver' => 'spool',
        'committedAt' => gmdate('Y-m-d H:i:s'),
    ])->execute();

    $result = makeDrainer($this)->run($now);

    // Its counters are already in the rollups; the record is simply dropped.
    expect($result->closedSessions)->toBe(0)
        ->and($this->sink->flushedSessions)->toBe(0)
        ->and($sessions->activeSessions(1, $now))->toBeEmpty();
});
