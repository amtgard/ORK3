# Officer Transition — Design

**Date:** 2026-08-29
**Branch:** `feature/officer-admin-expansion`
**Status:** Awaiting user review

## Problem

Putting a new person in an office is a bare assignment. Nothing tells the admin that a
term is being opened on the history rolls, nothing lets them say when the outgoing
officer actually left, and the note they type is silently discarded. The controls live
on the public Kingdom and Park profile pages rather than in the officer admin area.

Underneath that, the officer write path diverged from how every other write in this
application works: it is the one capability that cannot be reached by anything but a
single controller, and the one that takes the acting user's identity as an argument
rather than proving it from a token.

## Decisions

Settled with the user before design:

| Question | Decision |
|---|---|
| Flow shape | **Stepped wizard**, three steps: close the outgoing term, choose the incoming officer, review and commit. |
| Projected end date | **Dropped.** No `expected_end_date` column, no expiring-term surface. |
| Departure reason | **Dropped.** Step 1 collects an end date only. |
| Park | **Same treatment now**, including a revised-frontend Park admin console. |
| History Add / Edit / Delete | **Moves into the admin area.** The public modal's history tab becomes read-only. |
| Seats per office | **One.** A kingdom wanting two deputies creates two offices. |
| Empty rolls | **Backfill open terms** for every seated officer; unknown starts stay NULL, with a nudge to fill them. |
| Write path | **Domain-gated `$request` API methods**, registered on `orkservice/Json`, invoked in-process by the UI. |

## Current state

### Six write surfaces for one concept

| # | Surface | Location | Writes through |
|---|---|---|---|
| 1 | Kingdom "Edit Officers" pad | `Kingdomnew_index.tpl:1709` (`kn-editoff`), `revised.js:4966` | `KingdomAjax/setofficers`, `/vacateofficer` |
| 2 | Park "Edit Officers" pad | `Parknew_index.tpl:2691` (`pk-editoff`), `revised.js:13223` | `ParkAjax/setofficers`, `/vacateofficer` |
| 3 | Legacy Set Kingdom Officers | `Admin/setkingdomofficers`, `default/Admin_setofficers.tpl` | `Kingdom::SetOfficer` |
| 4 | Legacy Set Park Officers | `Admin/setparkofficers`, linked from `default/Admin_park.tpl:10` | `Park::SetOfficer` |
| 5 | Manage Officers assign modal | `_manage_officers.tpl:193` | `OfficerAdminAjax/setoccupant` |
| 6 | History Add / Edit / Delete | `_officer_details_modal.tpl`, on the **public** profile page | `KingdomAjax|ParkAjax/{add,edit,delete}officerhistory` |

Surfaces 1–4 collect no dates and no note. Surface 5 collects all three and discards
two of them. Surface 6 edits the rolls directly with no relationship to any of the others.

### Three defects in the write path

1. **Term dates vanish for crown offices.** `SetOfficerByPosition` (`class.OfficerPosition.php:1373`)
   accepts `$term_start` and `$term_end`, then its crown branch delegates to
   `Common::set_officer`, whose `record_officer_history` hardcodes `date('Y-m-d')`
   (`common.php:986,1006`). Only the supporting branch honours them, at
   `InsertOfficerRow:1817`. A crown transition cannot be backdated.

2. **`$note` is never written.** It is accepted by `actionSetOccupant`
   (`controller.OfficerAdminAjax.php:490`), passed to `SetOfficerByPosition`, and dropped.
   `ork_officer_history.notes` exists and is already read back by `GetOfficerHistory`
   (`class.Kingdom.php:1449`). Nothing populates it.

3. **The outgoing term always closes today.** Both `record_officer_history`
   (`common.php:998`) and `CloseOfficerHistoryTerm` (`class.OfficerPosition.php:1737`)
   stamp `date('Y-m-d')`. There is no way to record that a Regent's term ended three
   weeks ago at Midreign.

### The architectural divergence

