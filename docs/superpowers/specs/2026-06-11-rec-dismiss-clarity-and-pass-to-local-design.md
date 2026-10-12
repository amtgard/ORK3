# Recommendation Dismiss Clarity & Pass-to-Local Delegation — Design

**Date:** 2026-06-11
**Branch:** `feature/court-planner`
**Status:** Approved design, pending spec review

## Summary

Two related recommendation-workflow improvements, shipped together because they touch the same
surfaces (the Recommendations Manager grid, the inline recs panels, and the report query):

- **Part A — Dismiss clarity.** Make the soft-delete control read **"Dismiss"** consistently
  everywhere and explain (via tooltip) that dismissing is the right way to clear recs that were
  already given out or won't be awarded. Backlog cleanup uses the existing **At-or-Above** filter
  + bulk Dismiss — no new data or action.
- **Part B — Pass-to-local delegation.** Let a kingdom/principality officer **pass a
  recommendation down** to the recipient's home park ("the local") — a delegation/intent signal,
  distinguished visually in the recs list and the Manager, and surfaced to the park as work
  delegated to them. No award-level enforcement (the system does not gate awards by level today).

## Part A — Dismiss Clarity

### Goals
- The dismiss control reads **"Dismiss"** on every surface that can dismiss a recommendation.
- A tooltip educates officers that dismiss is appropriate for "already given out" / "no plans to
  award" recs — making "dismiss as already granted" a matter of guidance, not a separate tracked
  action.
- Backlog cleanup of already-has recs uses existing tooling.

### Changes
- **Audit + standardize** every recommendation-dismiss control to the label/aria **"Dismiss"**:
  - Recommendations Manager — per-row dismiss (`.rm-act-dismiss`) and bulk Dismiss
    (`.rm-bulk-dismiss`) in `Recommendations_manage.tpl`.
  - Court planner pending-recs modal — the per-rec dismiss control in `Court_detail.tpl`.
  - (Verify no other live surface dismisses a rec; the inline profile recs tabs had their admin
    actions moved to the Manager and should be confirmed clean.)
- **Tooltip** (in-product `data-tip`, never native `title` per project convention) on the dismiss
  control: **"Already given out previously? No plans to award this? You can dismiss this rec."**
- **Cleanup path (no new code):** the Manager's **At-or-Above** eligibility filter isolates recs
  whose recipient already holds the award; "select all (filtered)" + the bulk **Dismiss** clears
  them. This is the documented "easy way to clean up the backlog."

### Non-Goals (Part A)
- No `dismiss_reason`/status column; no separate "dismiss as granted" action; no new filter
  (At-or-Above already exists).

## Part B — Pass-to-Local Delegation

### Concept
A recommendation's recipient belongs to a home park. A kingdom (or principality) officer can
**pass the recommendation down** to that park, delegating the award to the local to handle. This
is an **intent/communication signal**, not a system-enforced authority change. A passed-down rec
is **not** put on a kingdom court — the local puts it on the local's own court.

### Data Model
Migration adds to `ork_recommendations`:
```sql
ALTER TABLE ork_recommendations
  ADD COLUMN passed_to_local    TINYINT NOT NULL DEFAULT 0,
  ADD COLUMN passed_to_local_by INT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN passed_to_local_at TIMESTAMP NULL DEFAULT NULL;
```
"Local" is implicit: the recipient's home park (`ork_mundane.park_id`). Recs have no `park_id`,
so no scope column is needed. The flag persists on a rec even after it is later granted/dismissed
(soft-deleted), preserving the delegation record.

### Report
`class.Report::PlayerAwardRecommendations` selects `passed_to_local` (+ `_by`/`_at`) and returns
per rec: `PassedToLocal` (bool), `PassedToLocalBy` (int|null), `PassedToLocalAt` (string|null).
The 300s cache for this query is already busted on create/delete/snooze/second; the pass-down
write must bust it too (so the badge updates), via the existing
`bust_player_award_recs_cache($recipientMundaneId)`.

### Action — "Pass down" toggle
- **Where:** the Recommendations Manager, **kingdom/principality scope only** (`Context==='kingdom'`).
  In **park** scope the officer is the *recipient* of delegations, so the button is hidden there
  (badge + filter still show). As a Thread-C group action, the toggle loops the cluster's member
  rec ids (consistent with snooze/dismiss).
- **Authority:** only an officer with `AUTH_KINGDOM` over the recipient's kingdom may pass down
  (or un-pass). The model method enforces this per rec (mirrors how `DeleteAwardRecommendation`
  enforces per-rec authority), so the endpoint is safe even though the Manager already gates the
  page.
- **Endpoint:** new `KingdomAjax` action `passtolocalrecommendation` (POST `RecommendationsId`,
  `Passed` = 1/0) → `Model_Player->set_recommendation_passed_to_local` →
  `class.Player::SetRecommendationPassedToLocal($request)`:
  - Loads the rec; resolves the recipient's kingdom; requires `AUTH_KINGDOM` there.
  - Sets `passed_to_local = Passed`, `passed_to_local_by = RequestedBy`,
    `passed_to_local_at = NOW()` (or clears `_by`/`_at` when un-passing); `$DB->Clear()` before
    raw execute; busts the recs cache; audits via `dangeraudit`.
- **Toggle semantics (group):** like the snooze toggle — if not all members are passed, pass all;
  if all are passed, un-pass all. The Manager loops `data-members` over the per-rec endpoint.
