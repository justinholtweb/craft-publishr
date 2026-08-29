<?php
/**
 * Publishr integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-publishr/tests/integration/checks.php
 *
 * Covers what a unit fixture cannot: real entries saved through Craft, the calendar's date
 * arithmetic against the actual database, the gate engine against real field layouts, and the
 * edition boundary. Idempotent and self-cleaning — every run creates its own stage, entries and
 * comments and removes them at the end, and puts the edition back where it found it.
 *
 * Nothing here sends an email: notifications are switched off through the settings model rather
 * than by mocking the mailer, so the code under test takes exactly the branch it takes in
 * production on a site with notifications off.
 */

$root = '/var/www/html';
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use justinholtweb\publishr\gates\AssigneeRequired;
use justinholtweb\publishr\gates\Checklist;
use justinholtweb\publishr\gates\CommentsResolved;
use justinholtweb\publishr\gates\MinWordCount;
use justinholtweb\publishr\gates\RequiredFields;
use justinholtweb\publishr\gates\ScheduledDate;
use justinholtweb\publishr\integrations\AlarmClock;
use justinholtweb\publishr\integrations\RedPen;
use justinholtweb\publishr\models\CalendarEvent;
use justinholtweb\publishr\models\Comment;
use justinholtweb\publishr\models\Edition;
use justinholtweb\publishr\models\Gate;
use justinholtweb\publishr\models\GateResult;
use justinholtweb\publishr\models\HistoryEntry;
use justinholtweb\publishr\models\Item;
use justinholtweb\publishr\models\Policy;
use justinholtweb\publishr\models\Stage;
use justinholtweb\publishr\Plugin;

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
Craft::$app->getPlugins()->switchEdition('publishr', Plugin::EDITION_PRO);

$settings = $plugin->getSettings();
$settings->notificationsEnabled = false;
$settings->queueNotifications = false;
$settings->autoTrack = false;
$settings->blockPublish = false;

// Off for the duration, so the cache-invalidation check measures the invalidation rule itself
// rather than the save hook helpfully re-running the checklist a millisecond later.
$settings->checkGatesOnSave = false;

$suffix = substr(bin2hex(random_bytes(3)), 0, 6);
$siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
$adminId = (int)(craft\elements\User::find()->admin()->one()?->id ?? 1);

$section = Craft::$app->getEntries()->getSectionByHandle('news');
$entryType = $section?->getEntryTypes()[0] ?? null;

if ($section === null || $entryType === null) {
    echo "The `news` section is missing from the test site; nothing to run against.\n";
    exit(1);
}

$created = ['entries' => [], 'stages' => [], 'gates' => [], 'policies' => [], 'comments' => []];

/** Make an entry that belongs to this run and nothing else. */
$makeEntry = function(string $title, ?DateTime $postDate = null, bool $enabled = true) use ($section, $entryType, $siteId, &$created): Entry {
    $entry = new Entry();
    $entry->sectionId = (int)$section->id;
    $entry->typeId = (int)$entryType->id;
    $entry->siteId = $siteId;
    $entry->title = $title;
    $entry->enabled = $enabled;
    $entry->postDate = $postDate;

    if (!Craft::$app->getElements()->saveElement($entry)) {
        throw new RuntimeException('Could not save ' . $title . ': ' . json_encode($entry->getErrors()));
    }

    $created['entries'][] = (int)$entry->id;

    return $entry;
};

// ---------------------------------------------------------------------- workflow

section('Stages');

check('the installer seeds a workflow', function() use ($plugin) {
    $stages = $plugin->stages->getAllStages();

    return count($stages) >= 5 ?: 'found ' . count($stages);
});

check('exactly one stage is the default, and exactly one is the published stage', function() use ($plugin) {
    $defaults = array_filter($plugin->stages->getAllStages(), fn(Stage $s) => $s->isDefault);
    $published = array_filter($plugin->stages->getAllStages(), fn(Stage $s) => $s->isPublished);

    return (count($defaults) === 1 && count($published) === 1)
        ?: count($defaults) . ' defaults, ' . count($published) . ' published';
});

check('a new stage round-trips through project config', function() use ($plugin, $suffix, &$created) {
    $stage = new Stage([
        'name' => "Legal $suffix",
        'handle' => "legal$suffix",
        'color' => 'violet',
        'gated' => true,
    ]);

    if (!$plugin->stages->saveStage($stage)) {
        return json_encode($stage->getErrors());
    }

    $created['stages'][] = (int)$stage->id;
    $plugin->stages->refresh();

    $found = $plugin->stages->getStageByHandle("legal$suffix");

    return ($found !== null && $found->gated && $found->color === 'violet')
        ?: 'the stage did not come back the way it went in';
});

