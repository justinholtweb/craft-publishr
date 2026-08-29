<?php

declare(strict_types=1);

namespace justinholtweb\publishr\gates;

use Craft;
use craft\elements\Entry;
use justinholtweb\publishr\models\Gate;
use justinholtweb\publishr\models\GateResult;
use justinholtweb\publishr\Plugin;

/**
 * "Somebody has to own this."
 *
 * The cheapest governance rule there is, and the one that stops the most trouble: a piece nobody
 * is assigned to is a piece that will not be fixed when it turns out to be wrong.
 */
class AssigneeRequired extends BaseGateType
{
    public static function handle(): string
    {
        return 'assigneeRequired';
    }

    public static function displayName(): string
    {
        return Craft::t('publishr', 'Has an owner');
    }

    public static function description(): string
    {
        return Craft::t('publishr', 'The piece must be assigned to somebody.');
    }

    public function check(Entry $entry, Gate $gate): GateResult
    {
        $item = Plugin::getInstance()->items->forEntry($entry);

        if ($item?->assigneeId === null) {
            return $this->fail($gate, $this->t('Nobody is assigned to this.'));
        }

        return $this->pass($gate, $item->getAssignee()?->friendlyName);
    }
}
