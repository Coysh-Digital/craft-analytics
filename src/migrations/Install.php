<?php

namespace coyshdigital\craftanalytics\migrations;

use coyshdigital\craftanalytics\db\SchemaBuilder;
use craft\db\Migration;

/**
 * Creates the whole current schema for a fresh install.
 *
 * The table definitions live in SchemaBuilder, shared with the numbered
 * migrations, so a site installed today and a site upgraded today end up
 * identical.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        SchemaBuilder::createSpineTables($this);
        SchemaBuilder::createRollupTables($this);
        SchemaBuilder::createProRollupTables($this);
        SchemaBuilder::createGoalTables($this);
        SchemaBuilder::createConsentTables($this);

        return true;
    }

    public function safeDown(): bool
    {
        foreach (SchemaBuilder::allTables() as $table) {
            $this->dropTableIfExists($table);
        }

        return true;
    }
}
