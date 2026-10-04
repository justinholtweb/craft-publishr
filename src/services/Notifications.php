<?php

declare(strict_types=1);

namespace justinholtweb\publishr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\Queue;
use craft\web\View;
use DateTime;
use justinholtweb\publishr\models\Comment;
use justinholtweb\publishr\models\Edition;
use justinholtweb\publishr\models\Item;
use justinholtweb\publishr\models\Stage;
use justinholtweb\publishr\models\Subscription;
use justinholtweb\publishr\Plugin;
use justinholtweb\publishr\queue\jobs\SendNotifications;
use justinholtweb\publishr\records\NotificationRecord;
use justinholtweb\publishr\records\SubscriptionRecord;
use justinholtweb\publishr\records\Table;
use Throwable;
use yii\db\IntegrityException;

/**
 * Telling people things.
 *
 * The shape worth explaining is that a notification is a **row first and an email second**. Queuing
 * a mail directly would make "did the reminder go out, and can I send it again" unanswerable, and
 * that is the first question anybody asks when a deadline is missed. A row per intended message,
 * with its own attempt count and error, makes it a question with an answer and a button.
 *
 * The row also carries a `dedupeKey` under a unique index, which is the only thing that makes the
 * daily sweep safe. The sweep runs from cron, from the queue and from a control-panel request, and
 * all three can fire in the same minute. Checking whether a reminder was already sent and then
 * inserting cannot be made safe in PHP; making the insert the thing that has to be won means the
 * losers are told so by an `IntegrityException` instead of by three identical emails.
 */
class Notifications extends Component
{
    public const EVENT_ASSIGNED = 'assigned';
    public const EVENT_STAGE_CHANGED = 'stageChanged';
    public const EVENT_DUE_SOON = 'dueSoon';
    public const EVENT_OVERDUE = 'overdue';
    public const EVENT_REVIEW_DUE = 'reviewDue';
    public const EVENT_PUBLISHED = 'published';
    public const EVENT_EXPIRED = 'expired';
    public const EVENT_COMMENT = 'comment';
    public const EVENT_MENTION = 'mention';

