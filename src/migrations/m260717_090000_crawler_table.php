<?php

namespace coyshdigital\craftanalytics\migrations;

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use craft\db\Migration;

/**
 * Crawler activity, so excluded bot traffic is visible rather than merely
 * absent.
 */
class m260717_090000_crawler_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists(Table::CRAWLERS_ROLLUP)) {
            return true;
        }

        SchemaBuilder::createCrawlerTable($this);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::CRAWLERS_ROLLUP);

        return true;
    }
}
