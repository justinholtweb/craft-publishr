---
title: Twig reference
---

# Twig reference

Everything on `craft.publishr` is **read-only**. Anything that changes something goes through a
controller with a permission check on it; a template is not the place from which a piece gets signed
off.

It works on the front end as well as in the control panel. A staging site that shows *"in progress ·
due Friday · Sam"* above each article is worth more to a review meeting than any amount of screen
sharing, and it takes one tag.

## The editorial record

```twig
{% set item = craft.publishr.item(entry) %}

{% if item %}
    {{ item.getStage().name }}
    {{ item.getStage().color }}
    {{ item.getAssignee().friendlyName ?? 'Unassigned' }}
    {{ item.dueDate|date('j M Y') }}
    {{ item.brief }}

    {% if item.isOverdue() %}
        {{ item.daysUntilDue()|abs }} days late
    {% else %}
        due in {{ item.daysUntilDue() }} days
    {% endif %}
{% endif %}
```

`item()` returns `null` for anything Publishr is not tracking. `daysUntilDue()` returns `null` when
there is no deadline, and counts **whole days in the site's time zone** — "due tomorrow" means
tomorrow's date, not 24 hours from now.

| | |
|---|---|
| `craft.publishr.item(entry)` | the editorial record, or null |
| `craft.publishr.stage(entry)` | its stage, or null |
| `craft.publishr.stages()` | every stage, in workflow order |

## The calendar

```twig
{% for day, events in craft.publishr.month(2026, 6, ['publish', 'due']) %}
    <h3>{{ day|date('j F') }}</h3>
    {% for event in events %}
        <p>
            <span class="lane-{{ event.lane }}">{{ event.lane }}</span>
            <a href="{{ event.entry.getCpEditUrl() }}">{{ event.entry.title }}</a>
            {% if event.late %}<strong>late</strong>{% endif %}
            {% if event.isDraft %}<em>draft</em>{% endif %}
        </p>
    {% endfor %}
{% endfor %}
```

`month(year, month, lanes, siteId)` — every argument optional. Returns events bucketed by `Y-m-d`.
Lanes are `due`, `publish`, `expire` and `review`; omitting them uses the site's defaults.

## Queues and lists

```twig
{% for item in craft.publishr.assignedTo() %}          {# defaults to the current user #}
    {{ item.getElement().title }} — {{ item.dueDate|date('j M') }}
{% endfor %}

{% for item in craft.publishr.overdue() %}{% endfor %}
{% for item in craft.publishr.reviewsDue(null, 20, 30) %}{% endfor %}
```

| | |
|---|---|
| `assignedTo(userId, siteId, limit)` | somebody's queue, soonest deadline first |
| `overdue(siteId, limit)` | past their deadline and not yet published |
| `reviewsDue(siteId, limit, withinDays)` | due a freshness review |

## Requirements, freshness and history

```twig
{% set report = craft.publishr.gates(entry) %}
{{ report.passedCount() }} of {{ report.applicableCount() }}

{% for result in report.blocking() %}
    <li>{{ result.gateName }} — {{ result.message }}</li>
{% endfor %}

{% if craft.publishr.staleness(entry) > 60 %}
    <p>Not reviewed since {{ craft.publishr.item(entry).lastReviewedAt|date('F Y') }}.</p>
{% endif %}

{% for row in craft.publishr.history(entry, 10) %}
    <li>{{ row.dateCreated|datetime('short') }} — {{ row.describe() }}</li>
{% endfor %}
```

| | |
|---|---|
| `gates(entry, recheck)` | the verdict; empty on Lite |
| `staleness(entry)` | 0–100, where 100 is a full interval overdue |
| `history(entry, limit)` | what happened, newest first |
| `comments(entry, includeResolved)` | the editorial thread |
| `openComments(entry)` | how many are unresolved |
| `report(siteId)` | everything the governance report shows |
| `isPro()` | which edition is licensed |

## A word about the front end

Editorial comments are internal. `craft.publishr.comments()` is available on the front end because a
staging build is a legitimate place to show them — but it is your template that decides, and putting
them on a public page will publish your desk's private conversation. Guard it.
