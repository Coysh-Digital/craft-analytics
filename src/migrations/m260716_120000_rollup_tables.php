<?php

namespace coyshdigital\craftanalytics\migrations;

use coyshdigital\craftanalytics\db\SchemaBuilder;
use craft\db\Migration;

/**
 * Adds the rollup tables — the only persistent analytics storage in Lite.
 *
 * Shape rules, applied to every rollup (see SchemaBuilder for the columns):
 *
 * - Keyed on (siteId, date, hour, ...dimensionIds) with a unique index, so
 *   every write upserts an existing row rather than adding one.
 * - Dimension *values* live in the dimensions table; rollups carry int FKs
 *   only. This is what keeps both row count and index size bounded (C2).
 * - `hour` is 0–23 while a day is at hourly grain, and -1 once GC has
 *   compacted it to a single daily row.
 * - `uniques` holds a serialised HyperLogLog sketch, which is mergeable — so
 *   a date range is the union of its rows, not the sum. Null when a
 *   non-sketch unique driver is in use.
 */
class m260716_120000_rollup_tables extends Migration
{
    public function safeUp(): bool
    {
        SchemaBuilder::createRollupTables($this);

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260716_120000_rollup_tables cannot be reverted without discarding every rollup.\n";

        return false;
    }
}
