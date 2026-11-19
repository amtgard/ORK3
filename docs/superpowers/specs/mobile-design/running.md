# Mobile Design — Bracket Running ("Match Deck" + "Bout List")

**Workflow:** 4.1 / 4.1.1 of `2026-05-23-tournament-mobile-organizer-design.md`
**Surface file:** `orkui/template/revised-frontend/Tournametnew_index.tpl` (8243 lines, inlined CSS/JS, `tn-` prefix)
**Scope:** Design only. Assumes the shared foundation primitives (`.tn-mobile` body class, `tnSwipe`, `tn-deck`, `tn-sheet`) already exist per §3.

---

## 0. Headline finding — the Match Deck already has a desktop ancestor

There is an existing **"Next Up" widget** (`tnRenderNextUp`, prefix `.tn-nu-*`, lines **7120–7539**, CSS **6949–6992**) that is 70% of the Match Deck already:

- Renders **NOW** + **ON DECK** cards (`labels = ['NOW','ON DECK']`, line 7474; only `slice(0,2)`, line 7471). The spec's "current + next 2" means we extend this to `slice(0,3)`.
- Has **two modes** persisted in `localStorage` (`tn_nu_mode_{tid}`): **Quick Win** (one-tap `1-wins`/`2-wins`/`tie` + `⋯` more) and **Track Fights** (live bout pips with auto-commit on majority). These ARE the spec's quick-result and full-pip flows (§4.1 item 5) — already wired to `TournamentAjax/match/{mid}/{tid}`.
- Has the canonical comparator already (`nextUnresolved` → `sideRank`, lines **7147–7169**).
- Is wrapped around `tnRenderBracketViz` so it repaints on every refresh (lines 7522–7538).
- **Is explicitly disabled for ironman** (`if (method === 'ironman'){ nuHost.innerHTML=''; }`, line 7439) and for setup/complete/finalized brackets (7441).

**Design decision: the mobile Match Deck is the `tn-mobile` evolution of `tn-nu`, not a new component.** Reuse `tnRenderNextUp`, `quickCardHTML`, `trackCardHTML`, `nextUnresolved`, `submitWithBouts`, `handlePipClick`, `evaluateMajority` verbatim where possible; the deck is mostly CSS (`.tn-mobile .tn-nu-*`) + three behavioral additions (3-card window, swipe-to-promote, Bout List sheet + jump). This keeps the deck and the desktop widget from diverging and minimizes new JS.

Two gaps the deck must close: (a) **`nextUnresolved`'s `sideRank` is missing `tiebreaker-3rd` and `tiebreaker`** (line 7157 only has `winners/'' /losers/grand-final`) — must extend to match §4.1.1 exactly (`tiebreaker-3rd`=3); (b) **ironman has no deck path** — it needs its own per-ring deck source (§4.1 type 5 below).

---

## 1. Current mobile-state audit (what breaks on a phone today)

### Cross-type (all bracket viz)
- **`renderElimTree` is a horizontal SVG tree** (`renderElimTree`, line 4423; rounds laid out left→right via `.tn-bv-round min-width`). On a 375px phone it relies on **pan + zoom only** (`.tn-bv-zoom-controls`, lines 764–767). Running a match requires pinch-zoom-find-tap — the core failure the deck fixes.
- **Quick-result is a click-to-expand inline bar** (`.tn-qr-bar` injected on match-box click, lines 5035–5081). Buttons are `padding:4px 10px; font-size:11px` (line 861) — **below the 44px touch target**; the 480px breakpoint never enlarges `.tn-qr-btn` (only `.tn-btn`/`.tn-btn-sm`, lines 796–797). Tapping the tiny `tn-bv-match` inside a zoomed-out tree is error-prone.
- **The Record Result modal is a centered overlay** (`#tn-recordresult-overlay`, line 2490, `width:460px;max-width:calc(100vw-40px)`). Not a bottom sheet → small targets, far reach, and the `<select id="tn-rr-result">` (line 2537) is a native picker mid-screen. Pips are `24px` (line 263), bumped to `32px` only at 480px (line 812) — still under 44px.
- **Hover tooltips on match boxes** (`mouseenter`/`mousemove` → `tnShowTooltip`, lines 4972–4996) **never fire on touch** — park/rank/bout detail is invisible on mobile. Must surface inline on the card.
- **Reset uses a hover-revealed button** (`.tn-bv-reset-btn opacity:1` only on `:hover`); `@media (hover:none)` forces it visible (line 824) but it overlaps tiny boxes.
- **No "where am I"** affordance: with the tree zoomed, an organizer cannot see fought-order or jump to a freed ring. This is exactly the Bout List gap.

