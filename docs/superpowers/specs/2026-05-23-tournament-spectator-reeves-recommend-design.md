# Tournament Module — Spectator Mode, Reeves Panel, Standings Recommendations

**Date:** 2026-05-23
**Branch:** feature/tournament-module
**Status:** Approved design — ready for implementation

Three independent features for the tournament profile (`Tournametnew_index.tpl`,
`controller.TournamentAjax.php`, `class.Tournament.php`, `model.Tournament.php`).

---

## Key files (existing)

- Template: `orkui/template/revised-frontend/Tournametnew_index.tpl`
- AJAX controller: `orkui/controller/controller.TournamentAjax.php`
- Profile controller: `orkui/controller/controller.Tournament.php`
- Service/DB layer: `system/lib/ork3/class.Tournament.php`
- Model pass-through: `orkui/model/model.Tournament.php`
- Auth: `system/lib/ork3/class.Authorization.php` (`HasAuthority`, constants `AUTH_KINGDOM/PARK/EVENT/EDIT`)
- Player recommendation form (to reuse): `Playernew_index.tpl` — `pnOpenModal()`, form
  `#pn-recommend-form` posts to `Player/profile/{MundaneId}/addrecommendation` with
  fields `KingdomAwardId`, `Rank`, `GivenById`, `Note`.
- Playersearch pattern: `kn-ac-results` custom dropdown (NOT jQuery UI). Endpoint
  `KingdomAjax/playersearch/{kingdomId}` and `&scope=own|exclude`. Scope park→kingdom.

## Existing auth model (relevant facts)

- `class.Tournament::check_auth($request)` returns true when the token's mundane has
  `AUTH_EDIT` at the tournament's kingdom / park / event. Used by all bracket/tournament
  mutation methods (`AddBracket`, `UpdateBracket`, `GenerateMatches`, `PostMatchResult`,
  `ResetMatch`, participant add/remove, etc.).
- `controller.Tournament.php` computes `$canManage` the same way and passes
  `CanManageTournament` to the template.
- Bracket status (`ork_bracket.status`): `setup` | `active` | `complete` | `finalized`.
- Match result lives in `ork_match.result`; `ork_match` has `created` (datetime) but **no
  `modified`** column — results are UPDATEd in place, so a timestamp cannot detect changes.

---

## Feature 1 — Spectator Mode

### Goal
An unauthenticated visitor (or any user without manage rights) viewing a tournament that
has at least one `active` bracket gets a dismissible "Spectator Mode — live" banner and a
read-only experience that auto-refreshes bracket/match state without a full page reload.

### Refresh mechanism: adaptive polling + cheap version signature
No schema change. Add a public, read-only endpoint that returns a tiny signature; the
client polls it and only re-fetches full bracket JSON when the signature changes.

**New endpoint:** `GET TournamentAjax/tournament/{id}/version` — **no auth required**.
Returns `{ status:0, version:"<sig>" }` where `<sig>` is a cheap aggregate computed in
`class.Tournament` over the whole tournament:

```sql
-- match-state component (captures result/score edits, resets, new matches)
SELECT COUNT(*) AS mc,
       COALESCE(SUM(CRC32(CONCAT_WS(':', match_id, result, score))),0) AS msum
FROM   ork_match WHERE tournament_id = ?;
-- bracket-state component (captures status changes, add/delete bracket)
SELECT GROUP_CONCAT(CONCAT(bracket_id,':',status) ORDER BY bracket_id) AS bsig
FROM   ork_bracket WHERE tournament_id = ?;
-- participant-set component (captures roster + live elimination / ironman streak changes)
SELECT COUNT(*) AS pc,
       COALESCE(SUM(CRC32(CONCAT_WS(':', participant_id, eliminated, bracket_side,
                                    im_wins, im_current_streak))),0) AS psum
FROM   ork_participant WHERE tournament_id = ?;
```
`version = md5("{mc}:{msum}|{bsig}|{pc}:{psum}")`. These are indexed-column aggregates over
a single tournament — trivially cheap. (`ork_participant` columns confirmed against schema:
`eliminated` TINYINT, `bracket_side` ENUM, `im_wins`/`im_current_streak`/`im_max_streak` INT;
players link via `ork_participant_mundane` — there is no single `status` column.)

**Service method:** `class.Tournament::GetVersion($request)` (public, takes `TournamentId`,
no auth check) → `model.Tournament::get_version()` pass-through.

### Client behavior (in `Tournametnew_index.tpl`)
- A spectator config flag is emitted server-side: `TnConfig.spectator = (!CanManageTournament && hasActiveBracket)`.
  When true, render the banner and start the poll loop.
