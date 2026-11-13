# Separate Participant Registration from Bracket Construction

**Date:** 2026-05-31
**Branch:** feature/tournament-module
**Status:** Approved design — ready for planning

## Problem

Today a participant only exists *inside a bracket*. The "Add Participant" button lives on each
bracket card, and `Tournament::AddParticipant()` always writes an `ork_participant` row with a
concrete `bracket_id`. There is no notion of "registered for the tournament but not yet assigned to
a bracket." The Participants tab is a read-only, post-hoc *merged* view that dedupes bracket rows by
`participant_number`.

We want to **decouple registration from bracket assignment**: organizers can register people in the
Participants tab first, and assign them to brackets later — for tournaments that prefer to run that
way.

## Goals

1. Register an individual participant at the **tournament level** (no bracket required) from the
   Participants tab.
2. Assign a registered participant to **one or more** brackets later (one-to-many).
3. Keep the existing **per-bracket quick-add** working (coexist), and have it transparently reuse an
   existing registration when the person is already registered.
4. Do all of this **without rewiring** the match / seed / standings code, which keys off per-bracket
   `ork_participant` rows.

## Non-Goals (YAGNI)

- **Team registration at the tournament level.** Teams stay per-bracket via the existing "Add Team"
  roster flow. (Registered individuals *may* later be offered as picks when building a team, but that
  is a follow-up, not part of this work.)
- No change to bracket generation algorithms, seeding, standings, or match recording.
- No change to the `participant_number` semantics (already tournament-stable).

## Key Decisions (from brainstorming)

| Question | Decision |
|---|---|
| Replace vs coexist | **Coexist** — tournament-level registration is an alternative, not a replacement |
| Multi-bracket | **One-to-many** — register once, assign to any number of brackets |
| Teams | **Individuals only** at tournament level; team brackets unchanged |
| Data model | **Registration rows (`bracket_id` NULL) + per-bracket entrant rows linked by `participant_number`** |
| Assign UI | **Both** — bulk multi-select picker on the bracket, plus per-person add still works |

## Architecture

### Data model

The stable identity is `participant_number` (already tournament-wide).

- **Registration row** — an `ork_participant` row with `bracket_id IS NULL`. Source of truth for "who
  is in this tournament." Carries: `tournament_id`, `alias`, `unit_id`, `park_id`, `kingdom_id`,
  `participant_number`, `warrior_level` snapshot, and tournament-level `status` (`active`/`withdrawn`).
  For an individual it has a matching `ork_participant_mundane` row (with `bracket_id` NULL).
- **Bracket entrant** — when a registrant is assigned to a bracket, a per-bracket `ork_participant`
  row (`bracket_id` set) is created, sharing the registrant's `participant_number`. **These rows are
  exactly what matches / seeds / standings already reference — unchanged.**

> **Schema impact is minimal.** `ork_participant.bracket_id` and `ork_participant_mundane.bracket_id`
> are `NOT NULL` in the live schema; the migration makes them nullable so a registration row can be
> `bracket_id IS NULL`. No new join table is introduced: the per-bracket
> `ork_participant` row *is* the assignment record (it already carries `seed`/`status`/`eliminated`/
> `bracket_side`), and `participant_number` is the link back to the registration row. This realizes the
> approved "registration + assignment" model without a redundant table or rewiring downstream code.
> The migration only needs to ensure the registration-status enum/value exists where required and add
> any helpful index (e.g. `(tournament_id, bracket_id)` / `(tournament_id, participant_number)`).
> The code-architect confirms exact migration contents against `db-migrations/`.

### "Registered but unassigned" query path

`buildFilterWhere()` only emits a `bracket_id =` clause when `valid_id(BracketId)` (>0). It therefore
**cannot currently express `bracket_id IS NULL`**. We add a registration-scoped path:

- A new filter mode (e.g. `RegistrationOnly => true`, or a dedicated `GetRegistrants()` query) that
  produces `... AND p.tournament_id = N AND p.bracket_id IS NULL`.
- `GetRegistrants($request)` returns the tournament roster (one row per registrant), decorated with
  the brackets each is assigned to (computed by joining other `ork_participant` rows of the same
  `participant_number` that have a non-null `bracket_id`).

### Ensure-registrant helper (single source of truth)

Both the explicit "Register Participant" action and the per-bracket auto-register path must converge
on one helper, e.g. `ensureRegistrant($tournament_id, {MundaneId|Alias, ...})`:

1. Resolve `participant_number` using the existing logic in `AddParticipant` (find by `mundane_id`,
   else by alias-only text, else `MAX+1`).
2. If a registration row (`bracket_id IS NULL`) for that `participant_number` already exists, reuse it.
3. Otherwise insert one (with the `warrior_level` snapshot + `ork_participant_mundane` link).
4. Return `{participant_number, registration_participant_id}`.

`AddParticipant` (per-bracket) is refactored to call `ensureRegistrant` first, then create the
bracket entrant row sharing that `participant_number` — so the per-bracket add never creates a
duplicate registrant.

### New DB-layer methods (`system/lib/ork3/class.Tournament.php`)

- `RegisterParticipant($request)` — tournament-level register (individual). Auth via `check_auth`.
  Uses `ensureRegistrant`. No bracket row created.
