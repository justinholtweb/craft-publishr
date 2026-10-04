<?php

declare(strict_types=1);

namespace justinholtweb\publishr\controllers;

use Craft;
use justinholtweb\publishr\integrations\AlarmClock;
use justinholtweb\publishr\integrations\RedPen;
use justinholtweb\publishr\models\CalendarEvent;
use justinholtweb\publishr\models\Edition;
use justinholtweb\publishr\models\Settings;
use justinholtweb\publishr\Plugin;
use justinholtweb\publishr\services\Notifications;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class SettingsController extends BaseController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Craft::$app->getUser()->getIsAdmin()) {
            $this->requirePermission(Plugin::PERMISSION_SETTINGS);
        }

        // Plugin settings are project config. Written on an environment that does not allow admin
        // changes, they drift from the repo's YAML and the next deploy quietly puts them back.
        if ($action->id === 'save' && !Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException(Craft::t('publishr', 'Settings can’t be changed in this environment.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->redirect('publishr/settings/general');
    }

    /** @param Settings|null $settings Comes back from a failed save, errors and all. */
    public function actionGeneral(?Settings $settings = null): Response
    {
        $plugin = $this->plugin();

        return $this->renderTemplate('publishr/settings/general', [
            'title' => Craft::t('publishr', 'Settings'),
            'settings' => $settings ?? $plugin->getSettings(),
            'sections' => Craft::$app->getEntries()->getAllSections(),
            'lanes' => CalendarEvent::LANES,
            'laneLabels' => [
                CalendarEvent::LANE_DUE => Craft::t('publishr', 'Due'),
                CalendarEvent::LANE_PUBLISH => Craft::t('publishr', 'Publishing'),
                CalendarEvent::LANE_EXPIRE => Craft::t('publishr', 'Expiring'),
                CalendarEvent::LANE_REVIEW => Craft::t('publishr', 'Review'),
            ],
            'alarmClock' => AlarmClock::statusMessage(),
            'hasAlarmClock' => AlarmClock::isAvailable(),
            'hasRedPen' => RedPen::isAvailable(),
            'isPro' => $plugin->isPro(),
            'selectedTab' => 'general',
        ]);
    }

    /** @param Settings|null $settings Comes back from a failed save, errors and all. */
    public function actionNotifications(?Settings $settings = null): Response
    {
        $plugin = $this->plugin();

        return $this->renderTemplate('publishr/settings/notifications', [
            'title' => Craft::t('publishr', 'Notifications'),
            'settings' => $settings ?? $plugin->getSettings(),
            'events' => Notifications::eventLabels(),
            'problems' => $plugin->notifications->problems(50),
            'isPro' => $plugin->isPro(),
            'allowsNotifications' => Edition::allowsNotifications($plugin->isPro()),
            'selectedTab' => 'notifications',
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = $this->plugin();
        $posted = (array)$this->request->getBodyParam('settings', []);

        // Merged onto the current settings, never assigned wholesale. Craft saves plugin settings
        // as one project-config blob, so posting only the fields on *this* screen and letting the
        // model fill the rest with defaults silently resets every setting on the other screens.
        $settings = $plugin->getSettings();

        // The numeric settings are typed properties, so an emptied box arriving as "" would be a
        // TypeError on assignment rather than a validation error. Hold those back and say so.
        $invalid = [];

        foreach ($posted as $name => $value) {
            if (is_string($value) && $settings->hasProperty($name) && is_int($settings->$name)) {
                if (!preg_match('/^-?\d+$/', trim($value))) {
                    $invalid[] = $name;
                    unset($posted[$name]);
                    continue;
                }

                $posted[$name] = (int)$value;
            }
        }

        $settings->setAttributes($posted, false);

        $saved = $invalid === [] && Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());

        if (!$saved) {
            // Validate what did assign, so one emptied box doesn't hide another out-of-range one.
            if ($invalid !== []) {
                $settings->validate();
            }

            foreach ($invalid as $name) {
                $settings->addError($name, Craft::t('publishr', 'Enter a whole number.'));
            }

            $this->setFailFlash(Craft::t('publishr', 'Couldn’t save those settings.'));

            // Back to whichever screen posted, which re-renders with every variable it needs.
            Craft::$app->getUrlManager()->setRouteParams(['settings' => $settings]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('publishr', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /** Run the sweep now, from the settings screen. */
    public function actionSweep(): Response
    {
        $this->requirePostRequest();

        $result = $this->plugin()->sweep->run(false);

        $this->setSuccessFlash(Craft::t('publishr', 'Swept: {advanced} advanced, {sent} notifications sent.', [
            'advanced' => $result['advanced'],
            'sent' => $result['sent'],
        ]));

        return $this->redirectToPostedUrl();
    }

    public function actionRetryNotification(): Response
    {
        $this->requirePostRequest();

        $this->plugin()->notifications->retry((int)$this->request->getRequiredBodyParam('id'));
        $this->plugin()->notifications->sendPending(10);

        $this->setSuccessFlash(Craft::t('publishr', 'Tried again.'));

        return $this->redirectToPostedUrl();
    }
}
