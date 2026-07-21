<?php

namespace coyshdigital\craftanalytics\migrations;

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use craft\db\Migration;

/**
 * The extension API's storage: the segments rollup, and room for a visitor id
 * a site supplied rather than one we issued.
 *
 * The widening is the reason these travel together. Both id columns were
 * char(32) because the only thing that ever went in them was 16 random bytes
 * in hex. A site can now hand us its own identifier for a consented visitor,
 * and a customer number is not a hash — so the columns become varchar(64),
 * which the values we issue still fit inside unchanged.
 */
class m260721_120000_segments_and_external_ids extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Table::SEGMENTS_ROLLUP)) {
            SchemaBuilder::createSegmentTable($this);
        }

        // Consent tables only exist on sites that got as far as installing
        // them; a fresh install builds them at the new width already.
        if ($this->db->tableExists(Table::JOURNEYS)) {
            $this->alterColumn(Table::JOURNEYS, 'visitorId', $this->string(64)->notNull());
        }

        if ($this->db->tableExists(Table::CONSENT_LOG)) {
            $this->alterColumn(Table::CONSENT_LOG, 'visitorId', $this->string(64));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::SEGMENTS_ROLLUP);

        // The columns are deliberately left wide. Narrowing them again would
        // truncate any site-supplied id already stored, and a rollback is not
        // a reason to corrupt the one table that holds personal data.
        return true;
    }
}