    public const EVENTS = [
        self::EVENT_ASSIGNED,
        self::EVENT_STAGE_CHANGED,
        self::EVENT_DUE_SOON,
        self::EVENT_OVERDUE,
        self::EVENT_REVIEW_DUE,
        self::EVENT_PUBLISHED,
        self::EVENT_EXPIRED,
        self::EVENT_COMMENT,
        self::EVENT_MENTION,
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /** @return array<string, string> Event => label, for the subscription form. */
    public static function eventLabels(): array
    {
        return [
            self::EVENT_ASSIGNED => Craft::t('publishr', 'Assigned to me'),
            self::EVENT_STAGE_CHANGED => Craft::t('publishr', 'Moved to another stage'),
            self::EVENT_DUE_SOON => Craft::t('publishr', 'Due soon'),
            self::EVENT_OVERDUE => Craft::t('publishr', 'Overdue'),
            self::EVENT_REVIEW_DUE => Craft::t('publishr', 'Due a freshness review'),
            self::EVENT_PUBLISHED => Craft::t('publishr', 'Went live'),
            self::EVENT_EXPIRED => Craft::t('publishr', 'Expired'),
            self::EVENT_COMMENT => Craft::t('publishr', 'New comment'),
            self::EVENT_MENTION => Craft::t('publishr', 'Mentioned in a comment'),
        ];
    }

    // ------------------------------------------------------------------ raising

    public function assigned(Item $item, ?int $byUserId = null): void
    {
        if ($item->assigneeId === null || $item->assigneeId === $byUserId) {
            // Assigning something to yourself does not need an email about it.
            return;
        }

        $this->raise(self::EVENT_ASSIGNED, $item, [$item->assigneeId], [
            'by' => $byUserId,
        ]);
    }

    public function stageChanged(Item $item, ?Stage $from, ?Stage $to, ?int $byUserId = null): void
    {
        $this->raise(self::EVENT_STAGE_CHANGED, $item, $this->audienceFor($item, $byUserId), [
            'from' => $from?->name,
            'to' => $to?->name,
            'by' => $byUserId,
        ]);
    }

    public function published(Item $item, ?Entry $entry = null): void
    {
        $this->raise(self::EVENT_PUBLISHED, $item, $this->audienceFor($item), [], $entry);
    }

    public function expired(Item $item, ?Entry $entry = null): void
    {
        $this->raise(self::EVENT_EXPIRED, $item, $this->audienceFor($item), [], $entry);
    }

    public function dueSoon(Item $item, int $days): int
    {
        return $this->raise(self::EVENT_DUE_SOON, $item, $this->audienceFor($item), ['days' => $days],
            null, $this->dayKey(self::EVENT_DUE_SOON, $item));
    }

    public function overdue(Item $item, int $days): int
    {
        return $this->raise(self::EVENT_OVERDUE, $item, $this->audienceFor($item), ['days' => $days],
            null, $this->dayKey(self::EVENT_OVERDUE, $item));
    }

    public function reviewDue(Item $item): int
    {
        return $this->raise(self::EVENT_REVIEW_DUE, $item, $this->audienceFor($item), [],
            null, $this->dayKey(self::EVENT_REVIEW_DUE, $item));
    }

    public function commented(Comment $comment): void
    {
        $plugin = Plugin::getInstance();
        $item = $plugin->items->forElement($comment->elementId, $comment->siteId);

        if ($item === null) {
            return;
        }

        $mentioned = $this->resolveMentions($comment);

        if ($mentioned !== []) {
            $this->raise(self::EVENT_MENTION, $item, $mentioned, [
                'by' => $comment->authorId,
                'body' => mb_substr($comment->body, 0, 500),
            ]);
        }

        $audience = array_values(array_diff($this->audienceFor($item, $comment->authorId), $mentioned));

        $this->raise(self::EVENT_COMMENT, $item, $audience, [
            'by' => $comment->authorId,
            'body' => mb_substr($comment->body, 0, 500),
        ]);
    }

    /**
     * Who should hear about something happening to this piece.
     *
     * The assignee is always in, without ever having subscribed — that is not a subscription, it
     * is the job. Everyone else is in because they said so.
     *
     * @return int[] User IDs.
     */
    public function audienceFor(Item $item, ?int $exceptUserId = null): array
    {
        $ids = [];

        if ($item->assigneeId !== null) {
            $ids[] = $item->assigneeId;
        }

        if (Edition::allowsSubscriptions(Plugin::getInstance()->isPro())) {
            $entry = $item->getElement();
            $sectionUid = $entry?->getSection()?->uid;

            $scopes = [
                'or',
                ['scope' => Subscription::SCOPE_ALL],
                ['scope' => Subscription::SCOPE_ELEMENT, 'elementId' => $item->elementId],
            ];

            if ($sectionUid !== null) {
                $scopes[] = ['scope' => Subscription::SCOPE_SECTION, 'sectionUid' => $sectionUid];
            }

            foreach (SubscriptionRecord::find()->where($scopes)->all() as $record) {
                $ids[] = (int)$record->userId;
            }
        }

        $ids = array_values(array_unique($ids));

        if ($exceptUserId !== null) {
            // Nobody needs an email about the thing they just did.
            $ids = array_values(array_diff($ids, [$exceptUserId]));
        }

        return $ids;
    }

    /**
     * Queue a message for each recipient who wants it.
     *
     * @param int[] $userIds
     * @param array<string, mixed> $payload
     * @param string|null $dedupeKey Set for anything the sweep can raise more than once.
     */
    public function raise(
        string $event,
        Item $item,
        array $userIds,
        array $payload = [],
        ?Entry $entry = null,
        ?string $dedupeKey = null,
    ): int {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->notificationsEnabled || !Edition::allowsNotifications($plugin->isPro()) || $userIds === []) {
            return 0;
        }

        $entry ??= $item->getElement();
        $written = 0;

        foreach (array_unique($userIds) as $userId) {
            if (!$this->wants((int)$userId, $event, $item)) {
                continue;
            }

            $record = new NotificationRecord();
            $record->userId = (int)$userId;
            $record->elementId = $item->elementId;
            $record->siteId = $item->siteId;
            $record->event = $event;
            $record->payload = $payload + [
                'title' => $entry?->title,
                'url' => $entry?->getCpEditUrl(),
                'stage' => $item->getStage()?->name,
                'due' => $item->dueDate?->format(DATE_ATOM),
            ];
            $record->status = self::STATUS_PENDING;
            $record->dedupeKey = $dedupeKey !== null ? $dedupeKey . ':' . $userId : null;

            try {
                if ($record->save(false)) {
                    $written++;
                }
            } catch (IntegrityException) {
                // Somebody else won the race for this dedupe key. That is the mechanism working.
            }
        }

        if ($written > 0) {
            $settings->queueNotifications
                ? Queue::push(new SendNotifications())
                : $this->sendPending();
        }

        return $written;
    }

