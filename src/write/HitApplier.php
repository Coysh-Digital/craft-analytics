<?php

namespace coyshdigital\craftanalytics\write;

use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\rollup\Aggregator;
use coyshdigital\craftanalytics\rollup\GoalMatcher;
use coyshdigital\craftanalytics\rollup\JourneyRecorder;
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
    public ?GoalMatcher $goalMatcher = null;
    public ?JourneyRecorder $journeys = null;
    public ?Settings $settings = null;

    public function apply(Hit $hit): void
    {
        // Each hit is its own batch here: there is no spool file to replay,
        // so the id only needs to be unique, not stable.
        $batchId = 'single-' . bin2hex(random_bytes(8));

        // Read before apply(): the session's referrer is how the visitor
        // reached the site, and the hit's own referrer is only that for the
        // first page of a visit. Nothing to look up yet on that first hit,
        // which is exactly when the fallback is correct.
        $session = $this->sessions()->get($hit->siteId, $hit->sessionKey);

        $aggregator = new Aggregator(null, $this->settings());
        $aggregator->add($hit, $session !== null ? $session->referrer : $hit->referrer);

        $delta = SessionDelta::fromHit($hit);

        // Matched here for the same reason the drain matches at the batch:
        // this is the last moment the path and the event name exist. By the
        // time the session closes only the handles that matched survive, so a
        // driver that skips this step reports every goal as zero forever.
        $this->goalMatcher()->matchBatch([$hit], [$hit->siteId . ':' . $hit->sessionKey => $delta]);

        $this->sessions()->apply($delta, $batchId);

        $db = $this->db();
        $transaction = $db->beginTransaction();

        try {
            // The interactions have to travel too. Without them the `direct`
            // and `queue` modes silently dropped every event, scroll depth,
            // outbound click and site search: the aggregator collected them
            // and nothing ever asked for them.
            $this->sink()->flush($aggregator->buckets(), [], $aggregator->interactions);

            // Same transaction as the rollup write, matching the drain: a
            // consented visitor's journey is part of what this hit recorded,
            // not a separate bookkeeping step that can survive its rollback.
            $this->journeys()->record([$hit]);

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

    private function goalMatcher(): GoalMatcher
    {
        return $this->goalMatcher ??= new GoalMatcher();
    }

    private function journeys(): JourneyRecorder
    {
        return $this->journeys ??= new JourneyRecorder();
    }

    private function settings(): Settings
    {
        return $this->settings ??= Plugin::getInstance()->getSettings();
    }

    private function db(): Connection
    {
        return $this->db ??= Craft::$app->getDb();
    }
}
