<?php

namespace coyshdigital\craftanalytics\write;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\rollup\Aggregator;
use coyshdigital\craftanalytics\rollup\GoalMatcher;
use coyshdigital\craftanalytics\rollup\JourneyRecorder;
use coyshdigital\craftanalytics\rollup\RollupSinkInterface;
use coyshdigital\craftanalytics\services\IdentityService;
use coyshdigital\craftanalytics\session\Session;
use coyshdigital\craftanalytics\session\SessionDelta;
use coyshdigital\craftanalytics\session\SessionStore;
use Craft;
use yii\base\Component;
use yii\db\Connection;
use yii\db\Query;

/**
 * Turns spooled hits into rollups.
 *
 * The design constraint is that killing this process at any instant must
 * neither lose a batch nor count one twice. That is achieved with three
 * moves, and the tests pin all three:
 *
 * 1. **Claim by rename.** The live spool is renamed before it is read, so
 *    appenders never race the reader and a batch's contents are fixed at
 *    claim time. A crash leaves the claimed file on disk, so it is replayed.
 * 2. **Commit once, marked.** The sink's writes and a `drain_log` marker
 *    share one transaction. A replayed batch whose marker is present has
 *    already been counted, so it is dropped rather than re-applied.
 * 3. **Idempotent session updates.** Session records carry the batch that
 *    last touched them and the batch that closed them, so a replay before
 *    the commit is absorbed, and a session closed by an already-committed
 *    batch is discarded instead of being counted again.
 *
 * Those three make replay safe, which is what makes the fourth affordable:
 *
 * 4. **Bounded retries, then quarantine.** A batch that throws is retried,
 *    because most failures are transient. A batch that keeps throwing is
 *    moved aside rather than retried forever - it would otherwise hold every
 *    later batch behind it and stop the pipeline for good, which is exactly
 *    what an unprocessable record used to do.
 */
class Drainer extends Component
{
    private const CLAIMED_SUFFIX = '.processing';

    /** Where a batch goes once retrying it has stopped being worth it. */
    private const FAILED_SUFFIX = '.failed';

    /** Sidecar recording how many times a claimed batch has thrown. */
    private const ATTEMPTS_SUFFIX = '.attempts';

    /**
     * Failures a batch gets before it is quarantined.
     *
     * More than one because the commonest failures — a deadlock, a dropped
     * connection — are transient and the batch is perfectly good. Not
     * unlimited because the rest are not: a batch that cannot be processed
     * retried forever is an outage, since every later batch queues behind it.
     */
    private const MAX_ATTEMPTS = 3;

    public ?Connection $db = null;
    public ?RollupSinkInterface $sink = null;
    public ?SessionStore $sessions = null;
    public ?SpoolWriter $spool = null;
    public ?JourneyRecorder $journeys = null;
    public ?GoalMatcher $goalMatcher = null;

    /** Settings override; defaults to the plugin's. Set in tests. */
    public ?Settings $settings = null;

    /**
     * Drains everything currently spooled.
     */
    public function run(?int $now = null): DrainResult
    {
        $now ??= time();
        $result = new DrainResult();

        foreach ($this->claim() as $file) {
            // One bad batch costs that batch. Letting it out of here would
            // strand its file as claimed-but-unfinished, and every subsequent
            // run would reclaim it, fail on it again, and never reach the
            // batches queued behind it.
            try {
                $this->process($file, $now, $result);
            } catch (\Throwable $e) {
                $this->recordFailure($file, $e, $result);
            }
        }

        try {
            $this->closeIdleSessions($now, $result);
        } catch (\Throwable $e) {
            // No file to quarantine: the fault is a session in the hot layer,
            // which the next run re-stages under a fresh batch id. Report it
            // rather than taking the run's completed batches down with it.
            $result->failedBatches++;
            Craft::error('Failed to close idle sessions: ' . $e->getMessage(), __METHOD__);
        }

        return $result;
    }

