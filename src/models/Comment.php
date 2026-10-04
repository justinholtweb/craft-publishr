<?php

declare(strict_types=1);

namespace justinholtweb\publishr\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use DateTime;

/**
 * An editorial comment.
 *
 * Deliberately not a Craft field and not stored on the element: a note from an editor is *about*
 * the content, not part of it, and must never end up rendered on the site or copied into a
 * revision. Storing it beside the element rather than inside it also means the conversation
 * survives the draft it was written against being applied and deleted.
 */
class Comment extends Model
{
    public ?int $id = null;
    public int $elementId = 0;
    public int $siteId = 0;
    public ?int $authorId = null;
    public ?int $parentId = null;
    public string $body = '';
    public bool $resolved = false;
    public ?int $resolvedBy = null;
    public ?DateTime $resolvedAt = null;
    public ?DateTime $dateCreated = null;

    /** @var Comment[] */
    public array $replies = [];

    protected function defineRules(): array
    {
        return [
            [['elementId', 'siteId', 'body'], 'required'],
            [['body'], 'string', 'max' => 5000],
            [['elementId', 'siteId', 'authorId', 'parentId', 'resolvedBy'], 'integer'],
            [['resolved'], 'boolean'],
        ];
    }

    public function getAuthor(): ?User
    {
        return $this->authorId !== null ? Craft::$app->getUsers()->getUserById($this->authorId) : null;
    }

    public function getResolver(): ?User
    {
        return $this->resolvedBy !== null ? Craft::$app->getUsers()->getUserById($this->resolvedBy) : null;
    }

    /**
     * User IDs named with an @handle in the body.
     *
     * Matched against real usernames by the caller — this only extracts candidates, because a
     * comment that says "email me at @gmail" should not notify anybody.
     *
     * @return string[]
     */
    public function mentionedUsernames(): array
    {
        preg_match_all('/(?<![\w@])@([a-zA-Z0-9_.\-]{1,64})/', $this->body, $matches);

        return array_values(array_unique($matches[1]));
    }
}