The application has exactly one write pattern. `Player::AddAward` (`class.Player.php:3769`)
is representative: a `$request` array carrying a `Token`, `IsAuthorized($request['Token'])`
resolving the actor, `checkPermissionOrAuthority` gating the action, and the class
registered in `orkservice/Json/index.php` so any client can call it. The UI reaches the
same method in-process — `class.APIModel.php:12` is `new $APISource`, not a network hop —
so the internal write path and the external API are the same code.

The officer work departs from this on every point:

| | `Player::AddAward` | `OfficerPosition::SetOfficerByPosition` |
|---|---|---|
| Shape | `$request` array | positional arguments |
| Actor identity | proven — `IsAuthorized($request['Token'])` | **asserted** — `$changed_by` parameter |
| Authorization | in the domain method | only in `controller.OfficerAdminAjax.php:90` |
| Audit | (see note) | none |
| API-registered | yes (`Player`) | **no** |

`Kingdom::SetOfficer` — the *older* path — does audit officer changes
(`class.Kingdom.php:1370`). The newer path does not, so officer changes lose audit
coverage as kingdoms migrate onto it.

**Identity-by-argument is the blocking defect.** Every mutating method on both new
classes takes the actor as a parameter: `OfficerPosition` uses `$creator_id`,
`$changed_by`, `$acting_uid`; `RBACService` uses `$granter_id`, `$revoker_id`,
`$creator_id`, `$editor_id`, `$deleter_id`. `GrantRole` does prevent privilege
escalation by comparing the granter's own permissions (`class.RBACService.php:355-363`),
but it compares them for whoever the caller *claimed* to be. Registering these classes
on the API as they stand would let any caller name any actor.

### Audit of the rest of the branch

Award creation and management **conform** and need no work: `Award::CreateAward`,
`Award::EditAward`, `Player::AddAward`, `Player::UpdateAward` and `Player::DeleteAward`
are all `$request`-shaped and token-gated, on classes already registered. The branch's
+326 lines of award work (kingdom ladders, max rank, Zodiac months) extended these
methods without breaking the pattern.

Non-conformant, and introduced by this branch:

| Class | Mutating methods | `$request` | Auth in domain | Audit | Registered |
|---|---|---|---|---|---|
| `OfficerPosition` | 7 | no | no | no | no |
| `RBACService` | 8 | no | no | no | no |
| `PermissionRegistry` | 2 (seed/bootstrap) | no | no | no | no |

`RBACService`'s mutators are reachable from **two** controllers, not one:
`controller.OfficerAdminAjax.php:90`, and the role-management block this branch added to
`controller.KingdomAjax.php` (`grantrole`, `revokerole`, `createrole`, `editrole`,
`deleterole`, gated on `kingdom.auth.manage`). Both gate correctly today. Two gated
callers is two chances for a third that doesn't.

`ConfigRegistry` was checked and is clear on writes, but not as small as it first looked:
21 functions, of which **10 are `public static`** (`GetAll`, `Exists`, `Get`, `Label`,
`Groups`, `GetByGroup`, `GetGrouped`, `FilterKnown`, `Count`, `Validate`). All are
PascalCase readers over an in-memory catalog with no DB access. It stays unregistered —
which, given the all-or-nothing registration behaviour described below, is a decision
rather than an oversight.

Pre-existing and explicitly **out of scope** (verified against `master`):
`AddAward`/`UpdateAward`/`CreateAward`/`EditAward` not calling `dangeraudit`;
`class.QualTest.php`'s 25 unguarded write methods; `Award::create_award`,
`Kingdom::create_kingdom_awards`, `Park::ParkGeocode`, `Player::SaveDietaryPreferences`.

### Why nothing caught it

`bin/check-layering.sh` is not on this branch or on `master`. `git log --all` shows it
only ever landed on `feature/front-door` (`abacad91`), and this branch's `.githooks/`
holds only `pre-commit`, with no layering step. Its six rules would not have caught this
anyway — they test for `$DB->` and `new Domain()` *inside `orkui/`*. These classes are
correctly placed; it is their shape that is wrong, and no rule tests shape.

