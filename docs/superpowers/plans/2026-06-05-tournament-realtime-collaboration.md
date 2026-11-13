# Tournament Real-Time Collaboration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let multiple reeves edit the same tournament bracket concurrently and see each other's match results / participant-status changes within ~1 second, with optimistic local edits that reconcile against the server.

**Architecture:** A monotonic per-tournament `seq` cursor lives in MariaDB (`ork_tournament_seq`) and is mirrored to Memcached. Every synced mutation appends a row to an append-only change-log (`ork_tournament_event`) and bumps the cursor, **inside the existing DB transaction**. Clients poll a cheap Memcached-backed heartbeat (`/seq`); when it advances they fetch ordered deltas (`/changes?since=N`). Local edits apply optimistically (pending state), carry a client `action_id` for echo-dedup, and reconcile against the server's authoritative refetch.

**Prototype granularity note:** The delta unit in this prototype is **per-bracket**: each event carries a `bracket_id`, and the client responds by refetching *that one bracket's* matches (existing `TournamentAjax/bracket/{bid}/matches`) and re-rendering it with the existing `tnRenderBracketViz(bid)`. This is a deliberate simplification of the spec's per-*cell* SVG delta — same architecture and server authority, but it avoids instrumenting the advancement/walkover cascade engine and refactoring the renderer. Per-cell delta is a documented follow-up (see "Deferred").

**Tech Stack:** PHP 8 (Apache), MariaDB, Memcached (via `Ghettocache`), vanilla JS in `Tournametnew_index.tpl`. Service logic in `system/lib/ork3/class.Tournament.php`; AJAX in `controller.TournamentAjax.php`; model auto-forwards snake_case → CamelCase via `__call`.

**Verification convention:** This project has no PHP unit harness; endpoints are verified with a curl-auth session (single cookie jar; single-device sessions mean login + all calls go in one shell block) and the UI is verified in Chrome with two sessions. Migrations run via `docker exec -i ork3-php8-db mariadb -u root -proot ork < file.sql`. App container is `ork3-php8-app`; HTTP 500s surface in `docker logs ork3-php8-app`. App base URL: `http://localhost:19080/orkui/index.php?Route=...`.

---

## File Structure

- **Create** `db-migrations/2026-06-05-tournament-realtime-events.sql` — the two new tables.
- **Modify** `system/lib/ork3/class.GhettoCache.php` — add `counterGet()` / `counterSet()` raw helpers.
- **Modify** `system/lib/ork3/class.Tournament.php` — `tnEmitEvent()` + `tnActorName()` helpers; emit hooks in `PostMatchResult()` and `UpdateParticipantStatus()`; new `GetSeq()` + `GetChanges()` read methods.
- **Modify** `orkui/controller/controller.TournamentAjax.php` — `seq` + `changes` actions in `tournament()`; thread `ActionId` through `match()`; return `seq` in the match response.
- **Modify** `orkui/template/revised-frontend/Tournametnew_index.tpl` — `tnToast()` helper; reeve collaboration poll loop (new IIFE); optimistic apply + `ActionId` wiring in `tnSubmitQuickResult` and the status-update handler.

> **PHP edit hygiene (project rule):** Before any multi-line Edit on a `.php`/`.tpl` file, run `awk '/^\t/{c++} END{print c+0}' <file>` — `0` means clean, use Edit. If non-zero (tab-indented), run `php tools/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php <file>` on that one file first, then Edit. `class.Tournament.php` and the controller use spaces already (seen in reads); the `.tpl` mixes tabs — check it before editing.

---

## Task 1: Database migration — change-log + cursor tables

**Files:**
- Create: `db-migrations/2026-06-05-tournament-realtime-events.sql`

- [ ] **Step 1: Write the migration SQL**

```sql
-- Real-time collaboration: per-tournament change-log + monotonic cursor.
-- The log is NOT the system of record (ork_match / ork_participant are); it is a
-- replayable delta feed clients use to sync. Safe to prune after a tournament ends.

CREATE TABLE IF NOT EXISTS ork_tournament_seq (
  tournament_id INT NOT NULL,
  last_seq      BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (tournament_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ork_tournament_event (
  event_id      BIGINT NOT NULL AUTO_INCREMENT,
  tournament_id INT NOT NULL,
  bracket_id    INT NULL,
  seq           BIGINT NOT NULL,
  type          VARCHAR(32) NOT NULL,
  payload       TEXT NULL,
  actor_id      INT NULL,
  actor_name    VARCHAR(255) NULL,
  action_id     CHAR(36) NULL,
  created       DATETIME NOT NULL,
  PRIMARY KEY (event_id),
  KEY idx_trn_seq (tournament_id, seq),
  UNIQUE KEY uq_trn_action (tournament_id, action_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Apply the migration**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-06-05-tournament-realtime-events.sql
```
Expected: no output, exit code 0.

