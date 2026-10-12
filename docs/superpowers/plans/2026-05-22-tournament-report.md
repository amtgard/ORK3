# Tournament Report Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a tabbed, scope-aware Tournament Report at Kingdom and Park level (Overview, Fighters, Awards, Parks) that aggregates tournament results over time, uses Order of the Warrior (0–12) as the ranking proxy, captures warrior-level-at-time-of-competition, and deep-links into the existing award-recommendation flow.

**Architecture:** All DB/business logic lives in a new `system/lib/ork3/class.TournamentReport.php` (auto-registered like every other ork3 lib class; reachable via `new APIModel('TournamentReport')`). `model.Reports.php` gets thin pass-throughs, `controller.Reports.php` gets a `tournaments()` action mirroring `attendance()`/`player_awards()`, and a new `Reports_tournaments.tpl` renders four tabs. A new `ork_participant.warrior_level` column snapshots warrior level when a participant is added. The riskiest unit — deriving 1st/2nd/3rd per bracket — is isolated in `GetBracketPlacements()`.

**Tech Stack:** PHP 8 (no namespaces, custom `Ork3` base class + `yapo` ORM), MariaDB (Docker container `ork3-php8-db`), raw SQL via `$this->db->query()`, GhettoCache, server-rendered `.tpl` templates with inline JS/CSS, Font Awesome, flatpickr.

---

## Verification Approach (read first)

This codebase has **no PHPUnit/composer test harness** and debugs via the browser console / `die(json_encode(...))` per project convention. Classic xUnit TDD is therefore replaced by **DB-cross-check verification**: for each data-layer method you (1) run a raw SQL query against the live Docker DB to compute the expected aggregate, then (2) invoke the method through a one-off CLI probe and confirm the returned JSON matches. This preserves real red→green discipline using the tools that actually exist here.

### PINNED FACTS (Task 0 already done — use these everywhere)

- **App container:** `ork3-php8-app`
- **DB container:** `ork3-php8-db`
- **Code root in app container:** `/var/www/ork.amtgard.com`
- **Bootstrap:** `startup.php` (needs `$_SERVER['HTTP_HOST']` set in CLI)

**CLI probe command (copy-paste, swap the method + args):**

```bash
docker exec -i ork3-php8-app php -r '$_SERVER["HTTP_HOST"]="localhost"; chdir("/var/www/ork.amtgard.com"); require "/var/www/ork.amtgard.com/startup.php"; echo "\n===\n"; echo json_encode((new TournamentReport())->GetBracketPlacements(["BracketId"=>14]), JSON_PRETTY_PRINT);' 2>/dev/null | tail -40
```

**DB query command:**

```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SELECT ..."
```

### PINNED TEST DATA (only one kingdom has real tournament data)

- **Test scope: `KingdomId = 17`** — tournament 154 "Testing Tourney" is the only tournament with brackets/matches/participants. All other tournaments are `kingdom_id=0` with no brackets. Park scope has no data (park_id=0) — verify empty-state handling there, not numbers.
- **Elimination test bracket: `bracket_id = 14`** (method `single`, status `complete`, 8 matches).
- **Standings test bracket: `bracket_id = 15`** (method `ironman`, status `finalized`). Expected placements: **1st = Tobi (mundane 90697), 2nd = Abraxos Junhawk (117252), 3rd = Foil (153303)**.
- **`GetStandings` return shape (verified):** `Success($rows)` →
  `{"Status":0,"Error":"Success","Detail":[ {"ParticipantId":..,"MundaneId":..,"Rank":1,..}, ... ]}`.
  The ranked rows are under the **`Detail`** key and each already carries a competition `Rank` field.
- **Note on warrior_level / upsets:** the new `ork_participant.warrior_level` column is 0 for all existing rows (greenfield, no backfill). Upset-win counts will therefore be 0 until participants are added *after* Task 3 — that is correct, not a bug.

---

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `db-migrations/2026-05-22-participant-warrior-level.sql` | Add `warrior_level` snapshot column | Create |
| `system/lib/ork3/class.TournamentReport.php` | All report aggregation + placement logic | Create |
| `system/lib/ork3/class.Tournament.php` | Write warrior snapshot on participant add | Modify (~line 373) |
| `orkui/model/model.Reports.php` | Thin pass-throughs to the new lib | Modify |
| `orkui/controller/controller.Reports.php` | `tournaments()` action (Type+Id+date parsing) | Modify |
| `orkui/template/default/Reports_tournaments.tpl` | Four-tab report UI | Create |
| `orkui/template/default/style/reports.css` | Shared report CSS (tn-report-* additions) | Modify |
| `orkui/template/revised-frontend/Kingdomnew_index.tpl` | Reports-grid entry link | Modify (~line 678) |
| `orkui/template/revised-frontend/Parknew_index.tpl` | Reports-grid entry link | Modify |

**Method inventory for `class.TournamentReport.php`** (signatures fixed here; later tasks must match exactly):

- `GetBracketPlacements($request)` — `$request['BracketId']` → ordered placements
- `GetTournamentProgramStats($request)` — `{KingdomId|ParkId, DateFrom, DateTo}`
- `GetFighterLeaderboard($request)` — `{KingdomId|ParkId, DateFrom, DateTo}`
- `GetTournamentAwardCandidates($request)` — `{KingdomId|ParkId, DateFrom, DateTo, MinChampionships, MinPodiums}`
- `GetTournamentParkComparison($request)` — `{KingdomId, DateFrom, DateTo}`
- `GetFighterRating($request)` — pluggable rating hook, returns `null` for now
- `warriorLevels(array $mundane_ids)` — public batch helper: live OotW level 0–12 per mundane (award 27/12/20)
- private `scopeWhere($request, $alias)` — shared scope/date WHERE builder
- private `placementsFromElimination` / `placementsFromStandings` / `decoratePlacements` / `matchWinner` / `groupCount` — internal helpers

---

## Task 0: Discovery — ALREADY DONE (facts pinned above)

Container names, code root, bootstrap path, the working probe command, the `GetStandings` return shape, and the test data (KID=17, brackets 14/15) are all pinned in the "Verification Approach" section above. No discovery work remains — use those facts directly.

---

## Task 1: Add `warrior_level` snapshot column

**Files:**
- Create: `db-migrations/2026-05-22-participant-warrior-level.sql`

- [ ] **Step 1: Write the migration**

```sql
-- Snapshot of Order-of-the-Warrior level (0-12) at time of competition.
-- 0 = unranked .. 10 = OotW rank, 11 = Warlord, 12 = Knight of the Sword.
ALTER TABLE ork_participant
  ADD COLUMN warrior_level TINYINT NOT NULL DEFAULT 0;
```

- [ ] **Step 2: Apply the migration**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-05-22-participant-warrior-level.sql
```
Expected: no error.

- [ ] **Step 3: Verify the column exists**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SHOW COLUMNS FROM ork_participant LIKE 'warrior_level';"
```
Expected: one row, `Type = tinyint(4)`, `Default = 0`.

- [ ] **Step 4: Commit**

```bash
git add db-migrations/2026-05-22-participant-warrior-level.sql
git commit -m "Enhancement: add ork_participant.warrior_level snapshot column"
```

---

## Task 2: Create `class.TournamentReport.php` with `GetBracketPlacements()`

This is the critical foundation. A *championship* = winning a bracket; placements are derived per bracket.

**Files:**
- Create: `system/lib/ork3/class.TournamentReport.php`

- [ ] **Step 1: Establish the expected result from the DB**

Pick a completed bracket and read its final match:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "
SELECT b.bracket_id, b.method, b.status FROM ork_bracket b WHERE b.status IN ('complete','finalized') LIMIT 5;"
```
Record one `bracket_id` as `<BID>`, then inspect its matches:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "
SELECT match_id, participant_1_id, participant_2_id, result, round, bracket_side, resolution_order
FROM ork_match WHERE bracket_id = <BID> ORDER BY resolution_order;"
```
By hand, determine who placed 1st/2nd/3rd. This is your expected output.

