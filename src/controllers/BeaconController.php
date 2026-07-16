<?php

namespace coyshdigital\craftanalytics\controllers;

use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * The beacon endpoint.
 *
 * Anonymous and CSRF-exempt by necessity: it is posted to by a script on a
 * page that may have been served from a cache PHP never touched, so there is
 * no session and no token to validate. That makes it forgeable — anyone can
 * curl fabricated pageviews at it. Every analytics endpoint that works behind
 * a cache has this property; the mitigations here are the bot filter, a
 * per-visitor rate limit, and validating everything before it is believed.
 * The blast radius is skewed numbers, never stored personal data: the same
 * privacy rules apply to this path as to server-side capture (C5, C6).
 *
 * Returns 204 for everything, including requests it ignores. A tracker that
 * reports back which requests were rejected is a tracker that tells a
 * fingerprinter what it wants to know.
 */
class BeaconController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    public $enableCsrfValidation = false;

    /** No page is plausibly read for longer than this; anything more is junk. */
    private const MAX_DWELL_MS = 3600000;

    public function actionIndex(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $request = $this->request;

        // In server-only mode there is no beacon; ignore anything that turns
        // up at this path.
        if ($settings->trackingMode === Settings::TRACKING_MODE_SERVER) {
            return $this->noContent();
        }

        $capture = $plugin->getCapture();

        // The same gates as server-side capture, applied again here — this
        // path must never become the way to track someone the other path
        // would have refused.
        if ($capture->respectsPrivacySignal($request)) {
            return $this->noContent();
        }

        $userAgent = (string)$request->getUserAgent();

        if ($plugin->getBots()->isBot($userAgent, $this->lowercaseHeaders())) {
            return $this->noContent();
        }

        $path = self::sanitizePath((string)$request->getBodyParam('p', ''));

        if ($path === null || $capture->isExcludedPath($path)) {
            return $this->noContent();
        }

        $site = Craft::$app->getSites()->getCurrentSite();

        if ($site->id === null) {
            return $this->noContent();
        }

        // Hashed here and dropped with this call frame, exactly as in
        // server-side capture. No address reaches the spool (C5).
        $visitorHash = $plugin->getIdentity()->visitorHash(
            (string)$request->getUserIP(),
            $userAgent,
            $site->id,
        );

        if ($this->isRateLimited($visitorHash, $settings)) {
            return $this->noContent();
        }

        // If this nonce is ours, PHP already counted this pageview and the
        // beacon is only here to report how long they stayed. If it isn't,
        // the page came from a cache (or bfcache) and this beacon is the only
        // record of the view.
        $countView = !$plugin->getNonces()->claim((string)$request->getBodyParam('n', ''));

        $plugin->getWriter()->write(new Hit(
            siteId: $site->id,
            path: $capture->normalizePath($path, ''),
            visitorHash: $visitorHash,
            sessionKey: $plugin->getIdentity()->sessionKey($visitorHash, $site->id),
            timestamp: time(),
            referrer: '',
            userAgent: $userAgent,
            acceptLanguage: (string)$request->getHeaders()->get('accept-language', ''),
            dwellMs: self::sanitizeDwell($request->getBodyParam('d')),
            countView: $countView,
        ));

        return $this->noContent();
    }

    /**
     * Counts beacons per visitor per minute.
     *
     * Keyed on the salted visitor hash, not on an address — there is no IP
     * here to key on, by design.
     */
    private function isRateLimited(string $visitorHash, Settings $settings): bool
    {
        $cache = Craft::$app->getCache();

        if ($cache === null) {
            return false;
        }

        $key = 'ca:rl:' . $visitorHash . ':' . floor(time() / 60);
        $count = (int)$cache->get($key);

        if ($count >= $settings->beaconRateLimit) {
            return true;
        }

        $cache->set($key, $count + 1, 120);

        return false;
    }

    /**
     * Nothing from the beacon is trusted: the path has to look like a path
     * this site could actually serve.
     */
    private static function sanitizePath(string $path): ?string
    {
        if ($path === '' || strlen($path) > 1024 || !str_starts_with($path, '/')) {
            return null;
        }

        if (str_contains($path, "\n") || str_contains($path, "\r") || str_contains($path, '://')) {
            return null;
        }

        return $path;
    }

    private static function sanitizeDwell(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        return max(0, min(self::MAX_DWELL_MS, (int)$value));
    }

    /**
     * @return array<string,string>
     */
    private function lowercaseHeaders(): array
    {
        $headers = [];

        foreach ($this->request->getHeaders() as $name => $values) {
            $headers[strtolower((string)$name)] = (string)($values[0] ?? '');
        }

        return $headers;
    }

    private function noContent(): Response
    {
        $this->response->format = Response::FORMAT_RAW;
        $this->response->content = '';
        $this->response->setStatusCode(204);

        return $this->response;
    }
}
