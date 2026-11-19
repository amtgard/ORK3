# Mobile Design — Bracket Generation Workflow

**Date:** 2026-05-23
**Workflow:** Bracket generation (Add/Edit Bracket, seed reorder, Generate/Re-generate)
**Status:** DESIGN ONLY — no product code modified.
**Spec source:** `docs/superpowers/specs/2026-05-23-tournament-mobile-organizer-design.md` §4.3
**Target file (build phase):** `orkui/template/revised-frontend/Tournametnew_index.tpl`
**Reference viewport:** 375px (iPhone SE/12 mini class)

Assumes the shared foundation (§3) already exists and is consumed, not designed here:
- `.tn-mobile` body/root class (`tnMobileMode`) — all mobile CSS keys off `.tn-mobile .tn-…`.
- `tnSwipe(el, {onLeft,onRight,onUp,onDown,threshold})` — axis-locked touch gesture utility.
- `tn-sheet` bottom-sheet primitive — sticky header + scrollable body + sticky footer, swipe-down/backdrop dismiss, replaces `.tn-overlay` when `.tn-mobile` is active.

---

## 1. Current mobile-state audit

### 1.1 Add Bracket modal (`#tn-addbracket-overlay`, `tnOpenAddBracketModal`)
- **HTML:** lines 1984–2078. Opener: lines 3085–3092. Submit: 3115–3147. Endpoint `TournamentAjax/tournament/{tid}/addbracket` (line 3083).
- **Base modal CSS:** `.tn-overlay` is a centered flex overlay (line 299); `.tn-modal-box` is a centered card with `max-height:90vh` and `width:520px;max-width:calc(100vw - 40px)` (lines 301, 1985).

**What breaks / is awkward on a phone:**
1. **Centered overlay, not a sheet.** At 375px the box is `100vw - 40px` (≈335px) floating mid-screen. Spec §3.4 mandates a bottom sheet on mobile. The modal is reachable but ergonomically wrong — the submit button sits in a footer that can fall below the fold once the keyboard opens, and there is no swipe-to-dismiss.
2. **Two-up field rows collapse but density is still high.** `.tn-field-row` is a 2-col grid (line 316) that becomes 1-col under `@media (max-width:768px)` (line 784). That helps, but the form still presents **two primary decisions at once** (Style + Format, lines 1992–2017) plus a disclosure-hidden "Advanced options" block (lines 2019–2069) with **four more controls in two more 2-up rows** + a conditional duration field + a style note. Spec §4.3 wants **one decision per step** — the current single-scroll form is the opposite.
3. **The "Advanced options" disclosure hides the very fields that matter for generation** (participants, rings, seeding, best-of, duration). On a phone the toggle (lines 2019–2023) adds an extra tap and hides seeding — the field that determines whether touch seed-reorder is even available downstream (see §1.3, `isDnd` gate at line 1682).
4. **Method-conditional logic is minimal and lives in two places.** Duration field shows only for `ironman` (open: lines 3088–3090; live `change` listener: 3094–3100). Best-of is always shown even for `score`/`ironman` where it is less meaningful. No per-step conditional reveal exists.
5. **Select dropdowns rely on the native picker** — acceptable on mobile, but the 13px `.tn-field select` (line 311) is below the comfortable 44px tap target; `@media (max-width:480px)` only bumps `.tn-btn` padding (lines 796–797), not selects.
6. **Footer buttons** (`.tn-modal-footer`, line 308) go `flex-wrap` + full-width only under 480px (lines 819–820); between 480–768px they remain right-aligned and small.

### 1.2 Edit Bracket modal (`#tn-editbracket-overlay`, `tnOpenEditBracketModal`)
- **HTML:** lines 2083–2178. Opener + auto-expand-advanced logic: lines 3155–3195. Submit: 3216–3249. Endpoint `…/updatebracket` (line 3153). Invoked from the bracket card Edit button (line 1603) which passes a JSON blob of current values.
- Same structural issues as Add. **Extra wrinkle:** it auto-expands the Advanced section when any field is non-default (lines 3172–3193) — good intent, but on mobile this produces a long single scroll that re-buries the primary Style/Format choice above a now-expanded advanced block.

