<?php

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\Settings;
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
    TestDb::dropTables([Table::DRAIN_LOG, Table::SALTS, Table::DIMENSIONS]);
    (new Install(['db' => $db]))->up();

    $this->spoolDir = sys_get_temp_dir() . '/ca-spool-' . bin2hex(random_bytes(6));
    mkdir($this->spoolDir, 0775, true);

    $this->settings = new Settings();
    $this->cache = new ArrayCache();
    $this->sink = new NullRollupSink();
});

afterEach(function() {
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
        'spool' => new SpoolWriter(['spoolDir' => $ctx->spoolDir, 'settings' => $ctx->settings]),
        'sessions' => new SessionStore([
            'settings' => $ctx->settings,
            'cache' => $ctx->cache,
            'siteIds' => [1],
        ]),
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

test('a failing sink rolls the batch back and leaves it to retry intact', function() {
    makeSpool($this->spoolDir, [makeHit('/pricing'), makeHit('/about')]);

    $failing = new class extends NullRollupSink {
        public function flush(array $buckets, array $closedSessions): void
        {
            throw new \RuntimeException('sink is down');
        }
    };

    expect(fn() => makeDrainer($this, $failing)->run())->toThrow(\RuntimeException::class);

    // Nothing committed, and the claimed file is still there.
    $committed = TestDb::connection()->createCommand('SELECT COUNT(*) FROM ' . Table::DRAIN_LOG)->queryScalar();
    expect((int)$committed)->toBe(0)
        ->and(glob($this->spoolDir . '/*.processing'))->toHaveCount(1);

    // A later run with a working sink picks it up and counts it once.
    $result = makeDrainer($this)->run();
    expect($result->hits)->toBe(2)
        ->and($this->sink->flushedViews)->toBe(2);
});

test('malformed lines are discarded without taking the batch down', function() {
    makeSpool($this->spoolDir, [makeHit('/pricing')]);
    file_put_contents($this->spoolDir . '/spool.ndjson', "not json at all\n{\"si\":0}\n", FILE_APPEND);

    $result = makeDrainer($this)->run();

    expect($result->hits)->toBe(1)
        ->and($result->malformedLines)->toBe(2)
        ->and($this->sink->flushedViews)->toBe(1);
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
