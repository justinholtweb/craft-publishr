<?php

declare(strict_types=1);

namespace justinholtweb\publishr\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $userId
 * @property int|null $elementId
 * @property int|null $siteId
 * @property string $event
 * @property array|null $payload
 * @property string $status
 * @property int $attempts
 * @property string|null $error
 * @property string|null $sentAt
 * @property string|null $dedupeKey
 * @property string $dateCreated
 */
class NotificationRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::NOTIFICATIONS;
    }
}
