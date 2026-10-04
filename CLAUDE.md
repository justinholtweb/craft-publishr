# Publishr — Craft CMS 5 Plugin

## Project Overview

Publishr is the editorial calendar and content governance layer for Craft CMS: a month view of
everything in flight, editorial stages, assignments, deadlines, publish requirements and freshness
reviews. Distributed as `justinholtweb/craft-publishr`. **Lite $59 / Pro $129**, and the split is *Lite plans
the work, Pro governs it*. In the spirit of WordPress's PublishPress, but the Craft problem is a
different problem — see below.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No build step, no runtime dependencies. The CP script in `src/web/assets/cp/dist` is a plain IIFE.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\publishr`
- Package: `justinholtweb/craft-publishr`
- Handle: `publishr` — one word, so namespace, handle, translation category, template root and
  console command are all the same string. No kebab-case split to remember.

### The load-bearing idea: an editorial stage is a second axis, never a replacement

Craft derives an entry's status in SQL from `enabled` and the dates. There is no seam to extend it,
and anything that tried would be fighting every element query on the site. So Publishr runs a
**parallel axis**: Craft answers "is this public", Publishr answers "where is this in the process".

The useful consequence is that Publishr **cannot break a front end**. Uninstall it and every entry
is exactly as public as it was. That is also why the one opt-in feature that *can* stop a publish
(`blockPublish`) is off by default, narrow, and overridable.

The two axes touch in exactly one place — the **published stage** — and that is set by Alarm Clock
noticing a crossing, not by anybody remembering to drag a card.

### Everything keys on the canonical element ID

`publishr_items` is unique on `(elementId, siteId)` where `elementId` is `getCanonicalId()`, never a
draft's own ID. A piece being written *is* a draft, and Craft creates and destroys provisional
drafts constantly; keying on the draft would scatter one piece's stage across a dozen rows and lose
all of them when the draft was applied.

This is also why `EVENT_AFTER_DELETE` must skip drafts and revisions — see the traps below.

### An entry is not an event

The calendar's load-bearing decision. A piece has up to four editorially interesting dates (due,
publish, expire, review), and a grid that can show only one has to pick — which is why every
editorial calendar built on `postDate` alone is useless to a writer. So the query runs **per lane**
and one piece legitimately appears on several days. The drag payload carries the lane, because which
date a drop writes depends entirely on it.

Publish/expire are read off entries with `status(null)->drafts(null)->revisions(false)`; due/review
are read off `publishr_items` and then hydrated, because on a site with 40,000 entries and 60
deadlines the other way round is 40,000 rows to find 60.

### Alarm Clock is the engine, RedPen is the QA

**Publishr owns no scheduler.** Craft fires no event when the clock passes a post date; Alarm Clock
solves that once, properly, with a ledger and a unique index. Publishr subscribes to
`Ticker::EVENT_AFTER_TRANSITION` and reacts: advance the stage, clear the met deadline, start the
freshness clock **from the scheduled instant** rather than from whenever a sweep ran. Without Alarm
Clock, `Sweep::advancePublished()` does the same job less promptly.

**Publishr does not check prose.** `gates\QualityReview` is a thin adapter over RedPen's
`Review::reviewElement()`.

Both adapters (`src/integrations/`) resolve their plugin by **string class name at runtime** and
never `use` a class from it — a hard reference makes the autoloader look for a class that is not
installed, which is a fatal error on the *entry editor*. Both check for the **service**
(`$plugin->has('review')`), not just the plugin row.

### "I don't know" is never "no"

A gate that cannot run returns `SKIPPED`, and `GateResult::blocks()` returns false for it. A gate
that *throws* is caught and also becomes `SKIPPED`. Otherwise uninstalling an optional dependency,
or one typo in somebody's custom gate, locks a whole desk out of sign-off.

### Transitions vs. facts

`publishr_history` is append-only and separate from `publishr_items`, with **no FK on elementId**.
A `stageId` column says where a piece is; it cannot say who moved it, when, or from what — and that
is the question a governance tool exists to answer. The missing FK is deliberate: "we published this
and then deleted it" is exactly what an audit trail is for.

`publishr_notifications` is a row first and an email second, with a uniquely-indexed `dedupeKey`
that changes daily. Three triggers (cron, queue, CP request) can fire in the same minute;
check-then-insert cannot be made safe in PHP, so the insert is the thing that has to be won.

## Traps found while building this

- **`EVENT_AFTER_DELETE` fires for drafts, and a draft's `getCanonicalId()` is the entry it came
  from.** A delete handler that trusts it deletes the editorial record of a piece that is very much
  alive — every time Craft discards a provisional draft, which is constantly. The check suite found
  this by deleting a draft and watching the item vanish. Skip drafts and revisions.
- **Twig cannot call a static method on a class-name string.** `{{ class.displayName() }}` over a
  `['handle' => FQCN]` map throws *"Impossible to invoke a method on a string variable"* at **render**
  time, so the settings screen 500s while every unit test passes. `Gates::describeTypes()` flattens
  them to arrays for templates.
- **`craft\base\Component` inherits `yii\base\Model::toArray()`,** so a private helper named
  `toArray()` on a *service* is a fatal compile error ("access level must be public"). Named
  `decodeArray()` here.
- **A Craft plugin's services are service-locator components, not properties.** `hasProperty('review')`
  is false for every service every plugin has — a check that always fails and never looks like it
  should. Use `$plugin->has('review')`.
- **`DateTimeHelper::class . '::toDateTime'` is not a validator.** Yii tries to instantiate it as a
  class and dies with `NotInstantiableException` on the first `validate()`. Use
  `craft\validators\DateTimeValidator`.
- **Yii quotes `orderBy` array *keys* as column names**, so `['[[dueDate]] IS NULL' => SORT_ASC]`
  becomes a backtick-quoted identifier and a syntax error. Nulls-last has to be an `Expression` in
  the *value*: `['x' => new Expression('CASE WHEN [[dueDate]] IS NULL THEN 1 ELSE 0 END')]`. It
  matters because plain `ORDER BY dueDate` puts NULLs *first* on MySQL — opening everybody's queue
  on the least urgent things on the desk.
- **`Db::prepareDateForDb()` output must never go to an element query *date param*** — it is read as
  system time and converted to UTC a second time, so the window matches nothing. Element queries take
  `['and', '>= ' . $d->format(DATE_ATOM), '< ' . …]`; raw column conditions take the prepared string.
  (Family-wide; see `[[craft-abacus-gotchas]]`.)
- **The same trap in reverse when reading.** A bare `Y-m-d H:i:s` from a column is UTC, but
  `new DateTime()` reads it in the *site's* zone. Every read goes through
  `DateTimeHelper::toDateTime($value, false, false)`.
- **Yii's query builder already JSON-encodes an array bound to a `json` column.** Encoding it
  yourself stores the JSON *of* a JSON string. Pass arrays; decode-until-container on the way back.
  (Family-wide; see `[[craft-schedulr-gotchas]]`.)
- **`SUM()` returns a string, and `SUM()` over an empty group returns NULL.** Both cast in
  `Governance::workload()`.
- **An empty `section()` argument is ignored by the query builder,** so a scope naming only sections
  that have since been deleted would silently widen the calendar to the whole site. It passes a
  sentinel handle instead.
- **A ternary cannot have an assignment in a branch** — `$ok ? $done++ : $skipped[] = $x` parses as
  `(...) = $x`. Use an `if`.
- **Craft saves plugin settings as one project-config blob,** so a settings screen that posts only
  its own fields and assigns wholesale resets every setting on the other screens.
  `SettingsController::actionSave()` merges onto the current model.
- **Never nest a `<form>` in a CP template.** The entry-editor sidebar renders inside Craft's own
  form; the parser drops the tag and keeps the children, leaving a second `action` input, and Craft
  takes the last one. (Family-wide; see `[[craft-plugin-gotchas]]`.)
- **Never mark plugin settings `required`** — a fresh install cannot then save any of them.
- **`afterInstall()`, not the migration, seeds project config.** Writes from a migration are buffered
  and can land before the plugin's own row exists; the change vanishes and the call still returns
  true. It also returns early on `getIsApplyingExternalChanges()`, or every environment gets
  duplicate stages with different UIDs.

- **Never `setElement(null)` because the entry is a draft.** `setElement()` marks the element
  *loaded*, so `getElement()` then returns null — and a gated move with a null element used to skip
  its requirements. A brand-new piece *is* an unpublished draft, so every new piece was signed off
  unchecked. Leave a draft's element unloaded; it lazy-loads `elementId`. A gated move with no
  resolvable entry is refused (fail closed) — "I don't know" is never "no" for one *gate*, but a
  missing entry is not a gate.
- **Publish/expire pips carry the element's own ID; due/review pips carry the canonical ID.** The
  former write `postDate`/`expiryDate` on that element. Dragging a draft's pip with the canonical
  ID moved the *live* entry's post date into the future and took it off the site.
- **A custom event with an `isValid` contract must extend `craft\events\CancelableEvent`.** On a
  plain `yii\base\Event`, reading `$event->isValid` throws `UnknownPropertyException` — every
  stage move would have died the moment anybody attached a listener.
- **Uninstall must remove `publishr` from project config, with `muteEvents` on.** Craft only clears
  `plugins.publishr`; the rest resurrects on reinstall as duplicate stages. The `onRemove` handlers
  delete rows from tables `safeDown()` has already dropped, hence the mute (`Plugin::afterUninstall()`).
- **Project config stores the policy reviewer as a user UID**, never an ID — IDs differ per
  environment, and a missing one was an FK failure that aborted the whole apply.
- **Soft deletes keep the item.** The delete handler acts only on `$entry->hardDelete`; a restored
  entry gets its stage, owner and deadline back. The FK cascades on the real delete.
- **Every Publishr read and write also requires Craft's `canView` on the entry**, and an editable
  site. Publishr permissions govern Publishr data, never more than Craft would show the person.
  Calendar/board/overview filter per entry; the calendar does it in the controller because the
  service also feeds front-end Twig.
- **`sendPending()` holds a mutex** (`publishr:send-notifications`). The dedupe key protects the
  insert only; cron, the queue and "Try again" draining the same rows double-sent.

See also `[[craft-plugin-gotchas]]`, `[[craft-abacus-gotchas]]` and `[[craft-schedulr-gotchas]]`.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-publishr/tests/integration/checks.php   # 71 checks
ddev exec bash /var/www/craft-publishr/tests/integration/cp-smoke.sh # 22 CP screens + the Lite boundary
ddev exec bash -c 'find /var/www/craft-publishr/src -name "*.php" -print0 | xargs -0 -n1 php -l'
ddev exec php craft publishr/sweep/status
```

