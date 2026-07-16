<?php

namespace coyshdigital\craftanalytics\db;

use craft\db\Migration;

/**
 * The schema, defined once.
 *
 * A fresh install runs `Install`, while an existing one runs the numbered
 * migrations — so the same tables are created from two places. Defining them
 * here means the two can't drift apart, which is the classic way a plugin
 * ends up with a schema that depends on when you installed it.
 */
final class SchemaBuilder
{
    /**
     * Dimensions, salts and the drain log: the spine every other table leans
     * on.
     */
    public static function createSpineTables(Migration $m): void
    {
        // (type, valueHash) → id lookup shared by every rollup table.
        // Rollups store int FKs only; values never repeat inline. (C2)
        $m->createTable(Table::DIMENSIONS, [
            'id' => $m->primaryKey(),
            'type' => $m->smallInteger()->notNull(),
            'valueHash' => $m->char(16)->notNull(), // xxh3-64, hex
            'value' => $m->string(500)->notNull(),
            'firstSeen' => $m->date()->notNull(),
        ]);
        $m->createIndex(null, Table::DIMENSIONS, ['type', 'valueHash'], true);

        // Single-row rotating salt store. The previous salt is destroyed on
        // rotation — never keep more than the current row. (C5, §5.1)
        $m->createTable(Table::SALTS, [
            'id' => $m->primaryKey(),
            'salt' => $m->char(64)->notNull(), // 32 random bytes, hex
            'rotatedAt' => $m->dateTime()->notNull(),
            'nextRotation' => $m->dateTime()->notNull(),
        ]);

        // Idempotency markers so a crashed/re-run drain never double-counts a
        // committed batch. Pruned by GC.
        $m->createTable(Table::DRAIN_LOG, [
            'id' => $m->primaryKey(),
            'batchId' => $m->string(64)->notNull(),
            'driver' => $m->string(16)->notNull(),
            'committedAt' => $m->dateTime()->notNull(),
        ]);
        $m->createIndex(null, Table::DRAIN_LOG, ['batchId'], true);
    }

    /**
     * The rollups — see m260716_120000_rollup_tables for the shape rules.
     */
    public static function createRollupTables(Migration $m): void
    {
        $m->createTable(Table::PAGES_ROLLUP, [
            'id' => $m->primaryKey(),
            'siteId' => $m->integer()->notNull(),
            'date' => $m->date()->notNull(),
            'hour' => $m->smallInteger()->notNull(),
            'pathDimId' => $m->integer()->notNull(),
            'elementId' => $m->integer(),
            'views' => $m->integer()->notNull()->defaultValue(0),
            'uniques' => $m->binary(),
            'totalDwellMs' => $m->bigInteger()->notNull()->defaultValue(0),
            'entrances' => $m->integer()->notNull()->defaultValue(0),
            'exits' => $m->integer()->notNull()->defaultValue(0),
            'bounces' => $m->integer()->notNull()->defaultValue(0),
        ]);
        $m->createIndex(null, Table::PAGES_ROLLUP, ['siteId', 'date', 'hour', 'pathDimId'], true);
        $m->createIndex(null, Table::PAGES_ROLLUP, ['siteId', 'elementId', 'date']);

        $m->createTable(Table::SESSIONS_ROLLUP, [
            'id' => $m->primaryKey(),
            'siteId' => $m->integer()->notNull(),
            'date' => $m->date()->notNull(),
            'hour' => $m->smallInteger()->notNull(),
            'sessions' => $m->integer()->notNull()->defaultValue(0),
            'bounces' => $m->integer()->notNull()->defaultValue(0),
            'totalDurationMs' => $m->bigInteger()->notNull()->defaultValue(0),
            'totalPageviews' => $m->integer()->notNull()->defaultValue(0),
            'uniques' => $m->binary(),
        ]);
        $m->createIndex(null, Table::SESSIONS_ROLLUP, ['siteId', 'date', 'hour'], true);

        $m->createTable(Table::SOURCES_ROLLUP, [
            'id' => $m->primaryKey(),
            'siteId' => $m->integer()->notNull(),
            'date' => $m->date()->notNull(),
            'hour' => $m->smallInteger()->notNull(),
            'channel' => $m->smallInteger()->notNull(),
            'refHostDimId' => $m->integer()->notNull()->defaultValue(0),
            'sessions' => $m->integer()->notNull()->defaultValue(0),
            'bounces' => $m->integer()->notNull()->defaultValue(0),
        ]);
        $m->createIndex(null, Table::SOURCES_ROLLUP, ['siteId', 'date', 'hour', 'channel', 'refHostDimId'], true);

        $m->createTable(Table::DEVICES_ROLLUP, [
            'id' => $m->primaryKey(),
            'siteId' => $m->integer()->notNull(),
            'date' => $m->date()->notNull(),
            'browserDimId' => $m->integer()->notNull(),
            'browserMajor' => $m->smallInteger()->notNull()->defaultValue(0),
            'osDimId' => $m->integer()->notNull(),
            'deviceType' => $m->smallInteger()->notNull()->defaultValue(0),
            'sessions' => $m->integer()->notNull()->defaultValue(0),
        ]);
        $m->createIndex(
            null,
            Table::DEVICES_ROLLUP,
            ['siteId', 'date', 'browserDimId', 'browserMajor', 'osDimId', 'deviceType'],
            true,
        );

        $m->createTable(Table::UNIQUE_MEMBERS, [
            'id' => $m->primaryKey(),
            'scopeKey' => $m->string(120)->notNull(),
            'siteId' => $m->integer()->notNull(),
            'date' => $m->date()->notNull(),
            'visitorHash' => $m->char(16)->notNull(),
        ]);
        $m->createIndex(null, Table::UNIQUE_MEMBERS, ['scopeKey', 'visitorHash'], true);
        $m->createIndex(null, Table::UNIQUE_MEMBERS, ['siteId', 'date']);
    }

    /**
     * @return string[] every plugin table, newest-dependency first so they
     *                  can be dropped in order
     */
    public static function allTables(): array
    {
        return [
            Table::UNIQUE_MEMBERS,
            Table::DEVICES_ROLLUP,
            Table::SOURCES_ROLLUP,
            Table::SESSIONS_ROLLUP,
            Table::PAGES_ROLLUP,
            Table::DRAIN_LOG,
            Table::SALTS,
            Table::DIMENSIONS,
        ];
    }
}
