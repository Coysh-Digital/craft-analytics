<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\enums\Channel;
use coyshdigital\craftanalytics\enums\DeviceType;
use coyshdigital\craftanalytics\models\DateRange;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\uniques\UniqueCounterInterface;
use coyshdigital\craftanalytics\uniques\UniqueScope;
use Craft;
use yii\base\Component;
use yii\db\Connection;
use yii\db\Query;

/**
 * Reads the rollups.
 *
 * Every query is a grouped aggregate over pre-aggregated rows, so a year of
 * traffic costs the same as a week of it. The one rule that isn't obvious:
 * **unique visitors are merged, never summed** — see uniquesFor().
 */
class StatsService extends Component
{
    public ?Connection $db = null;
    public ?UniqueCounterInterface $counter = null;

    /**
     * The headline numbers.
     *
     * @return array{views: int, uniques: int, sessions: int, bounceRate: float, avgDurationMs: int, avgViewsPerSession: float, avgDwellMs: int}
     */
    public function totals(int $siteId, DateRange $range): array
    {
        /** @var array{views: string|null, dwell: string|null}|null $pages */
        $pages = (new Query())
            ->select(['views' => 'SUM([[views]])', 'dwell' => 'SUM([[totalDwellMs]])'])
            ->from(Table::PAGES_ROLLUP)
            ->where(['siteId' => $siteId])
            ->andWhere(['between', 'date', $range->from, $range->to])
            ->one($this->db()) ?: null;

        /** @var array{sessions: string|null, bounces: string|null, duration: string|null, pageviews: string|null}|null $sessions */
        $sessions = (new Query())
            ->select([
                'sessions' => 'SUM([[sessions]])',
                'bounces' => 'SUM([[bounces]])',
                'duration' => 'SUM([[totalDurationMs]])',
                'pageviews' => 'SUM([[totalPageviews]])',
            ])
            ->from(Table::SESSIONS_ROLLUP)
            ->where(['siteId' => $siteId])
            ->andWhere(['between', 'date', $range->from, $range->to])
            ->one($this->db()) ?: null;

        $views = (int)($pages['views'] ?? 0);
        $sessionCount = (int)($sessions['sessions'] ?? 0);
        $bounces = (int)($sessions['bounces'] ?? 0);

        return [
            'views' => $views,
            'uniques' => $this->uniquesFor($siteId, $range),
            'sessions' => $sessionCount,
            'bounceRate' => $sessionCount > 0 ? $bounces / $sessionCount * 100 : 0.0,
            'avgDurationMs' => $sessionCount > 0 ? (int)round((int)($sessions['duration'] ?? 0) / $sessionCount) : 0,
            'avgViewsPerSession' => $sessionCount > 0 ? (int)($sessions['pageviews'] ?? 0) / $sessionCount : 0.0,
            'avgDwellMs' => $views > 0 ? (int)round((int)($pages['dwell'] ?? 0) / $views) : 0,
        ];
    }

    /**
     * Distinct visitors across a range.
     *
     * The sketches from every row in the range are **merged**, so a visitor
     * who appears on several days collapses to one. Summing the daily figures
     * — which is what a naive SUM() would do — would count them once per day.
     *
     * Note the honest limit this can't fix: Tier-1 hashes are re-salted every
     * 24 hours, so cross-day identity is destroyed by design and a multi-day
     * figure is on a daily-unique basis regardless. The merge still dedupes
     * correctly within each day and across hours. See docs/storage.md.
     */
    public function uniquesFor(int $siteId, DateRange $range, ?int $pathDimId = null): int
    {
        $counter = $this->counter();

        $query = (new Query())
            ->select(['uniques', 'date', 'hour', 'pathDimId'])
            ->from(Table::PAGES_ROLLUP)
            ->where(['siteId' => $siteId])
            ->andWhere(['between', 'date', $range->from, $range->to]);

        if ($pathDimId !== null) {
            $query->andWhere(['pathDimId' => $pathDimId]);
        }

        $scopes = [];
        $sketches = [];

        foreach ($query->all($this->db()) as $row) {
            $scopes[] = new UniqueScope(
                UniqueScope::KIND_PAGE,
                $siteId,
                (string)$row['date'],
                (int)$row['hour'],
                (int)$row['pathDimId'],
            );
            $sketches[] = self::readBlob($row['uniques']);
        }

        return $counter->estimate($scopes, $sketches);
    }

