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
use DateTime;
use justinholtweb\publishr\models\Edition;
use justinholtweb\publishr\models\HistoryEntry;
use justinholtweb\publishr\models\Item;
use justinholtweb\publishr\models\Policy;
use justinholtweb\publishr\Plugin;
use justinholtweb\publishr\records\ItemRecord;
use justinholtweb\publishr\records\Table;

/**
 * Content decay: when a page was last agreed to be true, and when somebody should check again.
 *
 * The problem this exists for is that **nothing on a website tells you it has gone out of date.**
 * A pricing page from 2021 renders exactly as confidently as one from this morning. The only
 * signal a CMS offers is `dateUpdated`, which records when a file was touched — a typo fix, a
 * re-save during a migration — not when a person last decided the content was still correct.
 * Those are different facts, and conflating them is why "we'll audit the site annually" never
 * survives its second year.
 *
 * So Publishr stores the second fact explicitly: `lastReviewedAt`, set only by a human pressing a
 * button that says they have read it. Everything else here is arithmetic on that date.
 */
class Freshness extends Component
{
    /**
     * When an item is next due a review.
     *
     * Counted from the last human review if there is one, and from the publication date if there
     * is not. **Never from "now"** — a policy applied to a ten-year archive that reset everything
     * to "reviewed today" would report a perfectly clean site and hide precisely the backlog the
     * policy was bought to find. Starting from the post date means the first sweep produces the
     * real, uncomfortable number.
     */
    public function nextReviewDate(Item $item, ?DateTime $from = null, ?Policy $policy = null, ?Entry $entry = null): ?DateTime
    {
        $entry ??= $item->getElement();
        $policy ??= $this->policyFor($item, $entry);

        if ($policy === null || !$policy->enabled) {
            return null;
        }

        $anchor = $from
            ?? $item->lastReviewedAt
            ?? $entry?->postDate
            ?? $entry?->dateCreated
            ?? DateTimeHelper::now();

        return (clone $anchor)->add(new DateInterval('P' . max(1, $policy->intervalDays) . 'D'));
    }

    public function policyFor(Item $item, ?Entry $entry = null): ?Policy
    {
        $policies = Plugin::getInstance()->policies;

        $explicit = $policies->getPolicyById($item->policyId);

        if ($explicit !== null) {
            return $explicit;
        }

        $entry ??= $item->getElement();

        return $entry !== null ? $policies->forEntry($entry) : null;
    }

    /**
     * Put an item under whichever policy covers it, and set its next review date.
     *
     * Called when a piece goes live, and by the sweep. Idempotent: running it twice on an item
     * that has not been reviewed in between produces the same date.
     */
    public function applyPolicy(Item $item, ?Entry $entry = null, ?DateTime $publishedAt = null): bool
    {
        if (!Edition::allowsFreshness(Plugin::getInstance()->isPro())) {
            return false;
        }

        $entry ??= $item->getElement();
        $policy = $this->policyFor($item, $entry);

        if ($policy === null || !$policy->enabled) {
            return false;
        }

        $item->policyId = $policy->id;

        $anchor = $item->lastReviewedAt ?? $publishedAt ?? $entry?->postDate;
        $item->reviewDue = $this->nextReviewDate($item, $anchor, $policy, $entry);

        // Only the *reviewer* is set here, and only when the policy names one. Reassigning the
        // piece itself would take a live article away from the person still working on it, months
        // after it went out, because a policy said so.
        if ($policy->assignTo === Policy::ASSIGN_USER && $policy->assigneeId !== null && $item->assigneeId === null) {
            $item->assigneeId = $policy->assigneeId;
        } elseif ($policy->assignTo === Policy::ASSIGN_AUTHOR && $item->assigneeId === null) {
            $item->assigneeId = $entry?->getAuthorId();
        }

        return true;
    }

