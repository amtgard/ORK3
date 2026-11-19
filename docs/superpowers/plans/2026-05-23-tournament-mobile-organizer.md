# Tournament Mobile Organizer — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a tournament organizer register fighters, generate brackets, and run a tournament to completion entirely from a phone, across all tournament types, via dedicated mobile views with a manual desktop/mobile toggle.

**Architecture:** All work is inlined CSS/JS inside `orkui/template/revised-frontend/Tournametnew_index.tpl` (the project's pattern — no external bundler, no JS test harness). A shared mobile **foundation** (view-mode controller, swipe util, card-deck, bottom/action sheet) lands first; three workflow tracks (running, registration, generation) build on it. Mobile CSS keys off a `.tn-mobile` class (JS-toggled, viewport-seeded, sessionStorage-persisted) — never bare media queries — so it is forceable and QA-able on desktop. No backend/DB changes; existing `TournamentAjax/*` endpoints are reused.

**Tech Stack:** PHP/Smarty `.tpl`, vanilla JS (no frameworks), CSS custom properties, `matchMedia` + `visualViewport`, touch events. Verification is manual + Claude-in-Chrome at a mobile viewport (no automated frontend tests exist in this repo) plus the project's Comprehensive QA Protocol.

**Reference design docs (full wireframes + component maps live here — read the relevant one before each track):**
- `docs/superpowers/specs/2026-05-23-tournament-mobile-organizer-design.md` (spec)
- `docs/superpowers/specs/mobile-design/running.md`
- `docs/superpowers/specs/mobile-design/registration.md`
- `docs/superpowers/specs/mobile-design/generation.md`

**Project hard rules that apply to EVERY task (non-negotiable):**
- PHP/`.tpl`/`.js` multi-line edits: use **Python string-replace**, not the Edit tool (tab/space mismatch). Single unambiguous lines may use Edit.
- **Explicit git staging only** — never `git add -A`/`.`; never stage `class.Authorization.php` or `CLAUDE.md`.
- Dark-mode parity on every new surface; reset the global `h1–h6` gray-box (`background/border/padding/border-radius/text-shadow`) on any heading inside a sheet/card.
- Autocomplete inside a sheet/modal → `position:fixed` via `tnFixedAcPosition`; keep the custom `tn-ac-results` pattern (never jQuery UI).
- No native `title` tooltips → `data-tip` CSS pattern. Human-readable dates (flatpickr `altInput`/`altFormat`).
- Inline-script IIFE guards use a `TnConfig.*` flag, never `getElementById` at top of section.
- Commit metadata trailer: `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`. Work on branch `feature/tournament-module`.

---

## Scope notes

**In scope:** single elim, double elim, swiss, round-robin, ironman/king-of-the-hill — registration, generation, running, on mobile.

**Descoped (flag, do not build):** **score-type** tournaments. Phase A found score-type has *no renderer at all even on desktop* (no `renderScoreView`); making a non-existent view mobile-friendly is out of scope here. Tracked as a separate pre-existing gap. (Spec §8 success criteria #1 is hereby narrowed to the five types above.)

---

## File structure

| File | Responsibility | Change |
|---|---|---|
| `orkui/template/revised-frontend/Tournametnew_index.tpl` | All foundation + workflow CSS/JS, inlined | Modify (the only product file) |
| `docs/superpowers/specs/mobile-design/*.md` | Design reference | Read-only |

All new JS is namespaced under a single `TnMobile` object to avoid global collisions; all new CSS uses the `.tn-mobile` prefix gate and existing `tn-` sub-prefixes (`tn-deck-`, `tn-sheet-`, `tn-mq-` for the toggle).

---

## PHASE C0 — Shared foundation (single agent, MUST land + be approved before C1)

Everything in C1 assumes these APIs. Build and Chrome-verify each before moving on.

### Task 1: View-mode controller + toggle pill

**Files:** Modify `Tournametnew_index.tpl` (add a new inlined `<script>`/`<style>` foundation block near the top of the template's JS, after `TnConfig` is defined).

- [ ] **Step 1: Add the `.tn-mobile` gate + toggle-pill CSS**
  - Define CSS custom properties for shared mobile tokens (sheet radius, safe-area insets via `env(safe-area-inset-bottom)`, deck card gap, ≥44px touch target var).
  - `.tn-mq-toggle` floating pill: fixed bottom-right, above content, dark-mode variants, `data-tip` (no `title`). Hidden when not relevant.
  - Reset rule: `.tn-mobile .tn-sheet h1,.tn-mobile .tn-sheet h2,.tn-mobile .tn-sheet h3,.tn-mobile .tn-sheet h4 { background:transparent;border:none;padding:0;border-radius:0;text-shadow:none; }` (global gray-box trap).

- [ ] **Step 2: Implement `TnMobile.viewMode`**
  - `init()`: read `sessionStorage['tnViewMode_'+TnConfig.tournamentId]`; if unset, seed from `matchMedia('(max-width:768px)').matches`. Apply class. Add a `matchMedia` `change` listener that updates ONLY when no manual override is stored.
  - `set('mobile'|'desktop')`: toggles `.tn-mobile` on the tournament root container, persists to sessionStorage (manual override), updates the pill label, and fires a `tn:viewmodechange` CustomEvent on the root so renderers can re-render.
  - `isMobile()`: returns current state.
  - Render the toggle pill and bind its click to flip `set()`.

- [ ] **Step 3: Chrome-verify**
  - Open a tournament profile at 375px and at desktop width. Confirm: auto-engages mobile ≤768px; pill flips both ways; choice survives a reload (sessionStorage); `tn:viewmodechange` observed in console. Verify in light + dark mode.

- [ ] **Step 4: Commit** — stage only `Tournametnew_index.tpl`. Message: `Enhancement: tournament mobile view-mode controller + toggle`.

### Task 2: Swipe utility with gesture-arbitration contract

**Files:** Modify `Tournametnew_index.tpl` (foundation block).

- [ ] **Step 1: Implement `TnMobile.swipe(el, opts)`**
  - `opts`: `{onLeft, onRight, onUp, onDown, threshold=40, restraint=60}`.
  - touchstart records origin; touchmove computes dominant axis and **axis-locks** once `|dx|` or `|dy|` exceeds a small start delta; touchend fires the matching callback only if past `threshold` and within `restraint` on the cross axis.
  - **Arbitration contract (critical, used by C1):** swipe is suppressed when (a) the gesture starts on an element matching `[data-tn-no-swipe]` (drag handles, sliders), or (b) `TnMobile.dragActive === true` (set by the touch-DnD reorder while a drag is live). Document this on the function.

- [ ] **Step 2: Chrome-verify** with a throwaway test element: horizontal swipe fires left/right, vertical scroll does NOT trigger left/right (axis-lock), and a swipe starting on a `[data-tn-no-swipe]` child is ignored.

- [ ] **Step 3: Commit** — `Enhancement: tournament mobile swipe utility with gesture arbitration`.

### Task 3: Bottom-sheet + action-sheet primitive

**Files:** Modify `Tournametnew_index.tpl` (foundation block).

- [ ] **Step 1: CSS for `tn-sheet`**
  - `.tn-mobile .tn-sheet`: bottom-anchored, slides up (transform transition), max-height with scrollable `.tn-sheet-body`, sticky `.tn-sheet-header` and sticky `.tn-sheet-footer` (primary action full-width, pinned, respects safe-area inset).
  - **Keyboard-safe footer:** position the footer using `visualViewport` height so it stays above the on-screen keyboard. Provide `.tn-sheet--action` auto-height variant (content-sized, for action menus).
  - Dark-mode variants for backdrop, surface, header, footer. Headings inherit the Task-1 reset.

- [ ] **Step 2: Implement `TnMobile.sheet`**
  - `open(el, {variant, onDismiss})`: shows backdrop, slides sheet up, traps focus, binds backdrop-tap and swipe-down (via `TnMobile.swipe onDown`) to dismiss.
  - `close(el)`: reverse + `onDismiss`.
  - `actionSheet(items)`: convenience that builds a `--action` sheet from `[{label, danger?, onTap}]` and returns it (used by registration + running per-fighter menus).
  - On `tn:viewmodechange` to desktop, any open sheet reverts to its original centered-overlay behavior (sheets are a `.tn-mobile`-only presentation of existing `.tn-overlay` markup — do NOT duplicate DOM).

- [ ] **Step 3: `tnFixedAcPosition` re-anchor** — extend the existing function so the fixed autocomplete dropdown re-positions on `scroll` and `visualViewport` `resize` while open (currently snapshots once). Guard so desktop behavior is unchanged.

- [ ] **Step 4: Chrome-verify** — wrap an existing modal (e.g. `#tn-addparticipant-overlay`) in the sheet presentation at 375px: slides from bottom, body scrolls, footer stays above keyboard when an input is focused, backdrop-tap and swipe-down dismiss, autocomplete dropdown stays anchored on scroll. Light + dark.

- [ ] **Step 5: Commit** — `Enhancement: tournament mobile bottom-sheet + action-sheet primitive`.

### Task 4: Card-deck primitive with `setLead`

**Files:** Modify `Tournametnew_index.tpl` (foundation block).

- [ ] **Step 1: CSS for `tn-deck`** — vertical stack: item 0 full (`.tn-deck-card--full`), items 1..n compact (`.tn-deck-card--compact`); promotion transition (compact→full) animates height/opacity. ≥44px tap targets. Dark-mode variants.

- [ ] **Step 2: Implement `TnMobile.deck`**
  - `mount(container, {items, renderFull, renderCompact, onLeadChange})`: renders item 0 full, the rest compact; binds `TnMobile.swipe` (onLeft = advance lead, onRight = previous) and tap-on-compact = promote.
  - **`setLead(id)`** (the Bout-List jump contract from running.md): re-orders the visible window so the item with that id becomes the full lead; clamps to available items; fires `onLeadChange(id)`.
  - `update(items)`: re-render preserving current lead where possible (used after a result is recorded).

- [ ] **Step 3: Chrome-verify** with stub data: full + 2 compact visible; swipe-left promotes next; tap-compact promotes; `setLead(id)` jumps correctly. Light + dark, 375px.

- [ ] **Step 4: Commit** — `Enhancement: tournament mobile card-deck primitive`.

### Task 5: Extend the canonical bout comparator (shared by deck + Bout List)

**Files:** Modify `Tournametnew_index.tpl` — the `sideRank` helper (~line 7157) inside the Next-Up/`nextUnresolved` logic.

- [ ] **Step 1: Extend `sideRank`** to the full §4.1.1 ordering: `winners`=0, `losers`=1, `grand-final`=2, `tiebreaker-3rd`=3, `''`/null=0. Confirm the comparator sorts `round ASC → sideRank ASC → match/order ASC`. This is intentionally the single source of truth for BOTH desktop Next-Up and mobile deck/Bout-List (so they never diverge); desktop ordering changing to match is expected and correct.

- [ ] **Step 2: Chrome-verify** on a generated double-elim bracket: Next-Up order now interleaves W/L/GF per the rule; no regressions on single-elim/swiss/RR ordering.

- [ ] **Step 3: Commit** — `Bugfix: complete bout-side ordering for double-elim (winners/losers/GF/3rd)`.

**C0 GATE:** Do not start C1 until Tasks 1–5 are committed and Chrome-verified. Report the foundation API surface (`TnMobile.viewMode/swipe/sheet/deck`, comparator) back for approval.

---

## PHASE C1 — Workflow tracks (3 parallel agents, after C0 gate)

Each track reads its design doc in full first. Each commits independently and touches only its own region of the template.

### Track R — Bracket running (Match Deck + Bout List)

Reference: `docs/superpowers/specs/mobile-design/running.md`. Core decision: evolve the existing `tn-nu` Next-Up widget; reuse `quickCardHTML`/`trackCardHTML`/`submitWithBouts`/`handlePipClick`/`evaluateMajority` verbatim.

- [ ] **Task R1: Mobile Match Deck = `tn-nu` in a `tn-deck`.** Under `.tn-mobile`, render the Next-Up window through `TnMobile.deck.mount` with a 3-card window (current + next 2 on-deck — change the existing `slice(0,2)` window to `(0,3)` for the mobile path only). `renderFull` reuses the existing Quick/Track card HTML; `renderCompact` shows fighters + seeds + side label. Recording a result calls the existing submit path, then `deck.update(...)`. Chrome-verify per-type (single, double, swiss, RR): current fight full, next 2 compact, promotion on swipe, result advances deck. Commit.
- [ ] **Task R2: Bout List jump sheet + double-elim sequencer.** FIRST build the **true interleaved fought-order sequencer** (spec §4.1.1, corrected): single/swiss/RR use `round → match`; double-elim must produce `WB R1 → LB R1 → WB R2 → LB R2 → LB R3 → WB R3 → … → WB final → LB final → Grand Final → (reset) → 3rd-place`, derived from the generation round-numbering in `class.Tournament.php` (WB R1 losers→LB R1; WB round r≥2 losers→LB `(r-1)*2`; odd LB rounds internal). This sequencer becomes the single source of truth feeding BOTH the deck (R1) and the Bout List — refactor R1's deck to consume it. Then build a `tn-sheet` listing ALL bouts in that order; row format per running.md (`R1W X vs Y`, etc.), with status (done/current/on-deck/not-ready). Tap a ready/current row → `TnMobile.deck.setLead(matchId)` + close sheet; not-ready rows non-actionable. Add the "Bout List" trigger button in the mobile running header. **Verify the sequencer against actually-generated brackets** (single + double elim, power-of-2 and non-power-of-2 with byes; confirm GF/reset/3rd land last). Chrome-verify ordering + jump + statuses on a double-elim bracket. Commit.
- [ ] **Task R3: Ironman deck path.** `tn-nu` is disabled for ironman (~line 7439). Build a per-ring deck source from the existing `computeIronmanQueue`/`tnIronmanApplyWin`: lead = current king vs next challenger; add a ring selector; ensure no layout collision with the `.tn-im-timer-bar`. Chrome-verify on an ironman bracket with ≥2 rings. Commit.
- [ ] **Task R4: Record-result as sheet on mobile.** Present `#tn-recordresult-overlay` via `TnMobile.sheet` under `.tn-mobile`; ensure bout pips are ≥32px, `.tn-modal-title` heading reset holds, dark-mode pips correct. Reuse `TournamentAjax/match/{mid}/{tid}`. Chrome-verify. Commit.

### Track G — Bracket registration

Reference: `docs/superpowers/specs/mobile-design/registration.md`. Reuse existing modal DOM; present as sheets; reuse all existing endpoints.

- [ ] **Task G1: Add/Bulk/Team modals → sheets.** Under `.tn-mobile`, present `#tn-addparticipant-overlay`, `#tn-bulkadd-overlay`, `#tn-addteam-overlay` via `TnMobile.sheet` (no DOM duplication): sticky search header, scrollable body, full-width Add pinned to sticky footer. Bulk textarea becomes `flex:1` with live line-count in the button label. Chrome-verify keyboard-safe footer + autocomplete `position:fixed` re-anchor (the `tn-ac-results` custom pattern, scoped search). Light + dark. Commit.
- [ ] **Task G2: Participant list mobile reflow + status action sheet.** Two-line stacked rows (~52px) via CSS (no PHP change); replace the `position:absolute` `.tn-status-menu` with `TnMobile.sheet.actionSheet([Active/Withdrawn/DQ → tnSetParticipantStatus, Remove → tnRemoveParticipant])`. Rank pills/seed legible at 375px. Optional `tnSwipe` accelerator is additive and MUST defer to `.tn-dnd-handle` (mark handles `[data-tn-no-swipe]`). Chrome-verify. Commit.
- [ ] **Task G3: Team member add loop.** Enlarge `.tn-team-member-tag` to ≥36px with ≥32px remove targets; keep the clear-and-refocus add loop. Chrome-verify on a team bracket. Commit.

### Track B — Bracket generation

Reference: `docs/superpowers/specs/mobile-design/generation.md`. Wizard writes into existing hidden form fields so existing submit handlers/endpoints run unchanged.

- [ ] **Task B1: Step-wizard sheet.** Build a `tn-sheet` wizard (Style → Format → Participants → Seeding → Rings → Best-of → Duration[ironman-only] → Review/Generate) that drives the EXISTING hidden fields of `#tn-addbracket-overlay`/`#tn-editbracket-overlay`, so the existing submit (`addbracket`/`updatebracket`) runs unchanged. Conditional steps (duration only for ironman) re-map the progress indicator when Format changes mid-wizard without losing entered values. One decision per step, large controls, sticky Next/Back. Chrome-verify creating each bracket type end-to-end. Light + dark. Commit.
- [ ] **Task B2: Touch seed-reorder.** Extract a shared `commitReorder(orderedIds)` from the existing HTML5 `drop` body (~lines 4196–4232). Add touch handlers (touchstart long-press → lift → touchmove reposition with edge autoscroll → touchend commit) that call `commitReorder` (same `…/reorder` endpoint). While dragging, set `TnMobile.dragActive = true` (suspends swipe per C0 contract) and mark the handle `[data-tn-no-swipe]`. Chrome-verify reordering by touch on a `*-manual` seeding; confirm desktop DnD still works. Commit.
- [ ] **Task B3: Sticky Generate + regen-confirm sheet.** Surface Generate/Re-generate as a sticky action; replace the native `confirm()` (and drop the `tnRegenArm` 4s auto-commit countdown under `.tn-mobile` only) with a styled confirm sheet. Collapse secondary actions into a "⋯ More" action sheet. Chrome-verify generate + regenerate. Commit.

---

## PHASE D — QA (Comprehensive QA Protocol, min 2 cycles)

- [ ] **Task D1: SDET → Principal Arch QA → Senior FSE → QA Review → Integration Manager**, ≥2 cycles, over the full diff on `feature/tournament-module`. Each finding addressed (not triaged) per the project's polish-completeness rule.
- [ ] **Task D2: Chrome end-to-end 0→1 at 375px**, both light and dark mode, for EACH of the five types: create bracket via wizard → register fighters via sheets → touch-seed (manual) → generate → run every match via the Match Deck to a champion, using the Bout List to jump at least once. Capture a GIF of one full run.
- [ ] **Task D3: Dark-mode checklist pass** on every new surface (sheet headers vs h1–h6 pill, ghost buttons, inline colors, placeholders, segmented toggles, deck cards, toggle pill).
- [ ] **Task D4: Toggle/regression** — force desktop mode on a phone-width viewport and mobile mode on desktop; confirm no desktop regressions (tree, modals, desktop DnD, desktop Next-Up ordering after the comparator change).

---

## Self-review notes
- Spec §3 foundation → Tasks 1–4 (+comparator Task 5). §4.1 running → Track R. §4.2 registration → Track G. §4.3 generation → Track B (B2 closes the touch-DnD gap). §5 guardrails → restated as per-task rules + Phase D checks. §6 pipeline → C0 gate + parallel C1 + Phase D. §8 success criteria → Phase D tasks (narrowed: score-type descoped with rationale).
- Type consistency: `TnMobile.{viewMode,swipe,sheet,deck}`, `deck.setLead/mount/update`, `sheet.open/close/actionSheet`, `TnMobile.dragActive`, `[data-tn-no-swipe]`, `commitReorder` — names used identically across C0 and C1.
- No automated-test steps because the repo has no JS test harness; verification is Chrome + the QA protocol, matching codebase conventions.
