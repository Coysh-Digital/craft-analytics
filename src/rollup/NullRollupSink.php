<?php

namespace coyshdigital\craftanalytics\rollup;

use yii\base\Component;

/**
 * A sink that counts what it was given and writes nothing.
 *
 * Phase 2 has no rollup tables yet (they arrive in phase 3), so this is what
 * the drain flushes into: it makes the ingestion path fully exercisable —
 * and the benchmark harness measurable — without pretending to store
 * anything. Replaced by the real sink in phase 3.
 */
class NullRollupSink extends Component implements RollupSinkInterface
{
    public int $flushedBuckets = 0;
    public int $flushedViews = 0;
    public int $flushedSessions = 0;

    public function flush(array $buckets, array $closedSessions, ?InteractionBuckets $interactions = null): void
    {
        $this->flushedBuckets += count($buckets);
        $this->flushedSessions += count($closedSessions);

        foreach ($buckets as $bucket) {
            $this->flushedViews += $bucket->views;
        }
    }
}
