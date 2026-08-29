---
title: The governance report
---

# The governance report

*Pro only.* **Publishr → Report.**

Every number here answers a question a person actually asks out loud. The ones that are *not* here
are as much of the design as the ones that are.

## Coverage, first

**How much of the site is on the calendar at all.**

It is first because a governance report has to be honest about it: a workflow covering 6% of a site's
content is not a workflow, and every other figure on the page is measuring that 6%. Below 30% on a
site of any size, the report says so and points at `publishr/track`.

## Where work is sitting

Per stage: how many pieces, how many overdue, and the **typical age** of a piece currently on it.

Typical age is a **median, not a mean**, and that is the whole point. One piece abandoned on "Needs
edit" for eight months drags a mean into meaninglessness, and every desk has one of those. The median
says what happens to a normal piece — which is the number you can act on.

## Who is carrying what

Pieces per person, and how many of theirs are late. The row for "Nobody" is usually the interesting
one.

## What is late, unowned, and stale

Three short lists, each capped for the screen. The CSV export is not capped.

A piece already on the published stage never appears as late. It has met its deadline.

## Throughput

Pieces that reached the published stage, by week.

Counted from the **history**, not from `postDate`. Post dates are editable and routinely backdated —
a piece imported from an old site, a piece re-dated to sit at the top of a listing — so a chart built
on them shows a burst of activity in 2019 that never happened. The history row records when the desk
actually finished the work.

Weeks with nothing in them are drawn as empty columns rather than closed up, so a quiet fortnight
looks like a quiet fortnight instead of making the desk look busier than it was.

## What is deliberately missing

**Average time to publish.** Dominated by the two pieces that sat in a drawer for a year. It tells
you nothing about the desk, and it is the number people quote when they have not looked at the
distribution.

**Words published this month.** Rewards the wrong thing, and everybody knows it within a week.

**Anything per-person that looks like a productivity score.** Workload is here because somebody has
to spot that one person is carrying nineteen pieces. It is not here so that it can be compared.

## Export

**Export CSV** gives one row per tracked piece — title, section, stage, owner, deadline, Craft status,
review date, staleness and URL. One row per piece rather than a rendering of the summary boxes,
because the reason anybody exports a governance report is to sort it their own way in a spreadsheet.
Handing them the aggregates back would be handing them the one thing they can already see.
