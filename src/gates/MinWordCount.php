<?php

declare(strict_types=1);

namespace justinholtweb\publishr\gates;

use Craft;
use craft\elements\Entry;
use craft\helpers\Cp;
use justinholtweb\publishr\models\Gate;
use justinholtweb\publishr\models\GateResult;

/**
 * "This has to be at least N words."
 *
 * A blunt instrument on purpose. It is not a quality measure and does not pretend to be — it
 * catches the specific failure of a placeholder going live, which is the single most common thing
 * a desk actually publishes by accident.
 */
class MinWordCount extends BaseGateType
{
    public static function handle(): string
    {
        return 'minWordCount';
    }

    public static function displayName(): string
    {
        return Craft::t('publishr', 'Minimum length');
    }

    public static function description(): string
    {
        return Craft::t('publishr', 'The named fields must add up to at least so many words.');
    }

    public static function settingsHtml(array $settings): string
    {
        return Cp::textareaFieldHtml([
                'label' => Craft::t('publishr', 'Field handles'),
                'instructions' => Craft::t('publishr', 'One per line. Their word counts are added together.'),
                'id' => 'handles',
                'name' => 'settings[handles]',
                'value' => implode("\n", $settings['handles'] ?? []),
                'rows' => 3,
            ]) . Cp::textFieldHtml([
                'label' => Craft::t('publishr', 'Minimum words'),
                'id' => 'min',
                'name' => 'settings[min]',
                'type' => 'number',
                'value' => $settings['min'] ?? 300,
            ]);
    }

    public function check(Entry $entry, Gate $gate): GateResult
    {
        $handles = array_filter(array_map('trim', $gate->settings['handles'] ?? []));
        $min = max(1, (int)($gate->settings['min'] ?? 300));

        if ($handles === []) {
            return $this->skip($gate, $this->t('No fields named.'));
        }

        $words = 0;
        $found = false;

        foreach ($handles as $handle) {
            if (!$this->hasField($entry, (string)$handle)) {
                continue;
            }

            $found = true;
            $words += $this->countWords($this->toText($this->fieldValue($entry, (string)$handle)));
        }

        if (!$found) {
            return $this->skip($gate, $this->t('None of those fields are on this entry.'));
        }

        if ($words >= $min) {
            return $this->pass($gate, $this->t('{n} words', ['n' => $words]));
        }

        return $this->fail(
            $gate,
            $this->t('{n} words — {min} needed', ['n' => $words, 'min' => $min]),
        );
    }

    /**
     * Words, for a value of "word" that survives contact with real prose.
     *
     * `str_word_count()` is the obvious call and is wrong here: it is ASCII-only by default, so it
     * counts "café" as two words and a paragraph of Japanese as zero. Splitting on Unicode
     * whitespace is cruder and does not lie about either.
     */
    private function countWords(string $text): int
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return $text === '' ? 0 : count(explode(' ', $text));
    }
}
