# Court Report — Design

**Date:** 2026-05-28
**Branch:** `feature/court-planner`
**Status:** Approved design, pending spec review

## Summary

A read-only **Court Report** accessible from the Kingdom and Park report grids. It lists
courts within a date range (default: past six months) and, on clicking a court, shows the
awards **confirmed to have been given out** at that court — recipient, award, a public
comment, and the attached artisans.

The report is a filtered read view over the existing Court Planner data
(`ork_court`, `ork_court_award`, `ork_court_award_artisan`). It does **not** introduce a new
domain class — per the chosen approach, report query methods are added to the existing
`system/lib/ork3/class.Court.php`, surfaced through the `Reports` controller for
discoverability alongside other reports.

## Decisions (from brainstorming)

- **"Confirmed" = award `status = 'given'`.** Only awards explicitly marked given appear.
  Planned/announced/cancelled awards are excluded.
- **Court list contents:** only courts that have **≥1 award with `status='given'`** in the
  date range. No empty reports.
- **Park report scope:** courts where `park_id = X` **OR** the court has a given award whose
  **recipient's home park is `X`** (so a park member's award received at kingdom court shows
  on the park report).
- **Access:** **publicly viewable** (these are awards actually given — public award history,
  consistent with the existing public `player_awards` report). Not gated behind court-manage
  permissions.
- **"Comments":** a **new public comment field** (`public_comment`) added to
  `ork_court_award`, kept separate from the planner's internal `notes`. The planner gets a
  field to fill it; the report surfaces it.

## Routes

| Purpose | Route |
|---|---|
| List (kingdom) | `Reports/courts&KingdomId={id}[&From=YYYY-MM-DD&Until=YYYY-MM-DD]` |
| List (park) | `Reports/courts&ParkId={id}[&From=YYYY-MM-DD&Until=YYYY-MM-DD]` |
| Detail | `Reports/court&CourtId={id}` |

`From`/`Until` are optional; when absent the list defaults to **today − 6 months … today**.
Query-parameter style mirrors the existing `Reports/player_awards&KingdomId={id}` pattern.

## Data layer — `system/lib/ork3/class.Court.php`

Two new read-only methods:

### `getCourtReportList($kingdomId, $parkId, $fromDate, $untilDate)`
Returns courts in `[fromDate, untilDate]` (inclusive, on `court_date`) that have **≥1 award
with `status = 'given'`**. Each entry:
- `CourtId`, `Name`, `CourtDate`
- scope label (kingdom court vs park name)
- event name (via `event_calendardetail_id` when present)
- `GivenAwardCount`

Scoping:
- **Kingdom report** (`$kingdomId` set, `$parkId` = 0): courts where `kingdom_id = $kingdomId`.
- **Park report** (`$parkId` set): courts where `park_id = $parkId` **OR** the court has a
  `status='given'` award whose recipient's **home park** = `$parkId`. (Recipient → park join
  via `ork_mundane`; the exact park column is verified during planning.)

Ordered by `court_date DESC`.

### `getCourtReportDetail($courtId)`
Returns the court header + its `status='given'` awards only, reusing the existing artisan
batch-load logic already present in `getCourtAwards()`. Per award:
- Recipient: `MundaneId` + persona (rendered as a link to the player profile)
- Award name + `rank` (shown only when the award is a ladder award)
- `public_comment` (the "Comments" column)
- Scroll maker (persona) and regalia maker (persona)
- `Artisans[]`: each `{ Persona, Contribution }`

## Controller & model

- `orkui/controller/controller.Reports.php` gains two actions:
  - `courts` — reads `KingdomId` / `ParkId` / `From` / `Until` from `$_GET`, applies the
    six-month default, calls `Model_Reports` → `class.Court::getCourtReportList(...)`, renders
    `Reports_courts.tpl`.
  - `court` — reads `CourtId`, calls `class.Court::getCourtReportDetail(...)`, renders
    `Reports_court.tpl`.
- `Model_Reports` is a thin pass-through to the `class.Court` methods (DB work stays in
  `system/lib/ork3/`).
- Both actions are in the **public** report set (no login redirect).

## Templates

### `Reports_courts.tpl` (list view)
- Header: "Court Report — {Kingdom/Park name}".
- Date-range filter: two flatpickr inputs (`From`, `Until`) with `altInput` + human-readable
  `altFormat`, defaulting to the last six months. Submits via GET back to the `courts` action.
- Court list: each row shows date (human-readable), court name, event, and given-award count;
  the row links to `Reports/court&CourtId={id}`.
- Empty state when no courts match.

### `Reports_court.tpl` (detail view)
- Court header: name, human-readable date, scope (kingdom/park), event.
- Given-awards table: **Recipient** (linked persona) · **Award** (name + rank if ladder) ·
  **Comments** (`public_comment`) · **Artisans** (persona + contribution; scroll/regalia
  makers included).
- "Back to Court Report" link returning to the list (preserving the kingdom/park scope).

Both templates must be **dark-mode compatible** proactively (modal/heading resets, ghost
buttons, labels, placeholders, inline colors — per project dark-mode checklist) and must never
show raw ISO datetimes.

## Schema change

```sql
ALTER TABLE ork_court_award
  ADD COLUMN public_comment TEXT NULL AFTER notes;
```
Migration file under `db-migrations/` (e.g. `2026-05-28-court-award-public-comment.sql`),
applied via the documented MariaDB container command.

## Planner UI change

The Court Planner award add/edit flow (the existing court-award modal in the Court Planner
templates) gains a **"Public comment"** field, distinct from internal **Notes**. It is
persisted through the existing court-award save handler in `controller.Court.php`
(remember to `$DB->Clear()` before raw execute, and assign `''` not `null` to clear it via
yapo).

## Report grid links

Add to the **Awards** group of:
- `orkui/template/revised-frontend/Kingdomnew_index.tpl` (Reports tab) →
  `Reports/courts&KingdomId={kingdom_id}`
- `orkui/template/revised-frontend/Parknew_index.tpl` (Reports tab) →
  `Reports/courts&ParkId={park_id}`

Suggested icon: `fa-gavel`.

## Out of scope (YAGNI)

- Pass-to-local delegation in the park scope (we use recipient home-park instead).
- Editing/printing/exporting the report.
- Any change to court or award status workflow.
- Surfacing planned/announced/cancelled awards.

## Build sequence

1. Migration: `public_comment` column.
2. Planner UI: add Public comment field + save wiring.
3. `class.Court` report methods (+ `Model_Reports` pass-through).
4. `Reports` controller `courts` / `court` actions.
5. `Reports_courts.tpl` + `Reports_court.tpl`.
6. Report grid links on Kingdom/Park profiles.
7. Verification (curl the routes; dark-mode walk-through).
