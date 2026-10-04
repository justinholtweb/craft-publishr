<?php

declare(strict_types=1);

namespace justinholtweb\publishr\controllers;

use Craft;
use justinholtweb\publishr\models\Edition;
use justinholtweb\publishr\models\Stage;
use justinholtweb\publishr\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class StagesController extends BaseController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Craft::$app->getUser()->getIsAdmin()) {
            $this->requirePermission(Plugin::PERMISSION_SETTINGS);
        }

        // Stages live in project config. Changed where admin changes are off, they drift from the
        // repo's YAML and the next deploy reverts them — or fails on a duplicate handle.
        if (in_array($action->id, ['save', 'reorder', 'delete'], true) && !Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException(Craft::t('publishr', 'Stages can’t be changed in this environment.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = $this->plugin();

        return $this->renderTemplate('publishr/settings/stages/index', [
            'title' => Craft::t('publishr', 'Stages'),
            'stages' => $plugin->stages->getAllStages(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'counts' => $plugin->items->countsByStage(),
            'canCreate' => $plugin->stages->canCreateStage(),
            'maxStages' => Edition::maxStages($plugin->isPro()),
            'selectedTab' => 'stages',
        ]);
    }

    public function actionEdit(?int $stageId = null, ?Stage $stage = null): Response
    {
        $plugin = $this->plugin();

        // The model comes back from a failed save through the route params. Rebuilding it from the
        // ID here would throw away everything the person typed.
        $stage ??= $stageId !== null ? $plugin->stages->getStageById($stageId) : new Stage();

        if ($stage === null) {
            throw new NotFoundHttpException('No such stage.');
        }

        return $this->renderTemplate('publishr/settings/stages/edit', [
            'title' => $stage->id !== null ? $stage->name : Craft::t('publishr', 'New stage'),
            'stage' => $stage,
            'isNew' => $stage->id === null,
            'colors' => Stage::COLORS,
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'selectedTab' => 'stages',
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = $this->plugin();
        $id = $this->request->getBodyParam('stageId');

        $stage = $id ? $plugin->stages->getStageById((int)$id) : new Stage();

        if ($stage === null) {
            throw new NotFoundHttpException('No such stage.');
        }

        $stage->name = (string)$this->request->getBodyParam('name', $stage->name);
        $stage->handle = (string)$this->request->getBodyParam('handle', $stage->handle);
        $stage->description = $this->request->getBodyParam('description') ?: null;
        $stage->color = (string)$this->request->getBodyParam('color', $stage->color);

        // Craft's colour picker posts its "no colour" option as a sentinel. A stage always has one.
        if ($stage->color === '' || $stage->color === '__blank__') {
            $stage->color = 'gray';
        }
        $stage->isDefault = (bool)$this->request->getBodyParam('isDefault', false);
        $stage->isPublished = (bool)$this->request->getBodyParam('isPublished', false);
        $stage->gated = (bool)$this->request->getBodyParam('gated', false);

        if (!$plugin->stages->saveStage($stage)) {
            $this->setFailFlash(Craft::t('publishr', 'Couldn’t save that stage.'));

            // Null, not a render or a redirect: Craft then runs the edit route for this URL with
            // the model in hand, so the screen comes back whole — title, errors and typed values.
            Craft::$app->getUrlManager()->setRouteParams(['stage' => $stage]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('publishr', 'Stage saved.'));

        return $this->redirectToPostedUrl($stage);
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        // Craft's admin table posts row IDs; the service orders project config, which is keyed on
        // UIDs. Unknown IDs are dropped rather than trusted.
        $ids = (array)\craft\helpers\Json::decode($this->request->getRequiredBodyParam('ids'));
        $stages = $this->plugin()->stages;
        $uids = [];

        foreach ($ids as $id) {
            $stage = $stages->getStageById((int)$id);

            if ($stage !== null) {
                $uids[] = $stage->uid;
            }
        }

        $stages->reorderStages($uids);

        return $this->asSuccess(Craft::t('publishr', 'Stages reordered.'));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)$this->request->getRequiredBodyParam('id');
        $stage = $this->plugin()->stages->getStageById($id);

        if ($stage === null) {
            return $this->asFailure(Craft::t('publishr', 'No such stage.'));
        }

        if (!$this->plugin()->stages->deleteStage($stage)) {
            return $this->asFailure(implode(' ', $stage->getFirstErrors()));
        }

        return $this->asSuccess(Craft::t('publishr', 'Stage deleted.'));
    }
}
