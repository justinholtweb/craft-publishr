<?php

declare(strict_types=1);

namespace justinholtweb\publishr\controllers;

use Craft;
use justinholtweb\publishr\models\Edition;
use justinholtweb\publishr\models\Policy;
use justinholtweb\publishr\Plugin;
use yii\web\ForbiddenHttpException;
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

        // Policies live in project config; see StagesController::beforeAction().
        if (in_array($action->id, ['save', 'reorder', 'delete'], true) && !Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException(Craft::t('publishr', 'Policies can’t be changed in this environment.'));
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
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
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
            'assignee' => $policy->assigneeId !== null
                ? Craft::$app->getUsers()->getUserById($policy->assigneeId)
                : null,
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'selectedTab' => 'policies',
        ]);
    }

    public function actionSave(): ?Response
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

            // Null, not a redirect: route params do not survive one, so the errors and everything
            // typed would be lost. Craft runs this URL's edit route with the model instead.
            Craft::$app->getUrlManager()->setRouteParams(['policy' => $policy]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('publishr', 'Policy saved.'));

        return $this->redirectToPostedUrl($policy);
    }

    /** First match wins, so the order is the policy. Craft's admin table posts row IDs. */
    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ids = (array)\craft\helpers\Json::decode($this->request->getRequiredBodyParam('ids'));
        $policies = $this->plugin()->policies;
        $uids = [];

        foreach ($ids as $id) {
            $policy = $policies->getPolicyById((int)$id);

            if ($policy !== null) {
                $uids[] = $policy->uid;
            }
        }

        $policies->reorderPolicies($uids);

        return $this->asSuccess(Craft::t('publishr', 'Policies reordered.'));
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
