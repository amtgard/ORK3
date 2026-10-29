# Tournament Module — Mobile Organizer Experience

**Date:** 2026-05-23
**Status:** Design approved, pending spec review
**Goal:** A tournament organizer can go from 0→1 — register fighters, generate brackets, and run a tournament to completion — entirely from a phone, with the same ease as desktop, across **all** tournament types (single elim, double elim, swiss, round-robin, ironman/king-of-the-hill, score).

---

## 1. Context — what exists today

The tournament module is already substantially built in `revised-frontend/Tournametnew_index.tpl` with a `tn-` CSS prefix system:

- **Bracket viz**: `tnRenderBracketViz()` dispatches to `renderElimTree()` (single/double elim, horizontal SVG tree), `renderIronmanView()` (`.tn-im-*` card grid), `renderRoundTable()` (`.tn-rr-*` swiss/round-robin, with a Rounds/Matrix toggle).
- **Result entry**: inline quick-result bar (`.tn-qr-*`), full Record Result modal (`#tn-recordresult-overlay`) with 9 bout pips.
- **Registration**: Add Participant / Bulk Add / Add Team modals; participant list with status dropdowns; HTML5 drag-and-drop seed reorder.
- **Generation**: Add/Edit Bracket modals (style, method, participants, seeding, rings, best-of).
- **Existing responsive CSS**: 768px (sidebar stacks) and 480px (44px touch targets) breakpoints, momentum scrolling, `hover:none` handling. But: the elim tree only offers zoom on small screens, modals are centered overlays (not sheets), and **seed reorder is HTML5 DnD = desktop-pointer only (no touch support)**.

This is an **enhancement of an existing system**, not a greenfield build. All new work lives in `Tournametnew_index.tpl` (inlined CSS/JS) plus the existing AJAX endpoints in `controller.TournamentAjax.php`; no new backend logic is anticipated (the deck and bout list are presentational reorderings of data already returned).

### AJAX endpoints in play (no changes expected)
- `TournamentAjax/match/{mid}/{tid}` — record / fetch match result
- `TournamentAjax/bracket/{bid}/matches` — all bracket matches
- `TournamentAjax/bracket/{bid}/reorder` — seed reorder (consumed by touch DnD)
- `TournamentAjax/bracket/{bid}/addparticipant | bulkadd | addteam | removeparticipant | updateparticipantstatus`
- `TournamentAjax/tournament/{tid}/addbracket | generate | updatebracket | ...`

---

## 2. Decisions (locked with the user)

| Decision | Choice |
|---|---|
| Mobile design strategy | **Dedicated mobile views** (card/vertical), not just responsive reflow |
| Activation | **Auto by viewport + manual override** — JS body class + sessionStorage, forceable on any device |
| Gesture vocabulary | **Taps + swipe + expand** (compact↔full promotion) |
| Execution scope | **Recommendations → full implementation** (design → build → QA, end-to-end) |
| Workflow priority | **All three equally**, in parallel |

---

## 3. Shared mobile foundation (built FIRST — all tracks depend on it)

This is the contract that keeps the three workflow tracks from diverging. It must land and be approved before workflow build begins.

### 3.1 View-mode controller (`tnMobileMode`)
- Sets/removes a `tn-mobile` class on the tournament root container.
- Initializes from `matchMedia('(max-width: 768px)')`; listens for viewport changes.
- A **persistent floating toggle pill** ("Mobile view" / "Desktop view") lets the organizer force either mode on any device. Manual choice wins over auto and persists in `sessionStorage` under `tnViewMode_{tournamentId}`.
- **All new mobile CSS keys off `.tn-mobile .tn-…`**, never bare media queries — so mobile is forceable and QA-able on desktop.

### 3.2 Gesture utility (`tnSwipe`)
- `tnSwipe(el, {onLeft, onRight, onUp, onDown, threshold})` wrapping touchstart/move/end.
- Distinguishes horizontal swipe from vertical scroll (axis-lock once dominant direction is detected) so it never fights native scrolling.
- Single implementation reused by the deck and any sheet dismissals.

### 3.3 Card-deck primitive (`tn-deck`)
- A vertical stack where item 0 = **full** card, items 1..n = **compact** cards.
- Swiping left/right (or tapping a compact card) advances which item is "full"; the promoted card animates compact→full.
- Generic over content (renderFull / renderCompact callbacks) so all three workflows reuse it.

