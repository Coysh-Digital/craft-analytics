<?php

namespace coyshdigital\craftanalytics\migrations;

use coyshdigital\craftanalytics\db\SchemaBuilder;
use craft\db\Migration;

/**
 * Adds the consented (Tier-2) tables: the consent evidence log, and the
 * opt-in raw journeys layer.
 *
 * Creating the tables changes nothing on its own — both stay empty unless
 * `enableConsent` (and, for journeys, `enableJourneys`) are deliberately
 * turned on, and a visitor affirmatively consents.
 */
class m260716_180000_consent_tables extends Migration
{
    public function safeUp(): bool
    {
        SchemaBuilder::createConsentTables($this);

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260716_180000_consent_tables cannot be reverted without discarding consent evidence.\n";

        return false;
    }
}
