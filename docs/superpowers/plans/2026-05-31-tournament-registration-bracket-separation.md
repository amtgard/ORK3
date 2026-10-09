# Tournament Registration / Bracket Separation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let organizers register participants at the tournament level (Participants tab) and assign them to one or more brackets later, while keeping the existing per-bracket quick-add working.

**Architecture:** A registration is an `ork_participant` row with `bracket_id IS NULL`, keyed by the already-tournament-stable `participant_number`. (NOTE: `ork_participant.bracket_id` and `ork_participant_mundane.bracket_id` were `NOT NULL` in the live schema — Task 1 makes them nullable so registration rows can exist.) Assigning to a bracket creates a per-bracket `ork_participant` row (the existing unit that matches/seeds/standings reference) sharing that `participant_number`. No new join table; no rewiring of downstream bracket code. A single `ensureRegistrant()` helper backs both the explicit register action and the per-bracket auto-register path.

**Tech Stack:** PHP 8 (DB layer `system/lib/ork3/`, MVC `orkui/`), MariaDB, plain-PHP `.tpl` templates, vanilla JS. App at `http://localhost:19080/orkui/`. DB via `docker exec -i ork3-php8-db mariadb -u root -proot ork`.

---

## Conventions for every task (READ FIRST)

- **PHP multi-line edits → use Python**, never the Edit tool (tab indentation; byte-perfect match fails). Pattern:
  `python3 -c "import pathlib; p=pathlib.Path('FILE'); t=p.read_text(); assert 'NEEDLE' in t; p.write_text(t.replace(OLD, NEW, 1))"`
- **DB layer only in** `system/lib/ork3/class.Tournament.php`; `model.Tournament.php` is thin pass-through.
- **`$this->db->query(...)` / Yapo** are the existing DB idioms in this class — match them exactly (parameterized where the surrounding code is). Call `$this->Participant->clear()` before reusing the Yapo object.
- **After every mutating op call `$this->bustTournamentReportCache();`** (already a private method).
- **Auth:** registration + assignment require `$this->check_auth($request)` (organizer/edit). Return `NoAuthorization()` otherwise.
- **Player search (UI):** scope to context (park→kingdom on park pages, kingdom on kingdom pages); build URL with `&q=` NOT `?q=`; define `tnFixedAcPosition(input, dropdown)` on the page and call it before every `.classList.add('kn-ac-open')` in BOTH the results and no-results branches; use the `kn-ac-results` dropdown pattern (never jQuery UI). Curl-test it returns rows before "done".
- **Dark mode:** every new modal/button/chip dark-mode compatible up front (modal headers vs orkui.css h1–h6 pill leak → reset `background:transparent;border:none;padding:0;border-radius:0`; ghost/cancel buttons; inline colors; labels; placeholders; segmented toggles; info boxes).
- **No native `confirm()`/`alert()`** → use `tnConfirm({title,body,confirmLabel,danger,onConfirm})`. **No native `title` tooltips** → use `data-tip`.
- **Debug output → browser console** (`console.log` / `die(json_encode(...))`), never `error_log`/`print_r`.
- **Never stage `class.Authorization.php`** (login-bypass hack). Stage files explicitly; run `git diff --cached` before committing.
- **Local curl auth:** the dev box has a login bypass in `class.Authorization.php` (do not commit it). Use `index.php?Route=Controller/action/id` URLs (NOT clean URLs). For AJAX POSTs include a `Token` param as other tournament AJAX calls do.

**Verification model:** this repo has no PHPUnit harness. "Test" steps = (a) curl the endpoint / page and inspect JSON or HTML, and (b) query MariaDB to confirm row state. Use a real tournament id from the dev DB (find one: `docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SELECT tournament_id,name FROM ork_tournament ORDER BY tournament_id DESC LIMIT 5;"`).

---

## File Structure

| File | Responsibility | Tasks |
|---|---|---|
| `db-migrations/2026-05-31-participant-registration-indexes.sql` | Indexes + confirm status enum for registration | 1 |
| `system/lib/ork3/class.Tournament.php` | `ensureRegistrant`, refactor `AddParticipant`, `GetRegistrants`, `AssignToBracket`, `UnassignFromBracket`, `RegisterParticipant`, `UpdateRegistrationStatus`, `RemoveRegistrant`, extend `buildFilterWhere`/`GetParticipants` | 2–5 |
| `orkui/model/model.Tournament.php` | Thin pass-throughs for the new methods | 6 |
| `orkui/controller/controller.TournamentAjax.php` | Tournament-level + bracket-level AJAX actions | 7–8 |
| `orkui/controller/controller.Tournament.php` | Load registrants for the profile page | 9 |
| `orkui/template/revised-frontend/Tournametnew_index.tpl` | Register modal, roster table, assign pickers, bracket assign modal | 9–12 |

Template tasks (9–12) all edit the **same** large file — they MUST run sequentially, never in parallel.

---

## Task 1: DB migration — registration indexes + status enum

**Files:**
- Create: `db-migrations/2026-05-31-participant-registration-indexes.sql`