    /**
     * Views and uniques over time, with empty periods filled in as zero.
     *
     * @return array{labels: string[], views: int[], uniques: int[], hourly: bool}
     */
    public function trend(int $siteId, DateRange $range): array
    {
        if ($range->isHourly()) {
            return $this->hourlyTrend($siteId, $range);
        }

        $rows = (new Query())
            ->select(['date', 'views' => 'SUM([[views]])'])
            ->from(Table::PAGES_ROLLUP)
            ->where(['siteId' => $siteId])
            ->andWhere(['between', 'date', $range->from, $range->to])
            ->groupBy('date')
            ->all($this->db());

        $viewsByDate = [];
        foreach ($rows as $row) {
            $viewsByDate[(string)$row['date']] = (int)$row['views'];
        }

        $labels = [];
        $views = [];
        $uniques = [];

        foreach ($range->dates() as $date) {
            $labels[] = $date;
            $views[] = $viewsByDate[$date] ?? 0;
            // Per-day uniques are merged within the day — correct — and are
            // deliberately not merged across days (see uniquesFor()).
            $uniques[] = $this->uniquesForDate($siteId, $date);
        }

        return ['labels' => $labels, 'views' => $views, 'uniques' => $uniques, 'hourly' => false];
    }

    /**
     * @return array{labels: string[], views: int[], uniques: int[], hourly: bool}
     */
    private function hourlyTrend(int $siteId, DateRange $range): array
    {
        $rows = (new Query())
            ->select(['hour', 'views' => 'SUM([[views]])'])
            ->from(Table::PAGES_ROLLUP)
            ->where(['siteId' => $siteId, 'date' => $range->from])
            ->groupBy('hour')
            ->all($this->db());

        $byHour = [];
        foreach ($rows as $row) {
            $byHour[(int)$row['hour']] = (int)$row['views'];
        }

        $labels = [];
        $views = [];
        $uniques = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $labels[] = sprintf('%02d:00', $hour);
            // -1 is a compacted day: its whole total sits on one row, so an
            // hourly axis has nothing left to show.
            $views[] = $byHour[$hour] ?? 0;
            $uniques[] = 0;
        }

        if (isset($byHour[-1])) {
            return ['labels' => $labels, 'views' => array_fill(0, 24, 0), 'uniques' => $uniques, 'hourly' => true];
        }