### 1.3 Seed reorder — **CONFIRMED TOUCH GAP**
- **DnD init:** lines 4170–4242. List markup: `<ul class="tn-participant-list tn-dnd-list" data-bracket-id>` (line 1683), enabled only when `isDnd` (seeding ∈ `manual`/`random-manual`/`glicko2-manual`, line 1682). Each `<li data-pid>` gets `.tn-dnd-handle` (grip, line 1687) + `.tn-seed-enhanced` seed circle (line 1688).
- **Handlers attached (lines 4181–4232):** `dragstart`, `dragend`, `dragover`, `dragleave`, `drop` — **all HTML5 Drag-and-Drop API, which does not fire on touch.** There are **NO `touchstart` / `touchmove` / `touchend` handlers anywhere in the DnD module.** `li.setAttribute('draggable','true')` + `cursor:grab` (lines 4178–4179) are pointer-only affordances.
- **Result:** on a phone the grip handle is visible (`.tn-dnd-handle`, CSS line 884), the seed circle scales on `:hover` (line 879) which never triggers on touch, and **dragging a seed does nothing** — the list is effectively read-only on mobile for the exact seeding modes (`manual`/`*-manual`) where reordering is the point. This is the single biggest functional gap in this workflow.
- Reorder persistence already exists and is touch-agnostic: `POST TournamentAjax/bracket/{bid}/reorder` with `Order` (JSON array of pids) + `TournamentId` (lines 4225–4231), plus optimistic renumber (4210–4216) and revert-on-failure (`restoreOrder`, 4217–4223). **Touch only needs to drive the same reorder + reuse the same fetch.**

### 1.4 Generate / Re-generate
- **Card buttons:** Generate / Re-generate at lines 1621–1628. `tnGenerateMatches(bid,tid)` direct for first generation; `tnRegenArm(this,event)` arm-countdown for regenerate (lines 1623–1626).
- **`tnGenerateMatches`:** lines 4247–4304 — builds a stats summary (byes/rounds/rings) and gates on a blocking `confirm()` (line 4288), then `POST …/generate` (4290–4294) + full reload.
- **`tnRegenArm`:** lines 7706–7745 — a 4s arm-and-auto-commit countdown button with inline "cancel" (no `confirm()`).
- Also rendered inside the bracket viz bar via `genBtn` (lines 4388–4398), disabled with a `data-tip` when completed matches exist (line 4393).

**What breaks / is awkward on a phone:**
1. **`confirm()` (line 4288) is a jarring native dialog** — its multi-line `•`/`⚠️` body renders poorly on mobile and can't match the sheet's dark-mode styling. Acceptable as a fallback but not the designed flow.
2. **Generate is buried in a wrapping flex button cluster** (lines 1602–1632: Edit, Copy, Add Participant, Paste Roster, Generate, Delete, Run). On a 375px card these wrap to 3–4 rows; the primary "Generate" action has no visual priority and is easy to miss among 6+ buttons.
3. **No sticky surfacing.** Spec §4.3 wants Generate as a prominent sticky action; today it scrolls away with the card.

---

## 2. Step-wizard sheet (Add/Edit Bracket)

Replace the centered modal with a **`tn-sheet`-based step wizard** when `.tn-mobile` is active. One decision per step. The desktop modal (lines 1984–2178) is untouched; the wizard is a parallel mobile presentation that submits to the **same** endpoints with the **same** FormData keys (lines 3124–3132 / 3225–3234) — no field renames, no new endpoints.

**Step order (matches spec §4.3):** Style → Format → Participants → Seeding → Rings → Best-of → (Duration, ironman only) → Review/Generate.

**Conditional step logic (derived from existing rules):**
- **Duration step** appears **only when Format = `ironman`** (mirrors lines 3088–3090, 3097, 3169, 3200). For all other formats it is skipped and the step counter recomputes (e.g. "Step 6 of 6" instead of "of 7").
- **Best-of step**: shown for all, but copy de-emphasizes it for `ironman`/`score` (where bouts-per-match is less meaningful) — label hint reused from lines 2051/2151.
- **Rings step**: always shown (used by swiss round-count math, line 4270, and concurrent-ring scheduling). For `ironman` the rings value gains extra weight (per-ring king/challenger) — copy notes this.
- **Seeding step**: choosing a `*-manual` seeding (`manual`/`random-manual`/`glicko2-manual`) sets the downstream `isDnd` gate (line 1682) → the Review step shows a "You'll arrange seeds by dragging after creating" hint and deep-links to the touch reorder (§3).

