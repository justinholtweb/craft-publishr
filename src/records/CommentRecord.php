<?php

declare(strict_types=1);

namespace justinholtweb\publishr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $elementId
 * @property int $siteId
 * @property int|null $authorId
 * @property int|null $parentId
 * @property string $body
 * @property bool $resolved
 * @property int|null $resolvedBy
 * @property string|null $resolvedAt
 * @property string $dateCreated
 */
class CommentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::COMMENTS;
    }
}
