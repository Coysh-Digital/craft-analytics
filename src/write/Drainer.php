<?php

namespace coyshdigital\craftanalytics\write;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\rollup\Aggregator;
use coyshdigital\craftanalytics\rollup\RollupSinkInterface;
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
 */
class Drainer extends Component
{
    private const CLAIMED_SUFFIX = '.processing';

    public ?Connection $db = null;
    public ?RollupSinkInterface $sink = null;
    public ?SessionStore $sessions = null;
    public ?SpoolWriter $spool = null;

    /**
     * Drains everything currently spooled.
     */
    public function run(?int $now = null): DrainResult
    {
        $now ??= time();
        $result = new DrainResult();

        foreach ($this->claim() as $file) {
            $this->process($file, $now, $result);
        }

        $this->closeIdleSessions($now, $result);

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
            @unlink($file);
            $result->skippedBatches++;

            return;
        }

        [$hits, $malformed] = $this->readHits($file);
        $result->malformedLines += $malformed;

        if ($hits === []) {
            $this->commit($batchId, [], []);
            @unlink($file);

            return;
        }

        $aggregator = new Aggregator();
        foreach ($hits as $hit) {
            $aggregator->add($hit);
        }

        foreach ($this->deltas($hits) as $delta) {
            $this->sessions()->apply($delta, $batchId);
        }

        $closed = $this->stageIdleSessions($now, $batchId);

        $this->commit($batchId, $aggregator->buckets(), $closed);

        // Past the commit the batch is counted; dropping the closed records
        // and the file is cleanup, and is safe to redo.
        foreach ($closed as $session) {
            $this->sessions()->delete($session);
        }

        @unlink($file);

        $result->batches++;
        $result->hits += count($hits);
        $result->buckets += $aggregator->bucketCount();
        $result->closedSessions += count($closed);
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
     */
    private function commit(string $batchId, array $buckets, array $closed): void
    {
        $db = $this->db();
        $transaction = $db->beginTransaction();

        try {
            $this->sink()->flush($buckets, $closed);

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

            if ($hit === null || $hit->siteId === 0 || $hit->visitorHash === '') {
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