### The data

Measured against a production backup on 2026-08-29:

| | |
|---|---|
| Seated officers (`mundane_id > 0`) | 2,507 |
| ...with a usable `ork_officer.modified` | 66 (2.6%) |
| ...with zero/null `modified` | 2,441 (97.4%) |
| Rows in `ork_officer_history` | 1 |
| Seated officers with no open term | **2,507** |
| History rows with a future `end_date` | 1 |

Three assumptions the backfill depends on were checked and hold: **0** seated officers
sit in an inactive or deleted park, **0** reference a missing `ork_mundane` row, and
**0** positions hold more than one occupant — so the backfill population is clean, and
one-seat enforcement is additive rather than a migration.

The single history row is `officer_history_id 1`: kingdom 17, `royal_scribe`,
`start_date 2026-08-29`, `end_date 2026-11-30`. Mundane 46193 still holds the office
(`officer_id 5850`), but because `end_date IS NULL` is what defines "current", that
officer already reads as departed. Someone used the Term End field as a projected end
date — the exact trap that led to dropping the concept.

## Architecture

### Domain API methods

Every officer and role write becomes a `$request`-shaped, self-authorizing domain method
matching `Player::AddAward`:

```php
public function TransitionOfficer($request)
{
    if (($actor_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0) {
        return NoAuthorization();
    }
    $scope    = ((int)$request['ParkId'] > 0) ? 'park' : 'kingdom';
    $scope_id = ($scope === 'park') ? (int)$request['ParkId'] : (int)$request['KingdomId'];
    // The permission KEY is scoped too, not just the scope argument -- see the matrix below.
    if (!Ork3::$Lib->authorizationgate->checkPermissionOrAuthority(
            $actor_id, $scope . '.officer.set', $scope, $scope_id, AUTH_EDIT)) {
        return NoAuthorization();
    }
    // validate → close outgoing term → open incoming term → note → RBAC sync
    $safe_request = $request;
    unset($safe_request['Token']);   // never audit the credential — Kingdom::SetOfficer:1372
    Ork3::$Lib->dangeraudit->audit(__CLASS__ . '::' . __FUNCTION__,
        $safe_request, $scope, $scope_id, $before, $after);
    return Success();
}
```

Five rules govern the conversion:

1. **The actor comes from the token, never from an argument.** `$changed_by`,
   `$creator_id`, `$acting_uid`, `$granter_id`, `$revoker_id`, `$editor_id`,
   `$deleter_id` are all removed from the public signatures and derived from
   `IsAuthorized`. This is what makes registration safe.
2. **Scope is derived inside the domain** from `ParkId`, and so is the permission key.
   `checkPermissionOrAuthority` maps `'park'` to `AUTH_PARK`
   (`class.AuthorizationGate.php:52-57`), so the scope argument works — but the *key*
   must change with it. `kingdom.officer.set` checked in a park scope is a kingdom
   permission looked up against a park id, and would simply fail. This is a hard
   requirement, not a nicety — see *Permissions* below.
3. **The UI supplies the token from the session.** The controller passes
   `$this->session->token` into the model, which puts it in `$request['Token']` — the
   same way `model.Kingdom::set_officers($token, …)` and `model.Attendance::add_attendance($token, …)`
   already do. The controller keeps a cheap `isset($this->session->user_id)` check to
   fail fast on a logged-out request, but it is no longer the security boundary. Note the
   standing gotcha: the controller session accessor is `$this->session->user_id`, not
   `$this->__session`, which silently yields uid 0.
4. **`IsAuthorized` must run before `audit`, and that is load-bearing.**
   `DangerAudit::audit` does not take an actor. It reads
   `$_SESSION['is_authorized_mundane_id']` (`class.DangerAudit.php:65`), which is
   populated as a side effect of `Authorization::IsAuthorized`
   (`class.Authorization.php:982`). The order in the sample above is therefore not
   stylistic: authorize first, or every audit row is attributed to uid 0. This is
   asserted by a test rather than left to the next editor to notice.
