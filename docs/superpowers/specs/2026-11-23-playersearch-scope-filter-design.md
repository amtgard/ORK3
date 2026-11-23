# Player Search Scope Filter (pill + filter modal) — Design

Date: 2026-11-23 · Branch: `refactor/playersearch`

## Intent
Make it easier to find the right player — especially when several share a name across
kingdoms/parks — by surfacing the hidden `KD:PK term` prefix as a visible, clickable filter.

## What the user sees
- A **filter (funnel) icon** at the right end of every eligible player-search field.
- Click → **modal** with cascading dropdowns: Kingdom (default "All kingdoms"); on choosing a
  kingdom, Park (default "All parks"). Apply / Clear. Opens pre-set to the current pill.
- Applying sets a **pill** at the left of the field (label `KCG:AR` or `KCG`), tooltip via
  `data-tip`: "Filtering to {kingdom name} - {park name}" (kingdom-only: "Filtering to {kingdom name}").
- Typing a valid prefix followed by a space (`KCG:AR `, `KCG: `, `KCG:* `) converts it into the
  pill. Invalid / out-of-scope prefixes stay as plain text (no pill).
- **Backspace** at caret 0 (no selection) breaks the pill back into editable `KCG:AR` text.
  A small × on the pill also removes it (touch users).

## Goals / non-goals / anti-goals
- Goal: one implementation, inside the shared `OrkPlayerSearch` component
  (`orkui/template/default/script/ork-player-search.js` + `style/ork-player-search.css`).
- Anti-goal: **any** per-page/separate implementation. The header Universal Search (its own
  multi-entity dropdown in `default.theme`) consumes the component's exported filter API
  (icon + pill + modal + prefix detection) — no copy.
- Anti-goal: the filter ever **widening** a field's scope. It may only narrow.
- Non-goal: filtering the header's parks/kingdoms/units sections (players only).

## Server (security-relevant)
- Bug today: `SearchService::RankedPlayers` applies the prefix filter in an `if/elseif` chain
  *ahead of* `restrictKingdomIds` / `restrictTo` (`class.SearchService.php` ~L1005), so a typed
  `XX:YY name` escapes a hard-scoped field. Fix: prefix/filter conditions AND with the hard scope.
- The pill is sent as explicit params (`filterKingdomId`, `filterParkId`), not re-embedded in `q`.
  Server validates ints; ANDs with scope; an out-of-scope filter yields no results.
  Typed prefixes in `q` keep working (same AND semantics).
- Small resolver endpoint (SearchAjax) turning `KD:PK` into `{kingdomId, kingdomName, parkId,
  parkName}`, honoring the field's scope params; returns nothing if unknown/out of scope.
  SQL stays in `system/lib/ork3/` (reuse `resolveAbbrevPrefix` logic).
- Header `SearchAjax/universal` accepts the same filter params for its player section.

## Scope awareness (component derives it from existing opts)
- `restrictTo:'park'` → no filter UI.
- `restrictTo:'kingdom'` / `restrictKingdomIds` → kingdom dropdown limited to that kingdom +
  principalities (family) / those ids; park dropdown within.
- Otherwise → all kingdoms.
- New opt `filter: false` disables the UI for surfaces that already have their own pickers.

## Per-surface decisions (judgment calls)
- **Filter on:** header Universal Search (players), award recipient + Given By (Player/Kingdom/
  Park profiles, Award_addawards, Admin_player), recommendation player fields + Recs manage,
  reconcile Given By, bonus credit player, Unit member/manager add, event staff + schedule leads,
  Admin ban, global/kingdom permissions, QualTest manage, roster "By" fields, Admin_manageevent.
- **Narrowed (park-only within kingdom family):** officer assignment (kn/pk), kingdom merge
  keep/remove, bonus granter.
- **Off (`filter:false`):** park-locked fields (park merge keep/remove); surfaces with their own
  kingdom/park pickers — all Attendance entry (Attendance_kingdom/park/event, pk-att, event
  attendance on Eventnew), Admin move/merge/suspend, control-panel move/merge, kn/pk move player,
  roster `sp-player-text`.

## Constraints
- Icon/pill are absolutely positioned overlays with input padding — no layout reflow of
  existing forms. Modal is in-page (no native dialogs), z-index above host modals.
- Dark mode via `html[data-theme="dark"]`; usable at phone width; FontAwesome 7 icons.
- Existing ARIA combobox behavior preserved; pill/icon keyboard-accessible.

## Approaches considered
1. **Shared component (chosen):** one implementation, all call sites inherit; header uses its API.
2. Per-page wiring: ~25 copies, drift — rejected by user.
3. Server-only (prefix parsing, no UI): doesn't solve discoverability — rejected.

## Left to the builder
Exact param names, modal markup, kingdom/park data source (reuse existing Search/Kingdom &
Search/Park endpoints or a cached list), pill styling details.