Static analysis runs in the phpstan-runner (PHP 8.4, `~/Sites` at `/sites`), never in the harness:

```sh
docker exec -w /sites/craft-publishr ddev-phpstan-runner-web composer phpstan    # level 5, clean
docker exec -w /sites/craft-publishr ddev-phpstan-runner-web composer check-cs
```

The `ignoreErrors` in `phpstan.neon` are three known false-positive classes (ActiveRecord row typing,
`craft\mail\Message::setTo(User)`, and the string-resolved integrations). Anything else is real —
the first run found the uncancellable `StageChangeEvent`.

`checks.php` is idempotent and self-cleaning: it creates its own stage, entries, requirements,
policies and comments, deletes them, and puts the edition back where it found it.

`cp-smoke.sh` is the other half — a template referencing a variable the controller does not pass
fails at *render* time and no service test finds it. It logs in with **`php craft users/impersonate`**
rather than by posting credentials: two dozen authenticated requests an hour trips Garrison's
brute-force lockout on the shared harness, and the refusal comes back as a generic *"invalid username
or password"*, which sends you hunting a password problem that does not exist.

**The harness is currently unstable** — the `plugin-testing` web container is being stopped and
recreated by something outside the session (`ddev start` reports `chown: cannot access
'/mnt/ddev-global-cache/global-commands'`, and runs die with exit 137 mid-request). Both suites have
run fully green; re-runs need `ddev start` first and sometimes twice.

