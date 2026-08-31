# Officer Presentation Rework — Design

**Date:** 2026-08-29
**Branch:** `feature/kingdom-ladder-awards`
**Status:** Approved, ready for implementation planning

## Problem

Officers are presented as a flat list in the Kingdom and Park profile sidebars. The
position registry has supported nesting (`ork_officer_position.parent_position_id`)
and a crown/supporting split (`classification`) since 2026-08-25, but no read surface
uses either: every officer appears at the same level, and a supporting office that
reports to the Regent looks exactly like the Monarch.

Officer History exists as a separate main-content tab, disconnected from the officers
it describes.

## Decisions

Settled with the user before design:

| Question | Decision |
|---|---|
| Which surfaces | **Kingdom + Park.** Player's officer card uses a different shape (`Player::GetOfficerRoles`, no position id / parent / classification) and spans many orgs; out of scope. |
| Existing Officer History main-content tab | **Move it** into the modal. Not duplicated. |
| Definition of "top-level" | **`classification = 'crown'`**, not `parent_position_id IS NULL`. |
| Sharing strategy | Share the **new** surface (one modal partial); leave the working sidebars in place, filtered. Do not revive `_officer_panel.tpl`. |

### Already satisfied — do not build

The user asked to "ensure that in Officer Admin they can designate a newly created
officer as a Crown officer." **This already works.**

- `partials/_manage_officers.tpl:97-103` renders a Crown/Supporting segmented control.
- `:1231-1235` — the new-position path hides the lock, re-enables both buttons, and
  defaults to `moSetClass('crown')`.
- `:1258` — the lock engages only when editing a **pinned** position.
- `controller.OfficerAdminAjax.php:555-580` forwards `Classification`;
  `OfficerPosition::CreatePosition()` validates `crown|supporting` with no guard
  against new crown offices.

Verify in the browser at delivery. Write no new code for it.

## Current state

### Officer rendering — three hand-duplicated surfaces

| Surface | Location | Prefix | Variable |
|---|---|---|---|
| Kingdom | `Kingdomnew_index.tpl:251-280` | `kn-` | `$officerList` (set `:13` from `$kingdom_officers['Officers']`) |
| Park | `Parknew_index.tpl:507-536` | `pk-` (heading leaks `kn-bare-heading` at `:510`) | `$officerList` (set `:29`) |
| Player | `Playernew_index.tpl:1590-1601` | `pna-` | `$OfficerRoles` — different shape, out of scope |

Kingdom and Park are byte-for-byte analogues, including the same hardcoded
`<em style="color:#a0aec0">Vacant</em>` (`Kingdomnew_index.tpl:271`, `Parknew_index.tpl:527`).

`partials/_officer_panel.tpl` (107 lines) is a fully-written reusable panel included by
**nothing**; it expects the `GetOfficersForDisplay` shape that no live page uses.

### The officers bar

`Kingdomnew_index.tpl:253-260` — an `<h4 class="kn-bare-heading">` laid out
`display:flex; justify-content:space-between`, holding a `<span>` title and, when
`$CanManageKingdom`, a pencil button calling `knOpenEditOfficersModal()`. Park mirrors
this at `:510-517` gated on `$CanAdminPark`.

There is no `kn-sidebar-title` or `kn-card-header` class in the repo; `.kn-bare-heading`
(`revised.css:2160-2167`) is the house sidebar heading.

### Data path

`Kingdom/profile/{id}` → `controller.Kingdom.php:203` → `:246`
`Kingdom->get_officers_bundle()` → `model.Kingdom.php:58` → `:48` → `Kingdom::GetOfficers()`
→ **`Kingdom::buildOfficerRows()` (`class.Kingdom.php:1219`)**.

Park path reaches the *same builder*: `controller.Park.php:60` → `:175` →
`model.Park.php:38` → `class.Park.php:489` → `class.Park.php:500`.

The SELECT (`class.Kingdom.php:1219-1237`) already reads `o.position_id`,
`op.parent_position_id`, `op.hide_when_vacant` and `op.classification`, and already
filters vacant non-crown positions:

```sql
and (op.retired_at IS NULL or op.position_id IS NULL)
and NOT (op.hide_when_vacant = 1 and op.classification != 'crown'
         and (o.mundane_id IS NULL or o.mundane_id = 0))
order by op.classification, op.sort_order, o.role
```

The emitted row (`:1245-1281`) carries `ParentPositionId`, `CanonicalKey`, `DisplayTitle`,
`HideWhenVacant`, `OfficerId` — but **not** `PositionId`, **not** `Classification`, and
**not** `SortOrder`. A consumer can therefore see which parent a row reports to and has
no id to match it against, and cannot tell crown from supporting.