### 2.1 Wireframe — Step 1: Style (~375px)

```
┌─────────────────────────────────────┐
│ ░░░░░░░░ (backdrop, tap to dismiss) ░│
├─────────────────────────────────────┤  ← tn-sheet slides up
│  ▁▁▁  (grab handle)                  │
│  Add Bracket            Step 1 of 6  │  ← sticky header (h-reset!)
│  ●━━━○──○──○──○──○                    │  ← progress dots/bar
├─────────────────────────────────────┤
│                                      │
│  WEAPON STYLE                        │
│  ┌─────────────────────────────────┐ │
│  │  ⚔  Single Sword            ◉  │ │ ← large radio rows,
│  ├─────────────────────────────────┤ │   44px+ tap targets
│  │  ⚔  Florentine              ○  │ │   (replaces <select>)
│  ├─────────────────────────────────┤ │
│  │  🛡 Sword & Shield          ○  │ │
│  ├─────────────────────────────────┤ │
│  │  🪓 Great Weapon            ○  │ │
│  ├─────────────────────────────────┤ │
│  │  🎯 Missile                 ○  │ │
│  │  …(Jugging/Battlegame/Quest/Other)│
│  └─────────────────────────────────┘ │
│                                      │
├─────────────────────────────────────┤
│  [ Back (disabled) ]      [ Next → ] │  ← sticky footer
└─────────────────────────────────────┘
```

### 2.2 Wireframe — Step 2: Format (drives conditional steps)

```
├─────────────────────────────────────┤
│  Add Bracket            Step 2 of 6  │
│  ●━━●━━○──○──○──○                     │
├─────────────────────────────────────┤
│  FORMAT                              │
│  ┌─────────────────────────────────┐ │
│  │ Single Elimination          ◉  │ │
│  │ One loss and you're out         │ │ ← one-line helper each
│  ├─────────────────────────────────┤ │
│  │ Double Elimination          ○  │ │
│  ├─────────────────────────────────┤ │
│  │ Swiss                       ○  │ │
│  │ Round Robin / Ironman / Score…  │ │
│  └─────────────────────────────────┘ │
│                                      │
│  ⓘ Ironman adds a Duration step.    │ ← info box; appears when
│                                      │   ironman picked (data-tip
├─────────────────────────────────────┤   pattern, not title=)
│  [ ← Back ]               [ Next → ] │
└─────────────────────────────────────┘
```

### 2.3 Wireframe — Step 5: Rings (numeric stepper, not raw number input)

```
├─────────────────────────────────────┤
│  Add Bracket            Step 5 of 6  │
│  ●━━●━━●━━●━━●━━○                     │
├─────────────────────────────────────┤
│  CONCURRENT RINGS                    │
│  How many matches run at once?       │
│                                      │
│        ┌────┐   ┌────┐   ┌────┐      │
│        │ −  │   │  2 │   │  + │      │ ← big ± stepper, 44px,
│        └────┘   └────┘   └────┘      │   writes to #…-rings
│                                      │
│  Swiss/RR round count is derived     │
│  from this value.                    │
├─────────────────────────────────────┤
│  [ ← Back ]               [ Next → ] │
└─────────────────────────────────────┘
```

### 2.4 Wireframe — Step 6/7: Duration (IRONMAN ONLY) + Review/Generate

