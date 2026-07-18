<?php

namespace coyshdigital\craftanalytics\ingest;

use coyshdigital\craftanalytics\events\TrackEvent;
use coyshdigital\craftanalytics\models\Campaign;
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

    /**
     * Query parameters always stripped from tracked paths, on top of the
     * campaign parameters. None of these carry page identity: they are
     * ad-network click and analytics artefacts, and Craft's own preview token.
     * Leaving them on would split one page into a row per variant.
     */
    public const NOISE_QUERY_PARAMS = [
        // Google Ads and Analytics (the click IDs live in Campaign::PARAMS).
        'gad_source', 'gad_campaignid', 'gclsrc', 'srsltid',
        '_gl', '_ga', '_gac', '_gid',
        // Craft's tokenised routes (live preview, share links).
        'token',
    ];

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

        // A nonce is issued while the page is being rendered, so its absence
        // means this page was *not* rendered by PHP on this request - it came
        // out of a full-page cache. Blitz's default setup serves its cache
        // from inside PHP (it calls send() and exits), which fires the same
        // event a real render does, so without this check the view is counted
        // twice: once here, and once by the beacon, whose nonce was baked into
        // the cached HTML and claimed long ago by whoever generated it.
        //
        // Cached pages are left to the beacon, which is what already happens
        // when a cache is served by nginx and PHP never runs at all. Doing the
        // same in both cases means the numbers don't depend on how the cache
        // happens to be wired up.
        $nonce = Plugin::getInstance()->getScriptInjector()->getPendingNonce();

        if ($nonce === null && $this->isCachedDelivery()) {
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
        if ($nonce !== null) {
            Plugin::getInstance()->getNonces()->record($nonce, $event->hit->visitorHash);
        }

        return $event->hit;
    }

    /**
     * Whether this response came out of a full-page cache rather than being
     * rendered now.
     *
     * Only meaningful when the tracker is injected by us and the beacon is
     * available to pick the page up: with `injectScript` off there is no
     * nonce to be missing, and in server-only mode there is no beacon to hand
     * the pageview to, so in both cases the server has to count it itself -
     * and a site running a cache in server-only mode is under-counting
     * anyway, which the settings screen says plainly.
     */
    private function isCachedDelivery(): bool
    {
        $settings = $this->settings();

        return $settings->injectScript
            && $settings->trackingMode === Settings::TRACKING_MODE_HYBRID;
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

        if ($this->isCrawler($request)) {
            // Crawlers are never mixed into the reports (`blockCrawlers`).
            // Turning that off does not turn the *detection* off - it stops
            // the detection excluding anything, which is occasionally useful
            // for debugging and a bad idea the rest of the time.
            return !$this->settings()->blockCrawlers;
        }

        return true;
    }

    /**
     * Whether this request is a crawler rather than a person.
     */
    public function isCrawler(Request $request): bool
    {
        return $this->bots()->isBot(
            (string)$request->getUserAgent(),
            $this->lowercaseHeaders($request),
        );
    }

    /**
     * Counts a crawler request that was kept out of the reports.
     *
     * Recorded so that "my server logs say 4,000 requests and this says 400"
     * has an answer on the screen instead of costing somebody an afternoon.
     * It rides the same spool and drain as everything else, so it is one
     * append post-flush and one rollup row per crawler per day - never a row
     * per request.
     */
    public function captureCrawler(Request $request, Response $response): bool
    {
        $settings = $this->settings();

        if (!$settings->trackCrawlers || !$settings->blockCrawlers) {
            return false;
        }

        // Only real page requests, judged by the same rules a human's would
        // be, minus the bot check itself.
        if (!$this->isPageRequest($request, $response) || !$this->isCrawler($request)) {
            return false;
        }

        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        if ($siteId === null) {
            return false;
        }

        $name = $this->bots()->crawlerName((string)$request->getUserAgent());

        Plugin::getInstance()->getWriter()->write(new Hit(
            siteId: $siteId,
            path: $this->normalizePath($request->getPathInfo(), $request->getQueryString()),
            // A crawler has no visitor hash and gets none: it is not a person,
            // so there is nothing to pseudonymise. The name is all that is
            // kept, and the address is never touched (C5).
            visitorHash: self::CRAWLER_HASH,
            sessionKey: self::CRAWLER_HASH,
            timestamp: time(),
            countView: false,
            kind: Hit::KIND_CRAWLER,
            eventName: $name,
        ));

        return true;
    }

    /**
     * A placeholder where a visitor hash would go.
     *
     * The drain rejects hits with an empty visitorHash as malformed, and a
     * crawler has no visitor to hash - so it gets a constant that is
     * obviously not a hash of anything.
     */
    public const CRAWLER_HASH = 'crawler000000000';

    /**
     * The request/response checks that apply to anyone, crawler or not.
     */
    private function isPageRequest(Request $request, Response $response): bool
    {
        return !$request->getIsConsoleRequest()
            && $request->getIsSiteRequest()
            && !$request->getIsCpRequest()
            && $request->getIsGet()
            && !$request->getIsActionRequest()
            && !$request->getIsPreview()
            && !$request->getIsLivePreview()
            && $request->getToken() === null
            && $response->getStatusCode() === 200
            && self::isHtml($response)
            && !$this->isExcludedPath('/' . $request->getPathInfo());
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
     *
     * Campaign and ad-network parameters are always stripped. They describe how
     * somebody arrived, not which page they arrived at - and campaigns are
     * captured into their own dimensions separately. Leaving them on the path
     * would split one page into a row per campaign and inflate path cardinality
     * for data already recorded elsewhere (C2).
     *
     * With `stripQueryString` on, the query is dropped entirely, so even
     * site-specific parameters collapse onto the one clean path. Attribution is
     * unaffected: it reads the raw query string before this runs.
     */
    public function normalizePath(string $pathInfo, string $queryString): string
    {
        $path = '/' . ltrim($pathInfo, '/');

        if ($queryString === '' || $this->settings()->stripQueryString) {
            return $path;
        }

        parse_str($queryString, $params);

        // A URL that was HTML-entity-encoded before being linked - sometimes
        // more than once - arrives with its separators as `&amp;` rather than
        // `&`, so parse_str reads the parameter after each one as
        // `amp;utm_source`, `amp;amp;cid` and so on. Strip those leading `amp;`
        // runs back off, so the real parameter beneath is recognised - and
        // excluded - like any other, instead of fragmenting the path.
        $decoded = [];
        foreach ($params as $key => $value) {
            $decoded[preg_replace('/^(?:amp;)+/', '', (string)$key)] = $value;
        }
        $params = $decoded;

        $excluded = array_merge(
            $this->settings()->excludeQueryParams,
            Campaign::PARAMS,
            self::NOISE_QUERY_PARAMS,
        );

        foreach ($excluded as $param) {
            unset($params[$param]);
        }

        if ($params === []) {
            return $path;
        }

        ksort($params);

        return $path . '?' . http_build_query($params);
    }

    /**
     * Records an event from server-side code — a Formie submission, a
     * Commerce order, or anything a site's own module wants counted.
     *
     * This is the one entry point that does not require a trackable *page*
     * request: the interesting events (a form posting, an order completing)
     * happen on POSTs and redirects, which are deliberately never pageviews.
     * The privacy rules are unchanged — the address is hashed in memory and
     * dropped, and nothing per-visitor is stored (C5, C6).
     *
     * Returns false when nothing was recorded, which is the normal answer on
     * Lite, with events disabled, or for a visitor sending GPC.
     */
    public function trackEvent(
        string $name,
        ?float $value = null,
        ?string $path = null,
        ?int $elementId = null,
    ): bool {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !$request instanceof Request) {
            return false;
        }

        $settings = $this->settings();

        if (!Plugin::getInstance()->is(Plugin::EDITION_PRO) || !$settings->enableEvents) {
            return false;
        }

        // A visitor who has asked not to be tracked is not tracked, whatever
        // the site's own code asks for.
        if ($this->respectsPrivacySignal($request)) {
            return false;
        }

        if ($this->bots()->isBot((string)$request->getUserAgent(), $this->lowercaseHeaders($request))) {
            return false;
        }

        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        if ($siteId === null) {
            return false;
        }

        $hit = $this->buildHit($request, $siteId);

        $hit = new Hit(
            siteId: $hit->siteId,
            // The page the visitor was on, not the action URL they posted to.
            path: $path !== null ? $this->normalizePath(...self::splitPath($path)) : $hit->path,
            visitorHash: $hit->visitorHash,
            sessionKey: $hit->sessionKey,
            timestamp: $hit->timestamp,
            elementId: $elementId ?? $hit->elementId,
            referrer: $hit->referrer,
            userAgent: $hit->userAgent,
            acceptLanguage: $hit->acceptLanguage,
            // An event is not a pageview and must never be counted as one.
            countView: false,
            visitorId: $hit->visitorId,
            userId: $hit->userId,
            campaign: $hit->campaign,
            countryCode: $hit->countryCode,
            region: $hit->region,
            kind: Hit::KIND_EVENT,
            eventName: $name,
            eventValue: $value,
        );

        $event = new TrackEvent($hit);
        $this->trigger(self::EVENT_BEFORE_TRACK, $event);

        if (!$event->isValid) {
            return false;
        }

        Plugin::getInstance()->getWriter()->write($event->hit);

        return true;
    }

    /**
     * Splits a stored path-and-query so it gets the same normalisation a real
     * request's would — otherwise an event's path lands on a different row
     * from the pageview it belongs to.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitPath(string $path): array
    {
        $parts = explode('?', ltrim($path, '/'), 2);

        return [$parts[0], $parts[1] ?? ''];
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

        // Tier-2, and only for a visitor who affirmatively agreed. For
        // everyone else — the overwhelming majority, by design — this is null
        // and the hit is as anonymous as it was in Lite.
        $consent = Plugin::getInstance()->getConsent();
        $visitorId = $consent->resolve($request, $siteId)->isGranted()
            ? $consent->visitorId($request)
            : null;

        $plugin = Plugin::getInstance();

        // Resolved here from the in-memory address, which is then gone. Only
        // the country and region travel any further (C5).
        $geo = $plugin->getGeo()->resolve((string)$request->getUserIP()) ?? ['country' => '', 'region' => ''];

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
            visitorId: $visitorId,
            userId: $visitorId !== null ? self::signedInUserId() : null,
            campaign: $this->settings()->enableCampaigns
                ? Campaign::fromQueryString($request->getQueryString())
                : null,
            countryCode: $geo['country'],
            region: $geo['region'],
        );
    }

    /**
     * The signed-in user, for the consented layer.
     *
     * Only ever attached to a consented hit, and only stored when
     * `associateUserId` is separately enabled (see JourneyRecorder) — being
     * measured and being named are two different agreements.
     */
    private static function signedInUserId(): ?int
    {
        if (!Plugin::getInstance()->getSettings()->associateUserId) {
            return null;
        }

        $userId = Craft::$app->getUser()->getId();

        return $userId === null ? null : (int)$userId;
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