5. **Existing positional methods become private helpers.** Most of the working logic
   (`EnsureCrownSlot`, `CloseOfficerHistoryTerm`, `InsertOfficerRow`, the crown advisory
   lock, cycle detection in `ValidateParent`) is not rewritten — it is wrapped. The one
   exception is the crown-uniqueness check, which is wrong against production data and is
   deleted rather than preserved, because the ORK imposes no such limit. See the next
   section.

### Methods converted

`OfficerPosition` — 7 public mutators, each gaining `$request` + token + gate + audit:
`CreatePosition`, `EditPosition`, `ReorderSiblings`, `RetirePosition`,
`ReinstatePosition`, `SetOfficerByPosition`, `VacateOfficerByPosition`. Plus four new
ones: `TransitionOfficer`, `AddHistoryTerm`, `EditHistoryTerm`, `DeleteHistoryTerm`.

`RBACService` — 8 public mutators: `GrantRole`, `RevokeRole`, `CreateRole`, `EditRole`,
`DeleteRole`, `SyncOfficerRole`, `SyncOfficerRoleByPositionId`, `SyncNewOfficerSlot`.
The three `Sync*` methods are internal — called from `Common::set_officer` and
`InsertOfficerRow`, never by a client — so they are renamed with underscores
(`sync_officer_role`, `sync_officer_role_by_position_id`, `sync_new_officer_slot`), which
puts them permanently out of the dispatcher's reach without token-gating an internal call.
They cannot simply be made `private`: they are called from another class. The five user-facing
ones get the full treatment. `GrantRole`'s escalation check stays, now comparing the
*proven* actor's permissions.

`PermissionRegistry::SyncToDatabase` is a deployment-time seed, not a user action. It is
made internal and excluded from the API rather than token-gated.

### API registration is all-or-nothing per class

`JsonServer::validate_method` (`class.JsonServer.php:398`) applies **no method
whitelist**. It refuses only a lowercase-initial name, a name containing `_`, and
`__construct`; `METHOD_ANY` then returns true. Whitelisting a class therefore exposes
**every public PascalCase method on it**, including ones added later.

Two consequences the draft of this design initially missed:

**`Kingdom::SetOfficer` is already externally callable.** `Kingdom` is whitelisted and
the method is public PascalCase, so `?call=Kingdom/SetOfficer` reaches the domain today.
That is exactly why it is token-gated — and it is the reason the officer methods must be
gated *before* registration, not after.

**Registering the new classes as they stand would leak data.** The exposure surface is
14 methods on `OfficerPosition` and 21 on `RBACService`. Among the latter:
`GetEffectivePermissions`, `GetUserRoles`, `GetKingdomRoleAssignments`,
`GetAllPermissions` — who holds which permissions, across every kingdom, unauthenticated.
`InvalidateUserCache` is an unauthenticated cache eviction. `DisplayTitleSql` and
`SortOrderSql` are SQL-fragment builders that have no business being API verbs; they are
renamed `display_title_sql` / `sort_order_sql`.

So registration is split, and the split is deliberate:

| Class | This work |
|---|---|
| `OfficerPosition` | **Gate, then register.** All 14 public methods audited first; the 7 mutators token-gated; the readers cover officer data that is already rendered on public profile pages. |
| `RBACService` | **Gate, do not register.** The 5 user-facing mutators lose identity-by-argument — that is the actual security defect, and it is fixed here. Its 21-method read surface needs its own pass before whitelisting, which is not this feature's job. |
| `PermissionRegistry`, `ConfigRegistry` | Neither gated nor registered. Seed and catalog code. |

`orkservice/Json/index.php:19` gains `'OfficerPosition'` only. Both classes are already
in `Ork3::$Lib` — `startup.php:57-70` auto-registers every `system/lib/ork3/class.*.php`
by lowercased name — so registration is the whitelist entry alone.