```
── Duration step (only when Format=ironman) ──
├─────────────────────────────────────┤
│  Add Bracket            Step 6 of 7  │
├─────────────────────────────────────┤
│  MAX DURATION                        │
│  ┌──┐┌──┐┌──┐┌──┐┌──────┐            │
│  │5 ││10││15││20││ Custom│           │ ← chip presets (minutes)
│  └──┘└──┘└──┘└──┘└──────┘            │   + custom → number stepper
│  0 = unlimited (no time cap)         │   writes to #…-duration
│  ⓘ Shown as "15 min" not raw value.  │   (human-readable label)
├─────────────────────────────────────┤
│  [ ← Back ]               [ Next → ] │
└─────────────────────────────────────┘

── Final Review / Generate step ──
├─────────────────────────────────────┤
│  Review                 Step 7 of 7  │
│  ●━━●━━●━━●━━●━━●━━●                  │
├─────────────────────────────────────┤
│  Single Sword · Single Elimination   │ ← summary card (h-reset)
│  Individual · Random seeding         │
│  2 rings · Best of 3                 │
│  Style note: "no shields"            │
│                                      │
│  ⓘ 14 participants → 2 byes,         │ ← reuses byes/rounds math
│     4 rounds                         │   from tnGenerateMatches
│                                      │   (lines 4262–4274)
│  [ Save bracket → then add fighters ]│
├─────────────────────────────────────┤
│  [ ← Back ]       [ ✓ Create Bracket]│  ← sticky primary; calls
└─────────────────────────────────────┘     existing submit (3117)
```

**Notes on the wizard:**
- Each "Next" validates the current step (Style + Format required, mirroring lines 3121/3222) before advancing; "Back" never validates.
- The wizard writes into the **existing hidden form fields** (`#tn-addbracket-style`, `…-method`, `…-participants`, `…-rings`, `…-seeding`, `…-bestof`, `…-duration`, `…-stylenote`) so the **existing submit handler (lines 3117/3218) runs unchanged**. The radio/stepper/chip controls are a presentation layer over those inputs.
- Step transitions reuse `tnSwipe` (foundation): swipe-left = Next (if valid), swipe-right = Back. Footer buttons remain the primary affordance; swipe is an accelerator.
- **Edit mode** seeds each step's control from the JSON blob (lines 3158–3168) and lands the user on Step 1, with a "jump to step" affordance from the Review summary (replaces the desktop auto-expand-advanced behavior at lines 3172–3193, which is meaningless once everything is a step).

---

## 3. Touch seed reorder (the gap fix)

Add a **touch reorder mode** that coexists with the existing HTML5 DnD (desktop unchanged). Activated when `.tn-mobile` is present on `.tn-dnd-list` (line 1683). Reuses the **same** `data-pid` rows (line 1686), the **same** `.tn-seed-enhanced` circles (line 1688), the **same** renumber logic, and the **same** `POST …/reorder` endpoint (lines 4225–4231) with `Order` + `TournamentId`.

### 3.1 Touch event model (long-press → lift → drag → drop)

```
touchstart on li[data-pid] (or its .tn-dnd-handle):
  • record startY, startX, the touched <li>
  • start a 350ms long-press timer
  • if finger moves > ~10px before timer fires → it's a scroll;
    cancel timer, do nothing (axis-lock; never fight native scroll)

long-press timer fires (finger still down, not moved):
  • "lift": add .tn-dnd-lifted to the <li>
  • haptic hint via navigator.vibrate?.(15) (guarded, optional)
  • clone the row OR raise it: position:fixed, translateY follows touch,
    box-shadow + scale(1.03), z-index above sheet
  • freeze list height (insert a placeholder gap where the row was)
  • e.preventDefault() from here on so the page doesn't scroll

touchmove (while lifted):
  • translateY the lifted row to follow touch.clientY
  • compute target index by comparing touch.clientY against the
    midpoints of the other li[data-pid]; shift the placeholder gap
    to that index (live reflow, same visual as .tn-dnd-over, line 880)
  • EDGE AUTOSCROLL: if touch.clientY is within ~60px of the sheet
    body's top/bottom edge, scroll the sheet body by ±N px/frame via
    requestAnimationFrame loop until finger leaves the hot zone or lifts

touchend / touchcancel:
  • drop the row into the placeholder slot (list.insertBefore …,
    same DOM move as drop handler lines 4206–4207)
  • remove .tn-dnd-lifted, clear fixed positioning, stop autoscroll RAF
  • renumber seed circles (reuse renumber(), lines 4210–4216)
  • build newOrder of data-pid (lines 4215) and POST to
    TournamentAjax/bracket/{bid}/reorder (lines 4225–4231)
  • on failure → restoreOrder() (lines 4217–4223) exactly as today
```

