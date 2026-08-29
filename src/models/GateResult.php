<?php

declare(strict_types=1);

namespace justinholtweb\publishr\models;

/**
 * One gate's verdict about one piece.
 *
 * Carries the message the gate type wrote rather than a code the template has to translate back
 * into prose, because the useful half of "this failed" is *what to do about it* — "no lead image"
 * is a sentence an editor can act on; `GATE_ASSET_MISSING` is not.
 */
class GateResult
{
    public const PASSED = 'passed';
    public const FAILED = 'failed';

    /** The gate could not run — RedPen is uninstalled, the field was deleted. Never a failure. */
    public const SKIPPED = 'skipped';

    public function __construct(
        public readonly string $gateHandle,
        public readonly string $gateName,
        public readonly string $status,
        public readonly string $severity = Gate::SEVERITY_REQUIRED,
        public readonly ?string $message = null,
        public readonly ?string $detail = null,
    ) {
    }

    public function passed(): bool
    {
        return $this->status === self::PASSED;
    }

    /**
     * Whether this result should stop a stage move.
     *
     * A skipped gate never blocks. A gate that cannot run has found nothing wrong; treating "I
     * don't know" as "no" would mean uninstalling RedPen locks the whole desk out of sign-off.
     */
    public function blocks(): bool
    {
        return $this->status === self::FAILED && $this->severity === Gate::SEVERITY_REQUIRED;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'gateHandle' => $this->gateHandle,
            'gateName' => $this->gateName,
            'status' => $this->status,
            'severity' => $this->severity,
            'message' => $this->message,
            'detail' => $this->detail,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string)($data['gateHandle'] ?? ''),
            (string)($data['gateName'] ?? ''),
            (string)($data['status'] ?? self::SKIPPED),
            (string)($data['severity'] ?? Gate::SEVERITY_REQUIRED),
            $data['message'] ?? null,
            $data['detail'] ?? null,
        );
    }
}
