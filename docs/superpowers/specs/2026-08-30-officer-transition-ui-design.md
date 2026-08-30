# Officer Transition UI — Design (Plan 2 of 2)

**Date:** 2026-08-30
**Branch:** `feature/officer-admin-expansion`
**Depends on:** `docs/superpowers/specs/2026-08-29-officer-transition-design.md` (Plan 1, shipped)
**Status:** Awaiting user review

## Problem

Plan 1 built the write path: twelve token-gated domain methods, an audited transition that can
backdate a term and carry a note, and a backfill that gave 2,507 seated officers an open term. None
of it is reachable from the UI. `TransitionOfficer` has no controller action at all — it is callable
only over the JSON API.

Meanwhile the surfaces Plan 1 was meant to replace are still live: two "Edit Officers" pads that
collect no dates and no note, two legacy set-officers pages, and Add/Edit/Delete history controls
sitting on the **public** profile page. Officer writes still have six homes.

## Confirmed against Plan 1 as shipped

Plan 1's own documents diverged from what landed in six places. This design is written against the
code, not against Plan 1's plan.

| Claim in Plan 1's docs | What actually shipped |
|---|---|
| Plan 2 "retires the now-redundant `$gate` array" | **It is not redundant.** `actionList`'s `personaLabel` (`controller.OfficerAdminAjax.php:229`) falls back to `GivenName + Surname` when a persona is blank, so an ungated `list` emits real legal names. The gate must become scope-aware, not disappear. |
| `Occupants[]` collapses to `Occupant` | **It did not.** `actionList:307,311` still emits plural for supporting and singular for crown; the JS normalizes at `_manage_officers.tpl:594-596`. Task 8 only changed `InsertOfficerRow`. |
| `TransitionOfficer` is wired up | **No controller action exists.** It is absent from both the `$gate` map and the dispatch switch. |
| — | `VacateOffice($request)` exists as a second vacate verb (no `MundaneId`), added when deleting `vacateall` broke the console's only crown Vacate button. The `vacateall` route survives, pointed at it. |
| — | `EditHistoryTerm`/`DeleteHistoryTerm` take **only** `OfficerHistoryId` + `Token` (+ dates/note). They derive kingdom and park from the stored row. The Correct-the-Rolls UI must not send scope for them. |
| — | Plan 1's final fix made `TransitionOfficer` accept a backdated `TermStart` on a **vacant** office. That is precisely what the 2-step Appoint path needs, and it is already tested. |

`TransitionOfficer` accepts: `Token, KingdomId, ParkId, PositionId, MundaneId, OutgoingEndDate,
OutgoingStartDate, TermStart, Note`. That is exactly the wizard's payload.

## Decisions

| Question | Decision |
|---|---|
| Wizard host | **Replaces the panel in place** inside the existing Manage Officers modal, with a back arrow. One overlay, one scroll context, no new z-index tier. |
| The existing assign modal | **Deleted.** The wizard subsumes it: 3 steps when the office is occupied, 2 when vacant (step 1 skipped, not shown empty). |
| `setoccupant` | The **controller action is deleted** with the modal it served. The domain method `SetOccupant($request)` stays — it is a registered API verb external clients may call. |
| The `$gate` array | **Made scope-aware**, not removed — see above. |

## Architecture

### The gate, corrected

`controller.OfficerAdminAjax.php:90` currently gates every action on
`('kingdom.officer.set', 'kingdom', $kingdom_id)`. That single hardcoded line is why a park-only
officer is refused before reaching the domain gate Plan 1 built. It is replaced by a per-action,
per-scope map using the helper Plan 1 already shipped:

```php
$park_id = (int)($_POST['ParkId'] ?? 0);
$action_kind = [
    'list' => 'set', 'transition' => 'set',
    'vacate' => 'vacate', 'vacateholder' => 'vacate', 'vacateall' => 'vacate',
    'createposition' => 'position', 'editposition' => 'position',
    'reorderpositions' => 'position', 'reclassify' => 'position',
    'retire' => 'position', 'reinstate' => 'position',
    'roles' => 'position', 'permissions' => 'position',
    'addhistory' => 'history', 'edithistory' => 'history',
    'deletehistory' => 'history',
][$action] ?? null;

$scope    = $park_id > 0 ? 'park' : 'kingdom';
$scope_id = $park_id > 0 ? $park_id : $kingdom_id;
$key      = OfficerPosition::PermissionKeyFor($action_kind, $park_id);
```

Two properties this must preserve: `list` stays gated (it can emit real names), and an unknown
action still fails closed.

**`edithistory`/`deletehistory` are the exception.** Their domain methods authorize against the
*row's* kingdom and park, which the controller does not know. The controller gate for those two uses
the caller's scope only as a cheap pre-filter; the domain remains the authority. This is documented
inline so nobody later "fixes" the apparent inconsistency by trusting the payload.

