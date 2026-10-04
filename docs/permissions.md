---
title: Permissions
slug: permissions
order: 90
summary: 'The seven permissions and what each one lets somebody do.'
---

# Permissions

Seven, under **Publishr** in a user group's settings.

| Permission | Lets somebody |
|---|---|
| **See the editorial calendar** | open the calendar, board, overview and their own desk |
| **Move work between stages and set deadlines** | drag pips, move cards, set deadlines and briefs |
| **Assign work to other people** | change who owns a piece |
| **Leave editorial comments** | post, resolve and reopen comments |
| **Carry out freshness reviews** | mark a piece reviewed, schedule outstanding reviews |
| **Sign off despite failing requirements** | push a piece into a gated stage anyway |
| **See the governance report** | open the report and export it |
| **Change the workflow and requirements** | edit stages, requirements, policies and settings |

Everything is nested under **See the editorial calendar** — without it, the Publishr section does not
appear at all.

## Two that are deliberately not nested where you would expect

**Sign off despite failing requirements** is not nested under stage management. Being allowed to move
a card and being allowed to *overrule the sign-off requirements* are different levels of trust, and
on most desks they belong to different people. An override is also written into the history with the
overrider's name and the requirements they overruled, which only means anything if not everybody has
it.

**Change the workflow and requirements** is separate from everything operational. A managing editor
should be able to reassign work and sign it off without being able to redefine what sign-off means.

## What Publishr never lets anybody do

**Edit an entry they could not already edit.** Dragging a pip in the publish lane changes an entry's
post date, and that goes through Craft's own `canSave` check. Publishr's permissions govern
Publishr's data; Craft's govern the content.

**Change something from a template.** Everything on `craft.publishr` is read-only. Every action lives
behind a controller with a permission check, and the override in particular is checked as a
permission on the server rather than trusted from a hidden form input — a hidden input is not an
authorisation.

## Admins

Admins have everything, as usual. The settings screens accept either an admin or the *Change the
workflow and requirements* permission, so a lead editor can own the workflow without being handed
the whole site.
