<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\models\Funnel;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use craft\db\Query;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use yii\base\Component;
use yii\db\Connection;

/**
 * Funnel definitions, in project config for the same reasons goals are: they
 * are configuration, and they should arrive in production on deploy.
 *
 * Steps are stored as goal handles in config and mirrored to goal IDs in the
 * database, so a funnel survives being deployed to an environment where the
 * goals have different auto-increment IDs.
 */
class FunnelsService extends Component
{
    public const CONFIG_PATH = 'craftAnalytics.funnels';

    /**
     * Connection override; defaults to Craft's. Set in tests.
     */
    public ?Connection $db = null;

    /** @var Funnel[]|null */
    private ?array $funnels = null;

    public ?GoalsService $goals = null;

    /**
     * @return Funnel[]
     */
    public function all(): array
    {
        if ($this->funnels !== null) {
            return $this->funnels;
        }

        $rows = (new Query())
            ->select(['id', 'uid', 'name', 'handle', 'siteId', 'enabled', 'sortOrder'])
            ->from([Table::FUNNELS])
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
            ->all($this->db);

        $steps = $this->stepHandles();

        return $this->funnels = array_map(static function(array $row) use ($steps): Funnel {
            $funnel = new Funnel();
            $funnel->id = (int)$row['id'];
            $funnel->uid = (string)$row['uid'];
            $funnel->name = (string)$row['name'];
            $funnel->handle = (string)$row['handle'];
            $funnel->siteId = $row['siteId'] !== null ? (int)$row['siteId'] : null;
            $funnel->enabled = (bool)$row['enabled'];
            $funnel->sortOrder = (int)$row['sortOrder'];
            $funnel->steps = $steps[(int)$row['id']] ?? [];

            return $funnel;
        }, $rows);
    }