### Per-type
- **Single/double elim:** tree only; `renderSection`/`buildMatchBox` (4807/4874) produce desktop-sized boxes. `tn-nu` deck already covers elim running but caps at 2 cards and has no swipe/jump.
- **Swiss / round-robin:** `renderRoundTable` (6272) has a Rounds/Matrix toggle. Matrix gets a horizontal-scroll shadow hack at 480px (lines 718–723) — readable but **not actionable for recording** (cells are clickable but 36px and require horizontal scrolling, lines 724–726). Round cards reflow to 100% width at 768px (line 788), which is fine for viewing but is a long scroll with no current-fight focus.
- **Ironman / KotH:** `renderIronmanView` (5263) is a per-ring card **grid** (`.tn-im-grid repeat(auto-fill,minmax(110px,1fr))`, line 349) + a quick-entry input (`.tn-im-qe-input`, type a winner). The timer bar stacks at 480px (line 806). The grid is tap-to-challenge but **there is no "current king vs next challenger" focused card** — the organizer must scan the grid. And `tn-nu` is disabled here, so ironman has **no deck at all** today.

---

## 2. Match Deck design (compact + full, ~375px)

The deck lives in the existing `#tn-nextup` host (`nuHost`, line 7428), restyled under `.tn-mobile`. It sits **above** the (collapsed) bracket map. Three cards: item 0 **FULL** (current fight), items 1–2 **COMPACT** (on-deck), per the `tn-deck` primitive contract (item 0 full, rest compact, swipe/tap to promote). The deck array = `nextUnresolved(bd)` (ready matches in canonical order); `slice(0,3)`.

The FULL card respects the current `tn-nu` **mode** (Quick Win vs Track Fights). Both states shown.

### 2a. FULL card — Quick Win mode (default)

```
┌─────────────────────────────────────────┐
│  ● NOW            Round 2 · Match 3       │  ← .tn-nu-pos-label.tn-nu-now + .tn-nu-match-num
│  ───────────────────────────────────────  │     (side chip appended for L/GF — see §3)
│  🟦 Ring 2                          ⋯     │  ← ring badge (new) + ⋯ opens full sheet
│                                           │
│   ┌───┐                                   │
│   │ 3 │  Sir Gawain                       │  ← seed pill (.tn-nu-p-seed) + name
│   └───┘  Iron Mountains · Knight          │  ← park/rank pills (from tooltip data, now inline)
│                                           │
│        — vs —                             │
│                                           │
│   ┌───┐                                   │
│   │ 6 │  Mordred                          │
│   └───┘  Wolf's Den · Squire              │
│                                           │
│  ┌──────────────────┐ ┌────────────────┐ │  ← large win targets (≥48px tall)
│  │  Sir Gawain wins │ │  Mordred wins  │ │     .tn-nu-btn-p1 / -p2, full-width split
│  └──────────────────┘ └────────────────┘ │
│  ┌─────────┐  ┌──────────────────────┐    │
│  │   Tie   │  │  Track fights / more  │   │  ← Tie (.tn-nu-btn-tie) + open Track/sheet
│  └─────────┘  └──────────────────────┘    │
└─────────────────────────────────────────┘
   ‹ swipe ›   ● ○ ○    (3-dot deck position)
```

### 2b. FULL card — Track Fights mode (best-of pips)