### 3.4 Sheet primitive (`tn-sheet`)
- Bottom-anchored, full-width modal that slides up; sticky header + sticky footer action; scrollable body; swipe-down or backdrop-tap to dismiss.
- Replaces centered `.tn-…-overlay` modals when `.tn-mobile` is active.
- **Autocomplete inside a sheet uses `position:fixed` via `tnFixedAcPosition`** (project hard rule — absolute positioning is clipped by the sheet's stacking context).

---

## 4. Workflow designs

### 4.1 Bracket running — "Match Deck" + "Bout List" (centerpiece)

**Match Deck** (replaces the tree on mobile; tree remains reachable):
- **Current fight** = full card: both fighters (name, seed, park/rank pills), large win/tie tap targets, bout pips for best-of, ring badge. Recording a result advances the deck.
- **On-deck**: the **next 2 fights stay visible in compact form** (names + seeds) below the current card, per the user's explicit requirement. Swiping promotes the next compact card to full.
- Per-type ordering of the deck:
  - **Elim (single/double):** deck = matches that are *ready* (both participants assigned, no result) in canonical order (see 4.1.1). **Current = first ready match; on-deck = next two ready** (or next-to-become-ready if fewer than two are currently ready).
  - **Swiss / round-robin:** deck = current round's unplayed pairings, in match order.
  - **Ironman / KotH:** deck = per-ring current king + challenger queue.

**Bout List** (jump menu — the canonical "where am I" affordance):
- A tap-opened sheet listing **every** bout in fought-order, flat.
- **Ordering rule:** `round ASC → bracket side (Winners → Losers → Grand Final) → match index ASC` — i.e. "straight down" a column then "over" to the next round. Matches the user's example:
  ```
  R1W  X vs Y
  R1W  A vs B
  R1L  Y vs B
  R2W  …
  ```
  (W/L labels appear only for double-elim; single-elim shows `R{n}`; swiss/RR show round/match; ironman shows ring/fight #.)
- Each row shows **status**: done (with result) / current / on-deck / not-ready (participants TBD).
- **Tapping a row focuses the deck on that bout** — this is how the organizer reaches a bout out of strict order (e.g. a ring frees up early). Not-ready bouts are visible but non-actionable.

**Bracket map:** the existing horizontal tree stays one tap away as an overview; not the primary running surface on mobile.

#### 4.1.1 Canonical bout ordering (single source of truth)
A single JS sequencer produces the ordered bout array consumed by **both** the deck (filtered to ready/current) and the Bout List (all bouts), so they never disagree about sequence.

**Single elim / swiss / round-robin:** a field sort suffices — `round ASC → match/order ASC` (all on `bracket_side`=''/'winners').

**Double elim — TRUE INTERLEAVED FOUGHT ORDER (corrected; supersedes the earlier naive sort idea).** A naive `round ASC → side → match` sort is WRONG here: generation stores the grand-final as `round = 1`, and winners vs losers rounds use separate, non-aligned numbering — so raw round numbers are not globally comparable. The Losers Bracket opens as soon as Winners Round 1 concludes and the brackets advance in lockstep:

```
WB R1 → LB R1 → WB R2 → LB R2 → LB R3 → WB R3 → LB R4 → LB R5 → … → WB final → LB final → Grand Final → (GF reset, if needed) → 3rd-place tiebreaker
```

This requires a **chronological sequencer** that assigns each match a global "stage" derived from the generation round-numbering in `class.Tournament.php` (WB R1 losers → LB R1; WB round r≥2 losers → LB round `(r-1)*2` [major], odd LB rounds are internal [minor]). Byes, the optional grand-final reset game, and the 3rd-place tiebreaker must land in the positions shown. This sequencer is a **Track R2 deliverable**, verified against actually-generated brackets (single/double elim of varied sizes incl. non-power-of-2). The C0 `sideRank` map remains as a within-stage tiebreaker; the existing desktop side-first order is retained until R2 ships the sequencer.

### 4.2 Fighter registration

- Add Participant / Bulk Add / Add Team open as **sheets** on mobile: sticky search at top, full-width autocomplete results, large primary "Add" action pinned at the bottom.
- Persona/park search keeps the project's **custom `kn-ac-results` / `tn-ac-results` dropdown pattern** (never jQuery UI) and proper search scoping rules.
- Participant rows become large tap targets; status change (Active / Withdrawn / DQ / Remove) via a tap-opened action sheet (and optional swipe affordance).
- Bulk paste roster gets its own full-height sheet with the textarea.

### 4.3 Bracket generation

- Add/Edit Bracket becomes a **step wizard sheet**: style → method → participants (individual/team) → seeding → rings → best-of (+ duration for ironman). One decision per step, large controls, progress indicator, sticky Next/Generate.
- **Seed reorder gains real touch support**: long-press to lift, drag to reposition, with autoscroll near edges — replacing/augmenting the desktop-only HTML5 DnD. Reuses the existing `TournamentAjax/bracket/{bid}/reorder` endpoint.
- Generate / Re-generate surfaced as a prominent sticky action.

---

## 5. Guardrails (baked into every agent brief)

From the project's hard rules — non-negotiable on every new surface:
- **Dark-mode parity** proactively (walk the dark-mode checklist: modal/sheet headers vs the global `h1–h6` pill, ghost buttons, inline colors, placeholders, segmented toggles).
- **Global `h1–h6` reset**: any heading inside a sheet/card must reset the gray-box `background/border/padding/radius`.
- **Autocomplete in sheets → `position:fixed`** via `tnFixedAcPosition`.
- **No native `title` tooltips** — use the `data-tip` CSS tooltip pattern.
- **Human-readable dates** (no raw ISO in inputs).
- **PHP edits via Python**, not the Edit tool, for any multi-line change (tab indentation). Same fallback for `.tpl`/`.js` if an Edit fails.
- **`revised.js`/inline IIFE guards** use a config flag (e.g. `TnConfig.*`), never `getElementById` at top of section.
- **Explicit git staging** — never `git add -A`/`.`; never stage `class.Authorization.php` (login-bypass hack) or `CLAUDE.md`.
- Custom `kn-ac-results` autocomplete pattern + correct search scoping.

---

## 6. Agent team & pipeline

```
Phase A — DESIGN  (parallel: 3 mobile-UX/UI specialist agents, one per workflow)
   Each agent: audit current mobile state of its workflow · concrete recommendations ·
   ASCII wireframes (compact + full states) · map every recommendation to specific
   tn- components / JS functions / line ranges · flag functional gaps (e.g. touch DnD).

Phase B — SYNTHESIS  (1 architect agent + lead)
   Reconcile the three design outputs into ONE coherent build spec.
   Finalize the shared-foundation contract (§3) so the three build tracks can't diverge.
   Resolve any cross-workflow conflicts (shared CSS tokens, z-index layering, toggle behavior).

Phase C — BUILD
   C0: Foundation first (view-mode controller, tnSwipe, tn-deck, tn-sheet) — single agent, must land before C1.
   C1: 3 parallel engineering agents (running / registration / generation) build on the foundation.

Phase D — QA  (Comprehensive QA Protocol, min 2 cycles)
   SDET → Principal Arch QA → Senior FSE → QA Review → Integration Manager.
   PLUS Chrome verification at a real mobile viewport (per project rule: Chrome only to
   verify after implementation), walking all three 0→1 flows end-to-end in light + dark mode.
```

Agents are dispatched in parallel within each phase (one per independent surface), never as a single mega-agent.

---

## 7. Out of scope (YAGNI)

- No new backend / DB schema (deck + bout list are presentational reorderings of existing match data).
- No offline / PWA / service-worker support.
- No native app.
- No changes to non-tournament templates.
- Desktop layouts unchanged except where the shared toggle is added.

---

## 8. Success criteria

1. On a phone (auto-detected) an organizer can: create a bracket via the wizard, register fighters via sheets, seed via touch drag, generate, then run every match to completion via the Match Deck — for each tournament type.
2. During running, the current fight + next 2 on-deck are always visible; the Bout List reaches any bout in canonical order with correct status.
3. The manual toggle forces mobile/desktop on any device and persists per tournament.
4. Every new surface passes the dark-mode checklist and the QA protocol's 2 cycles.
