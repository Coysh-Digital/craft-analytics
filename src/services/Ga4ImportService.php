<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\db\Upsert;
use coyshdigital\craftanalytics\enums\Channel;
use coyshdigital\craftanalytics\enums\DeviceType;
use coyshdigital\craftanalytics\enums\DimensionType;
use coyshdigital\craftanalytics\enums\Ga4Dataset;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\rollup\DimensionCapper;
use coyshdigital\craftanalytics\uniques\UniqueCounterInterface;
use coyshdigital\craftanalytics\uniques\UniqueScope;
use Craft;
use yii\base\Component;
use yii\db\Connection;
use yii\db\PdoValue;
use yii\db\Query;

/**
 * Turns GA4 Data API reports into rollup rows.
 *
 * The counterpart to DbRollupSink, but for aggregates that arrive already
 * summed rather than as individual hits. GA4 returns one row per (day,
 * dimension) tuple, which maps straight onto the day-keyed rollup rows, so this
 * writes them directly through the same Upsert helper and DimensionCapper the
 * live drain uses. Every row lands at `hour = -1` (UniqueScope::HOUR_DAILY),
 * the shape the nightly compactor leaves behind, so an imported day and a lived
 * one are indistinguishable to every reader.
 *
 * What it cannot reconstruct is the unique-visitor sketch: GA4 gives a count,
 * not the visitors behind it. So each row's `uniques` is *seeded* with that
 * many synthetic distinct tokens, which a HyperLogLog sketch then estimates
 * back to roughly the reported figure. See {@see seedUniques()}.
 *
 * Collaborators are injectable so the mapping can be tested against fixture GA4
 * payloads without a live connection, exactly as DbRollupSink is.
 */
class Ga4ImportService extends Component
{
    /**
     * The most synthetic tokens seeded for one row's unique count.
     *
     * Bounds the work a single very large day can ask for (each token is an
     * insert into the sketch, and for the `exact` driver a membership row). A
     * day with more distinct visitors than this on one scope has its imported
     * unique estimate capped here; the day's counted metrics stay exact. No
     * ordinary site approaches it.
     */
    public const MAX_SEEDED_UNIQUES = 100000;

    /**
     * GA4's placeholders for "no value here". None of them is a real source,
     * campaign, country or region, and treating them as one fills the reports
     * with rows named "(not set)".
     */
    private const PLACEHOLDERS = ['', '(not set)', '(direct)', '(none)', '(organic)', '(referral)', '(data not available)'];

    public ?Connection $db = null;
    public ?DimensionCapper $capper = null;
    public ?UniqueCounterInterface $counter = null;
    public ?Settings $settings = null;

    /** Edition override; defaults to the plugin's own. Set in tests. */
    public ?bool $isPro = null;

    /**
     * Whether to resolve each page path to a Craft element, so the Content
     * reports and the entry sidebar have something to join to. Off in the
     * minimal test harness, which has no elements service.
     */
    public bool $resolveElements = true;

    /** @var array<string,int|null> path => elementId */
    private array $elementIds = [];

    /**
     * The dates in a range that already carry the plugin's own rollups, and so
     * must not be imported over (the chosen overlap policy: skip).
     *
     * Checked against the pages and sessions rollups, the two every lived day
     * writes. A day present in either is a day the plugin was already
     * measuring.
     *
     * @return array<string,true> 'Y-m-d' => true
     */
    public function occupiedDates(int $siteId, string $startDate, string $endDate): array
    {
        $dates = [];

        foreach ([Table::PAGES_ROLLUP, Table::SESSIONS_ROLLUP] as $table) {
            $rows = (new Query())
                ->select('date')
                ->distinct()
                ->from($table)
                ->where(['siteId' => $siteId])
                ->andWhere(['between', 'date', $startDate, $endDate])
                ->column($this->db());

            foreach ($rows as $date) {
                // The driver may hand a DATE back with a time component.
                $dates[substr((string)$date, 0, 10)] = true;
            }
        }

        return $dates;
    }

