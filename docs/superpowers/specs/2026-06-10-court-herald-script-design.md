# Court Herald Script (Printable Court Order) — Design

**Date:** 2026-06-10
**Branch:** `feature/court-planner`
**Status:** Approved design, pending spec review

## Summary

A **printable court order / herald script** for actually *running* a court. The Court
Planner lets officers plan a court, and the public Court Report shows what was given
afterward — but there is nothing for the live ceremony in between. This adds a dedicated,
print-optimized view of a court's awards in presentation order, with a per-court density
toggle (compact checklist vs. full citation blocks), reachable from the planner.

This is the **print-first v1** of the broader "run the court" thread. A live on-screen
presentation mode (advance one award at a time, mark-given as you go) is a deliberate
follow-up that will reuse this view's controller data-load path.

## Goals

- Give the herald/operator a clean sheet to read from at court, on paper, with zero signal.
- Two densities, chosen per court at print time:
  - **Compact** — a tight checklist (many awards per page), tick-as-given.
  - **Citation** — per-award blocks the herald reads aloud, including the public comment
    and the artisans to thank.
- Print cleanly to 8.5×11 regardless of the app's dark-mode state.
- Establish the controller seam the future live-presentation mode will reuse.

## Non-Goals (YAGNI for v1)

- Live on-screen advancing / mark-given (the follow-up).
- Ceremonial-phrasing templates ("Come forth, X…").
- Recipient mundane/legal names.
- Recommender credit / the recommendation reason text.
- Server-side PDF generation.
- Reordering or any mutation from the print view (read-only).

## Access & Scope

- **Route:** `Court/print/{court_id}`.
- **Authority:** gated by `Court::canManage($uid, $kingdomId, $parkId)` — the same authority
  as the planner. This is an **operator tool, not public**: the public Court Report
  (`Reports/courts`) already covers after-the-fact viewing. Unauthorized users are bounced.
- **Scope:** works for both kingdom courts (`park_id = 0`) and park courts, resolved from the
  loaded court record exactly as the planner does.

## Architecture

Deliberately small; reuses existing tested reads. No new query, no schema change.

- **Controller:** new `print()` action in `orkui/controller/controller.Court.php`.
  - Resolves the court via `Ork3::$Lib->court->getCourtDetail($court_id)`.
  - Authority check via `Court::canManage` (bounce on failure, matching `list()`/`detail()`).
  - Loads awards via `Ork3::$Lib->court->getCourtAwards($court_id)` — already returns every
    field needed (`Persona`, `ParkAbbrev`, `AwardName`, `IsLadder`, `Rank`, `PublicComment`,
    `ScrollMakerPersona`, `RegaliaMakerPersona`, `Artisans[]` (persona + contribution),
    `SortOrder`, `Status`, `PassToLocal`).
  - Resolves the heraldry/location name the same way `list()` does (prefer park heraldry,
    fall back to kingdom).
  - Renders `Court_print.tpl`.
- **Template:** new `orkui/template/default/Court_print.tpl` (alongside the other
  `Court_*.tpl` planner templates). Standalone, print-optimized. All CSS prefixed `cp-`
  (court-print); CSS + JS inlined per project convention.
- **Model:** none — DB work stays in `system/lib/ork3/class.Court.php`, which already exposes
  the needed reads.

## What Prints

- All awards with `status <> 'cancelled'`, in the planner's `sort_order` (the exact order
  `getCourtAwards()` already returns).
- Each award shows its presentation number (1-based, by sort order).
- **Pass-to-local** awards print with a small "(pass to local)" marker so the herald knows
  the award was kingdom-approved for the park to bestow.
- No status-based filtering beyond excluding cancelled; an already-`given` award still prints
  (in compact mode its checkbox can be pre-ticked for clarity — see below).

## Template: `Court_print.tpl`

### On-screen chrome (hidden in `@media print`)
- A **density toggle**: Compact ↔ Citation (radio or segmented control, `cp-` styled,
  dark-mode compatible). Default: **Compact**.
- A **Print** button (calls `window.print()`).
- Both controls live in a `cp-toolbar` that is `display: none` under `@media print`.

### Header (prints on every page via running header where supported)
- Kingdom/park heraldry (or `fa-gavel` fallback), court name, human-readable date
  (never raw ISO), event name when linked.

### Compact mode (`cp-compact`)
A tight table, many rows per page:

