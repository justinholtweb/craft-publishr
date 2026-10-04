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
use DateTime;
use justinholtweb\publishr\events\StageChangeEvent;
use justinholtweb\publishr\models\GateReport;
use justinholtweb\publishr\models\HistoryEntry;
use justinholtweb\publishr\models\Item;
use justinholtweb\publishr\models\Stage;
use justinholtweb\publishr\Plugin;
use justinholtweb\publishr\records\HistoryRecord;
use justinholtweb\publishr\records\ItemRecord;
use justinholtweb\publishr\records\Table;
use Throwable;
use yii\db\Expression;

/**
 * The editorial record for a piece of content.
 *
 * Every read and write in the plugin goes through here, and every one of them keys on the
 * **canonical** element ID. See {@see Item} for why.
 */
class Items extends Component
{
    /** @event StageChangeEvent Fired before a stage move, cancellable. */
    public const EVENT_BEFORE_STAGE_CHANGE = 'beforeStageChange';

    /** @event StageChangeEvent Fired once a stage move has been written. */
    public const EVENT_AFTER_STAGE_CHANGE = 'afterStageChange';

    /**
     * The editorial record for an entry, creating one if it is missing.
     *
     * `$create` defaults to false so that merely *rendering* an entry never writes a row — the
     * sidebar, the calendar and the front end all ask this question, and a read that writes turns
     * a page view into a deadlock waiting to happen.
     */
    public function forEntry(Entry $entry, bool $create = false): ?Item
    {
        $elementId = (int)$entry->getCanonicalId();
        $siteId = (int)$entry->siteId;

        $item = $this->forElement($elementId, $siteId);

        if ($item !== null) {
            // A draft is not cached as the item's element: the item describes the canonical piece.
            // Left unloaded, getElement() resolves elementId — the canonical entry, or for a piece
            // that has never been published, the unpublished draft itself. Nulling it here would
            // make every gated move on a new piece skip its requirements.
            if (!$entry->getIsDraft()) {
                $item->setElement($entry);
            }

            return $item;
        }

        if (!$create) {
            return null;
        }

        return $this->create($elementId, $siteId);
    }

    public function forElement(int $elementId, int $siteId): ?Item
    {
        $record = ItemRecord::findOne(['elementId' => $elementId, 'siteId' => $siteId]);

        return $record !== null ? $this->toModel($record) : null;
    }

    /**
     * Editorial records for many elements at once.
     *
     * The calendar and the overview both draw hundreds of rows; asking per row is the difference
     * between one query and three hundred.
     *
     * @param int[] $elementIds
     * @return array<int, Item> Keyed by element ID.
     */
    public function forElements(array $elementIds, int $siteId): array
    {
        if ($elementIds === []) {
            return [];
        }

        $items = [];

        foreach (ItemRecord::find()->where(['elementId' => $elementIds, 'siteId' => $siteId])->all() as $record) {
            $items[(int)$record->elementId] = $this->toModel($record);
        }

        return $items;
    }

    /** Start tracking an element, putting it on the default stage. */
    public function create(int $elementId, int $siteId, ?int $stageId = null): Item
    {
        $item = new Item([
            'elementId' => $elementId,
            'siteId' => $siteId,
            'stageId' => $stageId ?? Plugin::getInstance()->stages->getDefaultStage()?->id,
        ]);

        $this->save($item);
        $this->log($item, HistoryEntry::EVENT_CREATED);

        return $item;
    }