**Hiding a method from the dispatcher: what actually works.** The obvious lever —
renaming a method lowercase-initial, since `validate_method` rejects a lowercase-initial
*request* — **does not work**, and the plan must not rely on it. PHP method names are
case-insensitive, and the caller controls the casing of the requested string. Verified:

```php
class T { public function lowerStart($x) { return "REACHED: $x"; } }
$requested = "LowerStart";                       // what an API caller sends
ctype_lower(substr($requested, 0, 1));           // false -> validate_method ALLOWS it
method_exists(new T(), $requested);              // true  -> case-insensitive
(new T())->$requested("payload");                // "REACHED: payload"
```

Two levers do work, and each was verified the same way:

- **An underscore in the name.** To reach `sync_officer_role` a caller must request a
  string containing `_`, and `validate_method` rejects that outright. Use this for
  internals that another class must still call — `RBACService`'s three `Sync*` methods
  (called from `Common::set_officer` and the officer insert path) and the two SQL-fragment
  builders (called from `class.Kingdom.php`, `class.Park.php`, `common.php`). This is also
  the idiomatic shape here: `common.php` names its own methods `set_officer`,
  `record_officer_history`.
- **`private` visibility.** Invoking a private method from outside throws `Error`, so it
  never executes. Use this for helpers nothing outside the class calls. It is the stronger
  lever, but it is not available across class boundaries, and it surfaces as a 500 rather
  than a clean refusal — so underscore naming is preferred at the boundary.

After this pass the rule is: **on a registered class, any public method without an
underscore is deliberate API surface and must be gated.** That is a rule a reviewer can
apply, and `ApiExposureTest` enforces it.

This is corroborated by the dispatcher's own comment at `class.JsonServer.php:265`:
*"orkservice/Json/index.php is the only caller that ever constructs a JsonServer, so
every call reaching this dispatcher is, by definition, real API traffic — never a
browser/UI request (the UI never touches this endpoint; it goes through orkui
controllers instead)."* The in-process path for our own frontend and the HTTP path for
external clients converging on one gated method is the intended architecture, stated in
the code.

### Permissions

The registry already defines a full park mirror
(`class.PermissionRegistry.php:56-72, 139-157`). The correct key is picked per action
*and* per scope:

| Action | Kingdom key | Park key |
|---|---|---|
| Assign / transition | `kingdom.officer.set` | `park.officer.set` |
| Vacate | `kingdom.officer.vacate` | `park.officer.vacate` |
| Create/edit/retire a position | `kingdom.officer.position.manage` | `park.officer.position.manage` |
| Add/edit/delete a history term | `kingdom.officer_history.manage` | `park.officer_history.manage` |

Two things this table fixes that are wrong today:

- **`kingdom.officer.vacate` and `kingdom.officer_history.manage` are defined but never
  checked by the officer admin.** `controller.OfficerAdminAjax.php:70-84` maps every
  vacate action to `kingdom.officer.set`. The distinct permissions exist and are honoured
  by the *legacy* path in `class.Kingdom.php`, so the newer console is strictly coarser
  than the one it replaces.
- **`park.officer.position.manage` is defined and checked by nothing at all.** It has no
  reference anywhere outside the registry. Building a Park officer admin without wiring
  it would ship park position management ungated.

### The park authorization gap

`controller.OfficerAdminAjax.php:90` gates every action on
`('kingdom.officer.set', 'kingdom', $kingdom_id)` — kingdom scope, hardcoded. The park
edit pad instead authorizes through `Park->set_officers($token, …)`, which honours
park-scoped standing. **Removing the park pad without a scope-aware gate would lock out
every park officer who can manage their own park's officers today.** Rule 2 above closes
this; it is a prerequisite of surface removal, and must be verified before the pad is
deleted.

## The wizard

Three steps, in a modal hosted by the officer admin area. Rendered by one shared
partial, `partials/_officer_transition.tpl`, prefix `ot-`, following the page-agnostic
include contract that `_manage_officers.tpl` and `_officer_details_modal.tpl` already use.

