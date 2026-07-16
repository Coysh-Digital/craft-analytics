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
}
