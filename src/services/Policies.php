<?php

declare(strict_types=1);

namespace justinholtweb\publishr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\elements\Entry;
use justinholtweb\publishr\models\Edition;
use justinholtweb\publishr\models\Policy;
use justinholtweb\publishr\Plugin;
use justinholtweb\publishr\records\PolicyRecord;
use justinholtweb\publishr\records\Table;

/**
 * Freshness policies, backed by project config.
 */
class Policies extends Component
{
    public const CONFIG_KEY = 'publishr.policies';

    /** @var Policy[]|null */
    private ?array $_policies = null;

    /** @return Policy[] */
    public function getAllPolicies(): array
    {
        if ($this->_policies !== null) {
            return $this->_policies;
        }

        $this->_policies = [];

        foreach (PolicyRecord::find()->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])->all() as $record) {
            $this->_policies[] = new Policy([
                'id' => (int)$record->id,
                'name' => $record->name,
                'handle' => $record->handle,
                'sectionUids' => $this->decode($record->sectionUids),
                'entryTypeUids' => $this->decode($record->entryTypeUids),
                'intervalDays' => (int)$record->intervalDays,
                'remindDaysBefore' => (int)$record->remindDaysBefore,
                'assignTo' => $record->assignTo,
                'assigneeId' => $record->assigneeId !== null ? (int)$record->assigneeId : null,
                'enabled' => (bool)$record->enabled,
                'sortOrder' => $record->sortOrder !== null ? (int)$record->sortOrder : null,
                'uid' => $record->uid,
            ]);
        }

        return $this->_policies;
    }

    public function getPolicyById(?int $id): ?Policy
    {
        if ($id === null) {
            return null;
        }

        foreach ($this->getAllPolicies() as $policy) {
            if ($policy->id === $id) {
                return $policy;
            }
        }

        return null;
    }

    public function getPolicyByHandle(string $handle): ?Policy
    {
        foreach ($this->getAllPolicies() as $policy) {
            if ($policy->handle === $handle) {
                return $policy;
            }
        }

        return null;
    }

    /**
     * The policy that governs an entry.
     *
     * **First match wins, in sort order.** Not "most specific", which sounds better and is worse:
     * specificity has no total ordering once a policy can name both sections and entry types, so
     * two overlapping policies would produce a stable-looking answer that changes when somebody
     * adds a third. An explicit, draggable order is a rule an editor can predict.
     */
    public function forEntry(Entry $entry): ?Policy
    {
        $sectionUid = $entry->getSection()?->uid;
        $typeUid = $entry->getType()->uid ?? null;

        foreach ($this->getAllPolicies() as $policy) {
            if ($policy->enabled && $policy->covers($sectionUid, $typeUid)) {
                return $policy;
            }
        }

        return null;
    }

    public function savePolicy(Policy $policy, bool $runValidation = true): bool
    {
        if (!Edition::allowsFreshness(Plugin::getInstance()->isPro())) {
            $policy->addError('name', Craft::t('publishr', 'Freshness reviews are a Pro feature.'));

            return false;
        }

        if ($runValidation && !$policy->validate()) {
            return false;
        }

        $isNew = $policy->id === null;

        if ($isNew) {
            $policy->uid = StringHelper::UUID();
            $policy->sortOrder ??= (int)((new Query())->from([Table::POLICIES])->max('[[sortOrder]]') ?? 0) + 1;
        } elseif (!$policy->uid) {
            $policy->uid = Db::uidById(Table::POLICIES, $policy->id);
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_KEY . '.' . $policy->uid,
            $policy->getConfig(),
            "Save the “{$policy->handle}” freshness policy",
        );

        if ($isNew) {
            $policy->id = Db::idByUid(Table::POLICIES, $policy->uid);
        }

        $this->refresh();

        return true;
    }

    public function deletePolicyById(int $id): bool
    {
        $policy = $this->getPolicyById($id);

        if ($policy === null) {
            return false;
        }

        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_KEY . '.' . $policy->uid,
            "Delete the “{$policy->handle}” freshness policy",
        );

        PolicyRecord::findOne(['uid' => $policy->uid])?->delete();
        $this->refresh();

        return true;
    }

    public function handleChangedPolicy(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $data = $event->newValue;

        $record = PolicyRecord::findOne(['uid' => $uid]) ?? new PolicyRecord();
        $record->uid = $uid;
        $record->name = $data['name'];
        $record->handle = $data['handle'];
        $record->sectionUids = $data['sectionUids'] ?? [];
        $record->entryTypeUids = $data['entryTypeUids'] ?? [];
        $record->intervalDays = (int)($data['intervalDays'] ?? 180);
        $record->remindDaysBefore = (int)($data['remindDaysBefore'] ?? 14);
        $record->assignTo = $data['assignTo'] ?? Policy::ASSIGN_CURRENT;
        $record->assigneeId = $data['assigneeId'] ?? null;
        $record->enabled = (bool)($data['enabled'] ?? true);
        $record->sortOrder = $data['sortOrder'] ?? null;
        $record->save(false);

        $this->refresh();
    }

    public function handleDeletedPolicy(ConfigEvent $event): void
    {
        PolicyRecord::findOne(['uid' => $event->tokenMatches[0]])?->delete();
        $this->refresh();
    }

    /** @param string[] $uids */
    public function reorderPolicies(array $uids): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        foreach ($uids as $index => $uid) {
            if ($this->getPolicyByUid((string)$uid) !== null) {
                $projectConfig->set(self::CONFIG_KEY . '.' . $uid . '.sortOrder', $index + 1, 'Reorder freshness policies');
            }
        }

        $this->refresh();

        return true;
    }

    public function getPolicyByUid(string $uid): ?Policy
    {
        foreach ($this->getAllPolicies() as $policy) {
            if ($policy->uid === $uid) {
                return $policy;
            }
        }

        return null;
    }

    public function refresh(): void
    {
        $this->_policies = null;
    }

    /** @return array<string, mixed> */
    public function rebuildProjectConfig(): array
    {
        $config = [];

        foreach ($this->getAllPolicies() as $policy) {
            if ($policy->uid !== null) {
                $config[$policy->uid] = $policy->getConfig();
            }
        }

        return $config;
    }

    private function decode(mixed $value): array
    {
        for ($i = 0; $i < 3 && is_string($value); $i++) {
            $value = Json::decodeIfJson($value);
        }

        return is_array($value) ? $value : [];
    }
}
