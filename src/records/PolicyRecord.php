<?php

declare(strict_types=1);

namespace justinholtweb\publishr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property array|null $sectionUids
 * @property array|null $entryTypeUids
 * @property int $intervalDays
 * @property int $remindDaysBefore
 * @property string $assignTo
 * @property int|null $assigneeId
 * @property bool $enabled
 * @property int|null $sortOrder
 * @property string $uid
 */
class PolicyRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::POLICIES;
    }
}
