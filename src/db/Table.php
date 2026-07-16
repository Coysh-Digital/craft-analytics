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
}
