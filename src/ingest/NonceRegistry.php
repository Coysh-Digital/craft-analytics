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
 * When PHP renders a page it counts the view, issues a nonce, embeds it in the
 * page, and records here that *this visitor's* view is already counted. That
 * visitor's beacon then finds the record and contributes only its dwell time.
 * Every other beacon carrying that nonce finds nothing and is counted, which
 * is what makes a cached page work with no cache integration at all.
 *
 * **The record is keyed on the nonce and the visitor, not on the nonce alone.**
 * A full-page cache bakes the nonce into the stored HTML and serves it to
 * everybody, so keyed on the nonce alone the first visitor to claim it is
 * mistaken for the one PHP rendered for, and their own view is swallowed.
 * Where the cache was warmed by something without JavaScript - a curl, a
 * cache-warming crawler - nobody ever claims the nonce and the first real
 * visitor is swallowed instead. Keyed on both, only the visitor PHP actually
 * rendered for can claim it, and two visitors sharing a cached page cannot
 * interfere with each other.
 *
 * Entries live for `nonceTtl`, which has to outlast the gap between a page
 * being rendered and its beacon arriving. The beacon goes on load, so that gap
 * is milliseconds and the default is generous.
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
     * Notes that the server has counted this visitor's view of this page.
     */
    public function record(string $nonce, string $visitorHash): void
    {
        $this->cache()->set(self::key($nonce, $visitorHash), 1, $this->settings()->nonceTtl);
    }

    /**
     * Claims a nonce for a visitor, returning whether it was theirs to claim.
     *
     * True means the server already counted this visitor's view and the caller
     * must not count it again. False means a cached page, a replayed beacon,
     * or a nonce that outlived the registry — all of which are counted.
     *
     * The read-then-delete isn't atomic on every cache backend, so one visitor
     * beaconing the same nonce twice in the same instant could be told "yes"
     * twice and lose a view. Losing one view is the right side to err on.
     */
    public function claim(string $nonce, string $visitorHash): bool
    {
        if ($nonce === '' || !ctype_xdigit($nonce) || strlen($nonce) !== 16) {
            return false;
        }

        $key = self::key($nonce, $visitorHash);

        if ($this->cache()->get($key) === false) {
            return false;
        }

        $this->cache()->delete($key);

        return true;
    }

    private static function key(string $nonce, string $visitorHash): string
    {
        return self::KEY_PREFIX . $nonce . ':' . $visitorHash;
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
