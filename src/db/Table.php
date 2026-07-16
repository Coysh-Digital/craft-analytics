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
}
