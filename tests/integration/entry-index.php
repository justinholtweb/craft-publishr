<?php
/**
 * Publishr in Craft's own entries index — the columns, card attributes and condition rules.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-publishr/tests/integration/entry-index.php
 *
 * Every rule is applied to a real element query and the result set compared with what the rule's
 * own `matchElement()` says, so the SQL and the PHP cannot drift apart. Idempotent and
 * self-cleaning, like `checks.php`; the edition is switched in memory only.
 */

$root = '/var/www/html';
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\conditions\entries\EntryCondition;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use justinholtweb\publishr\conditions\AssignedToMeConditionRule;
use justinholtweb\publishr\conditions\OverdueConditionRule;
use justinholtweb\publishr\conditions\StageConditionRule;
use justinholtweb\publishr\Plugin;
use justinholtweb\publishr\records\Table;
use justinholtweb\publishr\services\EntryIndex;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage()
            . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$originalEdition = $plugin->edition;
$plugin->edition = Plugin::EDITION_LITE;

$settings = $plugin->getSettings();
$settings->notificationsEnabled = false;
$settings->queueNotifications = false;
$settings->autoTrack = false;
$settings->blockPublish = false;
$settings->checkGatesOnSave = false;

$suffix = substr(bin2hex(random_bytes(3)), 0, 6);
$siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
$admin = User::find()->admin()->status(null)->one();
$userSvc = Craft::$app->getUser();

$section = Craft::$app->getEntries()->getSectionByHandle('news');
$entryType = $section?->getEntryTypes()[0] ?? null;

if ($section === null || $entryType === null || $admin === null) {
    echo "The `news` section or an admin is missing from the test site; nothing to run against.\n";
    exit(1);
}

$stages = $plugin->stages;
$working = $stages->getStageByHandle('inProgress');
$published = $stages->getPublishedStage();
$default = $stages->getDefaultStage();

if ($working === null || $published === null || $default === null) {
    echo "The seeded stages are missing; nothing to run against.\n";
    exit(1);
}

$created = [];

$makeEntry = function(string $title) use ($section, $entryType, $siteId, &$created): Entry {
    $entry = new Entry();
    $entry->sectionId = (int)$section->id;
    $entry->typeId = (int)$entryType->id;
    $entry->siteId = $siteId;
    $entry->title = $title . ' ' . bin2hex(random_bytes(2));

    if (!Craft::$app->getElements()->saveElement($entry)) {
        throw new RuntimeException('Could not save ' . $title . ': ' . json_encode($entry->getErrors()));
    }

    $created[] = (int)$entry->id;

    return $entry;
};

// A: in progress, mine, due yesterday — late.
// B: default stage, nobody's, due tomorrow — not late.
// C: never tracked.
// D: published, mine, due yesterday — not late, because it has already gone out.
$a = $makeEntry("Index A $suffix");
$b = $makeEntry("Index B $suffix");
$c = $makeEntry("Index C $suffix");
$d = $makeEntry("Index D $suffix");

$yesterday = DateTimeHelper::now()->modify('-1 day')->setTime(12, 0);
$tomorrow = DateTimeHelper::now()->modify('+1 day')->setTime(12, 0);

$itemA = $plugin->items->create((int)$a->id, $siteId, $working->id);
$plugin->items->assign($itemA, (int)$admin->id);
$plugin->items->setDueDate($itemA, $yesterday);

$itemB = $plugin->items->create((int)$b->id, $siteId, $default->id);
$plugin->items->setDueDate($itemB, $tomorrow);

$itemD = $plugin->items->create((int)$d->id, $siteId, $published->id);
$plugin->items->assign($itemD, (int)$admin->id);
$plugin->items->setDueDate($itemD, $yesterday);

$ids = [(int)$a->id, (int)$b->id, (int)$c->id, (int)$d->id];
$names = [(int)$a->id => 'A', (int)$b->id => 'B', (int)$c->id => 'C', (int)$d->id => 'D'];