### New controller actions

`transition` → `OfficerPosition::TransitionOfficer`, and `addhistory` / `edithistory` /
`deletehistory` → the three history write methods. All forward `'Token' => $this->session->token`.

**No new history READ action.** Correct the Rolls reuses the existing `KingdomAjax/officerhistory`
and `ParkAjax/officerhistory` reads — the same endpoints the public modal uses, which survive the
removals below (only the `{add,edit,delete}` writes retire). Duplicating a working, already-gated
read would be churn.

### `Occupants[]` collapses here

`actionList` emits a single `Occupant` key for every classification. One office holds one person as
of Plan 1's Task 8, so the plural array is a shape without a referent.

There are exactly **four** consumers, enumerated rather than assumed: `actionList:307` (the emit) and
three in `_manage_officers.tpl` — the normalizer at `:594-596`, the crown/supporting split at
`:1011-1012`, and the vacancy count at `:714`. All four collapse together. Re-grep before editing;
Plan 1 had four separate enumerations come up short.

## The wizard

One partial, `partials/_officer_transition.tpl`, prefix `ot-`, following the page-agnostic include
contract `_manage_officers.tpl` uses. It renders **into the Manage Officers modal body**, swapping
the officer list out and a breadcrumb in. `moRefresh()` restores the list on cancel or commit.

**Occupied office → Transition (3 steps).** Step 1 closes the outgoing term: who is seated, since
when, and the end date. Where the start is unknown — 2,506 of 2,507 rows after Plan 1's backfill —
it says so plainly and offers an optional field rather than inventing one:

```
Step 1 of 3 — Close the outgoing term
  ●──────○──────○

  Dame Ysolde has served as Regent
  since an unrecorded date.

  ℹ The ORK has no start date on file for this
    term. You can supply one now, or leave it blank.

  Took office   [ unknown        ▾]
  Term ended    [ Aug 15, 2026   ▾]
```

Step 2 is the incoming officer: player search (org-scoped, the standing `kn-ac-results` pattern,
term start defaulting to the outgoing end date, and the note.

`tnFixedAcPosition` needs no new work: `_manage_officers.tpl:521-522` already self-defines it behind
a `typeof window.tnFixedAcPosition !== 'function'` guard, and the wizard renders inside that same
partial, so it inherits both the positioner and the working search at `:1730`. Step 3 states every record that will change, then commits with one POST.

**Vacant office → Appoint (2 steps).** Step 1 is skipped entirely. `OutgoingEndDate` is omitted, and
`TransitionOfficer`'s ordering check is already conditioned on there being an outgoing holder, so a
backdated `TermStart` works — Plan 1 fixed and tested exactly this.

### Validation is the domain's job

The wizard mirrors the domain's rules for immediate feedback (no future end date, end not before
start, incoming start not before outgoing end) but never enforces them alone. Every rejection the
server returns is surfaced verbatim. The client is a convenience, not a gate.

## Correct the Rolls

A second tab inside the Manage Officers modal, beside the position list. Called **Correct the Rolls**
throughout — never "History", which is what the read-only public tab is called. It lists history terms per
office with Edit and Delete, plus "Add a past term". It posts to the new history actions.

Edit and Delete send `OfficerHistoryId` and nothing else identifying — the domain resolves scope from
the row. Add sends the full scope because it is creating a row that does not exist yet.

## The unknown-start-date nudge

An office card whose sitting officer has a NULL start date shows an inline prompt — *"Start date
unknown · Set it"* — opening a one-field editor that posts `edithistory` with a `StartDate`.

This is the only consumer of the 2,506 NULLs Plan 1's backfill wrote, and the mechanism that
converts the migration's honesty into data over time. Without it the backfill's decision to admit
ignorance never resolves.

## Park admin console

`revised-frontend/Admin_park.tpl`, replacing the 55-line legacy template (a link list plus a
reset-waivers confirm).

**This is not a port of the kingdom console.** `Admin_kingdom.tpl` is 446 lines and consumes
`$AdminDashboard`, `$AdminInfo`, `$KingdomInfo` and `$kingdom_info` — a data contract with no park
equivalent, including a dashboard `Kingdom::GetAdminDashboard` builds for kingdoms only. Building a
park dashboard is not in this plan and nobody asked for one.

What the Park console is: the legacy page's own link set, restyled with the `.ka-*` classes the
kingdom console already defines, plus one officers card hosting `_manage_officers.tpl` scoped to the
park. Its only data dependency is `$ParkInfo`, which `Admin::admin('park')` already supplies.

`Admin::admin('park')` (`controller.Admin.php:2535`) is re-pointed at it. Its front-door
authorization check at `:2283` — which accepts park standing alongside kingdom standing — is
preserved unchanged.

### `_manage_officers.tpl` gains `$mo_park_id`

