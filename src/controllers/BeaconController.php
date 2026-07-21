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

        $kind = self::sanitizeKind((string)$request->getBodyParam('k', Hit::KIND_VIEW));

        // Pro interactions are gated here as well as in the writer: a Lite
        // site's endpoint simply does not accept them, and neither does a Pro
        // site with events switched off — the setting has to mean the same
        // thing at the endpoint as it does in the tracker, or it only means
        // anything to visitors who aren't trying.
        if ($kind !== Hit::KIND_VIEW
            && (!$plugin->is(Plugin::EDITION_PRO) || !$settings->enableEvents)) {
            return $this->noContent();
        }

        // The engagement beacon reports time on page and scroll depth for a
        // view the load beacon already reported. It must never count a second
        // one, and it never claims a nonce - it carries none.
        $isEngagement = (string)$request->getBodyParam('e', '') === '1';

        // A pageview's nonce decides whether the server already counted it:
        // claiming it means PHP did, so this beacon adds nothing. An event is
        // not a pageview and never claims one either.
        $countView = $kind === Hit::KIND_VIEW
            && !$isEngagement
            && !$plugin->getNonces()->claim((string)$request->getBodyParam('n', ''), $visitorHash);

        $plugin->getWriter()->write(new Hit(
            siteId: $site->id,
            // The beacon sends one string of path-and-query; split it so the
            // same normalisation the server-side path gets is applied here.
            // Otherwise a beacon's dwell time lands on a *different* row from
            // the pageview it belongs to.
            path: $capture->normalizePath(...self::splitPath($path)),
            visitorHash: $visitorHash,
            sessionKey: $plugin->getIdentity()->sessionKey($visitorHash, $site->id),
            timestamp: time(),
            referrer: '',
            userAgent: $userAgent,
            acceptLanguage: (string)$request->getHeaders()->get('accept-language', ''),
            dwellMs: self::sanitizeDwell($request->getBodyParam('d')),
            countView: $countView,
            kind: $kind,
            eventName: self::sanitizeEventName($request->getBodyParam('en')),
            eventValue: self::sanitizeEventValue($request->getBodyParam('ev')),
            target: self::sanitizeTarget($request->getBodyParam('t')),
            scrollBucket: self::sanitizeScroll($request->getBodyParam('s')),
            // Filtered against the site's declarations before it is believed:
            // anyone can post to this route, so a segment the site did not
            // ask for - or did not mark as settable from the browser - is
            // dropped here rather than written.
            segments: $plugin->getSegments()->resolve(
                $request,
                $site->id,
                self::sanitizeSegments($request->getBodyParam('sg')),
            ),
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

    /**
     * Only the kinds we know about; anything else is an ordinary pageview.
     */
    private static function sanitizeKind(string $kind): string
    {
        return in_array($kind, [Hit::KIND_EVENT, Hit::KIND_OUTBOUND, Hit::KIND_DOWNLOAD], true)
            ? $kind
            : Hit::KIND_VIEW;
    }

    private static function sanitizeEventName(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        // Control characters stripped: an event name ends up in a dimension
        // row and on a screen.
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', trim($value)) ?? '';

        return $name === '' ? null : mb_substr($name, 0, 120);
    }

    /**
     * A monetary value. Clamped rather than trusted: the client can say
     * anything, and an event worth 10^20 is not a sale.
     */
    private static function sanitizeEventValue(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $number = (float)$value;

        if (!is_finite($number)) {
            return null;
        }

        return max(-1000000000, min(1000000000, round($number, 2)));
    }

    private static function sanitizeTarget(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $url = parse_url($value);

        // Must be a real http(s) URL — not a javascript: or data: payload.
        if (!is_array($url) || !in_array($url['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }

        return mb_substr($value, 0, 500);
    }

    /** The four buckets, or nothing. */
    private static function sanitizeScroll(mixed $value): ?int
    {
        $bucket = is_numeric($value) ? (int)$value : 0;

        return in_array($bucket, [25, 50, 75, 100], true) ? $bucket : null;
    }

    /**
     * The segments a browser claimed, as a shape the registry can look at.
     *
     * Only the shape is checked here — whether any of it is *allowed* is the
     * registry's business. The length cap is a parser guard: this is an
     * unauthenticated endpoint, and json_decode on a megabyte of nested
     * brackets is work somebody else chose for us.
     *
     * @return array<string,mixed>
     */
    private static function sanitizeSegments(mixed $value): array
    {
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_SEGMENTS_BYTES) {
            return [];
        }

        $decoded = json_decode($value, true, 3);

        return is_array($decoded) ? $decoded : [];
    }

    /** Comfortably more than five short key/value pairs, and no more. */
    private const MAX_SEGMENTS_BYTES = 1024;

    /**
     * Splits "/page?a=1" into ["/page", "a=1"], matching what the server-side
     * request hands to normalizePath().
     *
     * @return array{0: string, 1: string}
     */
    private static function splitPath(string $path): array
    {
        $parts = explode('?', $path, 2);

        return [$parts[0], $parts[1] ?? ''];
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