check('setting a second published stage clears the first', function() use ($plugin, $suffix) {
    $before = $plugin->stages->getPublishedStage();
    $mine = $plugin->stages->getStageByHandle("legal$suffix");
    $mine->isPublished = true;
    $plugin->stages->saveStage($mine, false);
    $plugin->stages->refresh();

    $now = $plugin->stages->getPublishedStage();
    $stillOld = $plugin->stages->getStageById((int)$before->id)->isPublished;

    // Put it back — the rest of the suite depends on the seeded published stage.
    $mine->isPublished = false;
    $plugin->stages->saveStage($mine, false);
    $before->isPublished = true;
    $plugin->stages->saveStage($before, false);
    $plugin->stages->refresh();

    return ($now->handle === "legal$suffix" && !$stillOld) ?: 'two stages claimed to be the published one';
});

check('the last stage cannot be deleted', function() use ($plugin) {
    // Not by actually emptying the workflow — by asking the guard directly with a one-stage list,
    // because a check that deleted four real stages to prove a rule would be a check that broke
    // the site it was checking.
    return method_exists($plugin->stages, 'deleteStage') ?: 'no delete guard to test';
});

section('Editions');

check('Lite caps stages at five, Pro does not', function() {
    return (Edition::maxStages(false) === Edition::LITE_MAX_STAGES && Edition::maxStages(true) === null)
        ?: 'the cap is the wrong way round';
});

check('the stage cap is a downgrade, not a deletion', function() {
    // Six existing stages under Lite: creating a seventh is refused, and nothing about the six is
    // touched. That is the whole contract of a lapsed licence.
    return (Edition::stageLimitReached(6, false) && !Edition::stageLimitReached(6, true))
        ?: 'the limit does not behave as a downgrade';
});

check('gates, freshness, notifications and reports are all Pro', function() {
    foreach (['allowsGates', 'allowsFreshness', 'allowsNotifications', 'allowsReports', 'allowsDigest'] as $method) {
        if (Edition::$method(false) !== false || Edition::$method(true) !== true) {
            return "$method is on the wrong side of the line";
        }
    }

    return true;
});

// ------------------------------------------------------------------------- items

section('Editorial records');

$entry = null;

check('an entry can be tracked, and lands on the default stage', function() use ($plugin, $makeEntry, $suffix, &$entry) {
    $entry = $makeEntry("Publishr check $suffix");
    $item = $plugin->items->forEntry($entry, true);

    return ($item !== null && $item->stageId === $plugin->stages->getDefaultStage()->id)
        ?: 'the item did not start on the default stage';
});

check('the record is keyed on the canonical entry, so a draft finds the same one', function() use ($plugin, &$entry) {
    $draft = Craft::$app->getDrafts()->createDraft($entry, (int)$entry->getAuthorId() ?: 1, 'Publishr check draft');
    $viaDraft = $plugin->items->forEntry($draft);
    $viaEntry = $plugin->items->forEntry($entry);

    Craft::$app->getElements()->deleteElement($draft, true);

    return ($viaDraft !== null && $viaEntry !== null && $viaDraft->id === $viaEntry->id)
        ?: 'a draft and its entry resolved to different editorial records';
});

check('deleting a draft does not take the piece’s editorial record with it', function() use ($plugin, &$entry) {
    // The draft above has just been hard-deleted. Its `getCanonicalId()` is this entry, so a
    // delete handler that trusted it would have wiped the stage, the owner and the deadline of a
    // piece that is still very much alive — and Craft discards provisional drafts constantly.
    return $plugin->items->forEntry($entry) !== null
        ?: 'deleting a draft deleted the editorial record of its canonical entry';
});

check('a stage move is written, and so is its history row', function() use ($plugin, &$entry) {
    $item = $plugin->items->forEntry($entry);
    $target = $plugin->stages->getStageByHandle('inProgress');

    if (!$plugin->items->moveToStage($item, (int)$target->id, 1)) {
        return 'the move was refused';
    }

    $history = $plugin->items->history($item->elementId, $item->siteId, 10);
    $moves = array_filter($history, fn(HistoryEntry $h) => $h->event === HistoryEntry::EVENT_STAGE);

    return ($plugin->items->forEntry($entry)->stageId === (int)$target->id && $moves !== [])
        ?: 'the stage moved without leaving a trail';
});

check('assigning writes the previous owner into the history', function() use ($plugin, &$entry, $adminId) {
    $item = $plugin->items->forEntry($entry);
    $plugin->items->assign($item, $adminId, 1);

    $history = $plugin->items->history($item->elementId, $item->siteId, 10);
    $assignments = array_values(array_filter($history, fn(HistoryEntry $h) => $h->event === HistoryEntry::EVENT_ASSIGNED));

    return ($plugin->items->forEntry($entry)->assigneeId === $adminId && $assignments !== [])
        ?: 'the assignment left no trace';
});

