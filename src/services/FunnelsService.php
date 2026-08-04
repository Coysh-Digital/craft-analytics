<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\models\Funnel;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use yii\base\Component;
use yii\db\Connection;

/**
 * Funnel definitions, in the database for the same reasons goals are - see
 * GoalsService for why they moved out of project config.
 *
 * A step is held as a goal ID, and read back out as a handle: the model talks
 * in handles because that is what the CP form posts and what a funnel means,
 * and the table stores the ID because that is what the rollup joins to.
 */
class FunnelsService extends Component
{
    /**
     * Where funnels used to be kept. Retained only so the migration can find
     * definitions that nothing reads any more.
     */
    public const LEGACY_CONFIG_PATH = 'craftAnalytics.funnels';

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

    /**
     * Saves a funnel and its steps.
     *
     * Both in one transaction: a funnel's steps are a sequence, and a funnel
     * left holding half of one reports drop-off that never happened.
     *
     * Returns false on a validation failure rather than throwing, so the
     * controller can hand the model back to the form with its errors.
     */
    public function save(Funnel $funnel, bool $runValidation = true): bool
    {
        if ($runValidation && !$funnel->validate()) {
            return false;
        }

        // Uniquely indexed, same as a goal's - see GoalsService::save().
        if ($this->handleTaken($funnel)) {
            $funnel->addError('handle', Craft::t('craft-analytics', 'That handle is already in use by another funnel.'));

            return false;
        }

        // saveSteps() skips a step that names no goal, which is right for a
        // one-way import but wrong for a form: the funnel would save, look
        // saved, and then report drop-off across a step that isn't there. The
        // model cannot check this itself - it has no database.
        $this->goalsService()->clearCaches();
        $unknown = array_diff($funnel->steps, array_keys($this->goalsService()->idsByHandle()));

        if ($unknown !== []) {
            $funnel->addError('steps', Craft::t('craft-analytics', 'No goal named “{handle}”.', [
                'handle' => reset($unknown),
            ]));

            return false;
        }

        $db = $this->connection();
        $isNew = $funnel->id === null;
        $transaction = $db->beginTransaction();

        try {
            if ($isNew) {
                if ($funnel->uid === '') {
                    $funnel->uid = StringHelper::UUID();
                }

                $db->createCommand()
                    ->insert(Table::FUNNELS, self::columnsFor($funnel) + [
                        'uid' => $funnel->uid,
                        'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                    ])
                    ->execute();

                $funnel->id = (int)$db->getLastInsertID(Table::FUNNELS);
            } else {
                $db->createCommand()
                    ->update(Table::FUNNELS, self::columnsFor($funnel), ['id' => $funnel->id])
                    ->execute();
            }

            $this->saveSteps((int)$funnel->id, $funnel->steps);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        $this->funnels = null;

        return true;
    }

    public function deleteByUid(string $uid): void
    {
        $this->connection()->createCommand()
            ->delete(Table::FUNNELS, ['uid' => $uid])
            ->execute();

        $this->funnels = null;
    }

    /**
     * The stored shape of a funnel, without its steps and without the columns
     * only an insert sets.
     *
     * @return array<string,mixed>
     */
    public static function columnsFor(Funnel $funnel): array
    {
        return [
            'name' => $funnel->name,
            'handle' => $funnel->handle,
            'siteId' => $funnel->siteId,
            'enabled' => $funnel->enabled,
            'sortOrder' => $funnel->sortOrder,
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
        ];
    }

    /**
     * Writes a funnel's steps, resolving goal handles to IDs.
     *
     * Returns the handles it could not place, so the caller can say so: a
     * funnel quietly missing a step reports drop-off that never happened, and
     * a warning in a log file is not where the person who just saved the form
     * is looking.
     *
     * @param array<int,mixed> $steps goal handles, in order
     * @return string[] the handles that named no goal and were skipped
     */
    public function saveSteps(int $funnelId, array $steps): array
    {
        $db = $this->connection();

        // A goal saved earlier in this request would otherwise be resolved
        // against the list as it was before that save - which is how a brand
        // new goal used as a step gets silently skipped.
        $this->goalsService()->clearCaches();
        $goalIds = $this->goalsService()->idsByHandle();

        // Rewritten wholesale rather than diffed: a funnel's steps are a
        // sequence, and a partial update of a sequence is a broken sequence.
        $db->createCommand()->delete(Table::FUNNEL_STEPS, ['funnelId' => $funnelId])->execute();

        $position = 1;
        $skipped = [];

        foreach ($steps as $handle) {
            $goalId = $goalIds[(string)$handle] ?? null;

            if ($goalId === null) {
                $skipped[] = (string)$handle;
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

        return $skipped;
    }

    /**
     * Whether any *other* funnel already holds this handle.
     */
    private function handleTaken(Funnel $funnel): bool
    {
        $query = (new Query())
            ->from([Table::FUNNELS])
            ->where(['handle' => $funnel->handle]);

        if ($funnel->id !== null) {
            $query->andWhere(['not', ['id' => $funnel->id]]);
        }

        return $query->exists($this->db);
    }

    private function connection(): Connection
    {
        return $this->db ?? Craft::$app->getDb();
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