**Step 1 — Close the outgoing term.** Shows who is seated and since when. Where the
start date is unknown (97% of rows today) it says so plainly and offers an optional
field to supply it, rather than inventing one:

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

**Step 2 — The incoming officer.** Player search, scoped to the org being edited per
the standing `kn-ac-results` pattern, term start defaulting to the outgoing officer's end date, and
the note field — which for the first time is actually persisted.

**Step 3 — Review.** Plain-language statement of every record that will change, then one
Confirm that posts once.

Entry points on an office card in Manage Officers:

- Occupied → **Transition** (all three steps) and **Vacate** (step 1 only).
- Vacant → **Appoint** (step 2 and 3 only; step 1 is skipped, not shown empty).

### Validation

Enforced in the domain, so the API and the wizard cannot diverge:

- End date may not precede the term's start date.
- End date may not be in the future. This is what makes the `royal_scribe` class of row
  impossible to create again.
- Incoming start date may not precede the outgoing end date.
- Start date may be NULL (unknown); end date on a closing term may not.
- The incoming officer must be a member of the scope, matching the existing rule in
  `Kingdom::SetOfficer:1348`.

## The crown-uniqueness check is removed

`SetOfficerByPosition` enforces *"A person may hold only one Crown office"* across every
scope at once (`class.OfficerPosition.php:1420-1447`). **The ORK does not limit holding
multiple offices, or offices at multiple levels.** The check is an invention of this
branch, it does not describe how Amtgard works, and against the production backup it
would refuse **242 people who hold office right now** — 176 of them holding two offices
in the same park, which is ordinary practice in a small park.

The check is deleted. No scoped or softened replacement is written, and multi-office
policy is explicitly not in scope for this work.

One consequence to handle while removing it: the advisory lock wrapping the check
(`GET_LOCK('crown_assign_' . $mundane_id)`) is keyed on the **person**, because
serialising the cross-scope conflict query was its whole purpose. With the query gone,
that key guards nothing that matters — two admins assigning *different* people to the
*same* office are not serialised, which is the race a transition actually has. The lock
is re-keyed to the office being written (`kingdom_id`, `park_id`, `position_id`). This is
a correctness fix inside code being rewritten anyway, not a new feature.

## One seat per office

This is the mirror image of the rule removed above, not a survivor of it. **An office
holds one person; a person may hold any number of offices, at any number of levels.** The
constraint is on the seat, never on the human.

`Occupants[]` collapses to `Occupant` in `actionList` (`controller.OfficerAdminAjax.php:298`),
so crown and supporting rows carry the same shape. `InsertOfficerRow` refuses a second
holder instead of appending. Assigning to an occupied office is a transition whatever the
classification.

**The `vacateall` HTTP action is retired; the service path behind it is not.**
`RetirePosition` (`class.OfficerPosition.php:1180`) calls `VacateOfficerByPosition` with
no `$mundane_id`, deliberately relying on all-holders semantics — retiring a position has
to clear it in every park *and* the kingdom at once. One-seat is a **per-scope** rule;
retire is **cross-scope**. Collapsing the two would break position retirement. The
service method keeps the all-holders branch as a `private` internal path, and only the
single-holder vacate survives as a client-callable verb.

Verified safe to enforce: a production backup has **zero** positions holding more than
one occupant, so no reconciliation of existing data is required. The constraint is
additive.

## Migration — data only, no DDL

`ork_officer_history.start_date` and `end_date` are already `DEFAULT NULL`
(`db-migrations/2026-08-25-03-officer-history.sql`), so nothing is altered.

`db-migrations/2026-08-29-officer-history-backfill.sql`, idempotent:

1. **Repair the future-end-date rows first** — the reopen must run BEFORE the insert. An officer
   whose only row carries a future `end_date` has no *open* term, so an insert-first order would add
   a second one and leave them with two. Written generally (`end_date > CURDATE()` joined to a
   still-seated holder), not against the one known id.
2. **Open a term for every seated officer with none** — 2,506 rows after step 1.
   `display_label` snapshots the position's DisplayTitle using the same tiering as
   `OfficerPosition::display_title_sql()`.

