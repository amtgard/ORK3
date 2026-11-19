# Participants Tab: Individuals/Teams Sub-tabs + Tournament-level Team Management

**Date:** 2026-06-01
**Branch:** feature/tournament-module
**Status:** Approved design — ready for planning

## Problem

The Participants tab now manages tournament-level **individual** registration (an `ork_participant`
row with `bracket_id IS NULL`, assignable to many brackets). **Teams**, however, still exist only
*inside* a team bracket (`ork_participant_teams.bracket_id` is NOT NULL) — there is no tournament-level
team concept and no way to view teams across the tournament. We want:

1. A two **sub-tab** model on the Participants tab — `Individuals` | `Teams` — left-aligned, on the
   same row as the action button (which is right-aligned).
2. **Full tournament-level team management**: create/build a team once at the tournament level and
   assign it to one or more team brackets later — symmetric with the individual registration model.

## Goals

1. Sub-tab toggle (`Individuals` / `Teams`) left-aligned; action button right-aligned on the same row.
   Both sub-tabs always render; switching is client-side (no reload).
2. Register a **team** at the tournament level (name + member roster) independent of any bracket.
3. Assign a registered team to **one or more** team brackets later (one-to-many), and unassign.
4. Keep the existing per-bracket **"Add Team"** flow working — it auto-registers the team at the
   tournament level (mirrors how per-bracket "Add Participant" auto-registers individuals).
5. Mirror the individual model end-to-end (data, backend, AJAX, UI) for consistency.

## Non-Goals (YAGNI)

- No change to bracket generation, seeding, standings, or match recording for team brackets.
- No cross-tournament team reuse / saved teams.
- The action button on the **Teams** sub-tab is "Create Team"; on **Individuals** it stays
  "Register Participant". (The earlier "hide Register on Teams" decision is realized by *swapping*
  the button per sub-tab, since full team management needs its own create action.)

## Key Decisions (from brainstorming)

| Question | Decision |
|---|---|
| Teams sub-tab scope | **Full tournament-level team management** (not read-only) |
| Sub-tab visibility | **Always show both**; Teams shows empty state when none |
| Action button | **Swaps per sub-tab**: Register Participant (Individuals) / Create Team (Teams) |
| Team roster source | **Registered individuals + kingdom-scoped player search** (search auto-registers) |
| Team data model | **Mirror the individual model** — `bracket_id` nullable + stable `team_number` |
| Team multi-bracket | **One-to-many** (a team can be assigned to multiple team brackets) |

## Architecture

### Data model (mirror the individual model)

The stable identity is a new `team_number` (tournament-scoped), parallel to `participant_number`.

- **Registered team** — an `ork_participant_teams` row with `bracket_id IS NULL`, a `team_number`
  (MAX+1 within the tournament), a `name`, and members in `ork_participant_team_members` (already
  keyed by `team_id`, no bracket scoping). Each member is also ensured as a registered individual
  (`ensureRegistrant`), so a player can be on a team and compete individually.
- **Team bracket entrant** — assigning a registered team to a team bracket clones:
  1. a per-bracket `ork_participant_teams` row (bracket_id set, same `team_number`),
  2. the per-bracket `ork_participant` entrant row that team brackets reference for matches/standings
     (the existing "team participant" row — alias = team name, `participants='team'` semantics),
  3. the member roster rows into `ork_participant_team_members` for the new team row.
  These per-bracket rows are exactly what the existing team-bracket code already consumes — unchanged.

### Migration (`db-migrations/2026-06-01-team-registration.sql`)

```sql
-- Tournament-level team registration: a team row with bracket_id IS NULL is a
-- registered team not yet assigned to any bracket. Mirrors the individual model.
ALTER TABLE ork_participant_teams MODIFY bracket_id INT(11) NULL DEFAULT NULL;
ALTER TABLE ork_participant_teams ADD COLUMN team_number INT(11) NOT NULL DEFAULT 0;
ALTER TABLE ork_participant_teams ADD INDEX idx_pteams_tourn_number (tournament_id, team_number);
ALTER TABLE ork_participant_teams ADD INDEX idx_pteams_tourn_bracket (tournament_id, bracket_id);
```

(Exact current column types confirmed against `SHOW CREATE TABLE` during implementation; only
nullability/new column/indexes change. `ork_participant_team_members` is unchanged — it has no
`bracket_id`.) yapo drops null on save, so registration team rows force `bracket_id = NULL` via an
explicit follow-up `UPDATE`.

### Backend (`system/lib/ork3/class.Tournament.php`) — parallel to the individual methods

