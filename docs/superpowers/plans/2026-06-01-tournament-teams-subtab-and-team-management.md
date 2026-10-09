# Participants Teams Sub-tab + Team Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an Individuals/Teams sub-tab toggle to the Participants tab and full tournament-level team management (create a team once, assign it to one or more team brackets later), mirroring the existing individual-registration model.

**Architecture:** A *registered team* is an `ork_participant_teams` row with `bracket_id IS NULL` carrying a tournament-stable `team_number`, paired with a registration `ork_participant` row (the team identity, `bracket_id IS NULL`, alias = team name) and member rows in `ork_participant_team_members`. Assigning to a team bracket clones the team's `ork_participant` entrant + `ork_participant_teams` row (same `team_number`) + member roster — exactly what existing team-bracket code already consumes. The per-bracket "Add Team" flow auto-registers the team. UI gets a left-aligned Individuals/Teams sub-tab toggle with a right-aligned action button that swaps per sub-tab.

**Tech Stack:** PHP 8 (DB layer `system/lib/ork3/`, MVC `orkui/`), MariaDB, plain-PHP `.tpl`, vanilla JS. App `http://localhost:19080/orkui/`. App container `ork3-php8-app` (repo at `/var/www/ork.amtgard.com`). DB container `ork3-php8-db` (MariaDB).

---

## Conventions for every task (READ FIRST)

- **PHP/.tpl multi-line edits → use Python**, never the Edit tool (tab indentation; byte-perfect match fails). Pattern:
  `python3 -c "import pathlib; p=pathlib.Path('FILE'); t=p.read_text(); assert OLD in t; p.write_text(t.replace(OLD,NEW,1))"`. Re-read the region after each edit.
- **DB layer only in** `system/lib/ork3/class.Tournament.php`; `model.Tournament.php` is thin pass-through.
- Match existing idioms: `$this->db->query(...)` (parameterized like neighbors), Yapo objects `$this->Participant`(=`ork_participant`) / `$this->Player`(=`ork_participant_mundane`) — call `->clear()` before reuse; `valid_id()`, `Success()/InvalidParameter()/NoAuthorization()`, `$this->check_auth($request)`, `$this->bustTournamentReportCache()` after mutations, `$this->db->GetLastInsertId()`.
- **yapo drops `null` on save** — force a NULL column with an explicit follow-up `UPDATE ... SET col = NULL WHERE ...`. Always verify NULL landed (not 0) in the DB.
- Reuse existing helpers: `ensureRegistrant()` (individual find-or-create), `fetchAwardsForMundanes()`, `warriorLevelFromAwards()`, `griffonLevelFromAwards()`, `deleteTeamRows($whereColumn, $id)`, `teamRoster($bracketId)`.
- **Stage files explicitly**; run `git diff --cached` before committing; a concurrent session also edits this repo — if foreign hunks appear, `git add -p` only your hunks. NEVER stage `system/lib/ork3/class.Authorization.php`; never `git add -A`/`.`.
- **No native `confirm()`/`alert()`** → `tnConfirm`. **No native `title`** → `data-tip`. **Dark mode**: every new surface uses the `html[data-theme="dark"] .selector` convention already in the template; modal headers use the `.tn-modal-title` class (self-resets the orkui.css gray pill).
- No automated test harness. Verify with `php -l`, `mariadb` queries, and curl.

**Curl auth (single-device sessions):** log in once and reuse ONE cookie jar in the SAME bash block; do not re-login mid-sequence. Login bypass accepts any password.
```bash
curl -s -c /tmp/ck.txt "http://localhost:19080/orkui/index.php?Route=Login/login" --data "username=rath-957&password=x" -o /dev/null
# warm + verify session, then your calls with -b /tmp/ck.txt:
curl -s -b /tmp/ck.txt "http://localhost:19080/orkui/index.php?Route=TournamentAjax/tournament/169/reeves"
```
`rath-957` (mundane 56034) has kingdom-17 `create` authority → `canManage` for tournament 169 (which has team bracket 63 and individual setup brackets 65–68). If a needed bracket isn't in `setup`, flip its status for the test and restore it after. PHP 500s surface in `docker logs ork3-php8-app`.

**Verification model:** "Test" steps = curl the endpoint + query MariaDB to confirm row state, and `php -l`. Use ZZ-prefixed names for any fixtures and delete them after (prove COUNT 0).

---

## Existing-code facts (confirmed against live schema/code)

