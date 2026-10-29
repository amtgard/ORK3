# Points Bracket Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a new bracket method `points` to the tournament module — a non-elimination multi-round format where every participant scores per round (Fixed pips or Open decimal input), totaled across rounds for placement.

**Architecture:** New enum value on `ork_bracket.method`; new `ork_point_score` table for per-cell scores; no use of `ork_match`. Backend dispatch slots into existing `GenerateMatches()` and `GetStandings()`. AJAX gets two new endpoints (`savepointscore`, `addpointsround`). Run/score UI is a spreadsheet grid rendered inside `Tournametnew_index.tpl` alongside the existing bracket-card render paths.

**Tech Stack:** PHP 8 / Yapo ORM, MariaDB, vanilla JS in `.tpl` files (no build step), CSS inline per project convention.

**Spec:** `docs/superpowers/specs/2026-05-28-points-bracket-design.md`

---

## Project Conventions Reminders (read before every task)

- **DB writes**: `$this->db->query(...)` for raw; for Yapo `save()` calls, run `$DB->Clear()` before raw Execute/DataSet on the same model. Stale PDO bindings cause silent failures.
- **PHP multi-line edits**: never use the `Edit` tool. Use Python with `replace()` — tabs vs spaces in `Edit` will mismatch every time.
- **Dark mode**: every new CSS surface must be checked in dark mode. Modal `h1–h6` need explicit reset (`background:transparent; border:none; padding:0; border-radius:0;`).
- **No native `title` tooltips** — use `data-tip` for hovers.
- **Debug output → browser console** via `console.log` or `die(json_encode(...))` only. Never `error_log` / `print_r`.
- **JS guards on revised.js fragments**: never use `document.getElementById(...)` as an IIFE guard. Use `PnConfig.canEditAdmin` or similar config flag.
- **Date display**: not applicable to this plan (no dates).
- **Commit boundary**: each task ends with a commit. Stage files explicitly — never `git add -A` or `git add .`. Never stage `class.Authorization.php` (login-bypass hack lives there).
- **PR titles**: `Enhancement: {Title}` for this feature.

---

## File Inventory

| File                                                                                | Change            |
| ----------------------------------------------------------------------------------- | ----------------- |
| `db-migrations/2026-05-28-points-bracket.sql`                                       | **create** (Task 1) |
| `system/lib/ork3/class.Tournament.php`                                              | modify (Tasks 2–5) |
| `system/lib/ork3/class.TournamentReport.php`                                        | modify (Task 6)   |
| `orkui/controller/controller.TournamentAjax.php`                                    | modify (Task 7)   |
| `orkui/template/revised-frontend/Tournametnew_index.tpl`                            | modify (Tasks 8–13) |

The single mega-template `Tournametnew_index.tpl` (11,500 lines) holds the add-bracket modal, bracket card rendering, run UI, standings UI, and all bracket JS. New code is added alongside existing branches keyed off `$b['Method']`.

---

## Task 1 — DB migration: enum + columns + ork_point_score table

**Files:**
- Create: `db-migrations/2026-05-28-points-bracket.sql`

- [ ] **Step 1.1: Write the migration file**

Create `db-migrations/2026-05-28-points-bracket.sql`:

```sql
-- Points bracket method: adds new method enum value, per-bracket config columns,
-- and the ork_point_score cell table.
ALTER TABLE ork_bracket
  MODIFY method enum('single','double','swiss','round-robin','ironman','score','points')
    NOT NULL DEFAULT 'single';

ALTER TABLE ork_bracket
  ADD COLUMN point_rounds int(11) NULL AFTER best_of,
  ADD COLUMN point_mode   enum('fixed','open') NULL AFTER point_rounds,
  ADD COLUMN point_scale  varchar(120) NULL AFTER point_mode;

CREATE TABLE ork_point_score (
  point_score_id  int(11)         NOT NULL AUTO_INCREMENT,
  bracket_id      int(11)         NOT NULL,
  participant_id  int(11)         NOT NULL,
  round           int(11)         NOT NULL,
  points          decimal(8,2)        NULL,
  scored_at       datetime            NULL,
  scored_by       int(11)             NULL,
  PRIMARY KEY (point_score_id),
  UNIQUE KEY uq_cell (bracket_id, participant_id, round),
  KEY idx_bracket (bracket_id),
  KEY idx_participant (participant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 1.2: Apply the migration**

```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-05-28-points-bracket.sql
```

Expected: no error output.

- [ ] **Step 1.3: Verify schema**

```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "
  SHOW COLUMNS FROM ork_bracket WHERE Field IN ('method','point_rounds','point_mode','point_scale');
  SHOW CREATE TABLE ork_point_score \\G
"
```

Expected:
- `method` enum value list contains `'points'` (last value).
- `point_rounds`, `point_mode`, `point_scale` exist with `NULL` allowed.
- `ork_point_score` table exists with `uq_cell` unique key on `(bracket_id, participant_id, round)`.

- [ ] **Step 1.4: Commit**

```bash
git add db-migrations/2026-05-28-points-bracket.sql
git commit -m "Enhancement: Points-bracket DB migration

Adds 'points' to bracket.method enum, three nullable config columns on
ork_bracket (point_rounds, point_mode, point_scale), and ork_point_score
cell table keyed (bracket_id, participant_id, round).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 2 — Backend: AddBracket + UpdateBracket accept new fields

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php` (around lines 192–290)

- [ ] **Step 2.1: Read AddBracket and UpdateBracket**

Use Read to view `system/lib/ork3/class.Tournament.php` lines 192–340 to confirm the exact code shape (specifically the `else` branch of `AddBracket` that creates a fresh bracket, and the full `UpdateBracket` method).

- [ ] **Step 2.2: Add a private validator**

Add this private method to `class.Tournament` (place it just after `normalize_best_of()` near line 287). Use Python to insert (per project rule on PHP multi-line edits):

```php
	/**
	 * Validate Points-bracket config. Returns null on success, an error string on failure.
	 * @param array $r Request payload (PointRounds, PointMode, PointScale).
	 * @param bool $allowScaleAndMode If false, callers (mid-run edits) skip mode/scale
	 *                                validation when there is no submitted change.
	 */
	private function validate_points_config($r, $allowScaleAndMode = true) {
		$rounds = (int)($r['PointRounds'] ?? 0);
		if ($rounds < 1 || $rounds > 32) return 'PointRounds must be 1–32.';
		if ($allowScaleAndMode) {
			$mode = $r['PointMode'] ?? '';
			if ($mode !== 'fixed' && $mode !== 'open') return 'PointMode must be fixed or open.';
			if ($mode === 'fixed') {
				$raw = trim((string)($r['PointScale'] ?? ''));
				if ($raw === '') return 'PointScale CSV required for fixed mode.';
				$parts = array_map('trim', explode(',', $raw));
				if (count($parts) < 1 || count($parts) > 16) return 'PointScale must have 1–16 values.';
				$seen = [];
				foreach ($parts as $p) {
					if (!preg_match('/^\d+(\.\d{1,2})?$/', $p)) return "PointScale value \"$p\" invalid (non-neg decimal, ≤2 dp).";
					$f = (float)$p;
					if ($f < 0 || $f > 999.99) return "PointScale value \"$p\" out of range (0–999.99).";
					$key = number_format($f, 2, '.', '');
					if (isset($seen[$key])) return "PointScale has duplicate value \"$p\".";
					$seen[$key] = true;
				}
			}
		}
		return null;
	}

	/**
	 * Normalize a PointScale CSV for storage: comma-joined, trimmed, no spaces.
	 */
	private function normalize_point_scale($raw) {
		$parts = array_map('trim', explode(',', (string)$raw));
		return implode(',', $parts);
	}
```

Python edit pattern:

```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Tournament.php')
t = p.read_text()
needle = "\tprivate static function normalize_best_of($v) {\n\t\t$n = (int)$v;\n\t\t$allowed = [1, 3, 5, 7, 9];\n\t\treturn in_array($n, $allowed, true) ? $n : 1;\n\t}\n"
insert = '''
\t/**
\t * Validate Points-bracket config. Returns null on success, an error string on failure.
\t */
\tprivate function validate_points_config($r, $allowScaleAndMode = true) {
\t\t$rounds = (int)($r['PointRounds'] ?? 0);
\t\tif ($rounds < 1 || $rounds > 32) return 'PointRounds must be 1–32.';
\t\tif ($allowScaleAndMode) {
\t\t\t$mode = $r['PointMode'] ?? '';
\t\t\tif ($mode !== 'fixed' && $mode !== 'open') return 'PointMode must be fixed or open.';
\t\t\tif ($mode === 'fixed') {
\t\t\t\t$raw = trim((string)($r['PointScale'] ?? ''));
\t\t\t\tif ($raw === '') return 'PointScale CSV required for fixed mode.';
\t\t\t\t$parts = array_map('trim', explode(',', $raw));
\t\t\t\tif (count($parts) < 1 || count($parts) > 16) return 'PointScale must have 1–16 values.';
\t\t\t\t$seen = [];
\t\t\t\tforeach ($parts as $p) {
\t\t\t\t\tif (!preg_match('/^\\d+(\\.\\d{1,2})?$/', $p)) return "PointScale value \\"$p\\" invalid (non-neg decimal, ≤2 dp).";
\t\t\t\t\t$f = (float)$p;
\t\t\t\t\tif ($f < 0 || $f > 999.99) return "PointScale value \\"$p\\" out of range (0–999.99).";
\t\t\t\t\t$key = number_format($f, 2, '.', '');
\t\t\t\t\tif (isset($seen[$key])) return "PointScale has duplicate value \\"$p\\".";
\t\t\t\t\t$seen[$key] = true;
\t\t\t\t}
\t\t\t}
\t\t}
\t\treturn null;
\t}

\tprivate function normalize_point_scale($raw) {
\t\t$parts = array_map('trim', explode(',', (string)$raw));
\t\treturn implode(',', $parts);
\t}

'''
print('found:', needle in t)
p.write_text(t.replace(needle, needle + insert, 1))
PY
```

- [ ] **Step 2.3: Wire validation + persistence into AddBracket (else branch)**

In the `else` branch of `AddBracket()` (just before the `team` ironman guard around line 259), add the points-method validation and, in the persistence block (lines 261–276), set the three new columns when `method === 'points'`. Patch with Python:

```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Tournament.php')
t = p.read_text()

