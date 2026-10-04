<?php

declare(strict_types=1);

namespace justinholtweb\publishr\events;

use craft\events\CancelableEvent;
use justinholtweb\publishr\models\Item;
use justinholtweb\publishr\models\Stage;

/**
 * Fired around a stage move.
 *
 * The `before` firing is cancellable — set `isValid = false` to refuse the move. That is the seam
 * for a rule Publishr's own gates cannot express, like "nothing may reach Ready during a code
 * freeze".
 */
class StageChangeEvent extends CancelableEvent
{
    public Item $item;
    public ?Stage $fromStage = null;
    public ?Stage $toStage = null;
    public ?int $userId = null;
    public ?string $note = null;
}
