<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\models\Goal;
use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use yii\base\Component;
use yii\db\Connection;

/**
 * Goal definitions, stored in the database.
 *
 * They used to live in project config, on the reasoning that a goal is
 * configuration and should arrive in production on deploy like a section does.
 * That reasoning held for the object and not for the people: project config is
 * read-only wherever `allowAdminChanges` is off, which is every production
 * site, so the person who wanted a goal could not add one and the person who
 * could was a deploy away. A goal is closer to a saved report than to a
 * section. It lives in the database, and every environment owns its own.
 *
 * The consequence, which is real: goals no longer travel on deploy. One created
 * in staging will not appear in production.
 *
 * Reads are memoised for the request, because the drain asks for the goal list
 * once per session it closes and this must not become a query per session.
 */
class GoalsService extends Component
{
    /**
     * Where goals used to be kept. Retained only so the migration and the CP
     * can tell whether a site still has definitions sitting in project config
     * that nothing reads any more.
     */
    public const LEGACY_CONFIG_PATH = 'craftAnalytics.goals';

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
     * Saves a goal.
     *
     * Returns false on a validation failure rather than throwing, so the
     * controller can hand the model back to the form with its errors.
     */
    public function save(Goal $goal, bool $runValidation = true): bool
    {
        if ($runValidation && !$goal->validate()) {
            return false;
        }

        // The handle is uniquely indexed, and it is what a funnel step and the
        // drain's rollup writes point at. Catching it here turns what would be
        // an integrity-constraint 500 into the form saying which field is
        // wrong - and creating goals in production is exactly when a handle
        // somebody else already used starts being easy to pick.
        if ($this->handleTaken($goal)) {
            $goal->addError('handle', Craft::t('craft-analytics', 'That handle is already in use by another goal.'));

            return false;
        }

        $db = $this->connection();
        $isNew = $goal->id === null;

        if ($isNew) {
            if ($goal->uid === '') {
                $goal->uid = StringHelper::UUID();
            }

            $db->createCommand()
                ->insert(Table::GOALS, self::columnsFor($goal) + [
                    'uid' => $goal->uid,
                    'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                ])
                ->execute();

            $goal->id = (int)$db->getLastInsertID(Table::GOALS);
        } else {
            $db->createCommand()
                ->update(Table::GOALS, self::columnsFor($goal), ['id' => $goal->id])
                ->execute();
        }

        $this->clearCaches();

        return true;
    }

    /**
     * Deletes a goal.
     *
     * The rollup rows go with it, by cascade. That is deliberate: a goal's
     * conversions are only meaningful as *that goal's* conversions, and
     * orphaned counts nobody can name are worse than no counts at all. The CP
     * says so before the delete happens.
     */
    public function deleteByUid(string $uid): void
    {
        $this->connection()->createCommand()
            ->delete(Table::GOALS, ['uid' => $uid])
            ->execute();

        $this->clearCaches();
    }

    /**
     * @param string[] $uids
     */
    public function reorder(array $uids): void
    {
        $db = $this->connection();
        $transaction = $db->beginTransaction();

        try {
            foreach ($uids as $order => $uid) {
                $db->createCommand()
                    ->update(Table::GOALS, ['sortOrder' => $order + 1], ['uid' => $uid])
                    ->execute();
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        $this->clearCaches();
    }

    /**
     * The stored shape of a goal, without the columns only an insert sets.
     *
     * @return array<string,mixed>
     */
    public static function columnsFor(Goal $goal): array
    {
        return [
            'name' => $goal->name,
            'handle' => $goal->handle,
            'type' => $goal->type,
            'target' => $goal->target,
            'value' => $goal->value,
            'enabled' => $goal->enabled,
            'siteId' => $goal->siteId,
            'sortOrder' => $goal->sortOrder,
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
        ];
    }

    /**
     * Whether any *other* goal already holds this handle.
     */
    private function handleTaken(Goal $goal): bool
    {
        $query = (new Query())
            ->from([Table::GOALS])
            ->where(['handle' => $goal->handle]);

        if ($goal->id !== null) {
            $query->andWhere(['not', ['id' => $goal->id]]);
        }

        return $query->exists($this->db);
    }

    private function connection(): Connection
    {
        return $this->db ?? Craft::$app->getDb();
    }
}
