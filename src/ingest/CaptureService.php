<?php

namespace coyshdigital\craftanalytics\ingest;

use coyshdigital\craftanalytics\events\TrackEvent;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\services\BotFilter;
use coyshdigital\craftanalytics\services\IdentityService;
use Craft;
use craft\helpers\StringHelper;
use craft\web\Request;
use craft\web\Response;
use yii\base\Component;

/**
 * Server-side pageview capture.
 *
 * Runs from Response::EVENT_AFTER_SEND, after the response bytes are already
 * on the wire and the FPM connection has been closed — so nothing here can
 * add to time-to-first-byte (C1). It still has to be cheap: the worker is
 * held until it returns.
 */
class CaptureService extends Component
{
    /**
     * @event TrackEvent Raised before a hit reaches the write path.
     * Cancellable via `$event->isValid = false`.
     */
    public const EVENT_BEFORE_TRACK = 'beforeTrack';

    public ?Settings $settings = null;
    public ?IdentityService $identity = null;
    public ?BotFilter $bots = null;

    /**
     * Captures the current request, if it is trackable at all.
     */
    public function capture(Request $request, Response $response): ?Hit
    {
        if (!$this->isTrackable($request, $response)) {
            return null;
        }

        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        if ($siteId === null) {
            return null;
        }

        $hit = $this->buildHit($request, $siteId);

        $event = new TrackEvent($hit);
        $this->trigger(self::EVENT_BEFORE_TRACK, $event);

        if (!$event->isValid) {
            return null;
        }

        Plugin::getInstance()->getWriter()->write($event->hit);

        // The nonce is only worth recording now that the pageview has
        // actually been counted: if it hadn't been, the beacon *should* count
        // it. Deliberately after the flush — this is a cache write, and the
        // visitor is not waiting for it (C1).
        $nonce = Plugin::getInstance()->getScriptInjector()->getPendingNonce();

        if ($nonce !== null) {
            Plugin::getInstance()->getNonces()->record($nonce);
        }

        return $event->hit;
    }

    /**
     * Whether this request/response pair represents a real, countable
     * pageview by a human.
     */
    public function isTrackable(Request $request, Response $response): bool
    {
        if ($request->getIsConsoleRequest()) {
            return false;
        }

        if (!$request->getIsSiteRequest() || $request->getIsCpRequest()) {
            return false;
        }

        if (!$request->getIsGet() || $request->getIsActionRequest()) {
            return false;
        }

        // Previews and tokenised requests are the author looking at their own
        // work, not an audience.
        if ($request->getIsPreview() || $request->getIsLivePreview() || $request->getToken() !== null) {
            return false;
        }

        if ($response->getStatusCode() !== 200 || !self::isHtml($response)) {
            return false;
        }

        if ($this->respectsPrivacySignal($request)) {
            return false;
        }

        if ($this->isExcludedPath('/' . $request->getPathInfo())) {
            return false;
        }

        return !$this->bots()->isBot(
            (string)$request->getUserAgent(),
            $this->lowercaseHeaders($request),
        );
    }

    /**
     * Global Privacy Control (and optionally the legacy DNT header) mean this
     * visitor is not tracked beyond the anonymous tier — and since a Tier-1
     * pageview is all we would record anyway, we honour it by not recording at
     * all when the signal is present.
     */
    public function respectsPrivacySignal(Request $request): bool
    {
        $settings = $this->settings();
        $headers = $request->getHeaders();

        if ($settings->honourGpc && (string)$headers->get('sec-gpc') === '1') {
            return true;
        }

        return $settings->honourDnt && (string)$headers->get('dnt') === '1';
    }

    public function isExcludedPath(string $path): bool
    {
        foreach ($this->settings()->excludePaths as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strips excluded query params, keeping the rest in a stable order so the
     * same page doesn't fragment into several path dimensions.
     */
    public function normalizePath(string $pathInfo, string $queryString): string
    {
        $path = '/' . ltrim($pathInfo, '/');
        $excluded = $this->settings()->excludeQueryParams;

        if ($queryString === '') {
            return $path;
        }

        parse_str($queryString, $params);

        foreach ($excluded as $param) {
            unset($params[$param]);
        }

        if ($params === []) {
            return $path;
        }

        ksort($params);

        return $path . '?' . http_build_query($params);
    }

    private function buildHit(Request $request, int $siteId): Hit
    {
        $userAgent = (string)$request->getUserAgent();

        // The address is hashed here and dropped when this method returns.
        // It is never placed in the hit, the spool, or a log (C5).
        $visitorHash = $this->identity()->visitorHash(
            (string)$request->getUserIP(),
            $userAgent,
            $siteId,
        );

        return new Hit(
            siteId: $siteId,
            path: $this->normalizePath($request->getPathInfo(), $request->getQueryString()),
            visitorHash: $visitorHash,
            sessionKey: $this->identity()->sessionKey($visitorHash, $siteId),
            timestamp: time(),
            elementId: self::matchedElementId(),
            referrer: self::externalReferrer((string)$request->getReferrer()),
            userAgent: $userAgent,
            acceptLanguage: (string)$request->getHeaders()->get('accept-language', ''),
        );
    }

    /**
     * The referrer, or '' when it's this site referring to itself — internal
     * navigation is not a traffic source.
     */
    private static function externalReferrer(string $referrer): string
    {
        if ($referrer === '') {
            return '';
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        if (!is_string($host)) {
            return '';
        }

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $baseUrl = $site->getBaseUrl();
            if ($baseUrl !== null && strcasecmp((string)parse_url($baseUrl, PHP_URL_HOST), $host) === 0) {
                return '';
            }
        }

        return $referrer;
    }

    /**
     * The element Craft matched this request to, if any — this is what makes
     * per-entry stats possible without a second lookup.
     */
    private static function matchedElementId(): ?int
    {
        $element = Craft::$app->getUrlManager()->getMatchedElement();

        return $element === false ? null : ($element->id ?? null);
    }

    private static function isHtml(Response $response): bool
    {
        $contentType = (string)$response->getHeaders()->get('content-type', 'text/html');

        return StringHelper::containsAny(strtolower($contentType), ['text/html', 'application/xhtml+xml']);
    }

    /**
     * @return array<string,string>
     */
    private function lowercaseHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower((string)$name)] = (string)($values[0] ?? '');
        }

        return $headers;
    }

    private function settings(): Settings
    {
        return $this->settings ??= Plugin::getInstance()->getSettings();
    }

    private function identity(): IdentityService
    {
        return $this->identity ??= Plugin::getInstance()->getIdentity();
    }

    private function bots(): BotFilter
    {
        return $this->bots ??= Plugin::getInstance()->getBots();
    }
}