    /**
     * Write an item.
     *
     * An upsert on `(elementId, siteId)` rather than find-then-insert. Two control-panel requests
     * saving the same entry in the same second is not exotic — Craft's autosave does it — and
     * check-then-write loses that race against a unique index every time.
     */
    public function save(Item $item): bool
    {
        if (!$item->validate()) {
            return false;
        }

        $record = $item->id !== null
            ? ItemRecord::findOne($item->id)
            : ItemRecord::findOne(['elementId' => $item->elementId, 'siteId' => $item->siteId]);

        $record ??= new ItemRecord();

        $record->elementId = $item->elementId;
        $record->siteId = $item->siteId;
        $record->stageId = $item->stageId;
        $record->assigneeId = $item->assigneeId;
        $record->dueDate = Db::prepareDateForDb($item->dueDate);
        $record->reviewDue = Db::prepareDateForDb($item->reviewDue);
        $record->lastReviewedAt = Db::prepareDateForDb($item->lastReviewedAt);
        $record->lastReviewedBy = $item->lastReviewedBy;
        $record->policyId = $item->policyId;
        $record->brief = $item->brief;
        $record->pinned = $item->pinned;
        $record->gateState = $item->gateState;
        $record->gatesCheckedAt = Db::prepareDateForDb($item->gatesCheckedAt);

        if (!$record->save(false)) {
            return false;
        }

        $item->id = (int)$record->id;
        $item->uid = $record->uid;

        return true;
    }

    public function deleteForElement(int $elementId, int $siteId): bool
    {
        return (bool)ItemRecord::deleteAll(['elementId' => $elementId, 'siteId' => $siteId]);
    }

    // ---------------------------------------------------------------- transitions

    /**
     * Move a piece to a stage.
     *
     * Where the governance actually happens. A move *into* a gated stage runs the checklist first
     * and refuses if a required gate fails, unless `$force` — which only a caller holding the
     * override permission should ever pass, and which is written into the history with the
     * offending gates named, so "who signed this off anyway" has an answer.
     *
     * @param string[] $problems Filled with the reasons a refused move was refused.
     */
    public function moveToStage(
        Item $item,
        ?int $stageId,
        ?int $userId = null,
        ?string $note = null,
        bool $force = false,
        array &$problems = [],
    ): bool {
        $plugin = Plugin::getInstance();
        $from = $item->stageId;

        if ($from === $stageId) {
            return true;
        }

        $target = $plugin->stages->getStageById($stageId);

        if ($stageId !== null && $target === null) {
            $problems[] = Craft::t('publishr', 'That stage no longer exists.');

            return false;
        }

        $entry = $item->getElement();

        $overridden = [];

        // A gated stage with nothing to evaluate is refused, not waved through. "I don't know" is
        // never "no" for a single gate; an entry that cannot be found at all is not a gate.
        if ($target !== null && $target->gated && $entry === null && !$force) {
            $problems[] = Craft::t('publishr', 'The entry could not be found, so its requirements could not be checked.');

            return false;
        }

        if ($target !== null && $target->gated && $entry !== null) {
            $report = $plugin->gates->evaluate($entry, $target);
            $this->storeGateReport($item, $report);

            $blocking = $report->blocking();

            if ($blocking !== []) {
                $overridden = array_map(static fn($r) => $r->gateName, $blocking);

                if (!$force) {
                    foreach ($blocking as $result) {
                        $problems[] = $result->message ?? $result->gateName;
                    }

                    return false;
                }
            }
        }

        $event = new StageChangeEvent([
            'item' => $item,
            'fromStage' => $plugin->stages->getStageById($from),
            'toStage' => $target,
            'userId' => $userId,
            'note' => $note,
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_STAGE_CHANGE)) {
            $this->trigger(self::EVENT_BEFORE_STAGE_CHANGE, $event);

            if (!$event->isValid) {
                $problems[] = Craft::t('publishr', 'The move was cancelled.');

                return false;
            }
        }

        $item->stageId = $stageId;

        if (!$this->save($item)) {
            return false;
        }

        $this->log($item, HistoryEntry::EVENT_STAGE, $userId, [
            'fromStageId' => $from,
            'toStageId' => $stageId,
            'note' => $note,
        ]);

        if ($overridden !== []) {
            $this->log($item, HistoryEntry::EVENT_GATE_OVERRIDE, $userId, [
                'toValue' => implode(', ', $overridden),
            ]);
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_STAGE_CHANGE)) {
            $this->trigger(self::EVENT_AFTER_STAGE_CHANGE, $event);
        }