### Officer history

Rendered as a main-content tab on both surfaces:

- Kingdom: nav `Kingdomnew_index.tpl:349-351`, panel `:913-946`, add modal `:1003-1057`,
  edit modal `:1058-1110`; JS `knLoadOfficerHistory()` `:3364-3383`,
  `knRenderOhTable()` `:3385-3434`.
- Park: nav `Parknew_index.tpl:620-622`, panel `:1295-1329`, modals `:1509`, `:1564`;
  JS `pkLoadOfficerHistory()` `:3531`.

Endpoint `KingdomAjax/kingdom/{id}/officerhistory` (`controller.KingdomAjax.php:994`) →
`model.Kingdom.php:213` → `Kingdom::GetOfficerHistory()` (`class.Kingdom.php:1390`).
Rows key off `role` (a canonical-key **string**), not `position_id`, so history cannot be
joined to the position tree.

## Informed by the admin rework (0db735f9)

The Manage Officers admin screen was rebuilt first, deliberately, so its treatment could
drive this one. What that changed for this spec:

**Per-kingdom ordering now exists, and this surface already inherits it.** A kingdom can
re-order the shared Core Five without affecting other kingdoms, via a nullable `sort_order`
on `ork_officer_position_alias` resolved by `OfficerPosition::SortOrderSql()`.
`buildOfficerRows`'s ORDER BY was routed through it (`class.Kingdom.php:1240`), so the
sidebar and modal show the kingdom's own order with no further work here. This was not true
when this spec was first written.

**Still outstanding, unchanged:** `buildOfficerRows` emits `ParentPositionId` but not
`PositionId`, `Classification` or `SortOrder`. The three additive keys are still required.

**Mirror the admin's visual language rather than inventing one.** The row treatment is now
established: one list with no crown/supporting divider, a gold crown glyph
(`.mo-crown-glyph`, `#d69e2e`) marking crown offices, and nesting shown by indent inside a
`.mo-children` container with a rail. The public modal should read as the same system.

**Reuse the admin's tree guards.** `renderGroupTree` walks with a `seen{}` cycle guard, a
depth cap of 12, and renders an orphan at root rather than dropping it. `WouldCreateCycle`
protects writes only; rows already in the table can still be malformed.

**Merging the two groups is what makes nesting work.** The admin's tree was previously built
*within* a classification group, so a supporting deputy reporting to a crown office rendered
as a false root. The modal's Current Officers tab must build its tree across the whole set,
then present crown at top level -- not build two trees.

**Orphans: diverge from the admin deliberately.** When a parent is retired, its children
render at top level. The admin labels these "Reports to X (retired)" because an officer
admin needs to know why the row cannot be re-ordered and how to fix it. The public modal has
no such need and no such action, so it renders the row at top level with **no** reports-to
caption -- a member reading a kingdom's officers should not see a dangling reference to a
retired office. The public modal therefore omits the reports-to caption entirely; indent is
the only nesting signal it needs.

## Requirements

1. Kingdom and Park sidebars list **only** `Classification === 'crown'` officers.
2. The officers bar carries a right-arrow affordance opening an officer details modal.
3. The modal has two tabs: **Current Officers** and **Officer History**.
4. Current Officers shows every position, with nested positions rendered beneath the
   position they report to.
5. Officer History moves into the modal's second tab; the main-content tab is removed.
6. Kingdom and Park share one implementation of the modal.

## Architecture

### Domain change (additive)

`Kingdom::buildOfficerRows()` (`class.Kingdom.php:1245-1281`) adds three keys to each
emitted row:

| Key | Source | Why |
|---|---|---|
| `PositionId` | `o.position_id` (already selected) | Without it `ParentPositionId` has nothing to match against — no tree is possible. |
| `Classification` | `op.classification` (already selected) | The sidebar's crown filter. |
| `SortOrder` | `op.sort_order` | Deterministic sibling ordering inside the tree; currently implicit in `ORDER BY` only. |

`SortOrder` requires adding `op.sort_order` to the SELECT list. The other two are already
selected and merely dropped.

This is additive — no key is renamed or removed — and it lands in the one builder Kingdom,
Park and the admin console all consume.

### No new endpoint

`$officerList` already contains crown *and* supporting rows; the sidebar simply never
distinguished them. Sidebar filters that array; the modal groups the same array. One
query, two presentations.

The History tab reuses `KingdomAjax/kingdom/{id}/officerhistory` and its Park mirror
unchanged, lazy-loaded on first tab activation — matching how the main-content tab loads
it today.

### Components

**`orkui/template/revised-frontend/partials/_officer_details_modal.tpl`** — one shared
partial, neutral `of-` prefix, included by both profile templates. Inputs: the officer
array, a scope (`kingdom|park`), and the org id. Its CSS lives once.