    /**
     * @return Funnel[]
     */
    public function enabledForSite(int $siteId): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn(Funnel $f): bool => $f->enabled && ($f->siteId === null || $f->siteId === $siteId),
        ));
    }

    public function getByHandle(string $handle): ?Funnel
    {
        foreach ($this->all() as $funnel) {
            if ($funnel->handle === $handle) {
                return $funnel;
            }
        }

        return null;
    }

    public function getById(int $id): ?Funnel
    {
        foreach ($this->all() as $funnel) {
            if ($funnel->id === $id) {
                return $funnel;
            }
        }

        return null;
    }

    public function save(Funnel $funnel, bool $runValidation = true): bool
    {
        if ($runValidation && !$funnel->validate()) {
            return false;
        }

        $isNew = $funnel->id === null;

        if ($funnel->uid === '') {
            $funnel->uid = $isNew || $funnel->id === null
                ? StringHelper::UUID()
                : (string)Db::uidById(Table::FUNNELS, $funnel->id);
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_PATH . '.' . $funnel->uid,
            $funnel->toConfig(),
            "Save the “{$funnel->handle}” analytics funnel",
        );

        if ($isNew) {
            $funnel->id = (int)Db::idByUid(Table::FUNNELS, $funnel->uid);
        }

        return true;
    }

    public function deleteByUid(string $uid): void
    {
        Craft::$app->getProjectConfig()->remove(self::CONFIG_PATH . '.' . $uid, 'Delete an analytics funnel');
    }

    /**
     * The uid that the changed config path matched.
     *
     * Craft passes token matches **numerically** — see the `array_values()`
     * in `ProjectConfig::_applyChanges()` — not keyed by the token's name.
     * Reading `$event->tokenMatches['uid']` therefore yields nothing, and with
     * a `?? ''` fallback every funnel silently collapses onto a single row with an
     * empty uid. That is precisely what happened before this method existed,
     * so it throws rather than defaulting.
     */
    private static function uidFor(ConfigEvent $event): string
    {
        return $event->tokenMatches[0]
            ?? throw new \UnexpectedValueException("No uid in the config path {$event->path}.");
    }

    /**
     * Mirrors a funnel from project config.
     *
     * The steps are written in a deferred pass. Craft applies config paths in
     * alphabetical order, so `craftAnalytics.funnels` lands *before*
     * `craftAnalytics.goals` — and a step written at that moment would find no
     * goal to point at and be silently dropped. Deferring runs it once the
     * whole change set has been applied, by which time the goals exist. (Found
     * by applying a funnel to a fresh environment and getting a funnel with
     * zero steps.)
     */
    public function handleChangedFunnel(ConfigEvent $event): void
    {
        $uid = self::uidFor($event);
        $data = $event->newValue;
        $db = Craft::$app->getDb();

        $id = (new Query())->select(['id'])->from([Table::FUNNELS])->where(['uid' => $uid])->scalar();

        $columns = [
            'name' => (string)($data['name'] ?? ''),
            'handle' => (string)($data['handle'] ?? ''),
            'siteId' => isset($data['siteId']) ? (int)$data['siteId'] : null,
            'enabled' => (bool)($data['enabled'] ?? true),
            'sortOrder' => (int)($data['sortOrder'] ?? 0),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
        ];

        if ($id !== false && $id !== null) {
            $db->createCommand()->update(Table::FUNNELS, $columns, ['id' => $id])->execute();
        } else {
            $db->createCommand()->insert(Table::FUNNELS, $columns + [
                'uid' => $uid,
                'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            ])->execute();
            $id = (new Query())->select(['id'])->from([Table::FUNNELS])->where(['uid' => $uid])->scalar();
        }

        $steps = array_values((array)($data['steps'] ?? []));
        $this->funnels = null;

        Craft::$app->getProjectConfig()->defer(
            $event,
            fn() => $this->saveSteps((int)$id, $steps),
        );
    }

    /**
     * @param array<int,mixed> $steps goal handles, in order
     */
    private function saveSteps(int $funnelId, array $steps): void
    {
        $db = Craft::$app->getDb();

        // Memoised reads would still be holding the goal list from before this
        // change set applied.
        $this->goalsService()->clearCaches();
        $goalIds = $this->goalsService()->idsByHandle();

        // Rewritten wholesale rather than diffed: a funnel's steps are a
        // sequence, and a partial update of a sequence is a broken sequence.
        $db->createCommand()->delete(Table::FUNNEL_STEPS, ['funnelId' => $funnelId])->execute();

        $position = 1;

        foreach ($steps as $handle) {
            $goalId = $goalIds[(string)$handle] ?? null;

            // A step naming a goal that doesn't exist. Skipped rather than
            // guessed at — but say so, because a funnel quietly missing a step
            // reports drop-off that never happened.
            if ($goalId === null) {
                Craft::warning(
                    "Analytics funnel step “{$handle}” refers to a goal that doesn’t exist; the step was skipped.",
                    __METHOD__,
                );
                continue;
            }

            $db->createCommand()->insert(Table::FUNNEL_STEPS, [
                'funnelId' => $funnelId,
                'goalId' => $goalId,
                'position' => $position++,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }

        $this->funnels = null;
    }

    public function handleDeletedFunnel(ConfigEvent $event): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete(Table::FUNNELS, ['uid' => self::uidFor($event)])
            ->execute();

        $this->funnels = null;
    }

    /**
     * The funnels as project config, for a `project-config/rebuild`. A plain
     * uid-keyed map — see GoalsService::rebuildConfig() for why it must not be
     * packed.
     *
     * @return array<string,mixed>
     */
    public function rebuildConfig(): array
    {
        $config = [];

        foreach ($this->all() as $funnel) {
            $config[$funnel->uid] = $funnel->toConfig();
        }

        return $config;
    }

    /**
     * @return array<int,array<int,string>> funnelId => [goal handle, …] in order
     */
    private function stepHandles(): array
    {
        $rows = (new Query())
            ->select(['s.funnelId', 's.position', 'g.handle'])
            ->from(['s' => Table::FUNNEL_STEPS])
            ->innerJoin(['g' => Table::GOALS], '[[g]].[[id]] = [[s]].[[goalId]]')
            ->orderBy(['s.funnelId' => SORT_ASC, 's.position' => SORT_ASC])
            ->all($this->db);

        $steps = [];

        foreach ($rows as $row) {
            $steps[(int)$row['funnelId']][] = (string)$row['handle'];
        }

        return $steps;
    }

    private function goalsService(): GoalsService
    {
        return $this->goals ??= Plugin::getInstance()->getGoals();
    }
}
