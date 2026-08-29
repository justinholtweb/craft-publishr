<?php

declare(strict_types=1);

namespace justinholtweb\publishr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\publishr\models\Edition;
use justinholtweb\publishr\models\Stage;
use justinholtweb\publishr\Plugin;
use justinholtweb\publishr\records\StageRecord;
use justinholtweb\publishr\records\Table;

/**
 * The editorial workflow, backed by project config.
 *
 * Project config rather than a plain table because a stage is *structure*, not content: the
 * staging site and production have to agree about what "Needs edit" means, and a workflow that
 * has to be re-created by hand on every environment is a workflow that silently diverges.
 */
class Stages extends Component
{
    public const CONFIG_KEY = 'publishr.stages';

    /** @var Stage[]|null */
    private ?array $_stages = null;

    /** @return Stage[] In workflow order. */
    public function getAllStages(): array
    {
        if ($this->_stages !== null) {
            return $this->_stages;
        }

        $this->_stages = [];

        $records = StageRecord::find()
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
            ->all();

        foreach ($records as $record) {
            $this->_stages[] = new Stage([
                'id' => (int)$record->id,
                'name' => $record->name,
                'handle' => $record->handle,
                'description' => $record->description,
                'color' => $record->color,
                'isDefault' => (bool)$record->isDefault,
                'isPublished' => (bool)$record->isPublished,
                'gated' => (bool)$record->gated,
                'sortOrder' => $record->sortOrder !== null ? (int)$record->sortOrder : null,
                'uid' => $record->uid,
            ]);
        }

        return $this->_stages;
    }

    public function getStageById(?int $id): ?Stage
    {
        if ($id === null) {
            return null;
        }

        foreach ($this->getAllStages() as $stage) {
            if ($stage->id === $id) {
                return $stage;
            }
        }

        return null;
    }

    public function getStageByHandle(string $handle): ?Stage
    {
        foreach ($this->getAllStages() as $stage) {
            if ($stage->handle === $handle) {
                return $stage;
            }
        }

        return null;
    }

    public function getStageByUid(string $uid): ?Stage
    {
        foreach ($this->getAllStages() as $stage) {
            if ($stage->uid === $uid) {
                return $stage;
            }
        }

        return null;
    }

    /** Where a piece starts. */
    public function getDefaultStage(): ?Stage
    {
        $stages = $this->getAllStages();

        foreach ($stages as $stage) {
            if ($stage->isDefault) {
                return $stage;
            }
        }

        return $stages[0] ?? null;
    }

    /** The stage a piece lands on when it actually goes live, if the site has named one. */
    public function getPublishedStage(): ?Stage
    {
        foreach ($this->getAllStages() as $stage) {
            if ($stage->isPublished) {
                return $stage;
            }
        }

        return null;
    }

    /** @return array<int, string> Stage ID => name, for a dropdown. */
    public function asOptions(): array
    {
        $options = [];

        foreach ($this->getAllStages() as $stage) {
            $options[(int)$stage->id] = $stage->name;
        }

        return $options;
    }

    public function canCreateStage(): bool
    {
        return !Edition::stageLimitReached(count($this->getAllStages()), Plugin::getInstance()->isPro());
    }

    public function saveStage(Stage $stage, bool $runValidation = true): bool
    {
        $isNew = $stage->id === null;

        if ($isNew && !$this->canCreateStage()) {
            $stage->addError('name', Craft::t('publishr', 'Publishr Lite supports {max} editorial stages. Upgrade to Pro to design a workflow of your own.', [
                'max' => Edition::LITE_MAX_STAGES,
            ]));

            return false;
        }

        if ($runValidation && !$stage->validate()) {
            return false;
        }

        if ($isNew) {
            $stage->uid = StringHelper::UUID();
            $stage->sortOrder ??= (int)((new Query())->from([Table::STAGES])->max('[[sortOrder]]') ?? 0) + 1;
        } elseif (!$stage->uid) {
            $stage->uid = Db::uidById(Table::STAGES, $stage->id);
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_KEY . '.' . $stage->uid,
            $stage->getConfig(),
            "Save the “{$stage->handle}” editorial stage",
        );

        if ($isNew) {
            $stage->id = Db::idByUid(Table::STAGES, $stage->uid);
        }

        // At most one default and at most one published stage. Enforced here rather than by a
        // partial unique index, which MySQL does not have.
        if ($stage->isDefault) {
            $this->clearOtherFlags($stage, 'isDefault');
        }

        if ($stage->isPublished) {
            $this->clearOtherFlags($stage, 'isPublished');
        }

        $this->refresh();

        return true;
    }

