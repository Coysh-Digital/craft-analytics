<?php

namespace coyshdigital\craftanalytics\rollup;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\db\Upsert;
use coyshdigital\craftanalytics\enums\DimensionType;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\services\ChannelClassifier;
use coyshdigital\craftanalytics\services\DeviceParser;
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
 * whole batch back and it is retried intact on the next run.
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

    public function flush(array $buckets, array $closedSessions): void
    {
        foreach ($buckets as $bucket) {
            $this->writePageBucket($bucket);
        }

        foreach ($closedSessions as $session) {
            $this->writeSession($session);
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
            ['views' => $bucket->views],
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
    }

    private function writeSource(Session $session, string $date, int $hour, bool $isBounce): void
    {
        $channel = $this->channels()->classify($session->referrer);
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

    private function devices(): DeviceParser
    {
        return $this->devices ??= Plugin::getInstance()->getDeviceParser();
    }

    private function db(): Connection
    {
        return $this->db ??= Craft::$app->getDb();
    }
}
