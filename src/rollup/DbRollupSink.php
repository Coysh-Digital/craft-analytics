<?php

namespace coyshdigital\craftanalytics\rollup;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\db\Upsert;
use coyshdigital\craftanalytics\enums\DimensionType;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\services\ChannelClassifier;
use coyshdigital\craftanalytics\services\DeviceParser;
use coyshdigital\craftanalytics\services\IdentityService;
use coyshdigital\craftanalytics\session\Session;
use coyshdigital\craftanalytics\uniques\UniqueCounterInterface;
use coyshdigital\craftanalytics\uniques\UniqueScope;
use Craft;
use yii\base\Component;
use yii\db\Connection;
use yii\db\Query;

/**
 * Writes a drained batch into the rollup tables.
 *
 * Called inside the drain's transaction, so anything thrown here rolls the
 * whole batch back and it is retried intact on the next run. Which is the
 * reason a record this cannot use is dropped with a warning rather than
 * thrown: it would fail identically on every retry, and the batch would never
 * commit. Throw only for faults a retry could actually clear.
 *
 * Everything is an upsert against a unique key, so a busy page's thousandth
 * view of the hour updates the same row as its first (C2). Dimension values
 * are resolved to int IDs and capped on the way in.
 */
class DbRollupSink extends Component implements RollupSinkInterface
{
    public ?Connection $db = null;
    public ?UniqueCounterInterface $counter = null;
    public ?DimensionCapper $capper = null;
    public ?ChannelClassifier $channels = null;
    public ?DeviceParser $devices = null;
    public ?ProRollupWriter $pro = null;

    /** Settings override; defaults to the plugin's. Set in tests. */
    public ?Settings $settings = null;

    public function flush(array $buckets, array $closedSessions, ?InteractionBuckets $interactions = null): void
    {
        foreach ($buckets as $bucket) {
            $this->writePageBucket($bucket);
        }

        foreach ($closedSessions as $session) {
            $this->writeSession($session);
        }

        if ($interactions !== null && !$interactions->isEmpty()) {
            // Crawler counts are not a Pro feature: knowing what was excluded
            // from your own numbers is not a premium concern.
            $this->writeCrawlers($interactions);
            $this->pro()->writeInteractions($interactions);
        }
    }

    /**
     * One row per crawler per day. Capped like any other dimension, so a
     * flood of one-off scrapers cannot grow the table without bound.
     */
    private function writeCrawlers(InteractionBuckets $interactions): void
    {
        foreach ($interactions->crawlers as $crawler) {
            Upsert::counters($this->db(), Table::CRAWLERS_ROLLUP, [
                'siteId' => $crawler['siteId'],
                'date' => $crawler['date'],
                'crawlerDimId' => $this->capper()->resolve(
                    $crawler['siteId'],
                    $crawler['date'],
                    DimensionType::Crawler,
                    $crawler['name'],
                ),
            ], [
                'requests' => $crawler['requests'],
            ]);
        }
    }

    private function writePageBucket(PageBucket $bucket): void
    {
        $pathDimId = $this->capper()->resolve(
            $bucket->siteId,
            $bucket->date,
            DimensionType::Path,
            $bucket->path,
        );

        $keys = [
            'siteId' => $bucket->siteId,
            'date' => $bucket->date,
            'hour' => $bucket->hour,
            'pathDimId' => $pathDimId,
        ];

        Upsert::counters(
            $this->db(),
            Table::PAGES_ROLLUP,
            $keys,
            ['views' => $bucket->views, 'totalDwellMs' => $bucket->dwellMs],
            // Only set when the row is created: a path's element doesn't
            // change mid-hour, and re-writing it on every upsert would be
            // pointless work.
            $bucket->elementId !== null ? ['elementId' => $bucket->elementId] : [],
        );

        $this->recordUniques(
            Table::PAGES_ROLLUP,
            $keys,
            new UniqueScope(UniqueScope::KIND_PAGE, $bucket->siteId, $bucket->date, $bucket->hour, $pathDimId),
            array_keys($bucket->visitorHashes),
        );
    }