    /**
     * Takes ownership of the current spool, plus anything a previous run
     * claimed but did not finish.
     *
     * @return string[]
     */
    private function claim(): array
    {
        $dir = $this->spool()->spoolDir();
        $live = $this->spool()->spoolPath();

        if (is_file($live) && filesize($live) > 0) {
            $claimed = $dir . DIRECTORY_SEPARATOR . 'spool-' . bin2hex(random_bytes(8)) . self::CLAIMED_SUFFIX;

            // Atomic within the filesystem: appenders holding the old inode
            // finish their line, and the next write recreates the live file.
            @rename($live, $claimed);
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*' . self::CLAIMED_SUFFIX) ?: [];
        sort($files);

        return $files;
    }

    private function process(string $file, int $now, DrainResult $result): void
    {
        // Stable across replays: the name is fixed at claim time and survives
        // a crash, so a replayed file presents the same batch identity.
        $batchId = basename($file, self::CLAIMED_SUFFIX);

        if ($this->isCommitted($batchId)) {
            $this->discard($file);
            $result->skippedBatches++;

            return;
        }

        [$hits, $malformed] = $this->readHits($file);
        $result->malformedLines += $malformed;

        if ($hits === []) {
            $this->commit($batchId, [], []);
            $this->discard($file);

            return;
        }

        $aggregator = new Aggregator(null, $this->settings());
        foreach ($hits as $hit) {
            $aggregator->add($hit);
        }

        $deltas = $this->deltas($hits);

        // Goals are matched here, against the hits, because this is the last
        // moment they exist. By the time the session closes the paths and
        // event names are gone — only the handles that matched survive.
        $this->goalMatcher()->matchBatch($hits, $deltas);

        foreach ($deltas as $delta) {
            $this->sessions()->apply($delta, $batchId);
        }

        $closed = $this->stageIdleSessions($now, $batchId);

        $this->commit($batchId, $aggregator->buckets(), $closed, $hits, $aggregator->interactions);

        // Past the commit the batch is counted; dropping the closed records
        // and the file is cleanup, and is safe to redo.
        foreach ($closed as $session) {
            $this->sessions()->delete($session);
        }

        $this->discard($file);

        $result->batches++;
        $result->hits += count($hits);
        $result->buckets += $aggregator->bucketCount();
        $result->closedSessions += count($closed);
    }

    /**
     * Drops a finished batch and the failure record it may have accumulated,
     * so an earlier transient failure can't count against a later batch that
     * happens to reuse the name.
     */
    private function discard(string $file): void
    {
        @unlink($file);
        $this->forgetAttempts($file);
    }

    /**
     * Drops a batch's failure record, if it ever earned one. Most never do,
     * so the existence check is the common path rather than the exception.
     */
    private function forgetAttempts(string $file): void
    {
        $path = $file . self::ATTEMPTS_SUFFIX;

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Counts a batch's failure, and quarantines it once retrying has stopped
     * being worth it.
     *
     * Quarantine renames rather than deletes: the batch is parked where it can
     * no longer block the queue, but its hits are still on disk and
     * `drain/retry` puts them back. The file keeps its name up to the suffix,
     * so a retried batch presents the same identity to `drain_log` and cannot
     * be counted twice if it had in fact partly committed.
     */
    private function recordFailure(string $file, \Throwable $e, DrainResult $result): void
    {
        $result->failedBatches++;
        $attempts = $this->countAttempt($file);

        if ($attempts < self::MAX_ATTEMPTS) {
            Craft::error(sprintf(
                'Drain batch %s failed (attempt %d of %d), and will be retried: %s',
                basename($file, self::CLAIMED_SUFFIX),
                $attempts,
                self::MAX_ATTEMPTS,
                $e->getMessage(),
            ), __METHOD__);

            return;
        }

        @rename($file, substr($file, 0, -strlen(self::CLAIMED_SUFFIX)) . self::FAILED_SUFFIX);
        $this->forgetAttempts($file);
        $result->quarantinedBatches++;

        Craft::error(sprintf(
            'Drain batch %s failed %d times and has been quarantined; its hits are not counted. '
            . 'Run craft-analytics/drain/retry once the cause is fixed. Last error: %s',
            basename($file, self::CLAIMED_SUFFIX),
            $attempts,
            $e->getMessage(),
        ), __METHOD__);
    }

    /**
     * Records one more failure against a claimed batch, and returns the total.
     *
     * Kept in a sidecar rather than in the file's name because the name *is*
     * the batch identity — renaming to count attempts would make a replay look
     * like a new batch and defeat the commit marker.
     */
    private function countAttempt(string $file): int
    {
        $path = $file . self::ATTEMPTS_SUFFIX;
        $attempts = (is_file($path) ? (int)file_get_contents($path) : 0) + 1;
        @file_put_contents($path, (string)$attempts);

        return $attempts;
    }

    /**
     * Puts quarantined batches back in the queue.
     *
     * @return int how many were requeued
     */
    public function retryFailed(): int
    {
        $dir = $this->spool()->spoolDir();
        $requeued = 0;

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*' . self::FAILED_SUFFIX) ?: [] as $file) {
            $claimed = substr($file, 0, -strlen(self::FAILED_SUFFIX)) . self::CLAIMED_SUFFIX;

            if (@rename($file, $claimed)) {
                // A fresh allowance: the operator has said the cause is fixed.
                $this->forgetAttempts($claimed);
                $requeued++;
            }
        }

        return $requeued;
    }

    /**
     * Batches parked by {@see recordFailure()}, waiting on an operator.
     *
     * @return string[]
     */
    public function failedBatches(): array
    {
        return glob($this->spool()->spoolDir() . DIRECTORY_SEPARATOR . '*' . self::FAILED_SUFFIX) ?: [];
    }

    /**
     * Closes sessions that went idle with no new hits to trigger a drain —
     * otherwise a visitor who leaves and never comes back would keep an open
     * session forever.
     */
    private function closeIdleSessions(int $now, DrainResult $result): void
    {
        $batchId = 'idle-' . bin2hex(random_bytes(8));
        $closed = $this->stageIdleSessions($now, $batchId);

        if ($closed === []) {
            return;
        }

        $this->commit($batchId, [], $closed);

        foreach ($closed as $session) {
            $this->sessions()->delete($session);
        }

        $result->closedSessions += count($closed);
    }

    /**
     * Marks idle sessions as belonging to this batch before anything is
     * committed, so a crash can't lose track of which batch owns them.
     *
     * @return Session[]
     */
    private function stageIdleSessions(int $now, string $batchId): array
    {
        $staged = [];

        foreach ($this->sessions()->idleSessions($now) as $session) {
            // Closed and committed by an earlier batch, but the delete never
            // happened (a crash between the two). Its counters are already in
            // the rollups; drop it rather than count it twice.
            if ($session->closedByBatch !== null && $this->isCommitted($session->closedByBatch)) {
                $this->sessions()->delete($session);
                continue;
            }

            $session->closedByBatch = $batchId;
            $this->sessions()->save($session);
            $staged[] = $session;
        }

        return $staged;
    }

    /**
     * @param array<string,\coyshdigital\craftanalytics\rollup\PageBucket> $buckets
     * @param Session[] $closed
     * @param Hit[] $hits the batch's hits, for the consented journeys layer
     */
    private function commit(
        string $batchId,
        array $buckets,
        array $closed,
        array $hits = [],
        ?\coyshdigital\craftanalytics\rollup\InteractionBuckets $interactions = null,
    ): void {
        $db = $this->db();
        $transaction = $db->beginTransaction();

        try {
            $this->sink()->flush($buckets, $closed, $interactions);

            // Inside the same transaction as the batch marker: a replayed
            // batch is skipped wholesale, so these rows cannot double up.
            $this->journeys()->record($hits);

            $db->createCommand()->insert(Table::DRAIN_LOG, [
                'batchId' => $batchId,
                'driver' => 'spool',
                'committedAt' => gmdate('Y-m-d H:i:s'),
            ])->execute();

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    private function isCommitted(string $batchId): bool
    {
        return (new Query())
            ->from(Table::DRAIN_LOG)
            ->where(['batchId' => $batchId])
            ->exists($this->db());
    }

    /**
     * @param Hit[] $hits
     * @return array<string,SessionDelta>
     */
    private function deltas(array $hits): array
    {
        $deltas = [];

        foreach ($hits as $hit) {
            $key = $hit->siteId . ':' . $hit->sessionKey;

            if (isset($deltas[$key])) {
                $deltas[$key]->add($hit);
            } else {
                $deltas[$key] = SessionDelta::fromHit($hit);
            }
        }

        return $deltas;
    }

    /**
     * @return array{0: Hit[], 1: int} hits, and the count of lines that
     *                                 weren't parseable
     */
    private function readHits(string $file): array
    {
        $hits = [];
        $malformed = 0;
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return [[], 0];
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $hit = Hit::decode($line);

            // The hash is checked here, not where it is finally used: a line
            // the sketch cannot accept is worth one increment of a counter at
            // the boundary, and is worth nothing at all inside a transaction.
            if ($hit === null || $hit->siteId === 0 || !IdentityService::isValidHash($hit->visitorHash)) {
                $malformed++;
                continue;
            }

            $hits[] = $hit;
        }

        fclose($handle);

        return [$hits, $malformed];
    }

    private function sink(): RollupSinkInterface
    {
        return $this->sink ??= Plugin::getInstance()->getRollupSink();
    }

    private function settings(): Settings
    {
        return $this->settings ??= Plugin::getInstance()->getSettings();
    }

    private function journeys(): JourneyRecorder
    {
        return $this->journeys ??= new JourneyRecorder();
    }

    private function goalMatcher(): GoalMatcher
    {
        return $this->goalMatcher ??= new GoalMatcher();
    }

    private function sessions(): SessionStore
    {
        return $this->sessions ??= Plugin::getInstance()->getSessions();
    }

    private function spool(): SpoolWriter
    {
        return $this->spool ??= new SpoolWriter();
    }

    private function db(): Connection
    {
        return $this->db ??= Craft::$app->getDb();
    }
}