old = "\t\t\t// Gate: Ironman brackets do not support team participants.\n\t\t\tif (($request['Method'] ?? '') === 'ironman' && ($request['Participants'] ?? '') === 'team') {\n\t\t\t\treturn InvalidParameter(null, 'Team mode is not supported for Ironman brackets.');\n\t\t\t}\n"
new = "\t\t\t// Gate: Ironman brackets do not support team participants.\n\t\t\tif (($request['Method'] ?? '') === 'ironman' && ($request['Participants'] ?? '') === 'team') {\n\t\t\t\treturn InvalidParameter(null, 'Team mode is not supported for Ironman brackets.');\n\t\t\t}\n\t\t\t// Gate: Points brackets validate their three extra fields up-front.\n\t\t\tif (($request['Method'] ?? '') === 'points') {\n\t\t\t\t$err = $this->validate_points_config($request, true);\n\t\t\t\tif ($err !== null) return InvalidParameter(null, $err);\n\t\t\t}\n"
print('found gate:', old in t)
t = t.replace(old, new, 1)

old2 = "\t\t\t$this->Bracket->best_of          = self::normalize_best_of($request['BestOf'] ?? 1);\n\t\t\t$this->Bracket->save();\n"
new2 = "\t\t\t$this->Bracket->best_of          = self::normalize_best_of($request['BestOf'] ?? 1);\n\t\t\tif (($request['Method'] ?? '') === 'points') {\n\t\t\t\t$this->Bracket->point_rounds = (int)$request['PointRounds'];\n\t\t\t\t$this->Bracket->point_mode   = $request['PointMode'];\n\t\t\t\t$this->Bracket->point_scale  = ($request['PointMode'] === 'fixed')\n\t\t\t\t\t? $this->normalize_point_scale($request['PointScale'] ?? '')\n\t\t\t\t\t: null;\n\t\t\t}\n\t\t\t$this->Bracket->save();\n"
print('found save:', old2 in t)
t = t.replace(old2, new2, 1)

p.write_text(t)
PY
```

- [ ] **Step 2.4: Read UpdateBracket and patch for points fields + mid-run lock**

Read `system/lib/ork3/class.Tournament.php` lines 286–360 to see the existing `UpdateBracket` body. Add patch logic: when the incoming method is `points`, validate rounds, and enforce the lock rules (mode and scale rejected after any cell scored; round count may only increase from current value). Apply via Python.

Sketch of insertion (place after the existing field copies, before `save()` in UpdateBracket):

```php
if (($request['Method'] ?? '') === 'points') {
    // Always validate rounds; scale/mode only when scoring has not yet started.
    $hasScores = false;
    $r = $this->db->query("SELECT COUNT(*) AS n FROM " . DB_PREFIX . "point_score WHERE bracket_id = $bracket_id AND points IS NOT NULL");
    if ($r && $r->next()) $hasScores = ((int)$r->n > 0);

    $err = $this->validate_points_config($request, !$hasScores);
    if ($err !== null) return InvalidParameter(null, $err);

    $newRounds = (int)$request['PointRounds'];
    $curRounds = (int)($this->Bracket->point_rounds ?? 0);
    if ($this->Bracket->status === 'active' && $newRounds < $curRounds) {
        return InvalidParameter(null, 'Cannot reduce rounds after bracket is active. Reset bracket first.');
    }
    $this->Bracket->point_rounds = $newRounds;
    if (!$hasScores) {
        $this->Bracket->point_mode  = $request['PointMode'];
        $this->Bracket->point_scale = ($request['PointMode'] === 'fixed')
            ? $this->normalize_point_scale($request['PointScale'] ?? '')
            : null;
    }
}
```

(Use Python to find the exact anchor — likely a line like `$this->Bracket->best_of = self::normalize_best_of(...)` near the end of UpdateBracket, just before `save()`.)

- [ ] **Step 2.5: Smoke-test backend changes**

```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SELECT 1"  # confirm DB up
docker exec ork3-php8 php -l system/lib/ork3/class.Tournament.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 2.6: Commit**

```bash
git add system/lib/ork3/class.Tournament.php
git commit -m "Enhancement: Points-bracket config in AddBracket/UpdateBracket

Adds validate_points_config + normalize_point_scale; AddBracket persists
point_rounds/point_mode/point_scale when method=points. UpdateBracket
enforces the mid-run lock: scale+mode rejected after any cell scored,
rounds may only increase post-activation.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 3 — Backend: generate_points + dispatch

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php` (GenerateMatches around lines 866–882; add `generate_points()` near other generator methods around line 1768)

- [ ] **Step 3.1: Add generate_points()**

Insert just after `generate_ironman()` (search for `private function generate_ironman` to find the location). It's a near no-op — verifies ≥1 participant (the dispatch already verifies ≥2 for other methods; for Points we relax to ≥1) and does NOT write match rows.

Python insert:

```php
	/**
	 * Points bracket: no matches are written. The scoring grid is built from
	 * ork_point_score rows keyed (bracket_id, participant_id, round). The bracket
	 * just needs to exist with status=active so the grid renders.
	 */
	private function generate_points($bracket_id, $tournament_id, $participants) {
		// Intentionally empty. ork_match stays empty for Points brackets.
		// Caller (GenerateMatches) flips status to 'active' after this returns.
	}
```

- [ ] **Step 3.2: Add dispatch branch in GenerateMatches**

Patch the dispatch chain at lines 871–882. Python:

```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Tournament.php')
t = p.read_text()
old = "\t\t\t} elseif ($method === 'ironman') {\n\t\t\t\t$this->generate_ironman($bracket_id, $tournament_id, $participants, $rings);\n\t\t\t} else {\n\t\t\t\t// score or unknown: single elim as fallback\n\t\t\t\t$this->generate_single_elim($bracket_id, $tournament_id, $participants);\n\t\t\t}\n"
new = "\t\t\t} elseif ($method === 'ironman') {\n\t\t\t\t$this->generate_ironman($bracket_id, $tournament_id, $participants, $rings);\n\t\t\t} elseif ($method === 'points') {\n\t\t\t\t$this->generate_points($bracket_id, $tournament_id, $participants);\n\t\t\t} else {\n\t\t\t\t// score or unknown: single elim as fallback\n\t\t\t\t$this->generate_single_elim($bracket_id, $tournament_id, $participants);\n\t\t\t}\n"
print('found:', old in t)
p.write_text(t.replace(old, new, 1))
PY
```

- [ ] **Step 3.3: Relax the 2-participant guard for points**

The existing guard at line 819 (`if (count($participants) < 2) return InvalidParameter('Need at least 2 participants');`) is too strict for Points (a 1-shooter exhibition round is meaningful). Patch:

```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Tournament.php')
t = p.read_text()
old = "\t\tif (count($participants) < 2) return InvalidParameter('Need at least 2 participants');\n"
new = "\t\t$min_participants = ($this->Bracket->method === 'points') ? 1 : 2;\n\t\tif (count($participants) < $min_participants) return InvalidParameter('Need at least ' . $min_participants . ' participant(s)');\n"
print('found:', old in t)
p.write_text(t.replace(old, new, 1))
PY
```

- [ ] **Step 3.4: Lint**

```bash
docker exec ork3-php8 php -l system/lib/ork3/class.Tournament.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3.5: Commit**

```bash
git add system/lib/ork3/class.Tournament.php
git commit -m "Enhancement: Points-bracket generate dispatch