- [ ] **Step 3: Verify the tables exist with the right shape**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SHOW CREATE TABLE ork_tournament_event\G SHOW CREATE TABLE ork_tournament_seq\G"
```
Expected: both `CREATE TABLE` definitions print; `ork_tournament_event` shows `idx_trn_seq` and `uq_trn_action` keys.

> **Note on the UNIQUE key + NULL action_id:** MySQL/MariaDB allow multiple NULLs in a UNIQUE index, so events emitted without an `action_id` (e.g. server-initiated) won't collide. Only client-supplied `action_id`s are deduped.

- [ ] **Step 4: Commit**

```bash
git add db-migrations/2026-06-05-tournament-realtime-events.sql
git commit -m "Enhancement: add tournament real-time change-log + cursor tables"
```

---

## Task 2: GhettoCache counter helpers

**Files:**
- Modify: `system/lib/ork3/class.GhettoCache.php`

The existing `get()/cache()` pair is a memoization wrapper with inverted lifetime semantics — wrong shape for a simple integer counter. Add two thin, prefix-aware raw accessors.

- [ ] **Step 1: Add `counterGet()` and `counterSet()` after `bust()`**

Insert after the `bust()` method (currently ends at line 44):

```php
	/**
	 * Raw prefixed get for simple scalar counters (e.g. the tournament seq
	 * cursor). Returns the stored value, or false on miss. Distinct from get(),
	 * which is a memoization wrapper with inverted-lifetime bookkeeping.
	 */
	function counterGet($name) {
		return $this->memcache->get("{$this->prefix}.counter.$name");
	}

	/** Raw prefixed set for simple scalar counters, with explicit TTL seconds. */
	function counterSet($name, $value, $ttl) {
		$this->memcache->set("{$this->prefix}.counter.$name", $value, $ttl);
		return $value;
	}
```

- [ ] **Step 2: Lint the file**

Run: `docker exec -i ork3-php8-app php -l /var/www/ork.amtgard.com/system/lib/ork3/class.GhettoCache.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add system/lib/ork3/class.GhettoCache.php
git commit -m "Enhancement: add raw counter get/set helpers to GhettoCache"
```

---

## Task 3: Event-emit + actor helpers in class.Tournament.php

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php`

Add two private helpers. `tnEmitEvent()` allocates the next per-tournament `seq`, inserts the event row, and returns the new seq — **all assuming it runs inside a caller transaction.** `tnActorName()` resolves a display name for the actor.

- [ ] **Step 1: Add the helpers immediately before `GetVersion()` (currently at line 3767)**

```php
	/**
	 * Append a change-log event and advance the tournament's seq cursor.
	 * MUST be called inside a transaction owned by the caller — the cursor bump
	 * and the event insert commit atomically with the mutation they describe.
	 * Returns the new seq (int). Caller should refresh the Memcache mirror AFTER
	 * its COMMIT via tnPublishSeq().
	 *
	 * $payload is an associative array (json-encoded here). $action_id is the
	 * client-supplied UUID for echo-dedup/idempotency, or null.
	 */
	private function tnEmitEvent($tournament_id, $bracket_id, $type, array $payload, $actor_id = 0, $action_id = null) {
		$this->db->query(
			"INSERT INTO " . DB_PREFIX . "tournament_seq (tournament_id, last_seq)
			 VALUES (:tid, 1)
			 ON DUPLICATE KEY UPDATE last_seq = last_seq + 1",
			[':tid' => $tournament_id]
		);
		$sr  = $this->db->query("SELECT last_seq FROM " . DB_PREFIX . "tournament_seq WHERE tournament_id = :tid", [':tid' => $tournament_id]);
		$seq = ($sr && $sr->next()) ? (int)$sr->last_seq : 0;

		$actor_name = $actor_id > 0 ? $this->tnActorName($actor_id) : '';

		try {
			$this->db->query(
				"INSERT INTO " . DB_PREFIX . "tournament_event
				   (tournament_id, bracket_id, seq, type, payload, actor_id, actor_name, action_id, created)
				 VALUES (:tid, :bid, :seq, :type, :payload, :aid, :aname, :actionid, NOW())",
				[
					':tid'      => $tournament_id,
					':bid'      => $bracket_id > 0 ? $bracket_id : null,
					':seq'      => $seq,
					':type'     => $type,
					':payload'  => json_encode($payload) ?: '{}',
					':aid'      => $actor_id > 0 ? $actor_id : null,
					':aname'    => $actor_name,
					':actionid' => $action_id,
				]
			);
		} catch (\Throwable $e) {
			// Duplicate action_id (a retried request) — the cursor already moved;
			// peers will fetch and find nothing new for this seq, which is harmless.
		}
		return $seq;
	}

	/** Mirror the current cursor to Memcache (call AFTER COMMIT). Best-effort. */
	private function tnPublishSeq($tournament_id, $seq) {
		try {
			if (isset(Ork3::$Lib->ghettocache)) {
				Ork3::$Lib->ghettocache->counterSet('tnseq.' . (int)$tournament_id, (int)$seq, 60);
			}
		} catch (\Throwable $e) { /* cache is an accelerator; DB is source of truth */ }
	}

	/** Best-effort display name for an actor (mundane) id, for change-log toasts. */
	private function tnActorName($mundane_id) {
		$r = $this->db->query("SELECT persona, mundane FROM " . DB_PREFIX . "mundane WHERE mundane_id = " . (int)$mundane_id . " LIMIT 1");
		if ($r && $r->next()) {
			$p = trim((string)$r->persona);
			if ($p !== '') return $p;
			return trim((string)$r->mundane);
		}
		return '';
	}
```

- [ ] **Step 2: Verify the mundane name columns exist (the helper assumes `persona` / `mundane`)**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SHOW COLUMNS FROM ork_mundane LIKE 'persona'; SHOW COLUMNS FROM ork_mundane LIKE 'mundane';"
```
Expected: both columns listed. If a column name differs, adjust `tnActorName()` accordingly before continuing.

- [ ] **Step 3: Lint**

Run: `docker exec -i ork3-php8-app php -l /var/www/ork.amtgard.com/system/lib/ork3/class.Tournament.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.Tournament.php
git commit -m "Enhancement: add tournament change-log emit + actor helpers"
```

---

## Task 4: Emit on match result + return seq

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php` (`PostMatchResult`, lines ~1792–1891)