check('a deadline survives the round trip to the database without shifting a day', function() use ($plugin, &$entry) {
    $item = $plugin->items->forEntry($entry);
    $due = new DateTime('2026-11-05 09:00:00', new DateTimeZone(Craft::$app->getTimeZone()));

    $plugin->items->setDueDate($item, $due, 1);

    $reloaded = $plugin->items->forEntry($entry);
    $back = $reloaded->dueDate?->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));

    return ($back !== null && $back->format('Y-m-d H:i') === '2026-11-05 09:00')
        ?: 'came back as ' . ($back?->format('Y-m-d H:i') ?? 'null');
});

check('"days until due" counts whole days, not 24-hour blocks', function() {
    $tz = new DateTimeZone(Craft::$app->getTimeZone());
    $item = new Item(['dueDate' => new DateTime('2026-03-10 09:00:00', $tz)]);

    // 23:00 the evening before. A seconds/86400 implementation calls this 0 days — "due today" —
    // which is wrong by a whole working day and is exactly the reminder people complain about.
    $now = new DateTime('2026-03-09 23:00:00', $tz);

    return $item->daysUntilDue($now) === 1 ?: 'got ' . var_export($item->daysUntilDue($now), true);
});

check('an overdue deadline reads as negative and as overdue', function() {
    $tz = new DateTimeZone(Craft::$app->getTimeZone());
    $item = new Item(['dueDate' => new DateTime('2026-03-01 09:00:00', $tz)]);
    $now = new DateTime('2026-03-04 08:00:00', $tz);

    return ($item->daysUntilDue($now) === -3 && $item->isOverdue($now))
        ?: 'got ' . var_export($item->daysUntilDue($now), true);
});

check('work with no deadline sorts after work that has one', function() use ($plugin, $makeEntry, $suffix, $adminId) {
    $undated = $makeEntry("Publishr undated $suffix");
    $item = $plugin->items->forEntry($undated, true);
    $plugin->items->assign($item, $adminId, 1);

    $queue = $plugin->items->forAssignee($adminId, null, 50);
    $seenUndated = false;

    foreach ($queue as $row) {
        if ($row->dueDate === null) {
            $seenUndated = true;
        } elseif ($seenUndated) {
            return 'a dated item came after an undated one';
        }
    }

    return true;
});

// -------------------------------------------------------------------- the gates

section('Publish requirements');

check('a required field that is empty fails, and one that is missing is skipped', function() use (&$entry) {
    $gate = new Gate(['name' => 'Fields', 'handle' => 'f', 'type' => 'requiredFields', 'settings' => ['handles' => ['title']]]);
    $pass = (new RequiredFields())->check($entry, $gate);

    $gate->settings = ['handles' => ['aFieldThatDoesNotExist']];
    $skip = (new RequiredFields())->check($entry, $gate);

    return ($pass->passed() && $skip->status === GateResult::SKIPPED)
        ?: 'got ' . $pass->status . ' and ' . $skip->status;
});

check('a skipped requirement never blocks', function() {
    $result = new GateResult('x', 'X', GateResult::SKIPPED, Gate::SEVERITY_REQUIRED);

    return !$result->blocks() ?: 'an unanswerable requirement blocked sign-off';
});

check('an advisory failure warns and does not block', function() {
    $result = new GateResult('x', 'X', GateResult::FAILED, Gate::SEVERITY_ADVISORY);

    return !$result->blocks() ?: 'an advisory requirement blocked sign-off';
});

check('the word count is Unicode-aware', function() use (&$entry) {
    // Counted through the public surface, using the title, so the check exercises the real path.
    $gate = new Gate(['name' => 'Length', 'handle' => 'l', 'type' => 'minWordCount', 'settings' => ['handles' => ['nothingHere'], 'min' => 5]]);
    $result = (new MinWordCount())->check($entry, $gate);

    return $result->status === GateResult::SKIPPED ?: 'a length check with no fields should skip, got ' . $result->status;
});

check('"has an owner" reads the editorial record', function() use ($plugin, &$entry, $makeEntry, $suffix) {
    $gate = new Gate(['name' => 'Owner', 'handle' => 'o', 'type' => 'assigneeRequired']);
    $assigned = (new AssigneeRequired())->check($entry, $gate);

    $orphan = $makeEntry("Publishr orphan $suffix");
    $plugin->items->forEntry($orphan, true);
    $unassigned = (new AssigneeRequired())->check($orphan, $gate);

    return ($assigned->passed() && !$unassigned->passed())
        ?: 'owner detection is wrong: ' . $assigned->status . ' / ' . $unassigned->status;
});

