---
title: Notifications and the digest
---

# Notifications and the digest

*Pro only.*

## What gets sent

| Event | Goes to |
|---|---|
| Assigned to me | the new owner |
| Moved to another stage | owner and subscribers |
| Due soon | owner and subscribers |
| Overdue | owner and subscribers |
| Due a freshness review | owner and subscribers |
| Went live | owner and subscribers |
| Expired | owner and subscribers |
| New comment | owner and subscribers |
| Mentioned in a comment | the person named |

**Nobody is emailed about the thing they just did.** Assigning something to yourself does not need
an email about it.

## Who counts as an audience

The **assignee**, always, without ever subscribing to anything. That is not a subscription, it is
the job.

Everybody else because they said so: following one piece, or watching a whole section. A managing
editor watching a section usually wants "went live", not "somebody moved a card", so a subscription
can name which events it wants.

## A row first, an email second

Every intended message is a durable row before it is a message, with its own attempt count and
error.

Queueing a mail directly would make *"did the reminder go out, and can I send it again"*
unanswerable — and that is the first question anybody asks when a deadline is missed. A row makes it
a question with an answer and a button. **Settings → Notifications** lists anything that failed,
with a **Try again** next to it.

Sending goes through Craft's queue by default: a stage move that waits on an SMTP handshake feels
broken, and a mail server having a bad afternoon should not make the control panel unusable.

## Sent once a day, not once ever

Reminders carry a deduplication key that changes daily, under a unique index.

So "this is due tomorrow" is sent once on the day it is true — and again the next day if it is still
true, rather than once ever, which would mean a reminder lost to a broken mail server is lost for
good.

The unique index is doing real work: the sweep can run from cron, from the queue and from a
control-panel request, and all three can fire in the same minute. Checking whether a reminder was
already sent and *then* inserting cannot be made safe in application code. Making the insert the
thing that has to be won means the losers are told so by the database instead of by three identical
emails.

## The digest

One mail a morning with the state of somebody's desk: what is late, what is coming up in the next
week, and what is due a freshness review.

**Only to people who have something in it.** A daily email that says "nothing to report" is an email
people filter — and once it is filtered, the day it matters is filtered too.

It goes out when the sweep runs with `--digest=1`, so put that on a once-a-morning cron entry
separate from the frequent one:

```
*/10 * * * *  cd /path/to/site && php craft publishr/sweep
0    8 * * *  cd /path/to/site && php craft publishr/sweep --digest=1
```

## Turning it down

- **Settings → Notifications → Send notifications** switches the lot off.
- **Warn this many days before a deadline** sets the due-soon window.
- Publishr never emails somebody about a piece already on the published stage. It has met its
  deadline; chasing it is noise.
