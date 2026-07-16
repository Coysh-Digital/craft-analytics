<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\models\DateRange;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use craft\db\Query;
use yii\base\Component;
use yii\db\Connection;

/**
 * Reads the goal and funnel rollups.
 *
 * Conversion *rate* needs a denominator, and the honest one is sessions — a
 * goal converts once per session, so dividing by pageviews would understate
 * every rate on the site by however much people browse.
 */
class ConversionStatsService extends Component
{
    public ?Connection $db = null;
    public ?GoalsService $goalsService = null;
    public ?FunnelsService $funnelsService = null;

    /**
     * Every goal with its conversions for the range, including the ones that
     * converted nothing.
     *
     * A goal sitting at zero is a real answer — usually that the target is
     * wrong — and dropping it from the report is how that goes unnoticed for
     * a month.
     *
     * @return array<int,array{id: int, name: string, handle: string, type: string,
     *                         conversions: int, value: float, rate: float}>
     */
    public function goals(int $siteId, DateRange $range): array
    {
        $rows = (new Query())
            ->select([
                'goalId',
                'conversions' => 'SUM([[conversions]])',
                'value' => 'SUM([[value]])',
            ])
            ->from([Table::GOALS_ROLLUP])
            ->where(['siteId' => $siteId])
            ->andWhere(['between', 'date', $range->from, $range->to])
            ->groupBy(['goalId'])
            ->all($this->db());

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int)$row['goalId']] = [
                'conversions' => (int)$row['conversions'],
                'value' => (float)$row['value'],
            ];
        }

        $sessions = $this->sessions($siteId, $range);
        $report = [];

        foreach ($this->goalDefinitions()->enabledForSite($siteId) as $goal) {
            $found = $counts[$goal->id] ?? ['conversions' => 0, 'value' => 0.0];

            $report[] = [
                'id' => (int)$goal->id,
                'name' => $goal->name,
                'handle' => $goal->handle,
                'type' => $goal->goalType()->label(),
                'conversions' => $found['conversions'],
                'value' => $found['value'],
                'rate' => $sessions > 0 ? $found['conversions'] / $sessions * 100 : 0.0,
            ];
        }

        usort($report, static fn(array $a, array $b): int => $b['conversions'] <=> $a['conversions']);

        return $report;
    }

    /**
     * One funnel's steps with the drop-off between them.
     *
     * `sessions` is "reached at least this far", so drop-off is the difference
     * between neighbours — computed here rather than stored, because storing a
     * subtraction is how the two get to disagree.
     *
     * @return array<int,array{position: int, name: string, sessions: int,
     *                         dropOff: int, dropOffRate: float, ofFirst: float}>
     */
    public function funnel(int $siteId, DateRange $range, int $funnelId): array
    {
        $funnel = $this->funnelDefinitions()->getById($funnelId);

        if ($funnel === null) {
            return [];
        }

        $rows = (new Query())
            ->select(['position', 'sessions' => 'SUM([[sessions]])'])
            ->from([Table::FUNNEL_STEP_ROLLUP])
            ->where(['siteId' => $siteId, 'funnelId' => $funnelId])
            ->andWhere(['between', 'date', $range->from, $range->to])
            ->groupBy(['position'])
            ->all($this->db());

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int)$row['position']] = (int)$row['sessions'];
        }

        $first = $counts[1] ?? 0;
        $steps = [];
        $previous = null;

        foreach ($funnel->steps as $index => $handle) {
            $position = $index + 1;
            $sessions = $counts[$position] ?? 0;
            $goal = $this->goalDefinitions()->getByHandle($handle);
            $dropOff = $previous === null ? 0 : max(0, $previous - $sessions);

            $steps[] = [
                'position' => $position,
                'name' => $goal?->name ?? $handle,
                'sessions' => $sessions,
                'dropOff' => $dropOff,
                'dropOffRate' => $previous > 0 ? $dropOff / $previous * 100 : 0.0,
                'ofFirst' => $first > 0 ? $sessions / $first * 100 : 0.0,
            ];

            $previous = $sessions;
        }

        return $steps;
    }

    /**
     * Total sessions in the range — the denominator for a conversion rate.
     */
    public function sessions(int $siteId, DateRange $range): int
    {
        return (int)(new Query())
            ->from([Table::SESSIONS_ROLLUP])
            ->where(['siteId' => $siteId])
            ->andWhere(['between', 'date', $range->from, $range->to])
            ->sum('[[sessions]]', $this->db());
    }

    private function goalDefinitions(): GoalsService
    {
        return $this->goalsService ??= Plugin::getInstance()->getGoals();
    }

    private function funnelDefinitions(): FunnelsService
    {
        return $this->funnelsService ??= Plugin::getInstance()->getFunnels();
    }

    private function db(): Connection
    {
        return $this->db ??= Craft::$app->getDb();
    }
}