Reuses `trackCardHTML` (7221) + `handlePipClick`/`evaluateMajority` (auto-commit at mathematical majority). Pips enlarged to ≥40px under `.tn-mobile`.

```
┌─────────────────────────────────────────┐
│  ● NOW            Round 2 · Match 3       │
│  🟦 Ring 2   ·   Best of 5            ⋯   │
│  ───────────────────────────────────────  │
│      Sir Gawain          Mordred          │
│      seed 3              seed 6           │
│                                           │
│     ◯  ◯  ◯  ◯  ◯      ← P1 pips (tap)    │  ← .tn-nu-track-pips[data-side=1], ≥40px
│        ─── vs ───                         │
│     ●  ●  ◯  ◯  ◯      ← P2 pips          │     filled = bout won
│                                           │
│        Bouts:  Gawain 0 — 2 Mordred       │  ← live tally
│  ┌─────────────────────────────────────┐ │
│  │      End · Mordred wins             │ │  ← .tn-nu-btn-end-show (appears once ≥1 bout)
│  └─────────────────────────────────────┘ │
└─────────────────────────────────────────┘
```
Auto-commit (existing `evaluateMajority`, 7306) fires when a side is mathematically ahead — no extra tap, matching desktop behavior. The 2s soft auto-commit countdown logic (Task 13, 7541+) applies only to the modal, not the inline deck; keep that division.

### 2c. COMPACT on-deck cards (items 1–2)

```
┌─────────────────────────────────────────┐
│ ON DECK   R2·M4   ②Lancelot  vs  ⑤Tristan │  ← single row: chip + match num + names+seeds
└─────────────────────────────────────────┘
┌─────────────────────────────────────────┐
│ UP NEXT   R2·M5   ④Percival  vs  ⑦Bedivere│
└─────────────────────────────────────────┘
```
- Compact = `quickCardHTML` with no action buttons (the existing card already collapses on mobile via line 6995 `.tn-nu-card{flex-direction:column}` — for the deck we instead use a **single-line** compact variant `.tn-mobile .tn-nu-card-compact`).
- **Promotion:** tapping a compact card OR `tnSwipe(deckEl,{onLeft,onRight})` advances which match is item 0; the promoted card animates compact→full (handled by `tn-deck` primitive). After a result is recorded, `submitWithBouts`/`tnSubmitQuickResult` → `refreshBracket` → `tnRenderNextUp` re-slices, so the next ready match auto-promotes to NOW (existing behavior, just now 3-wide).
- Labels extend the existing `['NOW','ON DECK']` to `['NOW','ON DECK','UP NEXT']`.

### Guardrails on the deck
- **Dark mode:** `tn-nu` already has a dark block (7001+). New `.tn-mobile` rules and the ring badge / park-rank pills MUST get `html[data-theme="dark"]` parity (segmented Quick/Track toggle, compact card bg, pip borders).
- **h1–h6 reset:** the deck uses no heading tags (`.tn-nu-title` is a span) — keep it that way. If any sheet/card introduces an `<h*>`, reset `background/border/padding/radius` (orkui.css pill trap).
- **No native title:** card detail uses `data-tip` already (`tn-nu-btn` has `data-tip`, 7211). Park/rank goes **inline**, not as a title.
- **Human-readable:** no dates on the deck; N/A here.

---

## 3. Bout List jump menu (the "where am I" sheet)

A `tn-sheet` opened from a persistent **"Bout List"** pill in the deck header (and from the `⋯` overflow). Body = **every** bout, flat, in canonical fought-order. Source array = the SAME comparator as the deck but **unfiltered** (all matches, not just ready). This is §4.1.1's single-source-of-truth guarantee.

