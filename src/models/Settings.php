<?php

declare(strict_types=1);

namespace justinholtweb\publishr\models;

use craft\base\Model;

/**
 * Plugin-wide settings.
 *
 * Nothing here is `required`. A fresh install has to be able to save its settings before it has
 * been configured, and a `required` rule fails `savePluginSettings()` wholesale — taking every
 * unrelated setting down with the one that has not been filled in yet.
 */
class Settings extends Model
{
    // ------------------------------------------------------------------- scope

    /**
     * Section UIDs Publishr manages. Empty manages every section.
     *
     * Worth setting on a site whose entries are mostly not editorial — a store with 40,000
     * products does not want them on a content calendar, and the calendar queries get cheaper
     * the moment it knows that.
     */
    public array $sections = [];

    /**
     * Give every managed entry an editorial record the first time it is saved.
     *
     * On by default because the alternative — a calendar that only shows entries somebody
     * remembered to add — is the reason editorial calendars get abandoned. Off suits a site that
     * wants to track one campaign and nothing else.
     */
    public bool $autoTrack = true;

    // ------------------------------------------------------------------ calendar

    /** Lanes shown when somebody opens the calendar for the first time. */
    public array $defaultLanes = [
        CalendarEvent::LANE_DUE,
        CalendarEvent::LANE_PUBLISH,
    ];

    /** 0 = Sunday. Craft has no site-wide setting for this, so Publishr needs its own. */
    public int $weekStartDay = 1;

    /** Most pips drawn in one day cell before it collapses to "+ 6 more". */
    public int $maxPerDay = 8;

    /** Show entries in sections Publishr manages even when they have no editorial record yet. */
    public bool $showUntracked = true;

    // ------------------------------------------------------------------ workflow

    /**
     * Move a piece to the published stage when Alarm Clock says it went live.
     *
     * The single most valuable automation in the plugin, and the reason Alarm Clock is a
     * dependency rather than a nice-to-have: without it, "Published" is a column somebody has to
     * remember to drag cards into, and within a fortnight it is lying.
     */
    public bool $advanceOnPublish = true;

    /** Clear the deadline when a piece goes live. It has been met; leaving it reads as overdue. */
    public bool $clearDueOnPublish = true;

    /** Warn on the entry screen when a piece is being edited by somebody it is not assigned to. */
    public bool $warnOnForeignEdit = true;

    // --------------------------------------------------------------------- gates

    /**
     * Also refuse to *publish* an entry whose required gates fail.
     *
     * Off by default, and the default matters. Blocking a stage move is a conversation between
     * colleagues; blocking a save is a wall between somebody and their work. Sites that need the
     * wall — regulated copy, medical claims, anything with a legal review — turn it on knowingly,
     * and even then it only bites when an entry would become *public*, never on a draft, never on
     * a disabled entry, and never for somebody holding the override permission.
     */
    public bool $blockPublish = false;

    /** Re-run the checklist automatically whenever a managed entry is saved. */
    public bool $checkGatesOnSave = true;

    // ----------------------------------------------------------------- freshness

    /** Master switch for review-by dates. */
    public bool $freshnessEnabled = true;

    /**
     * Cap on how many items one sweep will schedule reviews for.
     *
     * A policy applied to a ten-year archive matches thousands of entries at once. Scheduling them
     * in bounded batches keeps the first sweep after a policy change from being a timeout, and the
     * next sweep simply picks up where this one stopped.
     */
    public int $maxReviewsPerSweep = 500;

    // ------------------------------------------------------------- notifications

    public bool $notificationsEnabled = true;

    /** Days before a deadline to send the "due soon" nudge. */
    public int $dueSoonDays = 2;

    /** Send the daily digest. */
    public bool $digestEnabled = false;

    /** Hour of the day (site time) the digest is meant to go out. */
    public int $digestHour = 8;

    /** Days the digest looks ahead. */
    public int $digestHorizonDays = 7;

    /** Extra addresses that get the digest whatever their subscriptions say. */
    public array $digestRecipients = [];

    /**
     * Send notifications through the queue rather than inline.
     *
     * On by default: a stage move that waits on an SMTP handshake is a stage move that feels
     * broken, and a mail server having a bad afternoon should not make the CP unusable.
     */
    public bool $queueNotifications = true;

    // ---------------------------------------------------------------- retention

    /** Days of history and sent notifications to keep. 0 keeps everything. */
    public int $historyRetentionDays = 0;

    protected function defineRules(): array
    {
        return [
            [['weekStartDay'], 'integer', 'min' => 0, 'max' => 6],
            [['maxPerDay'], 'integer', 'min' => 1, 'max' => 50],
            [['dueSoonDays', 'digestHorizonDays'], 'integer', 'min' => 0, 'max' => 365],
            [['digestHour'], 'integer', 'min' => 0, 'max' => 23],
            [['maxReviewsPerSweep'], 'integer', 'min' => 1, 'max' => 10000],
            [['historyRetentionDays'], 'integer', 'min' => 0],
            [
                ['autoTrack', 'showUntracked', 'advanceOnPublish', 'clearDueOnPublish', 'warnOnForeignEdit',
                    'blockPublish', 'checkGatesOnSave', 'freshnessEnabled', 'notificationsEnabled',
                    'digestEnabled', 'queueNotifications', ],
                'boolean',
            ],
        ];
    }

    /** Whether a section is one Publishr manages. */
    public function managesSection(?string $sectionUid): bool
    {
        if ($this->sections === []) {
            return true;
        }

        return $sectionUid !== null && in_array($sectionUid, $this->sections, true);
    }
}
