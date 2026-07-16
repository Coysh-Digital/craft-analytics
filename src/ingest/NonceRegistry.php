<?php

namespace coyshdigital\craftanalytics\ingest;

use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use yii\base\Component;
use yii\caching\CacheInterface;

/**
 * The hybrid-mode deduplication registry.
 *
 * When PHP renders and counts a pageview it issues a nonce, embeds it in the
 * page, and records it here. The beacon for that page later claims the nonce,
 * which tells the endpoint "the server already counted this — take the dwell
 * time only".
 *
 * The point of a registry rather than a flag on the page: a full-page cache
 * bakes the nonce into the stored HTML and serves it to everyone. Only the
 * first claim finds it here; every later visitor of that cached page finds it
 * gone and is counted from the beacon — which is exactly the under-count that
 * server-side tracking suffers behind Blitz, Varnish or Cloudflare. It
 * self-heals with no cache integration at all.
 *
 * Entries live for `nonceTtl`, which therefore has to outlast how long
 * somebody might sit on a page before leaving (see the docs; the default
 * matches the session window).
 */
class NonceRegistry extends Component
{
    private const KEY_PREFIX = 'ca:n:';

    public CacheInterface|null $cache = null;
    public ?Settings $settings = null;

    /**
     * A nonce for one page delivery. Opaque and unguessable, but not a
     * secret: a forged one simply isn't in the registry, and is treated like
     * a cached page.
     */
    public function issue(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Notes that the server has counted the pageview this nonce belongs to.
     */
    public function record(string $nonce): void
    {
        $this->cache()->set(self::KEY_PREFIX . $nonce, 1, $this->settings()->nonceTtl);
    }

    /**
     * Claims a nonce, returning whether it was ours to claim.
     *
     * True means the server counted this pageview and the caller must not
     * count it again. False means either a cached page, a replayed beacon, or
     * a nonce that outlived the registry — all of which are counted.
     *
     * The read-then-delete isn't atomic on every cache backend, so two
     * simultaneous claims of one nonce could both be told "yes" and lose a
     * view. That needs the same nonce beaconed twice in the same instant;
     * losing one view is the right side to err on.
     */
    public function claim(string $nonce): bool
    {
        if ($nonce === '' || !ctype_xdigit($nonce) || strlen($nonce) !== 16) {
            return false;
        }

        $key = self::KEY_PREFIX . $nonce;

        if ($this->cache()->get($key) === false) {
            return false;
        }

        $this->cache()->delete($key);

        return true;
    }

    private function settings(): Settings
    {
        return $this->settings ??= Plugin::getInstance()->getSettings();
    }

    private function cache(): CacheInterface
    {
        return $this->cache ??= Craft::$app->getCache()
            ?? throw new \RuntimeException('No cache component is available for the nonce registry.');
    }
}
