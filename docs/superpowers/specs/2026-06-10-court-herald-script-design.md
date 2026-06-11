# Court Herald Script (Printable Court Order) — Design

**Date:** 2026-06-10
**Branch:** `feature/court-planner`
**Status:** Approved design (revised after discovering the existing Court Script), pending spec review

## Summary

Upgrade the Court Planner's existing **"Court Script"** print feature into a proper herald
script with a per-court **density toggle** (compact checklist vs. full citation blocks) and
the right content (public comment + artisans, not internal notes/reason).

The Court Planner already ships a basic "Court Script" button (`cpOpenScript()` +
`#cp-script-overlay` in `Court_detail.tpl`) that prints a fixed table of awards using the
recommendation **reason**/**notes**. That is the wrong content per this design's decisions,
has no density choice, prints internal notes (a privacy leak), and is only available once the
court is published/complete. This work **enhances that existing feature in place** rather than
building a parallel print route.

This is the **print-first v1** of the broader "run the court" thread. A live on-screen
presentation mode (advance one award at a time, mark-given as you go) is a deliberate
follow-up.

## Goals

- Two densities, chosen per court at print time, with an on-screen preview to proofread before
  printing:
  - **Compact** — a tight checklist (many awards per page), tick-as-given.
  - **Citation** — per-award blocks the herald reads aloud: the **public comment** and the
    **artisans to thank**.
- Print cleanly to 8.5×11 regardless of the app's dark-mode state.
- Stop printing internal `notes` and the recommendation `reason` (the current behavior).
- Make the script available in any court status (including draft), so a herald can print the
  order before the court is published.

## Non-Goals (YAGNI for v1)

- Live on-screen advancing / mark-given (the follow-up).
- Ceremonial-phrasing templates ("Come forth, X…").
- Recipient mundane/legal names.
- Recommender credit / the recommendation reason text.
- Server-side PDF generation.
- A separate `Court/print/{id}` route, controller action, template, or query (the original
  draft of this spec proposed these; rejected after finding the existing in-page Court Script,
  which already has every field it needs client-side).

## Why enhance in place (not a new route)

`Court_detail.tpl:1365` already emits the full `getCourtAwards()` result to the client as
`window.courtAwards`, including `Persona`, `ParkAbbrev`, `AwardName`, `IsLadder`, `Rank`,
`PublicComment`, `ScrollMakerPersona`, `RegaliaMakerPersona`, `Artisans[]` (persona +
contribution), `PassToLocal`, `Status`, and `SortOrder`. The script is built client-side from
this array. Therefore **no backend change is required** — no controller action, no new query,
no new template, no schema change. All work is contained in `Court_detail.tpl`.

## Scope

- **One file:** `orkui/template/default/Court_detail.tpl`.
- **No backend changes.** `getCourtAwards()` already returns everything needed and the planner
  already emits it to JS.

## What changes in `Court_detail.tpl`

### 1. "Court Script" button availability
Currently the button is rendered only when `in_array($courtSt, ['published','complete'])`
(around line 916). Move it out of that gate so it renders for **all** court statuses
(`draft`, `published`, `complete`). It stays inside the `canManage`-gated planner page, so no
extra auth is needed.

### 2. Overlay becomes a visible preview modal
Today `#cp-script-overlay` is `display:none` on screen and only appears via `@media print`.
Convert it into an on-screen **preview modal** shown when the button is clicked, containing:
- A header: court name + human-readable date (reuse the existing title/date logic).
- A **density segmented toggle**: `Compact | Citation` (default **Compact**). Re-renders the
  body on change; remembers the last choice for the session (simple JS variable, no
  persistence needed).
- The rendered **script body** (`#cp-script-body`) for the active density.
- Actions: **Print** (`window.print()`) and **Close**.

The modal chrome (toggle, action buttons, backdrop) is hidden under `@media print`; only the
script body prints.

### 3. Compact density (`cp-script-compact`)
A tight checklist table over `courtAwards.filter(a => a.Status !== 'cancelled')` in array order
(already `sort_order`):

| Col | Content |
|---|---|
| # | 1-based presentation number |
| ☐ | checkbox; rendered checked when `a.Status === 'given'` |
| Recipient | `a.Persona` + `a.ParkAbbrev` (when set) |
| Award | `a.AwardName` + ` (Rank N)` only when `a.IsLadder` and `a.Rank` |

Pass-to-local awards (`a.PassToLocal`) append a small "(pass to local)" marker after the award.

### 4. Citation density (`cp-script-citation`)
One block per non-cancelled award, in order:
- **Recipient** — `a.Persona` (+ `a.ParkAbbrev` when set), prominent.
- **Award** — `a.AwardName` + ` (Rank N)` only when ladder.
- **Citation** — `a.PublicComment`, rendered as the read-aloud line. Omitted cleanly when
  empty. (Do **not** fall back to `RecReason` or `Notes`.)
- **Artisans to thank** — a single line assembled, when present, from: `a.ScrollMakerPersona`
  (labelled "scroll"), `a.RegaliaMakerPersona` (labelled "regalia"), and each `a.Artisans[]`
  entry (`Persona`, with `Contribution` in parentheses when set). Omitted entirely when there
  are none.
- Pass-to-local marker when `a.PassToLocal`.

All dynamic strings pass through the existing `esc()` helper used by `cpOpenScript()`.

### 5. Remove reason/notes from the printed output
Delete the `RecReason` / `RecByPersona` / `Notes` rendering currently in `cpOpenScript()`.
Internal notes must never print; the citation is `PublicComment` only.

## Print & Dark-Mode CSS

- **Print (`@media print`):** keep the existing `body > *:not(#cp-script-overlay)
  { display:none }` approach. Within the overlay, hide the modal chrome (toggle + action
  buttons + backdrop), show only `#cp-script-body`, force white background / black text, set
  `break-inside: avoid` on citation blocks and compact rows, and sensible 8.5×11 margins.
- **On-screen (`@media screen`):** the preview modal is dark-mode compatible per the project
  checklist — heading resets (orkui.css h1–h6 pill leak), readable muted text, segmented
  toggle styling for light + dark, backdrop. The planner already defines dark-mode rules for
  its `cp-` surfaces; follow the same `html[data-theme="dark"] .cp-script-*` pattern.

## Data Flow

1. Operator clicks **Court Script** on the planner (any status).
2. `cpOpenScript()` opens the preview modal and renders the active density from
   `window.courtAwards` (no AJAX).
3. Toggling density re-renders `#cp-script-body` from the same array.
4. **Print** invokes `window.print()`; print CSS shows only the script body.

## Error / Empty States

- Empty court (no non-cancelled awards) → the modal body shows a clear "No awards to present"
  message rather than an empty sheet.
- The button is always available to `canManage` users; a draft court simply prints its current
  planned order.

## Testing

- **Content:** citation blocks show `PublicComment` and the assembled artisans line; no
  `RecReason`/`Notes` appears anywhere in the output; empty public comment omits the citation
  line; an award with no makers/contributors omits the artisans line.
- **Compact:** checkbox column present; checked when `Status==='given'`; ladder rank shown
  only for ladder awards; pass-to-local marker present when flagged.
- **Density toggle:** switching Compact ↔ Citation re-renders; the active density is what
  prints.
- **Availability:** the Court Script button renders for a draft court (previously hidden).
- **Print fidelity:** prints white-on-black-free at 8.5×11; modal chrome hidden in print;
  blocks/rows don't split across pages.
- **Dark mode:** on-screen walk of the preview modal, toggle, headings, citation blocks,
  compact table per the dark-mode checklist; print still forces light.
- **Conventions:** `.tpl` is plain PHP (no Smarty); human-readable dates (no raw ISO); no
  native `title` tooltips on new controls; existing `cp-` prefix retained.

## Build Sequence

1. Un-gate the Court Script button (render in all statuses).
2. Convert `#cp-script-overlay` into a preview modal with header + density toggle + Print/Close
   + `#cp-script-body`; add on-screen + dark-mode CSS.
3. Rewrite `cpOpenScript()` to render the active density; add `cpRenderScript(density)` with
   the compact and citation builders; remove reason/notes rendering.
4. Update `@media print` CSS to show only `#cp-script-body` and hide modal chrome; add
   `break-inside: avoid` + light-force.
5. Empty-state handling.
6. Verification (browser print + dark-mode walk on an environment where the court schema
   exists; lint the template).

## Notes / Risks

- Local dev cannot fully exercise this: the local `ork` DB does not have the court tables
  applied. Verify on an environment where the court schema exists; otherwise rely on lint +
  inspection.
- Removing `RecReason`/`Notes` from the script is an intentional behavior change (the current
  script prints internal notes, a privacy leak) aligned with this design's content decisions.
- This preview modal is a natural starting point for the deferred **live presentation mode**;
  keep `cpRenderScript()` data-driven so that mode can reuse it.
