<?php

namespace coyshdigital\craftanalytics\rollup;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\uniques\Hll;
use Craft;
use yii\base\Component;
use yii\db\Connection;
use yii\db\Query;

/**
 * Folds a day's 24 hourly rows into one daily row.
 *
 * Recent data is kept hourly because "when today did people visit" is a real
 * question; a year later nobody asks it of a Tuesday in March, and 24 rows
 * per page per day is 24× the storage for information nobody reads.
 *
 * Lossless at the daily grain: counters add, and sketches **merge** — which
 * is the only reason this is possible at all. Summing 24 hourly unique counts
 * would count a visitor once per hour they browsed; unioning the sketches
 * counts them once, which is the true daily figure.
 *
 * Idempotent: compacting a day twice is a no-op, because merging a sketch
 * into itself changes nothing and the hourly rows are gone after the first
 * pass.
 */
class Compactor extends Component
{
    public const DAILY_HOUR = -1;

    public ?Connection $db = null;
    public ?Settings $settings = null;

    /**
     * Compacts every day now outside the hourly window.
     *
     * @return int days compacted, per table
     */
    public function run(?int $now = null): int
    {
        $cutoff = $this->cutoffDate($now ?? time());
        $compacted = 0;

        foreach ([Table::PAGES_ROLLUP, Table::SESSIONS_ROLLUP, Table::SOURCES_ROLLUP] as $table) {
            $compacted += $this->compactTable($table, $cutoff);
        }

        return $compacted;
    }

    /**
     * The oldest date still kept at hourly grain.
     */
    public function cutoffDate(int $now): string
    {
        $days = $this->settings()->hourlyWindowDays;

        return (new \DateTimeImmutable('@' . $now))
            ->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()))
            ->modify("-$days days")
            ->format('Y-m-d');
    }

    private function compactTable(string $table, string $cutoff): int
    {
        $days = (new Query())
            ->select(['siteId', 'date'])
            ->distinct()
            ->from($table)
            ->where(['<', 'date', $cutoff])
            ->andWhere(['!=', 'hour', self::DAILY_HOUR])
            ->all($this->db());

        $count = 0;

        foreach ($days as $day) {
            $this->compactDay($table, (int)$day['siteId'], (string)$day['date']);
            $count++;
        }

        return $count;
    }

    private function compactDay(string $table, int $siteId, string $date): void
    {
        $db = $this->db();
        $groupColumns = self::groupColumns($table);
        $counterColumns = self::counterColumns($table);
        $hasSketch = self::hasSketch($table);

        $transaction = $db->beginTransaction();

        try {
            $rows = (new Query())
                ->from($table)
                ->where(['siteId' => $siteId, 'date' => $date])
                ->andWhere(['!=', 'hour', self::DAILY_HOUR])
                ->all($db);

            /** @var array<string,array<string,mixed>> $merged */
            $merged = [];
            /** @var array<string,list<string|null>> $sketches */
            $sketches = [];
            /** @var array<string,array<int,array<string,mixed>>> $grouped */
            $grouped = [];

            foreach ($rows as $row) {
                $key = implode('|', array_map(static fn($c) => (string)$row[$c], $groupColumns));
                $grouped[$key][] = $row;

                if (!isset($merged[$key])) {
                    $merged[$key] = array_intersect_key($row, array_flip($groupColumns));
                    foreach ($counterColumns as $column) {
                        $merged[$key][$column] = 0;
                    }
                    $sketches[$key] = [];
                }

                foreach ($counterColumns as $column) {
                    $merged[$key][$column] += (int)$row[$column];
                }

                if ($hasSketch) {
                    $sketches[$key][] = self::readBlob($row['uniques'] ?? null);
                }
            }

            // The daily rows may already exist from an interrupted pass, so
            // replace rather than upsert-add: the hourly rows are the whole
            // truth for this day.
            $db->createCommand()->delete($table, [
                'siteId' => $siteId,
                'date' => $date,
                'hour' => self::DAILY_HOUR,
            ])->execute();

            foreach ($merged as $key => $row) {
                $row['hour'] = self::DAILY_HOUR;

                if ($table === Table::PAGES_ROLLUP) {
                    $row['elementId'] = self::elementIdFor($grouped[$key]);
                }

                if ($hasSketch) {
                    $sketch = Hll::mergeAll($sketches[$key], $this->settings()->hllPrecision);
                    $row['uniques'] = $sketches[$key] === []
                        ? null
                        : new \yii\db\PdoValue($sketch->serialize(), \PDO::PARAM_LOB);
                }

                unset($row['id']);
                $db->createCommand()->insert($table, $row)->execute();
            }

            $db->createCommand()->delete($table, [
                'and',
                ['siteId' => $siteId, 'date' => $date],
                ['!=', 'hour', self::DAILY_HOUR],
            ])->execute();

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    private static function readBlob(mixed $value): ?string
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        if (is_resource($value)) {
            return (string)stream_get_contents($value);
        }

        return (string)$value;
    }

    /**
     * The columns a day's rows are merged on.
     *
     * These must be exactly the table's unique key minus `hour`, or the
     * compacted rows collide with each other on insert. `elementId` is
     * deliberately *not* here even though pages_rollup has one: the unique key
     * is (siteId, date, hour, pathDimId), so grouping by element as well
     * produces two rows that the index says are one, and the whole GC run dies
     * on a duplicate-key error.
     *
     * That is not hypothetical. One path can hold rows with different
     * elementIds - an entry deleted and recreated, or a URI that resolved to
     * an element on Monday and to a template-only route on Tuesday - and the
     * hourly writer already folds those onto one row per hour. Compaction has
     * to fold them the same way. See elementIdFor().
     *
     * @return string[]
     */
    private static function groupColumns(string $table): array
    {
        return match ($table) {
            Table::PAGES_ROLLUP => ['siteId', 'date', 'pathDimId'],
            Table::SOURCES_ROLLUP => ['siteId', 'date', 'channel', 'refHostDimId'],
            default => ['siteId', 'date'],
        };
    }

    /**
     * The element a compacted page row belongs to.
     *
     * First non-null wins: a path that resolved to an entry is more useful
     * than one that didn't, and a row that knows its element can be joined to
     * the Content reports.
     *
     * @param array<int,array<string,mixed>> $rows the hourly rows being merged
     */
    private static function elementIdFor(array $rows): ?int
    {
        foreach ($rows as $row) {
            if (($row['elementId'] ?? null) !== null) {
                return (int)$row['elementId'];
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private static function counterColumns(string $table): array
    {
        return match ($table) {
            Table::PAGES_ROLLUP => ['views', 'totalDwellMs', 'entrances', 'exits', 'bounces'],
            Table::SOURCES_ROLLUP => ['sessions', 'bounces'],
            default => ['sessions', 'bounces', 'totalDurationMs', 'totalPageviews'],
        };
    }

    private static function hasSketch(string $table): bool
    {
        return in_array($table, [Table::PAGES_ROLLUP, Table::SESSIONS_ROLLUP], true);
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
