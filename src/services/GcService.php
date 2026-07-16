<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\rollup\Compactor;
use Craft;
use yii\base\Component;
use yii\db\Connection;
use yii\db\Query;

/**
 * Keeps the tables to the size the settings promise.
 *
 * Hooked to Craft's GC *and* exposed as a console command: relying on Craft's
 * GC alone would mean retention silently depends on how often somebody visits
 * the CP, which is not a retention policy anyone can put in a privacy notice.
 */
class GcService extends Component
{
    public ?Connection $db = null;
    public ?Settings $settings = null;

    /**
     * @return array<string,int> what was done, for the console command
     */
    public function run(?int $now = null): array
    {
        $now ??= time();

        return [
            'compactedDays' => (new Compactor(['db' => $this->db, 'settings' => $this->settings]))->run($now),
            'expiredRollups' => $this->deleteExpiredRollups($now),
            'expiredMembers' => $this->deleteExpiredUniqueMembers($now),
            'expiredJourneys' => $this->deleteExpiredJourneys($now),
            'expiredConsentRecords' => $this->deleteExpiredConsentRecords($now),
            'prunedDrainLog' => $this->pruneDrainLog($now),
            'orphanedDimensions' => $this->deleteOrphanedDimensions(),
        ];
    }

    /**
     * Enforces the journeys retention window.
     *
     * This is the one table holding personal data, so its retention is the
     * one that most needs to be automatic rather than remembered.
     */
    private function deleteExpiredJourneys(int $now): int
    {
        $days = min($this->settings()->journeyRetentionDays, Settings::JOURNEY_MAX_RETENTION_DAYS);
        $cutoff = gmdate('Y-m-d H:i:s', $now - $days * 86400);

        return $this->db()->createCommand()
            ->delete(Table::JOURNEYS, ['<', 'occurredAt', $cutoff])
            ->execute();
    }

    /**
     * Consent evidence, if the site has set a retention for it.
     *
     * Zero means keep indefinitely, which is the usual legal-hold position:
     * the record that processing was lawful should outlive the processing.
     */
    private function deleteExpiredConsentRecords(int $now): int
    {
        $days = $this->settings()->consentLogRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        return $this->db()->createCommand()
            ->delete(Table::CONSENT_LOG, ['<', 'recordedAt', gmdate('Y-m-d H:i:s', $now - $days * 86400)])
            ->execute();
    }

    /**
     * Enforces the retention window. This is the promise in the privacy
     * notice, so it runs on data age rather than on anything discretionary.
     */
    private function deleteExpiredRollups(int $now): int
    {
        $cutoff = $this->retentionCutoff($now);
        $deleted = 0;

        foreach ([
            Table::PAGES_ROLLUP,
            Table::SESSIONS_ROLLUP,
            Table::SOURCES_ROLLUP,
            Table::DEVICES_ROLLUP,
        ] as $table) {
            $deleted += $this->db()->createCommand()
                ->delete($table, ['<', 'date', $cutoff])
                ->execute();
        }

        return $deleted;
    }

    /**
     * Drops membership rows once the salt that produced their hashes is gone.
     *
     * After rotation those hashes cannot be matched to anything — not by us,
     * not by anyone — so keeping them would be storing bytes with no meaning
     * and a nonzero liability.
     */
    private function deleteExpiredUniqueMembers(int $now): int
    {
        $interval = $this->settings()->saltRotationInterval;
        $cutoff = gmdate('Y-m-d', $now - $interval * 2);

        return $this->db()->createCommand()
            ->delete(Table::UNIQUE_MEMBERS, ['<', 'date', $cutoff])
            ->execute();
    }

    /**
     * Drain markers only need to outlive the spool files they guard.
     */
    private function pruneDrainLog(int $now): int
    {
        return $this->db()->createCommand()
            ->delete(Table::DRAIN_LOG, ['<', 'committedAt', gmdate('Y-m-d H:i:s', $now - 7 * 86400)])
            ->execute();
    }

    /**
     * Removes dimension values nothing references any more — the tail of a
     * cardinality spike, still occupying rows after the rollups that used it
     * have aged out.
     */
    private function deleteOrphanedDimensions(): int
    {
        $referenced = [];

        foreach ([
            [Table::PAGES_ROLLUP, 'pathDimId'],
            [Table::SOURCES_ROLLUP, 'refHostDimId'],
            [Table::DEVICES_ROLLUP, 'browserDimId'],
            [Table::DEVICES_ROLLUP, 'osDimId'],
        ] as [$table, $column]) {
            foreach ((new Query())->select($column)->distinct()->from($table)->column($this->db()) as $id) {
                $referenced[(int)$id] = true;
            }
        }

        $orphans = (new Query())
            ->select('id')
            ->from(Table::DIMENSIONS)
            ->where(['not in', 'id', array_keys($referenced) ?: [0]])
            ->column($this->db());

        if ($orphans === []) {
            return 0;
        }

        return $this->db()->createCommand()
            ->delete(Table::DIMENSIONS, ['id' => $orphans])
            ->execute();
    }

    private function retentionCutoff(int $now): string
    {
        $months = $this->settings()->rollupRetentionMonths;

        return (new \DateTimeImmutable('@' . $now))
            ->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()))
            ->modify("-$months months")
            ->format('Y-m-d');
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