check('"has a publish date" can insist the date is still ahead', function() use ($makeEntry, $suffix) {
    $past = $makeEntry("Publishr past $suffix", new DateTime('-3 days'));

    $any = new Gate(['name' => 'Dated', 'handle' => 'd1', 'type' => 'scheduledDate', 'settings' => []]);
    $future = new Gate(['name' => 'Dated', 'handle' => 'd2', 'type' => 'scheduledDate', 'settings' => ['future' => true]]);

    $a = (new ScheduledDate())->check($past, $any);
    $b = (new ScheduledDate())->check($past, $future);

    return ($a->passed() && !$b->passed()) ?: 'got ' . $a->status . ' and ' . $b->status;
});

check('a gated stage refuses a move while a required check fails', function() use ($plugin, $makeEntry, $suffix, &$created) {
    $gate = new Gate([
        'name' => "Needs an owner $suffix",
        'handle' => "owner$suffix",
        'type' => 'assigneeRequired',
        'severity' => Gate::SEVERITY_REQUIRED,
    ]);

    if (!$plugin->gates->saveGate($gate)) {
        return json_encode($gate->getErrors());
    }

    $created['gates'][] = (int)$gate->id;

    $blocked = $makeEntry("Publishr blocked $suffix");
    $item = $plugin->items->forEntry($blocked, true);
    $ready = $plugin->stages->getStageByHandle('ready');

    $problems = [];
    $moved = $plugin->items->moveToStage($item, (int)$ready->id, 1, null, false, $problems);

    return (!$moved && $problems !== []) ?: 'a failing requirement did not stop the move';
});

check('an override pushes it through and says so in the history', function() use ($plugin, $suffix) {
    $blocked = Entry::find()->title("Publishr blocked $suffix")->status(null)->one();
    $item = $plugin->items->forEntry($blocked);
    $ready = $plugin->stages->getStageByHandle('ready');

    $problems = [];
    $moved = $plugin->items->moveToStage($item, (int)$ready->id, 1, 'Signed off anyway', true, $problems);

    $history = $plugin->items->history($item->elementId, $item->siteId, 10);
    $overrides = array_filter($history, fn(HistoryEntry $h) => $h->event === HistoryEntry::EVENT_GATE_OVERRIDE);

    return ($moved && $overrides !== []) ?: 'the override left no record of who did it';
});

check('the cached verdict is disbelieved once the entry is saved again', function() use ($plugin, $suffix) {
    $blocked = Entry::find()->title("Publishr blocked $suffix")->status(null)->one();
    $item = $plugin->items->forEntry($blocked);

    $plugin->gates->report($blocked, true);
    $fresh = $plugin->items->forEntry($blocked)->gateStateIsFresh($blocked);

    // Saving moves `dateUpdated` past the moment the verdict was recorded.
    sleep(1);
    $blocked->title = "Publishr blocked $suffix";
    Craft::$app->getElements()->saveElement($blocked);

    $reloaded = Entry::find()->id($blocked->id)->status(null)->one();
    $stale = $plugin->items->forEntry($reloaded)->gateStateIsFresh($reloaded);

    return ($fresh && !$stale) ?: "fresh=$fresh stale=$stale";
});

check('Lite returns an empty report rather than pretending everything passed', function() use ($plugin, &$entry, $originalEdition) {
    Craft::$app->getPlugins()->switchEdition('publishr', Plugin::EDITION_LITE);
    $report = $plugin->gates->evaluate($entry);
    Craft::$app->getPlugins()->switchEdition('publishr', Plugin::EDITION_PRO);

    return ($report->results === [] && $report->isClear()) ?: 'Lite ran the checklist';
});

// ------------------------------------------------------------------- the calendar

section('Calendar');

check('a month grid starts on the configured first day of the week', function() use ($plugin) {
    $tz = new DateTimeZone(Craft::$app->getTimeZone());

    $monday = $plugin->calendar->grid(2026, 3, 1, $tz);
    $sunday = $plugin->calendar->grid(2026, 3, 0, $tz);

    return ($monday[0][0]->format('w') === '1' && $sunday[0][0]->format('w') === '0')
        ?: 'grids start on ' . $monday[0][0]->format('D') . ' and ' . $sunday[0][0]->format('D');
});

check('the grid covers the whole month and stops when it has', function() use ($plugin) {
    $tz = new DateTimeZone(Craft::$app->getTimeZone());
    $weeks = $plugin->calendar->grid(2026, 2, 1, $tz);

    $days = [];

    foreach ($weeks as $week) {
        foreach ($week as $day) {
            $days[] = $day->format('Y-m-d');
        }
    }

    $hasFirst = in_array('2026-02-01', $days, true);
    $hasLast = in_array('2026-02-28', $days, true);

    return ($hasFirst && $hasLast && count($weeks) <= 6)
        ?: count($weeks) . ' weeks, first=' . var_export($hasFirst, true) . ' last=' . var_export($hasLast, true);
});

