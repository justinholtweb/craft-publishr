<?php

declare(strict_types=1);

namespace justinholtweb\publishr;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\ModelEvent;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craft\services\Gc;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\publishr\integrations\AlarmClock;
use justinholtweb\publishr\models\Edition;
use justinholtweb\publishr\models\Settings;
use justinholtweb\publishr\queue\jobs\RunSweep;
use justinholtweb\publishr\services\Calendar;
use justinholtweb\publishr\services\Comments;
use justinholtweb\publishr\services\Freshness;
use justinholtweb\publishr\services\Gates;
use justinholtweb\publishr\services\Governance;
use justinholtweb\publishr\services\Items;
use justinholtweb\publishr\services\Notifications;
use justinholtweb\publishr\services\Policies;
use justinholtweb\publishr\services\Stages;
use justinholtweb\publishr\services\Sweep;
use justinholtweb\publishr\twig\PublishrVariable;
use Throwable;
use yii\base\Event;

/**
 * Publishr — the editorial calendar and content governance for Craft CMS.
 *
 * @property-read Stages $stages
 * @property-read Items $items
 * @property-read Calendar $calendar
 * @property-read Gates $gates
 * @property-read Policies $policies
 * @property-read Freshness $freshness
 * @property-read Comments $comments
 * @property-read Notifications $notifications
 * @property-read Governance $governance
 * @property-read Sweep $sweep
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_VIEW = 'publishr:view';
    public const PERMISSION_MANAGE = 'publishr:manage';
    public const PERMISSION_ASSIGN = 'publishr:assign';
    public const PERMISSION_COMMENT = 'publishr:comment';
    public const PERMISSION_REVIEW = 'publishr:review';
    public const PERMISSION_OVERRIDE_GATES = 'publishr:overrideGates';
    public const PERMISSION_REPORTS = 'publishr:reports';
    public const PERMISSION_SETTINGS = 'publishr:settings';

    public const LOG_CATEGORY = 'publishr';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'stages' => Stages::class,
                'items' => Items::class,
                'calendar' => Calendar::class,
                'gates' => Gates::class,
                'policies' => Policies::class,
                'freshness' => Freshness::class,
                'comments' => Comments::class,
                'notifications' => Notifications::class,
                'governance' => Governance::class,
                'sweep' => Sweep::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerCpRoutes();
        $this->registerPermissions();
        $this->registerProjectConfig();
        $this->registerTwig();
        $this->registerEntryHooks();
        $this->registerGarbageCollection();

        // Late, and only outside install/update: attaching to Alarm Clock asks the plugins service
        // whether it is enabled, and doing that during Craft's own bootstrap on a part-migrated
        // site is how a plugin takes the control panel down at the worst possible moment.
        Craft::$app->onInit(function() {
            AlarmClock::attach();
        });
    }

    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    /**
     * Seed the workflow.
     *
     * Here rather than in the migration, because project-config writes from a migration are
     * buffered and can land before the plugin's own row exists — the change then silently vanishes
     * while the call still returns true.
     */
    protected function afterInstall(): void
    {
        parent::afterInstall();

        if (Craft::$app->getProjectConfig()->getIsApplyingExternalChanges()) {
            // The config is arriving from somewhere else and already contains these stages.
            // Seeding on top of it would produce duplicates with different UIDs per environment.
            return;
        }

        $this->stages->installDefaults();
    }

    /**
     * Take Publishr's own project-config root with it.
     *
     * Craft clears `plugins.publishr` and nothing else, so `publishr.stages.*` and friends would
     * outlive the uninstall: every later `project-config/diff` carries them, and a reinstall seeds
     * five fresh stages beside the five orphans. Events are muted because the handlers delete rows
     * from tables the uninstall migration has already dropped.
     */
    protected function afterUninstall(): void
    {
        parent::afterUninstall();

        $projectConfig = Craft::$app->getProjectConfig();

        if ($projectConfig->getIsApplyingExternalChanges()) {
            return;
        }

        $muted = $projectConfig->muteEvents;
        $projectConfig->muteEvents = true;

        try {
            $projectConfig->remove('publishr', 'Remove Publishr’s stages, requirements and policies');
        } finally {
            $projectConfig->muteEvents = $muted;
        }
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('publishr', 'Publishr');

        $user = Craft::$app->getUser();

        if (!$user->checkPermission(self::PERMISSION_VIEW)) {
            return null;
        }

        $item['subnav']['calendar'] = ['label' => Craft::t('publishr', 'Calendar'), 'url' => 'publishr/calendar'];
        $item['subnav']['board'] = ['label' => Craft::t('publishr', 'Board'), 'url' => 'publishr/board'];
        $item['subnav']['overview'] = ['label' => Craft::t('publishr', 'Overview'), 'url' => 'publishr/overview'];
        $item['subnav']['mine'] = ['label' => Craft::t('publishr', 'My desk'), 'url' => 'publishr/mine'];

        // Wrapped, because the navigation is built on *every* control-panel request — including
        // the ones Craft serves while a migration is part-applied. A query against a table that
        // does not exist yet would take the whole CP down at exactly the moment somebody was
        // trying to fix it.
        try {
            $late = $this->items->overdueCountFor((int)$user->getId());

            if ($late > 0) {
                $item['badgeCount'] = $late;
            }
        } catch (Throwable) {
            // No badge. Nothing else is worth breaking for it.
        }

        if ($this->isPro() && $user->checkPermission(self::PERMISSION_REVIEW)) {
            $item['subnav']['reviews'] = ['label' => Craft::t('publishr', 'Reviews'), 'url' => 'publishr/reviews'];
        }

        if ($this->isPro() && $user->checkPermission(self::PERMISSION_REPORTS)) {
            $item['subnav']['report'] = ['label' => Craft::t('publishr', 'Report'), 'url' => 'publishr/report'];
        }

        if ($user->getIsAdmin() || $user->checkPermission(self::PERMISSION_SETTINGS)) {
            $item['subnav']['settings'] = ['label' => Craft::t('publishr', 'Settings'), 'url' => 'publishr/settings'];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Publishr's settings are several screens, not one pane.
     *
     * Never a redirect to `settings/plugins/publishr` — that *is* the URL Craft renders
     * `settingsHtml()` at, so overriding it that way is an infinite redirect.
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('publishr/settings'));
    }

    // -------------------------------------------------------------------- entries

    private function registerEntryHooks(): void
    {
        $this->registerEntrySidebar();
        $this->registerEntrySave();
        $this->registerPublishGuard();
    }

    /**
     * The panel on the entry editor.
     *
     * Everything an editor needs about a piece while they are looking at it: which stage it is on,
     * who owns it, when it is due, what is stopping it, and the conversation. That is the screen
     * they are already on — a calendar they have to navigate to is a calendar they consult once a
     * week and trust never.
     */
    private function registerEntrySidebar(): void
    {
        Event::on(Entry::class, Element::EVENT_DEFINE_SIDEBAR_HTML, function(DefineHtmlEvent $event) {
            /** @var Entry $entry */
            $entry = $event->sender;

            if (!Craft::$app->getRequest()->getIsCpRequest() || $entry->id === null || $entry->getIsRevision()) {
                return;
            }

            if (!Craft::$app->getUser()->checkPermission(self::PERMISSION_VIEW)) {
                return;
            }

            if (!$this->getSettings()->managesSection($entry->getSection()?->uid)) {
                return;
            }

            try {
                $item = $this->items->forEntry($entry);

                $event->html .= Craft::$app->getView()->renderTemplate('publishr/_sidebar', [
                    'entry' => $entry,
                    'item' => $item,
                    'stages' => $this->stages->getAllStages(),
                    'report' => $this->isPro() && $item !== null ? $this->gates->report($entry) : null,
                    'comments' => $item !== null
                        ? $this->comments->forElement($item->elementId, $item->siteId)
                        : [],
                    'canManage' => Craft::$app->getUser()->checkPermission(self::PERMISSION_MANAGE),
                    'canAssign' => Craft::$app->getUser()->checkPermission(self::PERMISSION_ASSIGN),
                    'canComment' => Craft::$app->getUser()->checkPermission(self::PERMISSION_COMMENT),
                    'canOverride' => Craft::$app->getUser()->checkPermission(self::PERMISSION_OVERRIDE_GATES),
                    'isPro' => $this->isPro(),
                ], View::TEMPLATE_MODE_CP);
            } catch (Throwable $e) {
                // A broken panel must never stop somebody editing their content.
                Craft::warning('Could not render the Publishr panel: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    /**
     * Start tracking new entries, and re-run the checklist on saved ones.
     *
     * `EVENT_AFTER_SAVE` and never `EVENT_BEFORE_SAVE`: creating the editorial record before the
     * element has an ID would write a row pointing at nothing, and a foreign key would refuse it.
     */
    private function registerEntrySave(): void
    {
        Event::on(Entry::class, Element::EVENT_AFTER_SAVE, function(ModelEvent $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            $settings = $this->getSettings();

            // Propagation runs this once per site with an identical element; drafts and revisions
            // are not the piece. Only the canonical save in its own site does any work.
            if ($entry->propagating || $entry->getIsRevision() || $entry->resaving) {
                return;
            }

            if (!$settings->managesSection($entry->getSection()?->uid)) {
                return;
            }

            try {
                $item = $this->items->forEntry($entry, $settings->autoTrack && !$entry->getIsDraft());

                if ($item === null) {
                    return;
                }

                if ($settings->checkGatesOnSave && $this->isPro() && !$entry->getIsDraft()) {
                    $this->gates->report($entry, true);
                }
            } catch (Throwable $e) {
                Craft::warning('Publishr could not record a save: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });

        Event::on(Entry::class, Element::EVENT_AFTER_DELETE, function(Event $event) {
            /** @var Entry $entry */
            $entry = $event->sender;

            // **Drafts and revisions must not reach this.** A draft's `getCanonicalId()` is the
            // entry it was made from, so deleting a draft — which Craft does constantly, every
            // time a provisional draft is discarded or applied — would delete the editorial record
            // of the piece the draft was *of*: its stage, its owner, its deadline, all gone
            // because somebody pressed "revert". Found by the check suite, which deleted a draft
            // and watched the item vanish underneath it.
            if ($entry->getIsDraft() || $entry->getIsRevision()) {
                return;
            }

            // A soft delete is a trip to the trash, and the trash has a Restore button. Deleting
            // the item here would restore the entry without its stage, owner or deadline. Trashed
            // entries already drop out of every list, and a hard delete cascades through the FK.
            if (!$entry->hardDelete) {
                return;
            }

            try {
                // The item row cascades with the element anyway. The history deliberately does not
                // — see the install migration — so "we published this and then deleted it"
                // survives the deletion.
                $this->items->deleteForElement((int)$entry->getCanonicalId(), (int)$entry->siteId);
            } catch (Throwable) {
                // The FK will have taken care of it.
            }
        });
    }

    /**
     * Optionally refuse to publish an entry whose required gates fail.
     *
     * Off by default, and the default matters: blocking a stage move is a conversation between
     * colleagues, and blocking a save is a wall between somebody and their work. Even switched on
     * it is narrow — it bites only when an entry would become genuinely *public*, never on a
     * draft, never on a disabled entry, and never for somebody holding the override permission.
     */
    private function registerPublishGuard(): void
    {
        Event::on(Entry::class, Element::EVENT_BEFORE_SAVE, function(ModelEvent $event) {
            if (!$this->getSettings()->blockPublish || !Edition::allowsGates($this->isPro())) {
                return;
            }

            /** @var Entry $entry */
            $entry = $event->sender;

            if ($entry->propagating || $entry->resaving || $entry->getIsDraft() || $entry->getIsRevision()) {
                return;
            }

            if (!$this->getSettings()->managesSection($entry->getSection()?->uid)) {
                return;
            }

            // Would this save make it public? A disabled entry, or one with a post date in the
            // future, is not going anywhere yet and does not need a sign-off to be saved.
            if (!$entry->enabled || !$entry->getEnabledForSite()) {
                return;
            }

            if ($entry->postDate !== null && $entry->postDate > new \DateTime('now')) {
                return;
            }

            if (Craft::$app->getUser()->checkPermission(self::PERMISSION_OVERRIDE_GATES)) {
                return;
            }

            try {
                $report = $this->gates->evaluate($entry);
            } catch (Throwable $e) {
                // A broken checklist must not become an unpublishable site.
                Craft::warning('Publishr could not run the publish guard: ' . $e->getMessage(), self::LOG_CATEGORY);

                return;
            }

            foreach ($report->blocking() as $result) {
                $entry->addError('publishr', Craft::t('publishr', '{gate}: {message}', [
                    'gate' => $result->gateName,
                    'message' => $result->message ?? Craft::t('publishr', 'not met'),
                ]));
            }

            if ($report->blocking() !== []) {
                $event->isValid = false;
            }
        });
    }

    // --------------------------------------------------------------- registration

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'publishr' => 'publishr/calendar/index',
                'publishr/calendar' => 'publishr/calendar/index',
                'publishr/calendar/<year:\d{4}>/<month:\d{1,2}>' => 'publishr/calendar/index',
                'publishr/board' => 'publishr/board/index',
                'publishr/overview' => 'publishr/overview/index',
                'publishr/mine' => 'publishr/overview/mine',
                'publishr/reviews' => 'publishr/reviews/index',
                'publishr/report' => 'publishr/reports/index',
                'publishr/activity' => 'publishr/overview/activity',

                'publishr/settings' => 'publishr/settings/index',
                'publishr/settings/general' => 'publishr/settings/general',
                'publishr/settings/stages' => 'publishr/stages/index',
                'publishr/settings/stages/new' => 'publishr/stages/edit',
                'publishr/settings/stages/<stageId:\d+>' => 'publishr/stages/edit',
                'publishr/settings/gates' => 'publishr/gates/index',
                'publishr/settings/gates/new' => 'publishr/gates/edit',
                'publishr/settings/gates/<gateId:\d+>' => 'publishr/gates/edit',
                'publishr/settings/policies' => 'publishr/policies/index',
                'publishr/settings/policies/new' => 'publishr/policies/edit',
                'publishr/settings/policies/<policyId:\d+>' => 'publishr/policies/edit',
                'publishr/settings/notifications' => 'publishr/settings/notifications',
            ];
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('publishr', 'Publishr'),
                'permissions' => [
                    self::PERMISSION_VIEW => [
                        'label' => Craft::t('publishr', 'See the editorial calendar'),
                        'nested' => [
                            self::PERMISSION_MANAGE => ['label' => Craft::t('publishr', 'Move work between stages and set deadlines')],
                            self::PERMISSION_ASSIGN => ['label' => Craft::t('publishr', 'Assign work to other people')],
                            self::PERMISSION_COMMENT => ['label' => Craft::t('publishr', 'Leave editorial comments')],
                            self::PERMISSION_REVIEW => ['label' => Craft::t('publishr', 'Carry out freshness reviews')],

                            // Deliberately not nested under "manage". Being allowed to move a card
                            // and being allowed to overrule the sign-off requirements are different
                            // levels of trust, and on most desks they belong to different people.
                            self::PERMISSION_OVERRIDE_GATES => ['label' => Craft::t('publishr', 'Sign off despite failing requirements')],
                            self::PERMISSION_REPORTS => ['label' => Craft::t('publishr', 'See the governance report')],
                            self::PERMISSION_SETTINGS => ['label' => Craft::t('publishr', 'Change the workflow and requirements')],
                        ],
                    ],
                ],
            ];
        });
    }

    private function registerProjectConfig(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        $projectConfig
            ->onAdd(Stages::CONFIG_KEY . '.{uid}', [$this->stages, 'handleChangedStage'])
            ->onUpdate(Stages::CONFIG_KEY . '.{uid}', [$this->stages, 'handleChangedStage'])
            ->onRemove(Stages::CONFIG_KEY . '.{uid}', [$this->stages, 'handleDeletedStage'])
            ->onAdd(Gates::CONFIG_KEY . '.{uid}', [$this->gates, 'handleChangedGate'])
            ->onUpdate(Gates::CONFIG_KEY . '.{uid}', [$this->gates, 'handleChangedGate'])
            ->onRemove(Gates::CONFIG_KEY . '.{uid}', [$this->gates, 'handleDeletedGate'])
            ->onAdd(Policies::CONFIG_KEY . '.{uid}', [$this->policies, 'handleChangedPolicy'])
            ->onUpdate(Policies::CONFIG_KEY . '.{uid}', [$this->policies, 'handleChangedPolicy'])
            ->onRemove(Policies::CONFIG_KEY . '.{uid}', [$this->policies, 'handleDeletedPolicy']);

        Event::on(ProjectConfig::class, ProjectConfig::EVENT_REBUILD, function(RebuildConfigEvent $event) {
            $event->config['publishr']['stages'] = $this->stages->rebuildProjectConfig();
            $event->config['publishr']['gates'] = $this->gates->rebuildProjectConfig();
            $event->config['publishr']['policies'] = $this->policies->rebuildProjectConfig();
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('publishr', PublishrVariable::class);
        });
    }

    /**
     * The sweep, hooked to Craft's own garbage collection.
     *
     * Queued rather than run inline: it can send several hundred emails, and nobody's page load
     * should pay for that. It is a *backstop* — a site with cron runs `publishr/sweep` far more
     * often — but it is the difference between reminders arriving late and never arriving on a
     * site that has neither cron nor a queue runner it thinks about.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            if (!$this->getSettings()->notificationsEnabled && !$this->getSettings()->freshnessEnabled) {
                return;
            }

            Queue::push(new RunSweep());
        });
    }
}
