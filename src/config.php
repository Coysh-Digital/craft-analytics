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

        // HyperLogLog precision for the 'hll' driver (11-14).
        // 12 = 4KB dense / ±1.6%; 14 = 16KB / ±0.8%. Sparse sketches cost far less.
        'hllPrecision' => 12,

        // Site paths never tracked (glob patterns), and query params stripped
        // from tracked URIs
        'excludePaths' => [],
        'excludeQueryParams' => [],

        // Session inactivity window, seconds
        'sessionWindow' => 1800,

        // Inject the tracker script automatically before </body> on site pages
        'injectScript' => true,

        // First-party site path the beacon posts to
        'beaconPath' => '_ca/collect',

        // How long a hybrid dedupe nonce stays claimable, seconds. Must outlast
        // how long a visitor might sit on a page before leaving.
        'nonceTtl' => 1800,

        // Maximum beacons accepted from one visitor per minute
        'beaconRateLimit' => 120,

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
