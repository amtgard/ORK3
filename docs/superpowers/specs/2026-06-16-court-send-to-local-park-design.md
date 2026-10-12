# Court Planner — "Send to Local Park" (Kingdom-side) + Flag Tooltip

**Date:** 2026-06-16
**Branch:** `feature/court-planner`
**Route:** `Court/detail/{court_id}` (Kingdom/Principality-scoped courts only)
**Files:** `orkui/template/default/Court_detail.tpl`, `orkui/controller/controller.CourtAjax.php`

## Problem

On Kingdom/Principality court planning, an officer may decide an award they were going to
give at this court should instead be given by the recipient's **local park**. Today the
expand panel has a persistent *"Pass to Local — Kingdom approves, Park to give"* checkbox
(`court_award.pass_to_local`), which keeps the award on the kingdom court flagged. The
desired behavior is an **action**: hand the award down to the recipient's local park and
**remove it from this court**.

Separately, the two Flags-column badges use native `title=` tooltips, which violate the
project's no-native-tooltips rule.

## Scope

**This work is Kingdom-side only.** The park-side received indicator ("Approved by Kingdom
to Award Locally") is handled in a separate effort and is explicitly **out of scope** here.
To avoid two efforts editing the same Flags-column lines, this work:
- **Does** convert the **From Recommendation** (star) flag tooltip to `data-tip`.
- **Does not** touch the pass-to-local (down-arrow) flag's display or tooltip — that belongs
  to the park-side effort.

## Existing infrastructure reused

- `set_recommendation_passed_to_local(['Token','RecommendationsId','Passed'])`
  (model wrapper → `class.Player.php::SetRecommendationPassedToLocal`) sets
  `recommendations.passed_to_local/by/at`, re-checks `AUTH_KINGDOM`/`AUTH_CREATE` over the
  **recipient's** kingdom (principality traversal included), busts the recs cache, and
  audits. The park-side surfaces this via its existing recs-tab badge.
- `remove_award` pattern: delete `court_award_artisan` rows, then the `court_award` row,
  after `requireCourtAuth($court_id)`.
- Guarded `tnConfirm({title, body, confirmLabel, danger, onConfirm})` (used by
  `cpDismissRec`), with a fallback when `tnConfirm` isn't loaded.

## Design

### 1. The control (expand panel)

On **kingdom/principality courts only** (`$court['ParkId'] == 0`), replace the
*"Pass to Local"* checkbox with a button:

- Label: `↓ Send to Local Park` (FontAwesome `fa-arrow-down`).
- `data-tip` = "Would you rather this award be given by their local park? Click here to
  remove from this Court and send to the local monarchy."
- Shown **only for rec-backed awards** (`RecommendationsId` present). Ad-hoc awards (no
  rec) show no button — there is no recommendation to carry the `passed_to_local` signal.

On **park courts** (`ParkId > 0`) the expand panel is unchanged (out of scope).

### 2. Click behavior

`cpSendToLocal(caid)`:
1. Guarded confirm — `tnConfirm({title:'Send to local park?', body:<tooltip text>,
   confirmLabel:'Send to Local', danger:true, onConfirm:doSend})`; fall back to the same
   `doSend` directly if `tnConfirm` isn't a function (mirrors `cpDismissRec`).
2. `doSend` → POST `CourtAjax/pass_award_to_local` with `CourtAwardId`.
3. On `status === 0`: remove the award's row from the planning list (reuse the existing
   row-removal animation/refresh used by `cpRemoveAward`) and refresh the progress counter.
4. On error: show the returned message inline; the award stays on the court.

### 3. New endpoint: `CourtAjax/pass_award_to_local`

POST `CourtAwardId`. Steps:
1. Validate `CourtAwardId`.
2. Look up the court award → `court_id`, `recommendations_id`, `mundane_id`.
   Error "Award not found." if missing.
3. `requireCourtAuth($court_id)` (gates court management, matching the other endpoints).
4. If `recommendations_id` is empty → `{status:1, error:'This award is not from a
   recommendation, so it cannot be passed to local.'}`.
5. `load_model('Player')`; call `set_recommendation_passed_to_local(['Token' =>
   $this->session->token, 'RecommendationsId' => $rec_id, 'Passed' => 1])`. If the result
   `Status != 0` → return `{status:3, error:'Not authorized to pass this award to local.'}`
   (or the pipe's error). Award is **not** removed on auth failure.
6. Delete `court_award_artisan` rows for the award, then the `court_award` row (mirroring
   `remove_award`).
7. `{status:0}`.

Ordering note: the pipe runs first; only on its success do we delete. The pipe is
idempotent (a re-pass just re-sets the same columns), so a delete failure after a
successful pass leaves a recoverable state (rec marked, award still listed) rather than a
silent loss.

### 4. Cleanup / guards

- `cpSaveAward` reads the PTL checkbox; guard it so a missing checkbox on kingdom courts
  (where it's now a button) doesn't throw: `var ptlEl = gid('cp-ptl-'+caid); var ptl =
  ptlEl ? (ptlEl.checked ? 1 : 0) : 0;`.
- Add a JS court-scope flag near `var courtId` (`~:1445`):
  `var courtIsKingdom = <?= ($court['ParkId'] ?? 0) == 0 ? 'true' : 'false' ?>;` for the
  `cpAppendAwardRow` render path to choose button vs. checkbox.

### 5. Flag tooltip (in-scope part only)

Convert the **From Recommendation** badge from `title="From Recommendation"` to
`data-tip="Added from a recommendation."` in both render paths (PHP `~:1062`, JS `~:2229`),
reusing the existing `[data-tip]` hover CSS already in the file (`~:182`). The down-arrow
pass-to-local badge is left as-is for the park-side effort.

## Out of scope

- Park-side "Approved by Kingdom to Award Locally" flag (separate effort).
- Ad-hoc (non-rec) award handoff.
- Un-send / reverse from the court UI (the recipient's rec can be un-passed via existing
  recs UI).
- `court_award.pass_to_local` column removal/migration (left intact; park courts still use
  it until the park-side effort revisits it).

## Testing

- Kingdom/principality court, rec-backed award → button present with correct tooltip;
  ad-hoc award → no button.
- Click → confirm → award row disappears; DB: `court_award` row gone,
  `recommendations.passed_to_local = 1` for that rec.
- Park court → expand panel unchanged (checkbox still present), no button.
- Non-authorized user can't reach it (page already gated by `canManage`; pipe re-checks).
- `cpSaveAward` on a kingdom court (no checkbox) saves without JS error.
- From Recommendation badge shows the `data-tip` tooltip (no native `title`).
- Dark mode: button + tooltip legible.
- `php -l` clean on both files.