- [ ] **Step 2: Create the class with `GetBracketPlacements()`**

```php
<?php
/**
 * TournamentReport — aggregation + placement logic for the Tournament Report.
 *
 * Verification probe (see plan Task 0):
 *   docker exec -i <APP> php -r 'require "<BOOT>";
 *     echo json_encode((new TournamentReport())->GetBracketPlacements(["BracketId"=><BID>]));'
 *
 * Auto-registered like every ork3 lib class; reachable via new APIModel('TournamentReport').
 */
class TournamentReport extends Ork3 {

	public function __construct() {
		parent::__construct();
	}

	/**
	 * Ordered placements (1st, 2nd, 3rd, ...) for a single bracket.
	 * Elimination: winner of grand-final/final = 1st, its loser = 2nd, semifinal losers = 3rd.
	 * RR/Swiss/Ironman: top of standings ordering (reuses Tournament::GetStandings).
	 *
	 * Returns: ['BracketId'=>int, 'Method'=>string,
	 *           'Placements'=>[ ['Place'=>1,'ParticipantId'=>..,'MundaneId'=>..,'Alias'=>..], ... ],
	 *           'Status'=>Success()]
	 */
	public function GetBracketPlacements($request) {
		$bracket_id = (int)($request['BracketId'] ?? 0);
		if (!valid_id($bracket_id)) return ['Placements' => [], 'Status' => InvalidParameter('BracketId required')];

		$brow = $this->db->query("SELECT method, status FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bracket_id");
		if ($brow === false || $brow->size() === 0) return ['Placements' => [], 'Status' => InvalidParameter('Bracket not found')];
		$brow->next();
		$method = $brow->method;

		$placements = [];

		if (in_array($method, ['single', 'double'], true)) {
			$placements = $this->placementsFromElimination($bracket_id, $method);
		} else {
			$placements = $this->placementsFromStandings($bracket_id);
		}

		return ['BracketId' => $bracket_id, 'Method' => $method, 'Placements' => $placements, 'Status' => Success()];
	}

	/**
	 * Elimination placements. The decisive match is the grand-final (double) or the
	 * highest-round winners match (single). Winner=1, its opponent=2. 3rd = losers of
	 * the matches feeding the final (semifinals).
	 */
	private function placementsFromElimination($bracket_id, $method) {
		// Decisive match: prefer grand-final, else the match with the largest numeric round.
		$sql = "SELECT match_id, participant_1_id, participant_2_id, result, round, bracket_side
				FROM " . DB_PREFIX . "match
				WHERE bracket_id = $bracket_id AND result IS NOT NULL AND result <> ''
				ORDER BY (bracket_side = 'grand-final') DESC, CAST(round AS UNSIGNED) DESC, resolution_order DESC
				LIMIT 1";
		$r = $this->db->query($sql);
		if ($r === false || $r->size() === 0) return [];
		$r->next();

		$p1 = (int)$r->participant_1_id;
		$p2 = (int)$r->participant_2_id;
		$winner = $this->matchWinner($p1, $p2, $r->result);
		$loser  = ($winner === $p1) ? $p2 : $p1;

		$ordered = [];
		if ($winner > 0) $ordered[] = $winner;
		if ($loser > 0)  $ordered[] = $loser;

		// 3rd place: losers of the two matches in the round just below the decisive round
		// that are NOT already placed. Pull losers ordered by latest resolution.
		$third = $this->db->query(
			"SELECT participant_1_id, participant_2_id, result FROM " . DB_PREFIX . "match
			 WHERE bracket_id = $bracket_id AND result IS NOT NULL AND result <> ''
			 ORDER BY resolution_order DESC LIMIT 6"
		);
		if ($third !== false && $third->size() > 0) {
			while ($third->next() && count($ordered) < 3) {
				$a = (int)$third->participant_1_id; $b = (int)$third->participant_2_id;
				$w = $this->matchWinner($a, $b, $third->result);
				$l = ($w === $a) ? $b : $a;
				if ($l > 0 && !in_array($l, $ordered, true)) $ordered[] = $l;
			}
		}

		return $this->decoratePlacements($bracket_id, $ordered);
	}

	/** RR/Swiss/Ironman: lean on the existing ranked standings. */
	private function placementsFromStandings($bracket_id) {
		$res = Ork3::$Lib->tournament->GetStandings(['BracketId' => $bracket_id]);
		// GetStandings returns Success($rows): { Status, Error, Detail:[ {ParticipantId, MundaneId, Rank, ...} ] }
		// already ordered by competition Rank (wins-desc, losses-asc).
		$rows = (is_array($res) && isset($res['Detail']) && is_array($res['Detail'])) ? $res['Detail'] : [];
		$ordered = [];
		foreach ($rows as $row) {
			$pid = (int)($row['ParticipantId'] ?? 0);
			if ($pid > 0) $ordered[] = $pid;
		}
		return $this->decoratePlacements($bracket_id, array_slice($ordered, 0, 3));
	}

	/** Given an ordered list of participant_ids, attach Place/MundaneId/Alias. */
	private function decoratePlacements($bracket_id, array $orderedPids) {
		if (empty($orderedPids)) return [];
		$idlist = implode(',', array_map('intval', $orderedPids));
		$lookup = [];
		$r = $this->db->query(
			"SELECT p.participant_id, p.alias, pm.mundane_id
			 FROM " . DB_PREFIX . "participant p
			 LEFT JOIN " . DB_PREFIX . "participant_mundane pm ON pm.participant_id = p.participant_id
			 WHERE p.participant_id IN ($idlist)"
		);
		if ($r !== false) { while ($r->next()) { $lookup[(int)$r->participant_id] = ['Alias' => $r->alias, 'MundaneId' => (int)$r->mundane_id]; } }

		$out = []; $place = 1;
		foreach ($orderedPids as $pid) {
			$out[] = [
				'Place' => $place++,
				'ParticipantId' => (int)$pid,
				'MundaneId' => $lookup[$pid]['MundaneId'] ?? 0,
				'Alias' => $lookup[$pid]['Alias'] ?? '',
			];
		}
		return $out;
	}

	/** Resolve a match winner participant_id from the result enum. 0 if no clear winner. */
	private function matchWinner($p1, $p2, $result) {
		switch ($result) {
			case '1-wins': case '2-forfeits': case '2-is-disqualified': case '2-is-bye': return (int)$p1;
			case '2-wins': case '1-forfeits': case '1-is-disqualified': case '1-is-bye': return (int)$p2;
			default: return 0; // tie / disqualified / score / forfeit (ambiguous)
		}
	}
}
```

- [ ] **Step 3: Run the probe and verify against Step 1**

Run (substitute `<APP>`/`<BOOT>`/`<BID>`):
```bash
docker exec -i ork3-php8 php -r 'require "<BOOT>"; echo json_encode((new TournamentReport())->GetBracketPlacements(["BracketId"=><BID>]), JSON_PRETTY_PRINT);'
```
Expected: `Placements` array whose 1st/2nd (and 3rd if present) match the hand-derived result from Step 1. If elimination ordering is off, re-check the decisive-match query against the actual `round`/`bracket_side` values you saw.

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.TournamentReport.php
git commit -m "Enhancement: TournamentReport::GetBracketPlacements bracket placement logic"
```

---

## Task 3: Write the warrior-level snapshot on participant add

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php` (individual-participant branch, ~line 373)

- [ ] **Step 1: Add snapshot write after the individual `participant_mundane` save**

In `AddParticipant`, immediately after the individual save block (the `$this->Player->save();` at ~line 373, inside `if (valid_id($request['MundaneId']))`), insert:

```php
				// Snapshot Order-of-the-Warrior level (0-12) at time of competition.
				$awards_map = $this->fetchAwardsForMundanes([(int)$request['MundaneId']]);
				$lvl = 0;
				if (isset($awards_map[(int)$request['MundaneId']])) {
					$a = $awards_map[(int)$request['MundaneId']];
					$lvl = !empty($a['is_knight_sword']) ? 12
						 : (!empty($a['is_warlord']) ? 11
						 : min(10, max(0, (int)$a['warrior_rank'])));
				}
				$this->db->query(
					"UPDATE " . DB_PREFIX . "participant SET warrior_level = :lvl WHERE participant_id = :pid",
					[':lvl' => (int)$lvl, ':pid' => (int)$_pid]
				);
```