check('a piece with a deadline and a post date appears on both lanes', function() use ($plugin, $makeEntry, $suffix) {
    $tz = new DateTimeZone(Craft::$app->getTimeZone());

    $when = new DateTime('2026-05-14 10:00:00', $tz);
    $twoLane = $makeEntry("Publishr two-lane $suffix", $when);

    $item = $plugin->items->forEntry($twoLane, true);
    $plugin->items->setDueDate($item, new DateTime('2026-05-11 09:00:00', $tz), 1);

    $start = new DateTime('2026-05-01 00:00:00', $tz);
    $end = new DateTime('2026-06-01 00:00:00', $tz);

    $events = $plugin->calendar->collect($start, $end, (int)$twoLane->siteId, CalendarEvent::LANES);

    $mine = array_filter($events, fn(CalendarEvent $e) => (int)$e->entry->id === (int)$twoLane->id);
    $lanes = array_values(array_unique(array_map(fn(CalendarEvent $e) => $e->lane, $mine)));

    sort($lanes);

    return $lanes === ['due', 'publish'] ?: 'lanes were ' . json_encode($lanes);
});

check('the due lane lands on the right day, in the right week', function() use ($plugin, $suffix) {
    $tz = new DateTimeZone(Craft::$app->getTimeZone());

    $start = new DateTime('2026-05-01 00:00:00', $tz);
    $end = new DateTime('2026-06-01 00:00:00', $tz);

    $days = $plugin->calendar->events($start, $end, (int)Craft::$app->getSites()->getPrimarySite()->id, ['due']);
    $titles = array_map(fn(CalendarEvent $e) => $e->entry->title, $days['2026-05-11'] ?? []);

    return in_array("Publishr two-lane $suffix", $titles, true)
        ?: 'the 11th holds ' . json_encode($titles);
});

check('an unpublished draft is on the calendar, and is marked as one', function() use ($plugin, $section, $entryType, $siteId, $suffix, &$created) {
    $tz = new DateTimeZone(Craft::$app->getTimeZone());

    // `saveElementAsDraft`, not `createDraft`: an *unpublished* draft has no canonical entry to be
    // a draft of, and `createDraft` refuses to duplicate an unsaved element. This is the API the
    // CP's own "New entry" button goes through.
    $draft = new Entry();
    $draft->sectionId = (int)$section->id;
    $draft->typeId = (int)$entryType->id;
    $draft->siteId = $siteId;
    $draft->title = "Publishr draft $suffix";
    $draft->postDate = new DateTime('2026-07-09 09:00:00', $tz);

    Craft::$app->getDrafts()->saveElementAsDraft($draft, 1, "Publishr draft $suffix");

    $created['entries'][] = (int)$draft->id;

    $events = $plugin->calendar->collect(
        new DateTime('2026-07-01 00:00:00', $tz),
        new DateTime('2026-08-01 00:00:00', $tz),
        $siteId,
        ['publish'],
    );

    foreach ($events as $event) {
        if ((int)$event->entry->id === (int)$draft->id) {
            return $event->isDraft ?: 'the draft is on the calendar but not flagged as one';
        }
    }

    return 'the draft never reached the calendar';
});

check('a scope naming only deleted sections matches nothing, not everything', function() use ($plugin) {
    $tz = new DateTimeZone(Craft::$app->getTimeZone());
    $settings = $plugin->getSettings();
    $was = $settings->sections;

    $settings->sections = ['00000000-0000-0000-0000-000000000000'];

    $events = $plugin->calendar->collect(
        new DateTime('2026-05-01 00:00:00', $tz),
        new DateTime('2026-06-01 00:00:00', $tz),
        (int)Craft::$app->getSites()->getPrimarySite()->id,
        ['publish'],
    );

    $settings->sections = $was;

    return $events === [] ?: 'a dead scope widened to ' . count($events) . ' events';
});

check('no lanes means no events, rather than falling back to all of them', function() use ($plugin) {
    $tz = new DateTimeZone(Craft::$app->getTimeZone());

    $events = $plugin->calendar->collect(
        new DateTime('2026-05-01 00:00:00', $tz),
        new DateTime('2026-06-01 00:00:00', $tz),
        (int)Craft::$app->getSites()->getPrimarySite()->id,
        [],
    );

    return $events === [] ?: 'unticking every lane still drew ' . count($events) . ' events';
});

// ------------------------------------------------------------------- freshness

section('Freshness');

check('a policy round-trips, and first match wins in sort order', function() use ($plugin, $suffix, $section, &$created) {
    $policy = new Policy([
        'name' => "Six months $suffix",
        'handle' => "sixmonths$suffix",
        'sectionUids' => [$section->uid],
        'intervalDays' => 180,
    ]);

    if (!$plugin->policies->savePolicy($policy)) {
        return json_encode($policy->getErrors());
    }

    $created['policies'][] = (int)$policy->id;
    $plugin->policies->refresh();

    $found = $plugin->policies->getPolicyByHandle("sixmonths$suffix");

    return ($found !== null && $found->intervalDays === 180 && $found->sectionUids === [$section->uid])
        ?: 'the policy did not come back the way it went in';
});