        return ['labels' => $labels, 'views' => $views, 'uniques' => $uniques, 'hourly' => true];
    }

    private function uniquesForDate(int $siteId, string $date): int
    {
        $rows = (new Query())
            ->select(['uniques', 'hour', 'pathDimId'])
            ->from(Table::PAGES_ROLLUP)
            ->where(['siteId' => $siteId, 'date' => $date])
            ->all($this->db());

        $scopes = [];
        $sketches = [];

        foreach ($rows as $row) {
            $scopes[] = new UniqueScope(
                UniqueScope::KIND_PAGE,
                $siteId,
                $date,
                (int)$row['hour'],
                (int)$row['pathDimId'],
            );
            $sketches[] = self::readBlob($row['uniques']);
        }

        return $this->counter()->estimate($scopes, $sketches);
    }

    /**
     * @return array<int,array{path: string, elementId: int|null, views: int, entrances: int, exits: int, bounces: int, avgDwellMs: int, bounceRate: float}>
     */
    public function topPages(int $siteId, DateRange $range, int $limit = 100): array
    {
        $rows = (new Query())
            ->select([
                'path' => '[[d]].[[value]]',
                'elementId' => 'MAX([[p]].[[elementId]])',
                'views' => 'SUM([[p]].[[views]])',
                'entrances' => 'SUM([[p]].[[entrances]])',
                'exits' => 'SUM([[p]].[[exits]])',
                'bounces' => 'SUM([[p]].[[bounces]])',
                'dwell' => 'SUM([[p]].[[totalDwellMs]])',
            ])
            ->from(['p' => Table::PAGES_ROLLUP])
            ->innerJoin(['d' => Table::DIMENSIONS], '[[d]].[[id]] = [[p]].[[pathDimId]]')
            ->where(['[[p]].[[siteId]]' => $siteId])
            ->andWhere(['between', '[[p]].[[date]]', $range->from, $range->to])
            ->groupBy('[[d]].[[value]]')
            ->orderBy(['views' => SORT_DESC])
            ->limit($limit)
            ->all($this->db());

        return array_map(static function(array $row): array {
            $views = (int)$row['views'];
            $entrances = (int)$row['entrances'];

            return [
                'path' => (string)$row['path'],
                'elementId' => $row['elementId'] !== null ? (int)$row['elementId'] : null,
                'views' => $views,
                'entrances' => $entrances,
                'exits' => (int)$row['exits'],
                'bounces' => (int)$row['bounces'],
                'avgDwellMs' => $views > 0 ? (int)round((int)$row['dwell'] / $views) : 0,
                'bounceRate' => $entrances > 0 ? (int)$row['bounces'] / $entrances * 100 : 0.0,
            ];
        }, $rows);
    }

    /**
     * @return array<int,array{channel: string, host: string, sessions: int, bounces: int, bounceRate: float}>
     */
    public function sources(int $siteId, DateRange $range, int $limit = 100): array
    {
        $rows = (new Query())
            ->select([
                'channel' => '[[s]].[[channel]]',
                'host' => '[[d]].[[value]]',
                'sessions' => 'SUM([[s]].[[sessions]])',
                'bounces' => 'SUM([[s]].[[bounces]])',
            ])
            ->from(['s' => Table::SOURCES_ROLLUP])
            ->leftJoin(['d' => Table::DIMENSIONS], '[[d]].[[id]] = [[s]].[[refHostDimId]]')
            ->where(['[[s]].[[siteId]]' => $siteId])
            ->andWhere(['between', '[[s]].[[date]]', $range->from, $range->to])
            ->groupBy(['[[s]].[[channel]]', '[[d]].[[value]]'])
            ->orderBy(['sessions' => SORT_DESC])
            ->limit($limit)
            ->all($this->db());

        return array_map(static function(array $row): array {
            $sessions = (int)$row['sessions'];
            $channel = Channel::tryFrom((int)$row['channel']) ?? Channel::Direct;

            return [
                'channel' => $channel->label(),
                'host' => (string)($row['host'] ?? ''),
                'sessions' => $sessions,
                'bounces' => (int)$row['bounces'],
                'bounceRate' => $sessions > 0 ? (int)$row['bounces'] / $sessions * 100 : 0.0,
            ];
        }, $rows);
    }

    /**
     * Sessions grouped by channel — the top-level "where does traffic come
     * from" answer.
     *
     * @return array<int,array{channel: string, sessions: int}>
     */
    public function channels(int $siteId, DateRange $range): array
    {
        $rows = (new Query())
            ->select(['channel', 'sessions' => 'SUM([[sessions]])'])
            ->from(Table::SOURCES_ROLLUP)
            ->where(['siteId' => $siteId])
            ->andWhere(['between', 'date', $range->from, $range->to])
            ->groupBy('channel')
            ->orderBy(['sessions' => SORT_DESC])
            ->all($this->db());

        return array_map(static fn(array $row): array => [
            'channel' => (Channel::tryFrom((int)$row['channel']) ?? Channel::Direct)->label(),
            'sessions' => (int)$row['sessions'],
        ], $rows);
    }

    /**
     * @param 'browser'|'os'|'deviceType' $by
     * @return array<int,array{label: string, sessions: int}>
     */
    public function devices(int $siteId, DateRange $range, string $by = 'browser'): array
    {
        $query = (new Query())
            ->from(['v' => Table::DEVICES_ROLLUP])
            ->where(['[[v]].[[siteId]]' => $siteId])
            ->andWhere(['between', '[[v]].[[date]]', $range->from, $range->to])
            ->orderBy(['sessions' => SORT_DESC]);

        if ($by === 'deviceType') {
            $rows = $query
                ->select(['label' => '[[v]].[[deviceType]]', 'sessions' => 'SUM([[v]].[[sessions]])'])
                ->groupBy('[[v]].[[deviceType]]')
                ->all($this->db());

            return array_map(static fn(array $row): array => [
                'label' => (DeviceType::tryFrom((int)$row['label']) ?? DeviceType::Unknown)->label(),
                'sessions' => (int)$row['sessions'],
            ], $rows);
        }

        $column = $by === 'os' ? 'osDimId' : 'browserDimId';

        $rows = $query
            ->select(['label' => '[[d]].[[value]]', 'sessions' => 'SUM([[v]].[[sessions]])'])
            ->innerJoin(['d' => Table::DIMENSIONS], "[[d]].[[id]] = [[v]].[[$column]]")
            ->groupBy('[[d]].[[value]]')
            ->all($this->db());

        return array_map(static fn(array $row): array => [
            'label' => (string)$row['label'],
            'sessions' => (int)$row['sessions'],
        ], $rows);
    }

    /**
     * Visitors active right now.
     *
     * A read over the session hot layer — no database query at all, which is
     * a pleasant side-effect of keeping sessions in the cache (§6.2).
     *
     * @return array{visitors: int, pages: array<int,array{path: string, visitors: int}>}
     */
    public function realtime(int $siteId, ?int $now = null): array
    {
        $now ??= time();
        $sessions = Plugin::getInstance()->getSessions()->activeSessions($siteId, $now);

        $byPath = [];
        foreach ($sessions as $session) {
            $byPath[$session->lastPath] = ($byPath[$session->lastPath] ?? 0) + 1;
        }

        arsort($byPath);

        $pages = [];
        foreach (array_slice($byPath, 0, 10, true) as $path => $visitors) {
            $pages[] = ['path' => (string)$path, 'visitors' => $visitors];
        }

        return ['visitors' => count($sessions), 'pages' => $pages];
    }

    /**
     * Views for one element — what the entry sidebar and the element index
     * column both read.
     *
     * @return array{views: int, uniques: int, series: int[]}
     */
    public function elementStats(int $siteId, int $elementId, DateRange $range): array
    {
        $rows = (new Query())
            ->select(['date', 'views' => 'SUM([[views]])'])
            ->from(Table::PAGES_ROLLUP)
            ->where(['siteId' => $siteId, 'elementId' => $elementId])
            ->andWhere(['between', 'date', $range->from, $range->to])
            ->groupBy('date')
            ->all($this->db());

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string)$row['date']] = (int)$row['views'];
        }

        $series = [];
        foreach ($range->dates() as $date) {
            $series[] = $byDate[$date] ?? 0;
        }

        return [
            'views' => array_sum($series),
            'uniques' => $this->elementUniques($siteId, $elementId, $range),
            'series' => $series,
        ];
    }

    /**
     * Views per element for a set of elements — one query for a whole element
     * index page, rather than one per row.
     *
     * @param int[] $elementIds
     * @return array<int,int> elementId => views
     */
    public function viewsByElement(int $siteId, array $elementIds, DateRange $range): array
    {
        if ($elementIds === []) {
            return [];
        }

        $rows = (new Query())
            ->select(['elementId', 'views' => 'SUM([[views]])'])
            ->from(Table::PAGES_ROLLUP)
            ->where(['siteId' => $siteId, 'elementId' => $elementIds])
            ->andWhere(['between', 'date', $range->from, $range->to])
            ->groupBy('elementId')
            ->all($this->db());

        $views = [];
        foreach ($rows as $row) {
            $views[(int)$row['elementId']] = (int)$row['views'];
        }

        return $views;
    }

    private function elementUniques(int $siteId, int $elementId, DateRange $range): int
    {
        $rows = (new Query())
            ->select(['uniques', 'date', 'hour', 'pathDimId'])
            ->from(Table::PAGES_ROLLUP)
            ->where(['siteId' => $siteId, 'elementId' => $elementId])
            ->andWhere(['between', 'date', $range->from, $range->to])
            ->all($this->db());

        $scopes = [];
        $sketches = [];

        foreach ($rows as $row) {
            $scopes[] = new UniqueScope(
                UniqueScope::KIND_PAGE,
                $siteId,
                (string)$row['date'],
                (int)$row['hour'],
                (int)$row['pathDimId'],
            );
            $sketches[] = self::readBlob($row['uniques']);
        }

        return $this->counter()->estimate($scopes, $sketches);
    }

    /**
     * How the unique figure was produced, so the UI can say so rather than
     * presenting an estimate as a count.
     */
    public function uniquesAccuracy(): string
    {
        return $this->counter()->accuracy();
    }

    private static function readBlob(mixed $value): ?string
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        return is_resource($value) ? (string)stream_get_contents($value) : (string)$value;
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