    /**
     * Writes one GA4 report into the rollups.
     *
     * A Pro dataset on a Lite install writes nothing: the rollup it targets
     * does not exist in the reports there, and importing into it would be data
     * no screen can show.
     *
     * @param array<string,mixed> $response a GA4 Data API runReport response
     * @param array<string,true> $skipDates dates already occupied, skipped
     * @return int rows written
     */
    public function import(Ga4Dataset $dataset, int $siteId, array $response, array $skipDates = []): int
    {
        if ($dataset->isPro() && !$this->isPro()) {
            return 0;
        }

        $written = 0;

        foreach ($this->normalizeRows($dataset, $response) as $row) {
            if (isset($skipDates[$row['date']])) {
                continue;
            }

            $this->writeRow($dataset, $siteId, $row['date'], $row['dims'], $row['metrics']);
            $written++;
        }

        return $written;
    }

    /**
     * @param array<string,string> $dims
     * @param array<string,string> $metrics
     */
    private function writeRow(Ga4Dataset $dataset, int $siteId, string $date, array $dims, array $metrics): void
    {
        match ($dataset) {
            Ga4Dataset::Pages => $this->writePages($siteId, $date, $dims, $metrics),
            Ga4Dataset::Totals => $this->writeTotals($siteId, $date, $metrics),
            Ga4Dataset::Sources => $this->writeSources($siteId, $date, $dims, $metrics),
            Ga4Dataset::Campaigns => $this->writeCampaigns($siteId, $date, $dims, $metrics),
            Ga4Dataset::Devices => $this->writeDevices($siteId, $date, $dims, $metrics),
            Ga4Dataset::Geo => $this->writeGeo($siteId, $date, $dims, $metrics),
            Ga4Dataset::Events => $this->writeEvents($siteId, $date, $dims, $metrics),
        };
    }

    /**
     * @param array<string,string> $dims
     * @param array<string,string> $metrics
     */
    private function writePages(int $siteId, string $date, array $dims, array $metrics): void
    {
        $path = $this->path($dims['pagePath'] ?? '');

        if ($path === '') {
            return;
        }

        $pathDimId = $this->capper()->resolve($siteId, $date, DimensionType::Path, $path);
        $keys = [
            'siteId' => $siteId,
            'date' => $date,
            'hour' => UniqueScope::HOUR_DAILY,
            'pathDimId' => $pathDimId,
        ];

        $elementId = $this->elementIdForPath($siteId, $path);

        Upsert::counters(
            $this->db(),
            Table::PAGES_ROLLUP,
            $keys,
            [
                'views' => (int)round((float)($metrics['screenPageViews'] ?? 0)),
                // GA4 reports engagement time in seconds; the rollup holds ms.
                'totalDwellMs' => (int)round((float)($metrics['userEngagementDuration'] ?? 0) * 1000),
            ],
            $elementId !== null ? ['elementId' => $elementId] : [],
            ['elementId'],
        );

        $this->seedUniques(
            Table::PAGES_ROLLUP,
            $keys,
            new UniqueScope(UniqueScope::KIND_PAGE, $siteId, $date, UniqueScope::HOUR_DAILY, $pathDimId),
            (int)round((float)($metrics['activeUsers'] ?? 0)),
        );
    }

    /**
     * @param array<string,string> $metrics
     */
    private function writeTotals(int $siteId, string $date, array $metrics): void
    {
        $sessions = (int)round((float)($metrics['sessions'] ?? 0));
        $keys = ['siteId' => $siteId, 'date' => $date, 'hour' => UniqueScope::HOUR_DAILY];

        Upsert::counters($this->db(), Table::SESSIONS_ROLLUP, $keys, [
            'sessions' => $sessions,
            // GA4 gives a bounce *rate*; the rollup keeps a count.
            'bounces' => (int)round($sessions * (float)($metrics['bounceRate'] ?? 0)),
            'totalDurationMs' => (int)round((float)($metrics['userEngagementDuration'] ?? 0) * 1000),
            'totalPageviews' => (int)round((float)($metrics['screenPageViews'] ?? 0)),
        ]);

        $this->seedUniques(
            Table::SESSIONS_ROLLUP,
            $keys,
            new UniqueScope(UniqueScope::KIND_SESSION, $siteId, $date, UniqueScope::HOUR_DAILY),
            (int)round((float)($metrics['activeUsers'] ?? 0)),
        );
    }

