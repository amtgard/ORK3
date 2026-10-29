# Tournament Focus Mode (Full-Screen Running View) — Design

**Date:** 2026-05-20
**Module:** Tournament
**File touched:** `orkui/template/revised-frontend/Tournametnew_index.tpl` (only)

## Goal

Give tournament organizers a distraction-free "focus mode" for running a bracket. A
single toggle collapses the surrounding page chrome (sidebar, statistics row,
playtest warning, hero header) and expands the bracket-running area to fill the
viewport, while keeping the tabs and bracket controls fully usable. This is for
running/projecting a live tournament, not a separate page.

## Mechanism

In-page CSS "focus mode" — **not** the native Fullscreen API. A single state class
`tn-focus` toggled on the page root (the wrapper containing `.tn-layout`) drives all
visual changes through CSS. Rationale: reliable across browsers, no permission
prompt, no user-gesture requirement, and the browser tab/chrome staying visible is
acceptable for the in-app use case.

## Behavior

### When focus mode is ON (`tn-focus` present)
- **Hidden:** `.tn-sidebar`, `.tn-stats-row`, `.tn-playtest-warn`, and the tournament
  hero header.
- **Replaced:** a slim sticky top bar (`.tn-focus-bar`) appears — tournament name on
  the left, an "Exit focus" button on the right. Hidden (`display:none`) when focus
  mode is off.
- **Expanded:** `.tn-main` goes full width (sidebar gone); `.tn-bv-wrap` gains height
  so the bracket tree uses the reclaimed vertical space.
- **Kept visible & functional:** `.tn-tab-nav` (all tabs clickable), the bracket
  pills (`.tn-bk-pills`), next-up panel (`#tn-nextup`), zoom controls
  (`.tn-bv-zoom-controls`), and `#tn-bv-container`.

### When focus mode is OFF
- Page renders exactly as today. Slim bar hidden. Toggle button shows the
  "enter focus" state.

## Trigger & Exit

- **Toggle button** rendered next to `.tn-tab-nav` (icon + short label, e.g. "Focus").
  Works on whatever tab is currently open — focus mode is layout chrome, not tab
  specific. It does not force a tab switch.
- **Exit** via any of:
  - the slim-bar "Exit focus" button,
  - clicking the toggle button again,
  - pressing `Esc`.

## State / Persistence

- `tnToggleFocus()` adds/removes the `tn-focus` class on the root wrapper and writes
  `sessionStorage['tnFocusMode']` (`"1"` / removed).
- On page init, if `sessionStorage['tnFocusMode'] === "1"`, re-apply the class so a
  mid-tournament page reload stays in focus mode. Mirrors the existing
  `tnToggleBracket` sessionStorage pattern.
- The `Esc` handler only acts when focus mode is active (it must not interfere with
  modals or other `Esc` consumers when inactive).

## Dark Mode

Per project rule, dark mode is handled up front, not as follow-up. The slim bar
(`.tn-focus-bar`) and toggle button (`.tn-focus-toggle`) get dark-mode variants
matching the existing `tn-` dark styling in the main `<style>` block. Walk the slim
bar, toggle button (both states), and the expanded bracket area in dark mode before
declaring done.

## Scope of Edits — all within `Tournametnew_index.tpl`

1. **CSS** (main `<style>` block, ~lines 87–1155):
   - `.tn-focus-toggle` button styling (+ dark mode).
   - `.tn-focus-bar` slim sticky bar, hidden by default (+ dark mode).
   - `.tn-focus` state rules: hide `.tn-sidebar`, `.tn-stats-row`,
     `.tn-playtest-warn`, hero header; show `.tn-focus-bar`; expand `.tn-main`,
     `.tn-bv-wrap`.

2. **HTML:**
   - Toggle button markup next to `.tn-tab-nav`.
   - `.tn-focus-bar` markup (tournament name + Exit button), hidden by default.

3. **JS** (runtime script block):
   - `tnToggleFocus()` — toggle class + sessionStorage.
   - `tnExitFocus()` (or shared) for the exit button.
   - `Esc` keydown handler (active only in focus mode).
   - Init-time restore from `sessionStorage`.

## Non-Goals (YAGNI)

- No native Fullscreen API.
- No separate route/page.
- No per-tab focus variations.
- No server/DB changes.

## Testing / Verification

- Toggle on/off from each tab; confirm chrome hides/shows and bracket expands.
- Confirm tabs remain clickable in focus mode.
- `Esc` exits only when active; does not break modals when inactive.
- Reload while in focus mode → stays in focus mode.
- Verify slim bar + toggle + expanded bracket in dark mode.