        $plugin->notifications->stageChanged($item, $plugin->stages->getStageById($from), $target, $userId);

        return true;
    }

    public function assign(Item $item, ?int $assigneeId, ?int $userId = null): bool
    {
        if ($item->assigneeId === $assigneeId) {
            return true;
        }

        // A user ID that does not exist is a foreign-key failure waiting in save(), and a 500.
        if ($assigneeId !== null && !User::find()->id($assigneeId)->status(null)->exists()) {
            return false;
        }

        $was = $item->getAssignee()?->friendlyName;
        $item->assigneeId = $assigneeId;

        if (!$this->save($item)) {
            return false;
        }

        $this->log($item, HistoryEntry::EVENT_ASSIGNED, $userId, [
            'fromValue' => $was,
            'toValue' => $item->getAssignee()?->friendlyName,
        ]);

        Plugin::getInstance()->notifications->assigned($item, $userId);

        return true;
    }

    public function setDueDate(Item $item, ?DateTime $due, ?int $userId = null): bool
    {
        if ($this->sameInstant($item->dueDate, $due)) {
            return true;
        }

        $was = $item->dueDate?->format('Y-m-d');
        $item->dueDate = $due;

        if (!$this->save($item)) {
            return false;
        }

        $this->log($item, HistoryEntry::EVENT_DUE, $userId, [
            'fromValue' => $was,
            'toValue' => $due?->format('Y-m-d'),
        ]);

        return true;
    }

    /** Stamp a freshness review as done and roll the next one forward. */
    public function markReviewed(Item $item, ?int $userId = null, ?string $note = null): bool
    {
        $now = DateTimeHelper::now();

        $item->lastReviewedAt = $now;
        $item->lastReviewedBy = $userId;
        $item->reviewDue = Plugin::getInstance()->freshness->nextReviewDate($item, $now);

        if (!$this->save($item)) {
            return false;
        }

        $this->log($item, HistoryEntry::EVENT_REVIEWED, $userId, [
            'toValue' => $item->reviewDue?->format('Y-m-d'),
            'note' => $note,
        ]);

        return true;
    }

    public function storeGateReport(Item $item, GateReport $report): void
    {
        // Ticks are a person's word about this version of the text, and the report was computed
        // from them. A refused move or a re-check must not wipe them while they are still true.
        $ticks = $item->gateState['ticks'] ?? null;
        $state = $report->toArray();

        if ($ticks && $item->gateStateIsFresh()) {
            $state['ticks'] = $ticks;
        }

        $item->gateState = $state;
        $item->gatesCheckedAt = $report->checkedAt ?? DateTimeHelper::now();

        $this->save($item);
    }

    // ------------------------------------------------------------------- history

    /** @param array<string, mixed> $extra */
    public function log(Item $item, string $event, ?int $userId = null, array $extra = []): void
    {
        $record = new HistoryRecord();
        $record->elementId = $item->elementId;
        $record->siteId = $item->siteId;
        $record->event = $event;
        $record->userId = $userId;
        $record->fromStageId = $extra['fromStageId'] ?? null;
        $record->toStageId = $extra['toStageId'] ?? null;
        $record->fromValue = $this->trim($extra['fromValue'] ?? null);
        $record->toValue = $this->trim($extra['toValue'] ?? null);
        $record->note = $extra['note'] ?? null;

        try {
            $record->save(false);
        } catch (Throwable $e) {
            // History is valuable but never worth failing the thing it is describing. A stage move
            // that succeeded and then threw on its own audit row would leave the CP claiming the
            // move failed while the database says otherwise.
            Craft::warning('Could not write Publishr history: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        }
    }

    /** @return HistoryEntry[] Newest first. */
    public function history(int $elementId, int $siteId, int $limit = 50): array
    {
        $rows = (new Query())
            ->from([Table::HISTORY])
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->all();

        return array_map([$this, 'toHistoryModel'], $rows);
    }

    /** @return HistoryEntry[] Newest first, across everything. */
    public function recentActivity(int $limit = 50, ?int $siteId = null): array
    {
        $query = (new Query())
            ->from([Table::HISTORY])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit);

        if ($siteId !== null) {
            $query->where(['siteId' => $siteId]);
        }

        return array_map([$this, 'toHistoryModel'], $query->all());
    }

    /** Delete history and notification rows older than `$days`. Returns rows removed. */
    public function pruneHistory(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $cutoff = Db::prepareDateForDb(new DateTime("-$days days"));

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::HISTORY, ['<', 'dateCreated', $cutoff])
            ->execute();
    }

    // --------------------------------------------------------------------- lists

    /**
     * Items assigned to somebody, soonest deadline first.
     *
     * Deadline-less work sorts last rather than first: `ORDER BY dueDate` puts NULLs at the top on
     * MySQL, which would open everybody's queue on the pieces with no deadline — the least urgent
     * things on the desk.
     *
     * @return Item[]
     */
    public function forAssignee(int $userId, ?int $siteId = null, int $limit = 100): array
    {
        $query = ItemRecord::find()
            ->where(['assigneeId' => $userId])
            ->orderBy($this->dueDateOrder() + ['id' => SORT_ASC])
            ->limit($limit);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return array_map([$this, 'toModel'], $query->all());
    }

    /** @return Item[] Overdue work, most overdue first. */
    public function overdue(?int $siteId = null, int $limit = 200): array
    {
        $query = ItemRecord::find()
            ->where(['<', 'dueDate', Db::prepareDateForDb(DateTimeHelper::now())])
            ->andWhere(['not', ['dueDate' => null]])
            ->orderBy(['dueDate' => SORT_ASC])
            ->limit($limit);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $items = array_map([$this, 'toModel'], $query->all());

        // A piece that is already out is not late, whatever its deadline says.
        return $this->withoutPublished($items);
    }

    /**
     * How many of one person's pieces are late. A COUNT, because the navigation asks on every
     * control-panel request and loading the rows to count them would be hundreds per page view.
     */
    public function overdueCountFor(int $userId): int
    {
        $query = (new Query())
            ->from([Table::ITEMS])
            ->where(['assigneeId' => $userId])
            // Late means the due *day* has passed, the same whole-day rule isOverdue() uses, so
            // the badge and the screens agree about a piece due at five this afternoon.
            ->andWhere(['<', 'dueDate', Db::prepareDateForDb(DateTimeHelper::now()->setTime(0, 0))]);

        // A piece that is already out is not late, whatever its deadline says.
        $published = Plugin::getInstance()->stages->getPublishedStage();

        if ($published !== null) {
            $query->andWhere(['or', ['stageId' => null], ['not', ['stageId' => $published->id]]]);
        }

        return (int)$query->count();
    }

    /** @return Item[] Work with no owner. */
    public function unassigned(?int $siteId = null, int $limit = 200): array
    {
        $query = ItemRecord::find()
            ->where(['assigneeId' => null])
            ->orderBy($this->dueDateOrder())
            ->limit($limit);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return $this->withoutPublished(array_map([$this, 'toModel'], $query->all()));
    }

    /** @return array<int, int> Stage ID => item count. */
    public function countsByStage(?int $siteId = null): array
    {
        $query = (new Query())
            ->select(['stageId', 'c' => 'COUNT(*)'])
            ->from([Table::ITEMS])
            ->groupBy(['stageId']);

        if ($siteId !== null) {
            $query->where(['siteId' => $siteId]);
        }

        $counts = [];

        foreach ($query->all() as $row) {
            $counts[(int)$row['stageId']] = (int)$row['c'];
        }

        return $counts;
    }

    /**
     * @param Item[] $items
     * @return Item[]
     */
    private function withoutPublished(array $items): array
    {
        $published = Plugin::getInstance()->stages->getPublishedStage();

        if ($published === null) {
            return $items;
        }

        return array_values(array_filter($items, static fn(Item $item) => $item->stageId !== $published->id));
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * "Soonest deadline first, then everything with no deadline."
     *
     * `ORDER BY dueDate` alone sorts NULLs to the *top* on MySQL, which opens everybody's queue on
     * the pieces with no deadline — the least urgent things on the desk. The nulls-last expression
     * has to be an `Expression` rather than an array key: Yii quotes order-by keys as column names,
     * so `'[[dueDate]] IS NULL' => SORT_ASC` becomes a backtick-quoted identifier and a syntax
     * error.
     *
     * @return array<string, mixed>
     */
    private function dueDateOrder(): array
    {
        return [
            'nullsLast' => new Expression('CASE WHEN [[dueDate]] IS NULL THEN 1 ELSE 0 END'),
            'dueDate' => SORT_ASC,
        ];
    }

    private function toModel(ItemRecord $record): Item
    {
        return new Item([
            'id' => (int)$record->id,
            'elementId' => (int)$record->elementId,
            'siteId' => (int)$record->siteId,
            'stageId' => $record->stageId !== null ? (int)$record->stageId : null,
            'assigneeId' => $record->assigneeId !== null ? (int)$record->assigneeId : null,

            // Bare `Y-m-d H:i:s` out of a column is UTC. `new DateTime()` would read it in the
            // site's zone and shift every deadline by the offset — which on a US site turns a
            // Monday deadline into a Sunday one.
            'dueDate' => $this->toDate($record->dueDate),
            'reviewDue' => $this->toDate($record->reviewDue),
            'lastReviewedAt' => $this->toDate($record->lastReviewedAt),
            'gatesCheckedAt' => $this->toDate($record->gatesCheckedAt),

            'lastReviewedBy' => $record->lastReviewedBy !== null ? (int)$record->lastReviewedBy : null,
            'policyId' => $record->policyId !== null ? (int)$record->policyId : null,
            'brief' => $record->brief,
            'pinned' => (bool)$record->pinned,
            'gateState' => $this->decodeArray($record->gateState),
            'uid' => $record->uid,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function toHistoryModel(array $row): HistoryEntry
    {
        return new HistoryEntry([
            'id' => (int)$row['id'],
            'elementId' => (int)$row['elementId'],
            'siteId' => (int)$row['siteId'],
            'event' => $row['event'],
            'userId' => $row['userId'] !== null ? (int)$row['userId'] : null,
            'fromStageId' => $row['fromStageId'] !== null ? (int)$row['fromStageId'] : null,
            'toStageId' => $row['toStageId'] !== null ? (int)$row['toStageId'] : null,
            'fromValue' => $row['fromValue'],
            'toValue' => $row['toValue'],
            'note' => $row['note'],
            'dateCreated' => $this->toDate($row['dateCreated']),
        ]);
    }

    private function toDate(mixed $value): ?DateTime
    {
        if (empty($value)) {
            return null;
        }

        $date = DateTimeHelper::toDateTime($value, false, false);

        return $date === false ? null : $date;
    }

    /** Postgres hands JSON columns back as a string; MySQL hands back an array. */
    private function decodeArray(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Decode until it stops being a string. Rows written by a caller that encoded the array
        // itself hold the JSON *of* a JSON string, and one pass leaves a string behind — which
        // every `is_array()` guard downstream then reads as "no gates ran".
        for ($i = 0; $i < 3 && is_string($value); $i++) {
            $value = Json::decodeIfJson($value);
        }

        return is_array($value) ? $value : null;
    }

    private function trim(?string $value): ?string
    {
        return $value !== null ? mb_substr($value, 0, 500) : null;
    }

    private function sameInstant(?DateTime $a, ?DateTime $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $a->getTimestamp() === $b->getTimestamp();
    }
}
