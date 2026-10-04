---
title: Troubleshooting
slug: troubleshooting
order: 120
summary: 'An empty calendar, notifications that never arrive, failing requirements, and other first checks.'
---

# Troubleshooting

## The calendar is empty

Run `php craft publishr/sweep/status`. If **tracked items** is 0, nothing has been added to the
calendar yet — `php craft publishr/track` backfills it.

If items are tracked but the calendar is still empty, check:

- **the lane toggles** — unticking all of them shows nothing, which is a legitimate thing to ask for;
- **the month** — pips sit on their dates, and a desk with nothing scheduled shows nothing;
- **Settings → General → Sections** — a scope naming only sections that have since been deleted
  matches nothing, deliberately, rather than silently widening to the whole site.

## Pieces are not moving to the published stage

`php craft publishr/sweep/status` shows whether Alarm Clock is installed.

**With Alarm Clock:** the move happens on its tick. Check `php craft alarm-clock/tick/status`.

**Without it:** the move happens on Publishr's sweep, so check the sweep is running — cron, or Craft's
garbage collection.

Either way, **Settings → General → Move to the published stage when a piece goes live** has to be on,
and one stage has to be marked as the published stage in **Settings → Stages**.

The sweep only ever moves work **forward**, and only for a piece that has never been recorded as
published. A piece deliberately pulled back to "Needs edit" while still live stays where you put it —
otherwise the sweep would overrule you every ten minutes.

## Notifications are not arriving

In order:

1. **Settings → Notifications** — is sending on, and is this a Pro licence?
2. The same screen lists **messages that didn't go out**, with the error and a **Try again**.
3. Is the queue running? Notifications go through it by default.
4. Is the sweep running? Due-soon and overdue reminders are raised by the sweep, not by a save.
5. Does the recipient have an email address, and is the piece assigned to them or are they
   subscribed?

Publishr never emails somebody about a piece already on the published stage, and never about a change
they made themselves.

## A requirement is failing and I cannot see why

Open the piece and look at the sidebar: each requirement shows its own message, which is written as
the thing to do rather than as a code.

A requirement showing a faint dot is **skipped**, not failed, and never blocks. That means it could
not run — a field handle that is not on this entry, RedPen not installed, a broken custom gate. The
message says which.

**Check again** re-runs the checklist. If the sidebar disagrees with what you are looking at, that is
the cached verdict, and it should have been invalidated by the save — press it.

## A requirement is failing and it should not be

- **Required fields:** a relation field is never empty in the truthy sense — it holds a query — so
  Publishr counts. Check the field actually has something related in *this site*.
- **Minimum length:** it counts the fields you named. A body in a Matrix field does not stringify to
  text and counts as zero.
- **No open comments:** resolving a thread resolves its replies; resolving a single reply does not
  resolve the thread.
- **Manual checklist:** ticks are cleared when the entry is saved. That is deliberate — a sign-off is
  about a specific version of the text.

## An entry will not save

If the error mentions Publishr, **Settings → General → Refuse to publish an entry whose requirements
fail** is on, and a required requirement is not met. It only applies when a save would make an entry
genuinely public.

Somebody with *Sign off despite failing requirements* can save it. Otherwise, meet the requirement,
or save the entry disabled or with a future post date and come back to it.

## Deadlines are showing on the wrong day

Deadlines are stored in UTC and rendered in the site's time zone. If they are consistently a day out,
check **Settings → General → Time Zone** in Craft against the server's — a mismatch of a few hours
moves a 9am deadline across midnight.

## The reviews screen is empty

`php craft publishr/sweep/status` shows **reviews scheduled**. If it is 0:

- is there a policy in **Settings → Freshness**, and does it cover the section?
- is this a Pro licence?
- has the sweep run? Reviews are scheduled 500 at a time, so a long archive takes a few passes.

Only **published** content gets a review date. A draft that has never gone out has no shelf life to
have run out.
