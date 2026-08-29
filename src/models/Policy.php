<?php

declare(strict_types=1);

namespace justinholtweb\publishr\models;

use craft\base\Model;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use justinholtweb\publishr\records\PolicyRecord;

/**
 * A freshness policy — how often a kind of content has to be looked at again.
 *
 * The problem it exists for: nothing on a website tells you it has gone out of date. A pricing
 * page from 2021 renders exactly as confidently as one from this morning, and the only signal a
 * CMS gives is `dateUpdated`, which measures when somebody last touched the file, not when
 * somebody last decided the content was still true. Policies turn that into a date with a name on
 * it.
 *
 * The interval runs from `lastReviewedAt` when there is one and from the post date when there is
 * not — so a policy applied to an archive of 400 pages immediately produces a real backlog rather
 * than quietly resetting everything to "reviewed today", which is the failure mode that makes
 * these tools useless.
 */
class Policy extends Model
{
    /** Whoever the piece is already assigned to; falls back to the entry's author. */
    public const ASSIGN_CURRENT = 'current';

    /** The entry's author, always. */
    public const ASSIGN_AUTHOR = 'author';

    /** One named person, whatever the piece is. */
    public const ASSIGN_USER = 'user';

    /** Nobody. The review shows on the reviews screen and in the report, unassigned. */
    public const ASSIGN_NOBODY = 'nobody';

    public ?int $id = null;
    public string $name = '';
    public string $handle = '';

    /** Section UIDs this covers. Empty means every section. */
    public array $sectionUids = [];

    /** Entry type UIDs this covers. Empty means every type in those sections. */
    public array $entryTypeUids = [];

    /** How long a piece is good for. */
    public int $intervalDays = 180;

    /** How far ahead of the review date to start warning. */
    public int $remindDaysBefore = 14;

    public string $assignTo = self::ASSIGN_CURRENT;
    public ?int $assigneeId = null;
    public bool $enabled = true;
    public ?int $sortOrder = null;
    public ?string $uid = null;

    protected function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class],
            [['handle'], UniqueValidator::class, 'targetClass' => PolicyRecord::class, 'targetAttribute' => 'handle'],
            [['intervalDays'], 'integer', 'min' => 1],
            [['remindDaysBefore'], 'integer', 'min' => 0],
            [['assignTo'], 'in', 'range' => [self::ASSIGN_CURRENT, self::ASSIGN_AUTHOR, self::ASSIGN_USER, self::ASSIGN_NOBODY]],
            [['assigneeId'], 'integer'],
            [['enabled'], 'boolean'],
            [['sortOrder'], 'integer'],
        ];
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return [
            'name' => $this->name,
            'handle' => $this->handle,
            'sectionUids' => array_values($this->sectionUids),
            'entryTypeUids' => array_values($this->entryTypeUids),
            'intervalDays' => $this->intervalDays,
            'remindDaysBefore' => $this->remindDaysBefore,
            'assignTo' => $this->assignTo,
            'assigneeId' => $this->assigneeId,
            'enabled' => $this->enabled,
            'sortOrder' => $this->sortOrder,
        ];
    }

    /** Whether this policy covers a given section and entry type. */
    public function covers(?string $sectionUid, ?string $entryTypeUid): bool
    {
        if ($this->sectionUids !== [] && ($sectionUid === null || !in_array($sectionUid, $this->sectionUids, true))) {
            return false;
        }

        if ($this->entryTypeUids !== [] && ($entryTypeUid === null || !in_array($entryTypeUid, $this->entryTypeUids, true))) {
            return false;
        }

        return true;
    }
}
