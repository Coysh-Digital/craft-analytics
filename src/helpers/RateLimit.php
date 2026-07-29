<?php

namespace coyshdigital\craftanalytics\helpers;

use Craft;

/**
 * A per-minute ceiling for the anonymous endpoints.
 *
 * Both public routes are CSRF-exempt and unauthenticated by necessity — they
 * are posted to from pages that may have been served from a cache PHP never
 * touched, so there is no session and no token to check. That makes a ceiling
 * the only thing standing between the endpoint and somebody with a loop.
 *
 * Keyed on the salted visitor hash rather than an address: there is no IP here
 * to key on, by design (C5).
 *
 * Deliberately best-effort. The read-then-write is not atomic, so under real
 * concurrency the count runs low, and if the cache is unavailable it fails
 * **open** rather than turning a cache outage into a site-wide refusal to
 * record anything. Both are the right way round for analytics: this bounds
 * abuse, it is not an access control.
 */
final class RateLimit
{
    /** How long a counter lives — two windows, so a rollover can't lose one. */
    private const TTL = 120;

    /**
     * Counts one request against a bucket, and says whether it is over.
     *
     * @param string $bucket what is being limited ('beacon', 'consent')
     * @param string $key the visitor hash, or whatever identifies the caller
     * @param int $perMinute the ceiling
     */
    public static function exceeded(string $bucket, string $key, int $perMinute): bool
    {
        $cache = Craft::$app->getCache();

        if ($cache === null) {
            return false;
        }

        $cacheKey = 'ca:rl:' . $bucket . ':' . $key . ':' . floor(time() / 60);
        $count = (int)$cache->get($cacheKey);

        if ($count >= $perMinute) {
            return true;
        }

        $cache->set($cacheKey, $count + 1, self::TTL);

        return false;
    }
}