```
┌───────────────────────────────────────┐
│  ▔▔▔▔   (grab handle, swipe-down close) │
│  Bout List              23 bouts   ✕    │  ← sticky header (span, not <h*>)
│  ───────────────────────────────────── │
│  ✓ R1   Gawain  def. Mordred    3–1     │  ← done: result + bout score, muted
│  ✓ R1   Lancelot def. Tristan   3–0     │
│  ✓ R1L  Mordred def. Tristan    3–2     │  ← double-elim Loser side → "R1L"
│  ▶ R2   Gawain  vs  Lancelot   ● NOW    │  ← current: green left-border, tap = focus deck
│  ◷ R2   Percival vs Bedivere   ON DECK  │  ← on-deck: amber dot
│  ◷ R2L  Tristan  vs  (TBD)              │  ← on-deck but
│  ○ R3   (winner R2) vs (TBD)            │  ← not-ready: gray, non-tappable, italic
│  ───────────────────────────────────── │
│  [  Jump to current fight  ]            │  ← sticky footer action → scroll+focus NOW
└───────────────────────────────────────┘
```

**Row format:** `{status glyph}  {round label}  {p1} {verb} {p2}  {trailing}`
- **Round label** per type (matches `headLine`, 7190): single-elim `R{n}`; double-elim `R{n}` / `R{n}L` (Losers) / `GF`(grand final) / `3rd` (tiebreaker-3rd); swiss/RR `R{n}`; ironman `Ring{n} #{fight}`.
- **Ordering rule (§4.1.1, exact):** `round ASC` → side weight `winners=0, losers=1, grand-final=2, tiebreaker-3rd=3, ''=0` → `match`/`order` ASC. = the corrected `nextUnresolved` `sideRank` map (extended). Reuse it; do not write a second sort.
- **Status states:** `✓ done` (has Result; show winner `def.` loser + bout score from `m.Bouts`/`m.Score`), `▶ current` (= deck item 0), `◷ on-deck` (deck item 1–2 OR ready-not-current), `○ not-ready` (a participant is null → "TBD"/"Awaiting Rd n" reusing `buildMatchBox` await label logic, 4928).

**Tap behavior:** tapping a **ready** row sets the deck's item-0 to that MatchId and closes the sheet (this is how the organizer reaches a bout out of strict order — e.g. a ring frees early). Implemented as a `tnDeckFocus(matchId)` that reorders the deck window to lead with that match. **Not-ready rows are non-actionable** (no tap handler, `pointer-events` off, muted).

### Guardrails on the sheet
- **h1–h6 trap:** header is a `<span>`, not `<h2>` — avoids the orkui.css gray-box pill. If a heading is used, reset it.
- **Dark mode:** done/current/on-deck/not-ready colors need dark variants (reuse `tn-bv-resolved` green `#f0fff4`→dark `#22543d` family already in the dark block).
- **No native title; data-tip** for the bout-score pill (existing `.tn-bout-score-pill` already uses `data-tip`, line 5013).
- **No autocomplete here** → the position:fixed rule doesn't apply to this sheet (it does apply to the registration sheets — out of this workflow).

---

## 4. Per-type deck ordering (what populates item 0 + on-deck)

All four use ONE comparator (the corrected `nextUnresolved`/`sideRank`); they differ only in the **filter** applied and the round/side labels.

| Type | Deck source (filter on ordered array) | item 0 (NOW) | on-deck (1–2) | Notes / source fn |
|---|---|---|---|---|
| **Single elim** | ready: both participants assigned, no Result | first ready match | next two ready | `nextUnresolved`, 7147. Side always `winners`. |
| **Double elim** | same ready filter | first ready by `round→side(W<L<GF<3rd)→match` | next two ready | sideRank MUST include `losers/grand-final/tiebreaker-3rd` (fix line 7157). |
| **Swiss / round-robin** | current round's unplayed pairings, in match order | first unplayed in active round | next two in round | active round from `tnRRActiveRound_{bid}` (line 6397). When round completes, deck rolls to next round's pairings. |
| **Ironman / KotH** | **per ring:** current king + challenger queue | king vs head-of-queue **for the focused ring** | next 2 challengers in that ring's queue | `computeIronmanQueue` (5192) already produces the ordered challenger array; king from `computeIronmanStats`. **New deck source — `tn-nu` is disabled for ironman today (7439).** Multi-ring → one deck per ring or a ring selector chip in the deck header. Record via existing `.tn-im-qe-input` flow / `tnIronmanApplyWin` (5225), reused inside the card. |

