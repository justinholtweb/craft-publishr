<?php

declare(strict_types=1);

namespace justinholtweb\publishr\twig;

use Craft;
use craft\elements\Entry;
use DateTime;
use DateTimeZone;
use justinholtweb\publishr\models\CalendarEvent;
use justinholtweb\publishr\models\GateReport;
use justinholtweb\publishr\models\Item;
use justinholtweb\publishr\models\Stage;
use justinholtweb\publishr\Plugin;

/**
 * `craft.publishr.…`
 *
 * Read-only. Everything that changes anything goes through a controller with a permission check on
 * it — a template is not the place from which a piece gets signed off.
 *
 * Useful on the front end as well as in the CP: a staging site that shows "in progress · due
 * Friday · Sam" above each article is worth more to a review meeting than any amount of screen
 * sharing, and it takes one tag.
 */
class PublishrVariable
{
    /** The editorial record for an entry, or null if it is not being tracked. */
    public function item(Entry $entry): ?Item
    {
        return Plugin::getInstance()->items->forEntry($entry);
    }

    public function stage(Entry $entry): ?Stage
    {
        return $this->item($entry)?->getStage();
    }

    /** @return Stage[] */
    public function stages(): array
    {
        return Plugin::getInstance()->stages->getAllStages();
    }

    /**
     * Everything happening in a month, bucketed by `Y-m-d`.
     *
     * @param string[]|null $lanes
     * @return array<string, CalendarEvent[]>
     */
    public function month(?int $year = null, ?int $month = null, ?array $lanes = null, ?int $siteId = null): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $tz = new DateTimeZone(Craft::$app->getTimeZone());
        $now = new DateTime('now', $tz);

        $year ??= (int)$now->format('Y');
        $month ??= (int)$now->format('n');
        $lanes ??= $settings->defaultLanes;
        $siteId ??= (int)Craft::$app->getSites()->getCurrentSite()->id;

        $weeks = $plugin->calendar->grid($year, $month, $settings->weekStartDay, $tz);
        [$start, $end] = $plugin->calendar->gridWindow($weeks);

        return $plugin->calendar->events($start, $end, $siteId, $lanes);
    }

    /** @return Item[] Somebody's queue. Defaults to whoever is logged in. */
    public function assignedTo(?int $userId = null, ?int $siteId = null, int $limit = 50): array
    {
        $userId ??= (int)(Craft::$app->getUser()->getId() ?? 0);

        return $userId > 0 ? Plugin::getInstance()->items->forAssignee($userId, $siteId, $limit) : [];
    }

    /** @return Item[] */
    public function overdue(?int $siteId = null, int $limit = 50): array
    {
        return Plugin::getInstance()->items->overdue($siteId, $limit);
    }

    /** @return Item[] Pieces due a freshness review. */
    public function reviewsDue(?int $siteId = null, int $limit = 50, int $withinDays = 0): array
    {
        return Plugin::getInstance()->freshness->due($siteId, $limit, $withinDays);
    }

    /** The publish requirements verdict for an entry. Pro only; Lite gets an empty report. */
    public function gates(Entry $entry, bool $recheck = false): GateReport
    {
        return Plugin::getInstance()->gates->report($entry, $recheck);
    }

    /**
     * How stale a piece is, 0–100.
     *
     * Handy for a staging-only banner: `{% if craft.publishr.staleness(entry) > 60 %}`.
     */
    public function staleness(Entry $entry): int
    {
        $item = $this->item($entry);

        return $item !== null ? Plugin::getInstance()->freshness->staleness($item) : 0;
    }

    /** @return \justinholtweb\publishr\models\HistoryEntry[] */
    public function history(Entry $entry, int $limit = 20): array
    {
        return Plugin::getInstance()->items->history((int)$entry->getCanonicalId(), (int)$entry->siteId, $limit);
    }

    /** @return \justinholtweb\publishr\models\Comment[] */
    public function comments(Entry $entry, bool $includeResolved = true): array
    {
        return Plugin::getInstance()->comments->forElement(
            (int)$entry->getCanonicalId(),
            (int)$entry->siteId,
            $includeResolved,
        );
    }

    public function openComments(Entry $entry): int
    {
        return Plugin::getInstance()->comments->openCount((int)$entry->getCanonicalId(), (int)$entry->siteId);
    }

    /** @return array<string, mixed> */
    public function report(?int $siteId = null): array
    {
        return Plugin::getInstance()->governance->report($siteId);
    }

    public function isPro(): bool
    {
        return Plugin::getInstance()->isPro();
    }
}
