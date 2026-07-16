<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\enums\ConsentMethod;
use coyshdigital\craftanalytics\enums\ConsentState;
use coyshdigital\craftanalytics\events\DefineConsentEvent;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use craft\web\Request;
use craft\web\Response as WebResponse;
use yii\base\Component;
use yii\db\Connection;
use yii\web\Cookie;

/**
 * The consent state machine.
 *
 * The rules, in order of who wins:
 *
 * 1. **Consent must be on.** If `enableConsent` is off — the default — this
 *    service says Denied to everything and never sets a cookie. The plugin is
 *    cookieless, full stop.
 * 2. **Pro only.** Tier-2 is a paid feature, gated here rather than in the UI,
 *    so no route or event can smuggle past it.
 * 3. **A browser privacy signal is absolute.** GPC (and optionally DNT) means
 *    Denied, and nothing — not a CMP, not the site's own event handler — can
 *    override it. A signal you can overrule is not a signal.
 * 4. Otherwise: our own consent cookie, then the site's CMP cookie, then the
 *    `defineConsent` event, which sees the resolved state and may change it.
 *
 * Absence is never consent. Unknown means Tier-1, exactly like a refusal.
 */
class ConsentService extends Component
{
    /**
     * @event DefineConsentEvent Resolve consent from the site's own state.
     */
    public const EVENT_DEFINE_CONSENT = 'defineConsent';

    /** What the consent covers, recorded as evidence. */
    public const SCOPE = 'analytics';

    private const VISITOR_ID_BYTES = 16; // 128 bits

    public ?Settings $settings = null;
    public ?Connection $db = null;

    /** Edition override; defaults to the plugin's own. Set in tests. */
    public ?bool $isPro = null;

    /**
     * Whether consented tracking is available at all on this install.
     */
    public function isAvailable(): bool
    {
        return $this->settings()->enableConsent && $this->isPro();
    }

    private function isPro(): bool
    {
        return $this->isPro ??= Plugin::getInstance()->is(Plugin::EDITION_PRO);
    }

    /**
     * The consent state for this request.
     */
    public function resolve(Request $request, int $siteId): ConsentState
    {
        if (!$this->isAvailable()) {
            return ConsentState::Denied;
        }

        // A browser-level refusal ends the conversation. Deliberately checked
        // before anything else and not passed to the event: honouring GPC
        // only when nobody objects would be theatre.
        if ($this->hasPrivacySignal($request)) {
            return ConsentState::Denied;
        }

        [$state, $method] = $this->fromCookies($request);

        $event = new DefineConsentEvent($state, $method, $siteId);
        $this->trigger(self::EVENT_DEFINE_CONSENT, $event);

        return $event->state;
    }

    /**
     * The consented visitor's ID for this request, or null when they are not
     * a consented visitor.
     *
     * This is the only stable, cross-session identifier the plugin ever
     * holds, and it exists only because somebody said yes.
     */
    public function visitorId(Request $request): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $value = $request->getCookies()->getValue($this->settings()->consentCookieName);

