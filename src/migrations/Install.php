<?php

namespace coyshdigital\craftanalytics\migrations;

use coyshdigital\craftanalytics\db\Table;
use craft\db\Migration;

/**
 * Install migration — phase 1 spine tables.
 *
 * Hashes are stored hex-encoded in fixed CHAR columns rather than binary:
 * Yii maps `binary()` to BLOB on MySQL, which cannot carry a plain unique
 * index, and hex keeps every index definition identical on both databases.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        // (type, valueHash) → id lookup shared by every rollup table.
        // Rollups store int FKs only; values never repeat inline. (C2)
        $this->createTable(Table::DIMENSIONS, [
            'id' => $this->primaryKey(),
            'type' => $this->smallInteger()->notNull(),
            'valueHash' => $this->char(16)->notNull(), // xxh3-64, hex
            'value' => $this->string(500)->notNull(),
            'firstSeen' => $this->date()->notNull(),
        ]);
        $this->createIndex(null, Table::DIMENSIONS, ['type', 'valueHash'], true);

        // Single-row rotating salt store. The previous salt is destroyed on
        // rotation — never keep more than the current row. (C5, §5.1)
        $this->createTable(Table::SALTS, [
            'id' => $this->primaryKey(),
            'salt' => $this->char(64)->notNull(), // 32 random bytes, hex
            'rotatedAt' => $this->dateTime()->notNull(),
            'nextRotation' => $this->dateTime()->notNull(),
        ]);

        // Idempotency markers so a crashed/re-run drain never double-counts a
        // committed batch. Pruned by GC.
        $this->createTable(Table::DRAIN_LOG, [
            'id' => $this->primaryKey(),
            'batchId' => $this->string(64)->notNull(),
            'driver' => $this->string(16)->notNull(),
            'committedAt' => $this->dateTime()->notNull(),
        ]);
        $this->createIndex(null, Table::DRAIN_LOG, ['batchId'], true);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::DRAIN_LOG);
        $this->dropTableIfExists(Table::SALTS);
        $this->dropTableIfExists(Table::DIMENSIONS);

        return true;
    }
}
