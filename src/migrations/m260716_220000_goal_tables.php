<?php

namespace coyshdigital\craftanalytics\migrations;

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use craft\db\Migration;

/**
 * Goals and funnels (Pro).
 *
 * Definitions are created for every edition. The tables staying empty on Lite
 * costs nothing, and it means an upgrade to Pro is a licence change rather
 * than a migration.
 */
class m260716_220000_goal_tables extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists(Table::GOALS)) {
            return true;
        }

        SchemaBuilder::createGoalTables($this);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::FUNNEL_STEP_ROLLUP);
        $this->dropTableIfExists(Table::FUNNEL_STEPS);
        $this->dropTableIfExists(Table::FUNNELS);
        $this->dropTableIfExists(Table::GOALS_ROLLUP);
        $this->dropTableIfExists(Table::GOALS);

        return true;
    }
}
