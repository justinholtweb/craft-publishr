<?php

declare(strict_types=1);

namespace justinholtweb\publishr\gates;

use Craft;
use craft\elements\Entry;
use justinholtweb\publishr\models\Gate;
use justinholtweb\publishr\models\GateResult;

/**
 * Shared plumbing for gate types: the three result constructors, and settings-form helpers.
 */
abstract class BaseGateType implements GateTypeInterface
{
    public static function isAvailable(): bool
    {
        return true;
    }

    public static function unavailableReason(): ?string
    {
        return null;
    }

    public static function settingsHtml(array $settings): string
    {
        return '';
    }

    protected function pass(Gate $gate, ?string $message = null): GateResult
    {
        return new GateResult($gate->handle, $gate->name, GateResult::PASSED, $gate->severity, $message);
    }

    protected function fail(Gate $gate, string $message, ?string $detail = null): GateResult
    {
        return new GateResult($gate->handle, $gate->name, GateResult::FAILED, $gate->severity, $message, $detail);
    }

    protected function skip(Gate $gate, string $why): GateResult
    {
        return new GateResult($gate->handle, $gate->name, GateResult::SKIPPED, $gate->severity, $why);
    }

    /**
     * A field's value on an entry, without exploding when the field was deleted.
     *
     * `getFieldValue()` throws `UnknownPropertyException` for a handle the layout does not have,
     * and a checklist configured last year against a field somebody removed in March must report
     * "cannot check", not take the entry editor down.
     */
    protected function fieldValue(Entry $entry, string $handle): mixed
    {
        try {
            if ($entry->getFieldLayout()?->getFieldByHandle($handle) === null) {
                return null;
            }

            return $entry->getFieldValue($handle);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function hasField(Entry $entry, string $handle): bool
    {
        return $entry->getFieldLayout()?->getFieldByHandle($handle) !== null;
    }

    /**
     * Plain text out of whatever a field holds.
     *
     * CKEditor and Redactor values stringify to HTML; a Matrix field does not usefully stringify
     * at all. Anything that cannot be reduced to text returns an empty string, and the gates that
     * count words treat that as "nothing to count" rather than guessing.
     */
    protected function toText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return trim(strip_tags($value));
        }

        if (is_scalar($value)) {
            return trim((string)$value);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return trim(strip_tags((string)$value));
        }

        return '';
    }

    protected function t(string $message, array $params = []): string
    {
        return Craft::t('publishr', $message, $params);
    }
}