- `ensureTeam(int $tid, array $team): array` — private find-or-create of the `bracket_id IS NULL`
  registration team row, keyed by `team_number` (reuse existing number when `TeamId`/`TeamNumber`
  supplied for edit; else MAX+1). Upserts the name and the member roster
  (`ork_participant_team_members` by `team_id`); runs each member's `MundaneId` through
  `ensureRegistrant` so team members are registered individuals too. Returns
  `['TeamNumber'=>int, 'TeamId'=>int]`. Caller wraps in a transaction; does NOT start its own.
- `RegisterTeam($request)` — tournament-level create via `ensureTeam` (requires Name + ≥1 member).
- `GetRegisteredTeams($request)` — roster: each registered team (`bracket_id IS NULL`) with
  `Members[]` (persona, park, warrior level via the existing `fetchAwardsForMundanes` /
  `warriorLevelFromAwards`) and `Brackets[]` (the team brackets it's assigned to, joined by
  `team_number`).
- `UpdateTeam($request)` — rename + edit roster of a registered team (re-runs `ensureTeam` for the
  same `team_number`). Rename is always allowed. Roster edits (add/remove members) are blocked when
  the team is in any non-setup bracket, consistent with assignment-locking — return a clear error in
  that case. When allowed, roster edits propagate to the team's setup-bracket entrant rows so a team
  assigned to a setup bracket stays in sync.
- `RemoveRegisteredTeam($request)` — blocked if the team is in any non-setup bracket; otherwise
  deletes every team row sharing the `team_number` (registration + any setup-bracket entrants) plus
  their `ork_participant` entrant rows and member rows, mirroring `deleteTeamRows` + `RemoveParticipant`
  cleanup.
- `AssignTeamToBracket($request)` — bulk by `TeamNumbers[]`; setup-status team brackets only; for each
  team not already in the bracket, clone the registration team row + `ork_participant` entrant +
  member roster (carry forward). Returns assigned list. Skips already-assigned.
- `UnassignTeamFromBracket($request)` — bulk; setup-only; deletes the per-bracket team row + its
  `ork_participant` entrant + member rows for that bracket, leaving the registration team intact.

