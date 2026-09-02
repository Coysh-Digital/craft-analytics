<?php

namespace coyshdigital\craftanalytics\migrations;

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use craft\db\Migration;

/**
 * The GA4 history import's Google connection table.
 *
 * One row holds the OAuth tokens and the chosen property for backfilling
 * history from Google Analytics 4. The table shape lives in SchemaBuilder,
 * shared with Install, so a fresh install and an upgrade land on the same
 * schema.
 */
class m260902_120000_ga4_import extends Migration
{
    public function safeUp(): bool
    {
        // Guarded so re-running (or installing fresh, where Install already
        // made it) is a no-op rather than a duplicate-table error.
        if (!$this->db->tableExists(Table::GA4_AUTH)) {
            SchemaBuilder::createGa4Table($this);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::GA4_AUTH);

        return true;
    }
}