/** Apply one rule to a query over the four entries; return the letters it kept, sorted. */
$apply = function(array $ruleConfig, bool $drafts = false) use ($ids, $names, $siteId): array {
    $condition = Entry::createCondition();
    $condition->addConditionRule(Craft::$app->getConditions()->createConditionRule($ruleConfig));

    $query = Entry::find()->siteId($siteId)->status(null);

    if ($drafts) {
        $query->drafts(true)->draftOf($ids);
    } else {
        $query->id($ids);
    }

    $condition->modifyQuery($query);

    $letters = array_map(
        fn(Entry $e) => $names[(int)$e->getCanonicalId()] ?? '?',
        $query->all(),
    );
    sort($letters);

    return $letters;
};

/** The same rule through matchElement(), for the SQL/PHP agreement checks. */
$match = function(array $ruleConfig) use ($a, $b, $c, $d): array {
    $condition = Entry::createCondition();
    $condition->addConditionRule(Craft::$app->getConditions()->createConditionRule($ruleConfig));
    $letters = [];

    foreach (['A' => $a, 'B' => $b, 'C' => $c, 'D' => $d] as $letter => $entry) {
        if ($condition->matchElement($entry)) {
            $letters[] = $letter;
        }
    }

    return $letters;
};

$expect = function(array $got, array $want): bool|string {
    return $got === $want ?: 'got [' . implode(',', $got) . '], expected [' . implode(',', $want) . ']';
};

// ----------------------------------------------------------------------- columns

section('Index columns and card attributes');

check('the stage, assignee and due columns are offered on the entries index', function() {
    $attrs = Entry::tableAttributes();

    foreach ([EntryIndex::ATTR_STAGE, EntryIndex::ATTR_ASSIGNEE, EntryIndex::ATTR_DUE] as $key) {
        if (!isset($attrs[$key])) {
            return "missing $key";
        }
    }

    return true;
});

check('and as card attributes, each with a placeholder that renders', function() {
    if (!defined(craft\base\Element::class . '::EVENT_REGISTER_CARD_ATTRIBUTES')) {
        return true;
    }

    $attrs = Entry::cardAttributes();

    foreach ([EntryIndex::ATTR_STAGE, EntryIndex::ATTR_ASSIGNEE, EntryIndex::ATTR_DUE] as $key) {
        if (!isset($attrs[$key]['placeholder']) || !is_callable($attrs[$key]['placeholder'])) {
            return "missing $key";
        }

        $attrs[$key]['placeholder']();
    }

    return true;
});

check('nobody logged in sees empty cells — the columns carry the calendar’s permission', function() use ($plugin, $a, $userSvc) {
    $userSvc->setIdentity(null);
    $plugin->entryIndex->reset();

    return $a->getAttributeHtml(EntryIndex::ATTR_STAGE) === '' ?: 'rendered for a guest';
});

$userSvc->setIdentity($admin);

check('the stage cell shows the stage, in Lite', function() use ($plugin, $a, $working) {
    $plugin->entryIndex->reset();
    $html = $a->getAttributeHtml(EntryIndex::ATTR_STAGE);

    return (str_contains($html, $working->name) && str_contains($html, 'status ' . $working->color)) ?: $html;
});

check('the assignee cell is a user chip, and an unowned piece says so', function() use ($a, $b, $admin) {
    $mine = $a->getAttributeHtml(EntryIndex::ATTR_ASSIGNEE);
    $none = $b->getAttributeHtml(EntryIndex::ATTR_ASSIGNEE);

    if (!str_contains($mine, 'data-id="' . $admin->id . '"')) {
        return 'no chip: ' . $mine;
    }

    return str_contains($none, 'Unassigned') ?: $none;
});

check('a late piece is marked overdue in words; a published one with the same date is not', function() use ($a, $b, $d) {
    $late = $a->getAttributeHtml(EntryIndex::ATTR_DUE);

    if (!str_contains($late, 'Overdue')) {
        return 'A: ' . $late;
    }

    if (str_contains($d->getAttributeHtml(EntryIndex::ATTR_DUE), 'Overdue')) {
        return 'D marked overdue';
    }

    return !str_contains($b->getAttributeHtml(EntryIndex::ATTR_DUE), 'Overdue') ?: 'B marked overdue';
});