**Key reuse points (do NOT duplicate):** the `drop`-handler body (lines 4196–4232) already contains the canonical move + renumber + save + revert. The touch handler should call into a **shared `commitReorder(list, bracketId)` helper extracted from that block** so DnD and touch persist identically. The `dragSrc` concept maps to a `liftedEl` ref.

### 3.2 Wireframe — lifted / dragging state (~375px, inside a sheet or the card)

```
┌─────────────────────────────────────┐
│  SEED ORDER (drag to arrange)        │  ← header (h-reset)
│  Long-press a fighter, then drag.    │
├─────────────────────────────────────┤
│  ⣿  ① Sir Gareth      Iron Mtn      │
│  ⣿  ② Mistress Vex     Wolf's Den   │
│ ┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄ │ ← placeholder gap (drop target)
│  ╔═══════════════════════════════╗  │
│  ║ ⣿  ③ Grimwald      Ash Keep   ║  │ ← LIFTED row: position:fixed,
│  ╚═══════════════════════════════╝  │   scale 1.03, shadow, follows
│        finger ✦                      │   touch.clientY
│  ⣿  ④ Lady Rowan      Fenwick      │
│  ⣿  ⑤ Brom            Iron Mtn      │
│                                      │
│  ▲ (autoscroll zone if near top)     │
│  ▼ (autoscroll zone if near bottom)  │
└─────────────────────────────────────┘
   ⣿ = .tn-dnd-handle grip (line 1687)
   ① = .tn-seed-enhanced circle (line 1688), renumbered live
```

- The grip handle `.tn-dnd-handle` (line 884) becomes the recommended (but not required) grab point; long-press anywhere on the row also lifts, since the handle alone is a small target.
- `@media (hover:none)` (line 823) already promotes some hover-only affordances; add a `.tn-mobile .tn-dnd-list .tn-dnd-handle` rule sized to ≥24px with more padding so it's a real touch target.
- Lifted-state styling (`.tn-dnd-lifted`) must have **dark-mode parity** — model it on the existing dark `.tn-dnd-handle` (line 1222) and seed-enhanced (gradient at line 878) tokens.

---

## 4. Generate / Re-generate — prominent sticky action

On mobile, lift Generate out of the wrapping button cluster (lines 1602–1632) into a **dedicated sticky action**:

### 4.1 Sticky generate bar (per bracket card, mobile)

```
┌─────────────────────────────────────┐
│  Bracket: Single Sword · Single Elim │
│  14 fighters · Random seeding        │
│  … participant list / seed reorder … │
│                                      │
│  (other actions collapsed into ⋯)    │ ← Edit/Copy/Add/Paste/Delete
├─────────────────────────────────────┤   move into an overflow
│  ▶  GENERATE MATCHES                 │ ← sticky bottom bar, full
└─────────────────────────────────────┘   width, calls
                                            tnGenerateMatches(bid,tid)
                                            (line 4247)
```

- Secondary card actions (Edit, Copy, Add Participant, Paste Roster, Delete — lines 1603–1631) collapse into a single **"⋯ More"** action sheet on mobile to declutter; Generate and "Run Bracket" (line 1635) stay as primary sticky/prominent buttons.
- The viz-bar `genBtn` (lines 4388–4398) keeps its existing **disabled + `data-tip`** state when completed matches exist (line 4393) — reuse the `data-tip` tooltip (NOT native `title`).

### 4.2 Re-generate confirmation on mobile (replace `confirm()` and the arm-countdown)

The desktop has two paths: blocking `confirm()` in `tnGenerateMatches` (line 4288) and the 4s arm-countdown `tnRegenArm` (lines 7706–7745). On mobile, replace both with a **confirmation `tn-sheet`** so the destructive warning is styled, readable, and dark-mode-correct:

