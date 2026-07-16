<?php

namespace coyshdigital\craftanalytics\models;

use craft\base\Model;

/**
 * Plugin settings.
 *
 * Every setting can be overridden from `config/craft-analytics.php` (per
 * environment) or via env vars in that file — the CP is never the only way to
 * set a value.
 */
class Settings extends Model
{
    public const TRACKING_MODE_SERVER = 'server';
    public const TRACKING_MODE_CLIENT = 'client';
    public const TRACKING_MODE_HYBRID = 'hybrid';

    public const WRITE_DRIVER_SPOOL = 'spool';
    public const WRITE_DRIVER_QUEUE = 'queue';
    public const WRITE_DRIVER_DIRECT = 'direct';

    public const UNIQUES_DRIVER_AUTO = 'auto';
    public const UNIQUES_DRIVER_REDIS = 'redis';
    public const UNIQUES_DRIVER_HLL = 'hll';
    public const UNIQUES_DRIVER_EXACT = 'exact';

    /**
     * How pageviews are captured: server-side only, client beacon only, or
     * both with nonce-based dedupe (default; survives full-page caching).
     */
    public string $trackingMode = self::TRACKING_MODE_HYBRID;

    /**
     * How captured hits reach the database. Never `direct` on real traffic.
     */
    public string $writeDriver = self::WRITE_DRIVER_SPOOL;

    /**
     * Unique-visitor counting driver. `auto` picks Redis when available,
     * otherwise the portable HyperLogLog sketches.
     */
    public string $uniqueCounterDriver = self::UNIQUES_DRIVER_AUTO;

    /**
     * Glob patterns of site paths that are never tracked.
     *
     * @var string[]
     */
    public array $excludePaths = [];

    /**
     * Query parameters stripped from tracked URIs.
     *
     * @var string[]
     */
    public array $excludeQueryParams = [];

    /** Session inactivity window, in seconds. */
    public int $sessionWindow = 1800;

    /**
     * Seconds between visitor-hash salt rotations. 24h is the privacy posture
     * the banner-free claim rests on; the privacy panel warns when extended.
     */
    public int $saltRotationInterval = 86400;

    /** Hour of day (site timezone) rotation aims for, to minimise split sessions. */
    public int $saltRotationHour = 4;

    /** Days of hourly-grain rollups kept before compaction to daily rows. */
    public int $hourlyWindowDays = 7;

    /** Per-(site, day, type) dimension cardinality cap; tail folds into `__other__`. */
    public int $dimensionCap = 1000;

    /** Rollup retention, in months. */
    public int $rollupRetentionMonths = 26;

    /** Back-pressure guard: spool size in bytes beyond which oldest data is dropped. */
    public int $spoolMaxBytes = 52428800;

    /** Honour the Global Privacy Control header (`Sec-GPC: 1`). */
    public bool $honourGpc = true;

    /** Honour the legacy `DNT: 1` header. */
    public bool $honourDnt = false;

    /**
     * @return array<int,array<int|string,mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['trackingMode'], 'in', 'range' => [
                self::TRACKING_MODE_SERVER,
                self::TRACKING_MODE_CLIENT,
                self::TRACKING_MODE_HYBRID,
            ]],
            [['writeDriver'], 'in', 'range' => [
                self::WRITE_DRIVER_SPOOL,
                self::WRITE_DRIVER_QUEUE,
                self::WRITE_DRIVER_DIRECT,
            ]],
            [['uniqueCounterDriver'], 'in', 'range' => [
                self::UNIQUES_DRIVER_AUTO,
                self::UNIQUES_DRIVER_REDIS,
                self::UNIQUES_DRIVER_HLL,
                self::UNIQUES_DRIVER_EXACT,
            ]],
            [['sessionWindow'], 'integer', 'min' => 60, 'max' => 14400],
            [['saltRotationInterval'], 'integer', 'min' => 3600],
            [['saltRotationHour'], 'integer', 'min' => 0, 'max' => 23],
            [['hourlyWindowDays'], 'integer', 'min' => 1, 'max' => 90],
            [['dimensionCap'], 'integer', 'min' => 10, 'max' => 100000],
            [['rollupRetentionMonths'], 'integer', 'min' => 1, 'max' => 26],
            [['spoolMaxBytes'], 'integer', 'min' => 1048576],
            [['honourGpc', 'honourDnt'], 'boolean'],
            [['excludePaths', 'excludeQueryParams'], 'each', 'rule' => ['string']],
        ];
    }
}
