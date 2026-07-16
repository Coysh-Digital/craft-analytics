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
     * The Pro analytics rollups.
     *
     * Same shape rules as the Lite rollups: keyed on (siteId, date[, hour],
     * …dimensionIds) with a unique index for upserting, dimension values held
     * once in the dimensions table, and growth bounded by cardinality × time
     * rather than by traffic (C2).
     */
    public static function createProRollupTables(Migration $m): void
    {
        // Campaign attribution. Sessions, not pageviews: a campaign brings
        // somebody to the site once, not once per page they then read.
        $m->createTable(Table::CAMPAIGNS_ROLLUP, [
            'id' => $m->primaryKey(),
            'siteId' => $m->integer()->notNull(),
            'date' => $m->date()->notNull(),
            'sourceDimId' => $m->integer()->notNull(),
            'mediumDimId' => $m->integer()->notNull()->defaultValue(0),
            'campaignDimId' => $m->integer()->notNull()->defaultValue(0),
            'termDimId' => $m->integer()->notNull()->defaultValue(0),
            'contentDimId' => $m->integer()->notNull()->defaultValue(0),
            // Fractional credit, so the linear model can split a session
            // across the campaigns that touched it without inventing sessions.
            'sessions' => $m->decimal(12, 4)->notNull()->defaultValue(0),
            'bounces' => $m->decimal(12, 4)->notNull()->defaultValue(0),
            'conversions' => $m->decimal(12, 4)->notNull()->defaultValue(0),
            'value' => $m->decimal(14, 2)->notNull()->defaultValue(0),
        ]);
        $m->createIndex(
            null,
            Table::CAMPAIGNS_ROLLUP,
            ['siteId', 'date', 'sourceDimId', 'mediumDimId', 'campaignDimId', 'termDimId', 'contentDimId'],
            true,
        );

        // Country is an ISO code — a closed, tiny set, so it needs no
        // dimension row. Region does, and it is capped like any other.
        $m->createTable(Table::GEO_ROLLUP, [
            'id' => $m->primaryKey(),
            'siteId' => $m->integer()->notNull(),
            'date' => $m->date()->notNull(),
            'countryCode' => $m->char(2)->notNull(),
            'regionDimId' => $m->integer()->notNull()->defaultValue(0),
            'sessions' => $m->integer()->notNull()->defaultValue(0),
        ]);
        $m->createIndex(null, Table::GEO_ROLLUP, ['siteId', 'date', 'countryCode', 'regionDimId'], true);

        $m->createTable(Table::EVENTS_ROLLUP, [
            'id' => $m->primaryKey(),
            'siteId' => $m->integer()->notNull(),
            'date' => $m->date()->notNull(),
            'hour' => $m->smallInteger()->notNull(),
            'eventNameDimId' => $m->integer()->notNull(),
            'pathDimId' => $m->integer()->notNull()->defaultValue(0),
            'count' => $m->integer()->notNull()->defaultValue(0),
            'sumValue' => $m->decimal(14, 2)->notNull()->defaultValue(0),
        ]);
        $m->createIndex(null, Table::EVENTS_ROLLUP, ['siteId', 'date', 'hour', 'eventNameDimId', 'pathDimId'], true);

        // Four buckets, not a percentage: "how far down did people get" is a
        // question with four useful answers, and 101 useless ones.
        $m->createTable(Table::SCROLL_ROLLUP, [
            'id' => $m->primaryKey(),
            'siteId' => $m->integer()->notNull(),
            'date' => $m->date()->notNull(),
            'pathDimId' => $m->integer()->notNull(),
            'bucket' => $m->smallInteger()->notNull(),
            'count' => $m->integer()->notNull()->defaultValue(0),
        ]);
        $m->createIndex(null, Table::SCROLL_ROLLUP, ['siteId', 'date', 'pathDimId', 'bucket'], true);

        // What people looked for, and whether the site had it.
        $m->createTable(Table::SEARCH_ROLLUP, [
            'id' => $m->primaryKey(),
            'siteId' => $m->integer()->notNull(),
            'date' => $m->date()->notNull(),
            'termDimId' => $m->integer()->notNull(),
            'count' => $m->integer()->notNull()->defaultValue(0),
            'zeroResults' => $m->integer()->notNull()->defaultValue(0),
        ]);
        $m->createIndex(null, Table::SEARCH_ROLLUP, ['siteId', 'date', 'termDimId'], true);

        $m->createTable(Table::OUTBOUND_ROLLUP, [
            'id' => $m->primaryKey(),
            'siteId' => $m->integer()->notNull(),
            'date' => $m->date()->notNull(),
            'targetHostDimId' => $m->integer()->notNull(),
            'targetDimId' => $m->integer()->notNull()->defaultValue(0),
            'pathDimId' => $m->integer()->notNull()->defaultValue(0),
            'count' => $m->integer()->notNull()->defaultValue(0),
        ]);
        $m->createIndex(
            null,
            Table::OUTBOUND_ROLLUP,
            ['siteId', 'date', 'targetHostDimId', 'targetDimId', 'pathDimId'],
            true,
        );
    }

    /**
     * Goals and funnels (Pro).
     *
     * The definition tables are the readable, joinable mirror of project
     * config — project config remains the source of truth, so goals deploy
     * with the site rather than being retyped in production. These rows exist
     * because a rollup needs a foreign key to point at, and because a report
     * shouldn't have to parse YAML to learn a goal's name.
     */
    public static function createGoalTables(Migration $m): void
    {
        $m->createTable(Table::GOALS, [
            'id' => $m->primaryKey(),
            'name' => $m->string()->notNull(),
            'handle' => $m->string(64)->notNull(),
            'type' => $m->string(16)->notNull(),
            'target' => $m->string(500)->notNull(),
            'value' => $m->decimal(14, 2)->notNull()->defaultValue(0),
            'enabled' => $m->boolean()->notNull()->defaultValue(true),
            // Null = every site.
            'siteId' => $m->integer(),
            'sortOrder' => $m->smallInteger()->notNull()->defaultValue(0),
            'uid' => $m->uid(),
            'dateCreated' => $m->dateTime()->notNull(),
            'dateUpdated' => $m->dateTime()->notNull(),
        ]);
        $m->createIndex(null, Table::GOALS, ['handle'], true);
        $m->createIndex(null, Table::GOALS, ['uid'], true);

        // Conversions, keyed by day — cardinality × time, like everything
        // else. `value` is the monetary total, `sessions` the denominator a
        // conversion *rate* needs.
        $m->createTable(Table::GOALS_ROLLUP, [
            'id' => $m->primaryKey(),
            'siteId' => $m->integer()->notNull(),
            'date' => $m->date()->notNull(),
            'goalId' => $m->integer()->notNull(),
            'conversions' => $m->integer()->notNull()->defaultValue(0),
            'value' => $m->decimal(14, 2)->notNull()->defaultValue(0),
        ]);
        $m->createIndex(null, Table::GOALS_ROLLUP, ['siteId', 'date', 'goalId'], true);
        $m->addForeignKey(null, Table::GOALS_ROLLUP, ['goalId'], Table::GOALS, ['id'], 'CASCADE');

        $m->createTable(Table::FUNNELS, [
            'id' => $m->primaryKey(),
            'name' => $m->string()->notNull(),
            'handle' => $m->string(64)->notNull(),
            'siteId' => $m->integer(),
            'enabled' => $m->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $m->smallInteger()->notNull()->defaultValue(0),
            'uid' => $m->uid(),
            'dateCreated' => $m->dateTime()->notNull(),
            'dateUpdated' => $m->dateTime()->notNull(),
        ]);
        $m->createIndex(null, Table::FUNNELS, ['handle'], true);
        $m->createIndex(null, Table::FUNNELS, ['uid'], true);

        // A step is a goal in a position. Reusing goals rather than inventing
        // a parallel matcher means a funnel step and a goal can never
        // disagree about what a conversion is.
        $m->createTable(Table::FUNNEL_STEPS, [
            'id' => $m->primaryKey(),
            'funnelId' => $m->integer()->notNull(),
            'goalId' => $m->integer()->notNull(),
            'position' => $m->smallInteger()->notNull(),
            'uid' => $m->uid(),
        ]);
        $m->createIndex(null, Table::FUNNEL_STEPS, ['funnelId', 'position'], true);
        $m->addForeignKey(null, Table::FUNNEL_STEPS, ['funnelId'], Table::FUNNELS, ['id'], 'CASCADE');
        $m->addForeignKey(null, Table::FUNNEL_STEPS, ['goalId'], Table::GOALS, ['id'], 'CASCADE');

        // One row per funnel-step-day: how many sessions reached this far.
        // Drop-off is the difference between neighbouring positions, computed
        // at read time — storing it would be storing a subtraction.
        $m->createTable(Table::FUNNEL_STEP_ROLLUP, [
            'id' => $m->primaryKey(),
            'siteId' => $m->integer()->notNull(),
            'date' => $m->date()->notNull(),
            'funnelId' => $m->integer()->notNull(),
            'position' => $m->smallInteger()->notNull(),
            'sessions' => $m->integer()->notNull()->defaultValue(0),
        ]);
        $m->createIndex(null, Table::FUNNEL_STEP_ROLLUP, ['siteId', 'date', 'funnelId', 'position'], true);
        $m->addForeignKey(null, Table::FUNNEL_STEP_ROLLUP, ['funnelId'], Table::FUNNELS, ['id'], 'CASCADE');
    }

    /**
     * The consented (Tier-2) tables — the only place per-visitor rows may
     * ever exist, and only for visitors who affirmatively agreed.
     */
    public static function createConsentTables(Migration $m): void
    {
        // Evidence that a lawful basis exists, and what it covered. Keyed on
        // a pseudonymous id, never on anything that identifies the person:
        // this proves *that* someone consented, not *who* they are.
        $m->createTable(Table::CONSENT_LOG, [
            'id' => $m->primaryKey(),
            'siteId' => $m->integer()->notNull(),
            // The consented visitor id, when one exists.
            'visitorId' => $m->char(32),
            // The Tier-1 rotating hash, for decisions made before/without a
            // visitor id (a denial, typically). Unlinkable once the salt
            // rotates, which is the point.
            'visitorHash' => $m->char(16),
            'state' => $m->string(16)->notNull(),
            'method' => $m->string(16)->notNull(),
            'scope' => $m->string(64)->notNull(),
            'policyVersion' => $m->string(32)->notNull(),
            'recordedAt' => $m->dateTime()->notNull(),
        ]);
        $m->createIndex(null, Table::CONSENT_LOG, ['visitorId']);
        $m->createIndex(null, Table::CONSENT_LOG, ['siteId', 'recordedAt']);

        // The consented raw layer (§6.4): one row per pageview, for consented
        // visitors only, and only when enableJourneys is on. This is the sole
        // exception to "no raw per-hit rows" (C6), and the only table in the
        // plugin whose growth tracks traffic — which is why it is opt-in, has
        // its own retention, and must be individually erasable.
        $m->createTable(Table::JOURNEYS, [
            'id' => $m->primaryKey(),
            'visitorId' => $m->char(32)->notNull(),
            'siteId' => $m->integer()->notNull(),
            'sessionId' => $m->char(32)->notNull(),
            'sequence' => $m->integer()->notNull()->defaultValue(0),
            'pathDimId' => $m->integer()->notNull(),
            'eventDimId' => $m->integer(),
            // Only when associateUserId is separately enabled.
            'userId' => $m->integer(),
            'occurredAt' => $m->dateTime()->notNull(),
        ]);
        // Serves the DSAR export and erase paths, which are the whole reason
        // this table is allowed to exist.
        $m->createIndex(null, Table::JOURNEYS, ['visitorId', 'occurredAt']);
        $m->createIndex(null, Table::JOURNEYS, ['userId']);
        $m->createIndex(null, Table::JOURNEYS, ['siteId', 'occurredAt']);
    }

    /**
     * @return string[] every plugin table, newest-dependency first so they
     *                  can be dropped in order
     */
    public static function allTables(): array
    {
        return [
            Table::FUNNEL_STEP_ROLLUP,
            Table::FUNNEL_STEPS,
            Table::FUNNELS,
            Table::GOALS_ROLLUP,
            Table::GOALS,
            Table::OUTBOUND_ROLLUP,
            Table::SEARCH_ROLLUP,
            Table::SCROLL_ROLLUP,
            Table::EVENTS_ROLLUP,
            Table::GEO_ROLLUP,
            Table::CAMPAIGNS_ROLLUP,
            Table::JOURNEYS,
            Table::CONSENT_LOG,
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
