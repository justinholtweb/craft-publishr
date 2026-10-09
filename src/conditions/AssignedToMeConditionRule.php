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
use justinholtweb\publishr\services\EntryIndex;

/**
 * "Assigned to me" — resolved against whoever is looking, at query time.
 *
 * That is what makes one shared custom source ("My work") right for every editor on the desk.
 * With nobody logged in (a console command, a queue job) there is no "me": "on" matches nothing
 * rather than everything, and "off" is no filter.
 */
class AssignedToMeConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('publishr', 'Assigned to me');
    }

    public function getExclusiveQueryParams(): array
    {
        return [];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var ElementQuery $query */
        $userId = Craft::$app->getUser()->getId();

        if ($userId === null) {
            if ($this->value) {
                $query->andWhere('0 = 1');
            }

            return;
        }

        $query->andWhere([
            $this->value ? 'exists' : 'not exists',
            Plugin::getInstance()->entryIndex->itemSubquery()
                ->andWhere([EntryIndex::ALIAS . '.assigneeId' => (int)$userId]),
        ]);
    }

    public function matchElement(ElementInterface $element): bool
    {
        $userId = Craft::$app->getUser()->getId();
        $item = $element instanceof Entry ? Plugin::getInstance()->entryIndex->itemForElement($element) : null;

        if ($userId === null) {
            return !$this->value;
        }

        return $this->matchValue($item !== null && $item->assigneeId === (int)$userId);
    }
}
