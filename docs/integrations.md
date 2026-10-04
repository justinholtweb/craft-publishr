---
title: Alarm Clock and RedPen
slug: integrations
order: 80
summary: 'How Alarm Clock and RedPen plug in, and what happens without them.'
---

# Alarm Clock and RedPen

Publishr works completely without either. Each makes it better, and neither is a dependency.

## Alarm Clock is the engine

**Publishr owns no scheduler**, and that is the reason these are two plugins.

Craft derives an entry's status in SQL from its dates, so an entry becomes live the instant the clock
passes its post date — with no save, no event, and nothing at all for a plugin to hang itself on.
Nothing is *triggered*; the answer to "is this live" simply changes.

That is the problem [Alarm Clock](https://justinholt.com/plugins/craft-alarmclock) solves once and
properly: a ledger, a watermark, three independent triggers, and a unique index that makes the race
between them safe. An editorial calendar rebuilding a worse version of that inside itself would be
the wrong plugin doing the wrong job.

So the arrangement is: **Alarm Clock notices, Publishr reacts.** When a piece goes live:

- it moves to the published stage, within about a minute;
- the deadline it has just met is cleared;
- the freshness clock starts **from the moment it actually went out**, not from whenever a sweep next
  ran. Over years, a clock started at "when the sweep ran" drifts steadily away from the truth;
- a history row records the publication;
- the owner and any subscribers are told.

The move to the published stage is **forced past the checklist**, deliberately. A gated "Published"
stage would otherwise mean an entry *already live on the website* could not be recorded as published
— a calendar lying about the state of the world to protect a checklist.

### Without it

Everything above still happens; it happens at the next sweep instead of within the minute. The sweep
finds anything that is live but not on the published stage and advances it.

It only ever moves work **forward**, and only for a piece that has never been recorded as published.
A piece somebody deliberately pulled back to "Needs edit" while it is still live stays where they put
it — otherwise the next sweep would overrule an editor, forever.

**Settings → General** says which of the two you are getting.

## RedPen is the QA

Publishr does not check prose. [RedPen](https://craft-redpen.com) does, it does it
well, and duplicating a rule engine so that two plugins could disagree about whether "utilise" is
acceptable would be the worst possible outcome for somebody who owns both.

So the **Passes RedPen** publish requirement is a thin adapter: it asks RedPen for a verdict on the
entry and turns it into a pass or a fail.

Configure it with:

- **Fail on** — errors only, errors and warnings, or anything at all.
- **RedPen profile** — which style guide to review against. Blank uses the one RedPen would pick for
  the entry itself.

### When RedPen cannot answer

The requirement reports itself **skipped**, never failed. RedPen not installed, its backend timed
out, or the named profile no longer exists — none of those mean the copy has been found wanting.
They mean it has not been read.

A named profile that no longer resolves is especially worth getting right: reviewing against the
default instead would silently apply the *wrong style guide*, which is worse than saying nothing.

A site that buys Publishr in January and RedPen in March gets a requirement that lights up on its
own. A site that never buys RedPen never sees a red cross it cannot clear.

## How the integrations are wired

Both adapters resolve their plugin by string at runtime and never `use` a class from it. A hard
reference would make Publishr's autoloader look for a class that is not installed, and on a site
without it that is a fatal error on the **entry editor** — the single worst place in a CMS to put
one.

Both also check for the *service*, not just the plugin row, so a future version that renamed
something reads as unavailable rather than crashing the first time somebody signs a piece off.

## Adding your own requirement

Any class implementing `GateTypeInterface` can be registered:

```php
use justinholtweb\publishr\events\RegisterGateTypesEvent;
use justinholtweb\publishr\services\Gates;
use yii\base\Event;

Event::on(Gates::class, Gates::EVENT_REGISTER_GATE_TYPES, function(RegisterGateTypesEvent $event) {
    $event->types[] = \mymodule\gates\ApprovedByLegal::class;
});
```

That is the same door the RedPen adapter goes through. An integration that needed privileged access
would be an integration nobody could copy.

Implementations must be cheap and side-effect free — the checklist runs on every save of every
managed entry — and must return `SKIPPED` rather than a failure when they cannot answer.
