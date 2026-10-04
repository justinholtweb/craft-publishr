---
title: Installation
slug: installation
order: 10
summary: 'Requirements, editions, install, and bringing existing entries onto the calendar.'
---

# Installation

```sh
composer require justinholtweb/craft-publishr
php craft plugin/install publishr
```

Publishr needs Craft CMS 5.3 or later and PHP 8.2 or later. It has no runtime dependencies.

## Editions

Both editions are paid. **Lite is $59** and **Pro is $129**.

Lite plans the work: the calendar, the board, stages, assignments, deadlines, comments and history.
Pro governs it: publish requirements, freshness reviews, notifications and the governance report.
Neither is capped by volume, and Lite's only limit is five editorial stages.

| | Lite | Pro |
| --- | --- | --- |
| Month calendar, all four lanes | ✓ | ✓ |
| Board and overview | ✓ | ✓ |
| Editorial stages | up to 5 | unlimited |
| Assignments, deadlines, comments, history | ✓ | ✓ |
| Publish requirements | | ✓ |
| Freshness reviews and policies | | ✓ |
| Notifications and daily digest | | ✓ |
| Governance report and CSV export | | ✓ |
| Section subscriptions | | ✓ |

## What installing does

Five editorial stages are created — Idea, In progress, Needs edit, Ready and Published — so the
board works before you have opened the settings screen. They are ordinary stages: rename them,
recolour them, reorder them, or replace them entirely.

Nothing else happens. No entries are touched, and nothing about how your site publishes changes.

## Bringing your content onto the calendar

Publishr is almost always installed on a site with several years of entries already on it, and a
calendar that starts empty is a calendar that stays empty.

```sh
php craft publishr/track
php craft publishr/track --section=news
php craft publishr/track --dryRun=1
```

Entries that are already live land on the **published** stage; everything else lands on the default
stage. Dropping three years of published articles onto "Idea" would produce a board claiming the
whole archive is unwritten.

From then on, new entries are tracked automatically as they are saved. That can be switched off in
**Settings → General** if you would rather add pieces to the calendar deliberately.

## Keeping it running

One command on cron:

```
*/10 * * * *  cd /path/to/site && php craft publishr/sweep
0    8 * * *  cd /path/to/site && php craft publishr/sweep --digest=1
```

Everything the sweep does is idempotent and every reminder it raises carries a day-scoped
deduplication key, so running it every ten minutes and running it once a day are both correct. The
difference is only how promptly a reminder arrives.

**No cron?** Publishr queues the same sweep from Craft's garbage collection. It still runs, just
less often — which is the difference between a reminder arriving late and never arriving at all.

## Two plugins that make it better

Neither is required, and Publishr works completely without both.

- **[Alarm Clock](https://justinholt.com/plugins/craft-alarmclock)** — pieces move to the published
  stage within a minute of going live, instead of at the next sweep. See
  [Alarm Clock and RedPen](integrations.md).
- **[RedPen](https://craft-redpen.com)** — adds a "Passes RedPen" publish
  requirement, so a piece cannot be signed off while it breaks your house style guide.

## Uninstalling

```sh
php craft plugin/uninstall publishr
```

Every Publishr table is dropped, including the history. **Your content is untouched** — Publishr
never owns an entry, only a record beside it, so uninstalling leaves every entry exactly as public
as it was.
