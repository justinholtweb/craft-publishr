---
title: Freshness reviews
---

# Freshness reviews

*Pro only.*

## The problem

**Nothing on a website tells you it has gone out of date.**

A pricing page from 2021 renders exactly as confidently as one written this morning. The only signal
a CMS offers is `dateUpdated`, which records when somebody last *touched* the file — a typo fix, a
re-save during a migration — not when somebody last decided the content was still true. Those are
different facts, and conflating them is why "we'll audit the site annually" never survives its
second year.

So Publishr stores the second fact explicitly. `lastReviewedAt` is set only by a person pressing a
button that says they have read it. Everything else is arithmetic on that date.

## Policies

**Settings → Freshness.** A policy says how long a kind of content stays good for.

- **Sections and entry types** it covers. Both empty means everything.
- **Good for this many days.**
- **Warn this many days early.**
- **Who reviews it** — whoever already owns it, the entry's author, one named person, or nobody.

**First match wins, in the order you drag them.** Not "most specific", which sounds better and is
worse: specificity has no total ordering once a policy can name both sections and entry types, so
two overlapping policies would give a stable-looking answer that changes when somebody adds a third.
An explicit order is a rule an editor can predict.

A policy only ever fills in an *empty* owner. It never takes a live piece away from the person still
working on it because a rule said so months later.

## Where the clock starts

From `lastReviewedAt` when there is one, and **from the post date when there is not** — never from
"now".

That is the decision that makes the feature worth having. A policy applied to a ten-year archive
that reset everything to "reviewed today" would report a perfectly clean site and hide precisely the
backlog it was bought to find. Starting from the post date means the first sweep produces the real,
uncomfortable number.

Only *published* content gets a review date. A draft that has never gone out has no shelf life to
have run out.

## The sweep

`php craft publishr/sweep` gives every managed, published piece with no review date one.

It is bounded — 500 per run by default — because a policy applied to a long archive matches thousands
of entries at once and doing them all in one request is a timeout. The next sweep picks up where this
one stopped, because the condition it selects on ("review date is null") is self-advancing.

## Staleness

A number from 0 to 100, where 100 is "overdue by a full interval or more".

A number rather than a flag because a backlog has to be sortable: *"47 pages are overdue"* is a fact
nobody can act on, and *"these 12 are the worst"* is a morning's work. **Publishr → Reviews** is
sorted worst-first for the same reason.

## Marking something reviewed

**Mark reviewed** on the reviews screen, or in the entry sidebar. It stamps who and when, rolls the
next review forward by the policy's interval, and writes a history row.

It records that a person *looked* at the page — not that they changed it. A page that is still
correct after five years is a page that has been reviewed, and forcing an edit to clear a review
would teach everybody to make a pointless one.

## Seeing it on the front end

```twig
{% if craft.publishr.staleness(entry) > 60 %}
    <p class="banner">Last reviewed {{ craft.publishr.item(entry).lastReviewedAt|date('F Y') }}.</p>
{% endif %}
```

Wrap it in a `devMode` check to keep it to staging, or show it to readers deliberately — a visible
"reviewed March 2026" is worth more trust than a hidden one.