For **score** tournaments (mentioned in the spec goal, no dedicated renderer found) the deck falls back to the elim/RR comparator on whatever matches exist; flag as an open question (§7).

---

## 5. Result recording on mobile (reuse existing AJAX only)

Endpoint for all non-ironman: **`POST TournamentAjax/match/{mid}/{tid}`** with `Result` / `Score` / `Bouts` (existing contract, lines 6769, 6905, 7337). Ironman win: same endpoint via the quick-entry flow (`tnIronmanApplyWin`, 5225). **No new endpoints** (spec §4.1 item 5, §7 YAGNI).

| Flow | Desktop today | Mobile adaptation |
|---|---|---|
| **Quick win** | `tn-qr-bar` inline buttons (5057–5076) AND `tn-nu` Quick mode (`quickCardHTML`, 7196) → `tnSubmitQuickResult` (6895) | The deck's FULL card Quick mode IS this — just larger targets. Drop the tiny `tn-qr-bar` on mobile (it's redundant with the deck); keep it on desktop. |
| **Full pips / best-of** | `tn-nu` Track mode (`trackCardHTML`+`handlePipClick`+`evaluateMajority`, 7221/7373/7306) → `submitWithBouts` (7319) | Track mode in the deck FULL card; pips ≥40px. Existing auto-commit-on-majority preserved. |
| **Forfeit / DQ / edge** | Record Result modal `<select>` (2537) | `⋯` "more" opens the modal **as a `tn-sheet`** under `.tn-mobile` (bottom-anchored). Same `tnOpenRecordResult` (6710); only the container chrome changes. The `<select id="tn-rr-result">` stays native (good mobile picker). |
| **Reset** | hover `.tn-bv-reset-btn` (5084) | Surface as a row action in the Bout List sheet on a **done** row (tap → existing 2-step confirm, 5093). Avoids overlapping tiny tree boxes. |

After any save, the existing refresh chain (`refreshBracket` 7350 → `tnRenderBracketViz` → wrapped `tnRenderNextUp` 7528) re-derives the deck — the new NOW auto-promotes. Optimistic-then-confirm with `restoreBouts` rollback (7329) already handles save failure; keep it.

**Guardrails:** modal-turned-sheet header is `<h3 class="tn-modal-title">` (line 2493) — **this WILL trip the orkui.css h1–h6 gray-box** in the sheet; the sheet rule must reset `background/border/padding/radius` on `.tn-mobile .tn-modal-title` (currently it inherits modal styling that may not cover the global pill). Dark-mode parity for the sheet backdrop + select. No native title on `⋯` (uses `data-tip` already, 7214/7238).

---

## 6. Component mapping (new mobile element → existing tn- origin)