        return is_string($value) && self::isValidVisitorId($value) ? $value : null;
    }

    /**
     * Records an affirmative grant: issues the visitor ID, sets the cookie,
     * and writes the evidence.
     *
     * @return string|null the visitor ID, or null if consent isn't available
     */
    public function grant(Request $request, int $siteId, ConsentMethod $method, ?string $visitorHash = null): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        // GPC outranks an affirmative click: if the browser says no, a banner
        // saying yes is a contradiction we resolve in the visitor's favour.
        if ($this->hasPrivacySignal($request)) {
            return null;
        }

        $visitorId = $this->visitorId($request) ?? bin2hex(random_bytes(self::VISITOR_ID_BYTES));

        $response = Craft::$app->getResponse();

        if (!$response instanceof WebResponse) {
            return null;
        }

        $response->getCookies()->add($this->cookie($request, $visitorId));
        $this->log($siteId, ConsentState::Granted, $method, $visitorId, $visitorHash);

        return $visitorId;
    }

    /**
     * Records a refusal or withdrawal: deletes the cookie immediately, writes
     * the evidence, and hands the visitor back to Tier-1.
     *
     * The consented rows are purged separately by GC within the configured
     * window (see PrivacyService::erase() for the immediate path).
     */
    public function deny(Request $request, int $siteId, ConsentMethod $method, ?string $visitorHash = null): void
    {
        if (!$this->settings()->enableConsent) {
            return;
        }

        $visitorId = $this->visitorId($request);
        $response = Craft::$app->getResponse();

        // Gone from the device before anything else happens.
        if ($response instanceof WebResponse) {
            $response->getCookies()->remove($this->settings()->consentCookieName);
        }

        $this->log($siteId, ConsentState::Denied, $method, $visitorId, $visitorHash);

        if ($visitorId !== null) {
            // Withdrawal means the data goes too, not just the cookie.
            Plugin::getInstance()->getPrivacy()->erase(visitorId: $visitorId);
        }
    }

    /**
     * Writes one row of consent evidence.
     *
     * Pseudonymous by construction: a visitor id or a rotating hash, and
     * nothing that names anybody.
     */
    public function log(
        int $siteId,
        ConsentState $state,
        ConsentMethod $method,
        ?string $visitorId = null,
        ?string $visitorHash = null,
    ): void {
        $this->db()->createCommand()->insert(Table::CONSENT_LOG, [
            'siteId' => $siteId,
            'visitorId' => $visitorId,
            'visitorHash' => $visitorHash,
            'state' => $state->value,
            'method' => $method->value,
            'scope' => self::SCOPE,
            'policyVersion' => $this->settings()->policyVersion,
            'recordedAt' => gmdate('Y-m-d H:i:s'),
        ])->execute();
    }

    /**
     * Whether the browser has asked not to be tracked.
     */
    public function hasPrivacySignal(Request $request): bool
    {
        $settings = $this->settings();
        $headers = $request->getHeaders();

        if ($settings->honourGpc && (string)$headers->get('sec-gpc') === '1') {
            return true;
        }

        return $settings->honourDnt && (string)$headers->get('dnt') === '1';
    }

    /**
     * @return array{0: ConsentState, 1: ConsentMethod}
     */
    private function fromCookies(Request $request): array
    {
        // Our own cookie is the record of an affirmative act — via the JS
        // API, a CMP adapter or TCF, all of which funnel through grant().
        if ($this->visitorId($request) !== null) {
            return [ConsentState::Granted, ConsentMethod::JsApi];
        }

        $cmpCookie = $this->settings()->cmpCookieName;

        if ($cmpCookie !== null && $cmpCookie !== '') {
            $value = $request->getCookies()->getValue($cmpCookie);

            if (is_string($value)) {
                $granted = in_array(
                    strtolower(trim($value)),
                    array_map(strtolower(...), $this->settings()->cmpCookieGrantedValues),
                    true,
                );

                // A CMP cookie that says anything other than yes is a no, not
                // an unknown: the visitor has been asked.
                return [$granted ? ConsentState::Granted : ConsentState::Denied, ConsentMethod::CmpCookie];
            }
        }

        return [ConsentState::Unknown, ConsentMethod::CmpCookie];
    }

    private function cookie(Request $request, string $visitorId): Cookie
    {
        $settings = $this->settings();

        return new Cookie([
            'name' => $settings->consentCookieName,
            'value' => $visitorId,
            // HttpOnly always, not "where possible": sendBeacon sends cookies
            // itself, so nothing of ours needs to read this from JavaScript —
            // and neither does anything else.
            'httpOnly' => true,
            'secure' => $request->getIsSecureConnection(),
            'sameSite' => Cookie::SAME_SITE_LAX,
            'expire' => time() + min($settings->consentCookieDuration, Settings::CONSENT_COOKIE_MAX_DURATION),
            'path' => '/',
        ]);
    }

    private static function isValidVisitorId(string $value): bool
    {
        return strlen($value) === self::VISITOR_ID_BYTES * 2 && ctype_xdigit($value);
    }

    private function settings(): Settings
    {
        return $this->settings ??= Plugin::getInstance()->getSettings();
    }

    private function db(): Connection
    {
        return $this->db ??= Craft::$app->getDb();
    }
}
