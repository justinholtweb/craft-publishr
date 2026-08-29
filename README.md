# Publishr

**Editorial calendar and content governance for Craft CMS 5.**

Craft knows when an entry was published. It does not know who is writing the next one, when it is
due, who has to sign it off, or that the pricing page has not been checked since 2021. Publishr
holds that second set of facts alongside the content, and puts them on a calendar.

It is the Craft answer to PublishPress: a month view of everything in flight, editorial stages of
your own, assignments, deadlines, publish requirements, and freshness reviews.

---

## What it does

**A calendar that shows the work, not just the output.** Four lanes — when a piece is *due*, when it
*publishes*, when it *expires*, and when it is next due a *review* — because an editorial calendar
built on `postDate` alone is useless to the person doing the writing. Their deadlines are not post
dates. Drafts that have never been published are on it too, which is most of what matters on a
Monday morning. Drag a pip to another day and the right date moves: the deadline in the due lane,
the post date in the publish lane.

**Editorial stages, parallel to Craft's own status.** Idea → In progress → Needs edit → Ready →
Published, or whatever your desk actually calls them. A stage is *not* a Craft status and never
becomes one: Craft answers "is this public", Publishr answers "where is this in the process". The
useful consequence is that Publishr cannot break your front end — turn the plugin off and every
entry is exactly as public as it was.

**Assignments and deadlines.** Who owns this, and when is it due. Separate from the entry's author,
because the person who wrote it in March is rarely the person fixing it in October.

**Editorial comments.** A threaded conversation about a piece, stored beside it rather than in a
field on it — so it never renders on the site, never lands in a revision, and survives the draft it
was written against being applied and thrown away. `@name` tells somebody.

**Publish requirements** *(Pro)*. A checklist a piece must satisfy before it can reach a gated
stage: fields that must be filled, a lead image with alt text, a minimum length, no open comments,
a post date, boxes a person ticks for the rules no software can check — and, if you have RedPen,
a clean pass against your house style guide. Required requirements block sign-off; advisory ones
warn. Somebody with the right permission can override, and their name goes in the history.

**Freshness reviews** *(Pro)*. Nothing on a website tells you it has gone out of date. A policy says
how long a kind of content stays good for, and the clock runs from the last *human* review — or
from the post date when there has never been one, so the first sweep produces the real backlog
rather than quietly declaring the archive fresh.

**Notifications and a digest** *(Pro)*. Assigned to you, due soon, overdue, review due, went live,
somebody commented. One mail a morning with the state of your desk — sent only to people who have
something in it.

**A governance report** *(Pro)*. How much of the site is on the calendar at all, where work is
piling up (median age per stage, not mean — every desk has one piece abandoned for eight months),
who is carrying what, what is late, what is unowned, what has gone stale, and how much actually
shipped per week.

---

## Alarm Clock is the engine

Publishr owns no scheduler, and that is deliberate.

Craft derives an entry's status in SQL from its dates, so an entry goes live the instant the clock
passes its post date — with no save, no event, and nothing for a plugin to hang itself on. That is
the problem [Alarm Clock](https://github.com/justinholtweb/craft-alarmclock) already solves properly:
a ledger, a watermark, three triggers and a unique index that makes the race safe. An editorial
calendar rebuilding a worse version of that inside itself would be the wrong plugin doing the wrong
job.

So Alarm Clock notices, and Publishr reacts. When a piece goes live it moves to the published stage
within a minute, its met deadline is cleared, and the freshness clock starts **from the moment it
actually went out**.

Without Alarm Clock installed everything still works — the sweep catches up instead. The stage still
advances; it just advances the next time cron, the queue or garbage collection runs.

## RedPen is the QA

Publishr does not check prose. [RedPen](https://github.com/justinholtweb/craft-redpen) does, and
duplicating a rule engine so that two plugins could disagree about whether "utilise" is acceptable
would be the worst possible outcome for somebody who owns both. The **Passes RedPen** requirement is
a thin adapter: it asks RedPen for a verdict and turns it into a pass or a fail.

Not installed? The requirement reports itself as unavailable and is skipped. It never fails a piece
because it could not read it — "I don't know" is not "no", and treating it as one would lock a desk
out of sign-off by uninstalling an optional dependency.

---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

Optional, and each makes Publishr better without being needed:

- **Alarm Clock 5.0+** — stages advance the moment content goes live, rather than at the next sweep
- **RedPen 5.0+** — the "Passes RedPen" publish requirement

## Installation

```sh
composer require justinholtweb/craft-publishr
php craft plugin/install publishr
```

Then bring the content you already have onto the calendar:

```sh
php craft publishr/track --section=news
```

Live entries land on the published stage, unpublished ones on the default stage — dropping three
years of published articles onto "Idea" would produce a board claiming the whole archive is
unwritten.

## Keeping it running

One command on cron. Everything it does is idempotent, so running it every ten minutes and running
it once a day are both correct; the difference is only how promptly a reminder arrives.

```
*/10 * * * *  cd /path/to/site && php craft publishr/sweep
0    8 * * *  cd /path/to/site && php craft publishr/sweep --digest=1
```

No cron? Publishr queues the same sweep from Craft's garbage collection, so it still runs — just
less often.

## Editions

**Lite plans the work. Pro governs it.**

| | Lite | Pro |
|---|---|---|
| Month calendar, all four lanes | ✓ | ✓ |
| Board and overview | ✓ | ✓ |
| Editorial stages | up to 5 | unlimited |
| Assignments and deadlines | ✓ | ✓ |
| Editorial comments | ✓ | ✓ |
| Stage history and activity log | ✓ | ✓ |
| Publish requirements | | ✓ |
| Freshness reviews and policies | | ✓ |
| Notifications and daily digest | | ✓ |
| Governance report and CSV export | | ✓ |
| Section subscriptions | | ✓ |

Neither list is capped by volume. Charging per entry on a content calendar is charging a publisher
for publishing.

The stage cap is a **downgrade, not a deletion**: a site whose licence lapses keeps every stage it
has and every piece sitting on them. What Lite stops is creating the sixth.

## Templating

```twig
{% set item = craft.publishr.item(entry) %}

{% if item %}
    {{ item.getStage().name }}
    {{ item.getAssignee().friendlyName ?? 'Unassigned' }}
    {% if item.isOverdue() %}{{ item.daysUntilDue()|abs }} days late{% endif %}
{% endif %}

{# A staging-only banner nobody has to remember to check #}
{% if craft.app.config.general.devMode and craft.publishr.staleness(entry) > 60 %}
    <p>This page has not been reviewed since {{ item.lastReviewedAt|date('F Y') }}.</p>
{% endif %}

{# Next month's plan, on an internal page #}
{% for day, events in craft.publishr.month(2026, 6, ['publish']) %}
    <h3>{{ day|date('j F') }}</h3>
    {% for event in events %}<p>{{ event.entry.title }}</p>{% endfor %}
{% endfor %}
```

Everything on `craft.publishr` is read-only. Anything that changes something goes through a
controller with a permission check on it — a template is not the place from which a piece gets
signed off.

## Console

```sh
php craft publishr/sweep                 # advance, schedule, remind, send
php craft publishr/sweep --digest=1      # …and send the daily digest
php craft publishr/sweep/status          # what the desk looks like right now
php craft publishr/track                 # backfill editorial records
php craft publishr/track --dryRun=1      # …without writing anything
```

## Documentation

Full documentation is in [`docs/`](docs/), and on
[justinholt.com/plugins/craft-publishr](https://justinholt.com/plugins/craft-publishr/docs).

## Licence

See [LICENSE.md](LICENSE.md).