    /**
     * Whether a user wants an event about a piece.
     *
     * The assignee always does. Everybody else is filtered by what their subscription asked for —
     * a managing editor watching a section usually wants "went live", not "somebody moved a card".
     */
    private function wants(int $userId, string $event, Item $item): bool
    {
        if ($item->assigneeId === $userId) {
            return true;
        }

        // Addressed to a person by name. A mention or an assignment that only reached people who
        // had already subscribed would miss exactly the colleague it was for.
        if (in_array($event, [self::EVENT_MENTION, self::EVENT_ASSIGNED], true)) {
            return true;
        }

        $records = SubscriptionRecord::find()
            ->where(['userId' => $userId])
            ->all();

        if ($records === []) {
            return false;
        }

        foreach ($records as $record) {
            $events = $this->decode($record->events);

            if ($events === [] || in_array($event, $events, true)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------- sending

    /**
     * Send everything pending.
     *
     * Each message is committed as sent or failed on its own row, so one bad address does not
     * strand the rest of the batch — the failure mode of a single try/catch around a whole loop.
     *
     * @return array{sent: int, failed: int}
     */
    public function sendPending(int $limit = 200): array
    {
        // Cron, the queue job every raise() pushes, and the CP's "Try again" can all arrive at
        // once, and on a host with several queue workers often do. The dedupe key only protects
        // the insert; without a lock, two of them would send the same pending rows twice.
        $mutex = Craft::$app->getMutex();
        $lock = 'publishr:send-notifications';

        if (!$mutex->acquire($lock)) {
            return ['sent' => 0, 'failed' => 0];
        }

        try {
            return $this->sendBatch($limit);
        } finally {
            $mutex->release($lock);
        }
    }

    /** @return array{sent: int, failed: int} */
    private function sendBatch(int $limit): array
    {
        $sent = 0;
        $failed = 0;

        $records = NotificationRecord::find()
            ->where(['status' => self::STATUS_PENDING])
            ->andWhere(['<', 'attempts', 5])
            ->orderBy(['dateCreated' => SORT_ASC])
            ->limit($limit)
            ->all();

        foreach ($records as $record) {
            $record->attempts = (int)$record->attempts + 1;

            try {
                if ($this->send($record)) {
                    $record->status = self::STATUS_SENT;
                    $record->sentAt = Db::prepareDateForDb(DateTimeHelper::now());
                    $record->error = null;
                    $sent++;
                } else {
                    $record->status = $record->attempts >= 5 ? self::STATUS_FAILED : self::STATUS_PENDING;
                    $record->error = 'The mailer refused the message.';
                    $failed++;
                }
            } catch (Throwable $e) {
                $record->status = $record->attempts >= 5 ? self::STATUS_FAILED : self::STATUS_PENDING;
                $record->error = mb_substr($e->getMessage(), 0, 1000);
                $failed++;
            }

            $record->save(false);
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function send(NotificationRecord $record): bool
    {
        $user = Craft::$app->getUsers()->getUserById((int)$record->userId);

        if ($user === null || $user->email === null) {
            return false;
        }

        $payload = $this->decode($record->payload);
        $labels = self::eventLabels();

        $subject = Craft::t('publishr', '{event}: {title}', [
            'event' => $labels[$record->event] ?? $record->event,
            'title' => $payload['title'] ?? Craft::t('publishr', 'Untitled'),
        ]);

        $body = $this->render('publishr/_emails/notification', [
            'user' => $user,
            'event' => $record->event,
            'label' => $labels[$record->event] ?? $record->event,
            'payload' => $payload,
        ]);

        return Craft::$app->getMailer()
            ->compose()
            ->setTo($user)
            ->setSubject($subject)
            ->setHtmlBody($body)
            ->send();
    }

    /**
     * Render a control-panel template for an email.
     *
     * The mode has to be set explicitly. A queue job runs with no template mode set at all, and
     * the default is `site` — so the same template that renders in the CP throws
     * `TemplateLoaderException` the moment the work moves to the queue, which is a bug that only
     * ever appears in production.
     */
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

    // ------------------------------------------------------------- subscriptions

    /** @return Subscription[] */
    public function subscriptionsFor(int $userId): array
    {
        $out = [];

        foreach (SubscriptionRecord::find()->where(['userId' => $userId])->all() as $record) {
            $out[] = new Subscription([
                'id' => (int)$record->id,
                'userId' => (int)$record->userId,
                'scope' => $record->scope,
                'elementId' => $record->elementId !== null ? (int)$record->elementId : null,
                'sectionUid' => $record->sectionUid,
                'events' => $this->decode($record->events),
                'uid' => $record->uid,
            ]);
        }

        return $out;
    }

    public function isWatching(int $userId, int $elementId): bool
    {
        return SubscriptionRecord::find()
            ->where(['userId' => $userId, 'scope' => Subscription::SCOPE_ELEMENT, 'elementId' => $elementId])
            ->exists();
    }

    /** Follow or unfollow one piece. Returns the state it ended in. */
    public function toggleWatch(int $userId, int $elementId): bool
    {
        $record = SubscriptionRecord::findOne([
            'userId' => $userId,
            'scope' => Subscription::SCOPE_ELEMENT,
            'elementId' => $elementId,
        ]);

        if ($record !== null) {
            $record->delete();

            return false;
        }

        $record = new SubscriptionRecord();
        $record->userId = $userId;
        $record->scope = Subscription::SCOPE_ELEMENT;
        $record->elementId = $elementId;
        $record->events = [];
        $record->save(false);

        return true;
    }

    public function saveSubscription(Subscription $subscription): bool
    {
        if (!$subscription->validate()) {
            return false;
        }

        $record = $subscription->id !== null ? SubscriptionRecord::findOne($subscription->id) : new SubscriptionRecord();

        if ($record === null) {
            return false;
        }

        $record->userId = $subscription->userId;
        $record->scope = $subscription->scope;
        $record->elementId = $subscription->elementId;
        $record->sectionUid = $subscription->sectionUid;
        $record->events = array_values($subscription->events);

        return $record->save(false);
    }

    public function deleteSubscription(int $id, int $userId): bool
    {
        return (bool)SubscriptionRecord::deleteAll(['id' => $id, 'userId' => $userId]);
    }

    // ---------------------------------------------------------------------- misc

    /** @return int[] User IDs actually named by an @mention in a comment. */
    private function resolveMentions(Comment $comment): array
    {
        $ids = [];

        foreach ($comment->mentionedUsernames() as $username) {
            $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($username);

            if ($user instanceof User && (int)$user->id !== $comment->authorId) {
                $ids[] = (int)$user->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * A dedupe key that changes once a day.
     *
     * So "this is due tomorrow" is sent once on the day it is true, and again the following day if
     * it is still true — rather than once ever, which would mean a reminder lost to a broken mail
     * server is lost for good.
     */
    private function dayKey(string $event, Item $item): string
    {
        return sprintf('%s:%d:%d:%s', $event, $item->elementId, $item->siteId, DateTimeHelper::now()->format('Y-m-d'));
    }

    /** @return array<int, array<string, mixed>> Pending and failed rows, for the CP. */
    public function problems(int $limit = 100): array
    {
        return (new Query())
            ->from([Table::NOTIFICATIONS])
            ->where(['status' => [self::STATUS_PENDING, self::STATUS_FAILED]])
            ->andWhere(['>', 'attempts', 0])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    public function retry(int $id): bool
    {
        $record = NotificationRecord::findOne($id);

        if ($record === null) {
            return false;
        }

        $record->status = self::STATUS_PENDING;
        $record->attempts = 0;
        $record->error = null;

        return $record->save(false);
    }

    public function prune(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::NOTIFICATIONS, [
                'and',
                ['status' => self::STATUS_SENT],
                ['<', 'dateCreated', Db::prepareDateForDb(new DateTime("-$days days"))],
            ])
            ->execute();
    }

    private function decode(mixed $value): array
    {
        for ($i = 0; $i < 3 && is_string($value); $i++) {
            $value = Json::decodeIfJson($value);
        }

        return is_array($value) ? $value : [];
    }
}
