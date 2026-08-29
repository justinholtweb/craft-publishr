<?php

declare(strict_types=1);

namespace justinholtweb\publishr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Entry;
use craft\events\ConfigEvent;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use justinholtweb\publishr\events\RegisterGateTypesEvent;
use justinholtweb\publishr\gates\AssigneeRequired;
use justinholtweb\publishr\gates\Checklist;
use justinholtweb\publishr\gates\CommentsResolved;
use justinholtweb\publishr\gates\GateTypeInterface;
use justinholtweb\publishr\gates\MinWordCount;
use justinholtweb\publishr\gates\QualityReview;
use justinholtweb\publishr\gates\RelationRequired;
use justinholtweb\publishr\gates\RequiredFields;
use justinholtweb\publishr\gates\ScheduledDate;
use justinholtweb\publishr\models\Edition;
use justinholtweb\publishr\models\Gate;
use justinholtweb\publishr\models\GateReport;
use justinholtweb\publishr\models\GateResult;
use justinholtweb\publishr\models\Stage;
use justinholtweb\publishr\Plugin;
use justinholtweb\publishr\records\GateRecord;
use justinholtweb\publishr\records\Table;
use Throwable;

/**
 * Publish requirements: what they are, and whether a piece meets them.
 */
class Gates extends Component
{
    public const CONFIG_KEY = 'publishr.gates';

    /** @event RegisterGateTypesEvent Fired so other code can add requirement types. */
    public const EVENT_REGISTER_GATE_TYPES = 'registerGateTypes';

    /** @var Gate[]|null */
    private ?array $_gates = null;

    /** @var array<string, class-string<GateTypeInterface>>|null */
    private ?array $_types = null;

    // ---------------------------------------------------------------------- types

    /** @return array<string, class-string<GateTypeInterface>> Keyed by type handle. */
    public function getTypes(): array
    {
        if ($this->_types !== null) {
            return $this->_types;
        }

        $event = new RegisterGateTypesEvent([
            'types' => [
                RequiredFields::class,
                MinWordCount::class,
                RelationRequired::class,
                ScheduledDate::class,
                AssigneeRequired::class,
                CommentsResolved::class,
                Checklist::class,
                QualityReview::class,
            ],
        ]);

        $this->trigger(self::EVENT_REGISTER_GATE_TYPES, $event);

        $this->_types = [];

        foreach ($event->types as $class) {
            if (is_subclass_of($class, GateTypeInterface::class)) {
                $this->_types[$class::handle()] = $class;
            }
        }

        return $this->_types;
    }

    /** @return class-string<GateTypeInterface>|null */
    public function getType(string $handle): ?string
    {
        return $this->getTypes()[$handle] ?? null;
    }

    /**
     * The gate types as plain arrays, for templates.
     *
     * Twig cannot call a static method on a class-name string — `{{ class.displayName() }}` throws
     * "impossible to invoke a method on a string variable" at *render* time, so the settings screen
     * 500s while every unit test passes. Flattening here rather than in the template keeps that
     * from being rediscovered once per screen.
     *
     * @return array<int, array{handle: string, name: string, description: string, available: bool, unavailableReason: string|null}>
     */
    public function describeTypes(): array
    {
        $out = [];

        foreach ($this->getTypes() as $handle => $class) {
            $out[] = [
                'handle' => $handle,
                'name' => $class::displayName(),
                'description' => $class::description(),
                'available' => $class::isAvailable(),
                'unavailableReason' => $class::unavailableReason(),
            ];
        }

        return $out;
    }