**`start_date` is written as NULL for every row. (Revised during implementation — see below.)**

The original design said to use `ork_officer.modified` where usable (66 of 2,507 rows), NULL
otherwise. That was wrong, and the branch had already said so: commit `52f729f7` concludes *"Any
history backfill has to treat pre-existing rows as start-date-unknown rather than reading this
column."* The data confirms it — of the 66 "usable" values, **42 share a single date across 42
distinct parks** and 17 share another across 17. Those are bulk row-creation artifacts, not people
taking office. Writing them would be exactly the inferred-date-as-recorded-fact bug this design
rejects elsewhere, and the one genuinely trustworthy value belongs to the row the insert skips.

So every backfilled `start_date` is NULL, and the unknown-start-date nudge below is the only path
by which the rolls acquire real start dates.

**Re-run safety is conditional, and the migration header says so.** `InsertOfficerRow` was the one
history writer that accepted a caller-supplied future `TermEnd` without validation — which is how
the single bad production row was created. That rejection is now in place, so no new future-dated
row can appear and step 1 matches nothing on a second run. Against a database built from an older
release, step 1 would erase a legitimately projected term end with no audit row.

Per the standing rule, the new file must be classified in ork-db's
`migration-classification.json5` or `drift-check --strict` blocks the unit-test run.

### The unknown-start-date nudge

An office card whose sitting officer has a NULL `start_date` shows an inline prompt —
"Start date unknown · Set it" — opening a one-field editor. This is the only consumer
built for the NULL state, and it exists so the backfill's honesty about what it does not
know converts into data over time rather than sitting there permanently.

## Surfaces

### Removed

| Surface | What goes |
|---|---|
| Kingdom edit pad | `Kingdomnew_index.tpl:1709` markup, the `revised.js:4966` IIFE, `kn-editoff-*` CSS, the `:326` button |
| Park edit pad | `Parknew_index.tpl:2691` markup, the `revised.js:13223` IIFE, `pk-editoff-*` CSS, the `:553` button |
| Legacy set-officers pages | `Admin/setkingdomofficers`, `Admin/setparkofficers`, `default/Admin_setofficers.tpl` |
| History write buttons on the public modal | Add / Edit / Delete controls in `_officer_details_modal.tpl`; the history tab becomes read-only |

Retired endpoints: `KingdomAjax/setofficers`, `KingdomAjax/vacateofficer`,
`ParkAjax/setofficers`, `ParkAjax/vacateofficer`, and the six
`{add,edit,delete}officerhistory` actions across both Ajax controllers. Verified safe to
retire: these are `orkui` Ajax routes with no SOAP service behind them and no caller
outside `orkui/`. They are **not** the same thing as `Kingdom/SetOfficer`, which is a
registered API verb and stays. `model.Principality::set_officers` is dead code with no
callers and is removed with them.

`Kingdom::SetOfficer`, `Kingdom::VacateOfficer`, `Park::SetOfficer` and
`Park::VacateOfficer` are **kept**. They are correctly-shaped API methods on registered
classes, and external clients may already call them.

**Convergence must be explicit, because the obvious wiring double-writes history.**
`Kingdom::SetOfficer` calls `Common::set_officer`, which calls `record_officer_history`
(`common.php:946`) and writes a term on its own. If `TransitionOfficer` writes its own
term *and* delegates to `set_officer`, every transition produces two history rows and
closes the outgoing term twice. The rule: exactly one function writes
`ork_officer_history` per transition. `Common::set_officer` gains an optional
`$skip_history` flag defaulting to false — so every existing caller is unchanged — and
the new path sets it, owning the history write itself along with the dates and note that
`record_officer_history` cannot express. A test asserts one row in, one row closed.

### Added

- `partials/_officer_transition.tpl` — the wizard, shared by Kingdom and Park.
- A "Correct the Rolls" tab in the officer admin area, hosting Add / Edit / Delete
  against the new token-gated history methods.