- **Button tooltip** (`data-tip`): **"For recommendations at a higher level than the park can
  provide, you are granting authority for that park to award at this level."**
- **Bulk:** a bulk "Pass down" action on selected groups (kingdom scope), looping members.

### Display — distinction badge
- A badge — a down-arrow (`fa-arrow-down`, echoing the court pass-to-local icon) + label
  **"passed to local"** — renders on passed-down recs in:
  - the **Manager grid** (Award cell, beside the "already has" / "below rec." badges; a group
    shows it when all members are passed), and
  - the **inline recs list** (`Kingdomnew_recommendations_panel.tpl` and the Park/Player recs
    panels) — read-only there (no action button inline).
- The badge has its own `data-tip`: "Passed to the local park to award."

### Park surfacing
- In the **park-scope** Manager and the **Park** profile recs tab, passed-down recs are visually
  highlighted and **filterable** via a new **"Passed to local"** chip/filter value — communicating
  "the kingdom delegated this for you to award." The park then grants it or adds it to the park's
  own court through the normal flow.
- The park does **not** get a pass-down/un-pass button (only the delegating kingdom can toggle it).

### Grouping interaction (Thread C)
Pass-down is a per-rec flag but the Manager renders clusters. The controller's `$Groups` gains a
group-level `PassedToLocal` = true iff **all** members are passed (mirrors `IsSnoozed`). The group
toggle loops `MemberRecIds` over the per-rec endpoint, and the badge reflects the all-members
state. The inline recs panels are per-rec (ungrouped), so they show the per-rec flag directly.

### Non-Goals (Part B)
- No enforcement of award-level grant authority (the system doesn't restrict awards by level).
- No court carry-through: a passed-to-local rec is handled at the local's court, never auto-added
  to a kingdom court.
- No notification on pass-down in v1 (it surfaces in the park Manager/recs; a future in-app
  notification to park officers could reuse the Thread-B `ork_notification` store).

## Data Flow

1. Kingdom officer (kingdom-scope Manager) clicks **Pass down** on a rec/cluster →
   `passtolocalrecommendation` per member → `passed_to_local=1` + by/at; cache busted.
2. The rec now shows the "passed to local" badge in the kingdom Manager and the inline recs lists.
3. In the recipient park's scope (park Manager + Park recs tab), the rec is highlighted +
   filterable as delegated-to-the-park.
4. The park officer grants it (or adds to the park's court) via the normal flow; the rec
   soft-deletes on grant/dismiss with the delegation record preserved.

## Error Handling
- Pass-down write failures surface a toast and revert the row's badge state (no optimistic commit
  on failure), matching the Manager's existing patterns.
- An unauthorized pass-down (no `AUTH_KINGDOM`) returns a JSON error; the Manager only shows the
  button in kingdom scope, but the server still enforces.
- Dismiss/label/tooltip changes are presentational and cannot fail.

## Testing
- **Part A:** every dismiss control reads "Dismiss" and carries the tooltip; At-or-Above filter +
  bulk Dismiss clears already-has recs (curl/UI).
- **Part B schema:** migration applies; the three columns exist with defaults.
- **Pass-down write:** toggling a rec sets `passed_to_local`/`_by`/`_at`; un-toggling clears
  `_by`/`_at`; verified by DB read-back. Authority: a non-`AUTH_KINGDOM` user is rejected.
- **Report:** `PlayerAwardRecommendations` returns `PassedToLocal` for a flagged rec; the cache is
  busted so the badge updates without a stale window.
- **Manager (kingdom scope):** the Pass-down button shows + toggles; the badge renders; a cluster
  toggles all members; bulk pass-down works.
- **Park scope / Park recs tab:** passed-down recs are highlighted + the "Passed to local" filter
  isolates them; no pass-down button is shown.
- **Inline recs panels:** the badge renders read-only.
- **Conventions:** `$DB->Clear()` before raw execute; `.tpl` plain PHP; `data-tip` not native
  `title`; dark-mode walk of the badge/button/filter; `tnConfirm` (not native) for any confirm.

## Build Sequence
1. Migration: `passed_to_local` columns.
2. `class.Report::PlayerAwardRecommendations` returns `PassedToLocal` (+ by/at).
3. `class.Player::SetRecommendationPassedToLocal` (+ `Model_Player` passthrough) with
   `AUTH_KINGDOM` enforcement + cache bust + audit.
4. `KingdomAjax::passtolocalrecommendation` endpoint.
5. `controller.Recommendations::manage` adds group-level `PassedToLocal`.
6. Manager grid: pass-down button (kingdom scope) + badge + bulk + "Passed to local" filter; and
   the dismiss-label/tooltip standardization (Part A).
7. Inline recs panels (Kingdom/Park/Player): the read-only badge; Court planner pending-recs modal
   dismiss-label/tooltip (Part A).
8. Verification (curl-auth + DB read-back; dark-mode walk).

## Risks / Notes
- `passed_to_local` must flow through `PlayerAwardRecommendations` (and its cache) or the badge
  won't appear / will be stale — the load-bearing integration point.
- Keep the pass-down group action consistent with the existing snooze/dismiss member-loop pattern
  to avoid divergent behavior in the grouped grid.
- Local dev can exercise all of this (recommendations + parks exist locally); the court pending-recs
  modal dismiss-label change is verifiable once court data is seeded (as in prior QA).
