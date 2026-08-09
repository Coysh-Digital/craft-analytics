<?php

namespace coyshdigital\craftanalytics\rollup;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\uniques\Hll;
use coyshdigital\craftanalytics\uniques\UniqueCounterInterface;
use coyshdigital\craftanalytics\uniques\UniqueScope;
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

    /** Unique-counter override; defaults to the configured driver. */
    public ?UniqueCounterInterface $counter = null;

    /**
     * Compacts every day now outside the hourly window.
     *
     * @return int days compacted, per table
     */
    public function run(?int $now = null): int
    {
        $cutoff = $this->cutoffDate($now ?? time());
        $compacted = 0;

        foreach ([
            Table::PAGES_ROLLUP,
            Table::SESSIONS_ROLLUP,
            Table::SOURCES_ROLLUP,
            // The only Pro rollup with an hour column, and so the only one
            // that grew 24 rows a day per (event, path) with nothing ever
            // folding them down.
            Table::EVENTS_ROLLUP,
        ] as $table) {
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
        $decimalColumns = array_fill_keys(self::decimalCounters($table), true);
        $hasSketch = self::hasSketch($table);

        // Hourly counters whose fold into the daily one has been staged, to be
        // dropped once the row rewrite below has actually committed.
        /** @var UniqueScope[] $foldedScopes */
        $foldedScopes = [];

        $transaction = $db->beginTransaction();

        try {
            // Every row for the day, the already-compacted one included. The
            // daily row is not a duplicate to be overwritten: once a day has
            // compacted, its hourly rows are gone and that row is the only
            // record of them. Rebuilding the day from the hourly rows alone
            // was therefore correct exactly once, on the first pass - and any
            // hourly row arriving afterwards (a quarantined batch replayed
            // past the hourly window, a spool recovered after an outage,
            // seeded history) brought the day back into range and silently
            // replaced a month of traffic with whatever had just turned up.
            $rows = (new Query())
                ->from($table)
                ->where(['siteId' => $siteId, 'date' => $date])
                ->all($db);

            /** @var array<string,array<string,mixed>> $merged */
            $merged = [];
            /** @var array<string,list<string|null>> $sketches */
            $sketches = [];
            /** @var array<string,array<int,array<string,mixed>>> $grouped */
            $grouped = [];
            /** @var array<string,array<int,array<string,mixed>>> $elementRows */
            $elementRows = [];

            foreach ($rows as $row) {
                $key = implode('|', array_map(static fn($c) => (string)$row[$c], $groupColumns));
                $isDaily = (int)$row['hour'] === self::DAILY_HOUR;

                if (!isset($merged[$key])) {
                    $merged[$key] = array_intersect_key($row, array_flip($groupColumns));
                    foreach ($counterColumns as $column) {
                        $merged[$key][$column] = 0;
                    }
                    $sketches[$key] = [];
                    $grouped[$key] = [];
                    $elementRows[$key] = [];
                }

                if ($isDaily) {
                    // Ahead of the hourly rows, so an element this day already
                    // resolved is not lost to a late row that never knew one.
                    array_unshift($elementRows[$key], $row);
                } else {
                    // Only the hourly rows: these drive the driver-counter
                    // fold below, and a scope at `hour = -1` is the daily
                    // counter itself - folding it into itself and then
                    // discarding it would throw away what was just merged.
                    $grouped[$key][] = $row;
                    $elementRows[$key][] = $row;
                }

                foreach ($counterColumns as $column) {
                    // Cast per column, not uniformly: `sumValue` is a decimal
                    // holding money, and adding it as an int would round every
                    // event's value down to the pound on compaction day.
                    $merged[$key][$column] += $decimalColumns[$column] ?? false
                        ? (float)$row[$column]
                        : (int)$row[$column];
                }

                if ($hasSketch) {
                    $sketches[$key][] = self::readBlob($row['uniques'] ?? null);
                }
            }

            // Now safe to replace: the day's existing total is inside $merged.
            $db->createCommand()->delete($table, [
                'siteId' => $siteId,
                'date' => $date,
                'hour' => self::DAILY_HOUR,
            ])->execute();

            foreach ($merged as $key => $row) {
                $row['hour'] = self::DAILY_HOUR;

                if ($table === Table::PAGES_ROLLUP) {
                    $row['elementId'] = self::elementIdFor($elementRows[$key]);
                }

                if ($hasSketch) {
                    // Tolerant on purpose: compaction rewrites a whole day at
                    // once, so one unreadable hourly sketch must not be able
                    // to stop the day — or every day after it — from ever
                    // compacting.
                    $sketch = Hll::mergeAll(
                        $sketches[$key],
                        $this->settings()->hllPrecision,
                        static fn(\InvalidArgumentException $e) => Craft::warning(
                            'Skipped an unreadable sketch while compacting ' . $table . ': ' . $e->getMessage(),
                            __METHOD__,
                        ),
                    );
                    $row['uniques'] = $sketches[$key] === []
                        ? null
                        : new \yii\db\PdoValue($sketch->serialize(), \PDO::PARAM_LOB);
                }

                unset($row['id']);
                $db->createCommand()->insert($table, $row)->execute();

                // The sketch on the row is only half the story. Drivers that
                // key their own storage (Redis, exact) recorded under the real
                // hours, and every reader from here on asks for `hour = -1` —
                // so unless their counters are folded too, this day's uniques
                // read zero from tonight onwards.
                $scopes = self::uniqueScopes($table, $grouped[$key]);

                if ($scopes !== null) {
                    [$daily, $hourly] = $scopes;
                    $this->counter()->compact($daily, $hourly);
                    array_push($foldedScopes, ...$hourly);
                }
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

        // Past the commit the fold is durable, so the hourly counters are just
        // storage now. Dropping them is cleanup: safe to skip, safe to redo.
        try {
            $this->counter()->discardCompacted($foldedScopes);
        } catch (\Throwable $e) {
            Craft::warning(
                'Compacted ' . $table . ' but could not drop the hourly unique counters: '
                . $e->getMessage() . ' The figures are correct; the counters expire on their own.',
                __METHOD__,
            );
        }
    }

    /**
     * The unique-counter scopes a compacted group covers: the daily one the
     * readers will ask for, and the hourly ones its counters are spread
     * across.
     *
     * Null for tables that keep no unique counters at all, which is every
     * table but pages and sessions.
     *
     * @param array<int,array<string,mixed>> $rows the hourly rows being merged
     * @return array{0: UniqueScope, 1: UniqueScope[]}|null
     */
    private static function uniqueScopes(string $table, array $rows): ?array
    {
        $kind = match ($table) {
            Table::PAGES_ROLLUP => UniqueScope::KIND_PAGE,
            Table::SESSIONS_ROLLUP => UniqueScope::KIND_SESSION,
            default => null,
        };

        if ($kind === null || $rows === []) {
            return null;
        }

        $siteId = (int)$rows[0]['siteId'];
        $date = (string)$rows[0]['date'];
        // Sessions are counted per site-hour with no dimension; pages are
        // counted per path. Must match what DbRollupSink recorded under.
        $dimId = $table === Table::PAGES_ROLLUP ? (int)$rows[0]['pathDimId'] : null;

        return [
            new UniqueScope($kind, $siteId, $date, self::DAILY_HOUR, $dimId),
            array_map(
                static fn(array $row) => new UniqueScope($kind, $siteId, $date, (int)$row['hour'], $dimId),
                array_values($rows),
            ),
        ];
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
            Table::EVENTS_ROLLUP => ['siteId', 'date', 'eventNameDimId', 'pathDimId'],
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
            Table::EVENTS_ROLLUP => ['count', 'sumValue'],
            default => ['sessions', 'bounces', 'totalDurationMs', 'totalPageviews'],
        };
    }

    /**
     * Which of a table's counters are decimals rather than integers.
     *
     * @return string[]
     */
    private static function decimalCounters(string $table): array
    {
        return $table === Table::EVENTS_ROLLUP ? ['sumValue'] : [];
    }

    private static function hasSketch(string $table): bool
    {
        return in_array($table, [Table::PAGES_ROLLUP, Table::SESSIONS_ROLLUP], true);
    }

    private function settings(): Settings
    {
        return $this->settings ??= Plugin::getInstance()->getSettings();
    }

    private function counter(): UniqueCounterInterface
    {
        return $this->counter ??= Plugin::getInstance()->getUniqueCounter();
    }

    private function db(): Connection
    {
        return $this->db ??= Craft::$app->getDb();
    }
}
