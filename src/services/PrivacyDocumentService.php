<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use yii\base\Component;

/**
 * Generates the compliance paperwork this plugin's processing implies.
 *
 * These are drafts built from the live configuration, not legal advice, and
 * they say so. Their value is that they are *accurate about what the software
 * does* — the part a lawyer cannot know and usually has to guess at.
 */
class PrivacyDocumentService extends Component
{
    public ?Settings $settings = null;

    /** Edition override; defaults to the plugin's own. Set in tests. */
    public ?bool $isPro = null;

    /**
     * @return array<string,string> filename => markdown
     */
    public function all(): array
    {
        return [
            'ropa.md' => $this->ropa(),
            'privacy-notice-appendix.md' => $this->privacyNotice(),
            'dpia-summary.md' => $this->dpiaSummary(),
        ];
    }

    /**
     * A Record of Processing Activities entry (UK/EU GDPR Art. 30).
     */
    public function ropa(): string
    {
        $s = $this->settings();
        $consent = $this->consentEnabled();
        $systemName = Craft::$app->getSystemName();

        $rows = [
            ['Processing activity', 'Website audience measurement'],
            ['System', 'Craft Analytics (self-hosted within this site’s own infrastructure)'],
            ['Controller', $systemName . ' — complete with your legal entity and contact details'],
            ['Processors', 'None. No data is sent to any third party, and no third-party service is called at runtime.'],
            ['Categories of data subject', 'Visitors to this website'],
            ['Categories of personal data', $consent
                ? 'Pseudonymous visitor hash (salted, rotating); for consenting visitors only: a random first-party identifier'
                    . ($s->enableJourneys ? ', and their page-by-page journey' : '')
                    . ($s->associateUserId ? ', and their user account ID' : '')
                : 'A pseudonymous visitor hash (salted, rotating), used transiently. No identifiers persist.', ],
            ['Special category data', 'None'],
            ['Source of the data', 'The visitor’s own HTTP requests to this site'],
            ['IP addresses', 'Not stored in any form. Hashed in memory during the request and discarded; never written to a table, a log, or a persistent cache key.'],
            ['Lawful basis', $consent
                ? 'Consent (Art. 6(1)(a)) for the identified layer. The aggregate statistics contain no personal data once the salt rotates.'
                : 'Not engaged for the statistics: they are anonymous aggregate data. The transient in-request hashing is necessary for the site’s legitimate interest in measuring its own audience (Art. 6(1)(f)).', ],
            ['Purpose', 'Understanding how this website is used, in order to improve it'],
            ['Automated decision-making', 'None'],
            ['Recipients', 'None'],
            ['International transfers', 'None. The data never leaves the infrastructure this site already runs on.'],
            ['Retention — aggregates', $s->rollupRetentionMonths . ' months (anonymous statistics)'],
            ['Retention — visitor salt', 'Rotated every ' . round($s->saltRotationInterval / 3600, 1) . ' hours; the previous salt is destroyed, after which its hashes cannot be linked to anything'],
            ['Retention — raw journeys', $consent && $s->enableJourneys ? $s->journeyRetentionDays . ' days' : 'Not applicable — not collected'],
            ['Retention — consent records', $consent
                ? ($s->consentLogRetentionDays === 0 ? 'Kept as evidence of lawful basis (no automatic deletion)' : $s->consentLogRetentionDays . ' days')
                : 'Not applicable — not collected', ],
            ['Security measures', 'Data resides in this site’s own database. No third-party access. Identifiers are pseudonymous and salted; the salt rotates and is destroyed.'],
        ];

        $out = "# Record of Processing Activities — website analytics\n\n"
            . $this->generatedLine()
            . "| Field | Detail |\n|---|---|\n";

        foreach ($rows as [$field, $detail]) {
            $out .= '| ' . $field . ' | ' . str_replace('|', '\|', $detail) . " |\n";
        }

        return $out . "\n" . $this->disclaimer();
    }

    /**
     * Plain-English text the site can paste into its privacy notice.
     */
    public function privacyNotice(): string
    {
        $s = $this->settings();
        $consent = $this->consentEnabled();

        $out = "# Analytics — privacy notice appendix\n\n"
            . $this->generatedLine()
            . "The following is written for your visitors to read. Adapt the tone; keep the facts.\n\n"
            . "---\n\n"
            . "## How we measure our website\n\n"
            . "We want to know which of our pages are useful, so we count visits. We do this "
            . "ourselves, on our own servers. We do not use Google Analytics or any other "
            . "third-party analytics service, and no information about your visit is ever sent "
            . "to another company.\n\n"
            . "**We do not store your IP address.** When you load a page, your address is "
            . "briefly combined with a secret key to produce a random-looking code, and then "
            . "discarded. We keep only the code. The secret key is replaced every "
            . round($s->saltRotationInterval / 3600, 1) . " hours and the old one is destroyed — after that, "
            . "even we cannot tell that yesterday's code and today's code belong to the same "
            . "person.\n\n"
            . "We record the page you visited, roughly how long you stayed, the site that "
            . "referred you (its name only — never the full address, which could contain your "
            . "search terms), and your browser and device type. We never build a profile of "
            . "you, and we never sell or share anything.\n\n";

        if ($consent) {
            $out .= "## If you agree to analytics cookies\n\n"
                . "If — and only if — you agree, we store one small file (a cookie called "
                . "`" . $s->consentCookieName . "`) on your device containing a random number. "
                . "It lets us recognise a returning visit, so we can tell whether people come "
                . "back. It expires after " . round($s->consentCookieDuration / 2629800) . " months, and you can "
                . "withdraw your agreement at any time — we delete the cookie immediately"
                . ($s->enableJourneys ? " and erase the history associated with it" : "") . ".\n\n";

            if ($s->enableJourneys) {
                $out .= "For visitors who agree, we keep the sequence of pages visited for "
                    . $s->journeyRetentionDays . " days, so we can see how people move through the "
                    . "site.\n\n";
            }

            if ($s->associateUserId) {
                $out .= "If you are signed in and have agreed to analytics, we link these "
                    . "measurements to your account.\n\n";
            }

            $out .= "**Your rights.** You can ask us for a copy of what we hold about you, or "
                . "ask us to delete it. Note that our overall visitor statistics are anonymous "
                . "counts and estimates — there is no \"you\" inside them to find or remove.\n\n";
        } else {
            $out .= "## Cookies\n\n"
                . "**Our analytics does not use cookies at all**, and stores nothing on your "
                . "device. There is nothing to opt out of, and nothing for you to dismiss.\n\n";
        }

        if ($s->honourGpc) {
            $out .= "## Global Privacy Control\n\n"
                . "If your browser sends a Global Privacy Control signal, we respect it "
                . "automatically. You do not need to tell us twice.\n\n";
        }

        return $out . "---\n\n" . $this->disclaimer();
    }

