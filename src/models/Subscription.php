<?php

declare(strict_types=1);

namespace justinholtweb\publishr\models;

use craft\base\Model;

/**
 * Who wants telling about what.
 *
 * Three scopes, and the reason there are three: an assignee is told about their own work without
 * ever subscribing to anything (that is not a subscription, it is the job), somebody following a
 * single piece wants that piece, and a managing editor wants a whole section. Modelling all three
 * as rows in one table means the notifier asks one question — "who cares about this element" —
 * instead of three.
 */
class Subscription extends Model
{
    public const SCOPE_ELEMENT = 'element';
    public const SCOPE_SECTION = 'section';
    public const SCOPE_ALL = 'all';

    public ?int $id = null;
    public int $userId = 0;
    public string $scope = self::SCOPE_ELEMENT;
    public ?int $elementId = null;
    public ?string $sectionUid = null;

    /** Event names from {@see \justinholtweb\publishr\services\Notifications}. Empty means all. */
    public array $events = [];

    public ?string $uid = null;

    protected function defineRules(): array
    {
        return [
            [['userId', 'scope'], 'required'],
            [['scope'], 'in', 'range' => [self::SCOPE_ELEMENT, self::SCOPE_SECTION, self::SCOPE_ALL]],
            [['userId', 'elementId'], 'integer'],
            [['elementId'], 'required', 'when' => fn(self $model) => $model->scope === self::SCOPE_ELEMENT],
            [['sectionUid'], 'required', 'when' => fn(self $model) => $model->scope === self::SCOPE_SECTION],
        ];
    }

    public function wants(string $event): bool
    {
        return $this->events === [] || in_array($event, $this->events, true);
    }
}
