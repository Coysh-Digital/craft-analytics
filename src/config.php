<?php

/**
 * Craft Analytics config
 *
 * Copy this file to your project's `config/craft-analytics.php` to override
 * plugin settings per environment. Every key maps to a property on
 * `coyshdigital\craftanalytics\models\Settings` and may be driven by env vars.
 */

use craft\helpers\App;

return [
    '*' => [
        // 'server', 'client', or 'hybrid' (default; survives full-page caching)
        'trackingMode' => 'hybrid',

        // 'spool' (default), 'queue' (dedicated worker required), or 'direct'
        'writeDriver' => App::env('CRAFT_ANALYTICS_WRITE_DRIVER') ?: 'spool',

        // 'auto' (default: Redis if available, else HLL), 'redis', 'hll', 'exact'
        'uniqueCounterDriver' => 'auto',

        // Site paths never tracked (glob patterns), and query params stripped
        // from tracked URIs
        'excludePaths' => [],
        'excludeQueryParams' => [],

        // Session inactivity window, seconds
        'sessionWindow' => 1800,

        // Visitor-hash salt rotation. 24h + destruction of the old salt is the
        // basis of the banner-free privacy posture — extend with care.
        'saltRotationInterval' => 86400,
        'saltRotationHour' => 4,

        // Days of hourly-grain rollups before compaction to daily rows
        'hourlyWindowDays' => 7,

        // Per-(site, day, type) dimension cardinality cap
        'dimensionCap' => 1000,

        // Rollup retention, months (hard cap 26)
        'rollupRetentionMonths' => 26,

        // Spool back-pressure limit, bytes
        'spoolMaxBytes' => 50 * 1024 * 1024,

        // Privacy signals
        'honourGpc' => true,
        'honourDnt' => false,
    ],

    'dev' => [
        // 'writeDriver' => 'direct',
    ],
];
