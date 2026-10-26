# Court Planner Resource Planning Tab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Resource Planning tab beside Order of Court in the Court Planner: a spreadsheet-like grid of scroll and regalia status, makers and artisans that stays in sync with the Order in both directions.

**Architecture:** This is front-end only, in `orkui/template/default/Court_detail.tpl`, plus its CSS (inline in that template, or the shared `default/style/court-planner.css`, wherever its neighbours live). The grid is rendered by JS from the one client model `window.courtAwards`. It reuses the Order's chip and picker (`.cp-chip`, `cpOpenTrackMenu`, `cpSetLineTracking`) and the existing endpoints (`set_award_tracking_status`, partial `update_award`, `add_artisan`). One invalidation hook, `cpRpInvalidate()`, is called from every model writer.

**Tech Stack:** PHP 8 templates (plain PHP), vanilla JS, CSS; verified in Chrome against Docker :19080.

**Spec:** `docs/superpowers/specs/2026-10-26-court-planner-resource-planning-design.md`

## Global Constraints

- No new endpoints, migrations, or SQL. The only server calls are `CourtAjax/set_award_tracking_status`, `CourtAjax/update_award` (posting only `ScrollMakerId` or `RegaliaMakerId`, plus `RowVersion`), and `CourtAjax/add_artisan`/`remove_artisan` through the existing modal.
- Every RP write goes through the per-line queue `cpLineWrites[caid]`. It sends the current `cpGetRowVersion(caid)`. On success it calls `cpSetRowVersion` and `cpPendingRunAdvance(caid, sentRv, newRv)`. Status 9 calls `cpStale(caid)`.
- A maker change from RP must also update the Order expand inputs `cp-scroll-maker-text-<id>`/`cp-scroll-maker-id-<id>` and `cp-regalia-maker-text-<id>`/`cp-regalia-maker-id-<id>`, so a later Order Save cannot post the old maker back.
- Scroll status values: 0 Not Tracked, 1 Custom Design, 2 Complete, 3 Scrollery Print. Regalia values: 0 Not Tracked, 1 Needed, 2 Complete. Labels come only from `cpTrackLabel`.
- "Needs an artisan" = (scroll 1 and no ScrollMakerId) or (regalia 1 and no RegaliaMakerId), on a line that isn't `cancelled`.
- Use the URL param `view=resources` for RP; any other value, or none, means Order. Set it with `history.replaceState`.
- Use only the shared scoped `cpAcSearch(input, dropId, hiddenId, onPick)` for player search. Tooltips are `data-tip`, never `title`. No native `alert`/`confirm`/`prompt`.
- Dark mode via `html[data-theme="dark"]` for every new surface. At ≤640px the RP rows become cards, with no horizontal page scroll.
- Never stage `system/lib/ork3/class.Authorization.php`; never `git add -A`; check `git diff --cached` before committing (concurrent sessions). Commit messages use `Enhancement:` prefix and end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Order Save after an RP maker change.** Assign a scroll maker on RP, switch to Order, expand the line, edit notes, and Save. The new maker must persist, not the old one. Task 2's browser check covers this.
2. **Two quick writes on one line from different tabs.** An RP maker pick followed at once by a chip pick must serialize: no false "row changed", and both land. Covered by the shared queue in Task 2.
3. **A heartbeat render while an RP maker search is open.** It must not destroy the input under the officer's cursor; the render waits. Task 1 builds the guard and Task 2 exercises it.
4. **A line added, removed or skipped on the Order, or by a peer.** RP gains, drops or dims it without a reload. Task 1 wires the invalidate into those paths.
5. **Scrollery Print scroll.** The RP maker cell is non-editable "Printed by Scrollery". Moving the chip off Scrollery Print makes the cell assignable again on both tabs.

---

### Task 1: Tabs, the Resource Planning grid, and the sync hook

**Files:**
- Modify: `orkui/template/default/Court_detail.tpl`, which covers the section header (~1643), the main column wrapper (~1640–2040), the JS model writers, and the CSS block at the top
- Modify (if its neighbours live there): `orkui/template/default/style/court-planner.css`

