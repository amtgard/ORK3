# Playersearch Exception Register

Surfaces that do NOT fit the clean `OrkPlayerSearch.attach({center, restrictTo})` path,
deferred during the rollout. Each needs an **additive rule** (often a small component
enhancement) before conversion. Compiled from the parallel rollout agents' reports
(2026-05-26).

The base component today supports: `{ uir, parkId, kingdomId, restrictTo, includeInactive,
includeSuspended, limit, onSelect }`. The exceptions below need capabilities beyond that.

---

## 1. Move-player (cascade, scope changes by mode)
**Surfaces:** `kn-moveplayer-*`, `pk-moveplayer-*` (revised.js), `cp-mp-player` (Admin_index).
**Behavior:** the player-search scope changes at runtime based on the selected move mode
(in / within / out) via `MpCascade` (kingdom→park cascade). `attach()` binds opts once.
**Proposed additive rule:** add `OrkPlayerSearch.reattach(inputEl, newOpts)` (or a returned
handle with `.setOpts(newOpts)`) so the cascade callback can swap `restrictTo`/`parkId`/
`kingdomId` without rebuilding DOM listeners.

## 2. Merge-player (dual-field, exclude the other selection)
**Surfaces:** `kn-merge-keep`/`kn-merge-remove` (revised.js), `Admin_mergeplayer` From/To,
`cp-mgp-keep`/`cp-mgp-remove` (Admin_index).
**Behavior:** two player fields in one modal; the player chosen in one must not be selectable
in the other (can't merge a player into themselves); results de-duplicated local+global.
**Proposed additive rule:** add an `excludeIds: () => [ids]` (or static array) opt that filters
those MundaneIds out of results. Each field passes the other field's current hidden id.

## 3. Event attendance (hide already-attended)
**Surfaces:** `ev-PlayerName` (revised Eventnew); legacy `Attendance_*` are already converted and
do NOT filter — only the revised event tab filters.
**Behavior:** players already on the attendance list are removed from results; the exclusion set
grows as players are added during the session (no reload).
**Proposed additive rule:** same `excludeIds` mechanism as #2 (dynamic getter form), so the
caller returns the current attended-id set on each search.

## 4. Reconcile per-row giver (different backend + custom sort + per-row)
**Surfaces:** `rc-field-givenby` (Playernew_reconcile, per table row); the pn-edit reconcile rows.
**Behavior:** uses `SearchService`/`SearchAjax/universal` (not `SearchAjax/players`), applies a
custom active→inactive→banned sort, attaches per-row inside a jQuery ready block with guards.
**Proposed additive rule:** once endpoint convergence (Task 10) routes universal's player branch
through `RankedPlayers`, these can use the standard route. Attach per-row in a loop. The custom
active/inactive/banned ordering is already the component's default (suspended last, active first),
so no special sort needed. Re-evaluate after Task 10.

## 5. Unit member / manager add (intentionally GLOBAL, unscoped)
**Surfaces:** `Admin_unit` member+manager; revised unit add-member/manager modals.
**Behavior:** cross-kingdom AND cross-park adds are intended (documented hard rule).
**Proposed additive rule:** convert with NO center and NO restrictTo (pure global rank by name),
`includeInactive: true`. This is the simplest exception — it's "clean path with no center." Just
needs explicit sign-off that global (no proximity center at all) is correct here.

## 6. Officer / authorization grants (hard-bounded to administered scope)
**Surfaces:** `Admin_permissions` (kingdom/park scoped), `Admin_permissions_global` (ORK-admin),
`ap-player-input`.
**Behavior:** you grant authority only within the scope you administer.
**Proposed additive rule:** `Admin_permissions` → `restrictTo:'kingdom'` (or 'park') with the
administered id as center; `Admin_permissions_global` → no center, no restrict (global). These
ARE expressible with the current component — they were deferred only because they're authority
surfaces warranting an explicit policy sign-off.

## 7. Officer-preload-on-empty (UX feature, not scoping)
**Surfaces:** Award giver (`#GivenBy`), `Admin_player` giver — both showed kingdom/park
Monarch+Regent BEFORE typing (`minLength:0`), data from `$PreloadOfficers`.
**Behavior:** component requires 2+ chars before searching; preload-on-focus dropped.
**Proposed additive rule:** add an optional `preload: [{MundaneId, Persona, ...}]` opt rendered
on focus before typing; or accept the drop. Low priority.

---

## Component enhancements implied (to build before/with conversions)
- **`excludeIds`** (static array or getter) — unblocks #2, #3.
- **`reattach`/`setOpts`** (live reconfig) — unblocks #1.
- **`preload`** (optional, low priority) — #7.
- #5 and #6 need **no** component change — only a policy sign-off.
- #4 re-evaluated after Task 10 endpoint convergence.
