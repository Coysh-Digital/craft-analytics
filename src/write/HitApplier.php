<?php

namespace coyshdigital\craftanalytics\write;

use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\rollup\Aggregator;
use coyshdigital\craftanalytics\rollup\RollupSinkInterface;
use coyshdigital\craftanalytics\session\SessionDelta;
use coyshdigital\craftanalytics\session\SessionStore;
use Craft;
use yii\base\Component;
use yii\db\Connection;

/**
 * Applies a single hit straight to the rollups.
 *
 * Shared by the `direct` writer and the queue job — the paths that handle one
 * hit at a time rather than a spooled batch. Both give up the drain's main
 * advantage (a thousand hits collapsing into a handful of upserts), which is
 * why `spool` remains the default for real traffic.
 */
class HitApplier extends Component
{
    public ?Connection $db = null;
    public ?RollupSinkInterface $sink = null;
    public ?SessionStore $sessions = null;

    public function apply(Hit $hit): void
    {
        // Each hit is its own batch here: there is no spool file to replay,
        // so the id only needs to be unique, not stable.
        $batchId = 'single-' . bin2hex(random_bytes(8));

        $aggregator = new Aggregator(null, Plugin::getInstance()->getSettings());
        $aggregator->add($hit);

        $this->sessions()->apply(SessionDelta::fromHit($hit), $batchId);

        $db = $this->db();
        $transaction = $db->beginTransaction();

        try {
            $this->sink()->flush($aggregator->buckets(), []);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    private function sink(): RollupSinkInterface
    {
        return $this->sink ??= Plugin::getInstance()->getRollupSink();
    }

    private function sessions(): SessionStore
    {
        return $this->sessions ??= Plugin::getInstance()->getSessions();
    }

    private function db(): Connection
    {
        return $this->db ??= Craft::$app->getDb();
    }
}
