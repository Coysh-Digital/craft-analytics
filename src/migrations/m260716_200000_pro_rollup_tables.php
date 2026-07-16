<?php

namespace coyshdigital\craftanalytics\migrations;

use coyshdigital\craftanalytics\db\SchemaBuilder;
use craft\db\Migration;

/**
 * Adds the Pro analytics rollups: campaigns, geo, events, scroll, search and
 * outbound.
 *
 * Creating them costs nothing on a Lite install — they stay empty, because
 * every writer behind them is edition-gated at the service layer.
 */
class m260716_200000_pro_rollup_tables extends Migration
{
    public function safeUp(): bool
    {
        SchemaBuilder::createProRollupTables($this);

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260716_200000_pro_rollup_tables cannot be reverted without discarding rollups.\n";

        return false;
    }
}