**Interfaces:**
- Consumes: `window.courtAwards` (fields `CourtAwardId, Status, Persona, ParkName`, the award name and rank fields the Order row uses, `ScrollStatus, RegaliaStatus, ScrollMakerId, ScrollMakerPersona, RegaliaMakerId, RegaliaMakerPersona, Artisans[{Persona, Contribution}], ScrolleryPrintable, SortOrder`); the PHP `cp_track_chip` markup and its JS twin `cpTrackChipHtml`; `cpOpenTrackMenu`; `cpSetLineTracking`; `cpApplyRowFields`; `cpReconcileState`; `cpAppendAwardRow`; the skip/unskip/remove/reorder/artisan writers.
- Produces:
  - `window.cpRpInvalidate()`, the one hook every model writer calls.
  - `window.cpRpRender()`.
  - `window.cpSetView('order'|'resources')`.
  - `window.cpRpEditing`, a boolean the render guard reads. Task 2 sets it while a maker search is open, and the render waits while it is true.
  - The RP maker cell is a `button.cp-rp-maker[data-kind="scroll"|"regalia"][data-caid]` that Task 2 makes interactive. Task 1 renders it read-only: the persona, "Assign…", or "Printed by Scrollery".

- [ ] **Step 1: Read the current section header, main column, the `cpLines` ordering, the row writers and the heartbeat reconcile**, so the tab split doesn't move the print bar or toolbar out of the Order panel. Find the existing numbering source the Order shows for "#".
- [ ] **Step 2: Build the tab strip.**
  - `role="tablist"` with two `role="tab"` buttons: "Order of Court" with the existing `#cp-award-count`, and "Resource Planning".
  - Two `role="tabpanel"` containers: the Order panel wraps today's print bar, toolbar and list; the RP panel is new.
  - Header actions show on the Order tab only.
  - ArrowLeft and ArrowRight move between tabs.
  - `cpSetView` toggles the panels, updates `aria-selected`, writes `?view=` with `replaceState`, and on show renders RP if it is dirty. The initial view comes from the URL.
- [ ] **Step 3: Build `cpRpRender()`.**
  - The summary strip and filter chips, with counts per the spec.
  - The grid header and one row per award line in Order order (notes excluded).
  - Status chips use the same chip markup as the Order, so a click opens the shared picker.
  - The read-only maker cells and the artisans cell. The `+` calls the existing `cpOpenArtisanModal(caid)`.
  - Line status as text; skipped rows dimmed; the "needs an artisan" accent; the empty state.
  - Keep the active filter in a variable that survives re-renders, and escape every interpolated string with the template's `esc`.
- [ ] **Step 4: Implement `cpRpInvalidate()`.** It coalesces with `requestAnimationFrame`. When RP is hidden it sets a dirty flag. When `cpRpEditing` is true it defers until it is cleared; Task 2 calls `cpRpInvalidate()` after clearing it. Call it from:
  - `cpSetLineTracking`
  - `cpApplyRowFields`
  - the end of `cpReconcileState`, and `cpReconcileLight` if it changes rows
  - `cpAppendAwardRow` and every row-removal path
  - skip/unskip, stage/unstage/grant success, and reorder
  - the expand-row `cpSaveAward` success
  - artisan add/remove success
- [ ] **Step 5: CSS.**
  - The tab strip, summary strip, filter chips, grid (sticky header), dimmed and accent rows, and maker cell states.
  - Dark-mode rules for each.
  - At `@media (max-width:640px)`, the card layout per the spec.
  - Match the planner's existing tokens and density variables where they apply.
- [ ] **Step 6: Verify.**
  - `php -l orkui/template/default/Court_detail.tpl`
  - Render a court page via curl (log in per `reference_local_curl_auth_session`) and run `node --check` on each inline script.
  - In Chrome, on a court with mixed statuses, check:
    1. both tabs switch
    2. `?view=resources` survives a reload
    3. the counts match the Order's status bar
    4. a status change made with an RP chip appears on the Order chip, and the reverse
    5. a skip on the Order dims the line on RP
    6. dark mode and the 375px iframe harness
  - Restore any DB rows you change.
- [ ] **Step 7: Commit.**

