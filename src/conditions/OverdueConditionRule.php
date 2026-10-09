<?php

declare(strict_types=1);

namespace justinholtweb\publishr\conditions;

use Craft;
use craft\base\conditions\BaseLightswitchConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQuery;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use justinholtweb\publishr\Plugin;

/**
 * "Overdue" — the due day has passed and the piece has not gone out.
 *
 * The same rule as the navigation badge and My desk (`Items::overdueCountFor()`), so a custom
 * source called "Late" and the badge never disagree about a piece due at five this afternoon.
 */
class OverdueConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('publishr', 'Overdue');
    }

    public function getExclusiveQueryParams(): array
    {
        return [];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var ElementQuery $query */
        $index = Plugin::getInstance()->entryIndex;

        $query->andWhere([
            $this->value ? 'exists' : 'not exists',
            $index->itemSubquery()->andWhere($index->overdueCondition()),
        ]);
    }

    public function matchElement(ElementInterface $element): bool
    {
        $index = Plugin::getInstance()->entryIndex;
        $item = $element instanceof Entry ? $index->itemForElement($element) : null;

        return $this->matchValue($item !== null && $index->isLate($item));
    }
}
