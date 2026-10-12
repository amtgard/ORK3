# Playersearch Standardization — Design Spec

**Date:** 2026-05-26
**Status:** Approved (design); pending spec review
**Component name:** **playersearch**

## Problem

Player/persona search is implemented ~34 different ways across the app, on 6 divergent
backend endpoints with incompatible scoping and ordering, and two different frontend
patterns (the custom `*-ac-results` dropdown and the banned jQuery UI `.autocomplete()`).

Consequences:
- **Recurring "can't find people outside my kingdom" bugs** — several surfaces hard-filter
  to the viewer's kingdom when they should rank-not-exclude (e.g. the award *giver* fixed in
  `bugfix/misc-fixes-05-26`).
- Ordering drift: only `ParkAjax/playersearch` (`prioritize=1`), `EventAjax/playersearch`, and
  `SearchAjax/universal` do park→kingdom→else; `KingdomAjax/playersearch` is kingdom-only;
  `SearchService::Player` and `AdminAjax/global/playersearch` do no proximity ranking at all.
- jQuery UI autocomplete still in use across legacy `default/` templates, violating the
  project rule that all player search use the custom dropdown pattern.

## Goal

One stock, plug-and-play **playersearch** component — a single backend ranking core + a single
frontend widget — that behaves identically everywhere and can be dropped onto any surface.

## The Ranking Contract (one rule, everywhere)

Think of concentric rings centered on the viewer: **Player → Park → Kingdom → Everywhere**.
A search always extends outward, nearest to farthest. The innermost *known* ring is set by
the surface.