(Note: `$_pid` is set at line 364; `fetchAwardsForMundanes` already exists at line 480. Team participants stay at the default 0 — individual-only for v1 per spec.)

- [ ] **Step 2: Verify with a fresh participant add via the live UI**

Add an individual participant (with a known knighted/warlord/OotW player) to a bracket through the tournament UI, then:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "
SELECT participant_id, warrior_level FROM ork_participant ORDER BY participant_id DESC LIMIT 3;"
```
Expected: the new row's `warrior_level` matches that player's actual award standing (e.g. 12 for a Sword Knight). Cross-check against:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "
SELECT award_id, MAX(\`rank\`) rnk, COUNT(*) cnt FROM ork_awards
WHERE mundane_id = <MID> AND award_id IN (12,20,27) AND revoked = 0 GROUP BY award_id;"
```

- [ ] **Step 3: Commit**

```bash
git add system/lib/ork3/class.Tournament.php
git commit -m "Enhancement: snapshot warrior_level on tournament participant add"
```

---

## Task 4: `GetTournamentProgramStats()` (Overview data)

**Files:**
- Modify: `system/lib/ork3/class.TournamentReport.php`

- [ ] **Step 1: Establish expected totals from the DB**

For a chosen `<KID>`:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "
SELECT COUNT(*) tournaments,
  SUM(status='complete') complete,
  (SELECT COUNT(DISTINCT pm.mundane_id) FROM ork_participant_mundane pm
     JOIN ork_tournament t2 ON t2.tournament_id = pm.tournament_id WHERE t2.kingdom_id=<KID>) unique_participants
FROM ork_tournament t WHERE t.kingdom_id=<KID>;"
```
Record these as expected values.

- [ ] **Step 2: Add the shared scope/date WHERE builder + the method**

Add to the class:

```php
	/**
	 * Builds the shared scope + date WHERE fragment for tournament queries.
	 * $alias is the ork_tournament alias (e.g. 't'). Joins on event are the caller's job
	 * only when needed; scope here matches TournamentReport convention (tournament.kingdom_id/park_id).
	 */
	private function scopeWhere($request, $alias = 't') {
		$w = '';
		if (valid_id($request['KingdomId'] ?? 0)) $w .= " AND $alias.kingdom_id = " . (int)$request['KingdomId'];
		if (valid_id($request['ParkId'] ?? 0))    $w .= " AND $alias.park_id = "    . (int)$request['ParkId'];
		if (!empty($request['DateFrom']))          $w .= " AND $alias.date_time >= '" . $this->db->escape($request['DateFrom']) . "'";
		if (!empty($request['DateTo']))            $w .= " AND $alias.date_time <= '" . $this->db->escape($request['DateTo']) . " 23:59:59'";
		return $w;
	}

	public function GetTournamentProgramStats($request) {
		$key = Ork3::$Lib->ghettocache->key($request);
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 1800)) !== false) return $cache;

		$where = $this->scopeWhere($request, 't');

		// Headline counts
		$row = $this->db->query(
			"SELECT COUNT(*) AS total,
			        SUM(t.status='setup')    AS setup,
			        SUM(t.status='active')   AS active,
			        SUM(t.status='complete') AS complete
			 FROM " . DB_PREFIX . "tournament t WHERE 1 $where"
		);
		$total = $setup = $active = $complete = 0;
		if ($row !== false && $row->size() > 0) { $row->next(); $total=(int)$row->total; $setup=(int)$row->setup; $active=(int)$row->active; $complete=(int)$row->complete; }

		// Unique participants + average warrior level of fields
		$prow = $this->db->query(
			"SELECT COUNT(DISTINCT pm.mundane_id) AS uniq, AVG(NULLIF(p.warrior_level,0)) AS avg_wl, COUNT(p.participant_id) AS part_rows
			 FROM " . DB_PREFIX . "participant p
			 JOIN " . DB_PREFIX . "tournament t ON t.tournament_id = p.tournament_id
			 LEFT JOIN " . DB_PREFIX . "participant_mundane pm ON pm.participant_id = p.participant_id
			 WHERE 1 $where"
		);
		$uniq = 0; $avg_wl = 0.0; $part_rows = 0;
		if ($prow !== false && $prow->size() > 0) { $prow->next(); $uniq=(int)$prow->uniq; $avg_wl=round((float)$prow->avg_wl,1); $part_rows=(int)$prow->part_rows; }

		// By style and by method
		$byStyle  = $this->groupCount("SELECT b.style AS k, COUNT(*) AS c FROM " . DB_PREFIX . "bracket b JOIN " . DB_PREFIX . "tournament t ON t.tournament_id=b.tournament_id WHERE 1 $where GROUP BY b.style ORDER BY c DESC");
		$byMethod = $this->groupCount("SELECT b.method AS k, COUNT(*) AS c FROM " . DB_PREFIX . "bracket b JOIN " . DB_PREFIX . "tournament t ON t.tournament_id=b.tournament_id WHERE 1 $where GROUP BY b.method ORDER BY c DESC");

		// Monthly trend (tournaments + participants)
		$trend = [];
		$tr = $this->db->query(
			"SELECT DATE_FORMAT(t.date_time,'%Y-%m') AS ym, COUNT(DISTINCT t.tournament_id) AS tcount,
			        COUNT(DISTINCT pm.mundane_id) AS pcount
			 FROM " . DB_PREFIX . "tournament t
			 LEFT JOIN " . DB_PREFIX . "participant_mundane pm ON pm.tournament_id = t.tournament_id
			 WHERE 1 $where GROUP BY ym ORDER BY ym"
		);
		if ($tr !== false) { while ($tr->next()) { $trend[] = ['Month'=>$tr->ym, 'Tournaments'=>(int)$tr->tcount, 'Participants'=>(int)$tr->pcount]; } }

		$response = [
			'Totals' => ['Total'=>$total, 'Setup'=>$setup, 'Active'=>$active, 'Complete'=>$complete,
			             'CompletionRate'=> $total>0 ? round(100*$complete/$total) : 0,
			             'UniqueParticipants'=>$uniq,
			             'AvgParticipantsPerTournament'=> $total>0 ? round($part_rows/$total,1) : 0,
			             'AvgWarriorLevel'=>$avg_wl],
			'ByStyle' => $byStyle,
			'ByMethod' => $byMethod,
			'Trend' => $trend,
			'Status' => Success(),
		];
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
	}

	/** helper: run a "k,c" grouped count query into [['Key'=>..,'Count'=>..], ...] */
	private function groupCount($sql) {
		$out = []; $r = $this->db->query($sql);
		if ($r !== false) { while ($r->next()) { $out[] = ['Key'=>$r->k, 'Count'=>(int)$r->c]; } }
		return $out;
	}
```

> **If `$this->db->escape()` does not exist** in this DB wrapper, dates are already constrained to `YYYY-MM-DD` by the flatpickr `altInput`/server cast; replace the escape calls with an explicit format guard: `preg_replace('/[^0-9-]/','',$request['DateFrom'])`. Confirm which by grepping `function escape` in the DB class during Step 3.

- [ ] **Step 2b: Confirm the escape helper**

Run:
```bash
grep -rnE "function (escape|Clear|GetLastInsertId)" system/lib/ | grep -iE "escape" | head
```
If no `escape` method exists, apply the `preg_replace` guard noted above before testing.

- [ ] **Step 3: Probe and verify**

```bash
docker exec -i ork3-php8 php -r 'require "<BOOT>"; echo json_encode((new TournamentReport())->GetTournamentProgramStats(["KingdomId"=><KID>]), JSON_PRETTY_PRINT);'
```
Expected: `Totals.Total`, `Totals.Complete`, `Totals.UniqueParticipants` equal the Step 1 numbers.

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.TournamentReport.php
git commit -m "Enhancement: TournamentReport::GetTournamentProgramStats overview aggregates"
```

