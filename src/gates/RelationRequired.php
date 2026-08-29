<?php

declare(strict_types=1);

namespace justinholtweb\publishr\gates;

use Craft;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\helpers\Cp;
use justinholtweb\publishr\models\Gate;
use justinholtweb\publishr\models\GateResult;

/**
 * "This needs a lead image / at least two related articles / a category."
 *
 * Separate from {@see RequiredFields} because a relation field is never empty in the sense that
 * matters — it holds a query — and because the useful rule is usually a *count*, not presence: one
 * category, three related pieces, exactly one hero image.
 *
 * The alt-text option is here rather than in a gate of its own because "there is an image" and
 * "the image is usable by somebody with a screen reader" are the same editorial decision, made at
 * the same moment, by the same person.
 */
class RelationRequired extends BaseGateType
{
    public static function handle(): string
    {
        return 'relationRequired';
    }

    public static function displayName(): string
    {
        return Craft::t('publishr', 'Related content');
    }

    public static function description(): string
    {
        return Craft::t('publishr', 'A relation field must hold at least so many things — a lead image, a category, related reading.');
    }

    public static function settingsHtml(array $settings): string
    {
        return Cp::textFieldHtml([
                'label' => Craft::t('publishr', 'Field handle'),
                'id' => 'handle',
                'name' => 'settings[handle]',
                'value' => $settings['handle'] ?? '',
            ]) . Cp::textFieldHtml([
                'label' => Craft::t('publishr', 'Minimum'),
                'id' => 'min',
                'name' => 'settings[min]',
                'type' => 'number',
                'value' => $settings['min'] ?? 1,
            ]) . Cp::lightswitchFieldHtml([
                'label' => Craft::t('publishr', 'Require alt text'),
                'instructions' => Craft::t('publishr', 'Assets only. Fails when any related asset has no alt text.'),
                'id' => 'requireAlt',
                'name' => 'settings[requireAlt]',
                'on' => (bool)($settings['requireAlt'] ?? false),
            ]);
    }

    public function check(Entry $entry, Gate $gate): GateResult
    {
        $handle = trim((string)($gate->settings['handle'] ?? ''));
        $min = max(0, (int)($gate->settings['min'] ?? 1));
        $requireAlt = (bool)($gate->settings['requireAlt'] ?? false);

        if ($handle === '' || !$this->hasField($entry, $handle)) {
            return $this->skip($gate, $this->t('“{handle}” isn’t on this entry.', ['handle' => $handle]));
        }

        $value = $this->fieldValue($entry, $handle);

        if (!$value instanceof ElementQuery) {
            return $this->skip($gate, $this->t('“{handle}” isn’t a relation field.', ['handle' => $handle]));
        }

        $related = $value->all();
        $count = count($related);

        if ($count < $min) {
            return $this->fail($gate, $this->t('{n} of {min} needed in “{handle}”', [
                'n' => $count,
                'min' => $min,
                'handle' => $handle,
            ]));
        }

        if ($requireAlt) {
            $noAlt = [];

            foreach ($related as $element) {
                if ($element instanceof Asset && trim((string)$element->alt) === '') {
                    $noAlt[] = $element->filename;
                }
            }

            if ($noAlt !== []) {
                return $this->fail(
                    $gate,
                    $this->t('{n, plural, =1{One image has no alt text} other{# images have no alt text}}', ['n' => count($noAlt)]),
                    implode(', ', array_slice($noAlt, 0, 5)),
                );
            }
        }

        return $this->pass($gate);
    }
}