    /**
     * @param array<string,string> $dims
     * @param array<string,string> $metrics
     */
    private function writeSources(int $siteId, string $date, array $dims, array $metrics): void
    {
        $channel = self::channelFromGa4($dims['sessionDefaultChannelGroup'] ?? '');
        $source = $dims['sessionSource'] ?? '';

        $refHostDimId = self::isPlaceholder($source)
            ? 0
            : $this->capper()->resolve($siteId, $date, DimensionType::ReferrerHost, $source);

        $sessions = (int)round((float)($metrics['sessions'] ?? 0));

        Upsert::counters($this->db(), Table::SOURCES_ROLLUP, [
            'siteId' => $siteId,
            'date' => $date,
            'hour' => UniqueScope::HOUR_DAILY,
            'channel' => $channel->value,
            'refHostDimId' => $refHostDimId,
        ], [
            'sessions' => $sessions,
            'bounces' => (int)round($sessions * (float)($metrics['bounceRate'] ?? 0)),
        ]);
    }

    /**
     * @param array<string,string> $dims
     * @param array<string,string> $metrics
     */
    private function writeCampaigns(int $siteId, string $date, array $dims, array $metrics): void
    {
        $source = self::campaignValue($dims['sessionSource'] ?? '');
        $medium = self::campaignValue($dims['sessionMedium'] ?? '');
        $campaign = self::campaignValue($dims['sessionCampaignName'] ?? '');

        // No source and no campaign is not a campaign touch. This is untagged
        // traffic GA4 grouped for its own report, and it belongs in Sources,
        // not here.
        if ($source === '' && $campaign === '') {
            return;
        }

        $sessions = (float)($metrics['sessions'] ?? 0);

        Upsert::counters($this->db(), Table::CAMPAIGNS_ROLLUP, [
            'siteId' => $siteId,
            'date' => $date,
            'sourceDimId' => $this->dimIdOrZero($siteId, $date, DimensionType::CampaignSource, $source),
            'mediumDimId' => $this->dimIdOrZero($siteId, $date, DimensionType::CampaignMedium, $medium),
            'campaignDimId' => $this->dimIdOrZero($siteId, $date, DimensionType::CampaignName, $campaign),
            'termDimId' => 0,
            'contentDimId' => 0,
        ], [
            'sessions' => $sessions,
            'bounces' => $sessions * (float)($metrics['bounceRate'] ?? 0),
        ], caps: [
            'sessions' => Upsert::DECIMAL_12_4_MAX,
            'bounces' => Upsert::DECIMAL_12_4_MAX,
        ]);
    }

    /**
     * @param array<string,string> $dims
     * @param array<string,string> $metrics
     */
    private function writeDevices(int $siteId, string $date, array $dims, array $metrics): void
    {
        $browser = self::orUnknown($dims['browser'] ?? '');
        $os = self::orUnknown($dims['operatingSystem'] ?? '');

        Upsert::counters($this->db(), Table::DEVICES_ROLLUP, [
            'siteId' => $siteId,
            'date' => $date,
            'browserDimId' => $this->capper()->resolve($siteId, $date, DimensionType::Browser, $browser),
            // GA4 gives no clean browser major version, so it stays 0 - the
            // same value live capture uses when it cannot parse one.
            'browserMajor' => 0,
            'osDimId' => $this->capper()->resolve($siteId, $date, DimensionType::Os, $os),
            'deviceType' => self::deviceTypeFromGa4($dims['deviceCategory'] ?? '')->value,
        ], [
            'sessions' => (int)round((float)($metrics['sessions'] ?? 0)),
        ]);
    }

    /**
     * @param array<string,string> $dims
     * @param array<string,string> $metrics
     */
    private function writeGeo(int $siteId, string $date, array $dims, array $metrics): void
    {
        $countryCode = strtoupper(trim($dims['countryId'] ?? ''));

        // The rollup's country is a two-letter ISO code. GA4 hands back a bare
        // "(not set)" for traffic it could not place; there is no row for it.
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            return;
        }

        $region = $dims['region'] ?? '';