```bash
git add orkui/template/default/Court_detail.tpl orkui/template/default/style/court-planner.css
git diff --cached --stat
git commit -m "Enhancement: Court Planner — Resource Planning tab beside Order of Court"
```

### Task 2: Inline maker assignment, Order ↔ RP maker sync, and end-to-end verification

**Files:**
- Modify: `orkui/template/default/Court_detail.tpl`, and `court-planner.css` if its rules live there

**Interfaces:**
- Consumes everything Task 1 produces: `cpRpInvalidate`, `cpRpEditing`, and the `button.cp-rp-maker` cells. It also uses `cpAcSearch`, `cpLineWrites`, `cpGetRowVersion`, `cpSetRowVersion`, `cpPendingRunAdvance`, `cpStale`, `cpSetLineTracking`, and `post`.
- Produces `cpRpSetMaker(caid, kind, mundaneId, persona)`. It is the one writer for a maker from RP: it queues the update, then updates the model and the Order inputs.

- [ ] **Step 1: Make the maker cell interactive.**
  - Clicking it, or pressing Enter or Space, swaps in a text input and a `cp-ac-dropdown` (position fixed, per the existing maker dropdowns), then sets `cpRpEditing = true`.
  - `cpAcSearch(..., onPick)` calls `cpRpSetMaker` on a pick.
  - Escape or blur with no pick restores the cell, clears `cpRpEditing`, and calls `cpRpInvalidate()`.
  - The `×` on an assigned cell calls `cpRpSetMaker(caid, kind, 0, '')`.
  - A Scrollery Print scroll cell stays non-interactive.
- [ ] **Step 2: Implement `cpRpSetMaker`.**
  1. Queue on `cpLineWrites[caid]`, the same chaining pattern `cpPickTrackOpt` uses.
  2. POST `update_award` with only `ScrollMakerId` or `RegaliaMakerId` and `RowVersion`.
  3. On status 0:
     - set the model's maker id and persona
     - set the Order inputs `cp-<kind>-maker-text-<id>` and `cp-<kind>-maker-id-<id>`
     - set the RowVersion to sent+1, matching the convention the Order's `cpSaveAward` uses (check it)
     - call `cpPendingRunAdvance`
  4. If a maker was **assigned** (id > 0) and the item's status is 0, queue a second write on the same line through `set_award_tracking_status`: scroll to 1, regalia to 1. On success call `cpSetLineTracking`.
  5. Status 9 calls `cpStale`. Any other failure calls `cpAlert`.
  6. Finally, call `cpRpInvalidate()`.
- [ ] **Step 3: Order → RP maker sync.**
  - Confirm the Order's expand Save success path and `cpApplyRowFields` update the model's maker fields, then call `cpRpInvalidate()`. If Save updates only the DOM inputs, update the model too.
  - Confirm moving a scroll to Scrollery Print, through `cpSyncScrollMakerField`, re-renders RP as "Printed by Scrollery".
- [ ] **Step 4: Verify in Chrome** on a test court, then restore its rows afterward. Use your own tab and the iframe harness for 375px, and check both themes. Check:
  1. RP: assign a scroll maker to a Not Tracked line. The scroll becomes Custom Design, and the Order chip and expand field show the maker.
  2. On the Order, edit that line's notes and Save. Reload. The maker is still the one assigned on RP.
  3. On the Order, change the regalia maker via expand Save. RP shows it without a reload.
  4. RP: clear a maker. Both tabs show it unassigned and the status is unchanged.
  5. RP: pick a maker, then immediately change that line's chip. Both land and nothing reads as stale.
  6. Open an RP maker search and wait past a heartbeat (≥15s). The input survives.
  7. A second session or tab changes a status (force the tab visible per `reference_chrome_hidden_tab_pauses_pollers`). RP updates.
  8. A Scrollery Print line shows "Printed by Scrollery". Move it to Custom Design and the cell becomes assignable.
  9. The "Needs an artisan" filter count drops as makers are assigned.
  10. No console errors.
- [ ] **Step 5: Commit.**

```bash
git add orkui/template/default/Court_detail.tpl orkui/template/default/style/court-planner.css
git diff --cached --stat
git commit -m "Enhancement: Court Planner — assign scroll and regalia makers inline on Resource Planning"
```