    /**
     * Folds a finished session into every rollup that describes it.
     *
     * This is the moment the hot layer's counters become permanent and the
     * session itself ceases to exist — the trade that buys session metrics
     * without a single raw hit row (C6).
     */
    private function writeSession(Session $session): void
    {
        [$date, $hour] = $this->dateAndHour($session->startedAt);
        $isBounce = $session->isBounce();

        $sessionKeys = ['siteId' => $session->siteId, 'date' => $date, 'hour' => $hour];

        Upsert::counters($this->db(), Table::SESSIONS_ROLLUP, $sessionKeys, [
            'sessions' => 1,
            'bounces' => $isBounce ? 1 : 0,
            'totalDurationMs' => $session->durationMs(),
            'totalPageviews' => $session->pageviews,
        ]);

        $this->recordUniques(
            Table::SESSIONS_ROLLUP,
            $sessionKeys,
            new UniqueScope(UniqueScope::KIND_SESSION, $session->siteId, $date, $hour),
            [$session->visitorHash],
        );

        $this->writeSource($session, $date, $hour, $isBounce);
        $this->writeDevice($session, $date);
        $this->writeEntryAndExit($session, $date, $hour, $isBounce);

        // Campaigns and geo are session-scoped and Pro-gated; on Lite this
        // returns immediately.
        $this->pro()->writeSession($session, $date);
    }

    private function pro(): ProRollupWriter
    {
        return $this->pro ??= new ProRollupWriter(['db' => $this->db, 'capper' => $this->capper]);
    }


    private function writeSource(Session $session, string $date, int $hour, bool $isBounce): void
    {
        // A tagged arrival is Campaign, whatever the referrer header said - the
        // same fact the campaigns rollup is about to record, so the two screens
        // agree. Gated on `enableCampaigns` for the same reason ProRollupWriter
        // is: a site that has turned campaigns off should not find a Campaign
        // channel in its sources report.
        $hasCampaign = $this->settings()->enableCampaigns && $session->campaigns !== [];

        $channel = $this->channels()->classify($session->referrer, $hasCampaign);
        $host = ChannelClassifier::host($session->referrer);

        $refHostDimId = $host === null
            ? 0
            : $this->capper()->resolve($session->siteId, $date, DimensionType::ReferrerHost, $host);

        Upsert::counters($this->db(), Table::SOURCES_ROLLUP, [
            'siteId' => $session->siteId,
            'date' => $date,
            'hour' => $hour,
            'channel' => $channel->value,
            'refHostDimId' => $refHostDimId,
        ], [
            'sessions' => 1,
            'bounces' => $isBounce ? 1 : 0,
        ]);
    }

    private function writeDevice(Session $session, string $date): void
    {
        $device = $this->devices()->parse($session->userAgent);

        Upsert::counters($this->db(), Table::DEVICES_ROLLUP, [
            'siteId' => $session->siteId,
            'date' => $date,
            'browserDimId' => $this->capper()->resolve($session->siteId, $date, DimensionType::Browser, $device['browser']),
            'browserMajor' => $device['browserMajor'],
            'osDimId' => $this->capper()->resolve($session->siteId, $date, DimensionType::Os, $device['os']),
            'deviceType' => $device['deviceType']->value,
        ], [
            'sessions' => 1,
        ]);
    }

    /**
     * Entry/exit/bounce counters land on the page rows they describe, so
     * "which pages do people leave from" is answerable without ever relating
     * two hits at query time.
     */
    private function writeEntryAndExit(Session $session, string $date, int $hour, bool $isBounce): void
    {
        $entryDimId = $this->capper()->resolve($session->siteId, $date, DimensionType::Path, $session->entryPath);

        Upsert::counters($this->db(), Table::PAGES_ROLLUP, [
            'siteId' => $session->siteId,
            'date' => $date,
            'hour' => $hour,
            'pathDimId' => $entryDimId,
        ], [
            'entrances' => 1,
            'bounces' => $isBounce ? 1 : 0,
        ]);

        // A one-page session entered and left by the same page; counting an
        // exit separately would double it.
        [$exitDate, $exitHour] = $this->dateAndHour($session->lastSeenAt);
        $exitDimId = $session->entryPath === $session->lastPath && $exitDate === $date && $exitHour === $hour
            ? $entryDimId
            : $this->capper()->resolve($session->siteId, $exitDate, DimensionType::Path, $session->lastPath);

        Upsert::counters($this->db(), Table::PAGES_ROLLUP, [
            'siteId' => $session->siteId,
            'date' => $exitDate,
            'hour' => $exitHour,
            'pathDimId' => $exitDimId,
        ], [
            'exits' => 1,
        ]);
    }