- Banner: top-of-page bar, "🔴 Spectator Mode — live", with a collapse/dismiss control.
  Dismissing only hides the bar; the poll loop keeps running.
- Poll loop:
  - Interval **5s** while any bracket is `active`; **20s** when none are active
    (complete/finalized) — still polls so a newly-started bracket is picked up.
  - On `document.visibilitychange` → hidden: pause polling; on visible: resume + immediate poll.
  - Each tick fetches `/version`. If `version` differs from the last seen value, re-fetch the
    changed data via the existing endpoints (`bracket/{id}/matches`,
    `tournament/{id}/brackets`) and re-render through the existing `tnRenderBracketViz` path,
    then store the new version. No change → do nothing.
- Read-only: reuse existing render functions with manage controls suppressed. Spectators
  must not see record-result / edit / generate buttons. Gate those on `CanManageTournament`
  (and the new reeve flags from Feature 2) — they already largely key off `CanManageTournament`.

### Security
- `/version` and the existing read endpoints (`brackets`, `bracket/{id}/matches`,
  `bracket/{id}/participants`) expose read-only data only. Confirm these GET endpoints don't
  require auth (or add a public read path) so spectators can fetch. **No mutation endpoint is
  exposed without auth.**

---

## Feature 2 — Reeves Panel (About tab)

### Goal
A user with create/edit auth (or an existing Tournament Organizer reeve) can designate
tournament reeves via playersearch and grant each a limited, **tournament-scoped** role,
without that person needing higher standing system-wide.

### Roles
- **Tournament Organizer** (`organizer`): everything an edit-auth user can do for this
  tournament — create/edit/delete/run brackets, change participants, manage reeves.
- **Bracket Runner** (`bracket_runner`): record match results only (wins/losses/ties/
  forfeit/DQ + resets). Cannot create/edit/delete brackets, change participants, or manage reeves.

### Schema (only DB change in this spec)
New table via migration (MariaDB container; run with `mariadb` not `mysql`):
```sql
CREATE TABLE IF NOT EXISTS `ork_tournament_reeve` (
  `tournament_reeve_id` int(11) NOT NULL AUTO_INCREMENT,
  `tournament_id` int(11) NOT NULL,
  `mundane_id` int(11) NOT NULL,
  `role` enum('organizer','bracket_runner') NOT NULL DEFAULT 'bracket_runner',
  `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tournament_reeve_id`),
  UNIQUE KEY `uq_tourn_mundane` (`tournament_id`,`mundane_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;
