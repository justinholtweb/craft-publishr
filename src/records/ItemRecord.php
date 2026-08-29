<?php

declare(strict_types=1);

namespace justinholtweb\publishr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $elementId The *canonical* element ID. Never a draft's own ID.
 * @property int $siteId
 * @property int|null $stageId
 * @property int|null $assigneeId
 * @property string|null $dueDate
 * @property string|null $reviewDue
 * @property string|null $lastReviewedAt
 * @property int|null $lastReviewedBy
 * @property int|null $policyId
 * @property string|null $brief
 * @property bool $pinned
 * @property array|null $gateState
 * @property string|null $gatesCheckedAt
 * @property string $uid
 */
class ItemRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::ITEMS;
    }
}
