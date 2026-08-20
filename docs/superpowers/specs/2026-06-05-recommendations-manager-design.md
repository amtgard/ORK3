# Recommendations Manager — Design

**Date:** 2026-06-05
**Branch:** feature/court-planner
**Status:** Approved design (pending spec review)

## Summary

A standalone, full-width, **spreadsheet-style** tool for managing award recommendations
at the kingdom and park level. It moves all *administrative* recommendation actions
(Grant Now, Dismiss, Snooze, Add to Court) off the inline profile tab and into a dedicated
page reached via a **Manage Recommendations** button. The inline Recommendations tab on the
Kingdom/Park profile keeps only the community-facing behavior (+1/second and reading notes).

The tool presents recommendations as a dense, sortable, filterable data grid with per-row
and bulk actions, an inline expand for seconds (+1s) and reason, and a new **Add to Court**
flow that drops recommendations onto an existing or newly-created court plan.

## Goals

- Give kingdom/park officers a fast, full-width, spreadsheet-like surface for triaging a large
  recommendation backlog.
- Consolidate the scattered inline admin actions into one purpose-built tool.
- Add an **Add to Court** action (single + bulk) that integrates with the existing Court Planner.
- Visualize +1s as a counter with expandable seconding notes.
- Filter by court membership (on a court / not on a court / specific court) and existing
  eligibility filters.

## Non-Goals

- No change to how recommendations are *created* (the "Recommend an Award" flow stays as-is).
- No change to the Court Planner's own pages or its existing "Add from Recommendation" flow
  (this tool drives the same endpoints from the opposite direction).
- No new reporting/exports in v1 (YAGNI). Column visibility toggles are out of scope for v1.
- Member-facing +1/second remains inline on the profile tab; it is not duplicated here as an
  admin action.

## Access & Scope

- **Routes:**
  - `Recommendations/manage/kingdom/{kingdomId}`
  - `Recommendations/manage/park/{parkId}`
- **Authority:** Page render and the **Manage Recommendations** button are both gated by
  `Court::canManage($uid, $kingdomId, $parkId)` — kingdom/park editors + officers (Monarch /
  Regent / Prime Minister), the same authority required to move recs onto a court today.
  Unauthorized users never see the button and get bounced from the route.
- **Scope resolution:** the park route resolves its parent `kingdomId` the same way
  `controller.Court.php::list()` does, so kingdom-level lookups (award options, courts) work
  for park scope.

## Architecture

New, deliberately small server surface; all mutations reuse existing, tested endpoints.

- **New controller:** `orkui/controller/controller.Recommendations.php`
  - `manage()` — resolves scope + authority, loads recommendation rows + court map + filter
    metadata (courts list, parks list for kingdom scope), renders the template.