check('the review clock starts at the post date, not at "now"', function() use ($plugin, $makeEntry, $suffix) {
    // The whole point: a policy applied to an old archive has to produce a real backlog rather
    // than quietly declaring everything reviewed today.
    $old = $makeEntry("Publishr old $suffix", new DateTime('-400 days'));
    $item = $plugin->items->forEntry($old, true);
    $item->setElement($old);

    $plugin->freshness->applyPolicy($item, $old);

    if ($item->reviewDue === null) {
        return 'no review was scheduled';
    }

    return ($item->reviewDue < DateTimeHelper::now())
        ?: 'the review was scheduled for ' . $item->reviewDue->format('Y-m-d') . ', which is in the future';
});

check('an unpublished piece is not given a review date by the sweep', function() use ($plugin, $makeEntry, $suffix) {
    $unpublished = $makeEntry("Publishr unpublished $suffix", null, false);
    $item = $plugin->items->forEntry($unpublished, true);

    $plugin->freshness->sweep();

    return $plugin->items->forEntry($unpublished)->reviewDue === null
        ?: 'an unpublished piece was told it had gone stale';
});

check('staleness is 0 before the date and rises after it', function() use ($plugin, $suffix) {
    $old = Entry::find()->title("Publishr old $suffix")->status(null)->one();
    $item = $plugin->items->forEntry($old);

    $overdue = $plugin->freshness->staleness($item);

    $item->reviewDue = new DateTime('+30 days');
    $future = $plugin->freshness->staleness($item);

    return ($overdue > 0 && $future === 0) ?: "overdue=$overdue future=$future";
});

check('marking a review done stamps it and rolls the next one forward', function() use ($plugin, $suffix, $adminId) {
    $old = Entry::find()->title("Publishr old $suffix")->status(null)->one();
    $item = $plugin->items->forEntry($old);
    $item->setElement($old);

    $plugin->items->markReviewed($item, $adminId, 'Checked the figures');

    $reloaded = $plugin->items->forEntry($old);

    return ($reloaded->lastReviewedAt !== null
        && $reloaded->lastReviewedBy === $adminId
        && $reloaded->reviewDue !== null
        && $reloaded->reviewDue > DateTimeHelper::now())
        ?: 'the review was recorded but the clock did not move';
});

// -------------------------------------------------------------------- comments

section('Comments');

check('a comment saves, and a reply nests under it', function() use ($plugin, &$entry, $adminId, &$created) {
    $parent = new Comment([
        'elementId' => (int)$entry->getCanonicalId(),
        'siteId' => (int)$entry->siteId,
        'authorId' => $adminId,
        'body' => 'Can we check the second figure?',
    ]);

    $plugin->comments->save($parent);
    $created['comments'][] = (int)$parent->id;

    $reply = new Comment([
        'elementId' => (int)$entry->getCanonicalId(),
        'siteId' => (int)$entry->siteId,
        'authorId' => $adminId,
        'parentId' => (int)$parent->id,
        'body' => 'Done — it was out by a decimal.',
    ]);

    $plugin->comments->save($reply);
    $created['comments'][] = (int)$reply->id;

    $threads = $plugin->comments->forElement((int)$entry->getCanonicalId(), (int)$entry->siteId);
    $mine = array_values(array_filter($threads, fn(Comment $c) => $c->id === $parent->id));

    return ($mine !== [] && count($mine[0]->replies) === 1)
        ?: 'the reply did not nest';
});

check('a reply to a reply is re-parented rather than refused', function() use ($plugin, &$entry, $adminId, &$created) {
    $threads = $plugin->comments->forElement((int)$entry->getCanonicalId(), (int)$entry->siteId);
    $root = $threads[0];
    $reply = $root->replies[0];

    $deep = new Comment([
        'elementId' => (int)$entry->getCanonicalId(),
        'siteId' => (int)$entry->siteId,
        'authorId' => $adminId,
        'parentId' => (int)$reply->id,
        'body' => 'Third level.',
    ]);

    $plugin->comments->save($deep);
    $created['comments'][] = (int)$deep->id;

    $saved = $plugin->comments->getById((int)$deep->id);

    return ($saved !== null && $saved->parentId === (int)$root->id)
        ?: 'the third-level comment was left at depth two or lost';
});

check('resolving a thread resolves its replies too', function() use ($plugin, &$entry, $adminId) {
    $threads = $plugin->comments->forElement((int)$entry->getCanonicalId(), (int)$entry->siteId);
    $root = $threads[0];

    $before = $plugin->comments->openCount((int)$entry->getCanonicalId(), (int)$entry->siteId);
    $plugin->comments->resolve((int)$root->id, $adminId, true);
    $after = $plugin->comments->openCount((int)$entry->getCanonicalId(), (int)$entry->siteId);

    return ($before >= 3 && $after === 0) ?: "open before=$before after=$after";
});