Adds no-op generate_points() and routes method=points through it from
GenerateMatches. Relaxes the 2-participant guard to 1 for points (a
single-shooter exhibition is valid).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 4 — Backend: SavePointScore + AddPointsRound

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php`

- [ ] **Step 4.1: Add SavePointScore()**

Insert at the end of the class (just before the final closing `}`). Use Python.

```php
	/**
	 * Upsert a single grid cell. $points may be null to clear.
	 * Returns Success({ participant_id, round, points, total }) on success.
	 */
	public function SavePointScore($request) {
		if (!$this->check_result_entry_auth($request)) return NoAuthorization();

		$bracket_id     = (int)($request['BracketId'] ?? 0);
		$participant_id = (int)($request['ParticipantId'] ?? 0);
		$round          = (int)($request['Round'] ?? 0);
		$rawPoints      = $request['Points'] ?? null;        // null/'' means clear

		if (!valid_id($bracket_id) || !valid_id($participant_id) || $round < 1) {
			return InvalidParameter('BracketId, ParticipantId, Round required.');
		}

		// Load bracket; verify method=points, status not finalized, round in range.
		$this->Bracket->clear();
		$this->Bracket->bracket_id = $bracket_id;
		if (!$this->Bracket->find()) return InvalidParameter('Bracket not found.');
		if ($this->Bracket->method !== 'points') return InvalidParameter('Bracket is not a Points bracket.');
		if ($this->Bracket->status === 'finalized') return InvalidParameter('Bracket is finalized.');
		$maxRound = (int)$this->Bracket->point_rounds;
		if ($round > $maxRound) return InvalidParameter("Round $round exceeds configured $maxRound.");

		// Verify participant belongs to this bracket.
		$ok = false;
		$r = $this->db->query("SELECT 1 FROM " . DB_PREFIX . "participant WHERE participant_id = $participant_id AND bracket_id = $bracket_id LIMIT 1");
		if ($r && $r->next()) $ok = true;
		if (!$ok) return InvalidParameter('Participant not in this bracket.');

		// Validate points value.
		$pointsValue = null;   // null = clear
		if ($rawPoints !== null && $rawPoints !== '') {
			if (!preg_match('/^\d+(\.\d{1,2})?$/', (string)$rawPoints)) {
				return InvalidParameter('Points must be a non-negative decimal with ≤2 decimal places.');
			}
			$f = (float)$rawPoints;
			if ($f < 0 || $f > 999.99) return InvalidParameter('Points out of range (0–999.99).');

			if ($this->Bracket->point_mode === 'fixed') {
				$scaleRaw = (string)$this->Bracket->point_scale;
				$scale = array_map('trim', explode(',', $scaleRaw));
				$allowedKeys = array_map(fn($v) => number_format((float)$v, 2, '.', ''), $scale);
				$thisKey = number_format($f, 2, '.', '');
				if (!in_array($thisKey, $allowedKeys, true)) {
					return InvalidParameter('Points value not in the bracket\'s fixed scale.');
				}
			}
			$pointsValue = number_format($f, 2, '.', '');
		}

		// Identify scorer (player_id) for audit. Match the project's existing pattern.
		$scoredBy = (int)($this->session->player_id ?? 0);
		$scoredByClause = $scoredBy > 0 ? $scoredBy : 'NULL';
		$pointsClause = ($pointsValue === null) ? 'NULL' : "'$pointsValue'";

		// Upsert via INSERT ... ON DUPLICATE KEY UPDATE on uq_cell.
		$this->db->Clear();
		$sql = "INSERT INTO " . DB_PREFIX . "point_score
				(bracket_id, participant_id, round, points, scored_at, scored_by)
				VALUES ($bracket_id, $participant_id, $round, $pointsClause, NOW(), $scoredByClause)
				ON DUPLICATE KEY UPDATE
				points = $pointsClause,
				scored_at = NOW(),
				scored_by = $scoredByClause";
		$this->db->query($sql);

		// Compute fresh standings to return so the client updates totals + ribbon.
		$standings = $this->GetPointStandings(['BracketId' => $bracket_id]);
		$detail = [
			'Cell'      => [
				'ParticipantId' => $participant_id,
				'Round'         => $round,
				'Points'        => $pointsValue,   // string (e.g. "5.00") or null
			],
			'Standings' => $standings['Detail'] ?? [],
		];
		$this->bustTournamentReportCache();
		return Success($detail);
	}

	/**
	 * Append a new round (increments point_rounds by 1). Blocked if bracket is finalized.
	 */
	public function AddPointsRound($request) {
		if (!$this->check_auth($request)) return NoAuthorization();
		$bracket_id = (int)($request['BracketId'] ?? 0);
		if (!valid_id($bracket_id)) return InvalidParameter('BracketId required.');

		$this->Bracket->clear();
		$this->Bracket->bracket_id = $bracket_id;
		if (!$this->Bracket->find()) return InvalidParameter('Bracket not found.');
		if ($this->Bracket->method !== 'points') return InvalidParameter('Not a Points bracket.');
		if ($this->Bracket->status === 'finalized') return InvalidParameter('Bracket is finalized.');

		$new = ((int)$this->Bracket->point_rounds) + 1;
		if ($new > 32) return InvalidParameter('Max 32 rounds.');

		$this->db->Clear();
		$this->db->query("UPDATE " . DB_PREFIX . "bracket SET point_rounds = $new WHERE bracket_id = $bracket_id");

		$this->bustTournamentReportCache();
		return Success(['PointRounds' => $new]);
	}
```

Python insert pattern: find the final closing `}` of the class, insert above it.

```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Tournament.php')
t = p.read_text()
# Anchor: last "}\n" of the file
anchor_idx = t.rstrip().rfind('}')
assert anchor_idx > 0
insert = '''
\t# ============== POINTS BRACKET ==============
\t# (paste the two methods above here, properly tab-indented)
\t# ============================================
'''
p.write_text(t[:anchor_idx] + insert + t[anchor_idx:])
PY
```

(Replace the placeholder marker with the full method bodies above. The executor agent should paste the two methods verbatim with project-standard tab indentation. Do not skip the `$this->db->Clear()` calls — they prevent silent failures.)

- [ ] **Step 4.2: Lint**

```bash
docker exec ork3-php8 php -l system/lib/ork3/class.Tournament.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 4.3: Smoke-test SavePointScore via direct SQL**