```
Unique `(tournament_id, mundane_id)` → re-adding updates the role (one row per person per
tournament). A yapo model class is added if the project convention requires one; otherwise
raw SQL through the DB layer (remember `$DB->Clear()` before raw Execute/DataSet).

### Service layer (`class.Tournament.php`)
- `GetReeves($request)` — list reeves for a tournament (mundane_id, persona, role); used by
  controller to render the panel. Readable by manage-level users.
- `AddReeve($request)` — `TournamentId`, `MundaneId`, `Role`. Auth: caller must pass
  `check_auth` (edit) **or** be an `organizer` reeve. Upsert into `ork_tournament_reeve`.
- `RemoveReeve($request)` — `TournamentId`, `MundaneId`. Same auth as AddReeve.
- **Auth integration — the core change:**
  - `check_auth($request)` returns true if the existing kingdom/park/event edit check passes
    **OR** the mundane is an `organizer` reeve for that `TournamentId`. (This automatically
    extends every organizer-level mutation to Organizer reeves.)
  - New `can_run_brackets($request)` returns true if `check_auth` passes **OR** the mundane is
    a `bracket_runner` reeve for that tournament. `PostMatchResult` and `ResetMatch` switch
    from `check_auth` to `can_run_brackets`. All other mutations keep `check_auth`
    (so bracket runners remain limited to result entry).
  - Managing reeves (`AddReeve`/`RemoveReeve`) requires organizer-level (`check_auth`), never
    `can_run_brackets`.
  - A small helper `get_reeve_role($mundane_id, $tournament_id)` backs both checks.

### Controller (`controller.TournamentAjax.php`)
New tournament-level actions (existing route `tournament/{id}/{action}`):
- `GET  reeves` → list.
- `POST addreeve` → `MundaneId`, `Role`.
- `POST removereeve` → `MundaneId`.
All go through the model pass-through to the service methods (auth enforced in service).

`controller.Tournament.php` additionally computes and passes to the template:
- `CanManageReeves` (edit auth OR organizer reeve),
- `IsBracketRunner` / `IsOrganizerReeve` flags,
- `Reeves` list for initial render.
`CanManageTournament` should also be true for organizer reeves so existing manage UI lights up.

### Template (`Tournametnew_index.tpl`)
- New card in the **About** tab (after the description block), shown when `CanManageReeves`:
  "Tournament Reeves" listing current reeves with role badges and a remove (×) per row, plus
  an "Add Reeve" button.
- **Add Reeve modal:** `kn-ac-results` playersearch (scope park→kingdom; use
  `tnFixedAcPosition` so the dropdown isn't clipped inside the modal — `position:fixed`
  per modal-autocomplete rule) + a role `<select>` (Organizer / Bracket Runner) + Add button.
  Posts to `addreeve`, then refreshes the reeve list in place.
- IIFE guard for the panel JS uses a `TnConfig` flag (e.g. `TnConfig.canManageReeves`), **not**
  `getElementById` (revised.js-style guard rule).
- Dark-mode compatible (badges, modal header heading reset, ghost button colors, select).

---

## Feature 3 — Standings "Recommend for…" Buttons

### Goal
On the standings table, add a **"Recommend for…"** column with two per-row buttons —
**⭐ Warrior** (`KingdomAwardId` 27) and **⭐ Griffin** (`KingdomAwardId` 33), both ladder
awards — that open the **player award-recommendation modal** (reason + ladder rank),
pre-populated with that row's player and the chosen award/next rank.

### Access
Visible to tournament staff: kingdom/park edit auth, Organizer reeves, **and Bracket
Runners**. (i.e. anyone with `CanManageTournament` OR `IsBracketRunner`.) Hidden for plain
spectators / logged-out visitors.

### Reuse the player modal
Port a focused copy of the Playernew recommendation modal into `Tournametnew_index.tpl`
(the standings page is a different template, so the modal + its submit JS are replicated, not
shared). It uses the same backend contract:
- Form posts to `Player/profile/{MundaneId}/addrecommendation` (or AJAX with the same fields).
- Fields: `KingdomAwardId` (27 = Warrior, 33 = Griffin), `Rank` (next ladder rank),
  `GivenById` (current user), `Note` (reason, optional, maxlength 400 with char counter).
- Pre-populate: player (from the row's `MundaneId`/`Alias`), award (from which star clicked),
  and the **next ladder rank** — derived from the player's current ladder level for that award
  if available; otherwise leave rank blank/default and let the modal's existing logic handle it.

### Standings table changes
- Each `$stRow` already carries `MundaneId` (used elsewhere for profile links). Emit it as a
  `data-mundane-id` (and persona) on the row or buttons.
- Add a header cell **"Recommend for…"** and a trailing cell per row with two buttons:
  `⭐ Warrior` and `⭐ Griffin`. Wrap the new column in the staff-visibility gate so column +
  cells appear/disappear together (keep `tnSortTable` column indices consistent — the recommend
  column is non-sortable / last).
- Clicking a button calls e.g. `tnOpenRecModal(mundaneId, persona, awardId)` which opens the
  ported modal pre-populated.

### Notes
- Warrior = 27, Griffin = 33 (from `class.Award::GetLadderMasterMap`).
- Dark-mode compatible buttons + modal.

---

## Cross-cutting conventions (apply to all three)

- DB logic in `system/lib/ork3/`; `model.Tournament.php` thin pass-through; controllers thin.
- `$DB->Clear()` before any raw Execute/DataSet.
- Migration via `docker exec -i ork3-php8-db mariadb -u root -proot ork < migration.sql`.
- Dark-mode pre-flight on every new surface (banner, reeves card, add-reeve modal, recommend
  buttons, recommend modal): heading box reset, ghost buttons, selects, placeholders, badges.
- No native `title` tooltips — use `data-tip`.
- Human-readable display; no raw ISO.
- Do NOT stage `class.Authorization.php` if the login-bypass hack is present; stage files
  explicitly (no `git add -A`).

## Out of scope
- Per-bracket reeve assignment (reeves are tournament-wide).
- WebSockets / SSE.
- Spectator interactions beyond viewing (no chat, no predictions).
- Changing the underlying recommendation backend.

## Test / verification plan
- Migration applies cleanly; table + unique key exist.
- Spectator: logged-out view of a tournament with an active bracket shows banner, polls
  `/version`, and re-renders only when a result is recorded (verify via second authed session
  recording a match — spectator updates within one interval). No mutation endpoints reachable
  unauthenticated.
- Reeves: organizer reeve can edit brackets + manage reeves; bracket runner can record
  results but is blocked (server-side) from bracket edits/participant changes/reeve management;
  UI hides what each can't do. Re-adding a person updates their role (unique key).
- Recommend: buttons appear only for staff; clicking opens modal pre-populated with player +
  award + rank; submit creates a recommendation visible on the player's profile.
- Dark mode walk-through of all new surfaces.