Every search returns **all** matches (rank-don't-exclude by default), ordered:

```
suspended ASC, active DESC, ring ASC, persona ASC
```

`ring` is computed from the surface's declared center:

| Surface declares      | ring 0     | ring 1       | ring 2     |
|-----------------------|------------|--------------|------------|
| `parkId` (+ kingdom)  | same park  | same kingdom | everywhere |
| `kingdomId` only      | same kingdom | everywhere | —          |
| nothing (global/admin)| —          | —            | everywhere (name only) |

- **Opt-in hard filter** `restrictTo: 'park' | 'kingdom'` drops the outer rings off, for
  genuinely bounded pickers (officer grants, move-player destination).
- The existing power-user **`"KD:PK term"` abbreviation prefix** is preserved and acts as an
  explicit one-off hard filter to the typed kingdom/park, overriding ring ranking.
- Kingdom 15 exclusion and the restricted-name admin gate (admins bypass; non-admins see only
  unrestricted names or restricted players who opted in) are preserved in the core.

## Backend — one canonical endpoint

- **Ranking core:** new `SearchService::RankedPlayers($params)` in
  `system/lib/ork3/class.SearchService.php` (DB layer, per architecture rule). Single source of
  truth for the ring SQL, restricted-name gate, kingdom-15 exclusion, abbreviation-prefix
  override, and the standard ORDER BY.
- **Canonical route:** `SearchAjax/players` (thin controller action in
  `controller.SearchAjax.php`) → normalized JSON array of:
  `{MundaneId, Persona, KingdomId, ParkId, KAbbr, PAbbr, KingdomName, ParkName, Active, Suspended, Ring}`.
- **Params:** `q`, `parkId`, `kingdomId`, `restrictTo`, `includeInactive`, `includeSuspended`, `limit`.
- **Convergence:** the 6 divergent endpoints are redirected to the core (or deprecated) so
  ordering can never drift again:
  `KingdomAjax/playersearch`, `ParkAjax/.../playersearch`, `SearchAjax/universal` (player
  branch only — keep its park/kingdom/unit entities), `SearchService::Player`,
  `AdminAjax/global/playersearch`, `EventAjax/playersearch`.

## Frontend — one plug-and-play component

A single shared module **`OrkPlayerSearch`** (new global asset `script/ork-player-search.js`
plus `ops-`-prefixed CSS), loaded app-wide so both revised-frontend and legacy templates use it.

```js
OrkPlayerSearch.attach(inputEl, {
  parkId, kingdomId,            // the surface's center (omit what's unknown)
  restrictTo,                   // optional 'park' | 'kingdom' hard filter
  includeInactive, includeSuspended,
  limit,
  onSelect(player) { ... }      // receives the normalized player object
});
```

Bakes in every project requirement:
- Custom `ops-ac-results` dropdown — **never jQuery UI**.
- URL built with `&q=` (UIR already ends in `?Route=`).
- Keyboard navigation, debounce, min-length, no-results state.
- **`position:fixed` dropdown positioning for use inside modals** (the recurring clipped-
  dropdown bug).
- Inactive / banned badges; dark-mode compatible; no native `title` tooltips (use `data-tip`).
- Initialization runs on `attach()` — **no `getElementById` IIFE guards** (external script
  loads before modal markup exists).

## Standard vs Exception Surfaces

### Clean-path surfaces (convert first)
Surfaces that map directly onto the ring contract by declaring their center and (optionally)
`restrictTo`. These are converted in the parallel rollout.

### Exception register (reserved to the END)
Surfaces whose behavior deviates from the clean concentric path. **Each is flagged during the
punch list, reserved to the end, and its *additive rules* are designed WITH the user — not
guessed.** Known candidates (final list produced during rollout):

- **Unit + add player (member / manager):** intentionally global, cross-kingdom *and*
  cross-park; ring center may not apply the usual way.
- **Merge-player (keep / remove):** dual-field with cross-field exclusion + local/global dedup.
- **Move-player:** cascade-driven `restrictTo` that changes by mode (in / within / out).
- **Event attendance:** hides already-attended players; multi-group rendering with prefix match.
- **Officer / authorization grants:** hard-bounded to the kingdom/park the viewer administers.

Workflow: build component → convert clean-path surfaces → present the full exception list →
get the additive rule for each exception → convert exceptions.

## Per-Surface Policy (default split; veto in review)

- **Rank-don't-exclude (no `restrictTo`):** award giver & recipient, attendance,
  recommendations, unit member/manager add, merge-player.
- **Hard `restrictTo`:** officer/authorization grants (administered scope), move-player
  destination cascade.

## Out of Scope

Location and park-*entity* searches the audit swept in are **not** player search and are
excluded: "Given At" location pickers (`Search/Location`), destination-park pickers and
create-player park pickers (`Search/Park`).

## Migration Plan (agent team, full rollout)

1. **Foundation (sequential):** backend `RankedPlayers` core + `SearchAjax/players` endpoint +
   `OrkPlayerSearch` JS/CSS asset + global asset loading, proven on one pilot surface (award
   giver/recipient).
2. **Parallel conversion** (one agent per template group, non-overlapping files):
   - Playernew / Kingdomnew / Parknew modals
   - Eventnew + Playernew_reconcile
   - Legacy `Admin_*` templates
   - Legacy `Attendance_*` templates
   - `Award_addawards` / `Reports_roster`
3. **Endpoint convergence:** redirect/deprecate the 6 divergent endpoints to the core.
4. **Exceptions (last):** present exception register, gather additive rules, convert.

## Testing

- **Per-ring curl tests** against `SearchAjax/players`: prove a term that exists in ≥3 kingdoms
  returns park-first, then kingdom, then everywhere; prove `restrictTo` hard-limits; prove the
  abbreviation prefix overrides. (Player search must be curl-tested to return rows before
  "done.")
- Per converted surface: dropdown opens, ranks correctly, selects, works inside modals
  (fixed positioning), dark-mode walk.

## Risks

- **Large blast radius** (~34 surfaces). Mitigated by foundation-first, one-pilot-proof, then
  parallel non-overlapping agents, with the core endpoint curl-tested before any conversion.
- **`class.Authorization.php` login bypass** must never be staged; stage files explicitly.
- **Concurrent edits** in `revised.js` / ajax controllers — verify `git diff --cached` before
  any commit.