```
├─────────────────────────────────────┤
│  ⚠  Re-generate bracket?             │ ← sheet header (h-reset),
│                                      │   danger accent
│  This bracket is ACTIVE with         │
│  12 matches and results.             │ ← reuses the warn copy at
│                                      │   lines 4283–4285
│  Re-generating will DELETE all       │
│  current matches and results.        │
│                                      │
│  Single Sword · Single Elim          │ ← reuses stats summary
│  14 fighters → 2 byes · 4 rounds     │   (lines 4262–4280)
├─────────────────────────────────────┤
│  [ Cancel ]   [ Delete & Regenerate ]│ ← sticky; primary is danger
└─────────────────────────────────────┘     → POST …/generate (4290)
```

- For **first** generation (no existing matches) the confirm sheet is informational (stats only, no destructive warning) with a single **"Generate"** primary — or skip straight to generate if we want zero friction; recommend keeping a light confirm to surface byes/rounds.
- The auto-commit countdown (`tnRegenArm`, 7737–7743) is **dropped on mobile** — an auto-firing destructive timer is hostile on touch where mis-taps are common. Explicit two-button confirm replaces it.

---

## 5. Component mapping table

| New mobile element | Derives from / replaces | Existing class / JS / line range | Endpoint (unchanged) |
|---|---|---|---|
| Bracket wizard sheet (Add) | `#tn-addbracket-overlay` centered modal | HTML 1984–2078; open `tnOpenAddBracketModal` 3085–3092; submit 3115–3147 | `…/addbracket` (3083) |
| Bracket wizard sheet (Edit) | `#tn-editbracket-overlay` centered modal | HTML 2083–2178; open 3155–3195; submit 3216–3249 | `…/updatebracket` (3153) |
| Step radio rows (Style/Format/Participants/Seeding) | native `<select>` `.tn-field select` | selects at 1995, 2009, 2028, 2041; CSS 311 | — |
| Rings ± stepper | `<input type=number id=…-rings>` | 2035 / 2135 | — |
| Best-of step | `<select id=…-bestof>` | 2052 / 2152 | — |
| Duration chips+stepper (ironman) | `#…-duration-field` conditional input | field 2061–2064; toggle 3088–3090, 3097, 3169, 3200 | — |
| Progress dots / step counter | (new) replaces `.tn-advanced-toggle` disclosure | toggle 2019–2023, 2119–2123; auto-expand 3172–3193 | — |
| Wizard footer Back/Next/Create | `.tn-modal-footer` + submit btns | footer 308; 2071–2076 / 2171–2176 | — |
| Touch reorder (`touchstart/move/end`) | HTML5 DnD (pointer-only) | DnD module 4170–4242; handlers 4181–4232 | `…/reorder` (4225) |
| `.tn-dnd-lifted` lifted-row style | `.tn-dnd-over` / `:hover` scale | 879, 880; dark 1222 | — |
| `commitReorder()` shared helper | inline `drop` body | 4196–4232 (move 4206–4207, renumber 4210–4216, save 4225–4231, revert 4217–4223) | `…/reorder` |
| Edge-autoscroll RAF loop | (new) | n/a | — |
| Sticky "Generate" bar | button cluster Generate btn | 1621–1628; `tnGenerateMatches` 4247–4304 | `…/generate` (4290) |
| Viz-bar generate btn (kept) | `genBtn` | 4388–4398 | `…/generate` |
| Regenerate confirm sheet | native `confirm()` + `tnRegenArm` countdown | confirm 4288; warn copy 4283–4285; stats 4262–4280; arm 7706–7745 | `…/generate` |
| "⋯ More" actions sheet | inline card button cluster | 1602–1632 | (各 existing) |

No new endpoints are introduced. Every mobile control writes into the existing form inputs / calls the existing JS functions.

---

## 6. Guardrails — where each applies