- [ ] **Step 1: Resolve the actor and read `ActionId` at the top of `PostMatchResult`**

After the existing line `$score = substr(trim($request['Score'] ?? ''), 0, 64);` (line 1798), add:

```php
		$action_id = substr(trim($request['ActionId'] ?? ''), 0, 36);
		$actor_id  = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
```

- [ ] **Step 2: Emit the event inside the transaction, just before COMMIT**

The transaction commits at line 1884 (`$this->db->query('COMMIT');`). Immediately **before** that line, add:

```php
			$seq = $this->tnEmitEvent($tournament_id, $bracket_id, 'match_result', [
				'match_id' => $match_id,
				'result'   => $result,
				'score'    => $score,
			], $actor_id, $action_id !== '' ? $action_id : null);
```

- [ ] **Step 3: Publish the cursor to cache after COMMIT and return the seq**

Replace the existing tail of the method:

```php
			$this->db->query('COMMIT');
		} catch (\Throwable $e) {
			$this->db->query('ROLLBACK');
			throw $e;
		}

		return Success($match_id);
```

with:

```php
			$this->db->query('COMMIT');
		} catch (\Throwable $e) {
			$this->db->query('ROLLBACK');
			throw $e;
		}

		$this->tnPublishSeq($tournament_id, $seq);
		return Success(['MatchId' => $match_id, 'Seq' => $seq]);
```

> The previous return was `Success($match_id)` (a scalar Detail). The controller (Task 6) is updated to read `Detail['MatchId']` / `Detail['Seq']`.

- [ ] **Step 4: Lint**

Run: `docker exec -i ork3-php8-app php -l /var/www/ork.amtgard.com/system/lib/ork3/class.Tournament.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.Tournament.php
git commit -m "Enhancement: emit match_result change-log event + return seq"
```

---

## Task 5: Emit on participant status change

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php` (`UpdateParticipantStatus`, lines ~3335–3405)

- [ ] **Step 1: Resolve actor + ActionId near the top of the method**

After `if (!valid_id($participant_id)) return InvalidParameter('ParticipantId required');` (line 3342), add:

```php
		$action_id = substr(trim($request['ActionId'] ?? ''), 0, 36);
		$actor_id  = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
```

- [ ] **Step 2: Emit inside the transaction, just before COMMIT**

The COMMIT is at line 3397. Immediately before it, add:

```php
			$seq = $this->tnEmitEvent($b_tid, $bracket_id, 'participant_status', [
				'participant_id' => $participant_id,
				'status'         => $status,
				'mode'           => $mode,
			], $actor_id, $action_id !== '' ? $action_id : null);
```

- [ ] **Step 3: Publish cursor + return seq**

Replace the method tail:

```php
			$this->db->query('COMMIT');
		} catch (\Throwable $e) {
			$this->db->query('ROLLBACK');
			throw $e;
		}

		$this->bustTournamentReportCache();
		return Success(['ParticipantId' => $participant_id, 'Status' => $status, 'Mode' => $mode]);
```

with:

```php
			$this->db->query('COMMIT');
		} catch (\Throwable $e) {
			$this->db->query('ROLLBACK');
			throw $e;
		}

		$this->bustTournamentReportCache();
		$this->tnPublishSeq($b_tid, $seq);
		return Success(['ParticipantId' => $participant_id, 'Status' => $status, 'Mode' => $mode, 'Seq' => $seq]);
```

- [ ] **Step 4: Lint**

Run: `docker exec -i ork3-php8-app php -l /var/www/ork.amtgard.com/system/lib/ork3/class.Tournament.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.Tournament.php
git commit -m "Enhancement: emit participant_status change-log event"
```

---

## Task 6: Read endpoints — GetSeq + GetChanges (service) and wire ActionId/seq in controller

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php` (new public methods)
- Modify: `orkui/controller/controller.TournamentAjax.php` (`tournament()` + `match()`)

The model auto-forwards `get_seq` → `GetSeq` and `get_changes` → `GetChanges` via `__call`, so no `model.Tournament.php` edit is needed (verified by the existing `get_version` → `GetVersion` mapping). Confirm in Step 5.

- [ ] **Step 1: Add `GetSeq()` and `GetChanges()` to class.Tournament.php (after `GetVersion`, ~line 3794)**

