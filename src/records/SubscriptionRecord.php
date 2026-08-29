<?php

declare(strict_types=1);

namespace justinholtweb\publishr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $userId
 * @property string $scope
 * @property int|null $elementId
 * @property string|null $sectionUid
 * @property array|null $events
 * @property string $uid
 */
class SubscriptionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::SUBSCRIPTIONS;
    }
}
