<?php

declare(strict_types=1);

namespace justinholtweb\publishr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateInterval;
use DatePeriod;
use DateTime;
use DateTimeZone;
use justinholtweb\publishr\models\CalendarEvent;
use justinholtweb\publishr\models\Item;
use justinholtweb\publishr\Plugin;
use justinholtweb\publishr\records\Table;

/**
 * The month view.
 *
 * Two things here are the difference between a calendar people use and one they open once.
 *
 * **An entry is not an event.** It has up to four editorially interesting dates — due, out, down,
 * review — and a grid that can only show one of them has to pick. Every editorial calendar built
 * on `postDate` alone is useless to the person doing the writing, because their deadlines are not
 * post dates. So the query runs per lane and an entry legitimately appears more than once.
 *
 * **Drafts have to be on it.** The pieces that matter most to an editor on a Monday morning are
 * the ones that do not exist yet as public entries. Craft's own date-range queries exclude drafts
 * by default, which is exactly backwards for this screen.
 */
class Calendar extends Component
{
    /**
     * Everything happening in a window, bucketed by day.
     *
     * @param string[] $lanes Which of {@see CalendarEvent::LANES} to include.
     * @param array{stageIds?: int[], assigneeId?: int, sectionIds?: int[], search?: string} $filters
     * @return array<string, CalendarEvent[]> `Y-m-d` => events.
     */
    public function events(DateTime $start, DateTime $end, int $siteId, array $lanes, array $filters = []): array
    {
        $days = [];

        foreach ($this->collect($start, $end, $siteId, $lanes, $filters) as $event) {
            $days[$event->dayKey()][] = $event;
        }

        foreach ($days as $key => $events) {
            usort($days[$key], static function(CalendarEvent $a, CalendarEvent $b) {
                return [$a->date->getTimestamp(), $a->entry->title ?? ''] <=> [$b->date->getTimestamp(), $b->entry->title ?? ''];
            });
        }

        return $days;
    }

    /** @return CalendarEvent[] */
    public function collect(DateTime $start, DateTime $end, int $siteId, array $lanes, array $filters = []): array
    {
        $lanes = array_values(array_intersect($lanes, CalendarEvent::LANES));

        if ($lanes === []) {
            return [];
        }

        $events = [];

        if (in_array(CalendarEvent::LANE_PUBLISH, $lanes, true)) {
            $events = array_merge($events, $this->entryDateLane($start, $end, $siteId, 'postDate', CalendarEvent::LANE_PUBLISH, $filters));
        }

        if (in_array(CalendarEvent::LANE_EXPIRE, $lanes, true)) {
            $events = array_merge($events, $this->entryDateLane($start, $end, $siteId, 'expiryDate', CalendarEvent::LANE_EXPIRE, $filters));
        }

        if (in_array(CalendarEvent::LANE_DUE, $lanes, true)) {
            $events = array_merge($events, $this->itemDateLane($start, $end, $siteId, 'dueDate', CalendarEvent::LANE_DUE, $filters));
        }

        if (in_array(CalendarEvent::LANE_REVIEW, $lanes, true)) {
            $events = array_merge($events, $this->itemDateLane($start, $end, $siteId, 'reviewDue', CalendarEvent::LANE_REVIEW, $filters));
        }

        return $events;
    }

    /**
     * The publish and expiry lanes, read off the entries themselves.
     *
     * `status(null)` plus `drafts(null)` is doing real work: the default query excludes both
     * disabled entries and drafts, which between them are most of what an editor wants to see in
     * next week's column. Revisions stay excluded — an entry's twelve saved revisions are not
     * twelve things happening on Thursday.
     *
     * @return CalendarEvent[]
     */
    private function entryDateLane(DateTime $start, DateTime $end, int $siteId, string $attribute, string $lane, array $filters): array
    {
        $query = Entry::find()
            ->siteId($siteId)
            ->status(null)
            ->drafts(null)
            ->revisions(false)
            ->limit(null);

        // A date *range* param, given as an array of two DateTimes — not a prepared string. A
        // value from `Db::prepareDateForDb()` handed to an element query's date param is read as
        // system time and converted to UTC a second time, so the window silently matches nothing.
        $query->$attribute(['and', '>= ' . $start->format(DATE_ATOM), '< ' . $end->format(DATE_ATOM)]);

        $this->applyEntryFilters($query, $filters);

        $entries = $query->all();
        $items = $this->itemsFor($entries, $siteId);
        $now = DateTimeHelper::now();
        $events = [];

        foreach ($entries as $entry) {
            $date = $entry->$attribute;

            if (!$date instanceof DateTime) {
                continue;
            }

            $item = $items[(int)$entry->getCanonicalId()] ?? null;

            if (!$this->passesItemFilters($item, $filters)) {
                continue;
            }

            $events[] = new CalendarEvent(
                $lane,
                $date,
                $entry,
                $item,
                $entry->getIsUnpublishedDraft(),
                // "Late" on the publish lane means the date has passed and the entry still is not
                // public — the one thing an editor actually needs flagging in red.
                $lane === CalendarEvent::LANE_PUBLISH && $date < $now && $entry->getStatus() !== Entry::STATUS_LIVE,
            );
        }

        return $events;
    }

