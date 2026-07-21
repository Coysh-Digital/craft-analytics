<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\enums\ConsentState;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use yii\base\Component;
use yii\db\Connection;
use yii\db\Query;

/**
 * Data-subject rights, and the compliance posture.
 *
 * The scope of a DSAR here is narrower than people expect, and that is the
 * point: **aggregate rollups are anonymous statistical data and are out of
 * scope.** There is no person in them to export or erase — a HyperLogLog
 * sketch cannot be interrogated for an individual, and the salted hashes that
 * fed it stop being linkable to anyone the moment the salt rotates.
 *
 * So a subject request touches exactly two things: the consented raw journeys
 * layer, and the consent evidence log. Both exist only for visitors who
 * affirmatively agreed, and only when the site turned them on.
 */
class PrivacyService extends Component
{
    public ?Connection $db = null;
    public ?Settings $settings = null;

    /** Edition override; defaults to the plugin's own. Set in tests. */
    public ?bool $isPro = null;

    /**
     * Everything held about one subject.
     *
     * @return array{subject: array<string,mixed>, consent: array<int,array<string,mixed>>, journeys: array<int,array<string,mixed>>, notes: string[]}
     */
    public function export(?string $visitorId = null, ?int $userId = null): array
    {
        $this->assertSubject($visitorId, $userId);

        $journeys = $this->journeyQuery($visitorId, $userId)
            ->select([
                'occurredAt' => '[[j]].[[occurredAt]]',
                'path' => '[[d]].[[value]]',
                'sessionId' => '[[j]].[[sessionId]]',
                'sequence' => '[[j]].[[sequence]]',
                'siteId' => '[[j]].[[siteId]]',
                'userId' => '[[j]].[[userId]]',
            ])
            ->innerJoin(['d' => Table::DIMENSIONS], '[[d]].[[id]] = [[j]].[[pathDimId]]')
            ->orderBy(['[[j]].[[occurredAt]]' => SORT_ASC])
            ->all($this->db());

        $consent = [];

        if ($visitorId !== null) {
            $consent = (new Query())
                ->select(['recordedAt', 'state', 'method', 'scope', 'policyVersion', 'siteId'])
                ->from(Table::CONSENT_LOG)
                ->where(['visitorId' => $visitorId])
                ->orderBy(['recordedAt' => SORT_ASC])
                ->all($this->db());
        }

        return [
            'subject' => array_filter([
                'visitorId' => $visitorId,
                'userId' => $userId,
            ], static fn($v) => $v !== null),
            'consent' => $consent,
            'journeys' => $journeys,
            'notes' => [
                'This export covers every record held about this subject: their consented page '
                . 'journeys and their consent history.',
                'Aggregate statistics (pageviews, unique-visitor estimates, sessions, sources, '
                . 'devices) are not included because they contain no personal data. They are '
                . 'counters and probabilistic sketches from which no individual can be '
                . 'identified or removed.',
                'No IP address is held, in any form. Addresses are hashed in memory during a '
                . 'request and discarded; the salt used to hash them is destroyed on rotation.',
            ],
        ];
    }

    /**
     * Erases a subject.
     *
     * Deletes the journeys outright. The consent log is kept by default,
     * because it is the evidence that the now-erased processing was lawful —
     * destroying it on request would leave the site unable to answer the very
     * question a regulator would ask. Pass $includeConsentLog to remove it
     * too, when the site's legal position calls for that.
     *
     * @return array{journeys: int, consentLog: int}
     */
    public function erase(?string $visitorId = null, ?int $userId = null, bool $includeConsentLog = false): array
    {
        $this->assertSubject($visitorId, $userId);

        $condition = $visitorId !== null ? ['visitorId' => $visitorId] : ['userId' => $userId];
        $journeys = $this->db()->createCommand()->delete(Table::JOURNEYS, $condition)->execute();
        $consentRows = 0;

        if ($includeConsentLog && $visitorId !== null) {
            $consentRows = $this->db()->createCommand()
                ->delete(Table::CONSENT_LOG, ['visitorId' => $visitorId])
                ->execute();
        }

        return ['journeys' => $journeys, 'consentLog' => $consentRows];
    }