        Upsert::counters($this->db(), Table::GEO_ROLLUP, [
            'siteId' => $siteId,
            'date' => $date,
            'countryCode' => $countryCode,
            'regionDimId' => self::isPlaceholder($region)
                ? 0
                : $this->capper()->resolve($siteId, $date, DimensionType::Region, $region),
        ], [
            'sessions' => (int)round((float)($metrics['sessions'] ?? 0)),
        ]);
    }

    /**
     * @param array<string,string> $dims
     * @param array<string,string> $metrics
     */
    private function writeEvents(int $siteId, string $date, array $dims, array $metrics): void
    {
        $name = trim($dims['eventName'] ?? '');

        if ($name === '') {
            return;
        }

        Upsert::counters($this->db(), Table::EVENTS_ROLLUP, [
            'siteId' => $siteId,
            'date' => $date,
            'hour' => UniqueScope::HOUR_DAILY,
            'eventNameDimId' => $this->capper()->resolve($siteId, $date, DimensionType::EventName, $name),
            // Imported per event per day, not per page: GA4's event totals are
            // not tied to a path here, and 0 is the rollup's "no path" key.
            'pathDimId' => 0,
        ], [
            'count' => (int)round((float)($metrics['eventCount'] ?? 0)),
            // GA4 exposes no clean per-event value sum, so it stays 0 rather
            // than being invented.
            'sumValue' => 0,
        ], caps: [
            'sumValue' => Upsert::DECIMAL_14_2_MAX,
        ]);
    }

    /**
     * Seeds a row's unique-visitor sketch to roughly a known count.
     *
     * GA4 tells us how many distinct visitors a scope had, but not who, so the
     * real sketch cannot be rebuilt. Feeding the counter that many distinct
     * synthetic tokens produces a sketch that estimates back to about the same
     * number and merges correctly with neighbouring days - which is all any
     * reader asks of it. The tokens are otherwise meaningless and never leave
     * this row.
     *
     * @param array<string,mixed> $keys the row's unique key, for the sketch update
     */
    private function seedUniques(string $table, array $keys, UniqueScope $scope, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $counter = $this->counter();
        $hashes = [];

        for ($i = 0, $n = min($count, self::MAX_SEEDED_UNIQUES); $i < $n; $i++) {
            $hashes[] = bin2hex(random_bytes(IdentityService::HASH_BYTES));
        }

        // Drivers that key their own storage (Redis, exact) record and return
        // nothing; there is no row blob to write.
        if (!$counter->storesOnRow()) {
            $counter->record($scope, $hashes, null);

            return;
        }

        $blob = $counter->record($scope, $hashes, $this->readSketch($table, $keys));

        if ($blob === null) {
            return;
        }

        $this->db()->createCommand()
            ->update($table, ['uniques' => new PdoValue($blob, \PDO::PARAM_LOB)], $keys)
            ->execute();
    }

    /**
     * @param array<string,mixed> $keys
     */
    private function readSketch(string $table, array $keys): ?string
    {
        $value = (new Query())->select('uniques')->from($table)->where($keys)->scalar($this->db());

        if ($value === false || $value === null || $value === '') {
            return null;
        }

        // Postgres hands bytea back as a stream.
        return is_resource($value) ? (string)stream_get_contents($value) : (string)$value;
    }

    /**
     * Normalises a GA4 response into flat rows keyed by dimension and metric
     * name.
     *
     * Reads the header names GA4 returns, falling back to the dataset's own
     * declared order so a fixture need not carry headers. The leading `date`
     * dimension is lifted out and converted from GA4's `YYYYMMDD` to `Y-m-d`.
     *
     * @param array<string,mixed> $response
     * @return array<int,array{date: string, dims: array<string,string>, metrics: array<string,string>}>
     */
    private function normalizeRows(Ga4Dataset $dataset, array $response): array
    {
        $dimNames = self::headerNames($response, 'dimensionHeaders') ?? $dataset->dimensions();
        $metricNames = self::headerNames($response, 'metricHeaders') ?? $dataset->metrics();

        /** @var array<int,array<string,mixed>> $rawRows */
        $rawRows = is_array($response['rows'] ?? null) ? $response['rows'] : [];
        $rows = [];

        foreach ($rawRows as $raw) {
            $dims = self::zip($dimNames, is_array($raw['dimensionValues'] ?? null) ? $raw['dimensionValues'] : []);
            $metrics = self::zip($metricNames, is_array($raw['metricValues'] ?? null) ? $raw['metricValues'] : []);

            $gaDate = $dims['date'] ?? '';
            unset($dims['date']);

            $date = self::parseDate($gaDate);

            if ($date === null) {
                continue;
            }

            $rows[] = ['date' => $date, 'dims' => $dims, 'metrics' => $metrics];
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $response
     * @return string[]|null
     */
    private static function headerNames(array $response, string $key): ?array
    {
        if (!is_array($response[$key] ?? null)) {
            return null;
        }

        $names = [];

        foreach ($response[$key] as $header) {
            if (is_array($header) && isset($header['name'])) {
                $names[] = (string)$header['name'];
            }
        }

        return $names === [] ? null : $names;
    }

    /**
     * Pairs header names with a row's `{value: ...}` cells.
     *
     * @param string[] $names
     * @param array<int,mixed> $cells
     * @return array<string,string>
     */
    private static function zip(array $names, array $cells): array
    {
        $out = [];

        foreach ($names as $i => $name) {
            $cell = $cells[$i] ?? null;
            $out[$name] = is_array($cell) ? (string)($cell['value'] ?? '') : (string)($cell ?? '');
        }

        return $out;
    }

    private static function parseDate(string $ga4Date): ?string
    {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $ga4Date, $m)) {
            return null;
        }

        return "$m[1]-$m[2]-$m[3]";
    }

    /**
     * Maps a GA4 default channel group onto the plugin's closed channel set.
     *
     * The plugin has six channels; GA4 has twenty-odd, most of them flavours of
     * paid marketing. Anything that is a marketing channel rather than an
     * organic one folds into Campaign, which is the plugin's home for
     * "arrived because of something you ran".
     */
    private static function channelFromGa4(string $group): Channel
    {
        $group = trim($group);

        if ($group === '' || $group === 'Direct' || $group === 'Unassigned') {
            return Channel::Direct;
        }

        if (str_contains($group, 'Search')) {
            return str_contains($group, 'Paid') ? Channel::Campaign : Channel::Search;
        }

        if (str_contains($group, 'Social')) {
            return str_contains($group, 'Paid') ? Channel::Campaign : Channel::Social;
        }

        if ($group === 'Referral') {
            return Channel::Referral;
        }

        // Email, Display, Affiliates, Paid/Organic Shopping and Video, Audio,
        // SMS, Cross-network: all marketing the site owner ran.
        return Channel::Campaign;
    }

    private static function deviceTypeFromGa4(string $category): DeviceType
    {
        return match (strtolower(trim($category))) {
            'desktop' => DeviceType::Desktop,
            'mobile' => DeviceType::Mobile,
            'tablet' => DeviceType::Tablet,
            default => DeviceType::Unknown,
        };
    }

    private static function isPlaceholder(string $value): bool
    {
        return in_array(strtolower(trim($value)), self::PLACEHOLDERS, true);
    }

    /**
     * Cleans a campaign value the way the live pipeline does: lower-cased, so
     * "Newsletter" and "newsletter" are one campaign, and GA4's placeholders
     * become the empty string.
     */
    private static function campaignValue(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return in_array($value, self::PLACEHOLDERS, true) ? '' : $value;
    }

    private static function orUnknown(string $value): string
    {
        $value = trim($value);

        return self::isPlaceholder($value) ? 'Unknown' : $value;
    }

    private function path(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        return str_starts_with($path, '/') ? $path : '/' . $path;
    }

    private function dimIdOrZero(int $siteId, string $date, DimensionType $type, string $value): int
    {
        return $value === '' ? 0 : $this->capper()->resolve($siteId, $date, $type, $value);
    }

    private function elementIdForPath(int $siteId, string $path): ?int
    {
        if (!$this->resolveElements || !method_exists(Craft::$app, 'getElements')) {
            return null;
        }

        if (!array_key_exists($path, $this->elementIds)) {
            $uri = $path === '/' ? '__home__' : ltrim($path, '/');
            // A path may carry a query string GA4 kept; the element lives under
            // the bare URI.
            $uri = explode('?', $uri)[0];

            try {
                $element = Craft::$app->getElements()->getElementByUri($uri, $siteId);
                $this->elementIds[$path] = $element?->id;
            } catch (\Throwable) {
                $this->elementIds[$path] = null;
            }
        }

        return $this->elementIds[$path];
    }

    private function isPro(): bool
    {
        return $this->isPro ??= Plugin::getInstance()->is(Plugin::EDITION_PRO);
    }

    private function capper(): DimensionCapper
    {
        return $this->capper ??= new DimensionCapper(['db' => $this->db]);
    }

    private function counter(): UniqueCounterInterface
    {
        return $this->counter ??= Plugin::getInstance()->getUniqueCounter();
    }

    private function db(): Connection
    {
        return $this->db ??= Craft::$app->getDb();
    }
}