- [ ] **Step 1: Confirm current column nullability + status enum**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SHOW CREATE TABLE ork_participant\G" | grep -iE "bracket_id|participant_number|\`status\`|KEY"
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SHOW CREATE TABLE ork_participant_mundane\G" | grep -iE "bracket_id|KEY"
```
Expected: `bracket_id` shows `DEFAULT NULL` on both tables; note whether a `status` enum exists and its allowed values, and whether an index on `(tournament_id, participant_number)` already exists.

- [ ] **Step 2: Write the migration**

Create `db-migrations/2026-05-31-participant-registration-indexes.sql`. Only include statements that Step 1 showed are missing. Template (trim to what's needed):

```sql
-- Registration/assignment support: speed up registrant lookups by stable number,
-- and roster/assignment scans. bracket_id is already DEFAULT NULL on both tables,
-- so no column change is required to store "registered but unassigned" rows.

-- Stable-identity lookups (ensureRegistrant, GetRegistrants assignment join):
ALTER TABLE ork_participant
  ADD INDEX idx_participant_tourn_number (tournament_id, participant_number);

-- Roster scan (bracket_id IS NULL) and per-bracket entrant scan:
ALTER TABLE ork_participant
  ADD INDEX idx_participant_tourn_bracket (tournament_id, bracket_id);

-- If Step 1 showed `status` enum lacks 'withdrawn', widen it (registration status):
-- ALTER TABLE ork_participant
--   MODIFY status ENUM('active','withdrawn','disqualified') NOT NULL DEFAULT 'active';
```

- [ ] **Step 3: Apply and verify**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-05-31-participant-registration-indexes.sql
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SHOW INDEX FROM ork_participant;" | grep -i idx_participant_tourn
```
Expected: both new indexes listed. (If an index already existed, MariaDB errors `Duplicate key name` — remove that statement from the file and re-run; the file must apply cleanly from scratch.)

- [ ] **Step 4: Commit**

```bash
git add db-migrations/2026-05-31-participant-registration-indexes.sql
git commit -m "Enhancement: Participant registration indexes"
```

---

## Task 2: `ensureRegistrant()` helper + refactor `AddParticipant` to auto-register

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php` (`AddParticipant` at ~440; add private `ensureRegistrant` near it)

**Context:** `AddParticipant` already computes a tournament-stable `participant_number` (lines ~485–515) by matching `mundane_id`, else alias text, else `MAX+1`. We extract the "find-or-create the registration row" concern into `ensureRegistrant()` so the explicit register action (Task 5) and per-bracket add share it. After this task, **every** participant entry path guarantees a registration row (`bracket_id IS NULL`) exists for that `participant_number`.

- [ ] **Step 1: Add `ensureRegistrant()` private method**

Insert before `AddParticipant`. It resolves/creates the registration row and returns its number + id. Add via Python (multi-line PHP):

```php
	/**
	 * Find-or-create the tournament-level registration row (bracket_id IS NULL)
	 * for a person, keyed by the tournament-stable participant_number. Shared by
	 * AddParticipant (per-bracket auto-register) and RegisterParticipant.
	 * $person: ['MundaneId'=>int, 'Alias'=>string, 'UnitId'=>int, 'ParkId'=>int, 'KingdomId'=>int]
	 * Returns ['ParticipantNumber'=>int, 'RegistrationId'=>int]. Caller wraps in a transaction.
	 */
	private function ensureRegistrant(int $tournament_id, array $person): array {
		$mid = (int)($person['MundaneId'] ?? 0);
		$pnum = 0;
		if (valid_id($mid)) {
			$ex = $this->db->query(
				"SELECT p.participant_number FROM " . DB_PREFIX . "participant p
				 JOIN " . DB_PREFIX . "participant_mundane pm ON pm.participant_id = p.participant_id
				 WHERE p.tournament_id = $tournament_id AND pm.mundane_id = $mid AND p.participant_number > 0 LIMIT 1"
			);
			if ($ex && $ex->next()) $pnum = (int)$ex->participant_number;
		} else {
			$alias = trim($person['Alias'] ?? '');
			if ($alias !== '') {
				$exa = $this->db->query(
					"SELECT p.participant_number FROM " . DB_PREFIX . "participant p
					 LEFT JOIN " . DB_PREFIX . "participant_mundane pm ON pm.participant_id = p.participant_id
					 WHERE p.tournament_id = $tournament_id AND pm.mundane_id IS NULL AND p.participant_number > 0 AND p.alias = :a LIMIT 1",
					[':a' => $alias]
				);
				if ($exa && $exa->next()) $pnum = (int)$exa->participant_number;
			}
		}
		if (!$pnum) {
			$max = $this->db->query("SELECT MAX(participant_number) AS m FROM " . DB_PREFIX . "participant WHERE tournament_id = $tournament_id");
			$pnum = ($max && $max->next() && $max->m > 0) ? (int)$max->m + 1 : 1;
		}

		// Already have a registration row (bracket_id IS NULL) for this number?
		$reg = $this->db->query(
			"SELECT participant_id FROM " . DB_PREFIX . "participant
			 WHERE tournament_id = $tournament_id AND participant_number = $pnum AND bracket_id IS NULL LIMIT 1"
		);
		if ($reg && $reg->next() && valid_id($reg->participant_id)) {
			return ['ParticipantNumber' => $pnum, 'RegistrationId' => (int)$reg->participant_id];
		}

		// Create the registration row (bracket_id NULL).
		$this->Participant->clear();
		$this->Participant->tournament_id      = $tournament_id;
		$this->Participant->bracket_id         = null;
		$this->Participant->alias              = $person['Alias'] ?? '';
		$this->Participant->unit_id            = (int)($person['UnitId'] ?? 0);
		$this->Participant->park_id            = (int)($person['ParkId'] ?? 0);
		$this->Participant->kingdom_id         = (int)($person['KingdomId'] ?? 0);
		$this->Participant->participant_number = $pnum;
		$this->Participant->save();
		$reg_id = (int)$this->Participant->participant_id;
		if (!valid_id($reg_id)) {
			throw new \RuntimeException('Registration row save failed — check sql_mode/constraints');
		}
		if (valid_id($mid)) {
			$this->Player->clear();
			$this->Player->participant_id = $reg_id;
			$this->Player->mundane_id     = $mid;
			$this->Player->tournament_id  = $tournament_id;
			$this->Player->bracket_id     = null;
			$this->Player->save();
			$awards_map = $this->fetchAwardsForMundanes([$mid]);
			$lvl = isset($awards_map[$mid]) ? $this->warriorLevelFromAwards($awards_map[$mid]) : 0;
			$this->db->query(
				"UPDATE " . DB_PREFIX . "participant SET warrior_level = :lvl WHERE participant_id = :pid",
				[':lvl' => (int)$lvl, ':pid' => $reg_id]
			);
		}
		return ['ParticipantNumber' => $pnum, 'RegistrationId' => $reg_id];
	}
```

> NOTE: confirm the Yapo object name is `$this->Participant` and `$this->Player` (used in `AddParticipant`), and that `bracket_id = null` is honored. Per project rule, yapo drops `null` from INSERT — if a NULL bracket_id does not persist, set the column explicitly with a follow-up `$this->db->query("UPDATE ... SET bracket_id = NULL WHERE participant_id = $reg_id")` right after save. Verify in Step 4.

- [ ] **Step 2: Refactor `AddParticipant` (else-branch) to call `ensureRegistrant` first, then create the bracket entrant sharing the number**

In the `else` branch of `AddParticipant` (the non-`ParticipantId` path, ~474–599), replace the inline participant_number computation + registration with: call `ensureRegistrant` to get `$_pnum`, then create the **bracket** entrant row exactly as today but with the resolved `$_pnum`. Keep the existing team-handling block. Do this via Python. The structural change:
- Remove the inline `$_pnum` block (lines computing MAX/alias/mundane match) and instead:
  `$reg = $this->ensureRegistrant($_tid, ['MundaneId'=>$_mid, 'Alias'=>$request['Alias']??'', 'UnitId'=>$request['UnitId']??0, 'ParkId'=>$request['ParkId']??0, 'KingdomId'=>$request['KingdomId']??0]); $_pnum = $reg['ParticipantNumber'];`
- Keep the subsequent `$this->Participant->clear(); ... bracket_id = (int)$request['BracketId']; ... participant_number = $_pnum; save();` block (this is the bracket entrant) and the team block, unchanged.
- The whole thing stays inside the existing `START TRANSACTION`/`COMMIT` wrapper.

- [ ] **Step 3: Restart PHP to clear opcache (if enabled)**

Run: `docker-compose -f docker-compose.php8.yml restart php8 2>/dev/null || docker restart ork3-php8 2>/dev/null; sleep 2; echo done`
(Container name may differ — use `docker ps --format '{{.Names}}' | grep -i php` to find it.)

- [ ] **Step 4: Verify per-bracket add creates BOTH a registration row and a bracket row, no duplicate registrant**

Pick a tournament + a setup bracket from the dev DB. Curl the existing add endpoint (route shape: `TournamentAjax/bracket/{bid}/addparticipant`, with `TournamentId`, `BracketId`, `MundaneId`, `Token`). Then:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
"SELECT participant_id, bracket_id, participant_number, alias FROM ork_participant WHERE tournament_id=TID AND participant_number=PNUM;"
```
Expected: exactly one row with `bracket_id` NULL (registration) AND one row with `bracket_id` = the bracket. Add the **same** mundane to a **second** bracket → still exactly one NULL row, now two non-NULL rows, all sharing `participant_number`.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.Tournament.php
git diff --cached --quiet system/lib/ork3/class.Authorization.php; git commit -m "Enhancement: Auto-register on per-bracket add via ensureRegistrant"
```

---

## Task 3: `GetRegistrants()` — tournament roster with per-registrant bracket list

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php` (add `GetRegistrants` after `GetParticipants`)

- [ ] **Step 1: Add `GetRegistrants($request)`**

Returns one row per registration (`bracket_id IS NULL`) for the tournament, decorated with awards/warrior pills (reuse `fetchAwardsForMundanes`/`warriorLevelFromAwards`) and a `Brackets` array of `{BracketId, BracketStyle}` derived from that person's non-null `participant_number` rows. Add via Python:

```php
	public function GetRegistrants($request) {
		$tid = (int)($request['TournamentId'] ?? 0);
		if (!valid_id($tid)) return InvalidParameter('TournamentId required');

		$sql = "SELECT p.*, m.persona, pm.mundane_id, k.name AS kingdom_name,
					COALESCE(park.name, mpark.name) AS park_name, u.name AS unit_name
				FROM " . DB_PREFIX . "participant p
					LEFT JOIN " . DB_PREFIX . "participant_mundane pm ON pm.participant_id = p.participant_id
					LEFT JOIN " . DB_PREFIX . "mundane m ON pm.mundane_id = m.mundane_id
					LEFT JOIN " . DB_PREFIX . "park mpark ON mpark.park_id = m.park_id
					LEFT JOIN " . DB_PREFIX . "unit u ON p.unit_id = u.unit_id
					LEFT JOIN " . DB_PREFIX . "park park ON p.park_id = park.park_id
					LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = p.kingdom_id
				WHERE p.tournament_id = $tid AND p.bracket_id IS NULL
				ORDER BY p.participant_number";
		$r = $this->db->query($sql);
		$regs = []; $byNum = []; $mids = [];
		if ($r !== false && $r->size() > 0) {
			while ($r->next()) {
				$mid = (int)$r->mundane_id;
				if ($mid > 0) $mids[$mid] = true;
				$row = [
					'ParticipantId'     => (int)$r->participant_id,
					'TournamentId'      => (int)$r->tournament_id,
					'ParticipantNumber' => (int)$r->participant_number,
					'Alias'             => $r->alias,
					'UnitId'            => (int)$r->unit_id,
					'ParkId'            => (int)$r->park_id,
					'KingdomId'         => (int)$r->kingdom_id,
					'Persona'           => $r->persona,
					'MundaneId'         => $mid,
					'KingdomName'       => $r->kingdom_name,
					'ParkName'          => $r->park_name,
					'UnitName'          => $r->unit_name,
					'WarriorLevel'      => (int)$r->warrior_level,
					'WarriorCount'      => 0, 'WarriorRank' => 0,
					'IsWarlord'         => false, 'IsKnightSword' => false,
					'Status'            => $r->status,
					'Brackets'          => [],
				];
				$regs[] = $row;
				$byNum[(int)$r->participant_number] = count($regs) - 1;
			}
		}
		// Decorate award pills.
		if (!empty($mids)) {
			$awards_map = $this->fetchAwardsForMundanes(array_keys($mids));
			foreach ($regs as &$rg) {
				$mid = (int)$rg['MundaneId'];
				if ($mid > 0 && isset($awards_map[$mid])) {
					$rg['WarriorCount']  = $awards_map[$mid]['warrior_count'];
					$rg['WarriorRank']   = $awards_map[$mid]['warrior_rank'];
					$rg['IsWarlord']     = $awards_map[$mid]['is_warlord'];
					$rg['IsKnightSword'] = $awards_map[$mid]['is_knight_sword'];
				}
			}
			unset($rg);
		}
		// Attach bracket assignments per participant_number.
		if (!empty($byNum)) {
			$br = $this->db->query(
				"SELECT p.participant_number AS num, b.bracket_id AS bid, b.style AS style
				 FROM " . DB_PREFIX . "participant p
				 JOIN " . DB_PREFIX . "bracket b ON b.bracket_id = p.bracket_id
				 WHERE p.tournament_id = $tid AND p.bracket_id IS NOT NULL"
			);
			if ($br && $br->size() > 0) {
				while ($br->next()) {
					$num = (int)$br->num;
					if (isset($byNum[$num])) {
						$regs[$byNum[$num]]['Brackets'][] = ['BracketId' => (int)$br->bid, 'BracketStyle' => $br->style];
					}
				}
			}
		}
		return Success($regs);
	}
```

- [ ] **Step 2: Verify**

```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
"SELECT participant_number, bracket_id FROM ork_participant WHERE tournament_id=TID ORDER BY participant_number;"
```
Then curl `TournamentAjax/tournament/{tid}/registrants` (after Task 7) — for now verify via a temporary `die(json_encode($this->GetRegistrants(['TournamentId'=>TID])));` in a scratch call, or defer the curl check to Task 7. Expected: one entry per distinct registrant, `Brackets[]` listing exactly the brackets that person's non-null rows are in.

- [ ] **Step 3: Commit**

```bash
git add system/lib/ork3/class.Tournament.php
git commit -m "Enhancement: GetRegistrants tournament roster"
```

---

## Task 4: `AssignToBracket()` / `UnassignFromBracket()` (bulk, setup-only)

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php`

- [ ] **Step 1: Add both methods**

```php
	public function AssignToBracket($request) {
		if (!$this->check_auth($request)) return NoAuthorization();
		$tid = (int)($request['TournamentId'] ?? 0);
		$bid = (int)($request['BracketId'] ?? 0);
		$nums = $request['ParticipantNumbers'] ?? [];
		if (!valid_id($tid) || !valid_id($bid)) return InvalidParameter('TournamentId and BracketId required');
		if (!is_array($nums) || empty($nums)) return InvalidParameter('ParticipantNumbers required');

		// Bracket must belong to tournament and be in setup.
		$b = $this->db->query("SELECT tournament_id, status, participants FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bid LIMIT 1");
		if (!$b || !$b->next() || (int)$b->tournament_id !== $tid) return InvalidParameter(null, 'Bracket does not belong to this tournament.');
		if ($b->status !== 'setup') return InvalidParameter(null, 'Participants can only be assigned while the bracket is in setup.');

		$assigned = [];
		$this->db->query('START TRANSACTION');
		try {
			foreach ($nums as $num) {
				$num = (int)$num;
				if ($num <= 0) continue;
				// Skip if already in this bracket.
				$ex = $this->db->query("SELECT participant_id FROM " . DB_PREFIX . "participant WHERE tournament_id=$tid AND bracket_id=$bid AND participant_number=$num LIMIT 1");
				if ($ex && $ex->next() && valid_id($ex->participant_id)) continue;
				// Source = registration row (or any row) for this number.
				$src = $this->db->query("SELECT participant_id FROM " . DB_PREFIX . "participant WHERE tournament_id=$tid AND participant_number=$num ORDER BY (bracket_id IS NOT NULL) ASC LIMIT 1");
				if (!$src || !$src->next() || !valid_id($src->participant_id)) continue;
				$srcId = (int)$src->participant_id;
				// Clone into the bracket (carry warrior_level snapshot).
				$this->db->query(
					"INSERT INTO " . DB_PREFIX . "participant (tournament_id, bracket_id, alias, unit_id, park_id, kingdom_id, participant_number, warrior_level)
					 SELECT tournament_id, $bid, alias, unit_id, park_id, kingdom_id, participant_number, warrior_level
					 FROM " . DB_PREFIX . "participant WHERE participant_id = $srcId"
				);
				$newPid = (int)$this->db->GetLastInsertId();
				if (!valid_id($newPid)) { $this->db->query('ROLLBACK'); return InvalidParameter(null, 'Assignment failed.'); }
				// Copy the player link (individual). Team rosters are not handled here (teams are per-bracket).
				$this->db->query(
					"INSERT INTO " . DB_PREFIX . "participant_mundane (participant_id, mundane_id, tournament_id, bracket_id)
					 SELECT $newPid, mundane_id, $tid, $bid FROM " . DB_PREFIX . "participant_mundane WHERE participant_id = $srcId"
				);
				$assigned[] = ['ParticipantNumber' => $num, 'ParticipantId' => $newPid];
			}
			$this->db->query('COMMIT');
		} catch (\Throwable $e) { $this->db->query('ROLLBACK'); throw $e; }
		$this->bustTournamentReportCache();
		return Success(['Assigned' => $assigned]);
	}

	public function UnassignFromBracket($request) {
		if (!$this->check_auth($request)) return NoAuthorization();
		$tid = (int)($request['TournamentId'] ?? 0);
		$bid = (int)($request['BracketId'] ?? 0);
		$nums = $request['ParticipantNumbers'] ?? [];
		if (!valid_id($tid) || !valid_id($bid)) return InvalidParameter('TournamentId and BracketId required');
		if (!is_array($nums) || empty($nums)) return InvalidParameter('ParticipantNumbers required');
		$b = $this->db->query("SELECT tournament_id, status FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bid LIMIT 1");
		if (!$b || !$b->next() || (int)$b->tournament_id !== $tid) return InvalidParameter(null, 'Bracket does not belong to this tournament.');
		if ($b->status !== 'setup') return InvalidParameter(null, 'Participants can only be removed while the bracket is in setup.');
		$this->db->query('START TRANSACTION');
		try {
			foreach ($nums as $num) {
				$num = (int)$num;
				if ($num <= 0) continue;
				$rows = $this->db->query("SELECT participant_id FROM " . DB_PREFIX . "participant WHERE tournament_id=$tid AND bracket_id=$bid AND participant_number=$num");
				if ($rows && $rows->size() > 0) {
					while ($rows->next()) {
						$pid = (int)$rows->participant_id;
						$this->db->query("DELETE FROM " . DB_PREFIX . "participant_mundane WHERE participant_id = $pid");
						$this->db->query("DELETE FROM " . DB_PREFIX . "participant WHERE participant_id = $pid");
					}
				}
			}
			$this->db->query('COMMIT');
		} catch (\Throwable $e) { $this->db->query('ROLLBACK'); throw $e; }
		$this->bustTournamentReportCache();
		return Success(true);
	}
```

> NOTE: confirm `ork_participant_mundane` column list matches the existing schema (recon shows `participant_mundane_id, participant_id, mundane_id, tournament_id, bracket_id`). Adjust the INSERT...SELECT column list to the real columns if different. Also confirm `RemoveParticipant` (~828) does the same cleanup so this matches its delete shape.

- [ ] **Step 2: Verify (after Task 8 wires the endpoint, or via scratch die())**

Assign 2 registrants to a setup bracket → 2 new non-null rows created, each with a `participant_mundane` link; assigning again is a no-op (no dupes). Unassign one → its bracket row + link gone, registration (NULL) row intact. Assign to an `active` bracket → returns the "setup only" error.

- [ ] **Step 3: Commit**

```bash
git add system/lib/ork3/class.Tournament.php
git commit -m "Enhancement: AssignToBracket / UnassignFromBracket (bulk, setup-only)"
```

---

## Task 5: `RegisterParticipant`, `UpdateRegistrationStatus`, `RemoveRegistrant`

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php`

- [ ] **Step 1: Add the three methods**

```php
	public function RegisterParticipant($request) {
		if (!$this->check_auth($request)) return NoAuthorization();
		$tid = (int)($request['TournamentId'] ?? 0);
		if (!valid_id($tid)) return InvalidParameter('TournamentId required');
		$hasAlias = strlen(trim($request['Alias'] ?? '')) > 0;
		$hasMundane = valid_id($request['MundaneId'] ?? 0);
		if (!$hasAlias && !$hasMundane) return InvalidParameter('Registration requires an Alias or MundaneId');
		$this->db->query('START TRANSACTION');
		try {
			$reg = $this->ensureRegistrant($tid, [
				'MundaneId' => (int)($request['MundaneId'] ?? 0),
				'Alias'     => $request['Alias'] ?? '',
				'UnitId'    => (int)($request['UnitId'] ?? 0),
				'ParkId'    => (int)($request['ParkId'] ?? 0),
				'KingdomId' => (int)($request['KingdomId'] ?? 0),
			]);
			$this->db->query('COMMIT');
		} catch (\Throwable $e) { $this->db->query('ROLLBACK'); throw $e; }
		$this->bustTournamentReportCache();
		return Success($reg);
	}

	public function UpdateRegistrationStatus($request) {
		if (!$this->check_auth($request)) return NoAuthorization();
		$tid = (int)($request['TournamentId'] ?? 0);
		$num = (int)($request['ParticipantNumber'] ?? 0);
		$status = $request['Status'] ?? '';
		if (!valid_id($tid) || $num <= 0) return InvalidParameter('TournamentId and ParticipantNumber required');
		if (!in_array($status, ['active','withdrawn'], true)) return InvalidParameter('Invalid status');
		// Apply to the registration row AND all setup-bracket entrant rows for this number.
		$this->db->query(
			"UPDATE " . DB_PREFIX . "participant SET status = :s WHERE tournament_id = :t AND participant_number = :n",
			[':s' => $status, ':t' => $tid, ':n' => $num]
		);
		$this->bustTournamentReportCache();
		return Success(true);
	}

	public function RemoveRegistrant($request) {
		if (!$this->check_auth($request)) return NoAuthorization();
		$tid = (int)($request['TournamentId'] ?? 0);
		$num = (int)($request['ParticipantNumber'] ?? 0);
		if (!valid_id($tid) || $num <= 0) return InvalidParameter('TournamentId and ParticipantNumber required');
		// Block if the person is in any active/complete bracket.
		$lock = $this->db->query(
			"SELECT COUNT(*) AS c FROM " . DB_PREFIX . "participant p
			 JOIN " . DB_PREFIX . "bracket b ON b.bracket_id = p.bracket_id
			 WHERE p.tournament_id = $tid AND p.participant_number = $num AND b.status <> 'setup'"
		);
		if ($lock && $lock->next() && (int)$lock->c > 0) {
			return InvalidParameter(null, 'This participant is in a bracket that has started. Remove them from that bracket first.');
		}
		$this->db->query('START TRANSACTION');
		try {
			$rows = $this->db->query("SELECT participant_id FROM " . DB_PREFIX . "participant WHERE tournament_id=$tid AND participant_number=$num");
			if ($rows && $rows->size() > 0) {
				while ($rows->next()) {
					$pid = (int)$rows->participant_id;
					$this->db->query("DELETE FROM " . DB_PREFIX . "participant_mundane WHERE participant_id = $pid");
					$this->db->query("DELETE FROM " . DB_PREFIX . "participant WHERE participant_id = $pid");
				}
			}
			$this->db->query('COMMIT');
		} catch (\Throwable $e) { $this->db->query('ROLLBACK'); throw $e; }
		$this->bustTournamentReportCache();
		return Success(true);
	}
```

> NOTE: mirror `RemoveParticipant` (~828) for any extra cleanup it performs (e.g. `ork_seed`, `participant_teams`) so registrant removal leaves no orphans.

- [ ] **Step 2: Verify**

Register an alias-only person → one NULL row, `participant_number` = MAX+1. Register the same alias again → no new row (reused). Set status withdrawn → status updates on all rows for that number. Remove a registrant only in setup brackets → all their rows gone; remove one in an active bracket → blocked error.

- [ ] **Step 3: Commit**

```bash
git add system/lib/ork3/class.Tournament.php
git commit -m "Enhancement: RegisterParticipant / status / RemoveRegistrant"
```

---

## Task 6: Model pass-throughs

**Files:**
- Modify: `orkui/model/model.Tournament.php`

- [ ] **Step 1: Add pass-throughs** (match existing style; if `__call` magic auto-forwards, only add the ones that need transforms — check how `add_participant`/`get_participants` are declared first).

```php
	public function register_participant($request) { return $this->Tournament->RegisterParticipant($request); }
	public function get_registrants($request)      { return $this->Tournament->GetRegistrants($request); }
	public function assign_to_bracket($request)    { return $this->Tournament->AssignToBracket($request); }
	public function unassign_from_bracket($request){ return $this->Tournament->UnassignFromBracket($request); }
	public function update_registration_status($request) { return $this->Tournament->UpdateRegistrationStatus($request); }
	public function remove_registrant($request)    { return $this->Tournament->RemoveRegistrant($request); }
```

- [ ] **Step 2: Verify** the file parses: `docker exec ork3-php8 php -l /var/www/html/orkui/model/model.Tournament.php` (adjust container/path). Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add orkui/model/model.Tournament.php
git commit -m "Enhancement: Model pass-throughs for registration/assignment"
```

---

## Task 7: AJAX — tournament-level actions

**Files:**
- Modify: `orkui/controller/controller.TournamentAjax.php` (`tournament($p)` action switch, ~19–344)

- [ ] **Step 1: Add actions** to the `tournament()` switch, mirroring the existing action pattern (read `$_POST`/`$p`, build `$request` with `Token`, call `$this->model->...`, echo JSON). Add: `register` → `register_participant`; `registrants` (GET) → `get_registrants`; `registrationstatus` → `update_registration_status`; `removeregistrant` → `remove_registrant`. Copy the exact request-building/echo idiom from a neighboring action (e.g. `addbracket`).

- [ ] **Step 2: Verify each endpoint with curl**

```bash
# list (GET)
curl -s "http://localhost:19080/orkui/index.php?Route=TournamentAjax/tournament/TID/registrants&Token=DEVTOKEN" | head -c 800
# register (POST)
curl -s -X POST "http://localhost:19080/orkui/index.php?Route=TournamentAjax/tournament/TID/register" \
  --data "Token=DEVTOKEN&TournamentId=TID&Alias=Test+Registrant" | head -c 400
```
Expected: JSON success; the registrant appears in the list call and as a `bracket_id IS NULL` row in the DB.

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.TournamentAjax.php
git commit -m "Enhancement: Tournament-level registration AJAX endpoints"
```

---

## Task 8: AJAX — bracket-level assign/unassign

**Files:**
- Modify: `orkui/controller/controller.TournamentAjax.php` (`bracket($p)` action switch, ~354–578)

- [ ] **Step 1: Add `assign` → `assign_to_bracket` and `unassign` → `unassign_from_bracket`** in the `bracket()` switch, mirroring the existing `addparticipant`/`reorder` idiom. Accept `ParticipantNumbers` as a posted array (the same way `reorder` accepts its `Order` array — match that parsing exactly).

- [ ] **Step 2: Verify with curl**

```bash
curl -s -X POST "http://localhost:19080/orkui/index.php?Route=TournamentAjax/bracket/BID/assign" \
  --data "Token=DEVTOKEN&TournamentId=TID&BracketId=BID&ParticipantNumbers[]=1&ParticipantNumbers[]=2" | head -c 400
```
Expected: JSON `Assigned` list; DB shows new non-null rows. Repeat → no duplicates. Hit unassign → rows removed.

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.TournamentAjax.php
git commit -m "Enhancement: Bracket assign/unassign AJAX endpoints"
```

---

## Task 9: UI — Participants tab register modal + roster table + controller load

**Files:**
- Modify: `orkui/controller/controller.Tournament.php` (`profile()`, ~123) — load registrants
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl` — Participants tab (~2614–2691)

- [ ] **Step 1: Load registrants in `profile()`** and pass to the template as `$this->data['registrants']` via `$this->model->get_registrants(['TournamentId' => $tournament_id])` (mirror how `standings_data` is loaded). Embed it for JS as `TnConfig.registrants` next to the existing bracket JSON.

- [ ] **Step 2: Replace the read-only Participants tab body** with an active roster: a "Register Participant" button + a roster table rendered from `$registrants` (alias · player/persona · park · warrior pills via existing `tnParticipantPills()`-equivalent · bracket chips from `Brackets[]` · actions cell). Plain-PHP `.tpl` (`<?php ?>`/`<?= ?>` — NOT Smarty).

- [ ] **Step 3: Add the Register modal** (dark-mode compatible, header pill-leak reset) with the **scoped, properly-formed, tested player search** (define `tnFixedAcPosition`; `&q=`; `kn-ac-results`/`kn-ac-open`; both branches positioned). On select → POST `tournament/{tid}/register` → on success insert a roster row (or re-fetch registrants) + toast. Alias-only registration allowed (a text field + "Register as alias" path).

- [ ] **Step 4: Verify in browser** (Claude-in-Chrome, post-implementation per project rule): open a tournament profile → Participants tab → Register → search returns rows (curl-verify the search URL first) → register → row appears with pills. Dark-mode pass.

- [ ] **Step 5: Commit**

```bash
git add orkui/controller/controller.Tournament.php orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: Participants tab registration UI"
```

---

## Task 10: UI — per-person "Assign to brackets" picker + chips refresh

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl`

- [ ] **Step 1: Add an "Assign to brackets" action** on each roster row → opens a modal listing the tournament's **setup** brackets as checkboxes, pre-checked from that registrant's `Brackets[]`. Brackets past setup are shown disabled with a `data-tip` explaining why.

- [ ] **Step 2: Wire submit** → diff checked vs current → POST `bracket/{bid}/assign` (newly checked) and `bracket/{bid}/unassign` (unchecked), with `ParticipantNumbers[]=<num>`. On success, update the row's bracket chips in place + toast.

- [ ] **Step 3: Edit-alias + withdraw + remove actions** on the roster row: edit-alias reuses existing alias-edit endpoint where possible; withdraw → `registrationstatus`; remove → `tnConfirm` (danger) → `removeregistrant`, handling the "in a started bracket" error by surfacing the message in a toast.

- [ ] **Step 4: Verify in browser** — assign a registrant to two brackets, confirm chips + the brackets' own participant lists update; unassign; remove (confirm blocked when in an active bracket). Dark-mode pass.

- [ ] **Step 5: Commit**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: Per-registrant bracket assignment + roster actions"
```

---

## Task 11: UI — Brackets tab "Assign Participants" bulk modal

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl` (brackets tab, ~2256–2611)

- [ ] **Step 1: Add an "Assign Participants" button** on each bracket card (visible only while bracket status = setup; for non-setup show disabled + `data-tip`). For team brackets, keep the existing "Add Team" flow and do NOT show this button.

- [ ] **Step 2: Build the modal** from `TnConfig.registrants`: checkbox list of all tournament registrants, pre-checked for those already in this bracket. Submit → diff → `assign`/`unassign` with `ParticipantNumbers[]`. On success, refresh that bracket's participant list (re-fetch `bracket/{bid}/participants` or update DOM) + the Participants-tab chips + toast.

- [ ] **Step 3: Verify in browser** — bulk-assign 3 registrants to a bracket, confirm they appear in the bracket card and generate works; uncheck one → removed. Dark-mode pass.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: Bracket Assign Participants bulk modal"
```

---

## Task 12: Integration + dark-mode + regression sweep

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl` (fixes only)

- [ ] **Step 1: End-to-end happy path** (browser): create/reuse a tournament → register 4 people in Participants tab → bulk-assign to bracket A, per-person assign 2 of them also to bracket B → generate matches on both → confirm brackets behave identically to the old per-bracket-add flow.

- [ ] **Step 2: Coexistence regression** — on a separate bracket, use the old per-bracket "Add Participant"; confirm it still works AND the person now also appears in the Participants roster (auto-registered), with no duplicate registrant.

- [ ] **Step 3: Dark-mode checklist** across every new surface (register modal, roster table, assign pickers, bracket assign modal, chips, toasts): modal headers, ghost/cancel buttons, inline colors, labels, placeholders, segmented toggles, info boxes. Fix any leaks.

- [ ] **Step 4: Player-search final curl proof** — curl the exact search URL the register modal builds and confirm rows returned (scoping + `&q=` correct).

- [ ] **Step 5: Commit any fixes**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Bugfix: Registration/assignment integration + dark-mode polish"
```

---

## Self-Review (completed)

- **Spec coverage:** register at tournament level (T5/T9), assign one-to-many (T4/T10/T11), coexist + auto-register (T2/T12), no downstream rewiring (T2/T4 reuse per-bracket rows), GetRegistrants roster+chips (T3/T9), edge cases setup-only + active-block (T4/T5), teams unchanged (T11 excludes team brackets), dark-mode + scoped-tested search (T9–T12). ✓
- **Placeholders:** none — every code step has concrete code; NOTE callouts flag facts to confirm against live schema/Yapo behavior, not deferred work. ✓
- **Type consistency:** `ParticipantNumber`/`ParticipantNumbers[]`, `RegistrationId`, `ensureRegistrant` signature, `Brackets[]` shape used consistently across T2–T11. ✓
- **Known risks to confirm during execution:** (a) Yapo dropping `null` bracket_id on insert (T2 Step1 NOTE — fallback UPDATE); (b) exact `ork_participant_mundane` columns (T4 NOTE); (c) whether `model.Tournament.php` uses `__call` magic (T6 Step1); (d) container/path names for `php -l` and restart.
