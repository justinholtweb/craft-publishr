<?php

declare(strict_types=1);

namespace justinholtweb\publishr\integrations;

use Craft;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use DateTime;
use justinholtweb\publishr\models\HistoryEntry;
use justinholtweb\publishr\Plugin;
use Throwable;
use yii\base\Event;

/**
 * The Alarm Clock adapter — Publishr's clock.
 *
 * **Publishr owns no scheduler, and this is the reason the two plugins exist separately.** Craft
 * derives an entry's status in SQL from its dates, so an entry becomes live the instant the clock
 * passes its post date, with no save, no event and nothing at all for a plugin to hang itself on.
 * That is the problem Alarm Clock solves, once, properly — a ledger, a watermark, three triggers
 * and a unique index that makes the race safe. Publishr rebuilding a worse version of that inside
 * an editorial calendar would be the wrong plugin doing the wrong job.
 *
 * So the arrangement is: Alarm Clock notices, Publishr reacts. When a piece goes live, Publishr
 * moves it to the published stage, clears the deadline it has just met, and starts the freshness
 * clock **from the moment it actually went out** rather than from whenever a sweep next ran.
 *
 * Without Alarm Clock installed, everything here degrades to a sweep from garbage collection and
 * the console command. The stage still advances; it just advances the next time something runs
 * instead of within a minute. Every reference is a runtime string for the same reason as the
 * RedPen adapter — a `use` into a namespace that is not installed is a fatal error on the entry
 * editor.
 */
class AlarmClock
{
    private const PLUGIN_HANDLE = 'alarm-clock';
    private const TICKER_CLASS = 'justinholtweb\\alarmclock\\services\\Ticker';
    private const EVENT_AFTER_TRANSITION = 'afterTransition';

    private static ?bool $_available = null;

    public static function isAvailable(): bool
    {
        if (self::$_available !== null) {
            return self::$_available;
        }

        self::$_available = Craft::$app->getPlugins()->isPluginEnabled(self::PLUGIN_HANDLE)
            && class_exists(self::TICKER_CLASS);

        return self::$_available;
    }

    public static function reset(): void
    {
        self::$_available = null;
    }

    /**
     * Subscribe to crossings.
     *
     * Bound to the class rather than to the instance, so it works whether or not Alarm Clock's
     * ticker component has been created yet — which on a normal control-panel request it has not.
     */
    public static function attach(): void
    {
        if (!self::isAvailable()) {
            return;
        }

        Event::on(self::TICKER_CLASS, self::EVENT_AFTER_TRANSITION, static function(Event $event) {
            try {
                self::handleTransition($event);
            } catch (Throwable $e) {
                // A handler that throws here would fail Alarm Clock's tick, and a broken editorial
                // calendar must never stop a site publishing its content.
                Craft::warning('Publishr could not handle a transition: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            }
        });
    }

    private static function handleTransition(Event $event): void
    {
        $transition = $event->transition ?? null;
        $element = $event->element ?? null;

        if ($transition === null || !$element instanceof Entry) {
            return;
        }

        $kind = (string)($transition->transition ?? '');
        $at = $transition->scheduledFor ?? null;

        if ($kind === 'published') {
            self::published($element, $at instanceof DateTime ? $at : DateTimeHelper::now());
        } elseif ($kind === 'expired') {
            self::expired($element);
        }
    }

    /**
     * A piece went live.
     *
     * @param DateTime $at The moment it was *scheduled* for, not the moment we noticed. The
     *                     difference is up to a tick interval, and a freshness clock started from
     *                     "when the sweep ran" drifts a little further from the truth every year.
     */
    public static function published(Entry $entry, DateTime $at): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->managesSection($entry->getSection()?->uid)) {
            return;
        }

        $item = $plugin->items->forEntry($entry, $settings->autoTrack);

        if ($item === null) {
            return;
        }

        $item->setElement($entry);
        $plugin->items->log($item, HistoryEntry::EVENT_PUBLISHED, null, [
            'toValue' => $at->format('Y-m-d H:i'),
        ]);

        if ($settings->clearDueOnPublish && $item->dueDate !== null) {
            $item->dueDate = null;
        }

        // The freshness clock starts now, from the real publication instant.
        if ($settings->freshnessEnabled) {
            $plugin->freshness->applyPolicy($item, $entry, $at);
        }

        $plugin->items->save($item);

        $published = $plugin->stages->getPublishedStage();

        if ($settings->advanceOnPublish && $published !== null && $item->stageId !== $published->id) {
            // Forced: a gated "Published" stage would otherwise mean an entry that is *already
            // live on the website* could not be recorded as published, which is a calendar lying
            // about the state of the world to protect a checklist.
            $plugin->items->moveToStage($item, $published->id, null, Craft::t('publishr', 'Published automatically'), true);
        }

        $plugin->notifications->published($item, $entry);
    }

    public static function expired(Entry $entry): void
    {
        $plugin = Plugin::getInstance();
        $item = $plugin->items->forEntry($entry);

        if ($item === null) {
            return;
        }

        $item->setElement($entry);
        $plugin->items->log($item, HistoryEntry::EVENT_EXPIRED);
        $plugin->notifications->expired($item, $entry);
    }

    /**
     * Entries Alarm Clock is waiting on, for the calendar's "scheduled drafts" lane.
     *
     * @return array<int, DateTime> Draft element ID => when it will be applied.
     */
    public static function scheduledDrafts(int $limit = 200): array
    {
        if (!self::isAvailable()) {
            return [];
        }

        try {
            $plugin = Craft::$app->getPlugins()->getPlugin(self::PLUGIN_HANDLE);

            if (!$plugin instanceof \yii\base\Module || !$plugin->has('schedules')) {
                return [];
            }

            $out = [];

            foreach ($plugin->schedules->upcoming($limit) as $schedule) {
                // `getPublishAtDate()` rather than the raw column: Active Record hands back a
                // bare `Y-m-d H:i:s` in UTC, and reading that with `new DateTime()` interprets it
                // in the site's zone — a nine o'clock schedule displaying as four in the afternoon.
                $when = $schedule->getPublishAtDate();

                if ($when !== null) {
                    $out[(int)$schedule->draftId] = $when;
                }
            }

            return $out;
        } catch (Throwable $e) {
            Craft::warning('Could not read Alarm Clock schedules: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return [];
        }
    }

    /** How the settings screen explains what is and is not automatic on this site. */
    public static function statusMessage(): string
    {
        return self::isAvailable()
            ? Craft::t('publishr', 'Alarm Clock is installed. Pieces move to the published stage within a minute of going live.')
            : Craft::t('publishr', 'Alarm Clock isn’t installed. Pieces still move to the published stage, but only when the sweep next runs.');
    }
}
