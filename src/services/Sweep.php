<?php

declare(strict_types=1);

namespace justinholtweb\publishr\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\web\View;
use DateInterval;
use justinholtweb\publishr\models\Edition;
use justinholtweb\publishr\models\Item;
use justinholtweb\publishr\Plugin;
use justinholtweb\publishr\records\ItemRecord;
use Throwable;

/**
 * The thing that runs on a schedule.
 *
 * One entry point, called from the console command, from Craft's garbage collection and from the
 * queue, because a site that has cron has one of those and a site that does not has the others.
 * Every step is idempotent and every notification it raises carries a day-scoped dedupe key, so
 * running it three times in a minute costs three queries and sends nothing twice.
 */
class Sweep extends Component
{
    /**
     * @return array{reviewsScheduled: int, dueSoon: int, overdue: int, reviewsDue: int, advanced: int, sent: int}
     */
    public function run(bool $sendDigest = false): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $result = [
            'reviewsScheduled' => 0,
            'dueSoon' => 0,
            'overdue' => 0,
            'reviewsDue' => 0,
            'advanced' => 0,
            'sent' => 0,
        ];

        // Catching up the published stage comes first. Everything after it reads the stage column,
        // and a piece that went live last night should not be chased for a deadline it has met.
        $result['advanced'] = $this->advancePublished();

        if ($settings->freshnessEnabled) {
            $result['reviewsScheduled'] = $plugin->freshness->sweep();
            $result['reviewsDue'] = $this->raiseReviewsDue();
        }

        if ($settings->notificationsEnabled && Edition::allowsNotifications($plugin->isPro())) {
            $result['dueSoon'] = $this->raiseDueSoon();
            $result['overdue'] = $this->raiseOverdue();

            if ($sendDigest && $settings->digestEnabled && Edition::allowsDigest($plugin->isPro())) {
                $result['sent'] += $this->sendDigest();
            }

            $result['sent'] += $plugin->notifications->sendPending()['sent'];
        }

        if ($settings->historyRetentionDays > 0) {
            $plugin->items->pruneHistory($settings->historyRetentionDays);
            $plugin->notifications->prune($settings->historyRetentionDays);
        }