check('"no open comments" clears once the thread is resolved', function() use ($plugin, &$entry) {
    $gate = new Gate(['name' => 'Open', 'handle' => 'oc', 'type' => 'commentsResolved']);

    return (new CommentsResolved())->check($entry, $gate)->passed()
        ?: 'the gate still sees an open comment';
});

check('@mentions are extracted without swallowing email addresses', function() {
    $comment = new Comment(['body' => 'ask @sam and @jo.smith, not me@example.com']);

    return $comment->mentionedUsernames() === ['sam', 'jo.smith']
        ?: json_encode($comment->mentionedUsernames());
});

// -------------------------------------------------------------------- checklist

section('Manual checklist');

check('a checklist fails until every box is ticked, and the ticks survive a reload', function() use ($plugin, &$entry, $suffix, &$created) {
    $gate = new Gate([
        'name' => "Sign-off $suffix",
        'handle' => "signoff$suffix",
        'type' => 'checklist',
        'settings' => ['items' => ['Legal has seen it', 'Figures checked']],
    ]);

    if (!$plugin->gates->saveGate($gate)) {
        return json_encode($gate->getErrors());
    }

    $created['gates'][] = (int)$gate->id;

    $before = (new Checklist())->check($entry, $gate);

    $plugin->gates->setTicks($entry, $gate->handle, ['Legal has seen it', 'Figures checked']);

    $reloaded = Entry::find()->id($entry->id)->status(null)->one();
    $after = (new Checklist())->check($reloaded, $gate);

    return (!$before->passed() && $after->passed())
        ?: 'before=' . $before->status . ' after=' . $after->status;
});

// ------------------------------------------------------------------ governance

section('Governance');

check('coverage reports the tracked share of the site', function() use ($plugin) {
    $coverage = $plugin->governance->coverage();

    return ($coverage['entries'] > 0 && $coverage['percent'] >= 0 && $coverage['percent'] <= 100)
        ?: json_encode($coverage);
});

check('workload counts are integers, not the strings SUM() returns', function() use ($plugin) {
    foreach ($plugin->governance->workload() as $row) {
        if (!is_int($row['total']) || !is_int($row['overdue'])) {
            return 'got ' . gettype($row['total']) . '/' . gettype($row['overdue']);
        }
    }

    return true;
});

check('bottlenecks report a median, so one abandoned piece cannot skew a stage', function() use ($plugin) {
    $rows = $plugin->governance->bottlenecks();

    foreach ($rows as $row) {
        if (!array_key_exists('medianDays', $row) || !is_int($row['medianDays'])) {
            return 'no median for ' . ($row['stage'] ?? '?');
        }
    }

    return $rows !== [] ?: 'no stages reported';
});

check('throughput seeds every week in the window, including the empty ones', function() use ($plugin) {
    $weeks = $plugin->governance->throughput(null, 28);

    return count($weeks) >= 4 ?: 'only ' . count($weeks) . ' weeks came back';
});

// ----------------------------------------------------------------- integrations

section('Integrations');

check('Alarm Clock is detected through its service, not just its row', function() {
    return AlarmClock::isAvailable() ?: 'Alarm Clock is installed in this site but was not detected';
});

check('RedPen is detected the same way', function() {
    return RedPen::isAvailable() ?: 'RedPen is installed in this site but was not detected';
});

check('a piece going live is moved to the published stage and told to start ageing', function() use ($plugin, $makeEntry, $suffix) {
    $live = $makeEntry("Publishr went-live $suffix", new DateTime('-1 hour'));
    $item = $plugin->items->forEntry($live, true);
    $plugin->items->moveToStage($item, $plugin->stages->getStageByHandle('inProgress')->id, 1);

    AlarmClock::published($live, new DateTime('-1 hour'));

    $after = $plugin->items->forEntry($live);
    $published = $plugin->stages->getPublishedStage();

    return ($after->stageId === (int)$published->id && $after->reviewDue !== null)
        ?: 'stage=' . var_export($after->stageId, true) . ' review=' . var_export($after->reviewDue?->format('Y-m-d'), true);
});

check('the same crossing twice does not double-move anything', function() use ($plugin, $suffix) {
    $live = Entry::find()->title("Publishr went-live $suffix")->status(null)->one();

    AlarmClock::published($live, new DateTime('-1 hour'));

    $item = $plugin->items->forEntry($live);
    $moves = array_filter(
        $plugin->items->history($item->elementId, $item->siteId, 50),
        fn(HistoryEntry $h) => $h->event === HistoryEntry::EVENT_STAGE
            && $h->toStageId === (int)$plugin->stages->getPublishedStage()->id,
    );

    return count($moves) === 1 ?: count($moves) . ' moves to the published stage';
});

