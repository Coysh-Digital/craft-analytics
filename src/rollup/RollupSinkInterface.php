<?php

namespace coyshdigital\craftanalytics\rollup;

use coyshdigital\craftanalytics\session\Session;

/**
 * Where a drained, aggregated batch lands.
 *
 * The drain owns batching, crash-safety and idempotency; the sink owns the
 * writes. Phase 2 ships only the null sink — phase 3 adds the rollup-table
 * implementation behind this same interface.
 */
interface RollupSinkInterface
{
    /**
     * Persist one batch. Called inside the drain's transaction: throwing
     * rolls the whole batch back, which is the correct response to a failed
     * write — the batch is retried intact on the next run.
     *
     * @param array<string,PageBucket> $buckets
     * @param Session[] $closedSessions sessions that ended in this batch
     * @param InteractionBuckets|null $interactions Pro events, scroll and
     *                                              outbound clicks
     */
    public function flush(array $buckets, array $closedSessions, ?InteractionBuckets $interactions = null): void;
}
