<?php

declare(strict_types=1);

namespace justinholtweb\publishr\events;

use yii\base\Event;

/**
 * Fired so other plugins and modules can add publish requirements of their own.
 *
 * A gate type is any class implementing {@see \justinholtweb\publishr\gates\GateTypeInterface}.
 * This is how the RedPen integration gets registered, and it is deliberately the same door
 * everybody else uses — an integration that needed privileged access would be an integration
 * nobody could copy.
 */
class RegisterGateTypesEvent extends Event
{
    /** @var class-string<\justinholtweb\publishr\gates\GateTypeInterface>[] */
    public array $types = [];
}