- **New template:** `orkui/template/revised-frontend/Recommendations_manage.tpl`
  - All CSS prefixed `rm-`; CSS + JS inlined per project convention.
  - Includes `_recommendation_seconds_assets.tpl` only if needed for note rendering parity;
    seconds here are display-only (admins don't +1 from the manager).
- **Model:** `orkui/model/model.Recommendations.php` only if a pass-through is needed;
  prefer calling existing models.
- **Data source:** `Report->PlayerAwardRecommendations(['KingdomId'|'ParkId' => id])`, which
  already returns per-rec: persona, park, award name, rank, reason, recommended-by + date,
  eligibility flags (`CurrentRank`, `AlreadyHas`, below/at-recommended, `AgeDays`),
  `IsSnoozed`, `IsOnCourt`, `Seconds[]` (supporter + note), `SecondsCount`.
- **New query — recommendation→court map:** a method (on `class.Court` or `class.Report`) that
  returns, for the scope, `recommendations_id => { court_id, court_name, court_date, status }`
  by joining `ork_court_award ⋈ ork_court` (active courts in scope). Needed because
  `IsOnCourt` is only a bool — the grid must show *which* court and the "specific court"
  filter must target one. Recs may appear on multiple courts; show the most relevant
  (e.g. earliest upcoming / most recent) and a `+n` if on more than one.

## Inline Profile Tab Changes

On `Kingdomnew_index.tpl` and `Parknew_index.tpl` Recommendations tab:
- **Add** a prominent **Manage Recommendations** button at the top of the tab (authority-gated).
- **Remove** the admin action controls from inline rows: Grant (`*GiveFromRec` button),
  Add to Court (`*-rec-addcourt-btn`), Snooze (`*-rec-snooze-btn`), Dismiss
  (`*-rec-dismiss-btn`), and the bulk-actions stub row.
- **Keep** the +1/second controls (`rs-action-btn`, edit/withdraw notes, edit reason) and the
  read-only display of reason + seconds. The associated `*-award-overlay` grant modal and
  `*-addcourt-overlay` court modal can be removed from the profile template once their only
  callers are gone (verify no other caller first).

## Spreadsheet-Style Grid (the `rm-` table)

**Feel:** dense data grid, not a styled card-table. Thin rows, visible gridlines, zebra
striping, `font-variant-numeric: tabular-nums` for date/age/rank/`+N`. Fills the viewport
width.

**Sticky chrome:**
- Frozen header row and frozen filter bar on vertical scroll.
- Sticky **Recipient** column on horizontal scroll.
- Optional footer total row: counts of filtered rows and selected rows.

**Columns:**
1. `[☑]` selection
2. **Recipient** — persona link + park abbreviation (sticky column)
3. **Award** — name + ladder rank or "non-ladder"
4. **Recommended** — by-persona + date + age-in-days; eligibility badge (e.g. "Below
   recommended", "Already has")
5. **Reason** — truncated to one line; `▸` expands an inline detail row with full text
6. **Support** — `+N ▸` chip; expands an inline detail row listing each supporter (persona
   link) + their note (or "(no note)")
7. **Court** — badge with court name + date (+`+n` if on multiple), or "—"
8. **Actions** — compact icon buttons (see below)

**Column-header interactions:**
- Click header to sort, with asc/desc caret. Sortable: Recipient, Award, Recommended (date),
  age, Support count. Default sort: oldest-first (age descending).
- Eligibility, Court, and Park filters live as **header dropdown menus** on their respective
  columns (spreadsheet-style). Active filters render as removable chips below the filter bar.

**Selection:**
- Checkbox column with **shift-click range select** and a "select all (filtered)" control.

**Expand-in-place:** Support and Reason expansions render as inline detail rows that span the
grid without breaking column alignment. Independent of each other; multiple rows may be
expanded.

## Filters

Client-side over the already-loaded row set (matching how the current tab filters):
- **Eligibility:** All · Below Recommended · At/Above Recommended · Non-Ladder · Snoozed
- **Court:** All · Not on a court · On any court · *specific court* (dropdown of scope's courts)
- **Park** (kingdom scope only): by recipient's park
- **Search:** recipient persona text match

## Actions

All mutations reuse existing endpoints. Confirmations use `tnConfirm()` (never native dialogs).

**Per-row (icon buttons with `data-tip` tooltips):**
- **Grant Now** (⚡) — *instant* grant, no award modal. `tnConfirm` guard, then POST to
  `Admin/player/{MundaneId}/addaward` (the same save URL the inline award modal posts to) with
  the rec's prefilled fields (`KingdomAwardId`/`AwardId`, `Rank`, `Reason`, date). On success
  the rec is removed from the grid (and dismissed/soft-deleted consistent with court-grant
  behavior). Mirrors the existing `knGiveFromRec` payload shape minus the modal step.
- **Add to Court** (＋) — opens the Add to Court modal (below).
- **Snooze / Unsnooze** (💤) — POST to
  `KingdomAjax/kingdom/{id}/snoozerecommendation` | `unsnoozerecommendation` (or the `ParkAjax`
  equivalents) with `RecommendationsId`. Toggles the row's snoozed state in place.
- **Dismiss** (✕) — POST to `KingdomAjax/kingdom/{id}/dismissrecommendation` (or `ParkAjax`)
  with `RecommendationsId`. Removes the row.

**Bulk bar** (appears when ≥1 row selected): **Add to Court · Snooze · Dismiss**.
- Runs as a client-side sequential loop over the same single-item endpoints, with a
  progress/result toast (e.g. "Added 18 of 20; 2 already on this court").
- **Grant Now is per-row only** in v1 (each grant is a real award write; keep it deliberate).

## Add to Court Modal

- **Mode toggle:**
  - *Existing court* — dropdown of the scope's courts (name + date + status).
  - *Create new court* — Name (required), Date (optional, human-readable flatpickr w/
    `altInput`), optional event link. Submits `CourtAjax/create_court` with the scope's
    `KingdomId`/`ParkId`.
- **Submit flow:**
  1. If creating, `CourtAjax/create_court` once → capture `court_id`.
  2. For each selected rec, `CourtAjax/add_award` with `CourtId`, `RecommendationsId`, and
     `MundaneId`/`KingdomAwardId`/`Rank` copied from the rec.
  3. Recs already on the chosen court are skipped and reported.
- **Result:** each affected row's **Court** badge updates in place; a toast summarizes
  added/skipped counts.

## Data Flow

1. `controller.Recommendations.php::manage()` → authority check via `Court::canManage`.
2. Loads rows from `Report->PlayerAwardRecommendations`, the court map, and filter metadata
   (courts, parks).
3. Template renders the grid with all data inlined (no initial AJAX round-trip).
4. Filtering/sorting/expand/selection are client-side.
5. Mutations fire to existing AJAX endpoints; the grid patches affected rows in place (no full
   reload), matching the current inline patch-not-reload pattern.

## Error Handling

- Endpoint failures surface via toast with the server `error` string; the row reverts to its
  prior state (no optimistic-commit on failure).
- Bulk loops continue past individual failures and report a per-item tally.
- Authority failures server-side return JSON `{status:1, error:...}`; client shows the message.
- Empty states: distinct copy for "no recommendations" vs "no rows match filters".

## Testing

- **Authority:** non-authorized user is blocked from the route and never sees the button
  (kingdom + park scope).
- **Data parity:** grid rows match what `Report->PlayerAwardRecommendations` returns for the
  same scope; court badges match `ork_court_award` membership.
- **Per-row actions:** Grant Now writes an `ork_awards` row and removes the rec; Snooze/Dismiss
  hit the correct Kingdom/Park endpoint and patch the row; verified via curl-auth session +
  DB read-back.
- **Bulk:** select-all-filtered + Add to Court creates one court and N court_awards; skip logic
  for already-on-court verified.
- **Filters/sort:** each eligibility/court/park filter and each sortable column verified;
  shift-click range select verified.
- **Dark mode:** full walk of grid, header dropdowns, chips, modals, toasts, expand rows per
  the dark-mode pre-flight checklist.
- **Conventions:** `.tpl` is plain PHP (no Smarty); player/court searches scoped + `&q=`;
  `tnConfirm` not native; no browser `title` tooltips; flatpickr human-readable date.

## Open Risks / Notes

- Confirm no remaining caller of the inline `*-award-overlay` / `*-addcourt-overlay` modals
  before removing them from the profile templates.
- Confirm the exact soft-delete/dismiss behavior expected after **Grant Now** (match
  court-grant, which soft-deletes the linked recommendation).
- The recommendation→court map should respect the active-court scope so stale/cancelled courts
  don't show as a rec's court badge.