| Col | Content |
|---|---|
| # | presentation number |
| Recipient | persona + park abbrev |
| Award | award name + rank (only when `IsLadder`) |
| ☐ | empty checkbox to tick when given (pre-ticked if `Status = 'given'`) |

`font-variant-numeric: tabular-nums` on the number column.

### Citation mode (`cp-citation`)
One block per award:
- **Recipient** persona (+ park abbrev), prominent.
- **Award** name + rank (rank only when ladder).
- **Citation** — the award's `PublicComment`, rendered as the read-aloud line. Omitted
  cleanly when empty.
- **Artisans to thank** — a single line assembled from, when present: scroll maker
  (`ScrollMakerPersona`), regalia maker (`RegaliaMakerPersona`), and each `Artisans[]` entry
  (persona, with contribution in parentheses when set). Omitted entirely when there are none.
- Pass-to-local marker when `PassToLocal`.

### Footer
- Generated-on date (human-readable) and page numbers via print CSS
  (`@page` / running footer where supported; a static footer line otherwise).

### Print CSS
- `@media print`: force white background / black text on all `cp-` surfaces (never inherit
  dark mode), hide `cp-toolbar`, avoid breaking an award block across a page
  (`break-inside: avoid` on citation blocks and compact rows), set sensible 8.5×11 margins.
- On-screen (`@media screen`): dark-mode compatible per the project checklist — heading
  resets (orkui.css h1–h6 pill leak), readable muted text, no inline `color:` that breaks in
  dark mode.

## Entry Point

A **"Print Court Order"** action on the planner, `Court_detail.tpl`:
- `canManage`-gated (it renders only inside the already-gated planner page, so no extra
  server check is needed, but the button is omitted if the planner ever renders read-only).
- Opens `Court/print/{court_id}` in a new browser tab (`target="_blank"`).
- Placed in the planner hero/action area near Publish / Mark Complete.

## Data Flow

1. `controller.Court::print($court_id)` → `getCourtDetail` → `canManage` gate.
2. `getCourtAwards($court_id)` → award rows (already sorted, already carrying every needed
   field).
3. Template renders both density layouts inline; the toggle shows/hides via CSS class on a
   wrapper (no AJAX, no reload). Print uses whichever density is active.

## Error Handling

- Missing/invalid court → bounce or "court not found" page, matching `detail()`'s behavior.
- Unauthorized → bounced by the `canManage` gate (same as the rest of the planner).
- Empty court (no non-cancelled awards) → a clear "No awards to present" empty state rather
  than a blank sheet.

## Testing

- **Authority:** non-authorized user is bounced from `Court/print/{id}` (kingdom + park
  scope); the planner button is absent when not authorized.
- **Data parity:** printed rows match `getCourtAwards()` for the court, in `sort_order`,
  excluding cancelled; pass-to-local marker matches `PassToLocal`.
- **Citation contents:** public comment renders as the citation; artisans line assembles
  scroll maker + regalia maker + contributors and is omitted when none; empty public comment
  omits the citation line.
- **Compact contents:** checkbox column present; ladder rank shown only for ladder awards;
  already-given rows pre-ticked.
- **Print fidelity:** prints white-on-black-free at 8.5×11; toolbar hidden in print; award
  blocks don't split across pages; toggle drives which density prints.
- **Dark mode:** on-screen walk of toolbar, toggle, headings, citation blocks, compact table
  per the dark-mode checklist; verify print still forces light.
- **Conventions:** `.tpl` is plain PHP (no Smarty); human-readable dates (no raw ISO);
  no native `title` tooltips; CSS prefixed `cp-`.

## Build Sequence

1. `controller.Court::print()` action (auth + loads + render).
2. `Court_print.tpl` — header, both density layouts, toggle, print CSS, empty state.
3. "Print Court Order" button on `Court_detail.tpl`.
4. Verification (curl the route where the court schema exists; print/dark-mode walk-through).

## Notes / Risks

- Local dev cannot fully exercise this: the local `ork` DB does not have the court tables
  applied (court migrations are not present locally). Verify on an environment where the
  court schema exists; otherwise rely on lint + inspection + the public-endpoint pattern.
- Running headers/footers with real page numbers vary by browser print engine; the design
  degrades gracefully to a static footer line if `@page` counters aren't honored.
- This view is the data seam for the deferred **live presentation mode**; keep the
  controller load path clean so that mode can reuse it without refactoring.
