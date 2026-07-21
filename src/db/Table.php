<?php

namespace coyshdigital\craftanalytics\db;

/**
 * Table name constants for all plugin tables.
 *
 * Every plugin table uses the `craftanalytics_` prefix (C8: all analytics
 * tables are plugin-owned and site-scoped where applicable).
 */
final class Table
{
    public const DIMENSIONS = '{{%craftanalytics_dimensions}}';
    public const SALTS = '{{%craftanalytics_salts}}';
    public const DRAIN_LOG = '{{%craftanalytics_drainlog}}';

    public const PAGES_ROLLUP = '{{%craftanalytics_pagesrollup}}';
    public const SESSIONS_ROLLUP = '{{%craftanalytics_sessionsrollup}}';
    public const SOURCES_ROLLUP = '{{%craftanalytics_sourcesrollup}}';
    public const DEVICES_ROLLUP = '{{%craftanalytics_devicesrollup}}';

    public const UNIQUE_MEMBERS = '{{%craftanalytics_uniquemembers}}';

    /** Pro, consented visitors only. */
    public const CONSENT_LOG = '{{%craftanalytics_consentlog}}';
    public const JOURNEYS = '{{%craftanalytics_journeys}}';

    /** Pro analytics. */
    public const CAMPAIGNS_ROLLUP = '{{%craftanalytics_campaignsrollup}}';
    public const GEO_ROLLUP = '{{%craftanalytics_georollup}}';
    public const EVENTS_ROLLUP = '{{%craftanalytics_eventsrollup}}';
    public const SCROLL_ROLLUP = '{{%craftanalytics_scrollrollup}}';
    public const SEARCH_ROLLUP = '{{%craftanalytics_searchrollup}}';
    public const OUTBOUND_ROLLUP = '{{%craftanalytics_outboundrollup}}';
    public const CRAWLERS_ROLLUP = '{{%craftanalytics_crawlersrollup}}';

    /** Pro, and only for the segments a site's own module declared. */
    public const SEGMENTS_ROLLUP = '{{%craftanalytics_segmentsrollup}}';

    /**
     * Pro goals and funnels. The definitions mirror project config; the
     * rollups are aggregates like any other.
     */
    public const GOALS = '{{%craftanalytics_goals}}';
    public const GOALS_ROLLUP = '{{%craftanalytics_goalsrollup}}';
    public const FUNNELS = '{{%craftanalytics_funnels}}';
    public const FUNNEL_STEPS = '{{%craftanalytics_funnelsteps}}';
    public const FUNNEL_STEP_ROLLUP = '{{%craftanalytics_funnelsteprollup}}';
}
