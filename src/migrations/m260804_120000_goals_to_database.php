<?php

namespace coyshdigital\craftanalytics\migrations;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\services\FunnelsService;
use coyshdigital\craftanalytics\services\GoalsService;
use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;

/**
 * Goals and funnels move out of project config and into the database.
 *
 * The tables already existed - project config was the source of truth and the
 * rows were a mirror maintained by its event handlers. So on nearly every site
 * this is a no-op: the mirror is already in step, and dropping the handlers is
 * the whole change.
 *
 * It exists for the sites where it is not. A definition can sit in project
 * config with no row behind it if the config was applied while the plugin was
 * disabled or before its tables existed, and with the handlers gone nothing
 * would ever mirror it - the goal would simply vanish from a screen it had
 * been on the day before.
 *
 * Nothing here writes to project config. `ProjectConfig::remove()` throws when
 * `allowAdminChanges` is off, which is exactly the production case this whole
 * change is for, so tidying up the old keys from a migration would fail the
 * update on every site that needed it most. The stale keys are inert once the
 * handlers are gone, and the CP says so until they are deleted.
 */
class m260804_120000_goals_to_database extends Migration
{
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        /** @var array<string,mixed> $goals */
        $goals = (array)($projectConfig->get(GoalsService::LEGACY_CONFIG_PATH) ?? []);
        /** @var array<string,mixed> $funnels */
        $funnels = (array)($projectConfig->get(FunnelsService::LEGACY_CONFIG_PATH) ?? []);

        foreach ($goals as $uid => $data) {
            if (is_array($data)) {
                $this->importGoal((string)$uid, $data);
            }
        }

        // Funnels second: a step names a goal, so every goal has to exist
        // before any step can resolve to one.
        foreach ($funnels as $uid => $data) {
            if (is_array($data)) {
                $this->importFunnel((string)$uid, $data);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Nothing to undo. The rows this may have written are the same rows the
        // project config handlers would have written, and the tables predate
        // this migration.
        return true;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function importGoal(string $uid, array $data): void
    {
        if ($this->rowExists(Table::GOALS, $uid)) {
            return;
        }

        // A handle is uniquely indexed. If config and the table disagree about
        // which uid owns a handle, the table wins - it is what every rollup row
        // already points at, and inserting would fail the whole migration.
        $handle = (string)($data['handle'] ?? '');

        if ($handle === '' || $this->handleExists(Table::GOALS, $handle)) {
            return;
        }

        $this->insert(Table::GOALS, [
            'uid' => $uid,
            'name' => (string)($data['name'] ?? ''),
            'handle' => $handle,
            'type' => (string)($data['type'] ?? ''),
            'target' => (string)($data['target'] ?? ''),
            'value' => (float)($data['value'] ?? 0),
            'enabled' => (bool)($data['enabled'] ?? true),
            'siteId' => isset($data['siteId']) ? (int)$data['siteId'] : null,
            'sortOrder' => (int)($data['sortOrder'] ?? 0),
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
        ]);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function importFunnel(string $uid, array $data): void
    {
        if ($this->rowExists(Table::FUNNELS, $uid)) {
            return;
        }

        $handle = (string)($data['handle'] ?? '');

        if ($handle === '' || $this->handleExists(Table::FUNNELS, $handle)) {
            return;
        }

        $this->insert(Table::FUNNELS, [
            'uid' => $uid,
            'name' => (string)($data['name'] ?? ''),
            'handle' => $handle,
            'siteId' => isset($data['siteId']) ? (int)$data['siteId'] : null,
            'enabled' => (bool)($data['enabled'] ?? true),
            'sortOrder' => (int)($data['sortOrder'] ?? 0),
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
        ]);

        $funnelId = (new Query())
            ->select(['id'])
            ->from([Table::FUNNELS])
            ->where(['uid' => $uid])
            ->scalar($this->db);

        if ($funnelId === false || $funnelId === null) {
            return;
        }

        $position = 1;

        foreach (array_values((array)($data['steps'] ?? [])) as $stepHandle) {
            $goalId = (new Query())
                ->select(['id'])
                ->from([Table::GOALS])
                ->where(['handle' => (string)$stepHandle])
                ->scalar($this->db);

            // A step naming a goal that is not there. Skipped rather than
            // guessed at, and said out loud: a funnel quietly missing a step
            // reports drop-off that never happened.
            if ($goalId === false || $goalId === null) {
                Craft::warning(
                    "Analytics funnel step “{$stepHandle}” refers to a goal that doesn’t exist; the step was skipped.",
                    __METHOD__,
                );
                continue;
            }

            $this->insert(Table::FUNNEL_STEPS, [
                'funnelId' => (int)$funnelId,
                'goalId' => (int)$goalId,
                'position' => $position++,
                'uid' => StringHelper::UUID(),
            ]);
        }
    }

    private function rowExists(string $table, string $uid): bool
    {
        return (new Query())->from([$table])->where(['uid' => $uid])->exists($this->db);
    }

    private function handleExists(string $table, string $handle): bool
    {
        return (new Query())->from([$table])->where(['handle' => $handle])->exists($this->db);
    }
}
