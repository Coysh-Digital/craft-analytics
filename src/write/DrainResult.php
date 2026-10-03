<?php

namespace coyshdigital\craftanalytics\write;

/**
 * What one drain run did — reported by the console command and asserted on
 * by the tests.
 */
final class DrainResult
{
    public int $batches = 0;
    public int $skippedBatches = 0;
    public int $hits = 0;
    public int $buckets = 0;
    public int $closedSessions = 0;
    public int $malformedLines = 0;

    /**
     * Batches left part-way through because the run's slice budget ran out
     * (see Drainer::$maxChunks). Still claimed, nothing lost; the next pass
     * resumes them.
     */
    public int $deferredBatches = 0;

    /** Batches that threw. Counted whether they were retried or quarantined. */
    public int $failedBatches = 0;

    /** Batches parked after failing too often; their hits are not counted. */
    public int $quarantinedBatches = 0;
}
