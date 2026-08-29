<?php

declare(strict_types=1);

namespace justinholtweb\publishr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string $type
 * @property string|null $description
 * @property array|null $settings
 * @property array|null $sectionUids
 * @property array|null $stageHandles
 * @property string $severity
 * @property bool $enabled
 * @property int|null $sortOrder
 * @property string $uid
 */
class GateRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::GATES;
    }
}