The include contract becomes `$mo_kingdom_id`, `$mo_park_id` (default 0), `$mo_can_manage`. The
partial posts `ParkId` on every request; the controller derives scope from it. One partial serves
both consoles.

## Surfaces removed

| Surface | What goes |
|---|---|
| Kingdom edit pad | `Kingdomnew_index.tpl:1709` markup, the `revised.js:4966` IIFE, `kn-editoff-*` CSS, the `:326` button |
| Park edit pad | `Parknew_index.tpl:2691` markup, the `revised.js:13223` IIFE, `pk-editoff-*` CSS, the `:553` button |
| Legacy set-officers pages | `Admin/setkingdomofficers`, `Admin/setparkofficers`, `default/Admin_setofficers.tpl` |
| History writes on the public pages | The write JS in `Kingdomnew_index.tpl` (`:3513` delete, `:3565` add, `:3626` edit) and its `Parknew_index.tpl` mirror, plus the controls that call them. **Not** in `_officer_details_modal.tpl` — that shared partial contains zero write controls; it renders the read-only view already. |
| The assign modal | The `setoccupant` modal in `_manage_officers.tpl` **and its controller action**, subsumed by the wizard. `OfficerPosition::SetOccupant` (the domain method) stays. |

Retired endpoints: `KingdomAjax/setofficers`, `KingdomAjax/vacateofficer`, `ParkAjax/setofficers`,
`ParkAjax/vacateofficer`, and the six `{add,edit,delete}officerhistory` actions.
`model.Principality::set_officers` is dead code with no callers and goes with them.

`Kingdom::SetOfficer`, `Kingdom::VacateOfficer`, `Park::SetOfficer`, `Park::VacateOfficer` are
**kept** — registered API verbs external clients may already call.

**Removal order is a safety property, not a preference.** The implementation plan must sequence it:
the scope-aware gate lands first and its park-officer test passes; the wizard and Correct the Rolls
land next; the Park console lands; and only then are the four surfaces deleted. Reversing any of that
locks every Sheriff out of their own park, and the deletion commit is the one that makes it
unrecoverable without a revert.

## Inherited follow-ups

From Plan 1's reviews, recorded in its spec and picked up here:

- **Roll integrity.** `AddHistoryTerm` can open a second term on an office that already has one, and
  `EditHistoryTerm` can reopen a closed one by clearing `EndDate`. Either produces two rows with
  `end_date IS NULL`, which reads as an office held by two people — the state the backfill just
  repaired. The Correct-the-Rolls surface is where a person can trip it, so the guard lands here.
- **`RevokeRole` has no row-scope check.** A caller holding `kingdom.auth.manage` for their own
  kingdom can revoke a `user_role` row belonging to another kingdom by id. Same defect class as the
  history rows, same fix: authorize against the row's own scope.
  **Scoping note, stated honestly:** this has no UI component and does not belong to a UI plan. It is
  carried here as an explicitly separate final task purely because it is a one-method domain fix that
  should not wait on unrelated UI work to ship. If this plan is cut short, that task travels
  independently.

## Testing

- **Scope-aware gate** — a park-only officer can list, transition, and vacate in their own park, and
  is refused in another park and at kingdom scope. This is the regression test that makes pad
  removal safe; it must exist and pass *before* any surface is deleted.
- **`list` stays gated** — an unauthenticated and an unprivileged caller are both refused, so the
  `personaLabel` name fallback is never reachable anonymously.
- **Unknown action fails closed** after the gate rewrite.
- **`Occupant` shape** — `actionList` emits the singular key for both classifications and no
  consumer reads `Occupants`.
- **Wizard payload** — a 3-step transition posts every field `TransitionOfficer` reads; a 2-step
  appoint omits `OutgoingEndDate` and succeeds with a backdated `TermStart`.
- **History actions** — edit and delete succeed with only `OfficerHistoryId`, and a row from another
  kingdom is refused even when the caller names their own.
- **Roll integrity** — a second open term is refused on both the add and the edit path.
- **Removal is complete** — no template, JS, or controller references a retired endpoint.

## Out of scope

- The Player profile's officer card (different shape, spans many orgs).
- Strengthening `ApiExposureTest` to assert authorization rather than authentication, and widening it
  beyond `OfficerPosition`. Recorded in Plan 1's spec; its own piece of work.
- Any limit on holding multiple offices. The ORK imposes none.

## Risks

- **Four write surfaces removed at once.** Mitigated by the ordering rule above and by verifying each
  console flow in a browser before its pad is deleted.
- **The wizard replaces a working modal.** `setoccupant` keeps working via the API throughout, so a
  failed wizard is a UI regression, not a loss of capability.
- **`Occupants[]` collapse touches read paths the admin console depends on.** The JS normalizer at
  `:594-596` exists precisely because the two shapes disagree; collapsing them is a simplification,
  but every consumer must be found — grep, not assumption. Plan 1 had four enumerations come up
  short; assume this one will too.