Each mutating method calls `bustTournamentReportCache()` and is guarded by `check_auth`.
All team queries that must NOT include registration rows stay scoped by a concrete `bracket_id`
(verify `teamRoster()` and `GetParticipants` team-collapse remain bracket-scoped so registration team
rows never leak into a bracket's participant list).

### Per-bracket "Add Team" reconciliation

`AddParticipant`'s team branch (per-bracket "Add Team") is refactored to call `ensureTeam` first
(find/create the registration team by `team_number`), then create the per-bracket team entrant sharing
that `team_number` — so the quick per-bracket flow auto-registers the team with no duplicate registered
team. Both flows coexist.

### AJAX (`orkui/controller/controller.TournamentAjax.php`)

Tournament-level (in `tournament($p)`), session-gated, FormData:
- `GET  tournament/{tid}/registeredteams` → `get_registered_teams`
- `POST tournament/{tid}/createteam`      → `register_team` (Name; Members as a JSON array of `{MundaneId}`)
- `POST tournament/{tid}/updateteam`      → `update_team` (TeamNumber, Name, Members)
- `POST tournament/{tid}/removeteam`      → `remove_registered_team` (TeamNumber)

Bracket-level (in `bracket($p)`):
- `POST bracket/{bid}/assignteams`   → `assign_team_to_bracket` (TournamentId, TeamNumbers JSON array)
- `POST bracket/{bid}/unassignteams` → `unassign_team_from_bracket`

`Members` / `TeamNumbers` arrive as JSON strings (mirror the existing `Members` json_decode in
`addparticipant` and `ParticipantNumbers` in `assign`). Int-filter `TeamNumbers`.

### Model (`orkui/model/model.Tournament.php`)

Thin pass-throughs: `register_team`, `get_registered_teams`, `update_team`,
`remove_registered_team`, `assign_team_to_bracket`, `unassign_team_from_bracket`.

### Controller (`orkui/controller/controller.Tournament.php` `profile()`)

Load `$this->data['registered_teams'] = $this->model->get_registered_teams(['TournamentId'=>$id])['Detail'] ?? []`
and embed for JS as `TnConfig.registeredTeams` next to `registrants`.

### UI (`orkui/template/revised-frontend/Tournametnew_index.tpl`)

**Participants tab header row** (`.tn-roster-bar` becomes a space-between row):
- Left: a segmented sub-tab toggle `Individuals` | `Teams` (reuse existing segmented-toggle styling;
  `tnParticipantsSubtab('individuals'|'teams')` shows/hides the two panels and swaps the button).
- Right: the action button — `Register Participant` (Individuals) / `Create Team` (Teams), both
  `canManage`-gated.

**Individuals panel**: the existing roster (`#tn-roster-table-wrap`, `tnRenderRoster()`), unchanged.

**Teams panel** (`#tn-teams-table-wrap`):
- Empty state: "No teams yet." (+ Create Team button context when canManage).
- Table: Team (name) · Members (count + expandable roster using the existing `tn-team-roster`
  expand pattern + warrior pills) · Brackets (chips from `Brackets[]`; "Unassigned" muted if none) ·
  Actions (canManage): Assign to brackets / Edit / Remove.
- `tnRenderTeamsRoster()` rebuilds the table from `TnConfig.registeredTeams`, parallel to
  `tnRenderRoster()`.

**Create/Edit Team modal**:
- Name field (required) + member builder. Members added by (a) picking from registered individuals
  (`TnConfig.registrants` as a checklist/typeahead) and (b) kingdom-scoped player search (reuse the
  existing search pattern — `&q=`, `tnFixedAcPosition` in both branches, `tn-ac-results`/`tn-ac-open`;
  picked players auto-register as individuals). Selected members shown as `tn-team-member-tag` chips
  with remove buttons (reuse existing). Requires ≥1 member.
- Submit → `createteam` (or `updateteam` when editing) → refresh `registeredTeams` GET →
  `tnRenderTeamsRoster()` → toast → close.

**Team Assign-to-brackets modal** (per registered team): same pattern as the individual assign modal,
but lists only **team** brackets (setup-status toggleable; non-setup disabled with `data-tip`).
Diff → `assignteams`/`unassignteams` → refresh teams roster (and the bracket cards via reload, matching
the individual bulk modal's reliability choice).

**Bracket-card "Assign Teams" bulk modal**: team bracket cards (setup status) get an "Assign Teams"
button (parallel to "Assign Participants" on individual brackets) listing registered teams with
checkboxes (pre-checked if already in the bracket) → `assignteams`/`unassignteams`. Non-setup → disabled
button + tip. The existing per-bracket "Add Team" button stays.

### Conventions

Dark-mode compatible up front (sub-tab toggle, Create Team modal header pill-reset via `.tn-modal-title`,
member checklist, chips — reuse existing `tn-team-*` dark selectors). `tnConfirm` for destructive
actions (no native dialogs). `data-tip` (no native tooltips). Multi-line `.tpl`/`.php` edits via Python.
Explicit staging; never stage `class.Authorization.php`; `git diff --cached` before each commit.

## Edge cases

- **Assign/unassign after generate**: team assignment allowed only while the team bracket is `setup`;
  show why when locked.
- **Duplicate prevention**: assigning a team already in a bracket is a no-op; per-bracket "Add Team"
  reuses the existing registered team (same `team_number`).
- **Member also an individual**: a member added to a team is auto-registered as an individual; they
  appear in the Individuals roster too. Allowed by design.
- **Removing a registered team in a started bracket**: blocked with a clear message.
- **Empty roster**: Create Team requires a name and ≥1 member.
- **Registration rows don't leak**: `GetParticipants`/match queries already exclude `bracket_id IS NULL`;
  team-roster reads stay bracket-scoped so registration team rows never show inside a bracket.

## Testing / verification

- DB-layer: create team → `bracket_id IS NULL` row with `team_number`; assign to 2 team brackets →
  2 per-bracket team rows + 2 `ork_participant` entrants, same `team_number`; unassign → entrant gone,
  registration intact; per-bracket "Add Team" of an existing registered team → no duplicate registration.
- Curl all new endpoints (single-session auth per the dev login note) on a tournament with a team
  bracket; clean up fixtures (ZZ-prefixed names) and prove COUNT 0.
- Browser (Claude-in-Chrome, post-implementation): toggle Individuals/Teams; Create Team (pick a
  registered individual + a searched player); assign team to a team bracket; confirm chips + bracket
  card update; team bracket "Assign Teams" modal pre-checks; dark-mode walk of the toggle + all modals.

## Files touched

| File | Change |
|---|---|
| `db-migrations/2026-06-01-team-registration.sql` | nullable `bracket_id` + `team_number` + indexes |
| `system/lib/ork3/class.Tournament.php` | `ensureTeam`, `RegisterTeam`, `GetRegisteredTeams`, `UpdateTeam`, `RemoveRegisteredTeam`, `AssignTeamToBracket`, `UnassignTeamFromBracket`; refactor `AddParticipant` team branch to auto-register |
| `orkui/model/model.Tournament.php` | thin pass-throughs |
| `orkui/controller/controller.TournamentAjax.php` | tournament team actions + bracket assignteams/unassignteams |
| `orkui/controller/controller.Tournament.php` | load `registered_teams` for the profile |
| `orkui/template/revised-frontend/Tournametnew_index.tpl` | sub-tab toggle, Teams roster, Create/Edit Team modal, team assign modals, bracket-card Assign Teams |