Pick an existing test tournament/bracket id from the dev DB, or create one via the UI (or skip and rely on Task 14's full manual run). Confirm the table accepts a manual upsert:

```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "
  -- Replace with real ids in dev DB. This step is illustrative.
  INSERT INTO ork_point_score (bracket_id, participant_id, round, points, scored_at)
    VALUES (999999, 999999, 1, 5.00, NOW())
    ON DUPLICATE KEY UPDATE points = 5.00;
  SELECT * FROM ork_point_score WHERE bracket_id = 999999;
  DELETE FROM ork_point_score WHERE bracket_id = 999999;
"
```

Expected: insert succeeds, row appears, delete succeeds.

- [ ] **Step 4.4: Commit**

```bash
git add system/lib/ork3/class.Tournament.php
git commit -m "Enhancement: Points-bracket SavePointScore + AddPointsRound

SavePointScore upserts one grid cell (bracket_id, participant_id, round)
into ork_point_score with fixed/open-mode validation, returning the
fresh standings so the client updates totals + ribbon in one round trip.
AddPointsRound increments point_rounds by 1 (capped at 32).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 5 — Backend: GetPointStandings + GetStandings dispatch

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php`

- [ ] **Step 5.1: Add GetPointStandings()**

Insert near the existing `GetStandings()` method. Use `grep -n "public function GetStandings" system/lib/ork3/class.Tournament.php` to find the anchor.

```php
	/**
	 * Standings for a Points bracket. Returns rows ordered by total DESC, alias ASC
	 * (alias is for stable display only — not a placement tiebreaker; ties share a place).
	 *
	 * Detail row: {
	 *   ParticipantId, Alias, ParticipantNumber, Status,
	 *   RoundScores: [string|null, ...], Total: string, Place: int|null, Tied: bool
	 * }
	 */
	public function GetPointStandings($request) {
		$bracket_id = (int)($request['BracketId'] ?? 0);
		if (!valid_id($bracket_id)) return InvalidParameter('BracketId required.');

		// Load bracket for point_rounds
		$this->Bracket->clear();
		$this->Bracket->bracket_id = $bracket_id;
		if (!$this->Bracket->find()) return InvalidParameter('Bracket not found.');
		$rounds = max(0, (int)$this->Bracket->point_rounds);

		// Load participants in the order they appear on the grid (seed asc, fallback id asc).
		$participants = [];
		$pr = $this->db->query("SELECT participant_id, alias, participant_number, status, seed
			FROM " . DB_PREFIX . "participant
			WHERE bracket_id = $bracket_id
			ORDER BY seed ASC, participant_id ASC");
		if ($pr) while ($pr->next()) {
			$participants[(int)$pr->participant_id] = [
				'ParticipantId'     => (int)$pr->participant_id,
				'Alias'             => (string)$pr->alias,
				'ParticipantNumber' => (int)$pr->participant_number,
				'Status'            => (string)$pr->status,
				'RoundScores'       => array_fill(0, $rounds, null),
				'Total'             => 0.0,
			];
		}

		// Load all cells.
		$sr = $this->db->query("SELECT participant_id, round, points
			FROM " . DB_PREFIX . "point_score
			WHERE bracket_id = $bracket_id");
		if ($sr) while ($sr->next()) {
			$pid = (int)$sr->participant_id;
			$rnd = (int)$sr->round;
			if (!isset($participants[$pid]) || $rnd < 1 || $rnd > $rounds) continue;
			$val = ($sr->points === null) ? null : (float)$sr->points;
			$participants[$pid]['RoundScores'][$rnd - 1] = ($val === null) ? null : number_format($val, 2, '.', '');
			if ($val !== null) $participants[$pid]['Total'] += $val;
		}

		// Sort: ACTIVE participants by total DESC, alias ASC; withdrawn/DQ at the bottom
		// in the same order.
		$active   = [];
		$inactive = [];
		foreach ($participants as $row) {
			$row['Total'] = number_format($row['Total'], 2, '.', '');
			if ($row['Status'] === 'active' || $row['Status'] === '') $active[] = $row;
			else $inactive[] = $row;
		}
		usort($active, function($a, $b) {
			$cmp = ((float)$b['Total']) <=> ((float)$a['Total']);
			return $cmp !== 0 ? $cmp : strcasecmp($a['Alias'], $b['Alias']);
		});

		// Rank-with-gaps: 1, 2, 2, 4
		$lastTotal = null;
		$lastPlace = 0;
		foreach ($active as $i => &$row) {
			$pos = $i + 1;
			if ($lastTotal !== null && (float)$row['Total'] === (float)$lastTotal) {
				$row['Place'] = $lastPlace;
				$row['Tied']  = true;
				// Mark the prior row as tied too (cosmetic for the client).
				$active[$i - 1]['Tied'] = true;
			} else {
				$row['Place'] = $pos;
				$row['Tied']  = false;
				$lastPlace = $pos;
			}
			$lastTotal = $row['Total'];
		}
		unset($row);

		foreach ($inactive as &$row) { $row['Place'] = null; $row['Tied'] = false; }
		unset($row);

		return Success(array_values(array_merge($active, $inactive)));
	}
```

- [ ] **Step 5.2: Dispatch from GetStandings**

Find the existing `GetStandings()` method. At its top, after auth/bracket-load, add an early-out:

```php
if ($this->Bracket->method === 'points') {
    return $this->GetPointStandings(['BracketId' => $bracket_id]);
}
```

Use `grep -n "public function GetStandings" system/lib/ork3/class.Tournament.php` to find the right line; read 30 lines following to confirm where the bracket is loaded, and Python-insert the early-out just after.

- [ ] **Step 5.3: Lint**

```bash
docker exec ork3-php8 php -l system/lib/ork3/class.Tournament.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 5.4: Commit**

```bash
git add system/lib/ork3/class.Tournament.php
git commit -m "Enhancement: Points-bracket standings

Adds GetPointStandings (reads ork_point_score, sums totals, applies
rank-with-gaps shared placement). GetStandings dispatches to it when
bracket.method='points'.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 6 — TournamentReport placements branch

**Files:**
- Modify: `system/lib/ork3/class.TournamentReport.php`

- [ ] **Step 6.1: Locate GetBracketPlacements + the existing non-elim helper**

```bash
grep -n "GetBracketPlacements\|placementsFromStandings\|round-robin\|swiss" system/lib/ork3/class.TournamentReport.php
```

- [ ] **Step 6.2: Add a branch for `method === 'points'`**

Read the method body. The existing pattern for round-robin/swiss/ironman likely calls `GetStandings()` then runs a `placementsFromStandings()` helper. Add an explicit branch that does the same for `points` — since `GetStandings()` already dispatches to `GetPointStandings()`, the simplest patch is: ensure the existing non-elim branch's condition includes `points`. If the existing code uses `if (in_array($method, ['round-robin', 'swiss', 'ironman']))`, change to include `'points'`.

Python edit pattern (verify the actual literal first):

```bash
python3 - <<'PY'
import pathlib, re
p = pathlib.Path('system/lib/ork3/class.TournamentReport.php')
t = p.read_text()
# Search for any in_array(['round-robin'...] expression and report it.
for m in re.finditer(r"in_array\([^)]*round-robin[^)]*\)", t):
    print(repr(m.group(0)))
PY
```

Apply the appropriate one-line change (add `'points'` to the array). Whether the file needs a brand-new branch or just an array addition depends on what we find. If unsure, default to: in `GetBracketPlacements`, near where `'round-robin'` is handled, add `|| $method === 'points'` to the same branch.

- [ ] **Step 6.3: Lint**

```bash
docker exec ork3-php8 php -l system/lib/ork3/class.TournamentReport.php
```

- [ ] **Step 6.4: Commit**

```bash
git add system/lib/ork3/class.TournamentReport.php
git commit -m "Enhancement: Points-bracket placement integration

Routes method=points through the existing non-elim placement-from-
standings branch in TournamentReport, so tournament-level standings_points
apply identically to RR/Swiss/Ironman.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 7 — AJAX endpoints + allowed-methods update

**Files:**
- Modify: `orkui/controller/controller.TournamentAjax.php`

- [ ] **Step 7.1: Add `points` to allowed_methods**

Two locations (lines 102 and 144 in the current file). Patch both:

```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.TournamentAjax.php')
t = p.read_text()
old = "$allowed_methods = ['single','double','swiss','round-robin','ironman','score'];"
new = "$allowed_methods = ['single','double','swiss','round-robin','ironman','score','points'];"
print('matches:', t.count(old))
p.write_text(t.replace(old, new))
PY
```

Expect `matches: 2`.

- [ ] **Step 7.2: Forward PointRounds/PointMode/PointScale through addbracket + updatebracket**

In the existing `addbracket` handler (around line 97–122), and `updatebracket` handler (around line 137–170), add three keys to the `array_merge` / payload passed to `Tournament->add_bracket()` / `Tournament->update_bracket()`. Python:

```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.TournamentAjax.php')
t = p.read_text()
old = "\t\t\t\t'BestOf'          => (int)($_POST['BestOf'] ?? 1),\n\t\t\t]);"
new = "\t\t\t\t'BestOf'          => (int)($_POST['BestOf'] ?? 1),\n\t\t\t\t'PointRounds'    => (int)($_POST['PointRounds'] ?? 0),\n\t\t\t\t'PointMode'      => trim($_POST['PointMode'] ?? ''),\n\t\t\t\t'PointScale'     => trim($_POST['PointScale'] ?? ''),\n\t\t\t]);"
print('matches:', t.count(old))
p.write_text(t.replace(old, new))
PY
```

If the matches count is not 1, inspect both call-sites and patch them individually.

- [ ] **Step 7.3: Add two new action branches**

Insert after the existing `'savestandingspoints'` action block (around line 270). Python:

```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.TournamentAjax.php')
t = p.read_text()
anchor = "\t\t} elseif ($action === 'savestandingspoints') {"
print('found anchor:', anchor in t)
insert = """\t\t} elseif ($action === 'savepointscore') {
\t\t\t$bracket_id = (int)($_POST['BracketId'] ?? 0);
\t\t\tif (!valid_id($bracket_id)) { echo json_encode(['status' => 1, 'error' => 'BracketId required.']); exit; }
\t\t\t$participant_id = (int)($_POST['ParticipantId'] ?? 0);
\t\t\t$round = (int)($_POST['Round'] ?? 0);
\t\t\t$points = (isset($_POST['Points']) && $_POST['Points'] !== '') ? trim($_POST['Points']) : null;
\t\t\t$r = $this->Tournament->save_point_score([
\t\t\t\t'Token'         => $this->session->token,
\t\t\t\t'TournamentId'  => $tournament_id,
\t\t\t\t'BracketId'     => $bracket_id,
\t\t\t\t'ParticipantId' => $participant_id,
\t\t\t\t'Round'         => $round,
\t\t\t\t'Points'        => $points,
\t\t\t]);
\t\t\techo ($r['Status'] == 0)
\t\t\t\t? json_encode(['status' => 0, 'detail' => $r['Detail']])
\t\t\t\t: $this->modelError($r);

\t\t} elseif ($action === 'addpointsround') {
\t\t\t$bracket_id = (int)($_POST['BracketId'] ?? 0);
\t\t\tif (!valid_id($bracket_id)) { echo json_encode(['status' => 1, 'error' => 'BracketId required.']); exit; }
\t\t\t$r = $this->Tournament->add_points_round([
\t\t\t\t'Token'        => $this->session->token,
\t\t\t\t'TournamentId' => $tournament_id,
\t\t\t\t'BracketId'    => $bracket_id,
\t\t\t]);
\t\t\techo ($r['Status'] == 0)
\t\t\t\t? json_encode(['status' => 0, 'detail' => $r['Detail']])
\t\t\t\t: $this->modelError($r);

"""
p.write_text(t.replace(anchor, insert + anchor, 1))
PY
```

(Note: the action dispatcher uses `$this->Tournament->snake_case_action(...)`. Confirm the project's `__call` magic maps `save_point_score` → `SavePointScore` by reading `model.Tournament.php` if uncertain — if not auto-mapped, add explicit thin wrappers there.)

- [ ] **Step 7.4: Lint**

```bash
docker exec ork3-php8 php -l orkui/controller/controller.TournamentAjax.php
```

- [ ] **Step 7.5: Commit**

```bash
git add orkui/controller/controller.TournamentAjax.php
git commit -m "Enhancement: Points-bracket AJAX endpoints

Adds 'savepointscore' and 'addpointsround' actions, forwards new
PointRounds/PointMode/PointScale fields through addbracket+updatebracket,
expands allowed_methods to include 'points'.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 8 — Add Bracket modal: method option + conditional fields + pip preview

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl` (lines around 88 for methodLabelMap, 2745 for method `<select>`, plus the bracket-card border-accent block at line 236, plus the JS that opens the AddBracket / UpdateBracket modal)

- [ ] **Step 8.1: Add the `points` option to the method select**

```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Tournametnew_index.tpl')
t = p.read_text()
old = "\t\t\t\t\t\t<option value=\"score\">Score</option>\n\t\t\t\t\t</select>"
new = "\t\t\t\t\t\t<option value=\"score\">Score</option>\n\t\t\t\t\t\t<option value=\"points\">Points</option>\n\t\t\t\t\t</select>"
print('matches:', t.count(old))
p.write_text(t.replace(old, new, 1))
PY
```

- [ ] **Step 8.2: Add methodLabelMap entry**

```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Tournametnew_index.tpl')
t = p.read_text()
old = "\t'ironman'     => 'Ironman',"
new = "\t'ironman'     => 'Ironman',\n\t'score'       => 'Score',\n\t'points'      => 'Points',"
# Only patch if 'points' not already in the map
if "'points'" not in t.split('$methodLabelMap',1)[1].split(']',1)[0]:
    print('inserting')
    p.write_text(t.replace(old, new, 1))
else:
    print('already present')
PY
```

- [ ] **Step 8.3: Add a method-color border accent for points**

```bash
python3 - <<'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Tournametnew_index.tpl')
t = p.read_text()
old = '.tn-bracket-card[data-method="score"] { border-left-color:#718096; }'
new = old + '\n.tn-bracket-card[data-method="points"] { border-left-color:#0bc5ea; }'
print('matches:', t.count(old))
p.write_text(t.replace(old, new, 1))
PY
```

- [ ] **Step 8.4: Insert the conditional fields block in the AddBracket modal**

The add-bracket modal currently has Style, Method, then "Advanced options" with Participants/Rings/Seeding. Insert a new conditional row between Method and the Advanced toggle, visible only when method === 'points'.

Read lines 2740–2820 of the template to find the exact closing `</div>` after the method `<select>`. Insert (via Python) immediately after that closing `</div>`:

```html
<div id="tn-addbracket-points-config" class="tn-field-row" style="display:none">
	<div class="tn-field">
		<label for="tn-addbracket-point-rounds">Rounds <span style="color:#e53e3e">*</span></label>
		<input type="number" id="tn-addbracket-point-rounds" value="3" min="1" max="32">
	</div>
	<div class="tn-field">
		<label>Point Mode <span style="color:#e53e3e">*</span></label>
		<div style="display:flex;gap:12px;align-items:center;padding-top:6px">
			<label style="display:flex;gap:4px;align-items:center;font-weight:400;cursor:pointer">
				<input type="radio" name="tn-addbracket-point-mode" value="fixed" checked> Fixed Points
			</label>
			<label style="display:flex;gap:4px;align-items:center;font-weight:400;cursor:pointer">
				<input type="radio" name="tn-addbracket-point-mode" value="open"> Open Points
			</label>
		</div>
	</div>