    /** @return array{handle: string, name: string, description: string, available: bool, unavailableReason: string|null}|null */
    public function describeType(string $handle): ?array
    {
        foreach ($this->describeTypes() as $type) {
            if ($type['handle'] === $handle) {
                return $type;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- definitions

    /** @return Gate[] */
    public function getAllGates(): array
    {
        if ($this->_gates !== null) {
            return $this->_gates;
        }

        $this->_gates = [];

        $records = GateRecord::find()
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
            ->all();

        foreach ($records as $record) {
            $this->_gates[] = new Gate([
                'id' => (int)$record->id,
                'name' => $record->name,
                'handle' => $record->handle,
                'type' => $record->type,
                'description' => $record->description,
                'settings' => $this->decode($record->settings),
                'sectionUids' => $this->decode($record->sectionUids),
                'stageHandles' => $this->decode($record->stageHandles),
                'severity' => $record->severity,
                'enabled' => (bool)$record->enabled,
                'sortOrder' => $record->sortOrder !== null ? (int)$record->sortOrder : null,
                'uid' => $record->uid,
            ]);
        }

        return $this->_gates;
    }

    public function getGateById(int $id): ?Gate
    {
        foreach ($this->getAllGates() as $gate) {
            if ($gate->id === $id) {
                return $gate;
            }
        }

        return null;
    }

    public function getGateByHandle(string $handle): ?Gate
    {
        foreach ($this->getAllGates() as $gate) {
            if ($gate->handle === $handle) {
                return $gate;
            }
        }

        return null;
    }

    public function saveGate(Gate $gate, bool $runValidation = true): bool
    {
        if (!Edition::allowsGates(Plugin::getInstance()->isPro())) {
            $gate->addError('name', Craft::t('publishr', 'Publish requirements are a Pro feature.'));

            return false;
        }

        if ($this->getType($gate->type) === null) {
            $gate->addError('type', Craft::t('publishr', 'Unknown requirement type “{type}”.', ['type' => $gate->type]));

            return false;
        }

        if ($runValidation && !$gate->validate()) {
            return false;
        }

        $isNew = $gate->id === null;

        if ($isNew) {
            $gate->uid = StringHelper::UUID();
            $gate->sortOrder ??= (int)((new Query())->from([Table::GATES])->max('[[sortOrder]]') ?? 0) + 1;
        } elseif (!$gate->uid) {
            $gate->uid = Db::uidById(Table::GATES, $gate->id);
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_KEY . '.' . $gate->uid,
            $gate->getConfig(),
            "Save the “{$gate->handle}” publish requirement",
        );

        if ($isNew) {
            $gate->id = Db::idByUid(Table::GATES, $gate->uid);
        }

        $this->refresh();

        return true;
    }

    public function deleteGateById(int $id): bool
    {
        $gate = $this->getGateById($id);

        if ($gate === null) {
            return false;
        }

        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_KEY . '.' . $gate->uid,
            "Delete the “{$gate->handle}” publish requirement",
        );

        GateRecord::findOne(['uid' => $gate->uid])?->delete();
        $this->refresh();

        return true;
    }

    public function handleChangedGate(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $data = $event->newValue;

        $record = GateRecord::findOne(['uid' => $uid]) ?? new GateRecord();
        $record->uid = $uid;
        $record->name = $data['name'];
        $record->handle = $data['handle'];
        $record->type = $data['type'];
        $record->description = $data['description'] ?? null;

        // Arrays go in as arrays. Yii's query builder encodes them for a `json` column; encoding
        // here as well stores the JSON *of* a JSON string, which decodes once to a string and
        // reads downstream as an empty setting.
        $record->settings = $data['settings'] ?? [];
        $record->sectionUids = $data['sectionUids'] ?? [];
        $record->stageHandles = $data['stageHandles'] ?? [];

        $record->severity = $data['severity'] ?? Gate::SEVERITY_REQUIRED;
        $record->enabled = (bool)($data['enabled'] ?? true);
        $record->sortOrder = $data['sortOrder'] ?? null;
        $record->save(false);

        $this->refresh();
    }

    public function handleDeletedGate(ConfigEvent $event): void
    {
        GateRecord::findOne(['uid' => $event->tokenMatches[0]])?->delete();
        $this->refresh();
    }

    public function refresh(): void
    {
        $this->_gates = null;
    }

    /** @return array<string, mixed> */
    public function rebuildProjectConfig(): array
    {
        $config = [];

        foreach ($this->getAllGates() as $gate) {
            if ($gate->uid !== null) {
                $config[$gate->uid] = $gate->getConfig();
            }
        }

        return $config;
    }

    // ----------------------------------------------------------------- evaluation

    /**
     * Run every applicable requirement against an entry.
     *
     * `$stage` narrows it to the requirements that stage cares about. Passing null runs everything
     * that applies to the entry's section, which is what the sidebar wants — an editor wants to
     * know what will stop them *before* they try to move the card, not after.
     */
    public function evaluate(Entry $entry, ?Stage $stage = null): GateReport
    {
        if (!Edition::allowsGates(Plugin::getInstance()->isPro())) {
            return new GateReport([], DateTimeHelper::now());
        }

        $sectionUid = $entry->getSection()?->uid;
        $results = [];

        foreach ($this->getAllGates() as $gate) {
            if (!$gate->enabled || !$this->applies($gate, $sectionUid, $stage)) {
                continue;
            }

            $class = $this->getType($gate->type);

            if ($class === null) {
                $results[] = new GateResult($gate->handle, $gate->name, GateResult::SKIPPED, $gate->severity,
                    Craft::t('publishr', 'The “{type}” requirement type isn’t installed.', ['type' => $gate->type]));

                continue;
            }

            if (!$class::isAvailable()) {
                $results[] = new GateResult($gate->handle, $gate->name, GateResult::SKIPPED, $gate->severity,
                    $class::unavailableReason());

                continue;
            }

            try {
                $results[] = (new $class())->check($entry, $gate);
            } catch (Throwable $e) {
                // A requirement that throws is a broken requirement, not a failing piece of
                // content. Blocking sign-off because somebody's custom gate has a typo in it
                // would make the plugin's worst day everybody else's worst day.
                Craft::warning("Publishr gate “{$gate->handle}” threw: " . $e->getMessage(), Plugin::LOG_CATEGORY);

                $results[] = new GateResult($gate->handle, $gate->name, GateResult::SKIPPED, $gate->severity,
                    Craft::t('publishr', 'This requirement couldn’t run.'), $e->getMessage());
            }
        }

        return new GateReport($results, DateTimeHelper::now());
    }

    /** Whether a gate applies to a given section and stage. */
    public function applies(Gate $gate, ?string $sectionUid, ?Stage $stage): bool
    {
        if ($gate->sectionUids !== [] && ($sectionUid === null || !in_array($sectionUid, $gate->sectionUids, true))) {
            return false;
        }

        if ($stage !== null && $gate->stageHandles !== [] && !in_array($stage->handle, $gate->stageHandles, true)) {
            return false;
        }

        return true;
    }

    /**
     * The report for an entry, reusing the cached one when it is still about this text.
     *
     * The cache is keyed on nothing but time: any verdict recorded before the element's own
     * `dateUpdated` is about a version that no longer exists, and a green tick against prose
     * somebody has since gutted is worse than no tick at all.
     */
    public function report(Entry $entry, bool $recheck = false): GateReport
    {
        $plugin = Plugin::getInstance();
        $item = $plugin->items->forEntry($entry);

        if (!$recheck && $item !== null && $item->gateStateIsFresh($entry)) {
            return GateReport::fromArray($item->gateState, $item->gatesCheckedAt);
        }

        $report = $this->evaluate($entry);

        if ($item !== null) {
            $plugin->items->storeGateReport($item, $report);
        }

        return $report;
    }

    /**
     * Record a person ticking a manual checklist box.
     *
     * Stored beside the cached verdict rather than in a table of its own, because a tick has
     * exactly the same lifetime as the verdict: both are statements about one version of the text,
     * and both stop being true the moment somebody edits it.
     */
    public function setTicks(Entry $entry, string $gateHandle, array $ticked): bool
    {
        $plugin = Plugin::getInstance();
        $item = $plugin->items->forEntry($entry, true);

        if ($item === null) {
            return false;
        }

        $state = $item->gateStateIsFresh($entry) ? ($item->gateState ?? []) : [];
        $state['ticks'][$gateHandle] = array_values($ticked);

        $item->gateState = $state;
        $item->gatesCheckedAt = DateTimeHelper::now();

        if (!$plugin->items->save($item)) {
            return false;
        }

        // Re-run now so the sidebar shows the effect of the tick immediately, and so the stored
        // verdict and the stored ticks describe the same instant.
        $report = $this->evaluate($entry);
        $merged = $report->toArray();
        $merged['ticks'] = $state['ticks'];

        $item->gateState = $merged;
        $item->gatesCheckedAt = DateTimeHelper::now();

        return $plugin->items->save($item);
    }

    private function decode(mixed $value): array
    {
        for ($i = 0; $i < 3 && is_string($value); $i++) {
            $value = Json::decodeIfJson($value);
        }

        return is_array($value) ? $value : [];
    }
}
