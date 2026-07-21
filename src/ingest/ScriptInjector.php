<?php

namespace coyshdigital\craftanalytics\ingest;

use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\web\Request as WebRequest;
use yii\base\Component;

/**
 * Puts the tracker on the page.
 *
 * The script is published from the plugin's own resources and served from the
 * site's own domain — never a CDN (C7). It is deferred, has no dependencies,
 * and renders nothing, so it cannot move the page or delay it.
 *
 * The nonce is minted here, while the HTML is being built, and recorded in
 * the registry after the response has been flushed — so the pre-flush cost is
 * a few random bytes and a string, and the cache write happens on the
 * plugin's own time (C1).
 */
class ScriptInjector extends Component
{
    public ?Settings $settings = null;
    public ?NonceRegistry $nonces = null;

    /**
     * The nonce issued for this request, if any. Read by CaptureService after
     * the flush: a nonce is only worth recording if the server actually
     * counted the pageview it belongs to.
     */
    private ?string $pendingNonce = null;

    public function getPendingNonce(): ?string
    {
        return $this->pendingNonce;
    }

    /**
     * The `<script>` tag for this request, or null if the page shouldn't
     * carry one.
     */
    public function tag(): ?string
    {
        $settings = $this->settings();

        if (!$settings->injectScript || $settings->trackingMode === Settings::TRACKING_MODE_SERVER) {
            return null;
        }

        $request = Craft::$app->getRequest();

        if (!$request instanceof WebRequest) {
            return null;
        }

        if (!$request->getIsSiteRequest() || $request->getIsCpRequest() || $request->getIsActionRequest()) {
            return null;
        }

        // A page we would never count doesn't need a beacon that would be
        // ignored on arrival.
        if (Plugin::getInstance()->getCapture()->isExcludedPath('/' . $request->getPathInfo())) {
            return null;
        }

        $url = $this->publishedUrl('tracker.js');

        if ($url === null) {
            return null;
        }

        $attributes = [
            'src' => $url,
            'defer' => true,
            'data-endpoint' => UrlHelper::siteUrl($settings->beaconPath),
        ];

        // Client mode has no server-side count to deduplicate against, so
        // there is no nonce and every beacon is a pageview.
        if ($settings->trackingMode === Settings::TRACKING_MODE_HYBRID) {
            $attributes['data-nonce'] = $this->pendingNonce = $this->nonces()->issue();
        }

        $tag = Html::tag('script', '', $attributes);

        // Only shipped when consented tracking is actually switched on, so a
        // Lite site — or a Pro site that stays cookieless — downloads nothing
        // for a feature it doesn't use.
        return $tag . ($this->consentTag() ?? '') . ($this->proTag() ?? '');
    }

    /**
     * The Pro tracker: events, outbound clicks, downloads, scroll depth and
     * segments.
     *
     * Separate from tracker.js so a Lite site downloads nothing for features
     * it does not have (C3) — which is also why segments live in here rather
     * than in the tracker every visitor to every site gets.
     *
     * A site that declared segments but left `enableEvents` off still gets
     * this file, with everything else in it switched off at the attributes.
     */
    private function proTag(): ?string
    {
        $settings = $this->settings();
        $plugin = Plugin::getInstance();

        if (!$plugin->is(Plugin::EDITION_PRO)) {
            return null;
        }

        $events = $settings->enableEvents;

        if (!$events && !$plugin->getSegments()->isEnabled()) {
            return null;
        }

        $url = $this->publishedUrl('pro.js');

        if ($url === null) {
            return null;
        }

        return Html::tag('script', '', [
            'src' => $url,
            'defer' => true,
            'data-endpoint' => UrlHelper::siteUrl($settings->beaconPath),
            'data-events' => $events ? '1' : '0',
            // `enableEvents` is the master switch for all of these, so an
            // install that has it off gets the file for its segments and
            // nothing else — the same as before this file could be loaded
            // for any other reason.
            'data-outbound' => $events && $settings->trackOutbound ? '1' : '0',
            'data-downloads' => $events && $settings->trackDownloads ? '1' : '0',
            'data-scroll' => $events && $settings->trackScroll ? '1' : '0',
            'data-extensions' => implode(',', $settings->downloadExtensions),
        ]);
    }

    /**
     * The consent API script, when there is any consent to manage.
     */
    private function consentTag(): ?string
    {
        if (!Plugin::getInstance()->getConsent()->isAvailable()) {
            return null;
        }

        $url = $this->publishedUrl('consent.js');

        if ($url === null) {
            return null;
        }

        return Html::tag('script', '', [
            'src' => $url,
            'defer' => true,
            'data-consent-endpoint' => UrlHelper::siteUrl($this->settings()->consentPath),
        ]);
    }

    /**
     * Publishes one of our scripts and returns its URL. Craft fingerprints
     * the published directory, so the file can be cached hard by the browser
     * and still change when we ship a new one.
     */
    private function publishedUrl(string $filename): ?string
    {
        $path = Craft::getAlias('@coyshdigital/craftanalytics/resources/js/' . $filename);

        if (!is_string($path) || !is_file($path)) {
            return null;
        }

        $url = Craft::$app->getAssetManager()->getPublishedUrl($path, true);

        return $url === false ? null : $url;
    }

    private function settings(): Settings
    {
        return $this->settings ??= Plugin::getInstance()->getSettings();
    }

    private function nonces(): NonceRegistry
    {
        return $this->nonces ??= Plugin::getInstance()->getNonces();
    }
}
