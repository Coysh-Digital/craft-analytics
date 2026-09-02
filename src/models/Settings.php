<?php

namespace coyshdigital\craftanalytics\models;

use coyshdigital\craftanalytics\uniques\Hll;
use Craft;
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

    /**
     * The longest a consented visitor cookie may live. Not configurable past
     * this: a "consent" someone gave two years ago and has not seen since is
     * not a consent anybody should rely on.
     */
    public const CONSENT_COOKIE_MAX_DURATION = 63072000; // 24 months

    /** Journeys are raw per-visitor rows; they never outlive the rollups. */
    public const JOURNEY_MAX_RETENTION_DAYS = 790; // 26 months

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
     * HyperLogLog precision for the portable driver. Each step up doubles
     * sketch size and cuts error by ~30%: p=12 is 4 KB/±1.6%, p=14 is
     * 16 KB/±0.8%.
     */
    public int $hllPrecision = 12;

    /**
     * Glob patterns of site paths that are never tracked.
     *
     * @var string[]
     */
    public array $excludePaths = [];

    /**
     * Query parameters stripped from tracked URIs, on top of the campaign and
     * ad-network parameters that are always removed.
     *
     * @var string[]
     */
    public array $excludeQueryParams = [];

    /**
     * Drop the entire query string from tracked page paths.
     *
     * Campaign attribution is read from the query before this applies, so
     * turning it on keeps your UTM and click reports while collapsing every
     * `?ad=...`, `?cid=...` and similar variant of a page onto one clean path.
     * Site search still works, because it is read separately.
     */
    public bool $stripQueryString = false;

    /** Session inactivity window, in seconds. */
    public int $sessionWindow = 1800;

    /**
     * Whether the tracker script is injected automatically before </body> on
     * site pages. Turn off to place it yourself.
     */
    public bool $injectScript = true;

    /** Site path the beacon posts to. First-party by design (C7). */
    public string $beaconPath = '_ca/collect';

    /** Site path consent decisions are posted to. */
    public string $consentPath = '_ca/consent';

    /**
     * How long a hybrid-mode dedupe nonce stays claimable, in seconds.
     *
     * Must outlast how long a visitor might stay on a page before leaving —
     * the beacon only fires on the way out. If it expires first, that page's
     * view can be counted twice (once server-side, once from the beacon).
     * Costs one small cache entry per server-counted pageview for this long.
     */
    public int $nonceTtl = 1800;

    /** Maximum beacons accepted from one visitor per minute. */
    public int $beaconRateLimit = 120;

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

    /**
     * Runs the drain from an ordinary web request when cron isn't an option,
     * throttled to roughly once a minute and skipped once the spool has grown
     * past a modest size. Only applies to the spool driver; queue and direct
     * need no draining.
     */
    public bool $autoDrain = true;

    /** Honour the Global Privacy Control header (`Sec-GPC: 1`). */
    public bool $honourGpc = true;

    /** Honour the legacy `DNT: 1` header. */
    public bool $honourDnt = false;

    // ---------------------------------------------------------------- Pro
    // Consent (§5.2). Every default here is the one that needs no banner:
    // turning any of it on is a deliberate act with compliance consequences,
    // so none of it is on by default (C4).

    /**
     * Whether consented (Tier-2) tracking is available at all.
     *
     * Off means the plugin is cookieless, full stop — no consent is read, no
     * cookie is ever set, and the banner-free posture holds.
     */
    public bool $enableConsent = false;

    /** Name of the first-party consented-visitor cookie. */
    public string $consentCookieName = '_ca_vid';

    /**
     * How long the consented visitor cookie lives, in seconds. Default 13
     * months; hard-capped at 24 (see CONSENT_COOKIE_MAX_DURATION).
     */
    public int $consentCookieDuration = 34186000;

    /**
     * A cookie the site's own CMP writes, read server-side to infer consent
     * without any JavaScript from us. Null disables this source.
     */
    public ?string $cmpCookieName = null;

    /**
     * Values of `cmpCookieName` that count as an affirmative grant. Anything
     * else — including an absent cookie — is not consent.
     *
     * @var string[]
     */
    public array $cmpCookieGrantedValues = ['granted', 'true', '1', 'yes', 'allow'];

    /** Read IAB TCF v2.2 (`__tcfapi`) consent for purposes 1 and 8. */
    public bool $enableTcf = false;

    /**
     * Associate a consented visitor with their Craft user ID.
     *
     * Deliberately separate from consent itself: agreeing to be measured is
     * not the same as agreeing to have that measurement tied to your account.
     */
    public bool $associateUserId = false;

    /**
     * The consented raw journeys layer — the only place per-visitor rows are
     * ever stored. Off by default; turning it on changes the site's
     * compliance posture (§6.4).
     */
    public bool $enableJourneys = false;

    /** Journey retention, in days. Hard-capped at 26 months. */
    public int $journeyRetentionDays = 90;

    /**
     * Consent-evidence retention, in days. 0 keeps it indefinitely, which is
     * the usual legal-hold position: the record of a lawful basis should
     * outlive the processing it authorised.
     */
    public int $consentLogRetentionDays = 0;

    /**
     * The privacy policy version recorded against each consent. Bump it when
     * the policy changes materially — old consents then evidence the old
     * policy, which is the point.
     */
    public string $policyVersion = '1';

    // ------------------------------------------------- Pro analytics (§7)

    /** Record campaign (`utm_*`) attribution. */
    public bool $enableCampaigns = true;

    /**
     * How a session's credit is divided between the campaigns that touched
     * it. Only matters for multi-touch sessions; with a single touch every
     * model agrees.
     */
    public string $attributionModel = 'last-click';

    /**
     * Country and region from a local database. Requires the database to be
     * installed first (`craft-analytics/geo/install`); no lookup service is
     * ever called (C7).
     */
    public bool $enableGeo = false;

    /** Custom events, outbound links, downloads and scroll depth. */
    public bool $enableEvents = true;

    /** Track clicks on links leaving the site. */
    public bool $trackOutbound = true;

    /** Track clicks on links to downloadable files. */
    public bool $trackDownloads = true;

    /**
     * File extensions counted as a download.
     *
     * @var string[]
     */
    public array $downloadExtensions = [
        'pdf', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'csv', 'dmg', 'exe', 'pkg', 'rar', 'gz', 'mp3', 'mp4', 'epub',
    ];

    /** Record how far down each page visitors read. */
    public bool $trackScroll = true;

    /**
     * Site-search terms. The path a search lands on, and the query parameter
     * holding the term — e.g. '/search' and 'q'.
     */
    public bool $trackSiteSearch = false;
    public string $siteSearchPath = '/search';
    public string $siteSearchParam = 'q';

    /**
     * Keep crawlers out of the reports.
     *
     * On by default, and it is what you want: a site with 400 real visitors
     * and 3,000 bot requests reports 3,400 without this, and every number on
     * every screen is then wrong in a way that looks plausible.
     *
     * Turning it off does not turn the detection off - it only stops the
     * detection from excluding anything, so bot traffic is counted as if it
     * were human. That is occasionally useful when debugging why traffic
     * looks low, and is a bad idea the rest of the time.
     */
    public bool $blockCrawlers = true;

    /**
     * Count the crawler requests that were kept out, so "where did my traffic
     * go" has an answer.
     *
     * Costs one small rollup row per crawler per day - the same
     * cardinality x time shape as everything else, and never a row per hit.
     */
    public bool $trackCrawlers = true;

    /** Email a periodic summary from the site's own mailer (Pro). */
    public bool $enableScheduledReports = false;

    /**
     * Who the summary goes to. Each entry may be an env var, so production
     * and staging can differ without a project config change.
     *
     * @var string[]
     */
    public array $reportRecipients = [];

    /**
     * The period each summary covers: any DateRange preset. The *schedule* is
     * the operator's cron, not a setting - a plugin with its own scheduler is
     * a plugin with a second, worse cron.
     */
    public string $reportPeriod = '7d';

    // ------------------------------------------------ GA4 history import

    /**
     * The OAuth client ID and secret for importing history from Google
     * Analytics 4. Both name this site to Google and nothing else; they read
     * what Google already holds and send nothing about your visitors.
     *
     * Either may be a `$ENV_VAR` reference rather than the literal value,
     * resolved at use with App::parseEnv() - the better home for the secret,
     * since a literal pasted in the CP persists to project config. Validation
     * leaves `$`-prefixed values alone, the same way reportRecipients does: the
     * value belongs to the environment, which this may not be running in.
     */
    public ?string $ga4ClientId = null;
    public ?string $ga4ClientSecret = null;

    /**
     * Normalises settings on the way in.
     *
     * The CP's editable table posts recipients as `[['email' => '…'], …]`
     * while a config file writes a plain list of addresses. Both are the same
     * setting, so both are flattened to the same shape here rather than every
     * reader having to know which one it is looking at.
     *
     * @param array<string,mixed> $values
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        if (isset($values['reportRecipients']) && is_array($values['reportRecipients'])) {
            $values['reportRecipients'] = self::flattenRecipients($values['reportRecipients']);
        }

        parent::setAttributes($values, $safeOnly);
    }

    /**
     * @param array<int|string,mixed> $rows
     * @return string[]
     */
    private static function flattenRecipients(array $rows): array
    {
        $recipients = [];

        foreach ($rows as $row) {
            $value = is_array($row) ? ($row['email'] ?? '') : $row;
            $value = trim((string)$value);

            if ($value !== '') {
                $recipients[] = $value;
            }
        }

        return $recipients;
    }

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
            [['hllPrecision'], 'integer', 'min' => Hll::MIN_PRECISION, 'max' => Hll::MAX_PRECISION],
            [['sessionWindow'], 'integer', 'min' => 60, 'max' => 14400],
            [['saltRotationInterval'], 'integer', 'min' => 3600],
            [['saltRotationHour'], 'integer', 'min' => 0, 'max' => 23],
            [['hourlyWindowDays'], 'integer', 'min' => 1, 'max' => 90],
            [['dimensionCap'], 'integer', 'min' => 10, 'max' => 100000],
            [['rollupRetentionMonths'], 'integer', 'min' => 1, 'max' => 26],
            [['spoolMaxBytes'], 'integer', 'min' => 1048576],
            [['nonceTtl'], 'integer', 'min' => 60, 'max' => 86400],
            [['beaconRateLimit'], 'integer', 'min' => 1],
            [['beaconPath', 'consentPath'], 'string'],
            [['beaconPath', 'consentPath'], 'match', 'pattern' => '/^[A-Za-z0-9\-_\/\.]+$/'],
            [['honourGpc', 'honourDnt', 'injectScript', 'stripQueryString', 'autoDrain'], 'boolean'],
            [['excludePaths', 'excludeQueryParams'], 'each', 'rule' => ['string']],

            // Consent (Pro)
            [
                ['enableConsent', 'enableTcf', 'associateUserId', 'enableJourneys'],
                'boolean',
            ],
            [['consentCookieName'], 'string', 'max' => 64],
            [['consentCookieName'], 'match', 'pattern' => '/^[A-Za-z0-9_\-]+$/'],
            [['consentCookieName'], 'required', 'when' => fn(self $model) => $model->enableConsent],
            [
                ['consentCookieDuration'],
                'integer',
                'min' => 3600,
                'max' => self::CONSENT_COOKIE_MAX_DURATION,
                'tooBig' => Craft::t(
                    'craft-analytics',
                    'A consented visitor cookie may not outlive 24 months.',
                ),
            ],
            [['cmpCookieName'], 'string', 'max' => 64],
            [['cmpCookieGrantedValues'], 'each', 'rule' => ['string']],
            [
                ['journeyRetentionDays'],
                'integer',
                'min' => 1,
                'max' => self::JOURNEY_MAX_RETENTION_DAYS,
                'tooBig' => Craft::t(
                    'craft-analytics',
                    'Raw journeys may not be kept longer than 26 months.',
                ),
            ],
            [['consentLogRetentionDays'], 'integer', 'min' => 0],
            [['policyVersion'], 'string', 'max' => 32],
            [['policyVersion'], 'required'],

            // Pro analytics
            [
                [
                    'enableCampaigns', 'enableGeo', 'enableEvents', 'trackOutbound',
                    'trackDownloads', 'trackScroll', 'trackSiteSearch',
                ],
                'boolean',
            ],
            [['attributionModel'], 'in', 'range' => ['last-click', 'first-click', 'linear']],
            [['siteSearchPath', 'siteSearchParam'], 'string', 'max' => 128],
            [['downloadExtensions'], 'each', 'rule' => ['string']],

            // Crawlers
            [['blockCrawlers', 'trackCrawlers'], 'boolean'],

            // Scheduled reports
            [['enableScheduledReports'], 'boolean'],
            [['reportPeriod'], 'in', 'range' => array_keys(DateRange::presets())],
            [['reportRecipients'], 'each', 'rule' => ['string']],
            [['reportRecipients'], 'validateRecipients'],

            // GA4 import. No format is enforced: the client ID and secret are
            // opaque strings Google issues, and either may be a `$ENV_VAR`
            // reference whose value belongs to the environment.
            [['ga4ClientId', 'ga4ClientSecret'], 'string'],
        ];
    }

    /**
     * Checks the report recipients look like addresses.
     *
     * Env vars are left alone: their value belongs to the environment, and
     * this may well be running somewhere the real address isn't set.
     */
    public function validateRecipients(string $attribute): void
    {
        foreach ($this->reportRecipients as $recipient) {
            $recipient = trim((string)$recipient);

            if ($recipient === '' || str_starts_with($recipient, '$')) {
                continue;
            }

            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $this->addError($attribute, Craft::t(
                    'craft-analytics',
                    '“{value}” is not an email address.',
                    ['value' => $recipient],
                ));
            }
        }
    }

    /**
     * Whether any setting here would put a cookie or a device identifier on a
     * visitor's machine — i.e. whether the banner-free claim still holds.
     *
     * The privacy posture panel leans on this, and it is deliberately
     * conservative: it answers "could we", not "have we".
     */
    public function usesDeviceStorage(): bool
    {
        return $this->enableConsent;
    }
}