- `ork_participant_teams`: `team_id`(PK), `tournament_id`, `bracket_id` (currently NOT NULL), `participant_id` (NOT NULL → the team's `ork_participant` row), `name`. Migration makes `bracket_id` nullable + adds `team_number`.
- `ork_participant_team_members`: `id`(PK), `team_id`, `mundane_id`, `tournament_id`. No `bracket_id`.
- A **team entrant in a bracket** = an `ork_participant` row (alias = team name, the team's "participant") + an `ork_participant_teams` row (links team→participant_id) + member rows. Matches/standings reference the `ork_participant` row.
- `AddParticipant` team branch (`class.Tournament.php` ~615–660): creates the `ork_participant` row first (in the shared else-branch, lines ~575–588 with `bracket_id = (int)$request['BracketId']`), then the `ork_participant_teams` row, members, and a summed warrior/griffon snapshot.
- `buildFilterWhere` already appends `AND {alias}.bracket_id IS NOT NULL` when filtering by TournamentId without a BracketId, so individual participant/match queries already exclude registration rows. Team-roster reads (`teamRoster`, `GetParticipants` team-collapse) are scoped by a concrete `bracket_id`, so they will not pick up `bracket_id IS NULL` registration team rows.

---

## File Structure

| File | Responsibility | Tasks |
|---|---|---|
| `db-migrations/2026-06-01-team-registration.sql` | nullable bracket_id + team_number + indexes on `ork_participant_teams` | 1 |
| `system/lib/ork3/class.Tournament.php` | `ensureTeam`, `RegisterTeam`, `GetRegisteredTeams`, `UpdateTeam`, `RemoveRegisteredTeam`, `AssignTeamToBracket`, `UnassignTeamFromBracket`; refactor `AddParticipant` team branch | 2–6 |
| `orkui/model/model.Tournament.php` | thin pass-throughs | 7 |
| `orkui/controller/controller.TournamentAjax.php` | tournament team actions + bracket assignteams/unassignteams | 8 |
| `orkui/controller/controller.Tournament.php` | load `registered_teams` for the profile | 9 |
| `orkui/template/revised-frontend/Tournametnew_index.tpl` | sub-tab toggle, Teams roster, Create/Edit Team modal, team assign modals, bracket-card Assign Teams | 9–12 |

Template tasks (9–12) edit the SAME large file — run sequentially, never in parallel.

---

## Task 1: DB migration — team registration columns

**Files:**
- Create: `db-migrations/2026-06-01-team-registration.sql`

- [ ] **Step 1: Confirm current `ork_participant_teams` definition**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SHOW CREATE TABLE ork_participant_teams\G"
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SELECT COUNT(*) AS before_count FROM ork_participant_teams;"
```
Expected: `bracket_id int(11) NOT NULL`, no `team_number` column, no FK on `bracket_id`. Note `before_count`.

- [ ] **Step 2: Write the migration**

Create `db-migrations/2026-06-01-team-registration.sql` (match the exact `bracket_id` type from Step 1; only nullability changes):
```sql
-- Tournament-level team registration: an ork_participant_teams row with
-- bracket_id IS NULL is a team registered to the tournament but not yet assigned
-- to any bracket. Mirrors the individual registration model (ork_participant).
ALTER TABLE ork_participant_teams MODIFY bracket_id INT(11) NULL DEFAULT NULL;
-- Tournament-stable team identity, shared across every bracket the team is in.
ALTER TABLE ork_participant_teams ADD COLUMN team_number INT(11) NOT NULL DEFAULT 0;
ALTER TABLE ork_participant_teams ADD INDEX idx_pteams_tourn_number (tournament_id, team_number);
```
(`idx_pt_bracket` already exists per Step 1, so do NOT re-add a tournament/bracket index unless Step 1 shows none.)

- [ ] **Step 3: Apply and verify**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-06-01-team-registration.sql
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SHOW CREATE TABLE ork_participant_teams\G" | grep -iE "bracket_id|team_number|idx_pteams"
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SELECT COUNT(*) AS after_count FROM ork_participant_teams;"
```
Expected: `bracket_id int(11) DEFAULT NULL`, `team_number int(11) NOT NULL DEFAULT 0`, the new index present, `after_count == before_count`.

- [ ] **Step 4: Commit**
```bash
git add db-migrations/2026-06-01-team-registration.sql
git diff --cached --name-only
git commit -m "Enhancement: Team registration columns (nullable bracket_id + team_number)"
```

---

## Task 2: `ensureTeam()` helper

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php` (add private method near `ensureRegistrant`, ~line 513)

**Context:** `ensureTeam` find-or-creates the tournament-level **registration** rows for a team: a registration `ork_participant` row (the team identity, `bracket_id IS NULL`, alias = team name) AND an `ork_participant_teams` row (`bracket_id IS NULL`, `participant_id` → that row, `team_number`), plus member rows in `ork_participant_team_members`, ensuring each member is also a registered individual. Caller wraps in a transaction.

- [ ] **Step 1: Add `ensureTeam()`**

Insert before `AddParticipant` (or right after `ensureRegistrant`). Add via Python.
```php
	/**
	 * Find-or-create the tournament-level registration rows for a team:
	 *  - a registration ork_participant row (the team identity, bracket_id IS NULL, alias=name)
	 *  - an ork_participant_teams row (bracket_id IS NULL, participant_id -> that row, team_number)
	 *  - ork_participant_team_members rows for each member (and ensureRegistrant per member).
	 * Keyed by the tournament-stable team_number. Reuses an existing registration team
	 * when $team['TeamNumber'] is supplied (edit) or a registration row already exists.
	 * $team: ['Name'=>string, 'Members'=>[['MundaneId'=>int],...], 'TeamNumber'=>int(optional)].
	 * Returns ['TeamNumber'=>int, 'TeamId'=>int, 'ParticipantId'=>int]. Caller wraps in a transaction.
	 */
	private function ensureTeam(int $tournament_id, array $team): array {
		$name    = trim($team['Name'] ?? '');
		$members = is_array($team['Members'] ?? null) ? $team['Members'] : [];
		$tnum    = (int)($team['TeamNumber'] ?? 0);

		// Resolve team_number: explicit (edit), else MAX+1.
		if ($tnum <= 0) {
			$max = $this->db->query("SELECT MAX(team_number) AS m FROM " . DB_PREFIX . "participant_teams WHERE tournament_id = $tournament_id");
			$tnum = ($max && $max->next() && $max->m > 0) ? (int)$max->m + 1 : 1;
		}

		// Existing registration team (bracket_id IS NULL) for this number?
		$reg = $this->db->query(
			"SELECT team_id, participant_id FROM " . DB_PREFIX . "participant_teams
			 WHERE tournament_id = $tournament_id AND team_number = $tnum AND bracket_id IS NULL LIMIT 1"
		);
		if ($reg && $reg->next() && valid_id($reg->team_id)) {
			$team_id = (int)$reg->team_id;
			$pid     = (int)$reg->participant_id;
			// Update the name on both the participant identity row and the team row.
			$this->db->query("UPDATE " . DB_PREFIX . "participant SET alias = :a WHERE participant_id = :p", [':a' => $name, ':p' => $pid]);
			$this->db->query("UPDATE " . DB_PREFIX . "participant_teams SET name = :a WHERE team_id = :t", [':a' => $name, ':t' => $team_id]);
		} else {
			// Create the registration participant identity row (bracket_id NULL).
			$this->Participant->clear();
			$this->Participant->tournament_id      = $tournament_id;
			$this->Participant->alias              = $name;
			$this->Participant->participant_number = 0; // teams are tracked by team_number, not participant_number
			$this->Participant->save();
			$pid = (int)$this->Participant->participant_id;
			if (!valid_id($pid)) throw new \RuntimeException('Team identity row save failed');
			$this->db->query("UPDATE " . DB_PREFIX . "participant SET bracket_id = NULL WHERE participant_id = $pid");
			// Create the registration team row (bracket_id NULL).
			$this->db->query(
				"INSERT INTO " . DB_PREFIX . "participant_teams (tournament_id, bracket_id, participant_id, name, team_number)
				 VALUES (:tid, NULL, :pid, :name, :tnum)",
				[':tid' => $tournament_id, ':pid' => $pid, ':name' => $name, ':tnum' => $tnum]
			);
			$team_id = (int)$this->db->GetLastInsertId();
			if (!valid_id($team_id)) throw new \RuntimeException('Team registration row save failed');
		}

		// Replace the member roster for this registration team.
		$this->db->query("DELETE FROM " . DB_PREFIX . "participant_team_members WHERE team_id = $team_id");
		$memberMids = [];
		foreach ($members as $m) {
			$mid = (int)($m['MundaneId'] ?? 0);
			if (!valid_id($mid)) continue;
			$memberMids[] = $mid;
			$this->db->query(
				"INSERT IGNORE INTO " . DB_PREFIX . "participant_team_members (team_id, mundane_id, tournament_id) VALUES (:t, :m, :tid)",
				[':t' => $team_id, ':m' => $mid, ':tid' => $tournament_id]
			);
			// Ensure each member is a registered individual too.
			$this->ensureRegistrant($tournament_id, ['MundaneId' => $mid, 'Alias' => '']);
		}

		// Snapshot summed warrior/griffon level on the team identity row.
		$awards = !empty($memberMids) ? $this->fetchAwardsForMundanes($memberMids) : [];
		$teamWL = 0; $teamGL = 0;
		foreach ($memberMids as $mid) {
			$teamWL += isset($awards[$mid]) ? $this->warriorLevelFromAwards($awards[$mid]) : 0;
			$teamGL += isset($awards[$mid]) ? $this->griffonLevelFromAwards($awards[$mid]) : 0;
		}
		$this->db->query(
			"UPDATE " . DB_PREFIX . "participant SET warrior_level = :wl, griffon_level = :gl WHERE participant_id = :p",
			[':wl' => (int)$teamWL, ':gl' => (int)$teamGL, ':p' => $pid]
		);

		return ['TeamNumber' => $tnum, 'TeamId' => $team_id, 'ParticipantId' => $pid];
	}
```
IMPORTANT: confirm `ork_participant` has `griffon_level` (the existing team branch updates it at ~658). If the registration participant row needs `participant_number` non-zero for any constraint, leave it 0 (teams use `team_number`); verify the insert succeeds. Confirm `ensureRegistrant` signature matches (`($tid, ['MundaneId'=>, 'Alias'=>, ...])`).

- [ ] **Step 2: Syntax check**
```bash
docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/system/lib/ork3/class.Tournament.php
```
Expected: No syntax errors.

- [ ] **Step 3: Commit**
```bash
git add system/lib/ork3/class.Tournament.php
git diff --cached --name-only
git commit -m "Enhancement: ensureTeam helper (tournament-level team find-or-create)"
```

---

## Task 3: `RegisterTeam` + `GetRegisteredTeams`

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php`

- [ ] **Step 1: Add both methods** (after `ensureTeam`). Add via Python.
```php
	public function RegisterTeam($request) {
		if (!$this->check_auth($request)) return NoAuthorization();
		$tid  = (int)($request['TournamentId'] ?? 0);
		$name = trim($request['Name'] ?? '');
		$members = is_array($request['Members'] ?? null) ? $request['Members'] : [];
		if (!valid_id($tid)) return InvalidParameter('TournamentId required');
		if ($name === '') return InvalidParameter('Team name required');
		$hasMember = false;
		foreach ($members as $m) { if (valid_id($m['MundaneId'] ?? 0)) { $hasMember = true; break; } }
		if (!$hasMember) return InvalidParameter('A team needs at least one member');
		$this->db->query('START TRANSACTION');
		try {
			$res = $this->ensureTeam($tid, ['Name' => $name, 'Members' => $members]);
			$this->db->query('COMMIT');
		} catch (\Throwable $e) { $this->db->query('ROLLBACK'); throw $e; }
		$this->bustTournamentReportCache();
		return Success($res);
	}

	public function GetRegisteredTeams($request) {
		$tid = (int)($request['TournamentId'] ?? 0);
		if (!valid_id($tid)) return InvalidParameter('TournamentId required');

		// Registration teams (bracket_id IS NULL), with their identity participant row.
		$r = $this->db->query(
			"SELECT pt.team_id, pt.team_number, pt.participant_id, pt.name,
			        p.warrior_level, p.griffon_level
			 FROM " . DB_PREFIX . "participant_teams pt
			 LEFT JOIN " . DB_PREFIX . "participant p ON p.participant_id = pt.participant_id
			 WHERE pt.tournament_id = $tid AND pt.bracket_id IS NULL
			 ORDER BY pt.team_number"
		);
		$teams = []; $byNum = []; $teamIdToIdx = [];
		if ($r !== false && $r->size() > 0) {
			while ($r->next()) {
				$row = [
					'TeamId'       => (int)$r->team_id,
					'TeamNumber'   => (int)$r->team_number,
					'ParticipantId'=> (int)$r->participant_id,
					'Name'         => $r->name,
					'WarriorLevel' => (int)$r->warrior_level,
					'GriffonLevel' => (int)$r->griffon_level,
					'Members'      => [],
					'Brackets'     => [],
				];
				$teams[] = $row;
				$byNum[(int)$r->team_number] = count($teams) - 1;
				$teamIdToIdx[(int)$r->team_id] = count($teams) - 1;
			}
		}
		if (empty($teams)) return Success([]);

		// Members per registration team.
		$teamIds = array_map(fn($t) => $t['TeamId'], $teams);
		$inIds   = implode(',', array_map('intval', $teamIds));
		$mids = [];
		$rosterByTeam = [];
		$mr = $this->db->query(
			"SELECT ptm.team_id, ptm.mundane_id, mn.persona, mpark.name AS park_name
			 FROM " . DB_PREFIX . "participant_team_members ptm
			 LEFT JOIN " . DB_PREFIX . "mundane mn ON mn.mundane_id = ptm.mundane_id
			 LEFT JOIN " . DB_PREFIX . "park mpark ON mpark.park_id = mn.park_id
			 WHERE ptm.team_id IN ($inIds)"
		);
		if ($mr && $mr->size() > 0) {
			while ($mr->next()) {
				$mid = (int)$mr->mundane_id;
				if ($mid > 0) $mids[$mid] = true;
				$rosterByTeam[(int)$mr->team_id][] = [
					'MundaneId' => $mid, 'Persona' => $mr->persona ?? '',
					'ParkName' => $mr->park_name ?? '', 'WarriorLevel' => 0,
				];
			}
		}
		$awards = !empty($mids) ? $this->fetchAwardsForMundanes(array_keys($mids)) : [];
		foreach ($rosterByTeam as $teamId => &$mList) {
			foreach ($mList as &$m) {
				$mid = $m['MundaneId'];
				$m['WarriorLevel'] = isset($awards[$mid]) ? $this->warriorLevelFromAwards($awards[$mid]) : 0;
			}
			unset($m);
			if (isset($teamIdToIdx[$teamId])) $teams[$teamIdToIdx[$teamId]]['Members'] = $mList;
		}
		unset($mList);

		// Bracket assignments per team_number (non-null bracket team rows joined to bracket).
		$br = $this->db->query(
			"SELECT pt.team_number AS num, b.bracket_id AS bid, b.style AS style
			 FROM " . DB_PREFIX . "participant_teams pt
			 JOIN " . DB_PREFIX . "bracket b ON b.bracket_id = pt.bracket_id
			 WHERE pt.tournament_id = $tid AND pt.bracket_id IS NOT NULL"
		);
		if ($br && $br->size() > 0) {
			while ($br->next()) {
				$num = (int)$br->num;
				if (isset($byNum[$num])) $teams[$byNum[$num]]['Brackets'][] = ['BracketId' => (int)$br->bid, 'BracketStyle' => $br->style];
			}
		}
		return Success($teams);
	}
```
IMPORTANT: confirm the `ork_mundane` persona column is `persona` and `ork_park` name is `name` (the existing `teamRoster()` uses exactly these joins — copy its column names). Confirm `griffon_level` exists on `ork_participant`.

- [ ] **Step 2: CRITICAL — exclude team identity rows from `GetRegistrants` (the Individuals roster).**

A registered team's *identity* row is an `ork_participant` row with `bracket_id IS NULL` and `participant_number = 0` (set by `ensureTeam`). The existing `GetRegistrants()` selects ALL `bracket_id IS NULL` rows for the tournament, so without a guard it would list teams in the Individuals roster. Individual registrations always have `participant_number > 0` (ensureRegistrant uses MAX+1, min 1). Add `AND p.participant_number > 0` to the `GetRegistrants` main query's WHERE clause. Find the query (`WHERE p.tournament_id = $tid AND p.bracket_id IS NULL`) and change it to `WHERE p.tournament_id = $tid AND p.bracket_id IS NULL AND p.participant_number > 0`. Do this via Python.

- [ ] **Step 3: Syntax check + SQL validation** (`php -l`; then run the two SELECTs from `GetRegisteredTeams` directly with a real `$tid` once Task 8 wiring exists, or via the curl test in Task 8). Confirm no errors. Also confirm (Task 8 curl) that after creating a team, the individuals `registrants` endpoint does NOT list the team name as an individual.

- [ ] **Step 4: Commit**
```bash
git add system/lib/ork3/class.Tournament.php
git diff --cached --name-only
git commit -m "Enhancement: RegisterTeam + GetRegisteredTeams; exclude team identities from individual roster"
```

---

## Task 4: `AssignTeamToBracket` / `UnassignTeamFromBracket`

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php`

**Context:** Cloning a team into a bracket = create a per-bracket `ork_participant` entrant row (alias = team name, carry warrior/griffon snapshot), a per-bracket `ork_participant_teams` row (same `team_number`, participant_id → new entrant), and copy member rows into a new `ork_participant_team_members` row set for the new team row. Unassign deletes those per-bracket rows, leaving the registration rows intact.

- [ ] **Step 1: Add both methods.**
```php
	public function AssignTeamToBracket($request) {
		if (!$this->check_auth($request)) return NoAuthorization();
		$tid  = (int)($request['TournamentId'] ?? 0);
		$bid  = (int)($request['BracketId'] ?? 0);
		$nums = $request['TeamNumbers'] ?? [];
		if (!valid_id($tid) || !valid_id($bid)) return InvalidParameter('TournamentId and BracketId required');
		if (!is_array($nums) || empty($nums)) return InvalidParameter('TeamNumbers required');

		$b = $this->db->query("SELECT tournament_id, status, participants FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bid LIMIT 1");
		if (!$b || !$b->next() || (int)$b->tournament_id !== $tid) return InvalidParameter('Bracket does not belong to this tournament.');
		if ($b->participants !== 'team') return InvalidParameter('This bracket is not a team bracket.');
		if ($b->status !== 'setup') return InvalidParameter('Teams can only be assigned while the bracket is in setup.');

		$assigned = [];
		$this->db->query('START TRANSACTION');
		try {
			foreach ($nums as $num) {
				$num = (int)$num;
				if ($num <= 0) continue;
				// Skip if already in this bracket.
				$ex = $this->db->query("SELECT team_id FROM " . DB_PREFIX . "participant_teams WHERE tournament_id=$tid AND bracket_id=$bid AND team_number=$num LIMIT 1");
				if ($ex && $ex->next() && valid_id($ex->team_id)) continue;
				// Source = registration team row.
				$src = $this->db->query("SELECT team_id, participant_id, name FROM " . DB_PREFIX . "participant_teams WHERE tournament_id=$tid AND team_number=$num AND bracket_id IS NULL LIMIT 1");
				if (!$src || !$src->next() || !valid_id($src->team_id)) continue;
				$srcTeamId = (int)$src->team_id;
				$srcPid    = (int)$src->participant_id;
				$teamName  = $src->name;
				// Clone the participant identity row into the bracket (carry snapshot).
				$this->db->query(
					"INSERT INTO " . DB_PREFIX . "participant (tournament_id, bracket_id, alias, unit_id, park_id, kingdom_id, participant_number, warrior_level, griffon_level)
					 SELECT tournament_id, $bid, alias, unit_id, park_id, kingdom_id, participant_number, warrior_level, griffon_level
					 FROM " . DB_PREFIX . "participant WHERE participant_id = $srcPid"
				);
				$newPid = (int)$this->db->GetLastInsertId();
				if (!valid_id($newPid)) { $this->db->query('ROLLBACK'); return InvalidParameter('Team assignment failed.'); }
				// Clone the team row into the bracket.
				$this->db->query(
					"INSERT INTO " . DB_PREFIX . "participant_teams (tournament_id, bracket_id, participant_id, name, team_number)
					 VALUES (:tid, :bid, :pid, :name, :num)",
					[':tid' => $tid, ':bid' => $bid, ':pid' => $newPid, ':name' => $teamName, ':num' => $num]
				);
				$newTeamId = (int)$this->db->GetLastInsertId();
				if (!valid_id($newTeamId)) { $this->db->query('ROLLBACK'); return InvalidParameter('Team assignment failed.'); }
				// Copy member roster (new team_id) + keep ork_participant_mundane populated for the new entrant.
				$rows = $this->db->query("SELECT mundane_id FROM " . DB_PREFIX . "participant_team_members WHERE team_id = $srcTeamId");
				if ($rows && $rows->size() > 0) {
					$mids = [];
					while ($rows->next()) $mids[] = (int)$rows->mundane_id;
					foreach ($mids as $mid) {
						if (!valid_id($mid)) continue;
						$this->db->query(
							"INSERT IGNORE INTO " . DB_PREFIX . "participant_team_members (team_id, mundane_id, tournament_id) VALUES (:t, :m, :tid)",
							[':t' => $newTeamId, ':m' => $mid, ':tid' => $tid]
						);
						$this->Player->clear();
						$this->Player->participant_id = $newPid;
						$this->Player->mundane_id     = $mid;
						$this->Player->tournament_id  = $tid;
						$this->Player->bracket_id     = $bid;
						$this->Player->save();
					}
				}
				$assigned[] = ['TeamNumber' => $num, 'TeamId' => $newTeamId, 'ParticipantId' => $newPid];
			}
			$this->db->query('COMMIT');
		} catch (\Throwable $e) { $this->db->query('ROLLBACK'); throw $e; }
		$this->bustTournamentReportCache();
		return Success(['Assigned' => $assigned]);
	}

	public function UnassignTeamFromBracket($request) {
		if (!$this->check_auth($request)) return NoAuthorization();
		$tid  = (int)($request['TournamentId'] ?? 0);
		$bid  = (int)($request['BracketId'] ?? 0);
		$nums = $request['TeamNumbers'] ?? [];
		if (!valid_id($tid) || !valid_id($bid)) return InvalidParameter('TournamentId and BracketId required');
		if (!is_array($nums) || empty($nums)) return InvalidParameter('TeamNumbers required');
		$b = $this->db->query("SELECT tournament_id, status FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bid LIMIT 1");
		if (!$b || !$b->next() || (int)$b->tournament_id !== $tid) return InvalidParameter('Bracket does not belong to this tournament.');
		if ($b->status !== 'setup') return InvalidParameter('Teams can only be removed while the bracket is in setup.');
		$this->db->query('START TRANSACTION');
		try {
			foreach ($nums as $num) {
				$num = (int)$num;
				if ($num <= 0) continue;
				$rows = $this->db->query("SELECT team_id, participant_id FROM " . DB_PREFIX . "participant_teams WHERE tournament_id=$tid AND bracket_id=$bid AND team_number=$num");
				if ($rows && $rows->size() > 0) {
					$pairs = [];
					while ($rows->next()) $pairs[] = [(int)$rows->team_id, (int)$rows->participant_id];
					foreach ($pairs as $pr) {
						list($teamId, $pid) = $pr;
						$this->db->query("DELETE FROM " . DB_PREFIX . "participant_team_members WHERE team_id = $teamId");
						$this->db->query("DELETE FROM " . DB_PREFIX . "participant_teams WHERE team_id = $teamId");
						if (valid_id($pid)) {
							$this->db->query("DELETE FROM " . DB_PREFIX . "participant_mundane WHERE participant_id = $pid");
							$this->db->query("DELETE FROM " . DB_PREFIX . "participant WHERE participant_id = $pid AND tournament_id = $tid");
						}
					}
				}
			}
			$this->db->query('COMMIT');
		} catch (\Throwable $e) { $this->db->query('ROLLBACK'); throw $e; }
		$this->bustTournamentReportCache();
		return Success(true);
	}
```
IMPORTANT: confirm `ork_participant` has `unit_id, park_id, kingdom_id, participant_number, warrior_level, griffon_level` columns (the individual `AssignToBracket` clones the first set; add `griffon_level`). If `griffon_level` does not exist, drop it from both the SELECT and INSERT lists.

- [ ] **Step 2: Syntax check** — `php -l`. Expected: no errors.

- [ ] **Step 3: Commit**
```bash
git add system/lib/ork3/class.Tournament.php
git diff --cached --name-only
git commit -m "Enhancement: AssignTeamToBracket / UnassignTeamFromBracket (bulk, setup-only)"
```

---

## Task 5: `UpdateTeam` + `RemoveRegisteredTeam`

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php`

- [ ] **Step 1: Add both methods.**
```php
	public function UpdateTeam($request) {
		if (!$this->check_auth($request)) return NoAuthorization();
		$tid  = (int)($request['TournamentId'] ?? 0);
		$num  = (int)($request['TeamNumber'] ?? 0);
		$name = trim($request['Name'] ?? '');
		$members = is_array($request['Members'] ?? null) ? $request['Members'] : [];
		if (!valid_id($tid) || $num <= 0) return InvalidParameter('TournamentId and TeamNumber required');
		if ($name === '') return InvalidParameter('Team name required');
		// Roster edits blocked when the team is in a non-setup bracket; rename always allowed.
		$lock = $this->db->query(
			"SELECT COUNT(*) AS c FROM " . DB_PREFIX . "participant_teams pt
			 JOIN " . DB_PREFIX . "bracket b ON b.bracket_id = pt.bracket_id
			 WHERE pt.tournament_id = $tid AND pt.team_number = $num AND b.status <> 'setup'"
		);
		$locked = ($lock && $lock->next() && (int)$lock->c > 0);
		$this->db->query('START TRANSACTION');
		try {
			if ($locked) {
				// Rename only across all rows for this team_number; do not touch roster.
				$this->db->query("UPDATE " . DB_PREFIX . "participant_teams SET name = :n WHERE tournament_id = :t AND team_number = :num", [':n' => $name, ':t' => $tid, ':num' => $num]);
				$this->db->query(
					"UPDATE " . DB_PREFIX . "participant p
					 JOIN " . DB_PREFIX . "participant_teams pt ON pt.participant_id = p.participant_id
					 SET p.alias = :n WHERE pt.tournament_id = :t AND pt.team_number = :num",
					[':n' => $name, ':t' => $tid, ':num' => $num]
				);
				$this->db->query('COMMIT');
				$this->bustTournamentReportCache();
				return Success(['TeamNumber' => $num, 'RosterLocked' => true]);
			}
			// Not locked: re-run ensureTeam (updates name + replaces registration roster).
			$res = $this->ensureTeam($tid, ['Name' => $name, 'Members' => $members, 'TeamNumber' => $num]);
			// Propagate roster to setup-bracket entrant team rows for this number.
			$setupRows = $this->db->query(
				"SELECT pt.team_id, pt.participant_id, pt.bracket_id FROM " . DB_PREFIX . "participant_teams pt
				 JOIN " . DB_PREFIX . "bracket b ON b.bracket_id = pt.bracket_id
				 WHERE pt.tournament_id = $tid AND pt.team_number = $num AND b.status = 'setup'"
			);
			$targets = [];
			if ($setupRows && $setupRows->size() > 0) { while ($setupRows->next()) $targets[] = [(int)$setupRows->team_id, (int)$setupRows->participant_id, (int)$setupRows->bracket_id]; }
			$srcMids = [];
			foreach ($members as $m) { $mid = (int)($m['MundaneId'] ?? 0); if (valid_id($mid)) $srcMids[] = $mid; }
			foreach ($targets as $t) {
				list($teamId, $pid, $bid) = $t;
				$this->db->query("UPDATE " . DB_PREFIX . "participant SET alias = :n WHERE participant_id = :p", [':n' => $name, ':p' => $pid]);
				$this->db->query("UPDATE " . DB_PREFIX . "participant_teams SET name = :n WHERE team_id = :t", [':n' => $name, ':t' => $teamId]);
				$this->db->query("DELETE FROM " . DB_PREFIX . "participant_team_members WHERE team_id = $teamId");
				$this->db->query("DELETE FROM " . DB_PREFIX . "participant_mundane WHERE participant_id = $pid");
				foreach ($srcMids as $mid) {
					$this->db->query("INSERT IGNORE INTO " . DB_PREFIX . "participant_team_members (team_id, mundane_id, tournament_id) VALUES (:t,:m,:tid)", [':t'=>$teamId, ':m'=>$mid, ':tid'=>$tid]);
					$this->Player->clear();
					$this->Player->participant_id = $pid; $this->Player->mundane_id = $mid;
					$this->Player->tournament_id = $tid; $this->Player->bracket_id = $bid;
					$this->Player->save();
				}
			}
			$this->db->query('COMMIT');
		} catch (\Throwable $e) { $this->db->query('ROLLBACK'); throw $e; }
		$this->bustTournamentReportCache();
		return Success(['TeamNumber' => $num, 'RosterLocked' => false]);
	}

	public function RemoveRegisteredTeam($request) {
		if (!$this->check_auth($request)) return NoAuthorization();
		$tid = (int)($request['TournamentId'] ?? 0);
		$num = (int)($request['TeamNumber'] ?? 0);
		if (!valid_id($tid) || $num <= 0) return InvalidParameter('TournamentId and TeamNumber required');
		// Block if in any non-setup bracket.
		$lock = $this->db->query(
			"SELECT COUNT(*) AS c FROM " . DB_PREFIX . "participant_teams pt
			 JOIN " . DB_PREFIX . "bracket b ON b.bracket_id = pt.bracket_id
			 WHERE pt.tournament_id = $tid AND pt.team_number = $num AND b.status <> 'setup'"
		);
		if ($lock && $lock->next() && (int)$lock->c > 0) {
			return InvalidParameter('This team is in a bracket that has started. Remove it from that bracket first.');
		}
		$this->db->query('START TRANSACTION');
		try {
			$rows = $this->db->query("SELECT team_id, participant_id FROM " . DB_PREFIX . "participant_teams WHERE tournament_id=$tid AND team_number=$num");
			$pairs = [];
			if ($rows && $rows->size() > 0) { while ($rows->next()) $pairs[] = [(int)$rows->team_id, (int)$rows->participant_id]; }
			foreach ($pairs as $pr) {
				list($teamId, $pid) = $pr;
				$this->db->query("DELETE FROM " . DB_PREFIX . "participant_team_members WHERE team_id = $teamId");
				$this->db->query("DELETE FROM " . DB_PREFIX . "participant_teams WHERE team_id = $teamId");
				if (valid_id($pid)) {
					$this->db->query("DELETE FROM " . DB_PREFIX . "participant_mundane WHERE participant_id = $pid");
					$this->db->query("DELETE FROM " . DB_PREFIX . "participant WHERE participant_id = $pid AND tournament_id = $tid");
				}
			}
			$this->db->query('COMMIT');
		} catch (\Throwable $e) { $this->db->query('ROLLBACK'); throw $e; }
		$this->bustTournamentReportCache();
		return Success(true);
	}
```

- [ ] **Step 2: Syntax check** — `php -l`. Expected: no errors.

- [ ] **Step 3: End-to-end DB verification of Tasks 2–5** (after Task 8 wires endpoints; OR validate the SQL paths directly now on a fixture). Defer the full curl proof to Task 8. At minimum confirm `php -l` clean.

- [ ] **Step 4: Commit**
```bash
git add system/lib/ork3/class.Tournament.php
git diff --cached --name-only
git commit -m "Enhancement: UpdateTeam + RemoveRegisteredTeam"
```

---

## Task 6: Refactor `AddParticipant` team branch to auto-register

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php` (team branch ~615–660)

- [ ] **Step 1: Refactor.** In the `elseif (!empty($request['Members']))` branch, BEFORE creating the per-bracket team rows, ensure a tournament-level registration team exists and reuse its `team_number`. Read the exact current branch text, then via Python wrap the per-bracket team creation so it:
  1. calls `$reg = $this->ensureTeam($_tid2, ['Name' => $this->Participant->alias, 'Members' => $request['Members']]); $teamNum = $reg['TeamNumber'];`
  2. sets `team_number = $teamNum` on the per-bracket `INSERT INTO participant_teams` (add the column + value to the existing insert at ~620–624).
  The rest of the per-bracket team branch (member rows, warrior snapshot) stays. Net effect: per-bracket "Add Team" now also creates/reuses a registration team sharing `team_number`.

- [ ] **Step 2: Syntax check** — `php -l`. Expected: no errors.

- [ ] **Step 3: Verify auto-register** (after Task 8, or via fixture): per-bracket Add Team on bracket 63 → a `bracket_id IS NULL` registration team row appears with the same `team_number` as the new bracket-63 team row. Defer curl to Task 8; confirm `php -l` now.

- [ ] **Step 4: Commit**
```bash
git add system/lib/ork3/class.Tournament.php
git diff --cached --name-only
git commit -m "Enhancement: per-bracket Add Team auto-registers via ensureTeam"
```

---

## Task 7: Model pass-throughs

**Files:**
- Modify: `orkui/model/model.Tournament.php`

- [ ] **Step 1: Add pass-throughs** after `remove_registrant` (match the `function x($request) { return $this->Tournament->X($request); }` style; tabs). Via Python.
```php
	function register_team($request) {
		return $this->Tournament->RegisterTeam($request);
	}

	function get_registered_teams($request) {
		return $this->Tournament->GetRegisteredTeams($request);
	}

	function update_team($request) {
		return $this->Tournament->UpdateTeam($request);
	}

	function remove_registered_team($request) {
		return $this->Tournament->RemoveRegisteredTeam($request);
	}

	function assign_team_to_bracket($request) {
		return $this->Tournament->AssignTeamToBracket($request);
	}

	function unassign_team_from_bracket($request) {
		return $this->Tournament->UnassignTeamFromBracket($request);
	}
```

- [ ] **Step 2: Syntax check** — `docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/model/model.Tournament.php`. Expected: no errors.

- [ ] **Step 3: Commit**
```bash
git add orkui/model/model.Tournament.php
git diff --cached --name-only
git commit -m "Enhancement: Model pass-throughs for team management"
```

---

## Task 8: AJAX endpoints — tournament team actions + bracket assignteams/unassignteams

**Files:**
- Modify: `orkui/controller/controller.TournamentAjax.php`

**Context:** Dispatch is an `if/elseif` chain on `$action` in `tournament($p)` and `bracket($p)`. New write actions inject `Token => $this->session->token`, validate ids, echo JSON; arrays arrive as JSON strings via `json_decode` (see `addparticipant` Members and `assign` ParticipantNumbers).

- [ ] **Step 1: Add tournament-level actions** in `tournament($p)` (before the final `else`), mirroring the `registrants`/`register`/`removeregistrant` blocks:
```php
		} elseif ($action === 'registeredteams') {
			$r = $this->Tournament->get_registered_teams([
				'Token'        => $this->session->token,
				'TournamentId' => $tournament_id,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0, 'teams' => $r['Detail'] ?? []])
				: $this->modelError($r);

		} elseif ($action === 'createteam' || $action === 'updateteam') {
			$name = trim($_POST['Name'] ?? '');
			if (!strlen($name)) { echo json_encode(['status' => 1, 'error' => 'Team name required.']); exit; }
			$membersJson = $_POST['Members'] ?? '';
			$members = [];
			if ($membersJson !== '') {
				$decoded = json_decode($membersJson, true);
				if (is_array($decoded)) {
					if (count($decoded) > 64) { echo json_encode(['status' => 1, 'error' => 'Too many team members.']); exit; }
					foreach ($decoded as $m) {
						if (is_array($m) && valid_id($m['MundaneId'] ?? 0)) $members[] = ['MundaneId' => (int)$m['MundaneId']];
					}
				}
			}
			$params = [
				'Token'        => $this->session->token,
				'TournamentId' => $tournament_id,
				'Name'         => $name,
				'Members'      => $members,
			];
			if ($action === 'updateteam') {
				$tn = (int)($_POST['TeamNumber'] ?? 0);
				if ($tn <= 0) { echo json_encode(['status' => 1, 'error' => 'TeamNumber required.']); exit; }
				$params['TeamNumber'] = $tn;
				$r = $this->Tournament->update_team($params);
			} else {
				$r = $this->Tournament->register_team($params);
			}
			echo ($r['Status'] == 0)
				? json_encode(array_merge(['status' => 0], is_array($r['Detail']) ? $r['Detail'] : []))
				: $this->modelError($r);

		} elseif ($action === 'removeteam') {
			$tn = (int)($_POST['TeamNumber'] ?? 0);
			if ($tn <= 0) { echo json_encode(['status' => 1, 'error' => 'TeamNumber required.']); exit; }
			$r = $this->Tournament->remove_registered_team([
				'Token'        => $this->session->token,
				'TournamentId' => $tournament_id,
				'TeamNumber'   => $tn,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0, 'teamNumber' => $tn])
				: $this->modelError($r);
```

- [ ] **Step 2: Add bracket-level actions** in `bracket($p)` (before the final `else`), mirroring `assign`/`unassign`:
```php
		} elseif ($action === 'assignteams' || $action === 'unassignteams') {
			$tid = (int)($_POST['TournamentId'] ?? 0);
			if (!valid_id($tid)) { echo json_encode(['status' => 1, 'error' => 'TournamentId required.']); exit; }
			$nums_arr = json_decode(trim($_POST['TeamNumbers'] ?? ''), true);
			if (!is_array($nums_arr) || count($nums_arr) === 0) { echo json_encode(['status' => 1, 'error' => 'TeamNumbers required.']); exit; }
			$nums_arr = array_values(array_filter(array_map('intval', $nums_arr), fn($n) => $n > 0));
			$payload = [
				'Token'        => $this->session->token,
				'TournamentId' => $tid,
				'BracketId'    => $bracket_id,
				'TeamNumbers'  => $nums_arr,
			];
			if ($action === 'assignteams') {
				$r = $this->Tournament->assign_team_to_bracket($payload);
				echo ($r['Status'] == 0) ? json_encode(['status' => 0, 'assigned' => $r['Detail']['Assigned'] ?? []]) : $this->modelError($r);
			} else {
				$r = $this->Tournament->unassign_team_from_bracket($payload);
				echo ($r['Status'] == 0) ? json_encode(['status' => 0]) : $this->modelError($r);
			}
```

- [ ] **Step 3: Syntax check** — `docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/controller/controller.TournamentAjax.php`. Expected: no errors.

- [ ] **Step 4: Full end-to-end curl verification (Tasks 2–8)** — ONE bash block, tournament 169, team bracket 63 (must be `setup`; if not, record its status, `UPDATE ork_bracket SET status='setup' WHERE bracket_id=63`, and restore after). Steps:
```bash
# login (rath-957, kingdom-17 create => canManage on t169)
curl -s -c /tmp/ckt.txt "http://localhost:19080/orkui/index.php?Route=Login/login" --data "username=rath-957&password=x" -o /dev/null
B="http://localhost:19080/orkui/index.php?Route=TournamentAjax"
CK="-b /tmp/ckt.txt"
curl -s $CK "$B/tournament/169/reeves"   # warm; expect {"status":0,...}
# pick two real kingdom-17 mundane_ids for members:
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SELECT mundane_id,persona FROM ork_mundane WHERE kingdom_id=17 AND persona<>'' LIMIT 2;"
# create team
curl -s $CK -X POST "$B/tournament/169/createteam" --data-urlencode "Name=ZZ Test Team" --data-urlencode 'Members=[{"MundaneId":<M1>},{"MundaneId":<M2>}]'
# list registered teams -> expect ZZ Test Team with 2 members, Brackets []
curl -s $CK "$B/tournament/169/registeredteams" | python3 -c "import sys,json;d=json.load(sys.stdin);print([{'Name':t['Name'],'Num':t['TeamNumber'],'Members':len(t['Members']),'Br':[b['BracketId'] for b in t['Brackets']]} for t in d.get('teams',[]) if t['Name'].startswith('ZZ')])"
# assign to bracket 63 (use the TeamNumber from the list)
curl -s $CK -X POST "$B/bracket/63/assignteams" --data "TournamentId=169" --data-urlencode 'TeamNumbers=[<NUM>]'
# verify rows: one bracket_id IS NULL registration team + one bracket-63 team, same team_number
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SELECT team_id,bracket_id,team_number,name FROM ork_participant_teams WHERE tournament_id=169 AND name='ZZ Test Team';"
# assign again -> no dup (assigned []); unassign -> bracket row gone, registration intact
curl -s $CK -X POST "$B/bracket/63/assignteams" --data "TournamentId=169" --data-urlencode 'TeamNumbers=[<NUM>]'
curl -s $CK -X POST "$B/bracket/63/unassignteams" --data "TournamentId=169" --data-urlencode 'TeamNumbers=[<NUM>]'
# removeteam -> all rows gone
curl -s $CK -X POST "$B/tournament/169/removeteam" --data "TournamentId=169" --data "TeamNumber=<NUM>"
# CLEANUP proof + restore bracket 63 status
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SELECT COUNT(*) AS zz FROM ork_participant_teams WHERE tournament_id=169 AND name='ZZ Test Team'; SELECT COUNT(*) AS zz_p FROM ork_participant WHERE tournament_id=169 AND alias='ZZ Test Team';"
```
Expected: create → 1 NULL-bracket team row (+1 NULL-bracket participant identity row); assign → +1 bracket-63 team row + 1 bracket-63 participant entrant, same team_number; re-assign → `assigned:[]`; unassign → bracket rows gone, NULL rows remain; removeteam → all ZZ rows gone (both counts 0). Restore bracket 63 status if changed. Commit NO debug.

- [ ] **Step 5: Commit**
```bash
git add orkui/controller/controller.TournamentAjax.php
git diff --cached --name-only
git commit -m "Enhancement: Team management AJAX endpoints"
```

---

## Task 9: Sub-tab toggle + Teams roster + controller load

**Files:**
- Modify: `orkui/controller/controller.Tournament.php` (`profile()`)
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl`

- [ ] **Step 1: Controller — load registered teams.** In `profile()`, right after the `registrants` load (~line 283), add (Python):
```php
		$_rt = $this->Tournament->get_registered_teams(['TournamentId' => $tournament_id]);
		$this->data['registered_teams'] = ($_rt['Status'] == 0) ? ($_rt['Detail'] ?? []) : [];
```

- [ ] **Step 2: Embed for JS.** In the `TnConfig = { ... }` literal (after `registrants:`), add:
```php
	registeredTeams:      <?= json_encode($registered_teams ?? [], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
```

- [ ] **Step 3: Sub-tab toggle header.** Replace the Participants tab header bar (`.tn-roster-bar`, ~2695–2699) so it is space-between with a left segmented toggle and a right button that swaps per sub-tab. Wrap the existing individuals roster in a panel div and add an empty teams panel div:
```php
				<div class="tn-roster-bar tn-roster-bar-split">
					<div class="tn-subtabs" role="tablist">
						<button class="tn-subtab tn-subtab-active" id="tn-subtab-individuals" onclick="tnParticipantsSubtab('individuals')">Individuals</button>
						<button class="tn-subtab" id="tn-subtab-teams" onclick="tnParticipantsSubtab('teams')">Teams</button>
					</div>
<?php if ($canManage): ?>
					<button class="tn-btn tn-btn-primary" id="tn-roster-action-individuals" onclick="tnOpenRegisterModal()"><i class="fas fa-user-plus"></i> Register Participant</button>
					<button class="tn-btn tn-btn-primary" id="tn-roster-action-teams" style="display:none" onclick="tnOpenCreateTeamModal()"><i class="fas fa-users"></i> Create Team</button>
<?php endif; ?>
				</div>
				<div id="tn-subpanel-individuals">
					<div id="tn-roster-table-wrap"><!-- existing individuals roster stays here --></div>
				</div>
				<div id="tn-subpanel-teams" style="display:none">
					<div id="tn-teams-table-wrap"></div>
				</div>
```
Keep the EXISTING individuals roster markup inside `#tn-roster-table-wrap` exactly as-is (move it inside `#tn-subpanel-individuals`). Render the initial teams table server-side into `#tn-teams-table-wrap` (Step 4).

- [ ] **Step 4: Teams roster server render** inside `#tn-teams-table-wrap`, iterating `$registered_teams` (PHP). Empty state `<div class="tn-empty">No teams yet.</div>` when empty. Table columns: Team (name), Members (count + expandable roster using the existing `tn-team-roster` expand pattern + warrior pills), Brackets (chips from `Brackets[]`; "Unassigned" muted if empty), Actions (canManage: Assign / Edit / Remove with `data-tnum`). Each `<tr data-tnum>`.

- [ ] **Step 5: Sub-tab JS.** Add `tnParticipantsSubtab(which)` that toggles `#tn-subpanel-individuals`/`#tn-subpanel-teams` display, the `tn-subtab-active` class, and the two action buttons (`tn-roster-action-individuals`/`-teams`). Add `tnRenderTeamsRoster()` that rebuilds `#tn-teams-table-wrap` from `TnConfig.registeredTeams` (parallel to `tnRenderRoster()`; reuse the JS pill renderer). Define both unconditionally.

- [ ] **Step 6: CSS** for `.tn-subtabs`/`.tn-subtab`/`.tn-subtab-active`/`.tn-roster-bar-split`/`.tn-team-chip` + dark-mode (`html[data-theme="dark"] ...`). Reuse existing segmented-toggle styling if present (search for an existing `.tn-seg`/toggle pattern; otherwise add a minimal pill toggle).

- [ ] **Step 7: Verify** — `php -l` controller + template; browser: Participants tab shows the toggle left + button right; clicking Teams swaps the panel + button; teams panel shows empty state or the server-rendered table. Dark-mode walk the toggle.

- [ ] **Step 8: Commit**
```bash
git add orkui/controller/controller.Tournament.php orkui/template/revised-frontend/Tournametnew_index.tpl
git diff --cached --name-only
git commit -m "Enhancement: Participants Individuals/Teams sub-tab toggle + Teams roster"
```

---

## Task 10: Create/Edit Team modal

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl`

- [ ] **Step 1: Modal markup** (`#tn-createteam-overlay`, near the Register modal). Header via `.tn-modal-title` ("Create Team" / "Edit Team"). Hidden `TeamNumber` field (set when editing). Name input. A member builder: a kingdom-scoped player-search input (`tn-createteam-player-text` + `tn-createteam-player-results` `.tn-ac-results`) AND a "pick from registered" affordance (a small list/typeahead of `TnConfig.registrants`). Selected members shown as `tn-team-member-tag` chips with remove buttons. Cancel / Save buttons + feedback div.

- [ ] **Step 2: Search JS** — copy the Add Participant search pattern (`KingdomAjax/playersearch/{kingdomId}&q=`, `tn-ac-results`/`tn-ac-open`, `tnFixedAcPosition` in BOTH branches, debounce, blur-close, global fallback). On select, add a member chip (dedupe by MundaneId). Also render `TnConfig.registrants` as clickable add-chips/checklist that add members the same way.

- [ ] **Step 3: Open/submit JS.** `tnOpenCreateTeamModal()` (reset → create mode) and `tnOpenEditTeamModal(teamNumber)` (prefill name + members from `TnConfig.registeredTeams`). `tnSubmitTeam()` validates name + ≥1 member, POSTs FormData (`Name`, `Members`=`JSON.stringify([{MundaneId}...])`, plus `TeamNumber` when editing) to `tournament/{tid}/createteam` or `/updateteam`. On success: re-fetch `registeredteams` → update `TnConfig.registeredTeams` → `tnRenderTeamsRoster()` → toast → close. Disable Save in-flight. Surface `status:1` errors (e.g. roster-locked) via feedback.

- [ ] **Step 4: CSS/dark-mode** for any new member-builder elements (reuse `tn-team-member-tag` which already has dark selectors).

- [ ] **Step 5: Verify** — `php -l`; browser: Create Team → add one registered individual + one searched player → Save → team appears in the Teams roster with 2 members; Edit → rename → reflected. Curl-prove the player search URL returns rows. Clean up ZZ test teams. Dark-mode walk the modal.

- [ ] **Step 6: Commit**
```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git diff --cached --name-only
git commit -m "Enhancement: Create/Edit Team modal"
```

---

## Task 11: Team roster row actions (assign / remove) + team assign-to-brackets modal

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl`

- [ ] **Step 1: Row actions.** `tnRenderTeamsRoster()` (and the server render) emit per-row actions (canManage): Assign-to-brackets (`tnOpenTeamAssignModal(tnum)`), Edit (`tnOpenEditTeamModal(tnum)`), Remove (`tnRemoveTeam(tnum)`). Use `data-tnum`.

- [ ] **Step 2: Team assign-to-brackets modal** (`#tn-teamassign-overlay`): lists only **team** brackets from `TnConfig.bracketData` (filter `.Bracket.Participants === 'team'`); setup-status toggleable checkboxes pre-checked from the team's `Brackets[]`; non-setup disabled with `data-tip`. `tnSubmitTeamAssign(tnum)` diffs checked-vs-was → ONE `assignteams` + ONE `unassignteams` (parallel `Promise.all`, collect errors), `TeamNumbers=JSON.stringify([tnum])`. On success: refresh `registeredteams` → `tnRenderTeamsRoster()`; reload for bracket-card sync (matching the individual modal's reliability choice). Surface errors via feedback.

- [ ] **Step 3: Remove.** `tnRemoveTeam(tnum)` → `tnConfirm({danger:true,...})` → POST `removeteam`. On `status:1` (in a started bracket) surface the error via toast and keep the row; on `status:0` remove from `TnConfig.registeredTeams` + `tnRenderTeamsRoster()` + toast.

- [ ] **Step 4: CSS/dark-mode** for the modal + chips.

- [ ] **Step 5: Verify** — `php -l`; browser: assign a team to team bracket 63 → chips update, team appears in the bracket card; remove (confirm blocked when in a started bracket). Clean up ZZ fixtures. Dark-mode walk.

- [ ] **Step 6: Commit**
```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git diff --cached --name-only
git commit -m "Enhancement: Team roster actions + team assign-to-brackets modal"
```

---

## Task 12: Bracket-card "Assign Teams" bulk modal + integration/dark-mode sweep

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl`

- [ ] **Step 1: Bracket-card button.** On TEAM bracket cards (`($b['Participants'] ?? '') === 'team'`), when `$canManage`, add an "Assign Teams" button: active (`onclick="tnOpenAssignTeamsModal(<?= $bid ?>)"`) when bracket status === 'setup', else DISABLED with `data-tip="Teams are locked once the bracket starts."`. (Individual brackets keep "Assign Participants"; the existing "Add Team" button stays.)

- [ ] **Step 2: Bulk modal** (`#tn-assignteams-overlay`, one shared overlay): lists `TnConfig.registeredTeams` as checkboxes (name + member count + warrior pills), filter input, Select all/Clear, pre-check teams already in this bracket (team's `Brackets[]` contains bid). `tnSubmitAssignTeams(bid)` diffs → ONE `assignteams` + ONE `unassignteams` (parallel), refresh `registeredteams` → `tnRenderTeamsRoster()` → reload for card sync. Empty-state hint → `tnParticipantsSubtab('teams')` + Create Team.

- [ ] **Step 3: Integration sweep (browser).** Create team → bulk-assign to team bracket 63 via the card modal → appears in card + Teams roster chips → generate/Run still works on the team bracket → per-bracket "Add Team" still works and the added team now shows in the Teams roster (auto-registered, no dup). 

- [ ] **Step 4: Dark-mode checklist** across every new surface (sub-tab toggle, Teams roster, Create/Edit Team modal, team assign modal, bracket Assign Teams modal, chips, member tags): headers (no gray pill), ghost/cancel buttons, inline colors, labels, placeholders, segmented toggle, info/empty states. Fix leaks.

- [ ] **Step 5: Verify + cleanup** — `php -l`; remove all ZZ fixtures (teams + their participant rows + members), restore any bracket status changed; prove COUNT 0.

- [ ] **Step 6: Commit**
```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git diff --cached --name-only
git commit -m "Enhancement: Bracket Assign Teams bulk modal + team management integration"
```

---

## Self-Review (completed)

- **Spec coverage:** sub-tab toggle + swapping button (T9), Teams roster (T9/T11), Create/Edit Team modal with registered+search members (T10), tournament-level team model + ensureTeam (T1/T2), Register/Get/Update/Remove (T3/T5), Assign/Unassign one-to-many setup-only (T4), per-bracket Add Team auto-register (T6), AJAX+model (T7/T8), bracket-card Assign Teams (T12), conventions/dark-mode (every UI task + T12). ✓
- **Placeholders:** none — every code step has concrete code; NOTE/IMPORTANT callouts flag live-schema facts to confirm (griffon_level presence, persona/park column names), not deferred work. ✓
- **Type consistency:** `team_number`/`TeamNumber`/`TeamNumbers[]`, `ensureTeam` return `{TeamNumber,TeamId,ParticipantId}`, `registeredteams`/`createteam`/`updateteam`/`removeteam`/`assignteams`/`unassignteams` actions, `TnConfig.registeredTeams`, `tnRenderTeamsRoster`/`tnParticipantsSubtab`/`tnOpenCreateTeamModal`/`tnOpenEditTeamModal`/`tnOpenTeamAssignModal`/`tnOpenAssignTeamsModal` used consistently. ✓
- **Known risks to confirm during execution:** (a) `ork_participant.griffon_level` column exists (used by existing team branch — almost certainly yes); (b) `ork_participant_teams.participant_id` NOT NULL requires the paired registration participant row (handled in ensureTeam); (c) team bracket 63 must be `setup` for assign tests (flip+restore); (d) registration team's identity `ork_participant` row has `bracket_id IS NULL` and `participant_number = 0` — ensure no query counts it as an individual registrant (GetRegistrants filters individuals by... it returns ALL bracket_id IS NULL rows — see risk e); (e) **GetRegistrants (individuals) must NOT list team identity rows** — team identity rows are `bracket_id IS NULL` `ork_participant` rows, so `GetRegistrants` would include them. MUST fix: GetRegistrants should exclude rows that are team identities (e.g. `participant_number > 0`, since team identity rows have participant_number 0; OR `NOT EXISTS (SELECT 1 FROM participant_teams pt WHERE pt.participant_id = p.participant_id)`). Task 3 implementer MUST add this exclusion to GetRegistrants and verify the Individuals roster does not show teams.
```
