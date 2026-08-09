<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\rollup\Compactor;
use coyshdigital\craftanalytics\uniques\UniqueCounterInterface;
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

    /** Unique-counter override, handed to the compactor. Set in tests. */
    public ?UniqueCounterInterface $counter = null;

    /** Rows removed per statement when expiring aged-out data. */
    private const DELETE_CHUNK = 5000;

    /** Dimension rows examined per pass when hunting for orphans. */
    private const ORPHAN_CHUNK = 1000;

    /**
     * @return array<string,int> what was done, for the console command
     */
    public function run(?int $now = null): array
    {
        $now ??= time();

        return [
            'compactedDays' => (new Compactor([
                'db' => $this->db,
                'settings' => $this->settings,
                'counter' => $this->counter,
            ]))->run($now),
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
     *
     * The table list lives in SchemaBuilder beside the schema that creates
     * them, for the same reason dimensionReferences() does: a rollup added
     * without an entry here is a rollup kept forever, silently, while the
     * Privacy screen goes on quoting a retention period.
     */
    private function deleteExpiredRollups(int $now): int
    {
        $cutoff = $this->retentionCutoff($now);
        $deleted = 0;

        foreach (SchemaBuilder::expiringRollups() as $table) {
            $deleted += $this->deleteInBatches($table, ['<', 'date', $cutoff]);
        }

        return $deleted;
    }

    /**
     * Deletes matching rows a batch at a time.
     *
     * The first run after a retention period elapses is the big one - on a
     * wide table like page sources that is every row of every day that has
     * just aged out, and doing it in one statement is a long transaction
     * holding locks on a table the drain is trying to write to. Batching
     * gives the same result in slices each of which commits on its own.
     *
     * Selected by primary key rather than deleted with a LIMIT, because
     * `DELETE ... LIMIT` is MySQL-only and this has to work on Postgres too.
     *
     * @param array<mixed> $condition
     */
    private function deleteInBatches(string $table, array $condition): int
    {
        $db = $this->db();
        $deleted = 0;

        while (true) {
            $ids = (new Query())
                ->select('id')
                ->from($table)
                ->where($condition)
                ->limit(self::DELETE_CHUNK)
                ->column($db);

            if ($ids === []) {
                return $deleted;
            }

            $deleted += $db->createCommand()->delete($table, ['id' => $ids])->execute();
        }
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

        // Site time, not UTC. The `date` column is written in the site's
        // timezone like every other date in the schema, so comparing it
        // against gmdate() put the cutoff up to a day out either way -
        // keeping a day longer than intended west of UTC, and dropping a day
        // early east of it.
        $cutoff = (new \DateTimeImmutable('@' . ($now - $interval * 2)))
            ->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()))
            ->format('Y-m-d');

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
        $db = $this->db();
        $deleted = 0;
        $lastId = 0;

        // Walked in chunks by id rather than resolved in one pass. The
        // previous version loaded every referenced id in the plugin into a
        // PHP array, built `NOT IN (<all of them>)`, and then deleted with
        // `IN (<every orphan>)`. All three grow with the table, so the method
        // fell over - out of memory, or past max_allowed_packet - on exactly
        // the cardinality spike it exists to clean up after. It runs on
        // Craft's Gc event as well as the console command, so that failure
        // lands on somebody's web request.
        //
        // Deleting only ids at or below the chunk's high-water mark keeps the
        // keyset walk correct while rows are disappearing underneath it.
        while (true) {
            $candidates = (new Query())
                ->select('id')
                ->from(Table::DIMENSIONS)
                ->where(['>', 'id', $lastId])
                ->orderBy(['id' => SORT_ASC])
                ->limit(self::ORPHAN_CHUNK)
                ->column($db);

            if ($candidates === []) {
                break;
            }

            $lastId = (int)end($candidates);
            /** @var array<int,true> $orphans */
            $orphans = array_fill_keys(array_map('intval', $candidates), true);

            // Every dimension column in the plugin, from the one list that the
            // schema itself owns. Hardcoding a subset here is how every Pro
            // report's dimensions got deleted the first time this ran.
            foreach (SchemaBuilder::dimensionReferences() as [$table, $column]) {
                if ($orphans === []) {
                    break;
                }

                $referenced = (new Query())
                    ->select($column)
                    ->distinct()
                    ->from($table)
                    ->where([$column => array_keys($orphans)])
                    ->column($db);

                foreach ($referenced as $id) {
                    unset($orphans[(int)$id]);
                }
            }

            if ($orphans !== []) {
                $deleted += $db->createCommand()
                    ->delete(Table::DIMENSIONS, ['id' => array_keys($orphans)])
                    ->execute();
            }
        }

        return $deleted;
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