---

## Task 5: `GetFighterLeaderboard()` (Fighters data)

Per-player aggregation across **individual** brackets in scope, including championships/podiums (via Task 2), warrior level, and upset wins.

**Files:**
- Modify: `system/lib/ork3/class.TournamentReport.php`

- [ ] **Step 1: Establish expected for one player**

```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "
SELECT pm.mundane_id,
  SUM((m.participant_1_id=p.participant_id AND m.result='1-wins') OR (m.participant_2_id=p.participant_id AND m.result='2-wins')) wins
FROM ork_participant p
JOIN ork_tournament t ON t.tournament_id=p.tournament_id
JOIN ork_participant_mundane pm ON pm.participant_id=p.participant_id
JOIN ork_match m ON (m.participant_1_id=p.participant_id OR m.participant_2_id=p.participant_id)
WHERE t.kingdom_id=<KID>
GROUP BY pm.mundane_id ORDER BY wins DESC LIMIT 5;"
```
Record top player's mundane_id + win count.

- [ ] **Step 2: Implement the method**

```php
	public function GetFighterLeaderboard($request) {
		$key = Ork3::$Lib->ghettocache->key($request);
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 1800)) !== false) return $cache;

		$where = $this->scopeWhere($request, 't');

		// Per-mundane W/L/win%, tournaments entered, current warrior level + best style.
		// Individual brackets only.
		$sql = "SELECT pm.mundane_id, mn.persona,
		           COUNT(DISTINCT p.tournament_id) AS tournaments_entered,
		           COUNT(DISTINCT p.bracket_id)    AS brackets_entered,
		           SUM((m.participant_1_id=p.participant_id AND m.result='1-wins')
		             OR (m.participant_2_id=p.participant_id AND m.result IN ('2-wins','forfeit','disqualified'))) AS wins,
		           SUM((m.participant_1_id=p.participant_id AND m.result IN ('2-wins','forfeit','disqualified'))
		             OR (m.participant_2_id=p.participant_id AND m.result='1-wins')) AS losses,
		           MAX(p.im_max_streak) AS max_streak
		       FROM " . DB_PREFIX . "participant p
		         JOIN " . DB_PREFIX . "tournament t ON t.tournament_id = p.tournament_id
		         JOIN " . DB_PREFIX . "bracket b ON b.bracket_id = p.bracket_id AND b.participants = 'individual'
		         JOIN " . DB_PREFIX . "participant_mundane pm ON pm.participant_id = p.participant_id
		         LEFT JOIN " . DB_PREFIX . "mundane mn ON mn.mundane_id = pm.mundane_id
		         LEFT JOIN " . DB_PREFIX . "match m ON (m.participant_1_id=p.participant_id OR m.participant_2_id=p.participant_id) AND m.bracket_id=p.bracket_id
		       WHERE 1 $where
		       GROUP BY pm.mundane_id, mn.persona";
		$rows = []; $mids = [];
		$r = $this->db->query($sql);
		if ($r !== false) {
			while ($r->next()) {
				$mid = (int)$r->mundane_id; if ($mid < 1) continue;
				$mids[$mid] = true;
				$wins = (int)$r->wins; $losses = (int)$r->losses;
				$rows[$mid] = [
					'MundaneId' => $mid,
					'Persona' => $r->persona,
					'TournamentsEntered' => (int)$r->tournaments_entered,
					'BracketsEntered' => (int)$r->brackets_entered,
					'Wins' => $wins, 'Losses' => $losses,
					'WinPct' => ($wins+$losses)>0 ? round(100*$wins/($wins+$losses)) : 0,
					'MaxStreak' => (int)$r->max_streak,
					'Championships' => 0, 'Podiums' => 0, 'UpsetWins' => 0,
					'WarriorLevel' => 0, 'Rating' => null,
				];
			}
		}

		// Championships / podiums: walk completed individual brackets in scope via GetBracketPlacements.
		$bsql = "SELECT b.bracket_id FROM " . DB_PREFIX . "bracket b
		          JOIN " . DB_PREFIX . "tournament t ON t.tournament_id=b.tournament_id
		          WHERE b.participants='individual' AND b.status IN ('complete','finalized') AND 1 $where";
		$br = $this->db->query($bsql);
		if ($br !== false) {
			while ($br->next()) {
				$pl = $this->GetBracketPlacements(['BracketId' => (int)$br->bracket_id]);
				foreach ($pl['Placements'] as $place) {
					$mid = (int)$place['MundaneId'];
					if ($mid < 1 || !isset($rows[$mid])) continue;
					if ($place['Place'] === 1) $rows[$mid]['Championships']++;
					if ($place['Place'] <= 3)  $rows[$mid]['Podiums']++;
				}
			}
		}

		// Upset wins: won a match where opponent's snapshot warrior_level >= mine + 3.
		$usql = "SELECT pm.mundane_id, COUNT(*) AS upsets
		         FROM " . DB_PREFIX . "match m
		           JOIN " . DB_PREFIX . "bracket b ON b.bracket_id=m.bracket_id AND b.participants='individual'
		           JOIN " . DB_PREFIX . "tournament t ON t.tournament_id=b.tournament_id
		           JOIN " . DB_PREFIX . "participant pw ON pw.participant_id = (CASE WHEN m.result='1-wins' THEN m.participant_1_id WHEN m.result='2-wins' THEN m.participant_2_id END)
		           JOIN " . DB_PREFIX . "participant pl ON pl.participant_id = (CASE WHEN m.result='1-wins' THEN m.participant_2_id WHEN m.result='2-wins' THEN m.participant_1_id END)
		           JOIN " . DB_PREFIX . "participant_mundane pm ON pm.participant_id = pw.participant_id
		         WHERE m.result IN ('1-wins','2-wins') AND pl.warrior_level >= pw.warrior_level + 3 AND 1 $where
		         GROUP BY pm.mundane_id";
		$ur = $this->db->query($usql);
		if ($ur !== false) { while ($ur->next()) { $m=(int)$ur->mundane_id; if (isset($rows[$m])) $rows[$m]['UpsetWins']=(int)$ur->upsets; } }

		// Current warrior level (live, from awards) — the ranking column.
		if (!empty($mids)) {
			$awards = Ork3::$Lib->tournament->fetchAwardsForMundanes(array_keys($mids));
			// fetchAwardsForMundanes is private on Tournament; if inaccessible, replicate the
			// award query here (award_id 27 rank, 12 warlord, 20 sword knight). See Step 2b.
		}

		$list = array_values($rows);
		usort($list, fn($a,$b) => $b['Championships'] <=> $a['Championships'] ?: ($b['WinPct'] <=> $a['WinPct']) ?: ($b['Wins'] <=> $a['Wins']));

		$response = ['Fighters' => $list, 'Status' => Success()];
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
	}
```

- [ ] **Step 2b: Resolve warrior-level decoration (fetchAwardsForMundanes is private)**

`Tournament::fetchAwardsForMundanes()` is `private`, so it cannot be called cross-class. Add a public helper in `class.TournamentReport.php` and use it (do NOT change the visibility of the existing method):

```php
	/** Live OotW level (0-12) per mundane: award 27=rank, 12=Warlord(11), 20=Sword Knight(12). */
	public function warriorLevels(array $mundane_ids) {
		$ids = array_filter(array_map('intval', $mundane_ids), fn($x)=>$x>0);
		$out = [];
		if (empty($ids)) return $out;
		$idlist = implode(',', array_unique($ids));
		$r = $this->db->query(
			"SELECT mundane_id, award_id, IFNULL(MAX(`rank`),0) rnk, COUNT(*) cnt
			 FROM " . DB_PREFIX . "awards WHERE mundane_id IN ($idlist) AND award_id IN (12,20,27) AND revoked=0
			 GROUP BY mundane_id, award_id"
		);
		$acc = [];
		if ($r !== false) { while ($r->next()) { $m=(int)$r->mundane_id; $acc[$m][(int)$r->award_id]=['rnk'=>(int)$r->rnk,'cnt'=>(int)$r->cnt]; } }
		foreach ($acc as $m => $a) {
			if (!empty($a[20]['cnt'])) $out[$m] = 12;
			elseif (!empty($a[12]['cnt'])) $out[$m] = 11;
			else $out[$m] = min(10, max(0, $a[27]['rnk'] ?? 0));
		}
		return $out;
	}
```

