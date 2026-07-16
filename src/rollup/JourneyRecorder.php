<?php

namespace coyshdigital\craftanalytics\rollup;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\enums\ConsentState;
use coyshdigital\craftanalytics\enums\DimensionType;
use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\services\DimensionsService;
use Craft;
use yii\base\Component;
use yii\db\Connection;
use yii\db\Query;

/**
 * The consented raw layer (§6.4).
 *
 * The one place in this plugin where a row means "this person did this" —
 * allowed to exist only because three separate things are true: the site has
 * Pro, has switched consent on, has switched journeys on, *and* the visitor
 * affirmatively agreed. Absent any one of those, this writes nothing.
 *
 * It is also the only table whose growth tracks traffic rather than
 * cardinality, which is exactly why it is opt-in, has its own short
 * retention, and is individually erasable (C2's carve-out, not its
 * exception).
 */
class JourneyRecorder extends Component
{
    public ?Connection $db = null;
    public ?Settings $settings = null;
    public ?DimensionsService $dimensions = null;

    /** Edition override; defaults to the plugin's own. Set in tests. */
    public ?bool $isPro = null;

    /**
     * Whether anything at all should be written here.
     */
    public function isEnabled(): bool
    {
        $settings = $this->settings();

        return $settings->enableJourneys
            && $settings->enableConsent
            && ($this->isPro ??= Plugin::getInstance()->is(Plugin::EDITION_PRO));
    }

    /**
     * Writes the consented hits of a batch.
     *
     * Called inside the drain's transaction, so these rows commit with the
     * batch marker and a replay cannot duplicate them.
     *
     * @param Hit[] $hits
     * @return int rows written
     */
    public function record(array $hits): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }

        // Only consented visitors, and only actual pageviews — a dwell-only
        // beacon is the same view being reported on, not a second step in the
        // journey.
        $consented = array_values(array_filter(
            $hits,
            static fn(Hit $hit) => $hit->visitorId !== null && $hit->countView,
        ));

        if ($consented === []) {
            return 0;
        }

        // Consent is checked again *here*, not trusted from capture time.
        //
        // A hit is consented when it is spooled, but the drain runs later —
        // and in between, the visitor may have withdrawn. Withdrawal deletes
        // their rows immediately, so without this check the drain would write
        // the spooled hits straight back afterwards and silently resurrect
        // data for somebody who said no. The consent log is the authority on
        // what is true now.
        $withdrawn = $this->withdrawnVisitors(array_unique(array_map(
            static fn(Hit $hit) => (string)$hit->visitorId,
            $consented,
        )));

        if ($withdrawn !== []) {
            $consented = array_values(array_filter(
                $consented,
                static fn(Hit $hit) => !isset($withdrawn[$hit->visitorId]),
            ));

            if ($consented === []) {
                return 0;
            }
        }

        $rows = [];
        $sequences = [];

        foreach ($consented as $hit) {
            $sessionKey = $hit->visitorId . '|' . $hit->sessionKey;
            $sequences[$sessionKey] ??= $this->nextSequence($hit);

            $rows[] = [
                $hit->visitorId,
                $hit->siteId,
                $hit->sessionKey,
                $sequences[$sessionKey]++,
                $this->dimensions()->getOrCreateId(DimensionType::Path, $hit->path),
                null,
                // Only when the site separately opted into linking analytics
                // to accounts: consenting to measurement is not consenting to
                // have it tied to your name.
                $this->settings()->associateUserId ? $hit->userId : null,
                gmdate('Y-m-d H:i:s', $hit->timestamp),
            ];
        }

        $this->db()->createCommand()->batchInsert(Table::JOURNEYS, [
            'visitorId',
            'siteId',
            'sessionId',
            'sequence',
            'pathDimId',
            'eventDimId',
            'userId',
            'occurredAt',
        ], $rows)->execute();

        return count($rows);
    }

    /**
     * Which of these visitors have since refused or withdrawn.
     *
     * One query per batch, reduced in PHP so it reads the same on both
     * databases. Ties on the same second are broken by id — the later row is
     * the later decision.
     *
     * @param string[] $visitorIds
     * @return array<string,true>
     */
    private function withdrawnVisitors(array $visitorIds): array
    {
        if ($visitorIds === []) {
            return [];
        }

        $rows = (new Query())
            ->select(['visitorId', 'state'])
            ->from(Table::CONSENT_LOG)
            ->where(['visitorId' => $visitorIds])
            ->orderBy(['recordedAt' => SORT_ASC, 'id' => SORT_ASC])
            ->all($this->db());

        $latest = [];
        foreach ($rows as $row) {
            $latest[(string)$row['visitorId']] = (string)$row['state'];
        }

        return array_filter(
            array_map(static fn(string $state) => $state === ConsentState::Denied->value, $latest),
        );
    }

    /**
     * Where this session's journey left off, so a session spanning two drain
     * batches keeps counting rather than restarting at zero.
     */
    private function nextSequence(Hit $hit): int
    {
        $max = (new Query())
            ->from(Table::JOURNEYS)
            ->where(['visitorId' => $hit->visitorId, 'sessionId' => $hit->sessionKey])
            ->max('[[sequence]]', $this->db());

        return $max === null ? 0 : (int)$max + 1;
    }

    private function dimensions(): DimensionsService
    {
        return $this->dimensions ??= Plugin::getInstance()->getDimensions();
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
