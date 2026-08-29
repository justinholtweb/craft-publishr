<?php

declare(strict_types=1);

namespace justinholtweb\publishr\gates;

use Craft;
use craft\elements\Entry;
use craft\helpers\Cp;
use craft\helpers\Html;
use justinholtweb\publishr\models\Gate;
use justinholtweb\publishr\models\GateResult;

/**
 * "These fields must not be empty."
 *
 * The workhorse. Craft can already mark a field required in its layout, and that is a *save*
 * requirement — it stops a writer saving a half-finished draft, which is exactly the wrong moment
 * to insist on a meta description. This gate moves the same rule to sign-off, where it belongs.
 */
class RequiredFields extends BaseGateType
{
    public static function handle(): string
    {
        return 'requiredFields';
    }

    public static function displayName(): string
    {
        return Craft::t('publishr', 'Required fields');
    }

    public static function description(): string
    {
        return Craft::t('publishr', 'Named fields must have a value. Checked at sign-off, not at save.');
    }

    public static function settingsHtml(array $settings): string
    {
        return Cp::textareaFieldHtml([
            'label' => Craft::t('publishr', 'Field handles'),
            'instructions' => Craft::t('publishr', 'One per line. A handle the entry’s layout doesn’t have is skipped, not failed.'),
            'id' => 'handles',
            'name' => 'settings[handles]',
            'value' => implode("\n", $settings['handles'] ?? []),
            'rows' => 4,
        ]);
    }

    public function check(Entry $entry, Gate $gate): GateResult
    {
        $handles = $gate->settings['handles'] ?? [];

        if ($handles === []) {
            return $this->skip($gate, $this->t('No fields named.'));
        }

        $missing = [];
        $checked = 0;

        foreach ($handles as $handle) {
            $handle = trim((string)$handle);

            if ($handle === '') {
                continue;
            }

            // Native attributes first: `title` and `slug` are not custom fields, and the most
            // obvious thing anybody types into this box is `title`.
            if (in_array($handle, ['title', 'slug', 'postDate', 'expiryDate'], true)) {
                $checked++;

                if (empty($entry->$handle)) {
                    $missing[] = $handle;
                }

                continue;
            }

            if (!$this->hasField($entry, $handle)) {
                continue;
            }

            $checked++;

            if ($this->isEmpty($this->fieldValue($entry, $handle))) {
                $missing[] = $handle;
            }
        }

        if ($checked === 0) {
            return $this->skip($gate, $this->t('None of those fields are on this entry.'));
        }

        if ($missing === []) {
            return $this->pass($gate);
        }

        return $this->fail(
            $gate,
            $this->t('{n, plural, =1{One required field is empty} other{# required fields are empty}}', ['n' => count($missing)]),
            implode(', ', $missing),
        );
    }

    /**
     * Whether a field value counts as empty.
     *
     * An element query is the trap: a Matrix or entries field returns a *query object*, which is
     * always truthy and never `empty()`, so the naive check passes a piece with no blocks in it.
     * Counting is the only honest answer, and it is one indexed query.
     */
    private function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if ($value instanceof \craft\elements\db\ElementQuery) {
            return $value->count() === 0;
        }

        if (is_array($value)) {
            return $value === [];
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return trim(strip_tags((string)$value)) === '';
        }

        if (is_string($value)) {
            return trim(strip_tags($value)) === '';
        }

        return $value === false || $value === 0 || $value === '';
    }
}
