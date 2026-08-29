<?php

declare(strict_types=1);

namespace justinholtweb\publishr\models;

use Craft;
use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use justinholtweb\publishr\records\StageRecord;

/**
 * One editorial stage — "Assigned", "In progress", "Needs edit", "Ready".
 *
 * A stage is **not** a Craft status and never becomes one. Craft's status is derived in SQL from
 * `enabled` and the dates, and there is no seam to extend it; anything that tried would be fighting
 * every element query on the site. So Publishr runs a second, parallel axis: Craft answers "is this
 * public", Publishr answers "where is this in the process". The pair is the truth, and the useful
 * consequence is that Publishr cannot break a front end — turning the plugin off leaves every
 * entry exactly as public as it was.
 *
 * `isPublished` marks the stage a piece lands on when it actually goes live. It is the one place
 * the two axes touch, and it is set by Alarm Clock noticing the crossing rather than by anybody
 * remembering to drag a card.
 */
class Stage extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public ?string $description = null;

    /** One of {@see self::COLORS}. Craft's own status-colour vocabulary, so the CP looks native. */
    public string $color = 'gray';

    /** Where a piece starts when Publishr first takes an interest in it. */
    public bool $isDefault = false;

    /** The stage a piece is moved to when it goes live. At most one. */
    public bool $isPublished = false;

    /** Whether moving *into* this stage has to satisfy the publish requirements. */
    public bool $gated = false;

    public ?int $sortOrder = null;
    public ?string $uid = null;

    public const COLORS = [
        'gray', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'teal',
        'cyan', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink',
    ];

    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['name'],
            ],
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class, 'reservedWords' => ['id', 'dateCreated', 'dateUpdated', 'uid', 'title']],
            [['handle'], UniqueValidator::class, 'targetClass' => StageRecord::class, 'targetAttribute' => 'handle'],
            [['color'], 'in', 'range' => self::COLORS],
            [['isDefault', 'isPublished', 'gated'], 'boolean'],
            [['sortOrder'], 'integer'],
        ];
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return [
            'name' => $this->name,
            'handle' => $this->handle,
            'description' => $this->description,
            'color' => $this->color,
            'isDefault' => $this->isDefault,
            'isPublished' => $this->isPublished,
            'gated' => $this->gated,
            'sortOrder' => $this->sortOrder,
        ];
    }

    public function __toString(): string
    {
        return $this->name;
    }

    /**
     * The five stages a fresh install gets.
     *
     * Chosen so that a site which never opens the settings screen still has a working board: a
     * place to put an idea, a place for work in progress, a place for work waiting on somebody
     * else, a place for work that is finished, and a place for work that is out.
     *
     * "Ready" is gated by default — it is the stage that means "an editor has signed this off",
     * which is exactly the moment a checklist is worth having.
     *
     * @return Stage[]
     */
    public static function defaults(): array
    {
        return [
            new self([
                'name' => Craft::t('publishr', 'Idea'),
                'handle' => 'idea',
                'description' => Craft::t('publishr', 'Commissioned or suggested. Nobody is writing it yet.'),
                'color' => 'gray',
                'isDefault' => true,
                'sortOrder' => 1,
            ]),
            new self([
                'name' => Craft::t('publishr', 'In progress'),
                'handle' => 'inProgress',
                'description' => Craft::t('publishr', 'Being written right now.'),
                'color' => 'blue',
                'sortOrder' => 2,
            ]),
            new self([
                'name' => Craft::t('publishr', 'Needs edit'),
                'handle' => 'needsEdit',
                'description' => Craft::t('publishr', 'Written, waiting on an editor.'),
                'color' => 'orange',
                'sortOrder' => 3,
            ]),
            new self([
                'name' => Craft::t('publishr', 'Ready'),
                'handle' => 'ready',
                'description' => Craft::t('publishr', 'Signed off. Waiting on its post date.'),
                'color' => 'teal',
                'gated' => true,
                'sortOrder' => 4,
            ]),
            new self([
                'name' => Craft::t('publishr', 'Published'),
                'handle' => 'published',
                'description' => Craft::t('publishr', 'Live. Moved here automatically when the post date passes.'),
                'color' => 'green',
                'isPublished' => true,
                'sortOrder' => 5,
            ]),
        ];
    }
}