    /**
     * Hands visitor hashes to the unique counter, and — for the sketch driver
     * — merges the result back onto the row.
     *
     * The row is locked for the read-modify-write so two drains (or a `direct`
     * writer under load) can't clobber each other's sketch.
     *
     * @param array<string,mixed> $keys
     * @param string[] $hashes
     */
    private function recordUniques(string $table, array $keys, UniqueScope $scope, array $hashes): void
    {
        $counter = $this->counter();
        $hashes = $this->usableHashes($table, $hashes);

        if (!$counter->storesOnRow()) {
            $counter->record($scope, $hashes, null);

            return;
        }

        $current = $this->lockAndReadSketch($table, $keys);
        $blob = $counter->record($scope, $hashes, $current);

        if ($blob === null) {
            return;
        }

        $this->db()->createCommand()
            ->update($table, ['uniques' => new \yii\db\PdoValue($blob, \PDO::PARAM_LOB)], $keys)
            ->execute();
    }

    /**
     * Drops hashes no counter driver can accept.
     *
     * This is the backstop for a bad value that reached the hot layer rather
     * than the spool — a session cached by a build with a different hash
     * width, say. Such a session is already durable in the cache and is
     * re-staged by every drain, so throwing on it wedges the pipeline
     * permanently. Losing one visitor from a bucket's unique estimate is a
     * rounding error against losing every write that follows.
     *
     * @param string[] $hashes
     * @return string[]
     */
    private function usableHashes(string $table, array $hashes): array
    {
        $usable = array_values(array_filter($hashes, IdentityService::isValidHash(...)));
        $dropped = count($hashes) - count($usable);

        if ($dropped > 0) {
            Craft::warning(sprintf(
                'Skipped %d unusable visitor hash(es) while rolling up %s. '
                . 'These were most likely written by a different plugin version; the rollup is otherwise intact.',
                $dropped,
                $table,
            ), __METHOD__);
        }

        return $usable;
    }

    /**
     * @param array<string,mixed> $keys
     */
    private function lockAndReadSketch(string $table, array $keys): ?string
    {
        $db = $this->db();
        $query = (new Query())->select('uniques')->from($table)->where($keys);
        [$sql, $params] = $db->getQueryBuilder()->build($query);

        // Both MySQL and Postgres take FOR UPDATE here; it serialises
        // concurrent writers on this row until the transaction commits.
        $value = $db->createCommand($sql . ' FOR UPDATE', $params)->queryScalar();

        if ($value === false || $value === null) {
            return null;
        }

        // Postgres hands bytea back as a stream.
        if (is_resource($value)) {
            return (string)stream_get_contents($value);
        }

        return (string)$value;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function dateAndHour(int $timestamp): array
    {
        $local = (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()));

        return [$local->format('Y-m-d'), (int)$local->format('G')];
    }

    private function counter(): UniqueCounterInterface
    {
        return $this->counter ??= Plugin::getInstance()->getUniqueCounter();
    }

    private function capper(): DimensionCapper
    {
        return $this->capper ??= new DimensionCapper(['db' => $this->db]);
    }

    private function channels(): ChannelClassifier
    {
        return $this->channels ??= Plugin::getInstance()->getChannels();
    }

    private function settings(): Settings
    {
        return $this->settings ??= Plugin::getInstance()->getSettings();
    }

    private function devices(): DeviceParser
    {
        return $this->devices ??= Plugin::getInstance()->getDeviceParser();
    }

    private function db(): Connection
    {
        return $this->db ??= Craft::$app->getDb();
    }
}
