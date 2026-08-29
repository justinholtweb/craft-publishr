<?php

declare(strict_types=1);

namespace justinholtweb\publishr\gates;

use Craft;
use craft\elements\Entry;
use craft\helpers\Cp;
use justinholtweb\publishr\models\Gate;
use justinholtweb\publishr\models\GateResult;
use justinholtweb\publishr\Plugin;

/**
 * "Somebody has ticked these boxes."
 *
 * The escape hatch for every rule software cannot check — "legal has seen this", "the numbers were
 * checked against the source", "the interviewee approved the quote". The tick is stored on the
 * editorial record with the item's gate state, so it is *per piece*, not a field on the entry that
 * would end up in revisions and exports.
 *
 * Ticks are cleared whenever the entry is saved after being ticked, deliberately: a sign-off is
 * about a specific version of the text, and letting it survive a rewrite is worse than not having
 * one. That falls out of the freshness rule on the cached gate state — see {@see \justinholtweb\publishr\models\Item::gateStateIsFresh()}.
 */
class Checklist extends BaseGateType
{
    public static function handle(): string
    {
        return 'checklist';
    }

    public static function displayName(): string
    {
        return Craft::t('publishr', 'Manual checklist');
    }

    public static function description(): string
    {
        return Craft::t('publishr', 'Boxes a person ticks. For the rules no software can check.');
    }

    public static function settingsHtml(array $settings): string
    {
        return Cp::textareaFieldHtml([
            'label' => Craft::t('publishr', 'Items'),
            'instructions' => Craft::t('publishr', 'One per line. All of them must be ticked.'),
            'id' => 'items',
            'name' => 'settings[items]',
            'value' => implode("\n", $settings['items'] ?? []),
            'rows' => 5,
        ]);
    }

    public function check(Entry $entry, Gate $gate): GateResult
    {
        $expected = array_values(array_filter(array_map('trim', $gate->settings['items'] ?? [])));

        if ($expected === []) {
            return $this->skip($gate, $this->t('No items on the checklist.'));
        }

        $item = Plugin::getInstance()->items->forEntry($entry);
        $ticked = [];

        if ($item !== null && $item->gateStateIsFresh($entry)) {
            $ticked = $item->gateState['ticks'][$gate->handle] ?? [];
        }

        $outstanding = array_values(array_diff($expected, is_array($ticked) ? $ticked : []));

        if ($outstanding === []) {
            return $this->pass($gate);
        }

        return $this->fail(
            $gate,
            $this->t('{n} of {total} ticked', ['n' => count($expected) - count($outstanding), 'total' => count($expected)]),
            implode(' · ', array_slice($outstanding, 0, 4)),
        );
    }
}