```php
	/**
	 * PUBLIC — no auth. Cheap heartbeat: the current per-tournament seq cursor.
	 * Reads Memcache first; on miss, reads ork_tournament_seq and repopulates.
	 * Param: TournamentId. Returns Success(['Seq' => int]).
	 */
	public function GetSeq($request) {
		$tournament_id = (int)($request['TournamentId'] ?? 0);
		if (!valid_id($tournament_id)) return InvalidParameter('TournamentId required');

		$seq = Ork3::$Lib->ghettocache->counterGet('tnseq.' . $tournament_id);
		if ($seq === false || $seq === null) {
			$sr  = $this->db->query("SELECT last_seq FROM " . DB_PREFIX . "tournament_seq WHERE tournament_id = $tournament_id");
			$seq = ($sr && $sr->next()) ? (int)$sr->last_seq : 0;
			Ork3::$Lib->ghettocache->counterSet('tnseq.' . $tournament_id, $seq, 60);
		}
		return Success(['Seq' => (int)$seq]);
	}

	/**
	 * PUBLIC — no auth (read-only delta feed). Ordered events with seq > Since.
	 * If Since is ahead of the high-water mark, or behind the oldest retained
	 * event, returns ['Resync' => true] so the client falls back to a full refresh.
	 * Params: TournamentId, Since. Returns Success(['Events' => [...], 'Seq' => int]).
	 */
	public function GetChanges($request) {
		$tournament_id = (int)($request['TournamentId'] ?? 0);
		$since         = (int)($request['Since'] ?? 0);
		if (!valid_id($tournament_id)) return InvalidParameter('TournamentId required');

		$sr   = $this->db->query("SELECT last_seq FROM " . DB_PREFIX . "tournament_seq WHERE tournament_id = $tournament_id");
		$high = ($sr && $sr->next()) ? (int)$sr->last_seq : 0;

		if ($since > $high) return Success(['Resync' => true, 'Seq' => $high]);

		$mr     = $this->db->query("SELECT MIN(seq) AS m FROM " . DB_PREFIX . "tournament_event WHERE tournament_id = $tournament_id");
		$minSeq = ($mr && $mr->next() && $mr->m !== null) ? (int)$mr->m : 0;
		if ($since > 0 && $minSeq > 0 && $since < $minSeq - 1) {
			return Success(['Resync' => true, 'Seq' => $high]);
		}

		$rows = $this->db->query(
			"SELECT seq, bracket_id, type, payload, actor_id, actor_name, action_id
			   FROM " . DB_PREFIX . "tournament_event
			  WHERE tournament_id = $tournament_id AND seq > $since
			  ORDER BY seq ASC LIMIT 500"
		);
		$events = [];
		if ($rows) {
			while ($rows->next()) {
				$events[] = [
					'Seq'       => (int)$rows->seq,
					'BracketId' => $rows->bracket_id !== null ? (int)$rows->bracket_id : null,
					'Type'      => (string)$rows->type,
					'Payload'   => json_decode((string)$rows->payload, true),
					'ActorId'   => $rows->actor_id !== null ? (int)$rows->actor_id : null,
					'ActorName' => (string)$rows->actor_name,
					'ActionId'  => (string)$rows->action_id,
				];
			}
		}
		return Success(['Events' => $events, 'Seq' => $high]);
	}
```

- [ ] **Step 2: Add `seq` + `changes` actions to controller `tournament()` (in the public block, after the `version` action, line ~39)**

