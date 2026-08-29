<?php

declare(strict_types=1);

namespace justinholtweb\publishr\models;

use DateTime;

/**
 * Every gate's verdict about one piece, plus the summary the sidebar shows.
 */
class GateReport
{
    /** @param GateResult[] $results */
    public function __construct(
        public readonly array $results = [],
        public readonly ?DateTime $checkedAt = null,
    ) {
    }

    /** @return GateResult[] */
    public function blocking(): array
    {
        return array_values(array_filter($this->results, static fn(GateResult $r) => $r->blocks()));
    }

    /** @return GateResult[] */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->results,
            static fn(GateResult $r) => $r->status === GateResult::FAILED && $r->severity === Gate::SEVERITY_ADVISORY,
        ));
    }

    /** @return GateResult[] */
    public function skipped(): array
    {
        return array_values(array_filter($this->results, static fn(GateResult $r) => $r->status === GateResult::SKIPPED));
    }

    public function isClear(): bool
    {
        return $this->blocking() === [];
    }

    public function passedCount(): int
    {
        return count(array_filter($this->results, static fn(GateResult $r) => $r->passed()));
    }

    /** Gates that actually ran. The denominator in "3 of 5". */
    public function applicableCount(): int
    {
        return count(array_filter($this->results, static fn(GateResult $r) => $r->status !== GateResult::SKIPPED));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'results' => array_map(static fn(GateResult $r) => $r->toArray(), $this->results),
            'clear' => $this->isClear(),
            'passed' => $this->passedCount(),
            'applicable' => $this->applicableCount(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?DateTime $checkedAt = null): self
    {
        return new self(
            array_map(static fn(array $r) => GateResult::fromArray($r), $data['results'] ?? []),
            $checkedAt,
        );
    }
}
