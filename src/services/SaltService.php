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
            return ($this->current = $this->rotate())['salt'];
        }

        return $salt['salt'];
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
    private function load(): array
    {
        $cached = $this->cache()?->get(self::CACHE_KEY);

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