    private function clearOtherFlags(Stage $winner, string $flag): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        foreach ($this->getAllStages() as $stage) {
            if ($stage->uid === $winner->uid || !$stage->$flag) {
                continue;
            }

            $stage->$flag = false;
            $projectConfig->set(self::CONFIG_KEY . '.' . $stage->uid, $stage->getConfig(), 'Clear a previous ' . $flag . ' stage');
        }
    }

    /** @param string[] $uids In the order they should appear. */
    public function reorderStages(array $uids): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        foreach ($uids as $index => $uid) {
            if ($this->getStageByUid((string)$uid) === null) {
                continue;
            }

            $projectConfig->set(self::CONFIG_KEY . '.' . $uid . '.sortOrder', $index + 1, 'Reorder editorial stages');
        }

        $this->refresh();

        return true;
    }

    public function deleteStageById(int $id): bool
    {
        $stage = $this->getStageById($id);

        return $stage !== null && $this->deleteStage($stage);
    }

    /**
     * Items on a deleted stage keep their history and lose their position.
     *
     * `publishr_items.stageId` is `SET NULL`, and an item with no stage reads as untracked — which
     * is honest. Sweeping them onto another stage would rewrite a decision somebody made, and the
     * history would then disagree with the column.
     */
    public function deleteStage(Stage $stage): bool
    {
        if (count($this->getAllStages()) === 1) {
            $stage->addError('name', Craft::t('publishr', 'The last stage can’t be deleted — work needs somewhere to sit.'));

            return false;
        }

        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_KEY . '.' . $stage->uid,
            "Delete the “{$stage->handle}” editorial stage",
        );

        $this->removeStageRecord((string)$stage->uid);
        $this->refresh();

        return true;
    }

    public function handleChangedStage(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $data = $event->newValue;

        $record = StageRecord::findOne(['uid' => $uid]) ?? new StageRecord();
        $record->uid = $uid;
        $record->name = $data['name'];
        $record->handle = $data['handle'];
        $record->description = $data['description'] ?? null;
        $record->color = $data['color'] ?? 'gray';
        $record->isDefault = (bool)($data['isDefault'] ?? false);
        $record->isPublished = (bool)($data['isPublished'] ?? false);
        $record->gated = (bool)($data['gated'] ?? false);
        $record->sortOrder = $data['sortOrder'] ?? null;
        $record->save(false);

        $this->refresh();
    }

    public function handleDeletedStage(ConfigEvent $event): void
    {
        $this->removeStageRecord($event->tokenMatches[0]);
        $this->refresh();
    }

    private function removeStageRecord(string $uid): void
    {
        StageRecord::findOne(['uid' => $uid])?->delete();
    }

    public function refresh(): void
    {
        $this->_stages = null;
    }

    /** @return array<string, mixed> */
    public function rebuildProjectConfig(): array
    {
        $config = [];

        foreach ($this->getAllStages() as $stage) {
            if ($stage->uid !== null) {
                $config[$stage->uid] = $stage->getConfig();
            }
        }

        return $config;
    }

    public function installDefaults(): void
    {
        if ($this->getAllStages() !== []) {
            return;
        }

        foreach (Stage::defaults() as $stage) {
            $this->saveStage($stage, false);
        }
    }
}
