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

        // Site paths never tracked (glob patterns), and extra query params
        // stripped from tracked URIs. Campaign tags (utm_*, gclid, fbclid, ...),
        // ad-network parameters (gad_source, _gl, ...) and Craft's preview token
        // are always stripped; list any site-specific ones here.
        'excludePaths' => [],
        'excludeQueryParams' => [],

        // Drop the whole query string from tracked page paths. Attribution is
        // read first, so UTM reports are unaffected; this just collapses every
        // ?ad=, ?cid= and similar variant of a page onto one clean path.
        'stripQueryString' => false,

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

        // --- Consent (Pro) ------------------------------------------------
        // All off by default: the plugin is cookieless unless you decide
        // otherwise, and each of these changes your compliance posture.

        // Allow consented (Tier-2) tracking at all
        'enableConsent' => false,

        // The first-party consented-visitor cookie
        'consentCookieName' => '_ca_vid',
        'consentCookieDuration' => 13 * 30 * 24 * 60 * 60, // 13 months; hard cap 24
        'consentPath' => '_ca/consent',

        // A cookie your own CMP writes, read server-side. Null disables it.
        'cmpCookieName' => null,
        'cmpCookieGrantedValues' => ['granted', 'true', '1', 'yes', 'allow'],

        // IAB TCF v2.2 (purposes 1 + 8)
        'enableTcf' => false,

        // Link consented visitors to their Craft account. Separate from
        // consent itself: being measured and being named are different things.
        'associateUserId' => false,

        // The consented raw journeys layer — the only per-visitor rows the
        // plugin ever stores. Enabling this makes you a controller of personal
        // data subject to access and erasure requests.
        'enableJourneys' => false,
        'journeyRetentionDays' => 90, // hard cap 26 months

        // Consent evidence retention. 0 = keep indefinitely (legal hold).
        'consentLogRetentionDays' => 0,

        // Bump when your privacy policy changes materially, so old consents
        // evidence the old policy.
        'policyVersion' => '1',
    ],

    'dev' => [
        // 'writeDriver' => 'direct',
    ],
];