        return $result;
    }

    /**
     * Move anything that is live but not on the published stage.
     *
     * The safety net under the Alarm Clock integration, and the whole story on a site that does
     * not have Alarm Clock installed. Bounded, and it only ever moves *forward* — a piece somebody
     * deliberately pulled back to "Needs edit" while it is still live stays where they put it,
     * which is why the check is "has it ever been recorded as published" rather than "is it live".
     */
    public function advancePublished(int $limit = 200): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $published = $plugin->stages->getPublishedStage();

        if (!$settings->advanceOnPublish || $published === null) {
            return 0;
        }

        $records = ItemRecord::find()
            ->where(['not', ['stageId' => $published->id]])
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit($limit)
            ->all();

        $moved = 0;

        foreach ($records as $record) {
            $item = $plugin->items->forElement((int)$record->elementId, (int)$record->siteId);
            $entry = $item?->getElement();

            if ($item === null || $entry === null || $entry->getStatus() !== Entry::STATUS_LIVE) {
                continue;
            }

            // Only a piece that has never been through the published stage. Otherwise a deliberate
            // move backwards would be undone by the next sweep, forever.
            if ($this->hasBeenPublished($item, $published->id)) {
                continue;
            }

            if ($settings->clearDueOnPublish) {
                $item->dueDate = null;
            }

            if ($settings->freshnessEnabled) {
                $plugin->freshness->applyPolicy($item, $entry, $entry->postDate);
            }

            $plugin->items->save($item);

            if ($plugin->items->moveToStage($item, $published->id, null, Craft::t('publishr', 'Live on the site'), true)) {
                $moved++;
            }
        }

        return $moved;
    }

    private function hasBeenPublished(Item $item, int $publishedStageId): bool
    {
        foreach (Plugin::getInstance()->items->history($item->elementId, $item->siteId, 200) as $entry) {
            if ($entry->toStageId === $publishedStageId) {
                return true;
            }
        }

        return false;
    }

    /** Nudge anybody whose deadline is inside the warning window. */
    public function raiseDueSoon(): int
    {
        $plugin = Plugin::getInstance();
        $days = max(0, $plugin->getSettings()->dueSoonDays);
        $now = DateTimeHelper::now();
        $until = (clone $now)->add(new DateInterval('P' . max(1, $days) . 'D'));

        $records = ItemRecord::find()
            ->where(['not', ['dueDate' => null]])
            ->andWhere(['>=', 'dueDate', Db::prepareDateForDb($now)])
            ->andWhere(['<', 'dueDate', Db::prepareDateForDb($until)])
            ->limit(500)
            ->all();

        $raised = 0;

        foreach ($records as $record) {
            $item = $plugin->items->forElement((int)$record->elementId, (int)$record->siteId);

            if ($item === null || $this->isFinished($item)) {
                continue;
            }

            $raised += $plugin->notifications->dueSoon($item, (int)$item->daysUntilDue($now));
        }

        return $raised;
    }

    public function raiseOverdue(): int
    {
        $plugin = Plugin::getInstance();
        $now = DateTimeHelper::now();
        $raised = 0;

        foreach ($plugin->items->overdue(null, 500) as $item) {
            if ($this->isFinished($item)) {
                continue;
            }

            $raised += $plugin->notifications->overdue($item, abs((int)$item->daysUntilDue($now)));
        }

        return $raised;
    }

    public function raiseReviewsDue(): int
    {
        $plugin = Plugin::getInstance();
        $raised = 0;

        foreach ($plugin->freshness->due(null, 500) as $item) {
            $plugin->freshness->logDue($item);
            $raised += $plugin->notifications->reviewDue($item);
        }

        return $raised;
    }

    /** A piece already on the published stage has met its deadline; chasing it is noise. */
    private function isFinished(Item $item): bool
    {
        $published = Plugin::getInstance()->stages->getPublishedStage();

        return $published !== null && $item->stageId === $published->id;
    }

    // -------------------------------------------------------------------- digest

    /**
     * One mail a morning with the state of the desk.
     *
     * Sent to everybody who has anything assigned to them plus any extra addresses configured, and
     * **only to people with something in it** — a daily email that says "nothing to report" is an
     * email people filter, and once it is filtered the day it matters is filtered too.
     */
    public function sendDigest(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!Edition::allowsDigest($plugin->isPro())) {
            return 0;
        }

        $sent = 0;

        foreach ($this->digestRecipients() as $userId) {
            $user = Craft::$app->getUsers()->getUserById($userId);

            if ($user === null || $user->email === null) {
                continue;
            }

            $data = $this->digestFor($userId, $settings->digestHorizonDays);

            if ($data['total'] === 0) {
                continue;
            }

            try {
                $body = $this->render('publishr/_emails/digest', [
                    'user' => $user,
                    'data' => $data,
                    'horizon' => $settings->digestHorizonDays,
                ]);

                if (Craft::$app->getMailer()->compose()
                    ->setTo($user)
                    ->setSubject(Craft::t('publishr', 'Your editorial digest'))
                    ->setHtmlBody($body)
                    ->send()
                ) {
                    $sent++;
                }
            } catch (Throwable $e) {
                Craft::warning('Publishr digest failed for user ' . $userId . ': ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            }
        }

        return $sent;
    }

    /** @return int[] */
    private function digestRecipients(): array
    {
        $ids = ItemRecord::find()
            ->select(['assigneeId'])
            ->distinct()
            ->where(['not', ['assigneeId' => null]])
            ->column();

        $ids = array_map('intval', $ids);

        foreach (Plugin::getInstance()->getSettings()->digestRecipients as $email) {
            $user = Craft::$app->getUsers()->getUserByUsernameOrEmail((string)$email);

            if ($user !== null) {
                $ids[] = (int)$user->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return array{overdue: Item[], dueSoon: Item[], reviews: Item[], total: int} */
    public function digestFor(int $userId, int $horizonDays = 7): array
    {
        $plugin = Plugin::getInstance();
        $now = DateTimeHelper::now();
        $horizon = (clone $now)->add(new DateInterval('P' . max(1, $horizonDays) . 'D'));

        $mine = $plugin->items->forAssignee($userId, null, 200);

        $overdue = [];
        $dueSoon = [];
        $reviews = [];

        foreach ($mine as $item) {
            if ($this->isFinished($item)) {
                continue;
            }

            if ($item->dueDate !== null) {
                if ($item->dueDate < $now) {
                    $overdue[] = $item;
                } elseif ($item->dueDate < $horizon) {
                    $dueSoon[] = $item;
                }
            }

            if ($item->reviewDue !== null && $item->reviewDue < $horizon) {
                $reviews[] = $item;
            }
        }

        return [
            'overdue' => $overdue,
            'dueSoon' => $dueSoon,
            'reviews' => $reviews,
            'total' => count($overdue) + count($dueSoon) + count($reviews),
        ];
    }

    private function render(string $template, array $variables): string
    {
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_CP);

            return $view->renderTemplate($template, $variables);
        } finally {
            $view->setTemplateMode($mode);
        }
    }
}
