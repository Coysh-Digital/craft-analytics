<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\Plugin;
use yii\base\Component;

/**
 * Anonymous (Tier-1) visitor identity.
 *
 * The IP address is an argument here and nothing else: it is hashed with the
 * current rotating salt and discarded with the call frame. It is never
 * returned, stored, logged, or used as a persistent cache key (C5).
 */
class IdentityService extends Component
{
    /** Truncation width of the stored visitor hash, in bytes. */
    public const HASH_BYTES = 8;

    public ?SaltService $salts = null;

    /**
     * Pseudonymous visitor hash for the current rotation window.
     *
     * Truncated to 8 bytes: enough to keep collisions negligible at realistic
     * daily visitor counts, small enough to keep sketches and membership rows
     * cheap, and short enough to be useless as a re-identifier once the salt
     * that produced it is destroyed.
     */
    public function visitorHash(string $ip, string $userAgent, int $siteId): string
    {
        $digest = hash(
            'sha256',
            $this->salts()->getCurrentSalt() . '|' . $ip . '|' . $userAgent . '|' . $siteId,
            true,
        );

        return bin2hex(substr($digest, 0, self::HASH_BYTES));
    }

    /**
     * Whether a value is a visitor hash this build can actually use.
     *
     * The single statement of the format. Everything downstream — the spool
     * reader, the rollup sink, the sketch — asks this rather than restating
     * the rule, so a change to HASH_BYTES moves the whole pipeline at once
     * instead of leaving a mismatch to detonate deep inside a commit.
     *
     * Values written by a build with a different HASH_BYTES are exactly the
     * case this guards: they are unusable here, and must be dropped where
     * dropping is cheap.
     */
    public static function isValidHash(string $hash): bool
    {
        return strlen($hash) === self::HASH_BYTES * 2 && ctype_xdigit($hash);
    }

    /**
     * Session key for a visitor hash. Sessions live only in the hot layer and
     * are never written to a visitor's device (C4).
     */
    public function sessionKey(string $visitorHash, int $siteId): string
    {
        return substr(hash('sha256', $visitorHash . '|' . $siteId), 0, 32);
    }

    private function salts(): SaltService
    {
        return $this->salts ??= Plugin::getInstance()->getSalts();
    }
}
