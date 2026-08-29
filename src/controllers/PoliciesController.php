<?php

declare(strict_types=1);

namespace justinholtweb\publishr\controllers;

use Craft;
use justinholtweb\publishr\models\Edition;
use justinholtweb\publishr\models\Policy;
use justinholtweb\publishr\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class PoliciesController extends BaseController
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

        return $this->renderTemplate('publishr/settings/policies/index', [
            'title' => Craft::t('publishr', 'Freshness policies'),
            'policies' => $plugin->policies->getAllPolicies(),
            'allowed' => Edition::allowsFreshness($plugin->isPro()),
            'summary' => $plugin->freshness->summary(),
            'selectedTab' => 'policies',
        ]);
    }

    public function actionEdit(?int $policyId = null, ?Policy $policy = null): Response
    {
        $plugin = $this->plugin();

        $policy ??= $policyId !== null ? $plugin->policies->getPolicyById($policyId) : new Policy();

        if ($policy === null) {
            throw new NotFoundHttpException('No such policy.');
        }

        return $this->renderTemplate('publishr/settings/policies/edit', [
            'title' => $policy->id !== null ? $policy->name : Craft::t('publishr', 'New policy'),
            'policy' => $policy,
            'isNew' => $policy->id === null,
            'sections' => Craft::$app->getEntries()->getAllSections(),
            'entryTypes' => Craft::$app->getEntries()->getAllEntryTypes(),
            'selectedTab' => 'policies',
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $plugin = $this->plugin();
        $id = $this->request->getBodyParam('policyId');
        $policy = $id ? $plugin->policies->getPolicyById((int)$id) : new Policy();

        if ($policy === null) {
            throw new NotFoundHttpException('No such policy.');
        }

        $policy->name = (string)$this->request->getBodyParam('name', $policy->name);
        $policy->handle = (string)$this->request->getBodyParam('handle', $policy->handle);
        $policy->sectionUids = array_values(array_filter((array)$this->request->getBodyParam('sectionUids', [])));
        $policy->entryTypeUids = array_values(array_filter((array)$this->request->getBodyParam('entryTypeUids', [])));
        $policy->intervalDays = (int)$this->request->getBodyParam('intervalDays', 180);
        $policy->remindDaysBefore = (int)$this->request->getBodyParam('remindDaysBefore', 14);
        $policy->assignTo = (string)$this->request->getBodyParam('assignTo', Policy::ASSIGN_CURRENT);
        $policy->enabled = (bool)$this->request->getBodyParam('enabled', true);

        $assignee = $this->request->getBodyParam('assigneeId');

        if (is_array($assignee)) {
            $assignee = $assignee[0] ?? null;
        }

        $policy->assigneeId = $assignee ? (int)$assignee : null;

        if (!$plugin->policies->savePolicy($policy)) {
            $this->setFailFlash(Craft::t('publishr', 'Couldn’t save that policy.'));

            Craft::$app->getUrlManager()->setRouteParams(['policy' => $policy]);

            return $this->redirect($this->request->getReferrer() ?? 'publishr/settings/policies');
        }

        $this->setSuccessFlash(Craft::t('publishr', 'Policy saved.'));

        return $this->redirectToPostedUrl($policy);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ok = $this->plugin()->policies->deletePolicyById((int)$this->request->getRequiredBodyParam('id'));

        return $ok
            ? $this->asSuccess(Craft::t('publishr', 'Policy deleted.'))
            : $this->asFailure(Craft::t('publishr', 'No such policy.'));
    }
}
