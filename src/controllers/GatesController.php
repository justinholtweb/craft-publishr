<?php

declare(strict_types=1);

namespace justinholtweb\publishr\controllers;

use Craft;
use justinholtweb\publishr\models\Edition;
use justinholtweb\publishr\models\Gate;
use justinholtweb\publishr\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class GatesController extends BaseController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Craft::$app->getUser()->getIsAdmin()) {
            $this->requirePermission(Plugin::PERMISSION_SETTINGS);
        }

        // Requirements live in project config; see StagesController::beforeAction().
        if (in_array($action->id, ['save', 'delete'], true) && !Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException(Craft::t('publishr', 'Requirements can’t be changed in this environment.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = $this->plugin();

        return $this->renderTemplate('publishr/settings/gates/index', [
            'title' => Craft::t('publishr', 'Publish requirements'),
            'gates' => $plugin->gates->getAllGates(),
            'types' => $plugin->gates->describeTypes(),
            'allowed' => Edition::allowsGates($plugin->isPro()),
            'stages' => $plugin->stages->getAllStages(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'selectedTab' => 'gates',
        ]);
    }

    public function actionEdit(?int $gateId = null, ?Gate $gate = null): Response
    {
        $plugin = $this->plugin();

        $gate ??= $gateId !== null ? $plugin->gates->getGateById($gateId) : new Gate([
            'type' => (string)$this->request->getParam('type', ''),
        ]);

        if ($gate === null) {
            throw new NotFoundHttpException('No such requirement.');
        }

        $class = $gate->type !== '' ? $plugin->gates->getType($gate->type) : null;
        $described = $gate->type !== '' ? $plugin->gates->describeType($gate->type) : null;

        return $this->renderTemplate('publishr/settings/gates/edit', [
            'title' => $gate->id !== null ? $gate->name : Craft::t('publishr', 'New requirement'),
            'gate' => $gate,
            'isNew' => $gate->id === null,
            'types' => $plugin->gates->describeTypes(),
            'typeSettingsHtml' => $class !== null ? $class::settingsHtml($gate->settings) : '',
            'typeAvailable' => $described === null || $described['available'],
            'unavailableReason' => $described['unavailableReason'] ?? null,
            'sections' => Craft::$app->getEntries()->getAllSections(),
            'stages' => $plugin->stages->getAllStages(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'selectedTab' => 'gates',
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = $this->plugin();
        $id = $this->request->getBodyParam('gateId');
        $gate = $id ? $plugin->gates->getGateById((int)$id) : new Gate();

        if ($gate === null) {
            throw new NotFoundHttpException('No such requirement.');
        }

        $gate->name = (string)$this->request->getBodyParam('name', $gate->name);
        $gate->handle = (string)$this->request->getBodyParam('handle', $gate->handle);
        $gate->type = (string)$this->request->getBodyParam('type', $gate->type);
        $gate->description = $this->request->getBodyParam('description') ?: null;
        $gate->severity = (string)$this->request->getBodyParam('severity', Gate::SEVERITY_REQUIRED);
        $gate->enabled = (bool)$this->request->getBodyParam('enabled', true);
        $gate->sectionUids = array_values(array_filter((array)$this->request->getBodyParam('sectionUids', [])));
        $gate->stageHandles = array_values(array_filter((array)$this->request->getBodyParam('stageHandles', [])));
        $gate->settings = $this->normalizeSettings((array)$this->request->getBodyParam('settings', []));

        if (!$plugin->gates->saveGate($gate)) {
            $this->setFailFlash(Craft::t('publishr', 'Couldn’t save that requirement.'));

            // Null, not a redirect: route params do not survive one, so the errors and everything
            // typed would be lost. Craft runs this URL's edit route with the model instead.
            Craft::$app->getUrlManager()->setRouteParams(['gate' => $gate]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('publishr', 'Requirement saved.'));

        return $this->redirectToPostedUrl($gate);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ok = $this->plugin()->gates->deleteGateById((int)$this->request->getRequiredBodyParam('id'));

        return $ok
            ? $this->asSuccess(Craft::t('publishr', 'Requirement deleted.'))
            : $this->asFailure(Craft::t('publishr', 'No such requirement.'));
    }

    /**
     * Turn the settings form's strings into the shapes the gate types expect.
     *
     * The textareas are the whole reason this exists: a list of field handles arrives as one blob
     * with `\r\n` in it from a Windows browser, and a gate that compared `"title\r"` against
     * `"title"` would report a field missing that is right there on the entry.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function normalizeSettings(array $settings): array
    {
        foreach (['handles', 'items'] as $key) {
            if (!isset($settings[$key])) {
                continue;
            }

            $lines = is_array($settings[$key])
                ? $settings[$key]
                : preg_split('/\R/u', (string)$settings[$key]);

            $settings[$key] = array_values(array_filter(array_map('trim', $lines ?: []), static fn($line) => $line !== ''));
        }

        foreach (['min'] as $key) {
            if (isset($settings[$key])) {
                $settings[$key] = (int)$settings[$key];
            }
        }

        foreach (['requireAlt', 'future'] as $key) {
            if (isset($settings[$key])) {
                $settings[$key] = (bool)$settings[$key];
            }
        }

        return $settings;
    }
}