</div>
<div id="tn-addbracket-point-scale-row" class="tn-field-row" style="display:none">
	<div class="tn-field" style="flex:1">
		<label for="tn-addbracket-point-scale">Point Scale <span style="color:#e53e3e">*</span></label>
		<input type="text" id="tn-addbracket-point-scale" value="5,3,1,0" placeholder="e.g. 5,3,1,0">
		<div style="font-size:11px;color:#718096;margin-top:4px">Comma-separated values shown as clickable pips. First value is highest.</div>
		<div id="tn-addbracket-point-scale-preview" style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap"></div>
		<div id="tn-addbracket-point-scale-err" style="display:none;color:#e53e3e;font-size:12px;margin-top:4px"></div>
	</div>
</div>
```

(Python anchor: search for the closing `</select>` of `tn-addbracket-method` and the next `</div>` — insert after both. The executor agent should locate the exact closing div by reading the file.)

- [ ] **Step 8.5: JS — show/hide on method change, render live pip preview**

Find the JS block that hooks the existing addbracket modal (search for `tn-addbracket-method` in the same template). Add an event-bound function that:

1. Hides `#tn-addbracket-points-config` and `#tn-addbracket-point-scale-row` when method !== 'points'.
2. When method === 'points': shows the config row; if Fixed Points is selected (radio), shows the scale row.
3. Hides `#tn-addbracket-best-of` (find existing best-of UI) when method === 'points'.
4. On scale-input `input` event: parse CSV, render pip preview as a row of `<span class="tn-pip">5</span>` etc.; show inline error on invalid input (regex same as backend).
5. On submit, only include PointRounds / PointMode / PointScale in the POST when method === 'points'.

Sketch of the JS to add:

```js
(function(){
	if (typeof PnConfig !== 'undefined' && !PnConfig.canEditAdmin) return;
	var methodSel  = document.getElementById('tn-addbracket-method');
	var cfgRow     = document.getElementById('tn-addbracket-points-config');
	var scaleRow   = document.getElementById('tn-addbracket-point-scale-row');
	var scaleInput = document.getElementById('tn-addbracket-point-scale');
	var scaleErr   = document.getElementById('tn-addbracket-point-scale-err');
	var scalePrev  = document.getElementById('tn-addbracket-point-scale-preview');
	if (!methodSel) return;

	function modeRadio() {
		var r = document.querySelector('input[name="tn-addbracket-point-mode"]:checked');
		return r ? r.value : 'fixed';
	}

	function syncVisibility() {
		var isPoints = (methodSel.value === 'points');
		if (cfgRow)   cfgRow.style.display   = isPoints ? '' : 'none';
		if (scaleRow) scaleRow.style.display = (isPoints && modeRadio() === 'fixed') ? '' : 'none';
	}

	function renderPreview() {
		if (!scalePrev) return;
		scalePrev.innerHTML = '';
		scaleErr.style.display = 'none';
		var raw = (scaleInput.value || '').trim();
		if (!raw) return;
		var parts = raw.split(',').map(function(s){ return s.trim(); });
		var seen = {};
		for (var i = 0; i < parts.length; i++) {
			var v = parts[i];
			if (!/^\d+(\.\d{1,2})?$/.test(v) || +v < 0 || +v > 999.99) {
				scaleErr.textContent = 'Invalid value: "' + v + '"';
				scaleErr.style.display = '';
				scalePrev.innerHTML = '';
				return;
			}
			var k = (+v).toFixed(2);
			if (seen[k]) {
				scaleErr.textContent = 'Duplicate value: "' + v + '"';
				scaleErr.style.display = '';
				scalePrev.innerHTML = '';
				return;
			}
			seen[k] = true;
		}
		if (parts.length < 1 || parts.length > 16) {
			scaleErr.textContent = 'Must have 1–16 values';
			scaleErr.style.display = '';
			return;
		}
		parts.forEach(function(v){
			var s = document.createElement('span');
			s.className = 'tn-pip tn-pip-preview';
			s.textContent = v;
			scalePrev.appendChild(s);
		});
	}

	methodSel.addEventListener('change', syncVisibility);
	document.querySelectorAll('input[name="tn-addbracket-point-mode"]').forEach(function(r){
		r.addEventListener('change', syncVisibility);
	});
	if (scaleInput) scaleInput.addEventListener('input', renderPreview);

	// Initial state
	syncVisibility();
	renderPreview();

	// Augment the existing submit hook by hooking the same submit button.
	// We expect the existing handler to read formData via fetch; we patch by
	// attaching a 'beforeSubmit' DOM event that the existing code already dispatches.
	// If no such hook exists, the executor agent should find the existing fetch()
	// in this template and add three POST fields conditionally.
})();
```

