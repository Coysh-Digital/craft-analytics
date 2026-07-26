<?php

namespace coyshdigital\craftanalytics\uniques;

/**
 * Counts distinct visitors without keeping a row per visitor.
 *
 * The contract that matters: `estimate()` over several scopes must **merge**
 * them, never sum them. Summing daily uniques to get a monthly figure
 * double-counts everyone who came back, and is the single most common lie in
 * analytics tooling. Every driver here unions instead.
 */
interface UniqueCounterInterface
{
    /** Driver handle, as used in config. */
    public function name(): string;

    /**
     * Human-readable accuracy, shown in the CP so the number's precision is
     * never oversold (e.g. "±1.6%" or "exact").
     */
    public function accuracy(): string;

    /**
     * Whether counters are stored in the rollup row's `uniques` column.
     *
     * False for drivers that own their storage (Redis keys, membership
     * table), which lets the sink skip the row's read-modify-write entirely.
     */
    public function storesOnRow(): bool;

    /**
     * Records visitor hashes against a scope.
     *
     * @param string[] $hashes distinct hashes from this batch
     * @param string|null $currentSketch the blob stored on the rollup row, if any
     * @return string|null the blob to store on the row, or null when this
     *                     driver keeps its counters elsewhere
     */
    public function record(UniqueScope $scope, array $hashes, ?string $currentSketch): ?string;

    /**
     * Distinct visitors across the given scopes, merged.
     *
     * @param UniqueScope[] $scopes
     * @param iterable<string|null> $sketches blobs from the matching rollup
     *                                        rows (ignored by drivers that
     *                                        store counters themselves)
     */
    public function estimate(array $scopes, iterable $sketches = []): int;

    /**
     * Folds a day's hourly counters into its daily one.
     *
     * Nightly compaction rewrites 24 hourly rollup rows into one row with
     * `hour = -1`, and readers build their scopes **from the stored row** - so
     * after compaction they ask for `…:-1:…`. A driver that keys its own
     * storage recorded under the real hours and has nothing under that key,
     * which is why this exists: without it, every unique figure older than
     * `hourlyWindowDays` silently reads zero.
     *
     * Called inside the compaction transaction, so it must be idempotent (a
     * re-run merges the same counters again, which for a union is a no-op) and
     * must not destroy the hourly counters - a rollback would leave the day
     * with neither. Dropping those is {@see discardCompacted()}'s job.
     *
     * @param UniqueScope[] $hourly
     */
    public function compact(UniqueScope $daily, array $hourly): void;

    /**
     * Drops hourly counters whose fold into the daily one has committed.
     *
     * Cleanup, called after the transaction: safe to skip, safe to repeat.
     * The same commit-then-tidy split the drain uses for its spool files.
     *
     * @param UniqueScope[] $hourly
     */
    public function discardCompacted(array $hourly): void;
}
