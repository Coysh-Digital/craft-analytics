<?php

namespace coyshdigital\craftanalytics\uniques;

use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use yii\base\Component;
use yii\redis\Cache as RedisCache;
use yii\redis\Connection as RedisConnection;

/**
 * Preferred driver: Redis's native HyperLogLog, with the portable sketch on
 * the rollup row as the durable copy.
 *
 * `PFADD`/`PFCOUNT`/`PFMERGE` are the same algorithm implemented in C, at
 * ~12 KB and ~0.81% error per counter, with the merge done by Redis. When a
 * site already runs Redis this is effectively free.
 *
 * It is not, however, durable. The connection this driver finds is the one
 * behind Craft's data cache, so the counters share a Redis database with the
 * cache - and `php craft clear-caches/data`, the Clear Caches utility and an
 * `allkeys-lru` eviction policy all treat them as cache. Twenty-six months of
 * unique visitors used to go with one deploy-time cache clear, with nothing
 * to say so. Every write therefore also merges the visitors into the
 * portable sketch on the rollup row, exactly as the `hll` driver does, and a
 * read whose Redis keys have gone answers from those instead. Redis stays
 * the fast path; the database is the record.
 *
 * Rows written before this driver kept a row sketch have none, so for them
 * the Redis key is still the only copy.
 */
class RedisUniqueCounter extends Component implements UniqueCounterInterface
{
    private const KEY_PREFIX = 'ca:u:';

    /** Keys per command, before the union has to be assembled in steps. */
    private const MAX_KEYS_PER_COMMAND = 1000;

    public ?RedisConnection $redis = null;
    public ?Settings $settings = null;

    /** The row-sketch half of the driver. Injectable for tests. */
    public ?HllUniqueCounter $rowSketch = null;

    /** Said once per process: a flushed Redis is one event, not one per row. */
    private static bool $warnedMissingKeys = false;

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

    /**
     * True since the row sketch became the durable copy. The sink pays the
     * same locked read-modify-write of the blob the `hll` driver does, which
     * is the price of surviving a cache flush.
     */
    public function storesOnRow(): bool
    {
        return true;
    }

    public function record(UniqueScope $scope, array $hashes, ?string $currentSketch): ?string
    {
        if ($hashes === []) {
            return $currentSketch;
        }

        $key = self::KEY_PREFIX . $scope->key();
        $this->connection()->executeCommand('PFADD', array_merge([$key], array_values($hashes)));

        // Counters outlive the rollups they describe by a day so a range
        // query at the edge of retention still has them.
        $this->connection()->executeCommand('EXPIRE', [$key, $this->ttlSeconds()]);

        // And the same visitors into the sketch on the row, which is what a
        // read falls back to once the key above has been flushed away.
        return $this->rowSketch()->record($scope, $hashes, $currentSketch);
    }

    public function estimate(array $scopes, iterable $sketches = []): int
    {
        if ($scopes === []) {
            return 0;
        }

        // Variadic PFCOUNT unions the keys server-side, so a month is the
        // union of its days rather than the sum.
        $keys = array_map(static fn(UniqueScope $scope) => self::KEY_PREFIX . $scope->key(), $scopes);

        // All or nothing: a Redis HLL and the row sketch are different
        // encodings and cannot be merged with each other, so a range with any
        // key missing is answered entirely from the rows. A missing key means
        // the cache was flushed or evicted, and then every key written before
        // that moment is gone together.
        if (!$this->allKeysExist($keys)) {
            if (!self::$warnedMissingKeys) {
                self::$warnedMissingKeys = true;
                Craft::warning(
                    'Unique-visitor counters are missing from Redis (the data cache was cleared, or Redis '
                    . 'evicted them); answering from the sketches on the rollup rows instead. Rows written '
                    . 'before the row sketch was kept carry none and read as zero.',
                    __METHOD__,
                );
            }

            return $this->rowSketch()->estimate($scopes, $sketches);
        }

        if (count($keys) <= self::MAX_KEYS_PER_COMMAND) {
            return (int)$this->connection()->executeCommand('PFCOUNT', $keys);
        }

        // A wide range on a busy site produces more scopes than belong in one
        // command. They cannot be counted a chunk at a time and added up -
        // that would count anybody appearing in two chunks twice - so the
        // chunks are merged into one temporary sketch and that is counted.
        // PFMERGE is a union, so the result is identical to the single-command
        // form, just assembled in several steps.
        $temp = self::KEY_PREFIX . 'tmp:' . bin2hex(random_bytes(8));
        $redis = $this->connection();

        try {
            foreach (array_chunk($keys, self::MAX_KEYS_PER_COMMAND) as $chunk) {
                $redis->executeCommand('PFMERGE', array_merge([$temp], $chunk));
                // Bounded lifetime from the first write, so an interruption
                // between here and the delete cannot leave it behind forever.
                $redis->executeCommand('EXPIRE', [$temp, 300]);
            }

            return (int)$redis->executeCommand('PFCOUNT', [$temp]);
        } finally {
            $redis->executeCommand('DEL', [$temp]);
        }
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

    /**
     * Whether every one of these keys is still in Redis.
     *
     * One round trip per thousand scopes - cheap next to the PFCOUNT that
     * follows, and the only way to know the answer that follows is complete.
     *
     * @param string[] $keys
     */
    private function allKeysExist(array $keys): bool
    {
        $redis = $this->connection();
        $found = 0;

        foreach (array_chunk($keys, self::MAX_KEYS_PER_COMMAND) as $chunk) {
            $found += (int)$redis->executeCommand('EXISTS', $chunk);
        }

        return $found === count($keys);
    }

    private function rowSketch(): HllUniqueCounter
    {
        return $this->rowSketch ??= new HllUniqueCounter(['settings' => $this->settings]);
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
