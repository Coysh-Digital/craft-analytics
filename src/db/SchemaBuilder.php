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

        self::createPageSourcesTable($m);

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
     * Site-declared segments (Pro).
     *
     * Session-grained, like sources and geo: a segment describes the visitor
     * for a visit, so counting it per pageview would multiply one fact by
     * however many pages they read. Everything a segment report needs -
     * sessions, views, bounce rate, average duration - falls out of a closed
     * session for free, which is why there is no per-hit table here.
     *
     * Growth is cardinality x time like every other rollup: a segment with
     * three values costs three rows a day whether the site had ten visits or
     * ten million.
     */
    public static function createSegmentTable(Migration $m): void
    {
        $m->createTable(Table::SEGMENTS_ROLLUP, [
            'id' => $m->primaryKey(),
            'siteId' => $m->integer()->notNull(),
            'date' => $m->date()->notNull(),
            'segmentDimId' => $m->integer()->notNull(),
            'sessions' => $m->integer()->notNull()->defaultValue(0),
            'views' => $m->integer()->notNull()->defaultValue(0),
            'bounces' => $m->integer()->notNull()->defaultValue(0),
            'durationMs' => $m->bigInteger()->notNull()->defaultValue(0),
        ]);
        $m->createIndex(null, Table::SEGMENTS_ROLLUP, ['siteId', 'date', 'segmentDimId'], true);
    }

    /**
     * Where the traffic to a given page came from.
     *
     * The one thing the other rollups genuinely could not answer. Sources are
     * session-grained and carry no path at all, so "how did people get to
     * /pricing" had no row anywhere to read: the entry path was known when a
     * session closed and simply thrown away.
     *
     * Counted per *pageview*, not per session, and that is the point. Keying
     * on the session's entry path would only ever describe pages people
     * landed on, and the pages worth asking this about are usually the ones
     * reached part-way through a visit.
     *
     * Daily rather than hourly, unlike SOURCES_ROLLUP. Path x channel x
     * referrer is already the widest key here, and an hour column would
     * multiply it by 24 to answer a question - "was it Google at 3am?" -
     * nobody asks. It also means the compactor has nothing to do here.
     *
     * Growth is bounded by the dimension cap: Path and ReferrerHost are both
     * capped types, so the widest this can get is cap x cap x channels per
     * day, with everything past the cap folded into __other__.
     */
    public static function createPageSourcesTable(Migration $m): void
    {
        $m->createTable(Table::PAGE_SOURCES_ROLLUP, [
            'id' => $m->primaryKey(),
            'siteId' => $m->integer()->notNull(),
            'date' => $m->date()->notNull(),
            'pathDimId' => $m->integer()->notNull(),
            'channel' => $m->smallInteger()->notNull(),
            'refHostDimId' => $m->integer()->notNull()->defaultValue(0),
            'views' => $m->integer()->notNull()->defaultValue(0),
        ]);
        $m->createIndex(
            null,
            Table::PAGE_SOURCES_ROLLUP,
            ['siteId', 'date', 'pathDimId', 'channel', 'refHostDimId'],
            true,
        );
    }

    /**
     * Crawler activity.
     *
     * One row per crawler per day, and only when `trackCrawlers` is on. This
     * is not analytics about people - it is the answer to "why is my traffic
     * lower than my server logs say", which is otherwise a day of confusion.
     * Same cardinality x time shape as every other rollup: a crawler making a
     * million requests still adds one row a day.
     */
    public static function createCrawlerTable(Migration $m): void
    {
        $m->createTable(Table::CRAWLERS_ROLLUP, [
            'id' => $m->primaryKey(),
            'siteId' => $m->integer()->notNull(),
            'date' => $m->date()->notNull(),
            'crawlerDimId' => $m->integer()->notNull(),
            'requests' => $m->integer()->notNull()->defaultValue(0),
        ]);
        $m->createIndex(null, Table::CRAWLERS_ROLLUP, ['siteId', 'date', 'crawlerDimId'], true);
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
            // The consented visitor id, when one exists. Wider than the 32
            // hex characters we issue, because a site can supply its own
            // (ConsentService::EVENT_DEFINE_VISITOR_ID) and a customer number
            // is not a hash.
            'visitorId' => $m->string(64),
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
            'visitorId' => $m->string(64)->notNull(),
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
     * Every column in the plugin that points at the dimensions table.
     *
     * The single source of truth for "is this dimension still in use", which
     * the GC needs before it deletes one. It lives here, beside the schema
     * that creates these columns, because the two must change together: a new
     * rollup with a dimension column and no entry here means the GC deletes
     * dimensions that rollup is still using, and the report silently empties
     * the first night the GC runs.
     *
     * That is not hypothetical - it is exactly what happened to every Pro
     * report, from the moment they were added until it was noticed on a
     * seeded demo site. DimensionReferencesTest reads the real schema and
     * fails if anything is missing from this list, so forgetting is no longer
     * possible.
     *
     * @return array<int,array{0: string, 1: string}> table, column
     */
    public static function dimensionReferences(): array
    {
        return [
            [Table::PAGES_ROLLUP, 'pathDimId'],
            [Table::PAGE_SOURCES_ROLLUP, 'pathDimId'],
            [Table::PAGE_SOURCES_ROLLUP, 'refHostDimId'],
            [Table::SOURCES_ROLLUP, 'refHostDimId'],
            [Table::DEVICES_ROLLUP, 'browserDimId'],
            [Table::DEVICES_ROLLUP, 'osDimId'],
            [Table::CAMPAIGNS_ROLLUP, 'sourceDimId'],
            [Table::CAMPAIGNS_ROLLUP, 'mediumDimId'],
            [Table::CAMPAIGNS_ROLLUP, 'campaignDimId'],
            [Table::CAMPAIGNS_ROLLUP, 'termDimId'],
            [Table::CAMPAIGNS_ROLLUP, 'contentDimId'],
            [Table::GEO_ROLLUP, 'regionDimId'],
            [Table::EVENTS_ROLLUP, 'eventNameDimId'],
            [Table::EVENTS_ROLLUP, 'pathDimId'],
            [Table::SCROLL_ROLLUP, 'pathDimId'],
            [Table::SEARCH_ROLLUP, 'termDimId'],
            [Table::OUTBOUND_ROLLUP, 'targetHostDimId'],
            [Table::OUTBOUND_ROLLUP, 'targetDimId'],
            [Table::OUTBOUND_ROLLUP, 'pathDimId'],
            [Table::CRAWLERS_ROLLUP, 'crawlerDimId'],
            [Table::SEGMENTS_ROLLUP, 'segmentDimId'],
            [Table::JOURNEYS, 'pathDimId'],
            [Table::JOURNEYS, 'eventDimId'],
        ];
    }

    /**
     * Every aggregate rollup the retention window applies to.
     *
     * The counterpart to dimensionReferences(), and it exists for the same
     * reason: the GC decided what to expire from a hardcoded list of four
     * tables, so every rollup added after those four was kept forever while
     * the Privacy screen went on stating a retention period as a fact. Search
     * terms are the sharp end of that - they are free text a visitor typed,
     * and people search their own name and email address.
     *
     * Tables *not* here have their own retention rule, which is deliberate and
     * tighter than this one: see RetentionCoverageTest, which reads the real
     * schema and fails on any date-keyed table that is in neither list.
     *
     * @return string[]
     */
    public static function expiringRollups(): array
    {
        return [
            // Lite.
            Table::PAGES_ROLLUP,
            Table::PAGE_SOURCES_ROLLUP,
            Table::SESSIONS_ROLLUP,
            Table::SOURCES_ROLLUP,
            Table::DEVICES_ROLLUP,
            Table::CRAWLERS_ROLLUP,
            // Pro.
            Table::CAMPAIGNS_ROLLUP,
            Table::GEO_ROLLUP,
            Table::EVENTS_ROLLUP,
            Table::SCROLL_ROLLUP,
            Table::SEARCH_ROLLUP,
            Table::OUTBOUND_ROLLUP,
            Table::SEGMENTS_ROLLUP,
            Table::GOALS_ROLLUP,
            Table::FUNNEL_STEP_ROLLUP,
        ];
    }

    /**
     * Date-keyed tables the aggregate retention window deliberately skips,
     * with the rule that governs them instead.
     *
     * Named rather than merely absent, so that "this table has no retention"
     * and "this table has a different retention" cannot be confused - which is
     * exactly the confusion that let the Pro rollups accumulate unbounded.
     *
     * @return array<string,string> table => the rule that applies
     */
    public static function retainedElsewhere(): array
    {
        return [
            // Pruned two salt rotations back: once the salt that produced these
            // hashes is destroyed they cannot be matched to anything.
            Table::UNIQUE_MEMBERS => 'GcService::deleteExpiredUniqueMembers() (salt rotation)',
            // First-seen date only; these are deleted when nothing references
            // them, not when they age.
            Table::DIMENSIONS => 'GcService::deleteOrphanedDimensions() (reference counting)',
        ];
    }

    /**
     * @return string[] every plugin table, newest-dependency first so they
     *                  can be dropped in order
     */
    public static function allTables(): array
    {
        return [
            Table::SEGMENTS_ROLLUP,
            Table::CRAWLERS_ROLLUP,
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
            Table::PAGE_SOURCES_ROLLUP,
            Table::SESSIONS_ROLLUP,
            Table::PAGES_ROLLUP,
            Table::DRAIN_LOG,
            Table::SALTS,
            Table::DIMENSIONS,
        ];
    }
}