    /**
     * The facts a DPIA needs, and an honest read on whether one is required.
     */
    public function dpiaSummary(): string
    {
        $s = $this->settings();
        $consent = $this->consentEnabled();

        $triggers = [];

        if ($consent && $s->enableJourneys) {
            $triggers[] = 'Individual browsing histories are recorded for consenting visitors, which is a form of tracking of behaviour.';
        }

        if ($consent && $s->associateUserId) {
            $triggers[] = 'Analytics data is linked to identified user accounts.';
        }

        if (!$s->honourGpc) {
            $triggers[] = 'A recognised browser opt-out signal is being ignored.';
        }

        $required = $triggers !== [];

        $out = "# DPIA support summary — website analytics\n\n"
            . $this->generatedLine()
            . "## Is a DPIA required?\n\n";

        if (!$required) {
            $out .= "**Probably not, on the strength of this configuration.** A DPIA is required "
                . "where processing is likely to result in a high risk to people's rights and "
                . "freedoms — typically systematic monitoring, large-scale profiling, or "
                . "special-category data.\n\n"
                . "As configured, this system: stores no IP addresses, sets no cookies, places "
                . "nothing on visitors' devices, builds no profiles, makes no automated "
                . "decisions, shares nothing with anyone, and destroys the ability to "
                . "re-identify anyone every " . round($s->saltRotationInterval / 3600, 1) . " hours. The "
                . "retained data is aggregate counts and probabilistic sketches.\n\n"
                . "That does not make a DPIA wrong to do — only, on these facts, unlikely to be "
                . "mandatory. Your own assessment governs.\n\n";
        } else {
            $out .= "**Quite possibly yes.** This configuration engages factors that commonly "
                . "trigger the requirement:\n\n";

            foreach ($triggers as $trigger) {
                $out .= "- " . $trigger . "\n";
            }

            $out .= "\nWork through it properly rather than relying on this file.\n\n";
        }

        $out .= "## Facts for the assessment\n\n";

        foreach (Plugin::getInstance()->getPrivacy()->posture()['facts'] as $label => $value) {
            $out .= '- **' . $label . ':** ' . $value . "\n";
        }

        $out .= "\n## Risks, and what mitigates them\n\n"
            . "| Risk | Mitigation |\n|---|---|\n"
            . "| Re-identification of a visitor from stored data | Identifiers are salted hashes; the salt rotates every "
            . round($s->saltRotationInterval / 3600, 1) . " hours and the old one is destroyed. Aggregates hold no identifiers at all. |\n"
            . "| IP address exposure | No address is ever stored, logged, or used in a persistent key. |\n"
            . "| Third-party data sharing | Structurally impossible: the plugin makes no outbound calls and has no third-party integrations. |\n"
            . "| Data leaving the jurisdiction | The data never leaves the infrastructure the site already runs on. |\n"
            . "| Excessive retention | Aggregates: " . $s->rollupRetentionMonths . " months, enforced by a scheduled task."
            . ($consent && $s->enableJourneys ? ' Raw journeys: ' . $s->journeyRetentionDays . ' days.' : '')
            . " |\n"
            . "| Ignoring an opt-out | " . ($s->honourGpc ? 'Global Privacy Control is honoured automatically and cannot be overridden by a CMP or by site code.' : '**Not mitigated — GPC is currently ignored.**') . " |\n";

        if ($consent) {
            $out .= "| Consent that cannot be evidenced | Every decision is logged with its timestamp, method, scope and policy version. |\n"
                . "| Withdrawal not honoured | Withdrawal deletes the cookie immediately and erases the associated records. |\n";
        }

        return $out . "\n" . $this->disclaimer();
    }

    private function consentEnabled(): bool
    {
        return $this->settings()->enableConsent
            && ($this->isPro ??= Plugin::getInstance()->is(Plugin::EDITION_PRO));
    }

    private function generatedLine(): string
    {
        return '> Generated from the live configuration of **' . Craft::$app->getSystemName() . '** on '
            . gmdate('j F Y') . ". Regenerate it whenever the settings change.\n\n";
    }

    private function disclaimer(): string
    {
        return "---\n\n"
            . "*This document is generated from the plugin's configuration. It is accurate about "
            . "what the software does; it is not legal advice, and it cannot know your "
            . "circumstances. Have somebody qualified review it before you rely on it.*\n";
    }

    private function settings(): Settings
    {
        return $this->settings ??= Plugin::getInstance()->getSettings();
    }
}