check('an untracked entry shows a dash, not an error', function() use ($c) {
    return str_contains($c->getAttributeHtml(EntryIndex::ATTR_STAGE), '–') ?: 'no dash';
});

check('a whole page loads its records once, not per row', function() use ($plugin, $ids, $siteId, $names) {
    $plugin->entryIndex->reset();
    $page = Entry::find()->id($ids)->siteId($siteId)->status(null)->orderBy(['elements.id' => SORT_ASC])->all();

    // Render the first row, then pull the second row's record out from under the memo. If the
    // second cell still finds it, it was loaded with the first.
    $page[0]->getAttributeHtml(EntryIndex::ATTR_STAGE);
    $second = null;

    foreach ($page as $e) {
        if ($names[(int)$e->id] === 'B') {
            $second = $e;
        }
    }

    $row = (new craft\db\Query())->from(Table::ITEMS)->where(['elementId' => $second->id, 'siteId' => $siteId])->one();
    Craft::$app->getDb()->createCommand()->delete(Table::ITEMS, ['id' => $row['id']])->execute();

    try {
        $html = $second->getAttributeHtml(EntryIndex::ATTR_STAGE);
    } finally {
        unset($row['id']);
        Craft::$app->getDb()->createCommand()->insert(Table::ITEMS, $row)->execute();
        $plugin->entryIndex->reset();
    }

    return !str_contains($html, '–') ?: 'the second row queried on its own';
});

check('a stage name is escaped, not rendered', function() use ($plugin, $a, $working) {
    $original = $working->name;
    $working->name = '<img src=x onerror=alert(1)>';
    $plugin->entryIndex->reset();

    try {
        $html = $a->getAttributeHtml(EntryIndex::ATTR_STAGE);
    } finally {
        $working->name = $original;
    }

    return !str_contains($html, '<img') ?: $html;
});

// --------------------------------------------------------------------- conditions

section('Condition rules');

check('all three rules are selectable on an entry condition', function() {
    $types = array_map(fn($rule) => get_class($rule), Entry::createCondition()->getSelectableConditionRules());

    foreach ([StageConditionRule::class, AssignedToMeConditionRule::class, OverdueConditionRule::class] as $class) {
        if (!in_array($class, $types, true)) {
            return "$class missing";
        }
    }

    return true;
});

check('they are not offered on a user condition', function() {
    $types = array_map(fn($rule) => get_class($rule), User::createCondition()->getSelectableConditionRules());

    return !in_array(StageConditionRule::class, $types, true) ?: 'offered on users';
});

$stageRule = fn(string $operator, array $values = []) => [
    'class' => StageConditionRule::class,
    'operator' => $operator,
    'values' => $values,
];

check('stage is "In progress" → A', function() use ($apply, $expect, $stageRule, $working) {
    return $expect($apply($stageRule('in', [$working->uid])), ['A']);
});

check('stage is not "In progress" → B, C, D (untracked included)', function() use ($apply, $expect, $stageRule, $working) {
    return $expect($apply($stageRule('ni', [$working->uid])), ['B', 'C', 'D']);
});

check('stage is empty → C; has a value → A, B, D', function() use ($apply, $expect, $stageRule) {
    $empty = $expect($apply($stageRule('empty')), ['C']);

    return $empty === true ? $expect($apply($stageRule('notempty')), ['A', 'B', 'D']) : $empty;
});

check('a rule naming only a deleted stage matches nothing, rather than everything', function() use ($apply, $expect, $stageRule) {
    return $expect($apply($stageRule('in', ['00000000-0000-0000-0000-000000000000'])), []);
});

check('a half-built stage rule (nothing picked) does not empty the index', function() use ($apply, $expect, $stageRule) {
    return $expect($apply($stageRule('in')), ['A', 'B', 'C', 'D']);
});

check('assigned to me → A, D; off → B, C', function() use ($apply, $expect) {
    $on = $expect($apply(['class' => AssignedToMeConditionRule::class, 'value' => true]), ['A', 'D']);

    return $on === true ? $expect($apply(['class' => AssignedToMeConditionRule::class, 'value' => false]), ['B', 'C']) : $on;
});

