<?php

namespace coyshdigital\craftanalytics\controllers;

use coyshdigital\craftanalytics\enums\GoalType;
use coyshdigital\craftanalytics\models\Funnel;
use coyshdigital\craftanalytics\models\Goal;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Managing goal and funnel definitions.
 *
 * Separate from the reports controller because this is settings, not
 * analytics: it needs the settings permission, it writes project config, and
 * on a site with `allowAdminChanges` off it must refuse rather than pretend.
 */
class GoalsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_SETTINGS);

        if (!Plugin::getInstance()->is(Plugin::EDITION_PRO)) {
            throw new ForbiddenHttpException('Goals and funnels are a Craft Analytics Pro feature.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('craft-analytics/settings/goals/index.twig', [
            'goals' => $plugin->getGoals()->all(),
            'funnels' => $plugin->getFunnels()->all(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    public function actionEdit(?string $uid = null, ?Goal $goal = null): Response
    {
        // $goal arrives populated when this is a re-render after a failed
        // save, so the operator gets their form back rather than a blank one.
        if ($goal === null) {
            $goal = $uid !== null
                ? $this->goalByUid($uid)
                : new Goal();
        }

        return $this->renderTemplate('craft-analytics/settings/goals/edit.twig', [
            'goal' => $goal,
            'isNew' => $goal->id === null,
            'types' => array_map(
                static fn(GoalType $type): array => [
                    'label' => Craft::t('craft-analytics', $type->label()),
                    'value' => $type->value,
                    'hint' => Craft::t('craft-analytics', $type->targetHint()),
                ],
                GoalType::cases(),
            ),
            'sites' => Craft::$app->getSites()->getAllSites(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdminChanges();

        $uid = $this->request->getBodyParam('uid');
        $goal = $uid !== null && $uid !== '' ? $this->goalByUid((string)$uid) : new Goal();

        $goal->name = (string)$this->request->getBodyParam('name', '');
        $goal->handle = (string)$this->request->getBodyParam('handle', '');
        $goal->type = (string)$this->request->getBodyParam('type', GoalType::Url->value);
        $goal->target = trim((string)$this->request->getBodyParam('target', ''));
        $goal->value = (float)$this->request->getBodyParam('value', 0);
        $goal->enabled = (bool)$this->request->getBodyParam('enabled', true);

        $siteId = $this->request->getBodyParam('siteId');
        $goal->siteId = $siteId === '' || $siteId === null ? null : (int)$siteId;

        if (!Plugin::getInstance()->getGoals()->save($goal)) {
            $this->setFailFlash(Craft::t('craft-analytics', 'Couldn’t save goal.'));
            Craft::$app->getUrlManager()->setRouteParams(['goal' => $goal]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('craft-analytics', 'Goal saved.'));

        return $this->redirectToPostedUrl($goal);
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdminChanges();

        Plugin::getInstance()->getGoals()->deleteByUid((string)$this->request->getRequiredBodyParam('uid'));

        return $this->asSuccess(Craft::t('craft-analytics', 'Goal deleted.'));
    }

    public function actionReorder(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdminChanges();

        $uids = json_decode((string)$this->request->getRequiredBodyParam('ids'), true);
        Plugin::getInstance()->getGoals()->reorder(is_array($uids) ? $uids : []);

        return $this->asSuccess();
    }

    public function actionEditFunnel(?string $uid = null, ?Funnel $funnel = null): Response
    {
        if ($funnel === null) {
            $funnel = $uid !== null ? $this->funnelByUid($uid) : new Funnel();
        }

        return $this->renderTemplate('craft-analytics/settings/goals/funnel.twig', [
            'funnel' => $funnel,
            'isNew' => $funnel->id === null,
            'goals' => Plugin::getInstance()->getGoals()->all(),
            'sites' => Craft::$app->getSites()->getAllSites(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    public function actionSaveFunnel(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdminChanges();

        $uid = (string)($this->request->getBodyParam('uid') ?? '');
        $funnel = $uid !== '' ? $this->funnelByUid($uid) : new Funnel();

        $funnel->name = (string)$this->request->getBodyParam('name', '');
        $funnel->handle = (string)$this->request->getBodyParam('handle', '');
        $funnel->enabled = (bool)$this->request->getBodyParam('enabled', true);

        $siteId = $this->request->getBodyParam('siteId');
        $funnel->siteId = $siteId === '' || $siteId === null ? null : (int)$siteId;

        $funnel->steps = self::postedSteps($this->request->getBodyParam('steps'));

        if (!Plugin::getInstance()->getFunnels()->save($funnel)) {
            $this->setFailFlash(Craft::t('craft-analytics', 'Couldn’t save funnel.'));
            Craft::$app->getUrlManager()->setRouteParams(['funnel' => $funnel]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('craft-analytics', 'Funnel saved.'));

        return $this->redirectToPostedUrl($funnel);
    }

    public function actionDeleteFunnel(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdminChanges();

        Plugin::getInstance()->getFunnels()->deleteByUid((string)$this->request->getRequiredBodyParam('uid'));

        return $this->asSuccess(Craft::t('craft-analytics', 'Funnel deleted.'));
    }

    /**
     * The step list out of an editable table's rows.
     *
     * The table posts `steps[rowId][goal] = handle`; the ordering is the
     * order of the rows, which is what the drag handle rewrites. Empty rows
     * are how somebody removes a step mid-list, so they are dropped rather
     * than saved as a goal named "".
     *
     * @return array<int,string>
     */
    private static function postedSteps(mixed $posted): array
    {
        if (!is_array($posted)) {
            return [];
        }

        $steps = [];

        foreach ($posted as $row) {
            $handle = is_array($row) ? ($row['goal'] ?? '') : $row;
            $handle = trim((string)$handle);

            if ($handle !== '') {
                $steps[] = $handle;
            }
        }

        return $steps;
    }

    private function goalByUid(string $uid): Goal
    {
        foreach (Plugin::getInstance()->getGoals()->all() as $goal) {
            if ($goal->uid === $uid) {
                return $goal;
            }
        }

        throw new NotFoundHttpException('Goal not found.');
    }

    private function funnelByUid(string $uid): Funnel
    {
        foreach (Plugin::getInstance()->getFunnels()->all() as $funnel) {
            if ($funnel->uid === $uid) {
                return $funnel;
            }
        }

        throw new NotFoundHttpException('Funnel not found.');
    }

    /**
     * Project config is read-only when admin changes are off, which is the
     * norm in production. Refusing here is honest; letting the form save and
     * silently discarding it would not be.
     */
    private function requireAdminChanges(): void
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException(
                'Goals are stored in project config, which is read-only while allowAdminChanges is disabled.',
            );
        }
    }
}
