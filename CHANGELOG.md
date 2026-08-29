# Publishr changelog

## 5.0.0 — 2026-08-27

Initial release.

### Added

- **Editorial calendar.** Month view with four lanes — due, publishing, expiring, review — filtered
  by stage, section, owner and search, with drag-and-drop that moves whichever date the lane
  represents. Unpublished drafts appear alongside live content.
- **Board and overview.** One column per stage, and a filterable list with bulk stage, assignment
  and deadline actions.
- **My desk.** One person's queue, soonest deadline first, with work that has no deadline sorted
  last rather than first.
- **Editorial stages.** Project-config-backed, colour-coded, with a default stage, a published
  stage and per-stage sign-off gating. Five seeded on install.
- **Assignments, deadlines and briefs**, keyed to the canonical entry so they survive every draft
  created and applied underneath them.
- **Editorial comments.** One level of threading, resolvable, with `@name` mentions. Stored beside
  the element, never in it.
- **Append-only history** of every stage move, assignment, deadline change, review and override.
- **Publish requirements** (Pro): required fields, minimum length, related content with optional
  alt-text enforcement, a publish date, an owner, no open comments, a manual checklist, and a clean
  RedPen pass. Required or advisory, scoped by section and stage, overridable with a permission and
  a name in the history.
- **Optional publish guard** (Pro): refuse to save an entry into a public state while a required
  requirement fails. Off by default, and never applies to drafts, disabled entries, or anybody
  holding the override permission.
- **Freshness reviews** (Pro): policies per section and entry type, review-by dates anchored to the
  last human review, a staleness score, a bounded sweep and a reviews screen sorted worst-first.
- **Notifications** (Pro): assignment, stage change, due soon, overdue, review due, published,
  expired, comment and mention — each a durable row with its own attempt count, error and retry
  button, deduplicated per day by a unique index.
- **Daily digest** (Pro), sent only to people who have something in it.
- **Governance report** (Pro): calendar coverage, per-stage volume and median age, workload by
  person, what is late, what is unowned, what is stale, weekly throughput, and a CSV export.
- **Alarm Clock integration**: stages advance, deadlines clear and freshness clocks start at the
  real moment a piece goes live.
- **RedPen integration**: the house style guide as a publish requirement.
- `craft.publishr` Twig variable, read-only.
- `publishr/sweep` and `publishr/track` console commands.
- Seven granular permissions, with sign-off override kept deliberately separate from stage
  management.
