<?php

namespace coyshdigital\craftanalytics\uniques;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\models\Settings;
use Craft;
use yii\base\Component;
use yii\db\Connection;
use yii\db\Query;

/**
 * Exact counting for small sites, via a membership table.
 *
 * One row per (scope, visitor) per day, so the table is bounded by *daily
 * unique visitors*, not by traffic — a site with 10 M pageviews from 50 k
 * daily visitors stores 50 k-ish rows, not 10 M (C2 holds).
 *
 * The rows are pruned on the same cycle as the salt: once the salt that
 * produced these hashes is destroyed they cannot be linked to anything
 * anyway, so keeping them would be storage without meaning.
 *
 * Note the honest limit: because hashes are only comparable within a salt
 * window, a multi-day estimate here is still a daily-unique basis, exactly as
 * with the sketch drivers. Exact means exact *within a day*.
 */
class ExactUniqueCounter extends Component implements UniqueCounterInterface
{
    public ?Connection $db = null;
    public ?Settings $settings = null;

    public function name(): string
    {
        return Settings::UNIQUES_DRIVER_EXACT;
    }

    public function accuracy(): string
    {
        return 'exact';
    }

    public function storesOnRow(): bool
    {
        return false;
    }

    public function record(UniqueScope $scope, array $hashes, ?string $currentSketch): ?string
    {
        if ($hashes === []) {
            return null;
        }

        $db = $this->db();

        foreach ($hashes as $hash) {
            // Seeing the same visitor again is the normal case, not an
            // error: insert if absent, otherwise do nothing. One statement
            // per new visitor is affordable precisely because this driver is
            // scoped to sites with few of them.
            $db->createCommand()->upsert(Table::UNIQUE_MEMBERS, [
                'scopeKey' => $scope->key(),
                'siteId' => $scope->siteId,
                'date' => $scope->date,
                'visitorHash' => $hash,
            ], false)->execute();
        }

        return null;
    }

    public function estimate(array $scopes, iterable $sketches = []): int
    {
        if ($scopes === []) {
            return 0;
        }

        $keys = array_map(static fn(UniqueScope $scope) => $scope->key(), $scopes);

        // COUNT(DISTINCT) across the scopes is a real union: a visitor who
        // appears in several of them still counts once.
        return (int)(new Query())
            ->from(Table::UNIQUE_MEMBERS)
            ->where(['scopeKey' => $keys])
            ->count('DISTINCT [[visitorHash]]', $this->db());
    }

    private function db(): Connection
    {
        return $this->db ??= Craft::$app->getDb();
    }
}