| Mobile element | Derives from / replaces | Lines |
|---|---|---|
| Match Deck container | `#tn-nextup` host + `tnRenderNextUp` (extend slice 2→3, add swipe/jump) | 7427–7518 |
| Deck FULL card (Quick) | `quickCardHTML` (enlarge targets via `.tn-mobile .tn-nu-btn`) | 7196–7219 |
| Deck FULL card (Track/pips) | `trackCardHTML` + `handlePipClick` + `evaluateMajority` + `submitWithBouts` | 7221–7348 |
| Deck COMPACT on-deck card | new `.tn-mobile .tn-nu-card-compact` (single-line variant of `quickCardHTML` sans actions) | 7196 + new CSS |
| NOW / ON DECK / UP NEXT chips | `.tn-nu-pos-label` + `labels` array (add 3rd) | 6961–6963, 7474 |
| Quick/Track segmented toggle | `.tn-nu-toggle` / `bindToggle` (verbatim; restyle dark in mobile) | 6954–6958, 7412–7425 |
| Canonical comparator | `nextUnresolved` → `sideRank` (**extend** with `losers`/`grand-final`/`tiebreaker-3rd`) | 7147–7169 |
| Bout List sheet | `tn-sheet` primitive + the comparator unfiltered; rows derive from `headLine`+`buildMatchBox` status logic | 7190–7194, 4874–5033 |
| Bout score on done rows | `.tn-bout-score-pill` + `m.Bouts` parse | 873, 4999–5022 |
| Inline park/rank on card | the **hover tooltip** data (`p.ParkName`, bouts) surfaced inline (tooltips don't fire on touch) | 4970–4997 |
| Ring badge on card | new; data from match `Ring`/ironman ring; styled like `.tn-im-king-badge` family | 344–348 |
| Ironman deck source | `computeIronmanQueue` + `computeIronmanStats` + `tnIronmanApplyWin` (NEW: feed into deck; today `tn-nu` is disabled for ironman) | 5151–5261, 7439 |
| Swiss/RR deck source | current-round filter via `tnRRActiveRound_{bid}`; cards from `renderRoundTable` match data | 6272–6400 |
| "More" → Record Result sheet | `tnOpenRecordResult` modal re-skinned as `tn-sheet` under `.tn-mobile` | 6710–6744, 2490–2550 |
| Reset action (in Bout List) | `.tn-bv-reset-btn` 2-step confirm logic | 5084–5135 |
| Refresh-after-save chain | `refreshBracket` / wrapped `tnRenderBracketViz` | 7350–7371, 7522–7538 |

---

## 7. Open questions / risks (for the synthesis architect)

1. **Ironman is the biggest gap.** `tn-nu` is hard-disabled for ironman (line 7439) and ironman has its own timer/king/queue model + multi-ring. The deck needs a **dedicated ironman deck source** and a **ring selector** in the deck header (one deck per ring vs a ring-switcher chip). Decide: does the deck replace the `.tn-im-grid` on mobile, or sit above it? Recommend: deck = focused "king vs next challenger" per ring; grid stays below as the standings/roster view. Cross-check with whoever owns the timer bar (`.tn-im-timer-*`, 379–399) so the deck and timer don't double-stack the viewport.

2. **`sideRank` correction is load-bearing and shared.** Extending it (add `losers/grand-final/tiebreaker-3rd`) changes desktop `tn-nu` ordering too. Confirm that's desired (it should be — it makes desktop match the spec). Single comparator must be the export consumed by deck (filtered) + Bout List (unfiltered).

3. **Score-type tournaments** are named in the success criteria (§8) but have no dedicated renderer in the template (only elim/ironman/RR were found). Architect to confirm scope: does "score" reuse the RR/elim deck, or is it out for this pass?

4. **Deck vs `tn-qr-bar` redundancy.** Recommend hiding `.tn-qr-bar` inline-on-tap under `.tn-mobile` (the deck is the entry surface) to avoid two competing recorders. Confirm desktop keeps it.

5. **`tnDeckFocus(matchId)` semantics** (Bout List tap → deck): does focusing an out-of-order ready bout reorder the whole deck window, or pin just item 0 and keep canonical on-deck after it? Recommend: pin tapped match as NOW, recompute on-deck as the next canonical ready ones excluding it. Needs the foundation `tn-deck` API to expose a `focus(id)`/`setLead(id)` — flag to the foundation/C0 agent.

6. **`tn-sheet` for the Record Result modal: the `<h3 class="tn-modal-title">` gray-box trap.** The h1–h6 reset must be applied when the modal renders inside a sheet, or the header gets the orkui.css pill. Shared concern with registration + generation sheets — define a single `.tn-sheet h1..h6 / .tn-modal-title` reset token in the foundation.

7. **Viewport budget:** FULL card + 2 compact + deck header + Bout List pill + (ironman) timer bar may exceed one phone screen. Confirm the on-deck cards may scroll while NOW stays pinned (sticky), or that the deck is allowed to be the only thing above the fold with the bracket map collapsed by default.
