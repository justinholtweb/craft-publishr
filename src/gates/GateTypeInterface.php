<?php

declare(strict_types=1);

namespace justinholtweb\publishr\gates;

use craft\elements\Entry;
use justinholtweb\publishr\models\Gate;
use justinholtweb\publishr\models\GateResult;

/**
 * A question with a yes/no answer about a piece of content.
 *
 * Implementations must be cheap and side-effect free: the checklist runs on every save of every
 * managed entry, and a gate that writes something, sends something or takes a second to answer
 * turns saving an entry into an event.
 *
 * A gate that cannot answer must return {@see GateResult::SKIPPED}, never a failure. "I don't
 * know" is not "no" — treating it as one means uninstalling an optional dependency locks the whole
 * desk out of sign-off.
 */
interface GateTypeInterface
{
    /** Stable identifier stored in `publishr_gates.type`. Never change one that has shipped. */
    public static function handle(): string;

    /** What this gate is called in the "add a requirement" menu. */
    public static function displayName(): string;

    /** One sentence describing what it checks, shown under the name. */
    public static function description(): string;

    /**
     * The settings form for one configured instance, already namespaced by the caller.
     *
     * @param array<string, mixed> $settings
     */
    public static function settingsHtml(array $settings): string;

    /** Whether this type can run at all right now — false when its dependency is missing. */
    public static function isAvailable(): bool;

    /** Why it cannot run, for the settings screen. Null when it can. */
    public static function unavailableReason(): ?string;

    public function check(Entry $entry, Gate $gate): GateResult;
}