- **Dark-mode parity:** wizard sheet, step rows, stepper, duration chips, lifted seed row (`.tn-dnd-lifted`), sticky generate bar, regen confirm sheet, "⋯ More" sheet. Model on existing dark tokens: modal box (1028), field inputs (1035–1042), DnD handle (1222), seed-enhanced gradient (878). Walk the dark-mode checklist on every new surface (segmented step rows, ghost "Back" button, info boxes).
- **Global `h1–h6` gray-box reset:** every sheet header and summary-card heading (Step header, Review card, Seed Order header, Regen confirm header) MUST reset `background/border/padding/border-radius/text-shadow`. The existing `.tn-modal-title` uses `h3` (lines 1987, 2086) and presumably already resets — reuse that exact reset pattern for sheet headers.
- **Autocomplete in sheets → `position:fixed`:** the bracket wizard itself has **no autocomplete** (it's selects/steppers). But the downstream Add Participant / Add Team sheets (registration workflow) do, and the existing `tnFixedAcPosition` (lines 3253–3268) already handles the modal stacking-context clip — the registration agent owns that; flagged here only because the wizard's Review step deep-links to seeding/participant flows.
- **No native `title` tooltips:** keep using `data-tip` (already used on the disabled genBtn, line 4393; card buttons, lines 1606/1617/1629). The Format-step "Ironman adds a Duration step" hint and any helper text use `data-tip` / inline info boxes, never `title=`.
- **Human-readable dates/durations:** the duration field is **minutes**, not a date — display as "15 min" / "Unlimited", never bare "15"/"0" (the raw `value="0"` at line 2063 is fine in the input, but the chip labels and Review summary must read human-friendly). No date inputs exist in this workflow, so flatpickr/altInput does not apply here (it applies to tournament date in the Edit Tournament modal, out of scope). If a date input is ever added to generation, use the altInput pattern.
- **PHP/`.tpl` edits via Python** (not the Edit tool) for any multi-line change during build — this is a `.tpl` file.
- **IIFE guards via `TnConfig.*`** (e.g. `TnConfig.canManage`, used at line 4248), never `getElementById` at the top of a section — the wizard/touch modules must guard on `TnConfig.canManage` and the `.tn-mobile` runtime check, not on element presence.
- **Explicit git staging** during build; never `git add -A`.

---

## 7. Open questions / risks for the architect

1. **Touch DnD is the highest-risk item.** Long-press + lift + autoscroll inside a scrollable `tn-sheet` body is fiddly: the autoscroll RAF loop and `tnSwipe`'s axis-lock can fight each other, and `e.preventDefault()` on `touchmove` must be scoped to the lifted state only or it kills normal list scroll. **Cross-workflow:** the `tnSwipe` foundation utility must expose a way to **suspend swipe handling while a drag is active** (or the touch-reorder must register its listeners with `{passive:false}` and stop propagation). Needs an explicit contract in the foundation.
2. **Where does seed reorder live on mobile — in the bracket card or in the wizard?** Today reorder is on the card's participant list (line 1683), and seeding mode is chosen in the wizard. Recommend: reorder stays on the card (post-create), and the wizard's Review step deep-links to it for `*-manual` seedings. Architect to confirm the navigation between "wizard done" → "now arrange seeds."
3. **Should the first-generation flow skip confirmation entirely?** Spec wants Generate prominent; a confirm sheet adds a tap. Recommend keeping a light informational confirm (byes/rounds are genuinely useful), but this is a product call.
4. **`tnRegenArm` auto-commit countdown removal** (lines 7706–7745) changes desktop-vs-mobile behavior. Confirm we only suppress it under `.tn-mobile` and leave desktop untouched, OR retire the countdown everywhere in favor of the confirm sheet (cleaner, but a desktop behavior change — out of this workflow's mandate).
5. **Step count is dynamic** (ironman = +1 duration step). The progress indicator and "Step N of M" must recompute when Format changes mid-wizard. Edge case: user picks ironman (7 steps), advances past where duration would be, then switches away from ironman — step indices must re-map without losing entered values.
6. **Native select vs custom radio rows:** custom rows are more tappable and on-brand but add markup/JS; native selects are accessible and zero-cost. Recommend custom rows for Style/Format/Seeding (high-value decisions, benefit from helper text) and keep native for nothing here. Architect to weigh against build budget.
7. **`.tn-mobile` toggle mid-wizard:** if the organizer flips the manual view toggle (foundation) while the wizard sheet is open, the open Add/Edit overlay and the wizard must not both be visible. Foundation contract should define teardown of open sheets on mode switch.
