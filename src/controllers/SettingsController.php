<?php

declare(strict_types=1);

namespace justinholtweb\publishr\controllers;

use Craft;
use justinholtweb\publishr\integrations\AlarmClock;
use justinholtweb\publishr\integrations\RedPen;
use justinholtweb\publishr\models\CalendarEvent;
use justinholtweb\publishr\models\Edition;
use justinholtweb\publishr\Plugin;
use justinholtweb\publishr\services\Notifications;
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

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->redirect('publishr/settings/general');
    }

    public function actionGeneral(): Response
    {
        $plugin = $this->plugin();

        return $this->renderTemplate('publishr/settings/general', [
            'title' => Craft::t('publishr', 'Settings'),
            'settings' => $plugin->getSettings(),
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

    public function actionNotifications(): Response
    {
        $plugin = $this->plugin();

        return $this->renderTemplate('publishr/settings/notifications', [
            'title' => Craft::t('publishr', 'Notifications'),
            'settings' => $plugin->getSettings(),
            'events' => Notifications::eventLabels(),
            'problems' => $plugin->notifications->problems(50),
            'isPro' => $plugin->isPro(),
            'allowsNotifications' => Edition::allowsNotifications($plugin->isPro()),
            'selectedTab' => 'notifications',
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $plugin = $this->plugin();
        $posted = $this->request->getBodyParam('settings', []);

        // Merged onto the current settings, never assigned wholesale. Craft saves plugin settings
        // as one project-config blob, so posting only the fields on *this* screen and letting the
        // model fill the rest with defaults silently resets every setting on the other screens.
        $settings = $plugin->getSettings();
        $settings->setAttributes($posted, false);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            $this->setFailFlash(Craft::t('publishr', 'Couldn’t save those settings.'));

            Craft::$app->getUrlManager()->setRouteParams(['settings' => $settings]);

            return $this->renderTemplate('publishr/settings/general', ['settings' => $settings]);
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
