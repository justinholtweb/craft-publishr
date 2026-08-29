<?php

declare(strict_types=1);

namespace justinholtweb\publishr\models;

use craft\base\Model;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\validators\DateTimeValidator;
use DateTime;
use justinholtweb\publishr\Plugin;

/**
 * The editorial record for one element in one site.
 *
 * Keyed on the **canonical** element ID, never a draft's own ID. A piece being written is usually
 * a draft, and the draft the writer opens on Tuesday is not the draft they opened on Monday —
 * Craft creates provisional drafts freely. Keying on the canonical entry means the stage, the
 * assignee and the deadline belong to *the piece*, and survive every draft that is created,
 * applied and thrown away underneath it. It is also the only key that still resolves after a draft
 * is applied and deleted.
 */
class Item extends Model
{
    public ?int $id = null;
    public int $elementId = 0;
    public int $siteId = 0;
    public ?int $stageId = null;
    public ?int $assigneeId = null;

    /** When the *work* is due. Deliberately not `postDate`, which is when the work goes out. */
    public ?DateTime $dueDate = null;

    /** When somebody should look at this again, whether or not anything is wrong with it. */
    public ?DateTime $reviewDue = null;

    public ?DateTime $lastReviewedAt = null;
    public ?int $lastReviewedBy = null;
    public ?int $policyId = null;

    /** What this piece is meant to be — the commission, in the commissioner's words. */
    public ?string $brief = null;

    public bool $pinned = false;

    /** Last gate evaluation, cached so a 200-row list does not run 200 checklists. */
    public ?array $gateState = null;
    public ?DateTime $gatesCheckedAt = null;

    public ?string $uid = null;

    private ?Entry $_element = null;
    private bool $_elementLoaded = false;

    protected function defineRules(): array
    {
        return [
            [['elementId', 'siteId'], 'required'],
            [['elementId', 'siteId', 'stageId', 'assigneeId', 'lastReviewedBy', 'policyId'], 'integer'],
            [['dueDate', 'reviewDue', 'lastReviewedAt', 'gatesCheckedAt'], DateTimeValidator::class],
            [['pinned'], 'boolean'],
            [['brief'], 'string'],
        ];
    }

    public function getStage(): ?Stage
    {
        return Plugin::getInstance()->stages->getStageById($this->stageId);
    }

    public function getAssignee(): ?User
    {
        return $this->assigneeId !== null
            ? \Craft::$app->getUsers()->getUserById($this->assigneeId)
            : null;
    }

    /** The canonical entry this record is about, or null if it has since been deleted. */
    public function getElement(): ?Entry
    {
        if (!$this->_elementLoaded) {
            $this->_elementLoaded = true;

            $element = Entry::find()
                ->id($this->elementId)
                ->siteId($this->siteId)
                ->status(null)
                ->drafts(null)
                ->revisions(false)
                ->one();

            $this->_element = $element instanceof Entry ? $element : null;
        }

        return $this->_element;
    }

    public function setElement(?Entry $entry): void
    {
        $this->_element = $entry;
        $this->_elementLoaded = true;
    }

    /** Whole days until the deadline. Negative once it is behind. Null when there is no deadline. */
    public function daysUntilDue(?DateTime $now = null): ?int
    {
        return self::wholeDaysBetween($now, $this->dueDate);
    }

    public function daysUntilReview(?DateTime $now = null): ?int
    {
        return self::wholeDaysBetween($now, $this->reviewDue);
    }

    public function isOverdue(?DateTime $now = null): bool
    {
        $days = $this->daysUntilDue($now);

        return $days !== null && $days < 0;
    }

    public function isReviewOverdue(?DateTime $now = null): bool
    {
        $days = $this->daysUntilReview($now);

        return $days !== null && $days <= 0;
    }

    /**
     * Whether the cached gate verdict can still be believed.
     *
     * Anything older than the element's own `dateUpdated` is about a version of the text that no
     * longer exists, and showing it would be worse than showing nothing — a green tick against
     * prose somebody has since gutted.
     */
    public function gateStateIsFresh(?Entry $element = null): bool
    {
        if ($this->gateState === null || $this->gatesCheckedAt === null) {
            return false;
        }

        $element ??= $this->getElement();

        if ($element?->dateUpdated === null) {
            return true;
        }

        return $this->gatesCheckedAt >= $element->dateUpdated;
    }

    /**
     * Comparison at whole-day granularity, in the site's own time zone.
     *
     * Not `(int)$diff->days` with a sign, and not seconds/86400: "due tomorrow" has to mean
     * tomorrow's *date*, so that a deadline at 09:00 tomorrow does not read as "0 days" all
     * afternoon today and produce a reminder that says a thing is due today when it is not.
     */
    private static function wholeDaysBetween(?DateTime $now, ?DateTime $target): ?int
    {
        if ($target === null) {
            return null;
        }

        $now ??= DateTimeHelper::now();

        $a = (clone $now)->setTime(0, 0, 0);
        $b = (clone $target)->setTimezone($now->getTimezone())->setTime(0, 0, 0);

        $diff = $a->diff($b);

        return (int)$diff->days * ($diff->invert ? -1 : 1);
    }
}