Tabs follow the only existing in-modal tab pattern in the codebase — the Player "Design My
Profile" modal (`Playernew_index.tpl:3279-3302`, JS `:4304-4345`) — renamed
`pn-design-*` → `of-*`. Chrome follows the `kn-modal-box` / `kn-modal-header` /
`kn-modal-body` house pattern (`Kingdomnew_index.tpl:1650-1671`).

**Tree building** lives in that partial as plain PHP. It operates on an array already in
hand, so no layer boundary is crossed — no SQL, no domain class. It mirrors the guards the
admin console's `renderGroupTree` already uses (`_manage_officers.tpl:699-733`):

- index rows by `PositionId`, group by `ParentPositionId`
- sort siblings by `SortOrder`, then `DisplayTitle`
- a `seen{}` cycle guard and a depth cap (12, matching the admin console)
- an orphan — a row whose parent is not in the set — renders at root rather than vanishing

`OfficerPosition::WouldCreateCycle()` protects *writes*; it says nothing about rows already
in the table, so the render guards are not redundant.

**The arrow** sits in the existing `<h4 class="kn-bare-heading">`, beside the pencil when
the viewer can manage. It copies the established `.pna-card-more` affordance
(`Playernew_index.tpl:1643`, CSS `:260-261`) rather than inventing a chevron.

**Stacking.** The officer modal sits at `--z-modal`; the history Add/Edit modals, which
move with the tab, sit at `--z-modal-top` so they open above it. This is the relationship
`ka-overlay-top` already uses (`admin-console.css:255`).

**Overlay registration.** The new overlay id must be added by hand to *two* selector lists
in `revised.css` — the base rule at `:1785-1839` and the `.kn-open` list at `:1840-1860`.
Visibility is a class toggle, not `display`. Omitting either leaves a modal that never
appears or never hides.

### Behaviour

- **Vacant supporting positions do not appear**, in sidebar or modal. `buildOfficerRows`
  already excludes them in SQL when `hide_when_vacant = 1` — the flag's purpose.
- **Vacant crown positions keep showing as "Vacant"** in both sidebar and modal, as today.
- **Supporting positions with no parent** render as top-level entries in the Current
  Officers tab, below the crown group — present in the modal, absent from the sidebar.
- **The history role filter** (`#kn-oh-role-filter`) comes along into the modal tab.

## Removals

- The Officer History nav item and panel from `Kingdomnew_index.tpl` (`:349-351`, `:913-946`)
  and `Parknew_index.tpl` (`:620-622`, `:1295-1329`).
- `partials/_officer_panel.tpl` — dead, and a decoy for the next reader.

## Non-goals

- The Player profile's officer card. Different data shape, different semantics.
- Joining officer history to the position tree. History keys off a role string; changing
  that is a data migration, not a presentation change.
- Rewriting the existing sidebars beyond the crown filter.
- Any change to Officer Admin — the crown-designation requirement is already met.

## Testing

**Unit** — the tree builder: nesting, sibling order by `SortOrder`, an orphan whose parent
is absent, a cycle, the depth cap.

**Integration** — `buildOfficerRows` emits `PositionId`, `Classification` and `SortOrder`
in both kingdom scope and park scope; existing keys unchanged.

**Browser** — Kingdom and Park profiles, light and dark, at narrow width: crown-only
sidebar, arrow opens the modal, both tabs render, history lazy-loads, add/edit modals stack
above the officer modal. Plus the Officer Admin crown-designation check named above.

**Baseline:** unit 278 / 0 failures, integration 358 / 0 failures. Any new failure is ours.

## Risks

- **Both features are near-empty in production data.** `ork_officer_history` holds 1 row,
  and exactly one nested position exists (`Royal Scribe` under Regent, kingdom 17). The
  nested view will be empty for every other kingdom on day one, so the layout cannot be
  validated against real data — seed fixtures deliberately.
- **Park is the volume surface**: 5,395 park-level officers vs 191 kingdom-level. Park
  correctness matters more than the officer counts on the Kingdom page suggest.
- **`buildOfficerRows` is shared by more consumers than the two profiles.** Confirmed call
  sites: `controller.Kingdom.php:246` (`profile()`, the surface being changed),
  `controller.Kingdom.php:64` (`index()`, the legacy Kingdom index rendering a *different*
  template), `model.Kingdom.php:50`, `model.Park.php:39`, and
  `controller.OfficerAdminAjax.php`. The change is additive, so none should break — but
  confirm nothing iterates the row array assuming a fixed key set, and note that the
  legacy `index()` template is deliberately untouched by the sidebar filter.
