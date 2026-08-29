<?php

declare(strict_types=1);

namespace justinholtweb\publishr\controllers;

use Craft;
use justinholtweb\publishr\models\Edition;
use justinholtweb\publishr\models\Stage;
use justinholtweb\publishr\Plugin;
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

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = $this->plugin();

        return $this->renderTemplate('publishr/settings/stages/index', [
            'title' => Craft::t('publishr', 'Stages'),
            'stages' => $plugin->stages->getAllStages(),
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
            'selectedTab' => 'stages',
        ]);
    }

    public function actionSave(): Response
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
        $stage->isDefault = (bool)$this->request->getBodyParam('isDefault', false);
        $stage->isPublished = (bool)$this->request->getBodyParam('isPublished', false);
        $stage->gated = (bool)$this->request->getBodyParam('gated', false);

        if (!$plugin->stages->saveStage($stage)) {
            $this->setFailFlash(Craft::t('publishr', 'Couldn’t save that stage.'));

            Craft::$app->getUrlManager()->setRouteParams(['stage' => $stage]);

            return $this->renderTemplate('publishr/settings/stages/edit', [
                'stage' => $stage,
                'isNew' => $stage->id === null,
                'colors' => Stage::COLORS,
            ]);
        }

        $this->setSuccessFlash(Craft::t('publishr', 'Stage saved.'));

        return $this->redirectToPostedUrl($stage);
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $uids = \craft\helpers\Json::decode($this->request->getRequiredBodyParam('ids'));

        $this->plugin()->stages->reorderStages((array)$uids);

        return $this->asSuccess();
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
