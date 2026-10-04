<?php

declare(strict_types=1);

namespace justinholtweb\publishr\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use DateTime;
use justinholtweb\publishr\Plugin;

/**
 * One thing that happened to a piece.
 *
 * Append-only and written at the moment it happens. A `stageId` column records where a piece is;
 * it cannot record who moved it there, when, or what it was before — and "who signed this off, and
 * when" is precisely the question a governance tool exists to answer. Reconstructing it afterwards
 * from `dateUpdated` is not possible and never was.
 */
class HistoryEntry extends Model
{
    public const EVENT_CREATED = 'created';
    public const EVENT_STAGE = 'stage';
    public const EVENT_ASSIGNED = 'assigned';
    public const EVENT_DUE = 'due';
    public const EVENT_REVIEWED = 'reviewed';
    public const EVENT_REVIEW_DUE = 'reviewDue';
    public const EVENT_PUBLISHED = 'published';
    public const EVENT_EXPIRED = 'expired';
    public const EVENT_GATE_OVERRIDE = 'gateOverride';
    public const EVENT_COMMENT = 'comment';

    public ?int $id = null;
    public int $elementId = 0;
    public int $siteId = 0;
    public string $event = self::EVENT_STAGE;
    public ?int $userId = null;
    public ?int $fromStageId = null;
    public ?int $toStageId = null;
    public ?string $fromValue = null;
    public ?string $toValue = null;
    public ?string $note = null;
    public ?DateTime $dateCreated = null;

    public function getUser(): ?User
    {
        return $this->userId !== null ? Craft::$app->getUsers()->getUserById($this->userId) : null;
    }

    public function getFromStage(): ?Stage
    {
        return Plugin::getInstance()->stages->getStageById($this->fromStageId);
    }

    public function getToStage(): ?Stage
    {
        return Plugin::getInstance()->stages->getStageById($this->toStageId);
    }

    /** A one-line description, for the activity list and the digest. */
    public function describe(): string
    {
        $who = $this->getUser()->friendlyName ?? Craft::t('publishr', 'Publishr');

        return match ($this->event) {
            self::EVENT_CREATED => Craft::t('publishr', '{who} started tracking this', ['who' => $who]),
            self::EVENT_STAGE => Craft::t('publishr', '{who} moved this from {from} to {to}', [
                'who' => $who,
                'from' => $this->getFromStage()->name ?? Craft::t('publishr', 'nowhere'),
                'to' => $this->getToStage()->name ?? Craft::t('publishr', 'nowhere'),
            ]),
            self::EVENT_ASSIGNED => $this->toValue !== null
                ? Craft::t('publishr', '{who} assigned this to {to}', ['who' => $who, 'to' => $this->toValue])
                : Craft::t('publishr', '{who} unassigned this', ['who' => $who]),
            self::EVENT_DUE => $this->toValue !== null
                ? Craft::t('publishr', '{who} set the deadline to {to}', ['who' => $who, 'to' => $this->toValue])
                : Craft::t('publishr', '{who} removed the deadline', ['who' => $who]),
            self::EVENT_REVIEWED => Craft::t('publishr', '{who} reviewed this', ['who' => $who]),
            self::EVENT_REVIEW_DUE => Craft::t('publishr', 'A review fell due', []),
            self::EVENT_PUBLISHED => Craft::t('publishr', 'This went live', []),
            self::EVENT_EXPIRED => Craft::t('publishr', 'This expired', []),
            self::EVENT_GATE_OVERRIDE => Craft::t('publishr', '{who} signed this off despite {to}', [
                'who' => $who,
                'to' => $this->toValue ?? Craft::t('publishr', 'failing requirements'),
            ]),
            self::EVENT_COMMENT => Craft::t('publishr', '{who} commented', ['who' => $who]),
            default => $this->event,
        };
    }
}