`ddev exec php craft clear-caches/cp-resources` after editing anything under `src/web/assets/*/dist`,
or Craft keeps serving the published copy.

## Icons, docs, promos and the marketing site

The icon follows the family shape: a 100×100 viewBox, a rounded tile at `rx="22.44"` filled with the
plugin's accent, and the mark in `#FEFEFE`. The accent is **`#4A4FA8`** — an editorial indigo, kept
clear of Showtime's plum and Telescope's navy. The tile colour, `accentColor` in the page seed and
the promo palette are all the same value; keep them that way.

The glyph is one `fill-rule="evenodd"` path — the unmarked days are outlines punched as holes, not
`opacity` — and `src/icon-mask.svg` is that path copied across unchanged, so the mask is a solid
silhouette. The binding rings stop at the body's top edge (`y=27`): any overlap is XOR'd out by
evenodd and shows as a notch.

`docs/*.md` is the **source of truth** for the marketing site's documentation. Each file needs YAML
front matter with at least a `title`; a file without it is skipped. Changing a doc means re-syncing:

```sh
cd ~/Sites/justinholt
ddev exec php craft pluginsite/docs/sync craft-publishr
ddev exec php scripts/check-plugin-sites.php
```

The marketing page itself is `scripts/seed/plugin-pages/craft-publishr.json` in that repo. The full
procedure and its traps live in the `plugin-marketing-site` skill there — use it rather than working
from memory. `promos/` is not built yet.

## Coding conventions

- `Craft::t('publishr', '…')` for user-facing strings
- Business logic in services; controllers stay thin
- A gate that cannot answer returns `SKIPPED` — never a failure
- Every date read from a column goes through `DateTimeHelper::toDateTime($v, false, false)`
- Never nest a `<form>` in a CP template
- Never mark plugin settings `required`