    /**
     * The due and review lanes, read off the editorial records.
     *
     * Queried from `publishr_items` and then hydrated, rather than by loading every entry and
     * checking: on a site with 40,000 entries and 60 deadlines, the other way round is 40,000 rows
     * to find 60.
     *
     * @return CalendarEvent[]
     */
    private function itemDateLane(DateTime $start, DateTime $end, int $siteId, string $column, string $lane, array $filters): array
    {
        $query = (new Query())
            ->select(['elementId', 'siteId', $column])
            ->from([Table::ITEMS])
            ->where(['siteId' => $siteId])
            ->andWhere(['>=', $column, Db::prepareDateForDb($start)])
            ->andWhere(['<', $column, Db::prepareDateForDb($end)]);

        if (!empty($filters['stageIds'])) {
            $query->andWhere(['stageId' => $filters['stageIds']]);
        }

        if (!empty($filters['assigneeId'])) {
            $query->andWhere(['assigneeId' => $filters['assigneeId']]);
        }

        $rows = $query->all();

        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn(array $row) => (int)$row['elementId'], $rows);

        $entries = Entry::find()
            ->id($ids)
            ->siteId($siteId)
            ->status(null)
            ->drafts(null)
            ->revisions(false)
            ->limit(null)
            ->indexBy('id')
            ->all();

        $items = Plugin::getInstance()->items->forElements($ids, $siteId);
        $now = DateTimeHelper::now();
        $events = [];

        foreach ($rows as $row) {
            $entry = $entries[(int)$row['elementId']] ?? null;

            if ($entry === null) {
                continue;
            }

            if (!empty($filters['sectionIds']) && !in_array((int)$entry->sectionId, $filters['sectionIds'], true)) {
                continue;
            }

            // Bare `Y-m-d H:i:s` out of a column is UTC; `new DateTime()` would read it in the
            // site's zone and slide the whole lane by the offset — which is how a deadline lands
            // in the wrong week.
            $date = DateTimeHelper::toDateTime($row[$column], false, false);

            if ($date === false) {
                continue;
            }

            $events[] = new CalendarEvent(
                $lane,
                $date,
                $entry,
                $items[(int)$row['elementId']] ?? null,
                $entry->getIsUnpublishedDraft(),
                $date < $now,
            );
        }

        return $events;
    }

    /** @param Entry[] $entries @return array<int, Item> */
    private function itemsFor(array $entries, int $siteId): array
    {
        $ids = array_values(array_unique(array_map(
            static fn(Entry $entry) => (int)$entry->getCanonicalId(),
            $entries,
        )));

        return Plugin::getInstance()->items->forElements($ids, $siteId);
    }

    private function applyEntryFilters(\craft\elements\db\EntryQuery $query, array $filters): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->sections !== []) {
            $query->section($this->sectionHandles($settings->sections));
        }

        if (!empty($filters['sectionIds'])) {
            $query->sectionId($filters['sectionIds']);
        }

        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }
    }

    private function passesItemFilters(?Item $item, array $filters): bool
    {
        if (!empty($filters['stageIds']) && !in_array($item?->stageId, $filters['stageIds'], true)) {
            return false;
        }

        if (!empty($filters['assigneeId']) && $item?->assigneeId !== (int)$filters['assigneeId']) {
            return false;
        }

        return true;
    }

    /** @param string[] $uids @return string[] */
    private function sectionHandles(array $uids): array
    {
        $handles = [];

        foreach ($uids as $uid) {
            $section = Craft::$app->getEntries()->getSectionByUid($uid);

            if ($section !== null) {
                $handles[] = $section->handle;
            }
        }

        // A scope naming only sections that have since been deleted must match nothing, not
        // everything — an empty `section()` argument is ignored by the query builder, which would
        // silently widen the calendar to the whole site.
        return $handles !== [] ? $handles : ['__publishr_no_such_section__'];
    }

    // ------------------------------------------------------------------ the grid

    /**
     * The weeks of a month, as a 6×7 grid of dates.
     *
     * Built in the *site's* time zone, and the reason that matters is subtle: `new DateTime()` in
     * a queue job or a console command runs in PHP's default zone, which on a well-configured
     * server is UTC. Building the grid there and rendering it to somebody in Auckland puts the
     * month boundary in the wrong place, and everything on the 1st disappears.
     *
     * @return array<int, DateTime[]> Weeks of seven days.
     */
    public function grid(int $year, int $month, int $weekStartDay = 1, ?DateTimeZone $tz = null): array
    {
        $tz ??= new DateTimeZone(Craft::$app->getTimeZone());

        $first = new DateTime(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);

        $offset = ((int)$first->format('w') - $weekStartDay + 7) % 7;
        $gridStart = (clone $first)->sub(new DateInterval("P{$offset}D"));

        $weeks = [];
        $cursor = clone $gridStart;

        for ($w = 0; $w < 6; $w++) {
            $week = [];

            for ($d = 0; $d < 7; $d++) {
                $week[] = clone $cursor;
                $cursor = $cursor->add(new DateInterval('P1D'));
            }

            $weeks[] = $week;

            // Stop once the grid has covered the month — a five-week month does not need a sixth
            // empty row, and a calendar that is always six rows tall wastes a screen of space
            // eleven months a year.
            if ((int)$cursor->format('n') !== $month && $cursor > $first) {
                break;
            }
        }

        return $weeks;
    }

    /** The window a month grid actually covers, inclusive of its leading and trailing days. */
    public function gridWindow(array $weeks): array
    {
        $first = $weeks[0][0];
        $last = end($weeks)[6];

        return [clone $first, (clone $last)->add(new DateInterval('P1D'))];
    }

    /** @return DateTime[] Seven days, starting on the configured first day of the week. */
    public function week(DateTime $anyDayInIt, int $weekStartDay = 1): array
    {
        $offset = ((int)$anyDayInIt->format('w') - $weekStartDay + 7) % 7;
        $start = (clone $anyDayInIt)->setTime(0, 0)->sub(new DateInterval("P{$offset}D"));

        $days = [];

        foreach (new DatePeriod($start, new DateInterval('P1D'), 7) as $day) {
            $days[] = $day;
        }

        return $days;
    }
}
