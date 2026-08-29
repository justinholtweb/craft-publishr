<?php

declare(strict_types=1);

namespace justinholtweb\publishr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $elementId
 * @property int $siteId
 * @property string $event
 * @property int|null $userId
 * @property int|null $fromStageId
 * @property int|null $toStageId
 * @property string|null $fromValue
 * @property string|null $toValue
 * @property string|null $note
 * @property string $dateCreated
 */
class HistoryRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::HISTORY;
    }
}