Then replace the `fetchAwardsForMundanes` block in Step 2 with:

```php
		if (!empty($mids)) {
			$levels = $this->warriorLevels(array_keys($mids));
			foreach ($rows as $mid => &$row) { $row['WarriorLevel'] = $levels[$mid] ?? 0; $row['Rating'] = $this->GetFighterRating(['MundaneId'=>$mid]); }
			unset($row);
		}
```

- [ ] **Step 2c: Add the pluggable rating hook**

```php
	/** Pluggable skill-rating hook. Returns null until a Glicko2/Elo pipeline exists. */
	public function GetFighterRating($request) {
		return null;
	}
```

- [ ] **Step 3: Probe and verify**

```bash
docker exec -i ork3-php8 php -r 'require "<BOOT>"; $r=(new TournamentReport())->GetFighterLeaderboard(["KingdomId"=><KID>]); echo json_encode(array_slice($r["Fighters"],0,5), JSON_PRETTY_PRINT);'
```
Expected: top fighter's `Wins` matches Step 1; `WarriorLevel` populated for knighted/warlord players; `Championships` ≥ 0 and consistent with completed brackets.

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.TournamentReport.php
git commit -m "Enhancement: TournamentReport::GetFighterLeaderboard with warrior level + upsets"
```

---

## Task 6: `GetTournamentAwardCandidates()` (Awards data)

Builds on the leaderboard; flags fighters whose results outpace their current Warrior rank → Order of the Warrior candidates.

**Files:**
- Modify: `system/lib/ork3/class.TournamentReport.php`

- [ ] **Step 1: Implement**

```php
	/**
	 * Recognition candidates. A fighter qualifies when they meet championship/podium
	 * thresholds in range. Headline reason flags those dominating fields above their
	 * current Warrior rank (Order of the Warrior candidates).
	 */
	public function GetTournamentAwardCandidates($request) {
		$minChamp  = (int)($request['MinChampionships'] ?? 1);
		$minPodium = (int)($request['MinPodiums'] ?? 2);

		$board = $this->GetFighterLeaderboard($request);
		$cands = [];
		foreach ($board['Fighters'] as $f) {
			if ($f['Championships'] < $minChamp && $f['Podiums'] < $minPodium) continue;

			$reasons = [];
			if ($f['Championships'] > 0) $reasons[] = $f['Championships'] . ' tournament championship' . ($f['Championships']>1?'s':'');
			if ($f['Podiums'] > 0)       $reasons[] = $f['Podiums'] . ' podium finish' . ($f['Podiums']>1?'es':'');
			if ($f['UpsetWins'] > 0)     $reasons[] = $f['UpsetWins'] . ' upset win' . ($f['UpsetWins']>1?'s':'') . ' over higher-ranked fighters';

			// OotW signal: strong results but Warrior level not yet at the top tier.
			$ootwCandidate = ($f['Championships'] >= 1 || $f['UpsetWins'] >= 2) && $f['WarriorLevel'] < 10;

			$cands[] = [
				'MundaneId' => $f['MundaneId'],
				'Persona' => $f['Persona'],
				'WarriorLevel' => $f['WarriorLevel'],
				'Championships' => $f['Championships'],
				'Podiums' => $f['Podiums'],
				'UpsetWins' => $f['UpsetWins'],
				'WinPct' => $f['WinPct'],
				'OotWCandidate' => $ootwCandidate,
				'EvidenceNote' => implode('; ', $reasons),
			];
		}
		// OotW candidates first, then by championships.
		usort($cands, fn($a,$b)=> ($b['OotWCandidate']<=>$a['OotWCandidate']) ?: ($b['Championships']<=>$a['Championships']));
		return ['Candidates' => $cands, 'Status' => Success()];
	}
```

- [ ] **Step 2: Probe and verify**

```bash
docker exec -i ork3-php8 php -r 'require "<BOOT>"; echo json_encode((new TournamentReport())->GetTournamentAwardCandidates(["KingdomId"=><KID>]), JSON_PRETTY_PRINT);'
```
Expected: candidates only where Championships≥1 or Podiums≥2; each has a non-empty `EvidenceNote`; `OotWCandidate` true only for sub-rank-10 fighters with a championship or ≥2 upsets.

- [ ] **Step 3: Commit**

```bash
git add system/lib/ork3/class.TournamentReport.php
git commit -m "Enhancement: TournamentReport::GetTournamentAwardCandidates OotW pipeline"
```

---

## Task 7: `GetTournamentParkComparison()` (Parks data, kingdom only)

**Files:**
- Modify: `system/lib/ork3/class.TournamentReport.php`

- [ ] **Step 1: Implement**

```php
	/** Per-park comparison within a kingdom: tournaments hosted, participants, championships, avg warrior level. */
	public function GetTournamentParkComparison($request) {
		if (!valid_id($request['KingdomId'] ?? 0)) return ['Parks' => [], 'Status' => InvalidParameter('KingdomId required')];
		$where = $this->scopeWhere(['KingdomId'=>$request['KingdomId'], 'DateFrom'=>$request['DateFrom']??null, 'DateTo'=>$request['DateTo']??null], 't');

		$sql = "SELECT pk.park_id, pk.name AS park_name,
		           COUNT(DISTINCT t.tournament_id) AS hosted,
		           COUNT(DISTINCT pm.mundane_id) AS participants,
		           AVG(NULLIF(p.warrior_level,0)) AS avg_wl
		        FROM " . DB_PREFIX . "tournament t
		          JOIN " . DB_PREFIX . "park pk ON pk.park_id = t.park_id
		          LEFT JOIN " . DB_PREFIX . "participant p ON p.tournament_id = t.tournament_id
		          LEFT JOIN " . DB_PREFIX . "participant_mundane pm ON pm.participant_id = p.participant_id
		        WHERE t.park_id > 0 $where
		        GROUP BY pk.park_id, pk.name ORDER BY hosted DESC";
		$parks = [];
		$r = $this->db->query($sql);
		if ($r !== false) {
			while ($r->next()) {
				$parks[(int)$r->park_id] = [
					'ParkId' => (int)$r->park_id, 'ParkName' => $r->park_name,
					'TournamentsHosted' => (int)$r->hosted, 'Participants' => (int)$r->participants,
					'AvgWarriorLevel' => round((float)$r->avg_wl,1), 'Championships' => 0, 'TopFighter' => '',
				];
			}
		}

		// Championships by park (via placements on completed individual brackets in this kingdom).
		$bsql = "SELECT b.bracket_id FROM " . DB_PREFIX . "bracket b
		          JOIN " . DB_PREFIX . "tournament t ON t.tournament_id=b.tournament_id
		          WHERE b.participants='individual' AND b.status IN ('complete','finalized') $where";
		$br = $this->db->query($bsql);
		if ($br !== false) {
			while ($br->next()) {
				$pl = $this->GetBracketPlacements(['BracketId'=>(int)$br->bracket_id]);
				foreach ($pl['Placements'] as $place) {
					if ($place['Place'] !== 1) continue;
					// participant.park_id of the champion
					$prow = $this->db->query("SELECT park_id FROM " . DB_PREFIX . "participant WHERE participant_id = " . (int)$place['ParticipantId']);
					if ($prow !== false && $prow->size() > 0) { $prow->next(); $pkid=(int)$prow->park_id; if (isset($parks[$pkid])) $parks[$pkid]['Championships']++; }
				}
			}
		}

		return ['Parks' => array_values($parks), 'Status' => Success()];
	}
