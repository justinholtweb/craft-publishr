<?php

declare(strict_types=1);

namespace justinholtweb\publishr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use justinholtweb\publishr\models\Comment;
use justinholtweb\publishr\models\HistoryEntry;
use justinholtweb\publishr\Plugin;
use justinholtweb\publishr\records\CommentRecord;
use justinholtweb\publishr\records\Table;

/**
 * The conversation about a piece.
 *
 * Kept beside the element rather than in a field on it, for three reasons that all bite in
 * practice: a note from an editor must never be renderable on the public site, it must not be
 * copied into every revision, and it has to survive the draft it was written against being applied
 * and deleted. A "notes" field satisfies none of the three.
 */
class Comments extends Component
{
    /**
     * Every comment on a piece, threaded one level deep.
     *
     * One level, not arbitrary depth: an editorial note is a question and an answer, and the
     * threads that go deeper than that are conversations that should have been a meeting. Flat
     * replies also mean the whole thread renders without recursion in the sidebar, where vertical
     * space is the scarce resource.
     *
     * @return Comment[] Top-level comments, oldest first, each with its replies.
     */
    public function forElement(int $elementId, int $siteId, bool $includeResolved = true): array
    {
        $query = CommentRecord::find()
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->orderBy(['dateCreated' => SORT_ASC, 'id' => SORT_ASC]);

        if (!$includeResolved) {
            $query->andWhere(['resolved' => false]);
        }

        $all = array_map([$this, 'toModel'], $query->all());

        /** @var array<int, Comment> $byId */
        $byId = [];

        foreach ($all as $comment) {
            $byId[(int)$comment->id] = $comment;
        }

        $roots = [];

        foreach ($all as $comment) {
            if ($comment->parentId !== null && isset($byId[$comment->parentId])) {
                $byId[$comment->parentId]->replies[] = $comment;

                continue;
            }

            $roots[] = $comment;
        }

        return $roots;
    }

    public function getById(int $id): ?Comment
    {
        $record = CommentRecord::findOne($id);

        return $record !== null ? $this->toModel($record) : null;
    }

    public function save(Comment $comment): bool
    {
        if (!$comment->validate()) {
            return false;
        }

        $record = $comment->id !== null ? CommentRecord::findOne($comment->id) : new CommentRecord();

        if ($record === null) {
            return false;
        }

        $record->elementId = $comment->elementId;
        $record->siteId = $comment->siteId;
        $record->authorId = $comment->authorId;

        // A reply to a reply is re-parented to the top of its thread rather than refused. Refusing
        // it would mean losing what somebody typed to enforce a display rule.
        $record->parentId = $this->rootOf($comment->parentId);

        $record->body = $comment->body;
        $record->resolved = $comment->resolved;
        $record->resolvedBy = $comment->resolvedBy;
        $record->resolvedAt = Db::prepareDateForDb($comment->resolvedAt);

        if (!$record->save(false)) {
            return false;
        }

        $isNew = $comment->id === null;
        $comment->id = (int)$record->id;

        if ($isNew) {
            $item = Plugin::getInstance()->items->forElement($comment->elementId, $comment->siteId);

            if ($item !== null) {
                Plugin::getInstance()->items->log($item, HistoryEntry::EVENT_COMMENT, $comment->authorId, [
                    'note' => mb_substr($comment->body, 0, 200),
                ]);
            }

            Plugin::getInstance()->notifications->commented($comment);
        }

        return true;
    }

    /** @return int|null The top-level ancestor of a comment, or null. */
    private function rootOf(?int $parentId): ?int
    {
        if ($parentId === null) {
            return null;
        }

        $parent = CommentRecord::findOne($parentId);

        if ($parent === null) {
            return null;
        }

        return $parent->parentId !== null ? (int)$parent->parentId : (int)$parent->id;
    }

    /**
     * Mark a thread resolved.
     *
     * Resolving the root resolves its replies too. A thread half-resolved is not a state anybody
     * means, and leaving replies open would make the "no open comments" gate impossible to clear
     * without ticking every line of a conversation.
     */
    public function resolve(int $id, ?int $userId, bool $resolved = true): bool
    {
        $record = CommentRecord::findOne($id);

        if ($record === null) {
            return false;
        }

        $ids = [(int)$record->id];

        if ($record->parentId === null) {
            foreach (CommentRecord::find()->where(['parentId' => $record->id])->all() as $reply) {
                $ids[] = (int)$reply->id;
            }
        }

        Craft::$app->getDb()->createCommand()->update(Table::COMMENTS, [
            'resolved' => $resolved,
            'resolvedBy' => $resolved ? $userId : null,
            'resolvedAt' => $resolved ? Db::prepareDateForDb(DateTimeHelper::now()) : null,
        ], ['id' => $ids])->execute();

        return true;
    }

    public function delete(int $id): bool
    {
        return (bool)CommentRecord::findOne($id)?->delete();
    }

    public function openCount(int $elementId, int $siteId): int
    {
        return (int)(new Query())
            ->from([Table::COMMENTS])
            ->where(['elementId' => $elementId, 'siteId' => $siteId, 'resolved' => false])
            ->count();
    }

    /** @return array<int, int> Element ID => open comment count. */
    public function openCounts(array $elementIds, int $siteId): array
    {
        if ($elementIds === []) {
            return [];
        }

        $counts = [];

        $rows = (new Query())
            ->select(['elementId', 'c' => 'COUNT(*)'])
            ->from([Table::COMMENTS])
            ->where(['elementId' => $elementIds, 'siteId' => $siteId, 'resolved' => false])
            ->groupBy(['elementId'])
            ->all();

        foreach ($rows as $row) {
            $counts[(int)$row['elementId']] = (int)$row['c'];
        }

        return $counts;
    }

    private function toModel(CommentRecord $record): Comment
    {
        return new Comment([
            'id' => (int)$record->id,
            'elementId' => (int)$record->elementId,
            'siteId' => (int)$record->siteId,
            'authorId' => $record->authorId !== null ? (int)$record->authorId : null,
            'parentId' => $record->parentId !== null ? (int)$record->parentId : null,
            'body' => $record->body,
            'resolved' => (bool)$record->resolved,
            'resolvedBy' => $record->resolvedBy !== null ? (int)$record->resolvedBy : null,
            'resolvedAt' => $record->resolvedAt ? (DateTimeHelper::toDateTime($record->resolvedAt, false, false) ?: null) : null,
            'dateCreated' => DateTimeHelper::toDateTime($record->dateCreated, false, false) ?: null,
        ]);
    }
}
