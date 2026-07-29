<?php

namespace coyshdigital\craftanalytics\uniques;

use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use yii\base\Component;
use yii\redis\Cache as RedisCache;
use yii\redis\Connection as RedisConnection;

/**
 * Preferred driver: Redis's native HyperLogLog.
 *
 * `PFADD`/`PFCOUNT`/`PFMERGE` are the same algorithm implemented in C, at
 * ~12 KB and ~0.81% error per counter, with the merge done by Redis. When a
 * site already runs Redis this is effectively free and keeps the sketches out
 * of the database entirely.
 */
class RedisUniqueCounter extends Component implements UniqueCounterInterface
{
    private const KEY_PREFIX = 'ca:u:';

    public ?RedisConnection $redis = null;
    public ?Settings $settings = null;

    public function name(): string
    {
        return Settings::UNIQUES_DRIVER_REDIS;
    }

    public function accuracy(): string
    {
        return '±0.8%';
    }

    /**
     * Whether a Redis connection can be reached through Craft's cache
     * component — the usual way a Craft site has Redis configured.
     */
    public static function isAvailable(): bool
    {
        return self::resolveConnection() !== null;
    }

    public function storesOnRow(): bool
    {
        return false;
    }

    public function record(UniqueScope $scope, array $hashes, ?string $currentSketch): ?string
    {
        if ($hashes === []) {
            return null;
        }

        $key = self::KEY_PREFIX . $scope->key();
        $this->connection()->executeCommand('PFADD', array_merge([$key], array_values($hashes)));

        // Counters outlive the rollups they describe by a day so a range
        // query at the edge of retention still has them.
        $this->connection()->executeCommand('EXPIRE', [$key, $this->ttlSeconds()]);

        // Nothing to store on the row: Redis is the counter.
        return null;
    }

    public function estimate(array $scopes, iterable $sketches = []): int
    {
        if ($scopes === []) {
            return 0;
        }

        // Variadic PFCOUNT unions the keys server-side, so a month is the
        // union of its days rather than the sum.
        $keys = array_map(static fn(UniqueScope $scope) => self::KEY_PREFIX . $scope->key(), $scopes);

        return (int)$this->connection()->executeCommand('PFCOUNT', $keys);
    }

    /**
     * `PFMERGE` the day's hourly counters into its daily key.
     *
     * A union, so re-running it changes nothing — which is what makes it safe
     * to call inside a transaction that might roll back. The hourly keys are
     * left alone here and dropped by discardCompacted() once the row rewrite
     * has committed.
     */
    public function compact(UniqueScope $daily, array $hourly): void
    {
        if ($hourly === []) {
            return;
        }

        $target = self::KEY_PREFIX . $daily->key();
        $sources = array_map(static fn(UniqueScope $scope) => self::KEY_PREFIX . $scope->key(), $hourly);

        // Destination first, and included as a source: merging into a key that
        // already holds an earlier pass must not discard what is in it.
        $this->connection()->executeCommand('PFMERGE', array_merge([$target, $target], $sources));
        $this->connection()->executeCommand('EXPIRE', [$target, $this->ttlSeconds()]);
    }

    public function discardCompacted(array $hourly): void
    {
        if ($hourly === []) {
            return;
        }

        $this->connection()->executeCommand('DEL', array_map(
            static fn(UniqueScope $scope) => self::KEY_PREFIX . $scope->key(),
            $hourly,
        ));
    }

    private function ttlSeconds(): int
    {
        $months = ($this->settings ??= Plugin::getInstance()->getSettings())->rollupRetentionMonths;

        return (int)round($months * 30.44 * 86400) + 86400;
    }

    private function connection(): RedisConnection
    {
        return $this->redis ??= self::resolveConnection()
            ?? throw new \RuntimeException('The redis unique counter driver requires a Redis connection.');
    }

    private static function resolveConnection(): ?RedisConnection
    {
        if (!class_exists(RedisCache::class)) {
            return null;
        }

        $cache = Craft::$app->getCache();

        if (!$cache instanceof RedisCache) {
            return null;
        }

        // `redis` may still be a component id or config array at this point.
        $connection = $cache->redis;

        return $connection instanceof RedisConnection ? $connection : null;
    }
}