```

- [ ] **Step 2: Probe and verify**

```bash
docker exec -i ork3-php8 php -r 'require "<BOOT>"; echo json_encode((new TournamentReport())->GetTournamentParkComparison(["KingdomId"=><KID>]), JSON_PRETTY_PRINT);'
```
Expected: one row per park that hosted a tournament; `TournamentsHosted` sums to ≤ the kingdom tournament total.

- [ ] **Step 3: Commit**

```bash
git add system/lib/ork3/class.TournamentReport.php
git commit -m "Enhancement: TournamentReport::GetTournamentParkComparison cross-park stats"
```

---

## Task 8: Model pass-throughs

**Files:**
- Modify: `orkui/model/model.Reports.php`

- [ ] **Step 1: Add the `TournamentReport` APIModel + pass-throughs**

In the constructor, after `$this->Report = new APIModel('Report');`:

```php
		$this->TournamentReport = new APIModel('TournamentReport');
```

Add these methods to the class:

```php
	function tournament_program_stats($request) {
		return $this->TournamentReport->GetTournamentProgramStats($request);
	}
	function tournament_fighter_leaderboard($request) {
		return $this->TournamentReport->GetFighterLeaderboard($request);
	}
	function tournament_award_candidates($request) {
		return $this->TournamentReport->GetTournamentAwardCandidates($request);
	}
	function tournament_park_comparison($request) {
		return $this->TournamentReport->GetTournamentParkComparison($request);
	}
```

- [ ] **Step 2: Verify the model loads (no parse error)**

Run:
```bash
docker exec -i ork3-php8 php -l /var/www/html/orkui/model/model.Reports.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add orkui/model/model.Reports.php
git commit -m "Enhancement: model.Reports pass-throughs for TournamentReport"
```

---

## Task 9: Controller action `tournaments()`

**Files:**
- Modify: `orkui/controller/controller.Reports.php`

- [ ] **Step 1: Add the action (mirrors player_awards Type/Id parsing + adds date range)**

```php
	public function tournaments($params=null) {
		$this->data['page_title'] = "Tournament Report";
		$type = ''; $id = 0;
		if (isset($this->request->KingdomId)) { $type = 'Kingdom'; $id = (int)$this->request->KingdomId; $this->data['page_title'] = "Kingdom Tournament Report"; }
		if (isset($this->request->ParkId))    { $type = 'Park';    $id = (int)$this->request->ParkId;    $this->data['page_title'] = "Park Tournament Report"; }

		$dateFrom = isset($this->request->DateFrom) ? preg_replace('/[^0-9-]/','',$this->request->DateFrom) : '';
		$dateTo   = isset($this->request->DateTo)   ? preg_replace('/[^0-9-]/','',$this->request->DateTo)   : '';

		$scope = [
			'KingdomId' => $type==='Kingdom' ? $id : 0,
			'ParkId'    => $type==='Park'    ? $id : 0,
			'DateFrom'  => $dateFrom,
			'DateTo'    => $dateTo,
		];

		$this->data['ScopeType']    = strtolower($type);
		$this->data['ScopeId']      = $id;
		$this->data['DateFrom']     = $dateFrom;
		$this->data['DateTo']       = $dateTo;
		$this->data['ProgramStats'] = $this->Reports->tournament_program_stats($scope);
		$this->data['Leaderboard']  = $this->Reports->tournament_fighter_leaderboard($scope);
		$this->data['AwardCandidates'] = $this->Reports->tournament_award_candidates($scope);
		$this->data['ParkComparison']  = $type==='Kingdom'
			? $this->Reports->tournament_park_comparison(['KingdomId'=>$id, 'DateFrom'=>$dateFrom, 'DateTo'=>$dateTo])
			: ['Parks'=>[]];
		$this->template = 'Reports_tournaments.tpl';
	}
