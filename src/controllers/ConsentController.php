<?php

namespace coyshdigital\craftanalytics\controllers;

use coyshdigital\craftanalytics\enums\ConsentMethod;
use coyshdigital\craftanalytics\helpers\RateLimit;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * Where a consent decision is recorded.
 *
 * Posted to by consent.js, whether the decision came from the site calling
 * `craftAnalytics.consent()` directly, from a CMP adapter, or from TCF. The
 * cookie is set here rather than in JavaScript so it can be HttpOnly and
 * signed — a consented visitor ID that page scripts can read or forge is
 * worse than useless.
 *
 * ## Why there is no CSRF token, and what stands in for one
 *
 * The same reason the beacon has none: this is posted to from a page that may
 * have been served by a cache PHP never touched, so there is no session and no
 * token in the markup to check. Requiring one would mean starting a session
 * for every visitor merely to record that they were asked - which is the
 * opposite of what a cookieless plugin should do.
 *
 * That leaves this endpoint forgeable in principle, and unlike the beacon the
 * consequences are not just skewed numbers: a `granted` writes a row into the
 * evidence log, and a `withdrawn` deletes somebody's journeys. So two things
 * stand in for the token:
 *
 * 1. **Same-origin.** Browsers always attach `Origin` to a cross-origin POST,
 *    so a request carrying an origin that is not one of this install's is a
 *    request no page of ours made.
 * 2. **A ceiling**, so the evidence log cannot be filled by a loop.
 *
 * Neither is airtight against a non-browser client, and neither needs to be:
 * the decisions here are keyed on the visitor's own cookie, so the worst a
 * forged request can reach is the data of whoever sent it.
 */
class ConsentController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    public $enableCsrfValidation = false;

    /**
     * Consent decisions per visitor per minute.
     *
     * A visitor makes one or two in a session, and consent.js already drops
     * repeats of the same answer, so this is generous by an order of magnitude
     * and still bounds what an unauthenticated caller can write.
     */
    private const RATE_LIMIT = 20;

    public function actionIndex(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $consent = $plugin->getConsent();

        // Not Pro, or consent switched off: there is nothing to record and no
        // cookie will ever be set.
        if (!$consent->isAvailable()) {
            return $this->noContent();
        }

        if (!$this->isSameOrigin()) {
            return $this->noContent();
        }

        $site = Craft::$app->getSites()->getCurrentSite();

        if ($site->id === null) {
            return $this->noContent();
        }

        $state = (string)$this->request->getBodyParam('state', '');
        $method = ConsentMethod::tryFrom((string)$this->request->getBodyParam('method', ''))
            ?? ConsentMethod::JsApi;

        // The Tier-1 hash lets a denial be logged as evidence without any
        // durable identifier — it stops being linkable when the salt rotates.
        $visitorHash = $plugin->getIdentity()->visitorHash(
            (string)$this->request->getUserIP(),
            (string)$this->request->getUserAgent(),
            $site->id,
        );

        // Counted before the decision is acted on, because the thing being
        // bounded is the write it causes.
        if (RateLimit::exceeded('consent', $visitorHash, self::RATE_LIMIT)) {
            return $this->noContent();
        }

        match ($state) {
            'granted' => $consent->grant($this->request, $site->id, $method, $visitorHash),
            'denied', 'withdrawn' => $consent->deny($this->request, $site->id, $method, $visitorHash),
            // Anything else is not an affirmative act, so it is not consent.
            default => null,
        };

        return $this->noContent();
    }

    /**
     * Whether this post came from a page of this install.
     *
     * Absent `Origin` is allowed through: not every client sends one, and a
     * missing header is not evidence of anything. Present-and-wrong is the
     * case worth refusing, and it is the only one a browser can be made to
     * produce by another site.
     */
    private function isSameOrigin(): bool
    {
        $origin = (string)$this->request->getHeaders()->get('origin', '');

        if ($origin === '') {
            return true;
        }

        $host = parse_url($origin, PHP_URL_HOST);

        if (!is_string($host)) {
            return false;
        }

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $baseUrl = $site->getBaseUrl();

            if ($baseUrl !== null && strcasecmp((string)parse_url($baseUrl, PHP_URL_HOST), $host) === 0) {
                return true;
            }
        }

        // A site whose base URL is relative or unset has no host to compare
        // against, so fall back to the host this request arrived on.
        return parse_url((string)$this->request->getHostInfo(), PHP_URL_HOST) === $host;
    }

    private function noContent(): Response
    {
        $this->response->format = Response::FORMAT_RAW;
        $this->response->content = '';
        $this->response->setStatusCode(204);

        return $this->response;
    }
}