check('with nobody logged in, "assigned to me" matches nothing', function() use ($apply, $expect, $userSvc, $admin) {
    $userSvc->setIdentity(null);

    try {
        return $expect($apply(['class' => AssignedToMeConditionRule::class, 'value' => true]), []);
    } finally {
        $userSvc->setIdentity($admin);
    }
});

check('overdue → A only (B is not due yet, D has gone out); off → B, C, D', function() use ($apply, $expect) {
    $on = $expect($apply(['class' => OverdueConditionRule::class, 'value' => true]), ['A']);

    return $on === true ? $expect($apply(['class' => OverdueConditionRule::class, 'value' => false]), ['B', 'C', 'D']) : $on;
});

check('the overdue rule agrees with the navigation badge', function() use ($plugin, $apply, $admin) {
    $rows = $apply(['class' => OverdueConditionRule::class, 'value' => true]);
    $mine = $apply(['class' => AssignedToMeConditionRule::class, 'value' => true]);

    // Of this run's entries, the ones both late and mine: A. The badge counts the whole site, so
    // the check is that A is counted at all and D is not.
    $badge = $plugin->items->overdueCountFor((int)$admin->id);

    return (array_values(array_intersect($rows, $mine)) === ['A'] && $badge >= 1) ?: "badge $badge";
});

check('matchElement() agrees with the SQL for every rule', function() use ($apply, $match, $stageRule, $working) {
    $configs = [
        $stageRule('in', [$working->uid]),
        $stageRule('ni', [$working->uid]),
        $stageRule('empty'),
        $stageRule('notempty'),
        ['class' => AssignedToMeConditionRule::class, 'value' => true],
        ['class' => AssignedToMeConditionRule::class, 'value' => false],
        ['class' => OverdueConditionRule::class, 'value' => true],
        ['class' => OverdueConditionRule::class, 'value' => false],
    ];

    foreach ($configs as $config) {
        $sql = $apply($config);
        $php = $match($config);

        if ($sql !== $php) {
            return get_class(new $config['class']()) . ': SQL [' . implode(',', $sql) . '] vs PHP [' . implode(',', $php) . ']';
        }
    }

    return true;
});

check('a draft row matches its piece’s stage', function() use ($a, $apply, $expect, $stageRule, $working, $admin) {
    $draft = Craft::$app->getDrafts()->createDraft($a, (int)$admin->id, 'Index check');

    try {
        return $expect($apply($stageRule('in', [$working->uid]), true), ['A']);
    } finally {
        Craft::$app->getElements()->deleteElement($draft, true);
    }
});

check('a saved custom source round-trips through its config and still filters', function() use ($ids, $names, $siteId, $stageRule, $working, $expect) {
    $condition = Entry::createCondition();
    $condition->addConditionRule(Craft::$app->getConditions()->createConditionRule($stageRule('in', [$working->uid])));
    $condition->addConditionRule(Craft::$app->getConditions()->createConditionRule(['class' => OverdueConditionRule::class, 'value' => true]));

    $restored = Craft::$app->getConditions()->createCondition($condition->getConfig());

    if (!$restored instanceof EntryCondition || count($restored->getConditionRules()) !== 2) {
        return 'rules lost in the round trip';
    }

    $query = Entry::find()->id($ids)->siteId($siteId)->status(null);
    $restored->modifyQuery($query);

    return $expect(array_map(fn(Entry $e) => $names[(int)$e->id], $query->all()), ['A']);
});

// --------------------------------------------------------------------- tidy up

section('Tidy up');

check('the suite’s entries are gone', function() use (&$created) {
    foreach ($created as $id) {
        $entry = Entry::find()->id($id)->status(null)->one();

        if ($entry !== null) {
            Craft::$app->getElements()->deleteElement($entry, true);
        }
    }

    $left = Entry::find()->id($created)->status(null)->drafts(null)->count();

    return (int)$left === 0 ?: "$left left behind";
});

$userSvc->setIdentity(null);
$plugin->edition = $originalEdition;

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
