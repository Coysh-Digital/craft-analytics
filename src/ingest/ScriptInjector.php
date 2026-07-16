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

        $url = $this->scriptUrl();

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

        return Html::tag('script', '', $attributes);
    }

    /**
     * Publishes tracker.js and returns its URL. Craft fingerprints the
     * published directory, so the file can be cached hard by the browser and
     * still change when we ship a new one.
     */
    private function scriptUrl(): ?string
    {
        $path = Craft::getAlias('@coyshdigital/craftanalytics/resources/js/tracker.js');

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
