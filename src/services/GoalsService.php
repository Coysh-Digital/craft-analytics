<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\models\Goal;
use Craft;
use craft\db\Query;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use yii\base\Component;
use yii\db\Connection;

/**
 * Goal definitions, stored in project config.
 *
 * Project config is the source of truth: goals are configuration, they change
 * rarely, and they should arrive in production the same way a section does —
 * on deploy, reviewed, in version control. The database rows are a mirror
 * maintained by the config event handlers, so a report can join to a goal's
 * name without parsing YAML.
 *
 * Reads are memoised for the request, because the drain asks for the goal list
 * once per session it closes and this must not become a query per session.
 */
class GoalsService extends Component
{
    public const CONFIG_PATH = 'craftAnalytics.goals';

    /**
     * Connection override; defaults to Craft's. Set in tests.
     */
    public ?Connection $db = null;

    /** @var Goal[]|null */
    private ?array $goals = null;

    /**
     * Every goal, enabled or not, in display order.
     *
     * @return Goal[]
     */
    public function all(): array
    {
        if ($this->goals !== null) {
            return $this->goals;
        }

        $rows = (new Query())
            ->select(['id', 'uid', 'name', 'handle', 'type', 'target', 'value', 'enabled', 'siteId', 'sortOrder'])
            ->from([Table::GOALS])
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
            ->all($this->db);

        return $this->goals = array_map(static function(array $row): Goal {
            $goal = new Goal();
            $goal->id = (int)$row['id'];
            $goal->uid = (string)$row['uid'];
            $goal->name = (string)$row['name'];
            $goal->handle = (string)$row['handle'];
            $goal->type = (string)$row['type'];
            $goal->target = (string)$row['target'];
            $goal->value = (float)$row['value'];
            $goal->enabled = (bool)$row['enabled'];
            $goal->siteId = $row['siteId'] !== null ? (int)$row['siteId'] : null;
            $goal->sortOrder = (int)$row['sortOrder'];

            return $goal;
        }, $rows);
    }

    /**
     * Drops the memoised goal list, so the next read reflects the database.
     */
    public function clearCaches(): void
    {
        $this->goals = null;
    }

    /**
     * The goals that could convert on this site.
     *
     * @return Goal[]
     */
    public function enabledForSite(int $siteId): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn(Goal $goal): bool => $goal->appliesTo($siteId),
        ));
    }

    public function getByHandle(string $handle): ?Goal
    {
        foreach ($this->all() as $goal) {
            if ($goal->handle === $handle) {
                return $goal;
            }
        }

        return null;
    }

    public function getById(int $id): ?Goal
    {
        foreach ($this->all() as $goal) {
            if ($goal->id === $id) {
                return $goal;
            }
        }

        return null;
    }

    /**
     * @return array<string,int> handle => id, for the drain's rollup writes
     */
    public function idsByHandle(): array
    {
        $map = [];

        foreach ($this->all() as $goal) {
            if ($goal->id !== null) {
                $map[$goal->handle] = $goal->id;
            }
        }

        return $map;
    }

    /**
     * Saves a goal, via project config.
     *
     * Returns false on a validation failure rather than throwing, so the
     * controller can hand the model back to the form with its errors.
     */
    public function save(Goal $goal, bool $runValidation = true): bool
    {
        if ($runValidation && !$goal->validate()) {
            return false;
        }

        $isNew = $goal->id === null;

        if ($goal->uid === '') {
            $goal->uid = $isNew || $goal->id === null
                ? StringHelper::UUID()
                : (string)Db::uidById(Table::GOALS, $goal->id);
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_PATH . '.' . $goal->uid,
            $goal->toConfig(),
            "Save the “{$goal->handle}” analytics goal",
        );

        if ($isNew) {
            $goal->id = (int)Db::idByUid(Table::GOALS, $goal->uid);
        }

        return true;
    }

    public function deleteByUid(string $uid): void
    {
        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_PATH . '.' . $uid,
            'Delete an analytics goal',
        );
    }

    /**
     * @param string[] $uids
     */
    public function reorder(array $uids): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        foreach ($uids as $order => $uid) {
            $projectConfig->set(self::CONFIG_PATH . '.' . $uid . '.sortOrder', $order + 1, 'Reorder analytics goals');
        }
    }

    /**
     * The uid that the changed config path matched.
     *
     * Craft passes token matches **numerically** — see the `array_values()`
     * in `ProjectConfig::_applyChanges()` — not keyed by the token's name.
     * Reading `$event->tokenMatches['uid']` therefore yields nothing, and with
     * a `?? ''` fallback every goal silently collapses onto a single row with an
     * empty uid. That is precisely what happened before this method existed,
     * so it throws rather than defaulting.
     */
    private static function uidFor(ConfigEvent $event): string
    {
        return $event->tokenMatches[0]
            ?? throw new \UnexpectedValueException("No uid in the config path {$event->path}.");
    }

    /**
     * Mirrors a project config change into the goals table.
     */
    public function handleChangedGoal(ConfigEvent $event): void
    {
        $uid = self::uidFor($event);
        $data = $event->newValue;

        $id = (new Query())
            ->select(['id'])
            ->from([Table::GOALS])
            ->where(['uid' => $uid])
            ->scalar();

        $columns = [
            'name' => (string)($data['name'] ?? ''),
            'handle' => (string)($data['handle'] ?? ''),
            'type' => (string)($data['type'] ?? ''),
            'target' => (string)($data['target'] ?? ''),
            'value' => (float)($data['value'] ?? 0),
            'enabled' => (bool)($data['enabled'] ?? true),
            'siteId' => isset($data['siteId']) ? (int)$data['siteId'] : null,
            'sortOrder' => (int)($data['sortOrder'] ?? 0),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
        ];

        if ($id !== false && $id !== null) {
            Craft::$app->getDb()->createCommand()
                ->update(Table::GOALS, $columns, ['id' => $id])
                ->execute();
        } else {
            Craft::$app->getDb()->createCommand()
                ->insert(Table::GOALS, $columns + [
                    'uid' => $uid,
                    'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                ])
                ->execute();
        }

        $this->clearCaches();
    }

    /**
     * Deletes the mirrored row when a goal leaves project config.
     *
     * The rollup rows go with it, by cascade. That is deliberate: a goal's
     * conversions are only meaningful as *that goal's* conversions, and
     * orphaned counts nobody can name are worse than no counts at all. The CP
     * says so before the delete happens.
     */
    public function handleDeletedGoal(ConfigEvent $event): void
    {
        $uid = self::uidFor($event);

        Craft::$app->getDb()->createCommand()
            ->delete(Table::GOALS, ['uid' => $uid])
            ->execute();

        $this->clearCaches();
    }

    /**
     * The goals as project config, for a `project-config/rebuild`.
     *
     * Returned as a plain uid-keyed map. It must *not* be run through
     * `packAssociativeArrays()`: that is for associative arrays nested inside
     * a config value whose key order matters, and applying it to the outer map
     * collapses every goal onto a single empty key — which is exactly what a
     * rebuild did before this comment existed.
     *
     * @return array<string,mixed>
     */
    public function rebuildConfig(): array
    {
        $config = [];

        foreach ($this->all() as $goal) {
            $config[$goal->uid] = $goal->toConfig();
        }

        return $config;
    }
}