Inside the `if ($action === 'version') { ... }` public block, add two more `elseif` branches **before** the closing of the public section (before line 41's `} elseif ($action === 'brackets')` — i.e. add them as additional public branches):

```php
		} elseif ($action === 'seq') {
			$r = $this->Tournament->get_seq(['TournamentId' => $tournament_id]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0, 'seq' => (int)($r['Detail']['Seq'] ?? 0)])
				: $this->modelError($r);
			exit;

		} elseif ($action === 'changes') {
			$since = (int)preg_replace('/[^0-9]/', '', $_GET['since'] ?? '0');
			$r = $this->Tournament->get_changes(['TournamentId' => $tournament_id, 'Since' => $since]);
			if ($r['Status'] != 0) { echo $this->modelError($r); exit; }
			echo json_encode([
				'status' => 0,
				'resync' => !empty($r['Detail']['Resync']),
				'seq'    => (int)($r['Detail']['Seq'] ?? 0),
				'events' => $r['Detail']['Events'] ?? [],
			]);
			exit;
```

- [ ] **Step 3: Thread `ActionId` through `match()` and return `seq`**

In `controller.TournamentAjax.php::match()`, after `$bouts = trim($_POST['Bouts'] ?? '[]');` (line 815) add:

```php
		$actionId = trim($_POST['ActionId'] ?? '');
```

Then in the same method, add `'ActionId' => $actionId,` to the `post_match_result([...])` array (after the `'Bouts' => $bouts,` line, ~828), and change the success response (line 830-832) from:

```php
		echo ($r['Status'] == 0)
			? json_encode(['status' => 0, 'matchId' => $match_id])
			: $this->modelError($r);
```

to:

```php
		echo ($r['Status'] == 0)
			? json_encode(['status' => 0, 'matchId' => $match_id, 'seq' => (int)($r['Detail']['Seq'] ?? 0)])
			: $this->modelError($r);
```

- [ ] **Step 4: Thread `ActionId` into the participant-status controller action**

Find the controller action that calls `update_participant_status` (search): `grep -n "update_participant_status" orkui/controller/controller.TournamentAjax.php`. In that handler, read `$actionId = trim($_POST['ActionId'] ?? '');` and add `'ActionId' => $actionId,` to the request array, and add `'seq' => (int)($r['Detail']['Seq'] ?? 0)` to its success JSON (mirroring Step 3).

- [ ] **Step 5: Lint both files + confirm model auto-forward**

Run:
```bash
docker exec -i ork3-php8-app php -l /var/www/ork.amtgard.com/system/lib/ork3/class.Tournament.php
docker exec -i ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/controller/controller.TournamentAjax.php
grep -n "function __call\|get_version" orkui/model/model.Tournament.php
```
Expected: both `No syntax errors detected`; the grep confirms either a `__call` magic method or an explicit `get_version` passthrough (proving `get_seq`/`get_changes` will resolve). If `model.Tournament.php` uses **explicit** passthroughs (no `__call`), add `get_seq`/`get_changes` passthroughs mirroring `get_version`.

- [ ] **Step 6: Verify endpoints with a curl-auth session (single block)**

Pick a real tournament id with at least one active bracket (`docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SELECT t.tournament_id, b.bracket_id, b.status FROM ork_tournament t JOIN ork_bracket b ON b.tournament_id=t.tournament_id WHERE b.status='active' LIMIT 5;"`). Then:

```bash
TID=<id>; BASE='http://localhost:19080/orkui/index.php?Route='
# heartbeat (public)
curl -s "${BASE}TournamentAjax/tournament/${TID}/seq"
echo
# delta from 0
curl -s "${BASE}TournamentAjax/tournament/${TID}/changes&since=0"
echo
# resync path (impossible-future cursor)
curl -s "${BASE}TournamentAjax/tournament/${TID}/changes&since=999999"
```
Expected: `seq` returns `{"status":0,"seq":N}`; `changes&since=0` returns `{"status":0,"resync":false,"seq":N,"events":[...]}`; `changes&since=999999` returns `"resync":true`.

> **Routing note (project rule):** UIR already ends in `?Route=`; append further query params with `&` (e.g. `&since=`), never a second `?`, or `$_GET['since']` will be empty.

- [ ] **Step 7: Verify a real write advances the cursor + appends an event**

In one curl-auth block: log in (`Login/login` with `username`/`password`), GET `/seq` (note N), POST a match result to `TournamentAjax/match/{matchId}/{TID}` with `Result=1-wins&ActionId=test-uuid-1`, GET `/seq` again (expect N+1), GET `/changes&since=N` (expect one `match_result` event with `actionId=test-uuid-1` and the right `bracketId`).

Expected: cursor increments by exactly 1; event present with correct payload + action id. (Use a disposable match, or reset it afterward via `TournamentAjax/match/{matchId}/{TID}/reset`.)

- [ ] **Step 8: Commit**

```bash
git add system/lib/ork3/class.Tournament.php orkui/controller/controller.TournamentAjax.php
git commit -m "Enhancement: tournament seq heartbeat + changes delta endpoints"
```

---

## Task 7: Client — toast helper + collaboration state

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl`

- [ ] **Step 1: Check tab/space cleanliness before editing the .tpl**

Run: `awk '/^\t/{c++} END{print c+0}' orkui/template/revised-frontend/Tournametnew_index.tpl`
If non-zero, the file is tab-indented; the JS edits below are inside `<script>` blocks (the file is plain-PHP `.tpl`), so prefer the Python `replace` fallback for these JS insertions to avoid tab/space byte-mismatch:
`python3 -c "import pathlib; p=pathlib.Path('orkui/template/revised-frontend/Tournametnew_index.tpl'); t=p.read_text(); print('found:', 'NEEDLE' in t); p.write_text(t.replace(OLD, NEW, 1))"`

- [ ] **Step 2: Add a reusable `tnToast()` helper (dark-mode compatible) near the spectator IIFE**

Insert a new `<script>` block (or append into the existing feature script) immediately before the `// Feature 1 — Spectator Mode` block (line ~13531):

```html
<style>
.tn-toast-wrap { position: fixed; left: 50%; bottom: 20px; transform: translateX(-50%); z-index: 9999; display: flex; flex-direction: column; gap: 8px; pointer-events: none; }
.tn-toast { background: #1f2937; color: #f9fafb; border: 1px solid #374151; border-radius: 8px; padding: 9px 14px; font-size: 13px; box-shadow: 0 4px 14px rgba(0,0,0,.25); opacity: 0; transform: translateY(8px); transition: opacity .18s, transform .18s; max-width: 88vw; }
.tn-toast.tn-toast-show { opacity: 1; transform: translateY(0); }
@media (prefers-color-scheme: dark) { .tn-toast { background: #e5e7eb; color: #111827; border-color: #d1d5db; } }
</style>
<script>
window.tnToast = function(msg, ms) {
	var wrap = document.getElementById('tn-toast-wrap');
	if (!wrap) { wrap = document.createElement('div'); wrap.id = 'tn-toast-wrap'; wrap.className = 'tn-toast-wrap'; document.body.appendChild(wrap); }
	var t = document.createElement('div');
	t.className = 'tn-toast';
	t.textContent = msg;
	wrap.appendChild(t);
	requestAnimationFrame(function() { t.classList.add('tn-toast-show'); });
	setTimeout(function() {
		t.classList.remove('tn-toast-show');
		setTimeout(function() { if (t.parentNode) t.parentNode.removeChild(t); }, 220);
	}, ms || 3200);
};

// Registry of action_ids this client originated, with timestamps for pruning.
// Used to drop our own changes when they echo back in the delta feed.
window.TnOwnActions = window.TnOwnActions || {};
window.tnRegisterAction = function(id) { if (id) window.TnOwnActions[id] = Date.now(); };
window.tnIsOwnAction = function(id) {
	if (!id) return false;
	// prune entries older than 60s
	var now = Date.now();
	for (var k in window.TnOwnActions) { if (now - window.TnOwnActions[k] > 60000) delete window.TnOwnActions[k]; }
	return !!window.TnOwnActions[id];
};
window.tnNewActionId = function() {
	return 'a-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
};
</script>
```

> **Dark-mode check (project rule):** the toast uses a `prefers-color-scheme: dark` override. Before declaring done, confirm in Chrome with the OS in dark mode that the toast text is legible.

- [ ] **Step 3: Verify the page still loads**

Run: `curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:19080/orkui/index.php?Route=Tournament/index/<TID>"`
Expected: `200`. Also `docker logs --tail 20 ork3-php8-app` shows no new PHP error.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: tnToast helper + own-action registry for live collab"
```

---

## Task 8: Client — reeve collaboration poll loop

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl`

A new IIFE for logged-in reeves (mirrors the spectator loop's structure but uses the seq cursor and refetches only changed brackets). It reuses the existing per-bracket refetch shape from `tnSubmitQuickResult`.

- [ ] **Step 1: Add the collaboration loop after the spectator IIFE (after line ~13650)**

```html
<script>
// ============================================================
// Reeve live collaboration — seq heartbeat + per-bracket delta sync
// ============================================================
(function() {
	// Reeves only; spectators already have their own version loop.
	if (TnConfig.spectator || !TnConfig.loggedIn) return;
	if (!(TnConfig.canManage || TnConfig.isOrganizerReeve || TnConfig.isBracketRunner)) return;

	var clientSeq = null;
	var timer = null;
	var paused = false;
	var nudgeUntil = 0;

	function anyActive() {
		var bd = TnConfig.bracketData || {};
		for (var k in bd) { if (bd[k] && bd[k].Bracket && bd[k].Bracket.Status === 'active') return true; }
		return false;
	}

	// Refetch a single bracket's matches + meta, then re-render if it's on screen.
	function refetchBracket(bid) {
		if (!bid || !TnConfig.bracketData[bid]) return Promise.resolve();
		var tid = TnConfig.tournamentId;
		return Promise.all([
			fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/matches').then(function(r) { return r.json(); }),
			fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tid + '/brackets').then(function(r) { return r.json(); })
		]).then(function(res) {
			var md = res[0], bd = res[1];
			if (md && md.status === 0) TnConfig.bracketData[bid].Matches = md.matches;
			if (bd && bd.status === 0 && bd.brackets) {
				var br = bd.brackets.find(function(b) { return parseInt(b.BracketId) === parseInt(bid); });
				if (br) TnConfig.bracketData[bid].Bracket = br;
			}
			var sel = document.getElementById('tn-bv-bracket-select');
			var curBid = sel ? parseInt(sel.value) : 0;
			if (parseInt(bid) === curBid && typeof tnRenderBracketViz === 'function') tnRenderBracketViz(bid);
			if (typeof tnRenderLeaderboard === 'function') tnRenderLeaderboard();
		}).catch(function(err) { console.warn('[tn-collab] bracket refetch failed', err); if (window.tnShowStaleWarning) tnShowStaleWarning(); });
	}

	function fullResync() {
		if (typeof window.tnCollabRefreshAll === 'function') return window.tnCollabRefreshAll();
		// Fallback: reload if no shared refreshAll is exposed.
		window.location.reload();
		return Promise.resolve();
	}

	function applyDeltas(data) {
		if (data.resync) { return fullResync().then(function() { clientSeq = data.seq; }); }
		var events = data.events || [];
		var bracketsToRefetch = {};
		var lastActor = '';
		events.forEach(function(ev) {
			if (window.tnIsOwnAction && window.tnIsOwnAction(ev.ActionId)) return; // echo — already applied locally
			if (ev.BracketId) bracketsToRefetch[ev.BracketId] = true;
			if (ev.ActorName) lastActor = ev.ActorName;
		});
		var bids = Object.keys(bracketsToRefetch);
		clientSeq = data.seq;
		if (!bids.length) return Promise.resolve();
		return Promise.all(bids.map(function(b) { return refetchBracket(parseInt(b)); })).then(function() {
			if (window.tnToast) window.tnToast(lastActor ? ('Updated by ' + lastActor) : 'Bracket updated');
		});
	}

	function poll() {
		if (paused) return;
		fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/seq')
			.then(function(r) { return r.json(); })
			.then(function(d) {
				if (!d || d.status !== 0) return;
				if (clientSeq === null) { clientSeq = d.seq; return; }
				if (d.seq > clientSeq) {
					return fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/changes&since=' + clientSeq)
						.then(function(r) { return r.json(); })
						.then(function(cd) { if (cd && cd.status === 0) return applyDeltas(cd); });
				}
			})
			.catch(function(err) { console.warn('[tn-collab] seq poll failed', err); });
	}

	function schedule() {
		if (timer) clearTimeout(timer);
		if (paused) return;
		var interval = (Date.now() < nudgeUntil) ? 750 : (anyActive() ? 1000 : 5000);
		timer = setTimeout(function() { poll(); schedule(); }, interval);
	}

	// Other client code calls this after a local edit to poll faster briefly.
	window.tnCollabNudge = function() { nudgeUntil = Date.now() + 4000; schedule(); };
	// Allow optimistic handlers to keep our cursor ahead of our own writes.
	window.tnCollabBumpSeq = function(seq) { if (typeof seq === 'number' && seq > (clientSeq || 0)) clientSeq = seq; };

	document.addEventListener('visibilitychange', function() {
		if (document.hidden) { paused = true; if (timer) { clearTimeout(timer); timer = null; } }
		else { paused = false; poll(); schedule(); }
	});

	// Seed cursor, then start.
	fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/seq')
		.then(function(r) { return r.json(); })
		.then(function(d) { if (d && d.status === 0) clientSeq = d.seq; })
		.catch(function() {})
		.finally(function() { schedule(); });
})();
</script>
```

- [ ] **Step 2: Expose a shared full-refresh for the resync fallback**

The spectator IIFE defines a local `refreshAll()`. To reuse it for reeve resync, expose it: inside the spectator IIFE (line ~13568), change `function refreshAll() {` to `window.tnCollabRefreshAll = function() {` and update its single internal recursive reference if any (there is none — it is only called from `poll`/`visibilitychange` within that IIFE; update those two call sites to `window.tnCollabRefreshAll()`).

> If exposing the spectator helper proves awkward (spectator IIFE early-returns for reeves via `if (!TnConfig.spectator) return;`, so it never runs for reeves and `window.tnCollabRefreshAll` would be undefined), instead define `window.tnCollabRefreshAll` unconditionally in the collaboration IIFE by inlining the same all-brackets refetch loop used in `refetchBracket` across `Object.keys(TnConfig.bracketData)`. **Use this inline approach** — it avoids coupling to the spectator-only IIFE. Replace the `fullResync()` body accordingly:

```javascript
	function fullResync() {
		var bids = Object.keys(TnConfig.bracketData || {});
		if (!bids.length) return Promise.resolve();
		return Promise.all(bids.map(function(b) { return refetchBracket(parseInt(b)); }));
	}
```

(and delete the `window.tnCollabRefreshAll` indirection in `fullResync`).

- [ ] **Step 3: Verify page load + no JS errors**

In Chrome (per project rule: Chrome only for post-implementation verification), open `http://localhost:19080/orkui/index.php?Route=Tournament/index/<TID>` logged in as a reeve. Open console, confirm no errors and that a `/seq` request fires every ~1s while a bracket is active (Network tab). Confirm the request is cheap (no DB — verify indirectly: response is `{"status":0,"seq":N}` and fast).

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: reeve live collaboration poll loop (seq heartbeat + delta refetch)"
```

---

## Task 9: Client — optimistic apply on quick result

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl` (`tnSubmitQuickResult`, lines ~11834–11872)

- [ ] **Step 1: Generate + register an ActionId and apply a pending state before POST**

Replace the body of `tnSubmitQuickResult` from the FormData setup through the fetch call. New version:

```javascript
window.tnSubmitQuickResult = function(matchId, result, event) {
	if (event) event.stopPropagation();
	var btn = (event && event.currentTarget) ? event.currentTarget : ((event && event.target) ? event.target : null);
	if (btn) btn.disabled = true;
	var tid = TnConfig.tournamentId;

	var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
	if (window.tnRegisterAction) window.tnRegisterAction(actionId);

	// Optimistic: mark the match result locally + show a pending state immediately.
	var sel = document.getElementById('tn-bv-bracket-select');
	var bid = sel ? parseInt(sel.value) : 0;
	var prevResult = null, matchObj = null;
	if (bid && TnConfig.bracketData[bid]) {
		(TnConfig.bracketData[bid].Matches || []).forEach(function(m) {
			if (parseInt(m.MatchId) === parseInt(matchId)) { matchObj = m; }
		});
		if (matchObj) {
			prevResult = matchObj.Result;
			matchObj.Result = result;
			matchObj._pending = true;
			if (typeof tnRenderBracketViz === 'function') tnRenderBracketViz(bid);
		}
	}
	if (window.tnCollabNudge) window.tnCollabNudge();

	var fd = new FormData();
	fd.append('Result', result);
	fd.append('Score', '');
	fd.append('Bouts', '[]');
	fd.append('ActionId', actionId);

	fetch(TnConfig.uir + 'TournamentAjax/match/' + matchId + '/' + tid, {method:'POST', body:fd})
		.then(function(r) { return r.json(); })
		.then(function(d) {
			if (d && d.status === 0) {
				if (typeof d.seq === 'number' && window.tnCollabBumpSeq) window.tnCollabBumpSeq(d.seq);
				if (bid && TnConfig.bracketData[bid]) {
					Promise.all([
						fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/matches').then(function(r) { return r.json(); }),
						fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tid + '/brackets').then(function(r) { return r.json(); })
					]).then(function(results) {
						var md = results[0], bd = results[1];
						if (md && md.status === 0) TnConfig.bracketData[bid].Matches = md.matches;
						if (bd && bd.status === 0 && bd.brackets && TnConfig.bracketData[bid]) {
							var br = bd.brackets.find(function(b) { return parseInt(b.BracketId) === parseInt(bid); });
							if (br) TnConfig.bracketData[bid].Bracket = br;
						}
						tnRenderBracketViz(bid);
					}).catch(function(err) { console.warn('[tn] refresh failed', err); tnShowStaleWarning(); });
				}
			} else {
				// Reject — roll back the optimistic change and tell the reeve why.
				if (matchObj) { matchObj.Result = prevResult; delete matchObj._pending; if (typeof tnRenderBracketViz === 'function') tnRenderBracketViz(bid); }
				if (btn) btn.disabled = false;
				if (window.tnToast) window.tnToast((d && d.error) ? d.error : 'Result not saved — it may have just been recorded by someone else.');
			}
		})
		.catch(function() {
			if (matchObj) { matchObj.Result = prevResult; delete matchObj._pending; if (typeof tnRenderBracketViz === 'function') tnRenderBracketViz(bid); }
			if (btn) btn.disabled = false;
			if (window.tnToast) window.tnToast('Network error recording result.');
		});
};
```

- [ ] **Step 2: Add a faint "pending" visual cue (optional but recommended)**

In `tnRenderBracketViz`, where a match cell is built, the renderer already has the match object; add a class when `m._pending`. Search for where match cells get their CSS class in `tnRenderBracketViz` and add `(m._pending ? ' tn-match-pending' : '')` to the className string. Add CSS near the toast styles:

```css
.tn-match-pending { animation: tnPendingPulse 1s ease-in-out infinite; }
@keyframes tnPendingPulse { 0%,100% { opacity: 1; } 50% { opacity: .55; } }
```

> If locating the exact className concatenation in `tnRenderBracketViz` is non-trivial, skip the visual cue for the prototype (the result still appears instantly via the optimistic value); note it as deferred.

- [ ] **Step 3: Verify single-user still works**

In Chrome as a reeve: record a quick result. Expected: result shows immediately; after the refetch, advancement appears; no console errors; `/seq` cursor advanced (Network) but the client did **not** double-render from its own echo (no toast for your own action).

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: optimistic apply + ActionId on quick match result"
```

---

## Task 10: Client — wire ActionId into status update + score modal

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl`

- [ ] **Step 1: Add ActionId to the participant-status update fetch**

Find the status-update handler (the one near line 11806 that posts status and calls `window.tnRefreshAndRender`). Locate its FormData/body construction (search `UpdateParticipantStatus` or the status POST URL). Before the fetch: `var actionId = window.tnNewActionId ? window.tnNewActionId() : ''; if (window.tnRegisterAction) window.tnRegisterAction(actionId); if (window.tnCollabNudge) window.tnCollabNudge();` and append `ActionId=actionId` to the request body. On the success branch, if `d.seq` present call `window.tnCollabBumpSeq(d.seq)`. (Status changes keep their existing refresh path; this just dedups the echo + nudges peers.)

- [ ] **Step 2: Add ActionId to the full score-entry modal submit (if present)**

Search for the other place that POSTs to `TournamentAjax/match/` (the score/bouts modal, distinct from `tnSubmitQuickResult`). Apply the same three lines (generate, register, nudge) and append `ActionId`, and `tnCollabBumpSeq(d.seq)` on success. (Optimistic apply optional here; the dedup + nudge are the important part so the score modal doesn't double-render on its own echo.)

- [ ] **Step 3: Verify**

In Chrome: withdraw a participant and record a scored result via the modal. Expected: change applies; no self-echo toast; cursor advances.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: ActionId dedup + peer nudge for status + score-modal writes"
```

---

## Task 11: Two-client end-to-end verification

**Files:** none (verification only)

- [ ] **Step 1: Open two reeve sessions on the same bracket**

Use two browser profiles (or one normal + one incognito), both logged in as reeves with run-brackets authority on the same tournament, both viewing the same active bracket.

- [ ] **Step 2: Live read-sync**

In session A, record a quick result. Within ~1s, session B shows the result + advancement and a toast "Updated by {A's persona}". Session A shows **no** toast for its own change.

- [ ] **Step 3: Conflict path**

Have both A and B open the same undecided match. A records `1-wins`; immediately B records `2-wins`. Expected: A succeeds; B's optimistic value rolls back and B sees a toast like "Match result has already been recorded" and then the correct result (A's) after refetch. The DB shows A's result; cursor advanced once for the accepted write.

- [ ] **Step 4: Ironman burst**

On an Ironman/multi-ring bracket, have A and B each record results on different rings within the same ~2s. Expected: both land; both clients converge to the same state within ~1-2s; no lost result. Verify against DB: `docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SELECT match_id,result,score FROM ork_match WHERE bracket_id=<BID> AND result IS NOT NULL ORDER BY match_id;"`

- [ ] **Step 5: Resync path**

In session B, in the console set the cursor stale beyond retention is hard to force; instead simulate by manually calling the changes endpoint with `since` greater than current seq and confirm `resync:true` is returned (already covered in Task 6), and confirm the loop's `fullResync()` repaints without a reload. (If retention pruning isn't implemented yet, this is the only resync trigger.)

- [ ] **Step 6: Record results in this plan's checklist; capture a short GIF of the two-session sync for the PR.**

Use the Chrome GIF recorder; name it `tournament-realtime-collab.gif`.

---

## Deferred (explicitly out of this prototype)

- **Per-cell SVG delta** (spec's finer granularity). Prototype refetches the whole changed bracket and calls `tnRenderBracketViz(bid)`. Optimize later by emitting `advancement` events with `{match_id, slot, participant_id}` and patching only those cells.
- **Coarse lifecycle/config cursor bumps.** Bracket start/finalize/config edits currently use full-page reload flows, so collaborators catch up on their next interaction or reload. A follow-up can emit a `coarse` event on those writes so peers refresh without a reload.
- **Retention pruning job.** Add a prune of `ork_tournament_event` rows older than tournament-finalize + 7 days (one-off script or on-finalize), once the feature is validated.
- **Presence roster / hard locks.** Intentionally excluded per the brainstorm.

---

## Self-Review notes

- Spec §1 (data model) → Task 1. §1 write-path emit → Tasks 3–5. §2 (heartbeat + changes + resync) → Task 6. §3 (optimistic apply, echo-dedup, reconcile) → Tasks 7–10. §4 (conflict policy) → existing optimistic lock + Task 9 rollback/toast + Task 11 §3. §5 (edge cases: cold cache, resync, idempotency, atomicity) → Tasks 3/6. §6 (testing) → Tasks 6/11.
- Method/identifier consistency: service `GetSeq`/`GetChanges` ↔ model `get_seq`/`get_changes` ↔ controller actions `seq`/`changes`; client globals `tnNewActionId`/`tnRegisterAction`/`tnIsOwnAction`/`tnCollabNudge`/`tnCollabBumpSeq`/`tnToast` defined in Task 7–8 and consumed in Tasks 8–10.
- Granularity deviation from spec (per-bracket vs per-cell) is documented above and in the plan header; flagged to the user.
```
