---
title: Publish requirements
---

# Publish requirements

*Pro only.*

A requirement is a question with a yes/no answer about a piece of content. Does it have a lead
image. Is the meta description filled in. Has legal seen it. Does the copy pass the house style
guide.

Requirements are checked when a piece moves **into a gated stage** — the sign-off moment — rather
than when it is saved. That distinction is the whole design: Craft can already mark a field required
in a field layout, and that is a *save* requirement, which stops a writer saving a half-finished
draft. Insisting on a meta description at that moment is insisting on it at the wrong moment.

## Two severities

**Required** blocks the move. Somebody holding *Sign off despite failing requirements* can push it
through anyway, and doing so is written into the history with their name and the requirements they
overruled.

**Advisory** never blocks anything. It shows as a warning in the entry sidebar and counts in the
report.

There is no third severity that silently rewrites content. A governance tool that edits the copy is
a governance tool nobody trusts.

## The requirement types

| Type | Checks |
|---|---|
| **Required fields** | Named fields are not empty. Native attributes (`title`, `slug`, `postDate`) work too. |
| **Minimum length** | The named fields add up to at least *n* words. |
| **Related content** | A relation field holds at least *n* things, optionally all with alt text. |
| **Has a publish date** | The entry has a post date, optionally one still in the future. |
| **Has an owner** | The piece is assigned to somebody. |
| **No open comments** | Every editorial comment has been resolved. |
| **Manual checklist** | Boxes a person ticks. For the rules no software can check. |
| **Passes RedPen** | The copy is clean against your RedPen style guide. |

Each is scoped by section and by stage. Leave both empty and it applies everywhere, on every gated
stage.

### Some notes that save an afternoon

**Required fields skips what it cannot find.** A handle the entry's layout does not have is *skipped*,
not failed — so one requirement can cover several entry types without failing every piece that
sensibly lacks the field.

**Related content counts, it does not test truthiness.** A relation field is never empty in the
sense that matters: it holds a query object, which is always truthy. Counting is the only honest
answer.

**Minimum length is a blunt instrument on purpose.** It is not a quality measure and does not pretend
to be. It catches the specific failure of a placeholder going live, which is the most common thing a
desk publishes by accident.

**Manual checklist ticks are cleared when the entry is edited.** A sign-off is about a specific
version of the text; letting it survive a rewrite is worse than not having one.

## "I don't know" is not "no"

A requirement that cannot run reports itself as **skipped**, and a skipped requirement never blocks.

That matters most for the RedPen requirement. RedPen not installed, RedPen's backend timed out, a
profile handle that no longer resolves — none of those mean the copy has been found wanting. They
mean it has not been read. Treating them as failures would mean uninstalling an optional dependency
locks a whole desk out of sign-off.

The same applies to a requirement that throws: a broken requirement is a broken requirement, not a
failing piece of content. Somebody's custom gate having a typo in it should not become everybody
else's worst day.

## Caching

Verdicts are cached on the editorial record so that listing 200 pieces does not run 200 checklists.
The cache is trusted only while it is **at or after the entry's own `dateUpdated`**: any verdict
recorded before the last edit is about a version of the text that no longer exists, and a green tick
against prose somebody has since gutted is worse than no tick at all.

By default the checklist re-runs whenever a managed entry is saved, which keeps the sidebar honest
without anybody pressing anything. **Check again** in the sidebar forces it.

## The publish guard

**Settings → General → Refuse to publish an entry whose requirements fail.**

Off by default, and the default matters. Blocking a stage move is a conversation between colleagues.
Blocking a save is a wall between somebody and their work.

Sites that need the wall — regulated copy, medical claims, anything with a legal review — turn it on
knowingly. Even then it is narrow: it bites only when a save would make an entry genuinely
**public**, never on a draft, never on a disabled entry, never on one with a future post date, and
never for somebody holding the override permission. And if the checklist itself throws, the save
goes through — a broken requirement must not become an unpublishable site.