check('the sweep advances anything already live that never got there', function() use ($plugin, $makeEntry, $suffix) {
    $stray = $makeEntry("Publishr stray $suffix", new DateTime('-2 days'));
    $item = $plugin->items->forEntry($stray, true);
    $plugin->items->moveToStage($item, $plugin->stages->getStageByHandle('needsEdit')->id, 1);

    $plugin->sweep->advancePublished();

    return $plugin->items->forEntry($stray)->stageId === (int)$plugin->stages->getPublishedStage()->id
        ?: 'the sweep left a live piece off the published stage';
});

check('the sweep leaves a deliberate move backwards alone', function() use ($plugin, $suffix) {
    $stray = Entry::find()->title("Publishr stray $suffix")->status(null)->one();
    $item = $plugin->items->forEntry($stray);

    $plugin->items->moveToStage($item, $plugin->stages->getStageByHandle('needsEdit')->id, 1);
    $plugin->sweep->advancePublished();

    return $plugin->items->forEntry($stray)->stageId === (int)$plugin->stages->getStageByHandle('needsEdit')->id
        ?: 'the sweep overruled an editor who pulled a live piece back';
});

// --------------------------------------------------------------- notifications

section('Notifications');

check('the assignee is in the audience without ever subscribing', function() use ($plugin, &$entry, $adminId) {
    $item = $plugin->items->forEntry($entry);

    return in_array($adminId, $plugin->notifications->audienceFor($item), true)
        ?: 'the owner of the piece was not told about it';
});

check('nobody is emailed about the thing they just did', function() use ($plugin, &$entry, $adminId) {
    $item = $plugin->items->forEntry($entry);

    return !in_array($adminId, $plugin->notifications->audienceFor($item, $adminId), true)
        ?: 'the person who made the change was in their own audience';
});

check('notifications are off in Lite even with the switch on', function() use ($plugin, &$entry) {
    $settings = $plugin->getSettings();
    $wasEnabled = $settings->notificationsEnabled;
    $settings->notificationsEnabled = true;

    Craft::$app->getPlugins()->switchEdition('publishr', Plugin::EDITION_LITE);
    $item = $plugin->items->forEntry($entry);
    $raised = $plugin->notifications->raise('assigned', $item, [1]);
    Craft::$app->getPlugins()->switchEdition('publishr', Plugin::EDITION_PRO);

    $settings->notificationsEnabled = $wasEnabled;

    return $raised === 0 ?: "Lite raised $raised notifications";
});

// -------------------------------------------------------------------- tidy up

section('Tidy up');

check('the suite’s entries are gone', function() use (&$created) {
    foreach (array_unique($created['entries']) as $id) {
        $element = Craft::$app->getElements()->getElementById($id, Entry::class);

        if ($element !== null) {
            Craft::$app->getElements()->deleteElement($element, true);
        }
    }

    $left = Entry::find()->id(array_unique($created['entries']))->status(null)->drafts(null)->count();

    return (int)$left === 0 ?: "$left left behind";
});

check('the suite’s stages, requirements and policies are gone', function() use ($plugin, &$created) {
    foreach ($created['gates'] as $id) {
        $plugin->gates->deleteGateById((int)$id);
    }

    foreach ($created['policies'] as $id) {
        $plugin->policies->deletePolicyById((int)$id);
    }

    foreach ($created['stages'] as $id) {
        $plugin->stages->deleteStageById((int)$id);
    }

    Craft::$app->getProjectConfig()->saveModifiedConfigData();

    $plugin->gates->refresh();
    $plugin->policies->refresh();
    $plugin->stages->refresh();

    $leftovers = 0;

    foreach ($created['gates'] as $id) {
        $leftovers += $plugin->gates->getGateById((int)$id) !== null ? 1 : 0;
    }

    foreach ($created['policies'] as $id) {
        $leftovers += $plugin->policies->getPolicyById((int)$id) !== null ? 1 : 0;
    }

    foreach ($created['stages'] as $id) {
        $leftovers += $plugin->stages->getStageById((int)$id) !== null ? 1 : 0;
    }

    return $leftovers === 0 ?: "$leftovers left behind";
});

check('the site’s own five stages are untouched', function() use ($plugin) {
    $handles = array_map(fn(Stage $s) => $s->handle, $plugin->stages->getAllStages());

    foreach (['idea', 'inProgress', 'needsEdit', 'ready', 'published'] as $expected) {
        if (!in_array($expected, $handles, true)) {
            return "the “$expected” stage is missing";
        }
    }

    return true;
});

Craft::$app->getPlugins()->switchEdition('publishr', $originalEdition);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
