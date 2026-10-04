---
title: Console commands
slug: console
order: 110
summary: 'The sweep, the digest, status, and tracking existing entries.'
---

# Console commands

## `publishr/sweep`

The one command a site needs on cron.

```sh
php craft publishr/sweep
php craft publishr/sweep --digest=1
```

In order:

1. **Advance published pieces.** Anything live but not on the published stage, moved forward.
2. **Schedule freshness reviews** for anything published that has none.
3. **Raise reminders** — due soon, overdue, review due.
4. **Send** whatever is waiting.
5. **Prune** history and sent notifications past the retention window.

Catching up the published stage comes first on purpose: everything after it reads the stage column,
and a piece that went live last night should not be chased for a deadline it has already met.

Every step is idempotent, and every reminder carries a day-scoped deduplication key — so running it
every ten minutes and running it once a day are both correct. The difference is only how promptly a
reminder arrives.

```
*/10 * * * *  cd /path/to/site && php craft publishr/sweep
0    8 * * *  cd /path/to/site && php craft publishr/sweep --digest=1
```

Give `--digest=1` to the once-a-morning entry, not the frequent one.

**No cron?** Publishr queues the same sweep from Craft's garbage collection. It runs less often, but
it runs.

## `publishr/sweep/digest`

Sends the digest on its own, whatever the schedule says. Useful for checking it looks right before
committing a cron entry.

## `publishr/sweep/status`

What the desk looks like, changing nothing:

```
Publishr
  edition            Pro
  stages             5
  tracked items      412
  overdue            7
  unassigned         19
  reviews scheduled  388
  reviews due        23
  alarm clock        yes
  redpen             yes
```

The last two lines are worth a glance after any deploy — they say which integrations this
environment actually has, which is not always the same as which the developer thinks it has.

## `publishr/track`

Backfills editorial records for content that predates the plugin.

```sh
php craft publishr/track
php craft publishr/track --section=news
php craft publishr/track --dryRun=1
php craft publishr/track --limit=5000
```

| Option | |
|---|---|
| `--section` | one section handle |
| `--dryRun` | print what would happen and write nothing |
| `--limit` | most entries to touch in one run (default 1000) |

Entries already live land on the **published** stage; everything else on the default stage. Dropping
three years of published articles onto "Idea" would produce a board claiming the whole archive is
unwritten.

Safe to run repeatedly — anything already tracked is skipped, so a large site can be brought on in
several passes.
