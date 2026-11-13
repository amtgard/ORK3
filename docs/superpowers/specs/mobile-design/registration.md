# Tournament Mobile Design — Fighter Registration

**Workflow:** §4.2 of `2026-05-23-tournament-mobile-organizer-design.md`
**Scope:** Add Participant, Bulk Add (Paste Roster), Add Team, participant list + status changes.
**Design-only.** All line references are to `orkui/template/revised-frontend/Tournametnew_index.tpl`.

Assumes these shared primitives already exist (designed by the foundation track, not here):
`.tn-mobile` body/root class, `tnSwipe(el, opts)`, the `tn-sheet` bottom-sheet primitive (sticky header + scrollable body + sticky footer action), and the existing `tnFixedAcPosition(inputEl, dropdownEl)` (line 3253).

---

## 1. Current mobile-state audit

### 1.1 Add Participant modal (`#tn-addparticipant-overlay`, lines 2183–2220; opener `tnOpenAddParticipantModal` line 3475)
- **Centered overlay, not a sheet.** `.tn-overlay` is `display:flex; align-items:center; justify-content:center` (line 299); `.tn-modal-box` is `width:480px; max-width:calc(100vw - 40px)` (line 2184) with `max-height:90vh` (line 301). On a 375px phone this floats mid-screen, leaving a thin band of body content above/below — wasteful and easy to mis-tap the backdrop.
- **Footer action is bottom-right, not full-width.** `.tn-modal-footer` is `justify-content:flex-end` (line 308). The 480px rule (lines 819–820) makes footer buttons `flex:1 1 auto`, so Cancel + "Add Participant" sit side-by-side — the primary Add target is only ~half width and shares a row with a ghost Cancel that is easy to fat-finger.
- **Autocomplete already escapes to `<body>` with `position:fixed`** (good — `tnAcRender` → `tnFixedAcPosition`, lines 3636/3659). But results are capped `max-height:200px` (line 892), narrowed to 150px under 480px (line 816), and the items are only `padding:8px 12px` (line 894) — ~33px tall, below the 44px touch target.
- **Two stacked text fields + a hidden Quick-Add section** (lines 2206–2212, `max-height:180px` scroll) inside an already height-constrained box → nested scroll regions on a small viewport.
- The keyboard, when the persona field is focused, covers roughly half the viewport; a centered modal's footer Add button can end up *behind* the keyboard with no sticky anchoring.

### 1.2 Bulk Add / Paste Roster (`#tn-bulkadd-overlay`, lines 2225–2252; opener line 7040)
- `rows="10"` textarea (line 2241) inside a `max-height:90vh` centered box. With the soft keyboard up, the textarea is squeezed and the "Add All" submit (line 2247) is bottom-right behind the keyboard.
- Progress line (line 2243) and feedback (line 2232) compete for the little remaining vertical space.
- This is the surface that most wants a **full-height sheet** (spec §4.2: "Bulk paste roster gets its own full-height sheet with the textarea").

### 1.3 Add Team modal (`#tn-addteam-overlay`, lines 2259–2309; opener `tnOpenAddTeamModal` line 3802)
- Two-step flow (step1 name → step2 members) toggled by show/hide (lines 2270/2277, JS 4009–4017). On mobile the Next/Save swap (lines 2301/2304) is bottom-right, same fat-finger / behind-keyboard problem.
- Member tags render via `tnRenderTeamMembers` (line 3917) as inline `.tn-team-member-tag` chips (line 244, `font-size:12px`, remove "×" is `padding:0 0 0 4px`, line 245) — the remove × is a ~14px target, far below 44px.
- Member autocomplete (`#tn-addteam-player-results`, line 2287) filters already-assigned mundanes (`tnTeamAcRender`, line 3948) — must be preserved.

