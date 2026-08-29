<?php

declare(strict_types=1);

namespace justinholtweb\publishr\events;

use justinholtweb\publishr\models\Item;
use justinholtweb\publishr\models\Stage;
use yii\base\Event;

/**
 * Fired around a stage move.
 *
 * The `before` firing is cancellable — set `isValid = false` to refuse the move. That is the seam
 * for a rule Publishr's own gates cannot express, like "nothing may reach Ready during a code
 * freeze".
 */
class StageChangeEvent extends Event
{
    public Item $item;
    public ?Stage $fromStage = null;
    public ?Stage $toStage = null;
    public ?int $userId = null;
    public ?string $note = null;
}