- `GetRegistrants($request)` — tournament roster + per-registrant bracket-assignment list.
- `AssignToBracket($request)` — given `{TournamentId, BracketId, ParticipantNumbers[]}`, create a
  bracket entrant row per registrant **not already in that bracket**. Bulk. Only when bracket
  `status = 'setup'` (mirror existing field-lock rules). Snapshot `warrior_level` carried from
  registration row (no re-fetch).
- `UnassignFromBracket($request)` — remove the bracket entrant row(s) for given registrants from a
  bracket. Only when bracket `status = 'setup'`. Does **not** delete the registration row.
- `UpdateRegistrationStatus($request)` — set tournament-level `active`/`withdrawn`.
- `RemoveRegistrant($request)` — remove a registration row; cascade-remove that person's entrant rows
  **only** from setup-status brackets; **block** (clear error) if they are in any active/complete
  bracket.

`AddParticipant` / `GetParticipants` are reconciled (auto-register; NULL-bracket awareness) rather
than duplicated.

### AJAX layer (`orkui/controller/controller.TournamentAjax.php`)

Tournament-level actions (in `tournament($p)`):
- `POST .../tournament/{tid}/register` → `register_participant`
- `GET  .../tournament/{tid}/registrants` → `get_registrants`
- `POST .../tournament/{tid}/registrationstatus` → `update_registration_status`
- `POST .../tournament/{tid}/removeregistrant` → `remove_registrant`

Bracket-level actions (in `bracket($p)`):
- `POST .../bracket/{bid}/assign` → `assign_to_bracket` (bulk `ParticipantNumbers[]`)
- `POST .../bracket/{bid}/unassign` → `unassign_from_bracket`

Existing `addparticipant` stays (now auto-registers under the hood).

### Model layer (`orkui/model/model.Tournament.php`)

Thin pass-throughs for each new method, matching existing style.

### UI (`orkui/template/revised-frontend/Tournametnew_index.tpl`)

**Participants tab — becomes active (read+write):**
- "Register Participant" button → modal with **scoped, properly-formed, curl-tested player search**
  (park→kingdom on park pages, kingdom on kingdom pages; build URL with `&q=` not `?q=`;
  `tnFixedAcPosition(input, dropdown)` **defined** on the page and called before every
  `kn-ac-open`, in both the results and no-results branches). Registers an individual.
- Roster table per registrant: alias · player/persona · park · warrior pills · **bracket chips**
  (which brackets they're assigned to) · actions: edit alias, withdraw, "Assign to brackets"
  (per-person multi-bracket picker), remove.
- Remove of an assigned registrant → `tnConfirm` (no native dialog); explains cascade / blocks if in
  an active/complete bracket.

**Brackets tab — per bracket card:**
- New "Assign Participants" button → modal listing all tournament registrants with checkboxes;
  already-assigned pre-checked; bulk add/remove → `assign`/`unassign`. Disabled with explanation when
  bracket is past `setup`.
- Existing "Add Participant" stays (registers brand-new person *and* assigns here in one step).
- Team brackets unchanged.

All new modals/buttons/chips **dark-mode compatible from the start** (walk the dark-mode checklist:
modal headers vs orkui.css h1–h6 pill leak, ghost/cancel buttons, inline colors, labels,
placeholders, segmented toggles, info boxes). No native `title` tooltips — use `data-tip`. No native
`confirm()`/`alert()` — use `tnConfirm()`.

### Auth

All registration + assignment actions require manage auth via the existing `check_auth` (organizer /
edit-level). Bracket runners (record-results-only) cannot register or assign.

## Edge cases

- **Assign/unassign after generate:** allowed only while bracket `status = 'setup'`; show why when
  locked.
- **Duplicate prevention:** assigning someone already in a bracket is a no-op for that bracket;
  per-bracket add reuses existing registration.
- **Warrior/awards snapshot:** taken once at registration, reused for all bracket entrants.
- **Alias-only registrants:** supported (no linked account), person-stable by alias text per existing
  `AddParticipant` logic.
- **Removing a registrant in an active/complete bracket:** blocked with a clear message.
- **Cache:** `bustTournamentReportCache()` after every mutating op, matching existing methods.

## Testing / verification

- DB-layer: register → appears as `bracket_id IS NULL`; assign to 2 brackets → 2 entrant rows, same
  `participant_number`; unassign → entrant row gone, registration intact; per-bracket add of an
  already-registered person → no duplicate registrant.
- Curl-test the new player search returns rows before declaring done (per project rule).
- Generate matches on a bracket populated via assignment → bracket behaves identically to one
  populated via the old per-bracket add.
- Browser verification (Claude-in-Chrome) only after implementation, per project rule: register a
  person, assign to a bracket, confirm chips + bracket card update; dark-mode pass on all new surfaces.

## Files touched

| File | Change |
|---|---|
| `db-migrations/2026-05-31-*.sql` | Indexes / status value if needed (column already nullable) |
| `system/lib/ork3/class.Tournament.php` | `ensureRegistrant`, `RegisterParticipant`, `GetRegistrants`, `AssignToBracket`, `UnassignFromBracket`, `UpdateRegistrationStatus`, `RemoveRegistrant`; reconcile `AddParticipant`/`GetParticipants`/`buildFilterWhere` |
| `orkui/controller/controller.TournamentAjax.php` | New tournament + bracket actions |
| `orkui/model/model.Tournament.php` | Thin pass-throughs |
| `orkui/template/revised-frontend/Tournametnew_index.tpl` | Participants-tab register/roster UI; bracket "Assign Participants" modal |
