<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use yii\base\Component;
use yii\caching\CacheInterface;
use yii\db\Connection;
use yii\db\Query;
use yii\mutex\Mutex;

/**
 * The rotating salt behind anonymous visitor hashing.
 *
 * A single row holds the current salt. On rotation the previous salt is
 * overwritten and destroyed — nothing anywhere retains it — which is what
 * makes cross-window re-identification of a Tier-1 visitor infeasible, and is
 * the entire legal basis for tracking without a consent banner.
 *
 * Consequence, by design: visitor hashes are only comparable within a
 * rotation window. Multi-day unique counts are therefore on a daily-unique
 * basis (see docs/configuration.md).
 */
class SaltService extends Component
{
    private const CACHE_KEY = 'craftAnalytics.salt';
    private const SALT_BYTES = 32;

    /**
     * How long to wait for another worker's rotation.
     *
     * Short: the work under the lock is one small write, and a request that
     * waits longer than this is better off rotating itself than holding a
     * visitor's worker.
     */
    private const LOCK_TIMEOUT = 3;

    /** Connection override — tests and (later) the Pro external database. */
    public ?Connection $db = null;

    /** Settings override; defaults to the plugin's. */
    public ?Settings $settings = null;

    /** Cache override; set false to disable caching (tests). */
    public CacheInterface|false|null $cache = null;

    /** @var array{salt: string, nextRotation: int}|null */
    private ?array $current = null;

    /**
     * Returns the current salt, rotating first if the window has elapsed.
     */
    public function getCurrentSalt(): string
    {
        $salt = $this->current ??= $this->load();

        if ($salt['nextRotation'] <= time()) {
            return $this->rotateOnce()['salt'];
        }

        return $salt['salt'];
    }

    /**
     * Rotates under a lock, so a burst of requests crossing the boundary
     * produces one new salt rather than one each.
     *
     * Unlocked, every worker that noticed the window had elapsed generated its
     * own salt and wrote it over the others. Each one then hashed its own
     * request with the salt it had made, so visitors arriving in that moment
     * were split across several salts - new hashes, new sessions, and the
     * split landed every rotation rather than rarely.
     *
     * A worker that cannot take the lock waits for the holder and re-reads,
     * which is the whole point: the value it wants is the one being written.
     * If there is no mutex to be had it rotates anyway, because a salt that is
     * overdue is worse than a salt that is duplicated.
     *
     * @return array{salt: string, nextRotation: int}
     */
    private function rotateOnce(): array
    {
        $mutex = $this->mutex();
        $name = 'craftAnalytics.saltRotation';

        if ($mutex === null || !$mutex->acquire($name, self::LOCK_TIMEOUT)) {
            return $this->current = $this->rotate();
        }

        try {
            // Re-read now the lock is held: whoever held it before this may
            // have rotated already, and rotating again would throw away a
            // salt that is already in use.
            $fresh = $this->load(refresh: true);

            if ($fresh['nextRotation'] > time()) {
                return $this->current = $fresh;
            }

            return $this->current = $this->rotate();
        } finally {
            $mutex->release($name);
        }
    }

    private function mutex(): ?Mutex
    {
        // Null only where there is no application to ask - the test harness.
        try {
            return Craft::$app->getMutex();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Generates a new salt, destroying the previous one.
     *
     * @return array{salt: string, nextRotation: int}
     */
    public function rotate(): array
    {
        $salt = bin2hex(random_bytes(self::SALT_BYTES));
        $now = time();
        $nextRotation = $this->nextRotationAfter($now);

        $db = $this->db();
        $exists = (new Query())->from(Table::SALTS)->where(['id' => 1])->exists($db);

        $row = [
            'salt' => $salt,
            'rotatedAt' => gmdate('Y-m-d H:i:s', $now),
            'nextRotation' => gmdate('Y-m-d H:i:s', $nextRotation),
        ];

        if ($exists) {
            // Overwrite in place: the old salt is gone, not archived.
            $db->createCommand()->update(Table::SALTS, $row, ['id' => 1])->execute();
        } else {
            $db->createCommand()->insert(Table::SALTS, $row + ['id' => 1])->execute();
        }

        $current = ['salt' => $salt, 'nextRotation' => $nextRotation];
        $this->current = $current;
        $this->cache()?->set(self::CACHE_KEY, $current, max(60, $nextRotation - $now));

        return $current;
    }

    /**
     * When the next rotation is due: one interval out, nudged to the
     * configured quiet hour when the interval is a whole number of days, so
     * fewer live sessions are split across the boundary.
     */
    public function nextRotationAfter(int $now): int
    {
        $settings = $this->settings();
        $target = $now + $settings->saltRotationInterval;

        if ($settings->saltRotationInterval % 86400 !== 0) {
            return $target;
        }

        $tz = new \DateTimeZone(Craft::$app->getTimeZone());
        $aligned = (new \DateTimeImmutable('@' . $target))
            ->setTimezone($tz)
            ->setTime($settings->saltRotationHour, 0)
            ->getTimestamp();

        // Never rotate early: if the quiet hour has already passed for the
        // target day, take the next one.
        return $aligned <= $now ? $aligned + 86400 : $aligned;
    }

    /**
     * @return array{salt: string, nextRotation: int}
     */
    private function load(bool $refresh = false): array
    {
        $cached = $refresh ? false : $this->cache()?->get(self::CACHE_KEY);

        if (is_array($cached) && isset($cached['salt'], $cached['nextRotation'])) {
            /** @var array{salt: string, nextRotation: int} $cached */
            return $cached;
        }

        /** @var array{salt: string, nextRotation: string}|null $row */
        $row = (new Query())
            ->select(['salt', 'nextRotation'])
            ->from(Table::SALTS)
            ->where(['id' => 1])
            ->one($this->db()) ?: null;

        if ($row === null) {
            return $this->rotate();
        }

        return [
            'salt' => $row['salt'],
            'nextRotation' => (int)strtotime($row['nextRotation'] . ' UTC'),
        ];
    }

    private function settings(): Settings
    {
        return $this->settings ??= Plugin::getInstance()->getSettings();
    }

    private function cache(): ?CacheInterface
    {
        if ($this->cache === false) {
            return null;
        }

        return $this->cache ??= Craft::$app->getCache();
    }

    private function db(): Connection
    {
        return $this->db ??= Craft::$app->getDb();
    }
}
