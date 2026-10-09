---
title: Stages and assignments
slug: stages
order: 30
summary: 'Editorial stages beside Craft''s status, plus assignments and deadlines.'
---

# Stages and assignments

## A stage is not a status

Craft already has a status — live, pending, expired, disabled — and it is derived in SQL from
`enabled` and the dates. There is no seam to extend it, and anything that tried would be fighting
every element query on the site.

So Publishr runs a **second, parallel axis**. Craft answers "is this public". Publishr answers
"where is this in the process". The pair is the truth, and the useful consequence is that Publishr
cannot break your front end: turn the plugin off and every entry is exactly as public as it was.

The two axes touch in exactly one place — the **published stage** — and that is set by Alarm Clock
noticing a piece actually went live, not by somebody remembering to drag a card.

## The five you start with

| Stage | What it means |
|---|---|
| **Idea** | Commissioned or suggested. Nobody is writing it yet. *(where new work starts)* |
| **In progress** | Being written right now. |
| **Needs edit** | Written, waiting on an editor. |
| **Ready** | Signed off. Waiting on its post date. *(gated)* |
| **Published** | Live. Moved here automatically. *(the published stage)* |

Rename them, recolour them, reorder them, replace them. "Ready" is gated by default because it is
the stage that means *an editor has signed this off*, which is exactly the moment a checklist is
worth having.

## The three flags

**Where new work starts.** One stage, and it is where a piece lands the first time Publishr takes an
interest in it.

**This is the published stage.** One stage. Pieces are moved here automatically when they go live —
by Alarm Clock within a minute, or by the sweep. Setting it on a second stage clears the first;
two stages both claiming to be "published" is not a state anybody means.

**Check publish requirements before anything moves here.** Turns the stage into a sign-off gate. See
[Publish requirements](requirements.md).

## Deleting a stage

Pieces sitting on a deleted stage keep their history and lose their position. They read as untracked,
which is honest — sweeping them onto another stage would rewrite a decision somebody made, and the
history would then disagree with the column.

The last stage cannot be deleted. Work needs somewhere to sit.

## The stage cap

Publishr Lite holds **five** stages — exactly the ones the installer creates. So Lite has a working
workflow it can rename, recolour and reorder; what it cannot do is model an eleven-step approval
chain, which is the shape of organisation buying governance software anyway.

The cap is a **downgrade, not a deletion**. A site whose licence lapses keeps every stage it has and
every piece on them. What Lite stops is creating the sixth.

## Assignments

Who owns this piece *now*. Deliberately separate from the entry's author: the person who wrote it in
March is rarely the person fixing it in October, and a calendar that conflates the two cannot show
anybody their actual workload.

An assignee is in the audience for everything that happens to their piece without ever subscribing
to anything. That is not a subscription, it is the job.

Assigning needs the *Assign work to other people* permission. Setting a deadline needs *Move work
between stages and set deadlines*.

## Deadlines

**When the work is due** — deliberately not the post date, which is when the work goes *out*. On
most desks those are days apart, and a calendar that only knows the second one is a calendar the
writers ignore.

Days are counted at **whole-day granularity in the site's time zone**, so "due tomorrow" means
tomorrow's date. A deadline at nine tomorrow morning does not read as "0 days" all afternoon today.

A piece that reaches the published stage has met its deadline, and Publishr clears it — leaving it
set would make a finished piece read as overdue forever. That can be switched off in
**Settings → General**.

## In the entries index

You do not have to open Publishr to see where things stand. Craft's own **Entries** screen gets:

**Columns** — pick them from the index's *Customize* menu (and, on Craft 5.5+, as card attributes
in an entry type's card designer):

| Column | Shows |
|---|---|
| **Editorial stage** | The stage, with its colour |
| **Assignee** | Who owns the piece, or *Unassigned* |
| **Due** | The deadline, marked *Overdue* in red once the due day has passed |

An entry Publishr is not tracking shows a dash. The cells are empty for anybody without *See the
editorial calendar*. A draft row shows its piece's stage, because the editorial record belongs to the
canonical entry.

**Condition rules** — in the index's filter bar, in custom sources (*Customize sources* at the foot of the
index sidebar), and anywhere else Craft builds an entry condition, such as an Entries field's selectable
entries:

| Rule | Matches |
|---|---|
| **Editorial stage** is / is not / is empty / has a value | Entries on the chosen stages. *Is empty* includes entries Publishr has never tracked |
| **Assigned to me** | Entries owned by whoever is looking. One shared "My work" source is right for every editor. With nobody logged in (a console command) it matches nothing |
| **Overdue** | The due day has passed and the piece is not on the published stage — the same rule as the navigation badge and My desk |

Stages are stored in a saved source by UID, so a source built locally still points at the right stage
after a project-config deploy. A rule naming only stages that have since been deleted matches
nothing rather than everything.

All of this is in Lite.

## History

Every stage move, assignment, deadline change, review and override is written as its own row at the
moment it happens, and it is never edited.

A `stageId` column records where a piece is. It cannot record who moved it there, when, or what it
was before — and "who signed this off, and when" is precisely the question a governance tool exists
to answer. It cannot be reconstructed from `dateUpdated` afterwards, so it is written down at the
time.

See it per piece in the entry sidebar, or site-wide at **Publishr → Overview → Activity**.