### 1.4 Participant list (`.tn-participant-list`, render lines 1683–1711)
- Rows are `padding:5px 0; font-size:13px` (line 236) → ~26px tall. Far below 44px; hard to tap on the intended row.
- Each row crams: drag handle (DnD only), seed pill, name + pills + persona paren + WD/DQ pill, park name, a kebab status button (`.tn-status-btn`, line 837, `padding:2px 5px` ≈ 18px), and a remove "×" (`.tn-remove-participant`, line 240). On 375px these collide / wrap badly.
- **Status menu is `position:absolute; right:0; top:100%` (line 839).** Inside a scrolling card on mobile it can be clipped or run off the bottom of the viewport — the same stacking/clipping problem the autocomplete already solved with `position:fixed`. Toggled by `tnToggleParticipantMenu` (line 6811) / set by `tnSetParticipantStatus` (line 6820).
- **Seed reorder is HTML5 DnD (`tn-dnd-list`, line 1683; `tn-dnd-handle`, line 1687) = desktop pointer only**, no touch — flagged in spec §1/§4.3. *(Touch DnD is the generation track's deliverable; noted here only because the handle lives in this same row.)*
- Pills (`tnParticipantPills`, lines 51–56; `.tn-pill-*`, lines 243–248) and WD/DQ pills (line 1699–1700) are fine size-wise but add horizontal pressure on a 375px row.

---

## 2. Sheet conversions (≈375px wireframes)

All three reuse the `tn-sheet` primitive when `.tn-mobile` is active; the existing centered `.tn-overlay` markup stays the desktop fallback (spec §3.4 — sheet *replaces* the overlay only under `.tn-mobile`). No DOM duplication: the same `#tn-addparticipant-overlay` element gets `tn-sheet` layout via `.tn-mobile .tn-overlay { align-items:flex-end; }` + `.tn-mobile .tn-overlay .tn-modal-box { width:100%; max-width:100%; max-height:92vh; border-radius:16px 16px 0 0; }`.

### 2.1 Add Participant — bottom sheet
```
┌─────────────────────────────────────┐  ← backdrop (tap = dismiss)
│                                       │
│                                       │
├─────────────────────────────────────┤
│  ⬓  drag-handle bar (swipe down ✕)    │  sticky header
│  👤  Add Participant            [✕]   │   (h3 reset: no gray box)
├─────────────────────────────────────┤
│  ⌕ Search by persona…            ⌫    │  STICKY search row
│  ┌─────────────────────────────────┐ │   (input pinned under header)
│  │ Sir Galahad      (EM:WR)        │ │  ← tn-ac-results, position:FIXED
│  │ Galen the Bold   (EM:NM)        │ │     full-width, 48px rows
│  │ Gareth           (CALON)        │ │
│  └─────────────────────────────────┘ │  scrollable body ↕
│                                       │
│  Alias / Fighter Name *               │
│  [ Sir Galahad                     ]  │  (auto-filled from pick)
│                                       │
│  ▸ Quick Add from other brackets  (3) │  collapsed accordion
├─────────────────────────────────────┤
│  [   + Add Participant            ]   │  STICKY footer, FULL-WIDTH
└─────────────────────────────────────┘   ~52px, primary green
```
Notes: search input gets focus on open but **do not auto-pop the keyboard** if the Quick-Add list is the likely path (organizers often add from other brackets). Keep the existing "Add stays open, keep adding" behavior (JS lines 3740/3746) — after each add, clear the search + alias, re-focus search, and surface the running count in the feedback line under the header. Quick-Add (lines 2206–2212) becomes a collapsed accordion so it doesn't create a nested scroll region.

### 2.2 Bulk Add / Paste Roster — full-height sheet
```
┌─────────────────────────────────────┐
│  ⬓  swipe-down to dismiss             │  sticky header
│  📋  Paste Roster              [✕]    │
├─────────────────────────────────────┤
│  One fighter per line. Paste from a   │  helper (small, muted)
│  signup sheet or type fast.           │
│  ┌─────────────────────────────────┐ │
│  │ Sir Galahad                     │ │
│  │ Morgana                         │ │  textarea fills
│  │ The Grey Wolf                   │ │  ALL remaining height
│  │ |                               │ │  (flex:1, monospace)
│  │                                 │ │
│  │                                 │ │
│  └─────────────────────────────────┘ │
│  Added 3 of 5…                        │  progress line
├─────────────────────────────────────┤
│  [   + Add All (5)               ]    │  sticky footer, full-width
└─────────────────────────────────────┘
```
Notes: this is the only sheet that should be **near-full-height** (`max-height:96vh`) so the textarea has room with the keyboard up. The textarea becomes `flex:1` inside the scroll body rather than fixed `rows="10"` (line 2241). Live-count the non-blank lines into the footer button label ("Add All (5)"). Preserve the sequential per-line POST loop and inline progress (JS lines 7748–7771); just retarget the progress text into the sticky region above the footer.

### 2.3 Add Team — bottom sheet (two-step, sticky stepper)
```
STEP 1 ─ name                  STEP 2 ─ members
┌──────────────────────────┐   ┌──────────────────────────┐
│  ⬓                         │   │  ⬓                         │
│  👥 Add Team        [✕]    │   │  👥 Add Team        [✕]    │
│  ●━━━○  Name · Members     │   │  ○━━━●  Name · Members     │  stepper
├──────────────────────────┤   ├──────────────────────────┤
│  Team Name *               │   │  Wolves of the North      │  (team label)
│  [ Wolves of the North  ]  │   │  ── add members below ──   │
│                            │   │  [👤 Sir Galahad  ✕]       │  member tags
│                            │   │  [👤 Morgana      ✕]       │  (≥44px, gap 8px)
│                            │   │                            │
│                            │   │  ⌕ Search by persona…  ⌫   │  sticky-ish search
│                            │   │  ┌──────────────────────┐ │
│                            │   │  │ Gareth     (CALON)   │ │  tn-ac-results FIXED
│                            │   │  └──────────────────────┘ │
├──────────────────────────┤   ├──────────────────────────┤
│  [ Next: Add Members  → ]  │   │  [  ✓ Save Team        ]   │  full-width footer
└──────────────────────────┘   └──────────────────────────┘
```
Notes: replace the show/hide step swap (lines 4012–4016) with a slide between two body panels under a shared sticky 2-dot stepper. Footer button swaps Next → Save Team as today (lines 2301/2304). On Save, keep the "saved, add another team" reset loop (JS 4047–4064). Member tags get bigger touch targets (see §5).

---

## 3. Autocomplete inside a sheet

**Hard rule (MEMORY + spec §3.4 + §5): autocomplete dropdowns inside a sheet MUST use `position:fixed` via `tnFixedAcPosition`, never `position:absolute`** — the sheet's transform/overflow creates a stacking context that clips an absolutely-positioned `.tn-ac-results`. This is **already correctly implemented** for all three registration surfaces and must be preserved verbatim:
- Add Participant: `tnAcRender` calls `tnFixedAcPosition(playerInput, resultsEl)` in both the empty branch and results branch (lines 3636, 3659).
- Add Team member: `tnTeamAcRender` likewise (lines 3961, 3982).
- `tnFixedAcPosition` (line 3253) reparents the dropdown to `<body>`, sets `position:fixed`, anchors `top = rect.bottom + 4`, `z-index:9999`.

**Mobile-specific guardrails to add (CSS only, keyed off `.tn-mobile`):**
1. **Reposition on scroll/resize.** `tnFixedAcPosition` snapshots `getBoundingClientRect()` once. On a phone the body scrolls and the soft keyboard resizes the viewport, so the fixed dropdown will drift from its input. Recommendation: while a results dropdown is open inside a `.tn-mobile` sheet, re-run `tnFixedAcPosition` on the sheet body's `scroll` and on `window` `resize`/`visualViewport.resize`. (Foundation/arch should decide whether this lives in `tnFixedAcPosition` itself or the sheet primitive — see open questions.)
2. **Full-width, larger rows.** Under `.tn-mobile`, give `.tn-ac-item` `padding:13px 14px; font-size:15px; min-height:44px` and let `.tn-ac-results` use a taller `max-height` (e.g. `min(50vh, 320px)`) so picking from a long persona list isn't a pinhole. The dropdown width already tracks the input (`rect.width`), so it spans the sheet.
3. **Selection still on `mousedown`/`e.preventDefault()`** (lines 3647, 3972) — keep, it fires before blur on touch too; do **not** switch to click.

**Custom pattern only — never jQuery UI.** Both dropdowns use the project's hand-rolled `.tn-ac-results` / `.tn-ac-item` pattern (the tn- analogue of `kn-ac-results`). This is the mandated pattern; jQuery UI autocomplete is forbidden (MEMORY hard rule). No change.

**Search-scoping rules (preserve exactly):**
- Add Participant search is **kingdom-scoped** when `TnConfig.kingdomId > 0` via `KingdomAjax/playersearch/{kingdomId}` (lines 3670–3679), falling back to global SOAP persona search otherwise (lines 3681–3689). A tournament is a kingdom-level entity, so kingdom scope is correct per the project scoping rule. Do not broaden it.
- Add Team member search uses the same scoping and additionally filters out already-assigned mundanes (`tnGetAssignedMundaneIds`, line 3951). Preserve both.

---

## 4. Participant list on mobile

### 4.1 Large tap-target row
```
┌─────────────────────────────────────┐
│ ⠿  ①  Sir Galahad  ⓦ K          ⋮   │  ← ~52px row
│       Emerald Hills · (Galahad)      │     park + persona on line 2
├─────────────────────────────────────┤
│ ⠿  ②  Morgana  WD                ⋮   │  withdrawn → strikethrough name
│       Northern Reaches               │
├─────────────────────────────────────┤
│ ⠿  ③  The Grey Wolf              ⋮   │
│       (no park)                      │
└─────────────────────────────────────┘
 ⠿ = drag handle (manual-seed brackets only; touch DnD = generation track)
 ① = .tn-participant-seed   ⓦ/K = .tn-pill-*   ⋮ = status/actions
```
Under `.tn-mobile`, change `.tn-participant-list li` to a **two-line stacked layout**: `min-height:52px; padding:10px 8px; flex-wrap:wrap`. Line 1 = handle + seed + name + rank pills + WD/DQ pill + the `⋮` actions button (pushed right with `margin-left:auto`). Line 2 (full-width, muted, `font-size:11px`) = park name + persona-paren. This removes the horizontal collision on 375px without touching the PHP render (lines 1684–1710) — pure CSS reflow.

### 4.2 Action sheet for status (replaces the absolute dropdown menu)
The kebab `⋮` (`.tn-status-btn`, line 837; toggles `.tn-status-menu`, line 839) currently opens an **`position:absolute` dropdown** that clips on mobile. Under `.tn-mobile`, route `tnToggleParticipantMenu` to open a **`tn-sheet` action sheet** instead (the foundation primitive), reusing the *same* menu items so the existing handlers fire unchanged:
```
┌─────────────────────────────────────┐
│  ⬓                                    │
│  Sir Galahad                          │  context label
├─────────────────────────────────────┤
│  ● Active                        ✓    │  → tnSetParticipantStatus(pid,'active',…)
│  ● Withdrawn                          │  → …'withdrawn'…
│  ● Disqualified                       │  → …'disqualified'…
├─────────────────────────────────────┤
│  🗑  Remove from bracket              │  → tnRemoveParticipant (separate btn today)
├─────────────────────────────────────┤
│  [          Cancel              ]     │
└─────────────────────────────────────┘
```
Each option ≥48px. The three status rows map 1:1 to the existing `.tn-status-menu-item` onclicks (line 1706) calling `tnSetParticipantStatus(pid, status, bid, this)` → `updateparticipantstatus` endpoint (line 6825). The Remove row maps to the existing `tnRemoveParticipant(this)` (button at line 1707) → `removeparticipant` endpoint (line 2798). **Fold the standalone "×" remove button into this action sheet on mobile** so the row isn't carrying two tiny separate controls. `tnSetParticipantStatus`'s success path already updates the row's `data-status`, strikethrough class, and inline WD/DQ pill (lines 6842–6866) — keep that; just dismiss the action sheet instead of closing the absolute menu.

**Optional swipe affordance (`tnSwipe`):** on a participant row, swipe-left reveals a quick **Withdraw / Remove** pair; swipe-right (or tapping the `⋮`) opens the full action sheet. Keep this strictly additive — the `⋮` action sheet is the primary, discoverable path; swipe is an accelerator. The drag handle on manual-seed brackets means swipe must axis-lock (handled by `tnSwipe` per §3.2) and the handle's long-press-drag (generation track) must win when the gesture starts on `.tn-dnd-handle`.

### 4.3 Rank pills + seed at phone width
- Seed `.tn-participant-seed` (line 238, 20px circle) is fine; keep on line 1.
- Rank pills `.tn-pill-warrior/-warlord/-knight` (lines 243–248) stay inline after the name on line 1. They are decorative+`data-tip` (no native title — good, MEMORY rule). On mobile, `data-tip` hover tooltips don't fire on touch; that's acceptable for pills (the letter glyph + color carries meaning), but **do not add `title=`** as a fallback (MEMORY: no native tooltips).
- WD/DQ status pills (lines 1699–1700) stay on line 1 next to the name; strikethrough styling (lines 851–852) carries over.

---

## 5. Team registration (multi-member add on mobile)

- **Member tags** (`.tn-team-member-tag`, line 244; rendered by `tnRenderTeamMembers`, line 3917) become larger chips under `.tn-mobile`: `font-size:14px; padding:6px 12px; min-height:36px`, and the remove control `.tn-team-member-remove` (line 245) grows to a `≥32px` tap target (`padding:6px 8px; min-width:32px`). Wrap the tag container as a flex-wrap row with `gap:8px`.
```
┌─────────────────────────────────────┐
│  [👤 Sir Galahad           ✕ ]        │  each chip ≥36px tall,
│  [👤 Morgana               ✕ ]        │  ✕ is a real ≥32px target
│  [👤 The Grey Wolf         ✕ ]        │
└─────────────────────────────────────┘
   add members ↓
  ⌕ Search by persona…             ⌫
```
- After each member pick, the search clears and stays focused (JS 3977) so the organizer can add the next member rapidly — keep this; it's ideal for a phone. The member appears as a new chip immediately above the search (already the behavior; just restyled).
- Already-assigned filtering (`tnTeamAcRender` excludes assigned mundanes, line 3951–3957) prevents duplicate adds — preserve.
- Member-count validation before Save (≥1 member, JS line 4032) unchanged.

---

## 6. Component mapping table

| New mobile element | Derives from / replaces | Existing tn- class / JS fn | Lines | AJAX endpoint (reuse, no change) |
|---|---|---|---|---|
| Add Participant sheet | `#tn-addparticipant-overlay` modal | `tnOpenAddParticipantModal` | 2183–2220 / 3475 | `bracket/{bid}/addparticipant` (3564, 3727) |
| Bulk Add full-height sheet | `#tn-bulkadd-overlay` modal | `tnOpenBulkAddModal` | 2225–2252 / 7040 | `bracket/{bid}/addparticipant` (per line, 7771) |
| Add Team two-step sheet | `#tn-addteam-overlay` modal | `tnOpenAddTeamModal` | 2259–2309 / 3802 | `bracket/{bid}/addparticipant` (team payload, 4041) |
| Sticky full-width footer Add | `.tn-modal-footer` (right-aligned) | `.tn-btn-primary` submit | 308, 2215/2247/2304 | — |
| Sheet autocomplete (fixed) | `.tn-ac-results` dropdown | `tnFixedAcPosition`, `tnAcRender`, `tnTeamAcRender` | 3253, 3632, 3948 | `KingdomAjax/playersearch/{kid}` (3672) / SOAP fallback (3682) |
| Larger AC rows | `.tn-ac-item` | (CSS only, `.tn-mobile`) | 894–897, 816 | — |
| Two-line participant row | `.tn-participant-list li` | (CSS reflow, `.tn-mobile`) | 235–238, 1684–1710 | — |
| Status action sheet | `.tn-status-menu` (absolute) | `tnToggleParticipantMenu`, `tnSetParticipantStatus` | 839, 6811, 6820 | `bracket/{bid}/updateparticipantstatus` (6825) |
| Remove (in action sheet) | `.tn-remove-participant` button | `tnRemoveParticipant` | 240, 1707, 2798 | `bracket/{bid}/removeparticipant` (2798) |
| Bigger member chips | `.tn-team-member-tag` / `-remove` | `tnRenderTeamMembers` | 244–245, 3917 | — (client-side until Save) |
| Swipe accelerator (optional) | new | `tnSwipe` (foundation) | §3.2 | reuses status/remove endpoints |
| Drag handle (manual seed) | `.tn-dnd-handle` (HTML5 DnD) | — touch DnD owned by **generation track** | 1683/1687 | `bracket/{bid}/reorder` |

All endpoints already exist (spec §1 "no new backend"). No new endpoints invented.

---

## 7. Guardrails applied (spec §5)

- **Dark mode:** every restyled surface already has dark counterparts — `.tn-ac-item` (1226–1228), `.tn-status-menu` (1204–1207), `.tn-team-member-tag/-remove` (986–987), `.tn-participant-list` (978–979), `.tn-overlay/.tn-modal-*` (1027–1033), `.tn-pill-*` (985–990). New `.tn-mobile` sheet/action-sheet rules and the status action sheet MUST ship dark variants in the same pass (backdrop, sheet bg `#1a202c`, borders `#2d3748`, muted text `#cbd5e0`). Walk the dark checklist on each new sheet.
- **Global h1–h6 gray-box reset:** any heading inside a sheet must reset background/border/padding/radius/text-shadow. `.tn-modal-title` already does this (`!important`, line 304) and the sheets reuse `.tn-modal-title`/`.tn-modal-header`, so it carries over — but any *new* heading element introduced in an action sheet (e.g. the context label) must use `.tn-modal-title` or carry the same reset.
- **Autocomplete in sheets → `position:fixed`:** mandatory, already done via `tnFixedAcPosition`; add the on-scroll/resize reposition (see §3).
- **No native `title` tooltips:** use `data-tip` (already used at lines 1706/1707/1617). Don't add `title=` as a touch fallback for pills.
- **Human-readable dates:** registration has no date inputs, so N/A here. (The Edit Tournament modal's Flatpickr handles dates, lines 3295–3304 — not this workflow.)
- **IIFE guards:** the registration IIFEs guard on `TnConfig.canManage` / `TnConfig.canEditAdmin` (e.g. line 3272), never `getElementById` — preserve; sheet markup loads with the page, but keep the config-flag guard convention.
- **PHP/TPL edits via Python**, explicit git staging (never `git add -A`, never stage `class.Authorization.php` / `CLAUDE.md`) — for the build track.

---

## 8. Open questions / risks for the architect

1. **Fixed-AC reposition ownership (cross-workflow).** `tnFixedAcPosition` snapshots position once. The on-scroll / `visualViewport.resize` re-anchor that mobile needs is generic — should it live in `tnFixedAcPosition` itself (benefits generation's wizard sheet too) or in the `tn-sheet` primitive? **Recommend: bake into the shared primitive / `tnFixedAcPosition`** so all sheets get it. Needs foundation-track decision.
2. **Soft-keyboard + sticky footer (cross-workflow).** When the keyboard opens, does the sticky footer "Add" stay visible (it should) or get covered? This depends on the `tn-sheet` primitive using `dvh`/`visualViewport` math. The bulk-add textarea is the worst case. Foundation must define the keyboard-safe footer behavior; all three registration sheets depend on it.
3. **Status action sheet vs. the foundation `tn-sheet`.** I'm reusing `tn-sheet` for a small *action* sheet (auto-height, list of options). Confirm the primitive supports an auto-height / non-full variant, or whether a thin `tn-action-sheet` modifier is warranted (would be shared with the running track's per-fighter menus).
4. **Swipe vs. drag-handle conflict.** On manual-seed brackets a row has both a long-press drag handle (generation track's touch DnD) and an optional swipe accelerator (this track). `tnSwipe` axis-lock must defer to a gesture that begins on `.tn-dnd-handle`. Needs a shared gesture-priority rule between this track and generation. **Recommend: swipe-on-row is optional/deferrable; ship the `⋮` action sheet first**, add swipe only if the gesture arbitration is clean.
5. **Quick-Add accordion default state.** Collapsing Quick-Add (lines 2206–2212) by default trims the sheet but hides a useful bulk path. Should it auto-expand when other brackets actually have participants to offer? Low-risk product call.
6. **Search debounce on mobile.** Current debounce is 280ms (line 3669). On a phone keyboard that may feel laggy; consider 200ms. Minor.
