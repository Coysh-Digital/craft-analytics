<?php

namespace coyshdigital\craftanalytics\controllers;

use coyshdigital\craftanalytics\enums\ConsentMethod;
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
 */
class ConsentController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    public $enableCsrfValidation = false;

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

        match ($state) {
            'granted' => $consent->grant($this->request, $site->id, $method, $visitorHash),
            'denied', 'withdrawn' => $consent->deny($this->request, $site->id, $method, $visitorHash),
            // Anything else is not an affirmative act, so it is not consent.
            default => null,
        };

        return $this->noContent();
    }

    private function noContent(): Response
    {
        $this->response->format = Response::FORMAT_RAW;
        $this->response->content = '';
        $this->response->setStatusCode(204);

        return $this->response;
    }
}
