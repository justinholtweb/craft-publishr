---
title: FAQ
slug: faq
order: 130
summary: 'Short answers to the questions people ask before installing.'
---

# Frequently asked questions

**Does Publishr change how my site publishes?**

No. An editorial stage is metadata beside an entry, never a replacement for Craft's status. Turn the
plugin off and every entry is exactly as public as it was. The one exception is opt-in and clearly
labelled: **Refuse to publish an entry whose requirements fail**, off by default.

**Do I need Alarm Clock?**

No. With it, a piece moves to the published stage within a minute of going live. Without it, the
sweep catches up. See [Alarm Clock and RedPen](integrations.md).

**Do I need RedPen?**

Only for the "Passes RedPen" requirement, which reports itself as unavailable and is skipped when
RedPen is not installed. Every other requirement type works on its own.

**Why is my calendar empty?**

Publishr tracks entries as they are saved, so a fresh install shows nothing until something is
edited. Run `php craft publishr/track` to bring the content you already have onto it.

**Why does the same piece appear three times in one month?**

Because it is due on the 4th, out on the 8th, and up for review in November. An entry has several
editorially interesting dates and each gets its own lane. Untick the lanes you do not want.

**Why did dragging a pip move the wrong date?**

It moved the date its lane represents. Dragged in the *due* lane it moves the deadline; in the
*publish* lane it moves the post date. If a piece is showing on two lanes, drag the one you mean.

**Can a piece be on the calendar without a post date?**

Yes, and this is the common case for work in progress. It appears on the due lane, and on the board.

**What happens when somebody deletes an entry?**

Its editorial record, comments and subscriptions go with it. The **history does not** — "we published
this and then deleted it" is exactly what an audit trail is for.

**What happens to a draft?**

Nothing. The editorial record belongs to the canonical entry, so the stage, owner and deadline survive
every draft created, applied and thrown away underneath them. Deleting a draft never touches them.

**Somebody overrode a requirement. Can I find out who?**

Yes. An override writes a history row naming the person and the requirements they overruled. The
entry sidebar shows it, and so does **Overview → Activity**.

**Why is the checklist green on a piece somebody just gutted?**

It should not be. A cached verdict is trusted only while it is at or after the entry's own
`dateUpdated`. If you are seeing this, press **Check again** and tell us what the dates were.

**What does it cost?**

Lite is $59 and Pro is $129, per production site. Lite is the calendar, the board, stages,
assignments and history; Pro adds publish requirements, freshness reviews, notifications and the
report. See [Installation](installation.md#editions) for the full table.

**My licence lapsed. Did I lose my stages?**

No. The stage cap is a downgrade, not a deletion — you keep every stage you have and every piece
sitting on them. Lite stops you creating the sixth. The same goes for requirements and policies: they
stop being enforced, they are not deleted, and they start working again the moment the licence is
renewed.

**Does Publishr send anything anywhere?**

Only email, through Craft's own mailer, to users of your site. It makes no outbound requests of any
kind.

**Can I add a requirement of my own?**

Yes — implement `GateTypeInterface` and register it on `Gates::EVENT_REGISTER_GATE_TYPES`. That is
the same door the RedPen adapter uses. See [Alarm Clock and RedPen](integrations.md).

**Multi-site?**

Every editorial record is per site, so a piece can be at different stages in different sites — which
is usually right, because translating it is separate work. Use the site switcher on every screen.