    /**
     * What the current configuration means, in the language a DPO uses.
     *
     * Deliberately reports what the settings *permit*, not what has happened
     * to have occurred: "we could set a cookie" is the compliance-relevant
     * fact, not "we haven't yet today".
     *
     * @return array{banner: bool, cookies: bool, identifiers: string, lawfulBasis: string, warnings: string[], facts: array<string,string>}
     */
    public function posture(): array
    {
        $settings = $this->settings();
        $warnings = [];

        $consentAvailable = $settings->enableConsent && $this->isPro();

        if ($consentAvailable) {
            $warnings[] = Craft::t('craft-analytics', 'Consented tracking is enabled, so this site sets a first-party cookie for visitors who agree. That requires a consent mechanism and a privacy notice entry - the banner-free posture no longer applies.');
        }

        if ($settings->enableJourneys && $consentAvailable) {
            $warnings[] = Craft::t('craft-analytics', 'The raw journeys layer is enabled. Individual page-by-page histories are stored for consented visitors - this is personal data, subject to access and erasure requests.');
        }

        if ($settings->associateUserId && $consentAvailable) {
            $warnings[] = Craft::t('craft-analytics', 'Consented visitors are linked to their Craft user account, which makes the data directly identifiable rather than pseudonymous.');
        }

        $suppliedIds = $consentAvailable
            && Plugin::getInstance()->getConsent()->hasEventHandlers(ConsentService::EVENT_DEFINE_VISITOR_ID);

        if ($suppliedIds) {
            $warnings[] = Craft::t('craft-analytics', 'A module on this site supplies its own identifier for consented visitors. That is no longer a random value meaningful only inside this plugin - it can be joined to the site\'s own records, which makes the consented data directly identifiable rather than pseudonymous.');
        }

        if (!$settings->honourGpc) {
            $warnings[] = Craft::t('craft-analytics', 'Global Privacy Control is being ignored. GPC is treated as a valid opt-out under several privacy laws, and disregarding it is a legal risk.');
        }

        if ($settings->saltRotationInterval > 86400) {
            $warnings[] = Craft::t('craft-analytics', 'The visitor salt rotates less often than every 24 hours, which lengthens how long a visitor stays re-identifiable and weakens the case that this data is anonymous.');
        }

        if ($settings->consentCookieDuration > 34186000 && $consentAvailable) {
            $warnings[] = Craft::t('craft-analytics', 'The consent cookie lasts longer than 13 months. Guidance in several jurisdictions expects consent to be refreshed at least annually.');
        }

        return [
            'banner' => $consentAvailable,
            'cookies' => $consentAvailable,
            'identifiers' => match (true) {
                $suppliedIds => Craft::t('craft-analytics', 'A rotating pseudonymous hash for everyone; for visitors who consent, an identifier supplied by this site\'s own code.'),
                $consentAvailable => Craft::t('craft-analytics', 'A rotating pseudonymous hash for everyone; a random first-party ID for visitors who consent.'),
                default => Craft::t('craft-analytics', 'A rotating pseudonymous hash, and nothing else.'),
            },
            'lawfulBasis' => $consentAvailable
                ? Craft::t('craft-analytics', 'Consent for the identified layer; legitimate interests (or none required) for the anonymous statistics.')
                : Craft::t('craft-analytics', 'No personal data is processed once the salt rotates, so no lawful basis is engaged for the statistics.'),
            'warnings' => $warnings,
            'facts' => $this->facts(),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function facts(): array
    {
        $settings = $this->settings();
        $consentAvailable = $settings->enableConsent && $this->isPro();

        return [
            Craft::t('craft-analytics', 'IP addresses stored') => Craft::t('craft-analytics', 'Never - hashed in memory and discarded'),
            Craft::t('craft-analytics', 'Cookies set') => $consentAvailable
                ? Craft::t('craft-analytics', 'One, for consenting visitors only ({name})', ['name' => $settings->consentCookieName])
                : Craft::t('craft-analytics', 'None'),
            Craft::t('craft-analytics', 'Device storage used') => Craft::t('craft-analytics', 'None'),
            Craft::t('craft-analytics', 'Data leaves the site') => Craft::t('craft-analytics', 'Never - no third party receives anything'),
            Craft::t('craft-analytics', 'Visitor salt rotation') => Craft::t('craft-analytics', 'Every {hours} hours, destroying the previous salt', [
                'hours' => round($settings->saltRotationInterval / 3600, 1),
            ]),
            Craft::t('craft-analytics', 'Global Privacy Control') => $settings->honourGpc
                ? Craft::t('craft-analytics', 'Honoured')
                : Craft::t('craft-analytics', 'Ignored'),
            Craft::t('craft-analytics', 'Aggregate retention') => Craft::t('craft-analytics', '{months} months', ['months' => $settings->rollupRetentionMonths]),
            Craft::t('craft-analytics', 'Raw per-visitor rows') => $settings->enableJourneys && $consentAvailable
                ? Craft::t('craft-analytics', 'Yes, for consenting visitors, kept {days} days', ['days' => $settings->journeyRetentionDays])
                : Craft::t('craft-analytics', 'None'),
        ];
    }

    /**
     * Counts, for the CP panel and the DSAR commands.
     *
     * @return array{consentedVisitors: int, journeyRows: int, consentGrants: int, consentDenials: int}
     */
    public function counts(): array
    {
        return [
            'consentedVisitors' => (int)(new Query())->from(Table::JOURNEYS)->count('DISTINCT [[visitorId]]', $this->db()),
            'journeyRows' => (int)(new Query())->from(Table::JOURNEYS)->count('*', $this->db()),
            'consentGrants' => (int)(new Query())->from(Table::CONSENT_LOG)
                ->where(['state' => ConsentState::Granted->value])->count('*', $this->db()),
            'consentDenials' => (int)(new Query())->from(Table::CONSENT_LOG)
                ->where(['state' => ConsentState::Denied->value])->count('*', $this->db()),
        ];
    }

    private function journeyQuery(?string $visitorId, ?int $userId): Query
    {
        $query = (new Query())->from(['j' => Table::JOURNEYS]);

        return $visitorId !== null
            ? $query->where(['[[j]].[[visitorId]]' => $visitorId])
            : $query->where(['[[j]].[[userId]]' => $userId]);
    }

    private function assertSubject(?string $visitorId, ?int $userId): void
    {
        if ($visitorId === null && $userId === null) {
            throw new \InvalidArgumentException('A subject request needs either a visitor ID or a user ID.');
        }
    }

    private function isPro(): bool
    {
        return $this->isPro ??= Plugin::getInstance()->is(Plugin::EDITION_PRO);
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
