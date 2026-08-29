<?php

declare(strict_types=1);

namespace justinholtweb\publishr\models;

use craft\base\Model;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use justinholtweb\publishr\records\GateRecord;

/**
 * One publish requirement.
 *
 * A gate is a question with a yes/no answer about a piece of content — "does it have a lead
 * image", "is the SEO description filled in", "has the copy been through RedPen cleanly". The
 * *question* is a gate type; this model is one configured instance of it, with the scope it
 * applies to.
 *
 * Two severities, and the difference is the whole design:
 *
 * - `required` blocks the move into a gated stage. Somebody with the override permission can
 *   still push it through, and doing so is written into the history with their name on it.
 * - `advisory` never blocks anything. It shows as a warning in the sidebar and counts in the
 *   report.
 *
 * There is no third severity that silently rewrites content. A governance tool that edits the
 * copy is a governance tool nobody trusts.
 */
class Gate extends Model
{
    public const SEVERITY_REQUIRED = 'required';
    public const SEVERITY_ADVISORY = 'advisory';

    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public string $type = '';
    public ?string $description = null;

    /** Type-specific configuration. Shape is the gate type's business. */
    public array $settings = [];

    /** Section UIDs this applies to. Empty means every section. */
    public array $sectionUids = [];

    /** Stage handles this is checked for. Empty means every gated stage. */
    public array $stageHandles = [];

    public string $severity = self::SEVERITY_REQUIRED;
    public bool $enabled = true;
    public ?int $sortOrder = null;
    public ?string $uid = null;

    protected function defineRules(): array
    {
        return [
            [['name', 'handle', 'type'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class],
            [['handle'], UniqueValidator::class, 'targetClass' => GateRecord::class, 'targetAttribute' => 'handle'],
            [['severity'], 'in', 'range' => [self::SEVERITY_REQUIRED, self::SEVERITY_ADVISORY]],
            [['enabled'], 'boolean'],
            [['sortOrder'], 'integer'],
        ];
    }

    public function isRequired(): bool
    {
        return $this->severity === self::SEVERITY_REQUIRED;
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return [
            'name' => $this->name,
            'handle' => $this->handle,
            'type' => $this->type,
            'description' => $this->description,
            'settings' => $this->settings,
            'sectionUids' => array_values($this->sectionUids),
            'stageHandles' => array_values($this->stageHandles),
            'severity' => $this->severity,
            'enabled' => $this->enabled,
            'sortOrder' => $this->sortOrder,
        ];
    }
}