```

- [ ] **Step 2: Verify routing returns the page (not a 500)**

In a browser (logged in), open:
`http://localhost:19080/orkui/Reports/tournaments/Kingdom&id=<KID>`
Then check the browser console / network tab for a 200 and no PHP fatal. (Template is built in Task 10; for now expect a "template not found" or blank — confirm the controller method itself doesn't fatal by temporarily adding `die(json_encode($this->data['ProgramStats']));` at the end of the method, viewing the JSON, then removing it.)

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.Reports.php
git commit -m "Enhancement: Reports/tournaments controller action"
```

---

## Task 10: `Reports_tournaments.tpl` — structure + tabs + date filter

The template renders four tabs from `$this->data`. CSS prefixed `tnr-`. **Dark-mode compatible from the start** (per project checklist): no inline `color:#xxx` that breaks on dark; reset global h1–h6 box styling on any heading; use `data-tip` not `title`; flatpickr with `altInput`+`altFormat`.

**Files:**
- Create: `orkui/template/default/Reports_tournaments.tpl`

- [ ] **Step 1: Scaffold — header, date filter, tab nav, empty panels**

```php
<?php /* Tournament Report — Overview / Fighters / Awards / Parks */ ?>
<link rel="stylesheet" href="<?= UIR ?>template/default/style/reports.css">
<div class="tnr-report" id="tnr-report"
     data-scope="<?= htmlspecialchars($this->data['ScopeType']) ?>"
     data-id="<?= (int)$this->data['ScopeId'] ?>">

  <div class="tnr-head">
    <h2 class="tnr-title"><?= htmlspecialchars($this->data['page_title']) ?></h2>
    <form class="tnr-filter" method="get" id="tnr-filter">
      <input type="hidden" name="<?= $this->data['ScopeType']==='park' ? 'ParkId' : 'KingdomId' ?>" value="<?= (int)$this->data['ScopeId'] ?>">
      <label>From <input type="text" class="tnr-date" name="DateFrom" value="<?= htmlspecialchars($this->data['DateFrom']) ?>"></label>
      <label>To <input type="text" class="tnr-date" name="DateTo" value="<?= htmlspecialchars($this->data['DateTo']) ?>"></label>
      <button type="submit" class="tnr-btn">Apply</button>
      <?php if ($this->data['DateFrom'] || $this->data['DateTo']): ?>
        <a class="tnr-btn tnr-btn-ghost" href="<?= UIR ?>Reports/tournaments/<?= ucfirst($this->data['ScopeType']) ?>&id=<?= (int)$this->data['ScopeId'] ?>">All time</a>
      <?php endif; ?>
    </form>
  </div>

  <nav class="tnr-tabs" role="tablist">
    <button class="tnr-tab tnr-active" data-tnrtab="overview"><i class="fas fa-chart-line"></i> Overview</button>
    <button class="tnr-tab" data-tnrtab="fighters"><i class="fas fa-khanda"></i> Fighters</button>
    <button class="tnr-tab" data-tnrtab="awards"><i class="fas fa-medal"></i> Awards</button>
    <?php if ($this->data['ScopeType']==='kingdom'): ?>
      <button class="tnr-tab" data-tnrtab="parks"><i class="fas fa-map-marked-alt"></i> Parks</button>
    <?php endif; ?>
  </nav>

  <div class="tnr-panel tnr-active" id="tnr-tab-overview"><?php /* Step 2 */ ?></div>
  <div class="tnr-panel" id="tnr-tab-fighters"><?php /* Step 3 */ ?></div>
  <div class="tnr-panel" id="tnr-tab-awards"><?php /* Step 4 */ ?></div>
  <?php if ($this->data['ScopeType']==='kingdom'): ?>
    <div class="tnr-panel" id="tnr-tab-parks"><?php /* Step 5 */ ?></div>
  <?php endif; ?>
</div>
```

- [ ] **Step 2: Overview panel content**

Inside `#tnr-tab-overview`:

```php
<?php $T = $this->data['ProgramStats']['Totals'] ?? []; ?>
<div class="tnr-stat-row">
  <div class="tnr-stat"><div class="tnr-stat-num"><?= (int)($T['Total']??0) ?></div><div class="tnr-stat-lbl">Tournaments</div></div>
  <div class="tnr-stat"><div class="tnr-stat-num"><?= (int)($T['UniqueParticipants']??0) ?></div><div class="tnr-stat-lbl">Unique Fighters</div></div>
  <div class="tnr-stat"><div class="tnr-stat-num"><?= (int)($T['CompletionRate']??0) ?>%</div><div class="tnr-stat-lbl">Completion Rate</div></div>
  <div class="tnr-stat"><div class="tnr-stat-num"><?= htmlspecialchars($T['AvgWarriorLevel']??0) ?></div><div class="tnr-stat-lbl">Avg Warrior Level</div></div>
</div>

<div class="tnr-grid2">
  <div class="tnr-card"><h4 class="tnr-h">By Style</h4>
    <?php foreach (($this->data['ProgramStats']['ByStyle']??[]) as $s): ?>
      <div class="tnr-bar-row"><span><?= htmlspecialchars($s['Key']) ?></span><b><?= (int)$s['Count'] ?></b></div>
    <?php endforeach; ?>
  </div>
  <div class="tnr-card"><h4 class="tnr-h">By Method</h4>
    <?php foreach (($this->data['ProgramStats']['ByMethod']??[]) as $s): ?>
      <div class="tnr-bar-row"><span><?= htmlspecialchars($s['Key']) ?></span><b><?= (int)$s['Count'] ?></b></div>
    <?php endforeach; ?>
  </div>
</div>

<div class="tnr-card"><h4 class="tnr-h">Activity Over Time</h4>
  <svg id="tnr-trend" class="tnr-trend" viewBox="0 0 600 160" preserveAspectRatio="none"></svg>
</div>
<script>window.__tnrTrend = <?= json_encode($this->data['ProgramStats']['Trend'] ?? []) ?>;</script>
```

- [ ] **Step 3: Fighters panel — sortable table**

```php
<table class="tnr-table tnr-sortable" id="tnr-fighters">
  <thead><tr>
    <th data-sort="text">Fighter</th>
    <th data-sort="num" data-tip="Order of the Warrior 0-12">Warrior</th>
    <th data-sort="num">Tournaments</th>
    <th data-sort="num">W</th><th data-sort="num">L</th><th data-sort="num">Win %</th>
    <th data-sort="num">Championships</th><th data-sort="num">Podiums</th>
    <th data-sort="num">Streak</th><th data-sort="num" data-tip="Wins vs fighters 3+ Warrior levels higher">Upsets</th>
  </tr></thead>
  <tbody>
  <?php foreach (($this->data['Leaderboard']['Fighters']??[]) as $f): ?>
    <tr>
      <td><a href="<?= UIR ?>Player/index/<?= (int)$f['MundaneId'] ?>"><?= htmlspecialchars($f['Persona']) ?></a></td>
      <td><?= (int)$f['WarriorLevel'] ?></td>
      <td><?= (int)$f['TournamentsEntered'] ?></td>
      <td><?= (int)$f['Wins'] ?></td><td><?= (int)$f['Losses'] ?></td><td><?= (int)$f['WinPct'] ?>%</td>
      <td><?= (int)$f['Championships'] ?></td><td><?= (int)$f['Podiums'] ?></td>
      <td><?= (int)$f['MaxStreak'] ?></td><td><?= (int)$f['UpsetWins'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if (empty($this->data['Leaderboard']['Fighters'])): ?><div class="tnr-empty">No individual-bracket results in range.</div><?php endif; ?>
```

- [ ] **Step 4: Awards panel — candidates + recommend deep-link**

```php
<?php foreach (($this->data['AwardCandidates']['Candidates']??[]) as $c): ?>
  <div class="tnr-cand <?= $c['OotWCandidate'] ? 'tnr-cand-ootw' : '' ?>">
    <div class="tnr-cand-main">
      <a class="tnr-cand-name" href="<?= UIR ?>Player/index/<?= (int)$c['MundaneId'] ?>"><?= htmlspecialchars($c['Persona']) ?></a>
      <span class="tnr-cand-wl">Warrior <?= (int)$c['WarriorLevel'] ?></span>
      <?php if ($c['OotWCandidate']): ?><span class="tnr-pill">Order of the Warrior candidate</span><?php endif; ?>
      <div class="tnr-cand-note"><?= htmlspecialchars($c['EvidenceNote']) ?></div>
    </div>
    <a class="tnr-btn"
       href="<?= UIR ?>Player/profile/<?= (int)$c['MundaneId'] ?>/addrecommendation?note=<?= rawurlencode($c['EvidenceNote']) ?>"
       data-tip="Open the award recommendation form pre-filled with this evidence">Recommend</a>
  </div>
<?php endforeach; ?>
<?php if (empty($this->data['AwardCandidates']['Candidates'])): ?><div class="tnr-empty">No recognition candidates meet the thresholds in range.</div><?php endif; ?>
```

> **Confirm the recommend deep-link target.** Task 10b verifies that `Player/profile/{id}/addrecommendation` accepts a `note` query param and pre-fills. If it does not, fall back to linking the player's Playernew recommendation modal (`Player/index/{id}` then trigger), and capture the chosen approach in the plan's notes.

- [ ] **Step 4b: Verify the recommend target pre-fills**

Open one candidate's Recommend link in the browser. Expected: the existing recommendation form opens with the note text present. If the `note` param is ignored, switch the href to the player profile and note the limitation (manual paste) — do not invent a new endpoint.

- [ ] **Step 5: Parks panel (kingdom only)**

```php
<table class="tnr-table tnr-sortable" id="tnr-parks">
  <thead><tr><th data-sort="text">Park</th><th data-sort="num">Tournaments Hosted</th><th data-sort="num">Participants</th><th data-sort="num">Championships</th><th data-sort="num">Avg Warrior</th></tr></thead>
  <tbody>
  <?php foreach (($this->data['ParkComparison']['Parks']??[]) as $p): ?>
    <tr>
      <td><a href="<?= UIR ?>Park/index/<?= (int)$p['ParkId'] ?>"><?= htmlspecialchars($p['ParkName']) ?></a></td>
      <td><?= (int)$p['TournamentsHosted'] ?></td><td><?= (int)$p['Participants'] ?></td>
      <td><?= (int)$p['Championships'] ?></td><td><?= htmlspecialchars($p['AvgWarriorLevel']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
```

- [ ] **Step 6: Inline JS — tabs, sortable, flatpickr, trend chart**

Append before the closing of the template:

```php
<script>
(function(){
  var root = document.getElementById('tnr-report'); if(!root) return;

  // Tabs
  root.querySelectorAll('.tnr-tab').forEach(function(btn){
    btn.addEventListener('click', function(){
      root.querySelectorAll('.tnr-tab').forEach(t=>t.classList.remove('tnr-active'));
      root.querySelectorAll('.tnr-panel').forEach(p=>p.classList.remove('tnr-active'));
      btn.classList.add('tnr-active');
      var panel = document.getElementById('tnr-tab-'+btn.dataset.tnrtab);
      if(panel) panel.classList.add('tnr-active');
    });
  });

  // Sortable tables
  root.querySelectorAll('.tnr-sortable th[data-sort]').forEach(function(th){
    th.style.cursor='pointer';
    th.addEventListener('click', function(){
      var table=th.closest('table'), tbody=table.tBodies[0], idx=Array.from(th.parentNode.children).indexOf(th);
      var num=th.dataset.sort==='num', dir=th.__asc=!th.__asc?1:-1;
      Array.from(tbody.rows).sort(function(a,b){
        var x=a.cells[idx].textContent.trim(), y=b.cells[idx].textContent.trim();
        if(num){ x=parseFloat(x)||0; y=parseFloat(y)||0; return (x-y)*dir; }
        return x.localeCompare(y)*dir;
      }).forEach(function(r){tbody.appendChild(r);});
    });
  });

  // flatpickr (human-readable display) — flatpickr is already loaded app-wide
  if(window.flatpickr){
    root.querySelectorAll('.tnr-date').forEach(function(el){
      flatpickr(el,{altInput:true,altFormat:'F j, Y',dateFormat:'Y-m-d'});
    });
  }

  // Trend chart — simple dual-series bars, no dependency
  var data=window.__tnrTrend||[], svg=document.getElementById('tnr-trend');
  if(svg && data.length){
    var W=600,H=160,pad=20,n=data.length,bw=(W-pad*2)/n;
    var maxT=Math.max.apply(null,data.map(d=>d.Tournaments).concat([1]));
    var maxP=Math.max.apply(null,data.map(d=>d.Participants).concat([1]));
    var html='';
    data.forEach(function(d,i){
      var x=pad+i*bw;
      var th=(H-pad)*d.Tournaments/maxT, ph=(H-pad)*d.Participants/maxP;
      html+='<rect x="'+(x+2)+'" y="'+(H-th)+'" width="'+(bw/2-3)+'" height="'+th+'" class="tnr-bar-t"></rect>';
      html+='<rect x="'+(x+bw/2)+'" y="'+(H-ph)+'" width="'+(bw/2-3)+'" height="'+ph+'" class="tnr-bar-p"></rect>';
    });
    svg.innerHTML=html;
  }
})();
</script>
```

- [ ] **Step 7: Verify the page renders all tabs**

Open `http://localhost:19080/orkui/Reports/tournaments/Kingdom&id=<KID>`. Expected: four tabs switch correctly, Fighters/Parks tables sort on header click, date pickers show human-readable dates, the trend chart draws bars. Check the console for JS errors.

- [ ] **Step 8: Commit**

```bash
git add orkui/template/default/Reports_tournaments.tpl
git commit -m "Enhancement: Reports_tournaments.tpl four-tab report UI"
```

---

## Task 11: Report CSS (incl. dark-mode pass)

**Files:**
- Modify: `orkui/template/default/style/reports.css`

- [ ] **Step 1: Append `tnr-` styles using theme variables**

Use CSS variables already used by the dark theme (grep existing `--ork-` vars in `reports.css` / `orkui.css` first and reuse them). Append:

```css
/* ===== Tournament Report ===== */
.tnr-report { max-width: 1100px; margin: 0 auto; }
.tnr-head { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; }
.tnr-title { background:transparent; border:none; padding:0; border-radius:0; text-shadow:none; } /* reset global h2 box */
.tnr-filter { display:flex; gap:8px; align-items:center; }
.tnr-tabs { display:flex; gap:4px; border-bottom:1px solid var(--ork-border,#ccc); margin:12px 0; }
.tnr-tab { background:transparent; border:none; padding:8px 14px; cursor:pointer; color:var(--ork-text-muted,#555); font-weight:600; }
.tnr-tab.tnr-active { color:var(--ork-text,#1a202c); border-bottom:2px solid var(--ork-accent,#276749); }
.tnr-panel { display:none; } .tnr-panel.tnr-active { display:block; }
.tnr-stat-row { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
.tnr-stat { background:var(--ork-card-bg,#f7fafc); border:1px solid var(--ork-border,#e2e8f0); border-radius:8px; padding:14px; text-align:center; }
.tnr-stat-num { font-size:26px; font-weight:800; color:var(--ork-text,#1a202c); }
.tnr-stat-lbl { font-size:12px; color:var(--ork-text-muted,#718096); }
.tnr-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin:14px 0; }
.tnr-card { background:var(--ork-card-bg,#fff); border:1px solid var(--ork-border,#e2e8f0); border-radius:8px; padding:14px; }
.tnr-h { background:transparent; border:none; padding:0 0 8px; text-shadow:none; border-radius:0; font-size:14px; color:var(--ork-text,#2d3748); }
.tnr-bar-row { display:flex; justify-content:space-between; padding:3px 0; color:var(--ork-text,#2d3748); }
.tnr-table { width:100%; border-collapse:collapse; }
.tnr-table th, .tnr-table td { padding:7px 9px; border-bottom:1px solid var(--ork-border,#edf2f7); text-align:left; color:var(--ork-text,#2d3748); }
.tnr-table th { font-size:12px; text-transform:uppercase; color:var(--ork-text-muted,#718096); }
.tnr-empty { padding:24px; text-align:center; color:var(--ork-text-muted,#a0aec0); }
.tnr-btn { background:var(--ork-accent,#276749); color:#fff; border:none; border-radius:5px; padding:6px 12px; font-weight:600; cursor:pointer; text-decoration:none; }
.tnr-btn-ghost { background:transparent; color:var(--ork-text-muted,#4a5568); border:1px solid var(--ork-border,#cbd5e0); }
.tnr-cand { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:12px; border:1px solid var(--ork-border,#e2e8f0); border-radius:8px; margin-bottom:8px; background:var(--ork-card-bg,#fff); }
.tnr-cand-ootw { border-color:var(--ork-accent,#276749); box-shadow:0 0 0 1px var(--ork-accent,#276749) inset; }
.tnr-cand-name { font-weight:700; color:var(--ork-text,#1a202c); margin-right:8px; }
.tnr-cand-wl { font-size:12px; color:var(--ork-text-muted,#718096); }
.tnr-cand-note { font-size:13px; color:var(--ork-text-muted,#4a5568); margin-top:4px; }
.tnr-pill { display:inline-block; background:var(--ork-accent,#276749); color:#fff; font-size:11px; padding:2px 8px; border-radius:10px; }
.tnr-trend { width:100%; height:160px; }
.tnr-bar-t { fill:var(--ork-accent,#276749); } .tnr-bar-p { fill:var(--ork-accent-2,#90cdf4); }
@media (max-width:700px){ .tnr-stat-row{grid-template-columns:repeat(2,1fr);} .tnr-grid2{grid-template-columns:1fr;} .tnr-table{display:block;overflow-x:auto;} }
```

- [ ] **Step 2: Dark-mode walkthrough (per project checklist)**

Toggle dark mode and open the report. Verify each surface: stat cards, tab labels (muted but legible), table text, ghost "All time" button text contrast, candidate cards, the OotW pill, flatpickr inputs, the trend bars. Fix any hard-coded light fallback that fails by replacing with the matching `--ork-` variable. Confirm no global h1–h6 gray box leaks onto `.tnr-title`/`.tnr-h`.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/default/style/reports.css
git commit -m "Enhancement: Tournament Report CSS, dark-mode compatible"
```

---

## Task 12: Entry links in Kingdom & Park reports grids

**Files:**
- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl` (~line 678, in the awards/competition `<ul>`)
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl` (equivalent reports grid)

- [ ] **Step 1: Add the Kingdom link**

After the Beltline Explorer `<li>` (~line 678):

```php
							<li><a href="<?= UIR ?>Reports/tournaments/Kingdom&id=<?= $kingdom_id ?>"><i class="fas fa-trophy"></i> Tournament Report</a></li>
```

- [ ] **Step 2: Add the Park link**

Find the equivalent reports `<ul>` in `Parknew_index.tpl` (grep `Reports/` in that file) and add:

```php
							<li><a href="<?= UIR ?>Reports/tournaments/Park&id=<?= $park_id ?>"><i class="fas fa-trophy"></i> Tournament Report</a></li>
```

(Confirm the park id variable name in that template — grep `\$park_id` / `ParkId`. Use whatever the surrounding report links use.)

- [ ] **Step 3: Verify links navigate correctly**

Open a Kingdom profile → Reports tab → click "Tournament Report"; repeat for a Park profile. Expected: each lands on the scoped report with data.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/Kingdomnew_index.tpl orkui/template/revised-frontend/Parknew_index.tpl
git commit -m "Enhancement: link Tournament Report from Kingdom & Park reports grids"
```

---

## Final Verification

- [ ] Open the Kingdom report; confirm Overview totals match a direct DB count, Fighters table is populated and sortable, Awards shows candidates with working Recommend deep-links, Parks compares parks.
- [ ] Open a Park report; confirm the Parks tab is absent and the other three are correct and scoped to the park.
- [ ] Apply a date range; confirm every tab's numbers shrink consistently.
- [ ] Toggle dark mode; confirm every surface is legible (no gray heading boxes, no invisible ghost-button text).
- [ ] Confirm GhettoCache doesn't serve stale data after adding a participant (the report caches 1800s; if testing live edits, flush memcache per project convention).
