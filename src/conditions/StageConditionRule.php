<?php

declare(strict_types=1);

namespace justinholtweb\publishr\conditions;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQuery;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use justinholtweb\publishr\Plugin;
use justinholtweb\publishr\services\EntryIndex;

/**
 * "Editorial stage is …" — for the entries index filter, custom sources, entry field selection
 * conditions, and anything else Craft builds from an entry condition.
 *
 * Values are stage **UIDs**, never IDs: a custom source is project config, and the stage IDs on
 * production are not the ones on a laptop.
 *
 * "Is empty" means "no stage" — untracked entries included, because an entry Publishr has never
 * seen is exactly as stage-less as one whose stage was deleted.
 */
class StageConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    protected bool $includeEmptyOperators = true;

    public function getLabel(): string
    {
        return Craft::t('publishr', 'Editorial stage');
    }

    public function getExclusiveQueryParams(): array
    {
        return [];
    }

    protected function options(): array
    {
        $options = [];

        foreach (Plugin::getInstance()->stages->getAllStages() as $stage) {
            $options[] = ['value' => $stage->uid, 'label' => Craft::t('site', $stage->name)];
        }

        return $options;
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var ElementQuery $query */
        $staged = Plugin::getInstance()->entryIndex->itemSubquery()
            ->andWhere(['not', [EntryIndex::ALIAS . '.stageId' => null]]);

        switch ($this->operator) {
            case self::OPERATOR_EMPTY:
                $query->andWhere(['not exists', $staged]);
                return;

            case self::OPERATOR_NOT_EMPTY:
                $query->andWhere(['exists', $staged]);
                return;
        }

        $stageIds = $this->selectedStageIds();

        // Nothing picked is not a filter — keeps a half-built rule from emptying the index.
        if ($this->getValues() === []) {
            return;
        }

        // Picked, but every pick has since been deleted: "in" matches nothing, "not in" everything.
        if ($stageIds === []) {
            if ($this->operator === self::OPERATOR_IN) {
                $query->andWhere('0 = 1');
            }

            return;
        }

        $query->andWhere([
            $this->operator === self::OPERATOR_NOT_IN ? 'not exists' : 'exists',
            $staged->andWhere([EntryIndex::ALIAS . '.stageId' => $stageIds]),
        ]);
    }

    public function matchElement(ElementInterface $element): bool
    {
        $stageId = $element instanceof Entry
            ? Plugin::getInstance()->entryIndex->itemForElement($element)?->stageId
            : null;

        return match ($this->operator) {
            self::OPERATOR_EMPTY => $stageId === null,
            self::OPERATOR_NOT_EMPTY => $stageId !== null,
            self::OPERATOR_NOT_IN => $stageId === null || !in_array($stageId, $this->selectedStageIds(), true),
            default => $this->getValues() === [] || ($stageId !== null && in_array($stageId, $this->selectedStageIds(), true)),
        };
    }

    /** @return int[] */
    private function selectedStageIds(): array
    {
        $ids = [];

        foreach ($this->getValues() as $uid) {
            $stage = Plugin::getInstance()->stages->getStageByUid((string)$uid);

            if ($stage?->id !== null) {
                $ids[] = $stage->id;
            }
        }

        return $ids;
    }
}
