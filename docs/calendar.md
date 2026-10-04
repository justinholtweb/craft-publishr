---
title: The calendar
slug: calendar
order: 20
summary: 'The month grid, its four lanes, and what a drag actually changes.'
---

# The calendar

**Publishr → Calendar.**

A month grid of everything in flight. Two decisions in it are worth explaining, because they are
what makes it usable by the person doing the writing rather than only by the person counting the
output.

## An entry is not an event

A piece of content has up to four editorially interesting dates:

| Lane | The date | Where it lives |
|---|---|---|
| **Due** | when the *work* is due | Publishr |
| **Publishing** | when it goes out | the entry's post date |
| **Expiring** | when it comes down | the entry's expiry date |
| **Review** | when somebody should look at it again | Publishr |

A month view that can only show one of them has to pick — which is why every editorial calendar
built on `postDate` alone is useless to a writer. Their deadlines are not post dates.

So each piece emits a pip **per date it has**, on its own lane, and you choose which lanes to see
with the toggles above the grid. The same piece can legitimately appear three times in one month:
due on the 4th, out on the 8th, and up for review in November. That is not a bug, it is the point.

Unticking every lane shows nothing, which is a legitimate thing to ask for.

## Drafts are on it

The pieces that matter most on a Monday morning are the ones that do not exist yet as public
entries. Craft's own date queries exclude drafts by default, which is exactly backwards for this
screen, so Publishr asks for them explicitly. An unpublished draft is drawn in italics.

Saved revisions are *not* on it — an entry's twelve revisions are not twelve things happening on
Thursday.

## Colour

A pip takes the colour of its **stage**, not its lane, because the question a glance at a calendar
answers on a working desk is "how much of next week is still unwritten", not "which lane is this".
Pieces Publishr has no record of fall back to a lane colour.

A pip goes red when it is late: on the publish lane that means the post date has passed and the
entry still is not public.

## Dragging

Drag a pip to another day and **the date that moves is the one the lane represents**. The same
piece dragged in the due lane moves its deadline; dragged in the publish lane it moves its post
date. A calendar that guessed would be wrong about half the time.

The existing time of day is kept. Dragging a post from Tuesday to Thursday is a statement about the
day, not an instruction to republish it at nine in the morning.

Dragging needs the *Move work between stages and set deadlines* permission, and moving a post date
also needs permission to edit that entry — Publishr never edits an entry somebody could not have
edited themselves.

## Filters

Stage, section, owner and a search box, all in the URL — so a filtered calendar is a link you can
send somebody. **Anybody → Me** is the fastest route to your own week.

## Settings that change it

In **Settings → General**:

- **Sections** — which sections Publishr manages at all. Worth setting on a site whose entries are
  mostly not editorial; a store with 40,000 products does not want them on a content calendar, and
  the queries get cheaper the moment Publishr knows that.
- **Lanes shown by default** — what somebody sees the first time they open it.
- **Week starts on** — Craft has no site-wide setting for this, so Publishr needs its own.
- **Most pips in a day** — beyond this a cell says "+ 6 more".

## The board

**Publishr → Board** is the same rows arranged by stage instead of by date. The calendar answers
*when*; the board answers *how far along*. Neither is a mode of the other, because the two questions
get asked by different people — a board is what an editor stands in front of on a Monday, and a
calendar is what a marketing manager checks before promising a client a date.

Columns are capped at 60 cards. A "Published" column on a site with 9,000 articles is not a column
anybody scrolls.