- `revised-frontend/Admin_park.tpl` — a Park admin console. The legacy template it
  replaces is 55 lines: a link list plus a reset-waivers confirm. It gains the officers
  card that hosts `_manage_officers.tpl` scoped to the park. `Admin::admin('park')` at
  `controller.Admin.php:2535` is re-pointed at it, and its front-door authorization
  check (`:2283`) is preserved unchanged.
- `_manage_officers.tpl`'s include contract gains `$mo_park_id`, defaulting to 0, so one
  partial serves both scopes.

## Testing

Extends the existing suites (`OfficerPositionReorderTest`, `OfficerPositionReinstateTest`,
`OfficerHistoryRowShapeTest`, `OfficerRowShapeTest`), which already have the fixtures.

- **Authorization** — every converted method rejects an absent, invalid, and
  insufficiently-privileged token. A park-scoped actor succeeds on their own park and is
  refused on another. This is the regression test for the gap the removal would open.
- **Identity** — a request naming a different actor than the token's owner is attributed
  to the token's owner, not the claim.
- **Term correctness** — a crown transition backdated to a past date writes that date,
  not today. This fails against current code, which is the point.
- **The note persists** — round-trip through `GetOfficerHistory`.
- **Validation** — each rule above rejected, including a future end date.
- **One seat** — a second occupant is refused for both classifications.
- **Migration** — idempotent on re-run; the 66/2,441 split lands correctly; the
  future-end-date row reopens; a re-run does not reopen a legitimately closed term.
- **Audit** — a transition writes a `dangeraudit` row with correct before/after, and
  `by_whom_id` is the token's owner rather than 0 (the `IsAuthorized`-before-`audit`
  ordering dependency).
- **No office limits** — a park officer can take a kingdom office, and a person can hold
  two offices in one park. Both are refused by current code and must pass.
- **History is written once** — a transition produces exactly one new term and closes
  exactly one, with `Common::set_officer`'s own history write suppressed.
- **Position retirement still clears every scope** — `RetirePosition` on a position held
  in three parks vacates all three, proving the all-holders path survived the removal of
  the `vacateall` HTTP action.
- **Permission keys** — a park actor holding only `park.officer.set` can assign but not
  vacate (needs `park.officer.vacate`) and not manage positions (needs
  `park.officer.position.manage`).
- **Exposure** — a test enumerates every public PascalCase method on each registered
  class and asserts each is either token-gated or on an explicit reviewed-public list.
  This is the regression guard for the all-or-nothing registration behaviour: without it,
  the next PascalCase method added to `OfficerPosition` is silently published.

## Out of scope

- The Player profile's officer card. Different shape (`Player::GetOfficerRoles`), spans
  many orgs.
- The pre-existing audit and authorization gaps listed under *Audit of the rest of the
  branch*. Named, deliberately not fixed here.
- Registering `RBACService` on the API. Its 5 user-facing mutators are fixed here; its
  21-method read surface needs an authorization pass first.
- Restoring `bin/check-layering.sh` to this branch, and extending it with a rule that
  tests domain-method *shape*. Worth doing; it is its own piece of work.
- Any limit on holding multiple offices, or offices at multiple levels. The ORK does not
  impose one; this work does not introduce one.
- Officer terms as first-class objects with succession planning. Not asked for.

## Risks

- **Removing four write surfaces at once.** Mitigated by the park-scope test above and by
  verifying in the browser as a park-only officer before the pads are deleted. Order the
  work so authorization lands and is proven before removal.
- **A 2,507-row backfill against a table holding 1 row.** Low blast radius — the table is
  effectively unused — but it is the first time the rolls carry real data, so read
  surfaces that have only ever rendered an empty list will render 2,507 open terms.
  `_officer_details_modal.tpl`'s history panel should be checked against a populated table.
- **Converting `RBACService` while officer work depends on it.** The three `Sync*` methods
  are called from `Common::set_officer` and `InsertOfficerRow`; making them internal must
  not change those call sites' behaviour. Convert the five user-facing methods first,
  separately.
