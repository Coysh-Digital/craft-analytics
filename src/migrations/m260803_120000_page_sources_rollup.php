<?php

namespace coyshdigital\craftanalytics\migrations;

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use craft\db\Migration;

/**
 * Where the traffic to a given page came from.
 *
 * No existing rollup could answer this. Sources are session-grained with no
 * path column, so the entry path was known at the moment a session closed and
 * then discarded; every other per-path table records what happened *on* a page
 * rather than how anyone got to it.
 *
 * This only fills forward. Nothing can be backfilled, because the raw hits it
 * would have to be derived from were aggregated away as they arrived - which
 * is the whole design. The report says as much rather than showing an empty
 * card that looks broken.
 */
class m260803_120000_page_sources_rollup extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Table::PAGE_SOURCES_ROLLUP)) {
            SchemaBuilder::createPageSourcesTable($this);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::PAGE_SOURCES_ROLLUP);

        return true;
    }
}
