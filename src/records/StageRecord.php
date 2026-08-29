<?php

declare(strict_types=1);

namespace justinholtweb\publishr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string|null $description
 * @property string $color
 * @property bool $isDefault
 * @property bool $isPublished
 * @property bool $gated
 * @property int|null $sortOrder
 * @property string $uid
 */
class StageRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::STAGES;
    }
}