    /**
     * Give every managed entry that has no review date one.
     *
     * Bounded by `maxReviewsPerSweep`: a policy applied to a large archive matches thousands of
     * entries at once, and doing them all in one request is a timeout. The next sweep simply picks
     * up where this one stopped, because the query's condition — "review date is null" — is
     * self-advancing.
     *
     * @return int How many were scheduled.
     */
    public function sweep(?int $limit = null): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->freshnessEnabled || !Edition::allowsFreshness($plugin->isPro())) {
            return 0;
        }

        $limit ??= $settings->maxReviewsPerSweep;
        $scheduled = 0;

        $records = ItemRecord::find()
            ->where(['reviewDue' => null])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->all();

        foreach ($records as $record) {
            $item = $plugin->items->forElement((int)$record->elementId, (int)$record->siteId);

            if ($item === null) {
                continue;
            }

            $entry = $item->getElement();

            // Only live content decays. A draft that has never been published has no shelf life
            // to have run out, and scheduling a review for it would put an unwritten piece on the
            // "needs re-checking" list.
            if ($entry === null || $entry->postDate === null || $entry->postDate > DateTimeHelper::now()) {
                continue;
            }

            if ($this->applyPolicy($item, $entry) && $item->reviewDue !== null) {
                $plugin->items->save($item);
                $scheduled++;
            }
        }

        return $scheduled;
    }

    /**
     * Items whose review has come due, oldest first.
     *
     * @return Item[]
     */
    public function due(?int $siteId = null, int $limit = 200, int $withinDays = 0): array
    {
        $cutoff = DateTimeHelper::now();

        if ($withinDays > 0) {
            $cutoff = (clone $cutoff)->add(new DateInterval("P{$withinDays}D"));
        }

        $query = ItemRecord::find()
            ->where(['not', ['reviewDue' => null]])
            ->andWhere(['<=', 'reviewDue', Db::prepareDateForDb($cutoff)])
            ->orderBy(['reviewDue' => SORT_ASC])
            ->limit($limit);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $items = [];

        foreach ($query->all() as $record) {
            $item = Plugin::getInstance()->items->forElement((int)$record->elementId, (int)$record->siteId);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * How stale a piece is, 0–100, where 100 is "overdue by a full interval or more".
     *
     * A number rather than a boolean because a governance report has to be sortable: "47 pages are
     * overdue" is a fact nobody can act on, and "these 12 are the worst" is a morning's work.
     */
    public function staleness(Item $item, ?DateTime $now = null): int
    {
        if ($item->reviewDue === null) {
            return 0;
        }

        $now ??= DateTimeHelper::now();

        if ($item->reviewDue > $now) {
            return 0;
        }

        $policy = $this->policyFor($item);
        $interval = max(1, $policy?->intervalDays ?? 180);
        $overdueDays = (int)floor(($now->getTimestamp() - $item->reviewDue->getTimestamp()) / 86400);

        return (int)min(100, round($overdueDays / $interval * 100));
    }

    /** Record that a review fell due, so the history shows the deadline as well as the response. */
    public function logDue(Item $item): void
    {
        Plugin::getInstance()->items->log($item, HistoryEntry::EVENT_REVIEW_DUE, null, [
            'toValue' => $item->reviewDue?->format('Y-m-d'),
        ]);
    }

    /** @return array{tracked: int, scheduled: int, due: int, neverReviewed: int} */
    public function summary(?int $siteId = null): array
    {
        $base = static function() use ($siteId) {
            $q = (new Query())->from([Table::ITEMS]);

            return $siteId !== null ? $q->where(['siteId' => $siteId]) : $q;
        };

        $now = Db::prepareDateForDb(DateTimeHelper::now());

        return [
            'tracked' => (int)$base()->count(),
            'scheduled' => (int)$base()->andWhere(['not', ['reviewDue' => null]])->count(),
            'due' => (int)$base()->andWhere(['not', ['reviewDue' => null]])->andWhere(['<=', 'reviewDue', $now])->count(),
            'neverReviewed' => (int)$base()->andWhere(['lastReviewedAt' => null])->count(),
        ];
    }
}