The executor agent will need to find the existing fetch/POST in this template (search for `addbracket` action in the template's JS) and append:

```js
if (methodSel.value === 'points') {
	formData.append('PointRounds', document.getElementById('tn-addbracket-point-rounds').value);
	formData.append('PointMode',   document.querySelector('input[name="tn-addbracket-point-mode"]:checked').value);
	if (formData.get('PointMode') === 'fixed') {
		formData.append('PointScale', document.getElementById('tn-addbracket-point-scale').value);
	}
}
```

- [ ] **Step 8.6: CSS — pip preview**

Add to the existing inline `<style>` block (somewhere in the file's CSS section, e.g. near other `.tn-*` styles):

```css
.tn-pip {
	display:inline-flex; align-items:center; justify-content:center;
	min-width:32px; height:28px; padding:0 10px;
	border:1px solid #cbd5e0; border-radius:14px;
	background:#fff; color:#2d3748;
	font-weight:600; font-size:13px; cursor:pointer;
	user-select:none;
	transition:background-color .1s, color .1s, border-color .1s;
}
.tn-pip:hover { border-color:#4a5568; }
.tn-pip.tn-pip-selected {
	background:#2b6cb0; color:#fff; border-color:#2b6cb0;
}
.tn-pip-preview { cursor:default; }
/* Dark mode */
body.dark-mode .tn-pip,
.dark-mode .tn-pip {
	background:#2d3748; color:#e2e8f0; border-color:#4a5568;
}
body.dark-mode .tn-pip:hover,
.dark-mode .tn-pip:hover { border-color:#a0aec0; }
body.dark-mode .tn-pip.tn-pip-selected,
.dark-mode .tn-pip.tn-pip-selected {
	background:#3182ce; color:#fff; border-color:#3182ce;
}
```

- [ ] **Step 8.7: Test in browser**

After saving, refresh the tournament page in dev. Open Add Bracket modal, switch Method to Points. Verify: rounds input, mode radio, and scale input appear; typing in the scale renders the pip preview live; switching to Open Points hides the scale row; switching back to Single Elim hides everything points-related.

Verify dark mode by toggling theme — pip colors readable both ways.

- [ ] **Step 8.8: Commit**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: Points option in Add Bracket modal

Adds Points to method select, methodLabelMap, and bracket-card border
accent. Inserts conditional Rounds/Mode/Scale fields with live pip
preview and inline validation. Dark-mode pip styles included.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 9 — Server-side: prep Points grid data + base grid render

**Files:**
- Modify: `orkui/controller/controller.Tournament.php` (or wherever bracket data is hydrated for the index view)
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl`

- [ ] **Step 9.1: Locate the bracket-card render branch**

```bash
grep -n "data-method=\|tn-bracket-card\|_isIronman\|tn-bracket-run" orkui/template/revised-frontend/Tournametnew_index.tpl | head -30
```

The template branches off `$b['Method']` in the per-bracket render section (around line 2197 and line 2409 for ironman). Find the analogous "this bracket's run body" wrapper.

- [ ] **Step 9.2: Hydrate Points data for each bracket in the controller**

Open `orkui/controller/controller.Tournament.php`, find where `$bracketData` (or similar) is built per-bracket. For brackets where `Method === 'points'`, call `$this->model_tournament->get_point_standings(['BracketId' => $bid])` and stash the result on the bracket payload as `PointStandings`. Use a Read first to locate the exact loop.

Pseudocode:

```php
if (($b['Method'] ?? '') === 'points') {
    $ps = $this->model_tournament->get_point_standings(['BracketId' => $bid]);
    $b['PointStandings'] = ($ps['Status'] == 0) ? $ps['Detail'] : [];
    $b['PointRounds'] = (int)($b['PointRounds'] ?? 0);
    $b['PointMode']   = $b['PointMode'] ?? 'fixed';
    $b['PointScale']  = $b['PointScale'] ?? '';
}
```

- [ ] **Step 9.3: Render the grid (PHP + HTML)**

Inside the per-bracket render block in the template, add a branch `<?php elseif (($b['Method'] ?? '') === 'points'): ?>` that renders:

- A standings ribbon `<div class="tn-points-ribbon">` populated server-side from `$b['PointStandings']`.
- A `<table class="tn-points-grid">` with one row per `$b['PointStandings']` entry. Each round column gets a `<td class="tn-points-cell" data-pid="…" data-round="N">` whose contents depend on mode.
- A sticky Total column on the right.
- An "Add Round" button visible when `$b['Status'] === 'active'`.

Skeleton (place inside the template's bracket-render switch):

```php
<?php elseif (($b['Method'] ?? '') === 'points'): ?>
	<?php
		$pmode   = $b['PointMode'] ?? 'fixed';
		$pscale  = ($pmode === 'fixed') ? array_map('trim', explode(',', (string)($b['PointScale'] ?? ''))) : [];
		$prounds = (int)($b['PointRounds'] ?? 0);
		$pstand  = $b['PointStandings'] ?? [];
		$canEdit = $b['CanEdit'] ?? false; // assume hydrated upstream — match existing template convention
	?>
	<div class="tn-points-wrap" data-bid="<?= $bid ?>"
		data-mode="<?= htmlspecialchars($pmode) ?>"
		data-scale="<?= htmlspecialchars((string)($b['PointScale'] ?? '')) ?>"
		data-rounds="<?= $prounds ?>">

		<div class="tn-points-ribbon" id="tn-points-ribbon-<?= $bid ?>">
			<?php $i = 0; foreach ($pstand as $row): if ($row['Status'] !== 'active') continue; if ($i++ >= 5) break; ?>
				<span class="tn-points-rib-item">
					<strong><?= $row['Tied'] ? 'T-' : '' ?><?= htmlspecialchars((string)$row['Place']) ?></strong>
					<?= htmlspecialchars($row['Alias']) ?> (<?= htmlspecialchars($row['Total']) ?>)
				</span>
			<?php endforeach; ?>
		</div>

		<div class="tn-points-grid-scroll">
			<table class="tn-points-grid">
				<thead>
					<tr>
						<th class="tn-points-col-player">Player</th>
						<?php for ($r = 1; $r <= $prounds; $r++): ?>
							<th class="tn-points-col-round">R<?= $r ?></th>
						<?php endfor; ?>
						<?php if ($canEdit && $b['Status'] === 'active'): ?>
							<th class="tn-points-col-add">
								<button class="tn-btn tn-btn-sm tn-btn-outline" onclick="tnPointsAddRound(<?= $bid ?>)" data-tip="Add another round">+</button>
							</th>
						<?php endif; ?>
						<th class="tn-points-col-total">Total</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($pstand as $row): $pid = (int)$row['ParticipantId']; ?>
						<tr data-pid="<?= $pid ?>" class="<?= $row['Status'] !== 'active' ? 'tn-points-row-inactive' : '' ?>">
							<td class="tn-points-col-player">#<?= $row['ParticipantNumber'] ?> <?= htmlspecialchars($row['Alias']) ?></td>
							<?php for ($r = 1; $r <= $prounds; $r++): $val = $row['RoundScores'][$r-1] ?? null; ?>
								<td class="tn-points-cell" data-pid="<?= $pid ?>" data-round="<?= $r ?>" data-value="<?= htmlspecialchars($val ?? '') ?>">
									<?php if (!$canEdit): ?>
										<span class="tn-points-readonly"><?= $val !== null ? htmlspecialchars($val) : '—' ?></span>
									<?php elseif ($pmode === 'fixed'): ?>
										<div class="tn-pips">
											<?php foreach ($pscale as $sv): $selected = ($val !== null && (float)$val === (float)$sv); ?>
												<span class="tn-pip <?= $selected ? 'tn-pip-selected' : '' ?>" data-val="<?= htmlspecialchars($sv) ?>"><?= htmlspecialchars($sv) ?></span>
											<?php endforeach; ?>
										</div>
									<?php else: ?>
										<input type="text" class="tn-points-input" inputmode="decimal" maxlength="5" value="<?= htmlspecialchars($val ?? '') ?>">
									<?php endif; ?>
									<span class="tn-points-status" aria-hidden="true"></span>
								</td>
							<?php endfor; ?>
							<?php if ($canEdit && $b['Status'] === 'active'): ?>
								<td class="tn-points-col-add">&nbsp;</td>
							<?php endif; ?>
							<td class="tn-points-col-total"><?= htmlspecialchars($row['Total']) ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
<?php endif; ?>
```

Match the existing template's surrounding `<?php if (… single)>` chain — don't break sibling branches.

- [ ] **Step 9.4: CSS — grid layout, dark-mode safe**

Add to the inline CSS:

```css
.tn-points-wrap { margin:8px 0; }
.tn-points-ribbon {
	display:flex; flex-wrap:wrap; gap:14px; padding:8px 12px; margin-bottom:8px;
	background:#f7fafc; border:1px solid #e2e8f0; border-radius:6px;
	font-size:13px;
}
.tn-points-rib-item strong { color:#2b6cb0; margin-right:4px; }
.tn-points-grid-scroll { overflow-x:auto; }
.tn-points-grid {
	border-collapse:separate; border-spacing:0;
	width:100%; min-width:520px;
	background:transparent;
}
.tn-points-grid th, .tn-points-grid td {
	padding:6px 8px; border-bottom:1px solid #edf2f7; vertical-align:middle;
}
.tn-points-grid th {
	background:#edf2f7; color:#2d3748;
	font-size:12px; font-weight:600; text-align:center;
	/* Reset orkui.css global h1-h6 background-leak (no headings here but defensive). */
}
.tn-points-grid th.tn-points-col-player,
.tn-points-grid td.tn-points-col-player {
	text-align:left; position:sticky; left:0; background:inherit; z-index:1; min-width:160px;
}
.tn-points-grid th.tn-points-col-total,
.tn-points-grid td.tn-points-col-total {
	text-align:right; font-weight:700; position:sticky; right:0; background:inherit; z-index:1; min-width:60px;
}
.tn-points-cell { text-align:center; }
.tn-points-row-inactive { opacity:.55; }
.tn-pips { display:inline-flex; gap:4px; justify-content:center; flex-wrap:wrap; }
.tn-points-input {
	width:48px; padding:4px; text-align:center;
	border:1px solid #cbd5e0; border-radius:4px;
	font-size:13px; background:#fff; color:#2d3748;
}
.tn-points-input:focus { outline:none; border-color:#3182ce; box-shadow:0 0 0 2px rgba(49,130,206,.25); }
.tn-points-input.tn-points-err { border-color:#e53e3e; }
.tn-points-status {
	display:inline-block; width:14px; height:14px; margin-left:4px; vertical-align:middle;
}
.tn-points-status.tn-saving::before { content:'⋯'; color:#a0aec0; }
.tn-points-status.tn-saved::before  { content:'✓'; color:#48bb78; }
.tn-points-status.tn-error::before  { content:'!'; color:#e53e3e; font-weight:700; }

/* Dark mode */
body.dark-mode .tn-points-ribbon,
.dark-mode .tn-points-ribbon {
	background:#2d3748; border-color:#4a5568; color:#e2e8f0;
}
body.dark-mode .tn-points-rib-item strong,
.dark-mode .tn-points-rib-item strong { color:#63b3ed; }
body.dark-mode .tn-points-grid th,
.dark-mode .tn-points-grid th { background:#2d3748; color:#e2e8f0; }
body.dark-mode .tn-points-grid td,
.dark-mode .tn-points-grid td { border-color:#4a5568; color:#e2e8f0; }
body.dark-mode .tn-points-grid td.tn-points-col-player,
.dark-mode .tn-points-grid td.tn-points-col-player { background:#1a202c; }
body.dark-mode .tn-points-grid td.tn-points-col-total,
.dark-mode .tn-points-grid td.tn-points-col-total { background:#1a202c; }
body.dark-mode .tn-points-input,
.dark-mode .tn-points-input { background:#2d3748; color:#e2e8f0; border-color:#4a5568; }
```

- [ ] **Step 9.5: Verify in browser**

Create a Points bracket via the modal, add 3 participants, click Generate. Visit the tournament page — grid should render with empty pip rows. Toggle dark mode; everything must remain legible.

- [ ] **Step 9.6: Commit**

```bash
git add orkui/controller/controller.Tournament.php orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: Points-bracket grid render (read-only scaffold)

Hydrates PointStandings server-side, renders a sticky-column grid
(player × round × total) with pip-cell scaffolding (Fixed) or text
input (Open), plus a standings ribbon. Dark-mode CSS included.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 10 — Fixed-mode pip cells: click handlers + auto-save

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl`

- [ ] **Step 10.1: Add JS for pip clicks**

Append (in the existing JS section of the template, behind a `PnConfig.canEditAdmin` or similar guard):

```js
(function(){
	function postSave(bid, pid, round, value, cellEl) {
		var status = cellEl.querySelector('.tn-points-status');
		if (status) { status.className = 'tn-points-status tn-saving'; }
		var fd = new FormData();
		fd.append('Action', 'savepointscore');
		fd.append('Token', (window.tnToken || ''));
		fd.append('BracketId', bid);
		fd.append('ParticipantId', pid);
		fd.append('Round', round);
		if (value !== null) fd.append('Points', value);
		fetch(window.tnAjaxUrl /* set elsewhere */, { method:'POST', body:fd, credentials:'same-origin' })
			.then(function(r){ return r.json(); })
			.then(function(j){
				if (j.status !== 0) throw new Error(j.error || 'Save failed');
				cellEl.dataset.value = (j.detail && j.detail.Cell && j.detail.Cell.Points !== null) ? j.detail.Cell.Points : '';
				if (status) { status.className = 'tn-points-status tn-saved'; setTimeout(function(){ if (status.classList.contains('tn-saved')) status.className = 'tn-points-status'; }, 800); }
				if (j.detail && j.detail.Standings) {
					tnPointsRenderStandings(bid, j.detail.Standings);
				}
			})
			.catch(function(e){
				if (status) { status.className = 'tn-points-status tn-error'; status.title = ''; status.setAttribute('data-tip', String(e.message || e)); }
				console.log('[points] save failed', e);
			});
	}

	function tnPointsRenderStandings(bid, standings) {
		// Update the ribbon's top-5
		var ribbon = document.getElementById('tn-points-ribbon-' + bid);
		if (ribbon) {
			var html = '';
			var i = 0;
			for (var k = 0; k < standings.length && i < 5; k++) {
				var row = standings[k];
				if (row.Status !== 'active' && row.Status !== '') continue;
				html += '<span class="tn-points-rib-item"><strong>' +
					(row.Tied ? 'T-' : '') + row.Place + '</strong> ' +
					escapeHtml(row.Alias) + ' (' + escapeHtml(row.Total) + ')</span>';
				i++;
			}
			ribbon.innerHTML = html;
		}
		// Update each row's Total column
		standings.forEach(function(row){
			var tr = document.querySelector('.tn-points-wrap[data-bid="' + bid + '"] tr[data-pid="' + row.ParticipantId + '"]');
			if (!tr) return;
			var tot = tr.querySelector('.tn-points-col-total');
			if (tot) tot.textContent = row.Total;
		});
	}

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function(c){ return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; });
	}

	// Delegated click handler for pips
	document.addEventListener('click', function(ev){
		var pip = ev.target.closest('.tn-pip:not(.tn-pip-preview)');
		if (!pip) return;
		var cell = pip.closest('.tn-points-cell');
		if (!cell) return;
		var wrap = cell.closest('.tn-points-wrap');
		if (!wrap) return;
		var bid = wrap.dataset.bid;
		var pid = cell.dataset.pid;
		var round = cell.dataset.round;
		var clickedVal = pip.dataset.val;
		var currentVal = cell.dataset.value || '';
		// Optimistic UI: toggle selection now, server confirms.
		var siblings = cell.querySelectorAll('.tn-pip');
		var willClear = (currentVal !== '' && parseFloat(currentVal) === parseFloat(clickedVal));
		siblings.forEach(function(s){ s.classList.remove('tn-pip-selected'); });
		if (!willClear) pip.classList.add('tn-pip-selected');
		postSave(bid, pid, round, willClear ? null : clickedVal, cell);
	});

	// Expose ribbon-render for other handlers (open-mode input save uses it too).
	window.tnPointsRenderStandings = tnPointsRenderStandings;
	window.tnPointsPostSave = postSave;
})();
```

(`window.tnAjaxUrl` and `window.tnToken` — confirm the project's existing convention for the AJAX URL + CSRF token by reading the existing AJAX call near `addbracket` in the same template.)

- [ ] **Step 10.2: Browser test**

Click pips on a fresh Points bracket. Verify:
- Click an unselected pip: it becomes selected, a saving spinner shows, then turns into a green ✓ briefly.
- Click the selected pip: it clears, server returns Points=null, Total decrements.
- Click another pip in the same cell: selection switches, single POST.
- Total column and standings ribbon update live.

- [ ] **Step 10.3: Commit**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: Points-bracket Fixed-mode pip click + auto-save

Delegated click handler on .tn-pip: optimistic select/deselect, POST to
savepointscore, ribbon + Total cells updated live from response.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 11 — Open-mode input cells: validation + auto-save

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl`

- [ ] **Step 11.1: Add JS for open-mode text inputs**

Append to the same JS section:

```js
(function(){
	function validateOpen(v) {
		if (v === '' || v === null) return { ok:true, value:null };
		if (!/^\d+(\.\d{1,2})?$/.test(v)) return { ok:false, error:'Use non-negative decimal (≤2 dp).' };
		var f = parseFloat(v);
		if (f < 0 || f > 999.99) return { ok:false, error:'Out of range (0–999.99).' };
		return { ok:true, value:f.toFixed(2) };
	}

	function commit(input) {
		var cell = input.closest('.tn-points-cell');
		var wrap = cell.closest('.tn-points-wrap');
		var bid = wrap.dataset.bid;
		var pid = cell.dataset.pid;
		var round = cell.dataset.round;
		var raw = input.value.trim();
		var prev = cell.dataset.value || '';
		if (raw === prev) return; // no change

		var v = validateOpen(raw);
		if (!v.ok) {
			input.classList.add('tn-points-err');
			input.value = prev;
			setTimeout(function(){ input.classList.remove('tn-points-err'); }, 800);
			return;
		}
		input.classList.remove('tn-points-err');
		window.tnPointsPostSave(bid, pid, round, v.value, cell);
	}

	document.addEventListener('blur', function(ev){
		if (ev.target.classList && ev.target.classList.contains('tn-points-input')) {
			commit(ev.target);
		}
	}, true);

	document.addEventListener('keydown', function(ev){
		if (ev.key !== 'Enter') return;
		if (ev.target.classList && ev.target.classList.contains('tn-points-input')) {
			ev.preventDefault();
			ev.target.blur();
		}
	});
})();
```

- [ ] **Step 11.2: Browser test**

Create a second bracket with Open mode. Enter `8.5`, blur — saves. Enter `abc` — red border, reverts. Enter `1000` — same. Verify Total + ribbon update.

- [ ] **Step 11.3: Commit**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: Points-bracket Open-mode input + auto-save

Blur/Enter triggers commit; client-side validation matches backend
(non-neg decimal ≤2 dp, ≤999.99). Invalid values flash red and revert.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 12 — Add Round button: append a new column

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl`

- [ ] **Step 12.1: Add the JS handler**

Append:

```js
window.tnPointsAddRound = function(bid) {
	var wrap = document.querySelector('.tn-points-wrap[data-bid="' + bid + '"]');
	if (!wrap) return;
	var btn = wrap.querySelector('.tn-points-col-add button');
	if (btn) btn.disabled = true;

	var fd = new FormData();
	fd.append('Action', 'addpointsround');
	fd.append('Token', (window.tnToken || ''));
	fd.append('BracketId', bid);
	fetch(window.tnAjaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
		.then(function(r){ return r.json(); })
		.then(function(j){
			if (j.status !== 0) throw new Error(j.error || 'Add round failed');
			var newRound = j.detail.PointRounds;
			wrap.dataset.rounds = newRound;

			// Build the new round-column header
			var headRow = wrap.querySelector('thead tr');
			var newTh = document.createElement('th');
			newTh.className = 'tn-points-col-round';
			newTh.textContent = 'R' + newRound;
			// Insert before .tn-points-col-add (or before .tn-points-col-total if no add col)
			var addColTh = headRow.querySelector('.tn-points-col-add') || headRow.querySelector('.tn-points-col-total');
			headRow.insertBefore(newTh, addColTh);

			// Append a blank cell to each row
			var mode = wrap.dataset.mode;
			var scale = (wrap.dataset.scale || '').split(',').map(function(s){ return s.trim(); }).filter(Boolean);
			wrap.querySelectorAll('tbody tr').forEach(function(tr){
				var pid = tr.dataset.pid;
				var td = document.createElement('td');
				td.className = 'tn-points-cell';
				td.dataset.pid = pid;
				td.dataset.round = newRound;
				td.dataset.value = '';
				if (mode === 'fixed') {
					var pipsHtml = '<div class="tn-pips">' + scale.map(function(v){
						return '<span class="tn-pip" data-val="' + v + '">' + v + '</span>';
					}).join('') + '</div>';
					td.innerHTML = pipsHtml + '<span class="tn-points-status"></span>';
				} else {
					td.innerHTML = '<input type="text" class="tn-points-input" inputmode="decimal" maxlength="5"><span class="tn-points-status"></span>';
				}
				var addColTd = tr.querySelector('.tn-points-col-add') || tr.querySelector('.tn-points-col-total');
				tr.insertBefore(td, addColTd);
			});
		})
		.catch(function(e){ console.log('[points] add round failed', e); alert('Could not add a round: ' + e.message); })
		.finally(function(){ if (btn) btn.disabled = false; });
};
```

- [ ] **Step 12.2: Browser test**

Click `+`. Verify a new R{N+1} column appears with blank pip rows for every participant. DB `SELECT point_rounds FROM ork_bracket WHERE bracket_id = …` reflects the new count.

- [ ] **Step 12.3: Commit**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: Points-bracket Add Round button

POSTs addpointsround, appends a new column header + blank cells (pip
row or text input) to every participant row in-place, no reload.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 13 — Mobile: TnMobile integration + stack view

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl`

- [ ] **Step 13.1: Mobile grid CSS**

Add to inline CSS:

```css
.tn-mobile .tn-points-grid { min-width:380px; }
.tn-mobile .tn-points-grid th,
.tn-mobile .tn-points-grid td { padding:8px 6px; }
.tn-mobile .tn-points-grid td.tn-points-col-player { min-width:120px; font-size:13px; }
.tn-mobile .tn-pip { min-width:36px; height:32px; font-size:14px; } /* finger-friendly */
.tn-mobile .tn-points-input { width:54px; height:32px; font-size:14px; }
```

- [ ] **Step 13.2: (Optional) Stack view toggle**

If TnMobile provides a viewMode toggle (search `TnMobile.viewMode\|tn-view-mode\|tnSetViewMode` in the template), add a third view called `'points-stack'`. When active, hide the grid `<table>` and render a one-participant-per-screen card with the current participant's row of pips per round, plus prev/next arrows.

If the existing TnMobile foundation has no obvious extension point for a 3rd mode, defer the stack view — the horizontally-scrollable mobile grid (Step 13.1) is acceptable as v1.

- [ ] **Step 13.3: Browser test**

Open the bracket on a phone-width viewport (DevTools device emulation). Verify the grid scrolls horizontally without breaking layout, pips remain tappable, ribbon wraps cleanly.

- [ ] **Step 13.4: Commit**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: Points-bracket mobile sizing

Finger-friendly pip + input sizing under .tn-mobile, sticky player +
total columns retain pinning on narrow viewports.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 14 — Dark-mode pre-flight + manual verification pass

**Files:** none (verification only)

- [ ] **Step 14.1: Dark-mode walk per project checklist**

Open the tournament page in dark mode (toggle theme). Visually verify each surface added in tasks 8–13:
- AddBracket modal: rounds input, mode radio, scale input, pip preview row — all legible.
- Grid: ribbon background + text, table header row, sticky player + total cells, pip selected/unselected, open-mode input.
- Error states: invalid scale CSV, invalid open-mode input.
- Saving / saved / error pip statuses readable.
- Add Round button.

Fix any contrast / `h1-h6` background-leak / muted-text issues by adding explicit overrides.

- [ ] **Step 14.2: Walk the 15-step spec test plan**

Run through every numbered step in the spec's "Test Plan" section:
1. Create points bracket with fixed scale; invalid CSV blocked.
2. Add 4 participants.
3. Generate → status=active, grid renders.
4. Score across pips; verify each click saves; Total + ribbon update live.
5. Click selected pip; cell clears, Total recomputes.
6. Click + button; R4 column added blank.
7. Add 5th participant; row appears with blank cells.
8. Open mode: enter `8.5`, `9.25`, `0`; invalid values flash red + revert.
9. Two-browser concurrency: last write wins.
10. Score two participants to same total; ribbon shows `T-1` for both.
11. Log out: pips not clickable, inputs disabled.
12. Dark mode toggled for every surface.
13. Mobile viewport: grid scrolls horizontally; pip tap works.
14. Finalize bracket; placements 1–N in TournamentReport (with T- shared placements).
15. Direct API attempt to change `point_scale` after scoring → rejected.

Note any failures and address before declaring done.

- [ ] **Step 14.3: Verify no Authorization.php was staged**

```bash
git log --name-only --pretty=format:"" -10 | sort -u | grep -i Authorization || echo "OK"
```

Expected: `OK`.

- [ ] **Step 14.4: Final commit (only if Step 14.1 required CSS fixes)**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: Points-bracket dark-mode polish

Final pass — explicit overrides for any contrast issues found in the
pre-flight walk (modal headers, muted text, button states).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage check:**

| Spec section | Covered by task(s) |
| --- | --- |
| Configuration: rounds/mode/scale, locks | Tasks 2, 8 |
| Mid-run rules (add round, lock scale, late participant) | Tasks 2, 4, 12 |
| Data model: `ork_point_score`, bracket columns, enum | Task 1 |
| Backend: AddBracket/UpdateBracket validation | Task 2 |
| Backend: generate_points dispatch | Task 3 |
| Backend: SavePointScore / AddPointsRound | Task 4 |
| Backend: GetPointStandings + dispatch | Task 5 |
| TournamentReport placement branch | Task 6 |
| AJAX endpoints + allowed_methods | Task 7 |
| UI: AddBracket modal fields + pip preview | Task 8 |
| UI: Grid render server-side | Task 9 |
| UI: Fixed pip click + auto-save | Task 10 |
| UI: Open input + validation + auto-save | Task 11 |
| UI: Add Round button | Task 12 |
| UI: Standings ribbon live update | Tasks 9 (initial), 10 (live) |
| UI: Mobile sizing | Task 13 |
| Dark-mode pre-flight | Tasks 8, 9, 14 |
| Manual test plan | Task 14 |

No gaps.

**Placeholder scan:** No "TBD", no "implement later", no "add error handling" without code. Step 8.5 and 9.2/9.3 contain skeletons with a deliberate instruction to the executor to locate exact anchors via grep/Read — this is unavoidable because the template is 11,500 lines and exact line anchors will drift; the steps name the anchors precisely (e.g. "search for the closing `</select>` of `tn-addbracket-method`") rather than leaving them vague.

**Type consistency:**
- `SavePointScore` returns `{ Cell:{ ParticipantId, Round, Points }, Standings:[...] }` — used as `j.detail.Cell.Points` and `j.detail.Standings` in Task 10. ✓
- `AddPointsRound` returns `{ PointRounds: N }` — used as `j.detail.PointRounds` in Task 12. ✓
- `GetPointStandings` returns rows with `ParticipantId, Alias, ParticipantNumber, Status, RoundScores, Total, Place, Tied` — used identically in PHP render (Task 9) and JS ribbon update (Task 10). ✓
- `methodLabelMap['points']` set in Task 8.2; used by existing rendering paths.
- `participant.status` value `'active'` referenced consistently.

Plan is consistent.

---

## Execution

Per project rule (subagent-driven ALWAYS), this plan will be executed via `superpowers:subagent-driven-development`: fresh subagent per task + two-stage review between tasks.
