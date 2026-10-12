# Court & Recs Thread 0 — Correctness Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the eight correctness defects in the Court Planner and Recommendations Manager that block the print-and-catch-up workflow, without adding any new features.

**Architecture:** All DB work goes in `system/lib/ork3/class.Court.php` (and `class.Player.php`); `orkui/model/` is the only membrane between controllers and the lib; controllers never touch `$DB` directly. Each task adds a PHPUnit integration test under `tests/Integration/` backed by a new `tests/Support/CourtFixture.php`, then the minimal implementation. Two tasks are template/CSS-only and are verified in the browser at 390px and 768px.

**Tech Stack:** PHP 8.2, MariaDB, PHPUnit 10 (`vendor/bin/phpunit`), plain-PHP `.tpl` templates, FontAwesome 7, Flatpickr.

**Spec:** `docs/superpowers/specs/2026-09-01-court-recs-workflow-design.md` — read §3 (Thread 0) and §10 (Mobile) before starting. The plan implements Thread 0 only; Threads 1–6 are out of scope.

## Global Constraints

Every task's requirements implicitly include all of these.

- **`.tpl` files are PLAIN PHP, not Smarty.** Use `<?php ?>` / `<?= ?>`. `{$var}` and `{if}` render literally.
- **DB work belongs in `system/lib/ork3/` only.** Controllers call models; models call the lib. `new Domain()` is legal in a model, never in a controller or `.tpl`.
- **Always `$DB->Clear()` before a raw `Execute`/`DataSet`** — stale PDO bindings cause silent save failures.
- **`$DB->DataSet()` needs a manual `->Next()`** before reading fields, or they are null.
- **No native `confirm()`/`alert()`/`prompt()`.** Use the page's own helper (`cpConfirm`/`cpAlert` in Court templates, `tnConfirm` in revised-frontend). Native dialogs freeze the browser automation.
- **Tooltips are `data-tip`, never the native `title` attribute.**
- **Dark mode is required** on every new or changed surface: `html[data-theme="dark"]`.
- **Mobile is blocking:** every changed surface works at 390px and 768px, no horizontal page scroll, no interactive target under 44px.
- **Human-readable dates**: Flatpickr with `altInput: true` and `altFormat: 'F j, Y'`.
- **Controller session accessor is `$this->session->user_id`**, never `$this->__session` (which yields uid 0).
- **NEVER stage `system/lib/ork3/class.Authorization.php`** — it carries a local auth bypass. Stage files explicitly; never `git add -A`.
- **NEVER commit `CLAUDE.md` or `agent-instructions/claude.md`.**
- **Normalize-first before editing any PHP file:** run `awk '/^\t/{c++}END{print c+0}' <file>`. If it prints `0` the file is clean — edit directly. If non-zero, run php-cs-fixer on that file first, commit that separately, then edit.
- **Never `git stash` to A/B test.** It has previously desynced the worktree and staged the forbidden file.

**Running tests:**
```bash
vendor/bin/phpunit --testsuite integration --filter Court
```
Tests skip themselves when the test DB is unavailable (`ork3_test_db_available()`), so a skip is not a pass — confirm the DB is up.

---

## File Structure

**Created:**
- `db-migrations/2026-09-01-court-recorder.sql` — the one schema change (recorder column).
- `tests/Support/CourtFixture.php` — ephemeral court/award/player fixtures for every task's tests. One responsibility: create and clean up court rows.
- `tests/Integration/CourtThread0Test.php` — all Thread 0 lib-level tests. Grouped in one file because they share the fixture and the same domain object.

**Modified:**
- `system/lib/ork3/class.Court.php` — new lib methods (`getUnrecordedCourts`, `updateCourt`, `getDefaultRecorder`), and the `updateAward` signature change.
- `orkui/model/model.Court.php` — thin pass-throughs for the new lib methods.
- `orkui/controller/controller.CourtAjax.php` — new `update_court` action; `update_award` partial-field parsing; `RowVersion` on `grant_award`.
- `orkui/template/default/Court_detail.tpl` — inline court-meta editor, struck-through skipped rows in the printed script, sheet footer URL, `RowVersion` on the grant POST.
- `orkui/template/default/Court_list.tpl` — unrecorded-court warning line.
- `orkui/template/revised-frontend/Recommendations_manage.tpl` — select-all honesty; responsive card collapse.
- `tools/ork-db/manifests/migration-classification.json5` — classify the new migration.

---

## Task 1: Migration — recorder column and its classification

**Files:**
- Create: `db-migrations/2026-09-01-court-recorder.sql`
- Modify: `tools/ork-db/manifests/migration-classification.json5:95-96`

**Interfaces:**
- Consumes: nothing.
- Produces: column `ork_court.recorder_mundane_id INT NULL DEFAULT NULL`, relied on by Tasks 3 and 9.

An unclassified migration file makes `drift-check --strict` block the entire unit-test run, so the classification entry is part of this task, not a follow-up.

- [ ] **Step 1: Write the migration**

Create `db-migrations/2026-09-01-court-recorder.sql`:

```sql
-- Names the officer responsible for recording this court's grants.
-- Defaults to the Prime Minister (Corpora record-keeping responsibility);
-- see docs/superpowers/specs/2026-09-01-court-recs-workflow-design.md 0.7.
ALTER TABLE ork_court
  ADD COLUMN recorder_mundane_id INT NULL DEFAULT NULL AFTER finalized_by;
```

- [ ] **Step 2: Apply it and verify the column exists**

```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-09-01-court-recorder.sql
docker exec -i ork3-php8-db mariadb -u ork -psecret ork -e "SHOW COLUMNS FROM ork_court LIKE 'recorder_mundane_id';"
```
Expected: one row, `Type: int(11)`, `Null: YES`, `Default: NULL`.

- [ ] **Step 3: Classify the migration**

In `tools/ork-db/manifests/migration-classification.json5`, add after the `2026-08-18-session-device-metadata-columns.sql` line (keep the trailing comma correct — the `dev-set-test-logins.php` entry stays last with no comma):

```json5
    "2026-09-01-court-recorder.sql": { "class": "S", "render": "full", "notes": "Names the officer responsible for recording a court's grants" },
```

- [ ] **Step 4: Verify the unit-test gate is not blocked**

```bash
vendor/bin/phpunit --testsuite unit 2>&1 | tail -5
```
Expected: the suite runs to completion (no drift-check abort). A pre-existing `class` catalog-hash failure is a known local-only issue and is acceptable; a *drift-check* abort naming the new migration is not.

- [ ] **Step 5: Commit**

```bash
git add db-migrations/2026-09-01-court-recorder.sql tools/ork-db/manifests/migration-classification.json5
git commit -m "Court: add recorder_mundane_id to ork_court"
```

---

## Task 2: Test fixture for courts

**Files:**
- Create: `tests/Support/CourtFixture.php`

**Interfaces:**
- Consumes: `DB_PREFIX`, `DB_HOSTNAME`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` from the test bootstrap.
- Produces, relied on by every later task's tests:
  - `CourtFixture::create(): self`
  - `firstKingdomId(): int`
  - `firstParkId(int $kingdomId): int`
  - `createPlayer(string $tag, int $kingdomId, int $parkId = 0): array` → `['mundane_id' => int]`
  - `createCourt(array $overrides = []): int` → `court_id`
  - `createAward(int $courtId, int $mundaneId, array $overrides = []): int` → `court_award_id`
  - `insertOfficer(int $mundaneId, int $kingdomId, int $parkId, string $role): void`
  - `fetchCourt(int $courtId): array`
  - `fetchAward(int $courtAwardId): array`
  - `cleanup(): void`

- [ ] **Step 1: Write the fixture**

Create `tests/Support/CourtFixture.php`:

```php
<?php

declare(strict_types=1);

/**
 * Ephemeral DB fixtures for Court Planner Thread 0 tests.
 * Every row created here is tracked and removed by cleanup().
 */
final class CourtFixture
{
    private const MARKER = 'T0CRT';

    /** @var list<int> */
    private array $mundaneIds = [];
    /** @var list<int> */
    private array $courtIds = [];
    /** @var list<int> */
    private array $awardIds = [];
    /** @var list<int> */
    private array $officerIds = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function create(): self
    {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function firstKingdomId(): int
    {
        return (int) $this->pdo->query(
            'SELECT kingdom_id FROM ' . DB_PREFIX . "kingdom WHERE active = 'Active' ORDER BY kingdom_id ASC LIMIT 1"
        )->fetchColumn();
    }

    public function firstParkId(int $kingdomId): int
    {
        return (int) $this->pdo->query(
            'SELECT park_id FROM ' . DB_PREFIX . 'park WHERE kingdom_id = ' . $kingdomId . ' ORDER BY park_id ASC LIMIT 1'
        )->fetchColumn();
    }

    /**
     * Clone an existing mundane row, then override what we care about.
     * ork_mundane has ~15 NOT NULL columns with no defaults (given_name,
     * surname, username, email, token, ...), so a bare three-column INSERT
     * throws. Mirrors KingdomProfileFixture's INSERT ... SELECT approach,
     * building the column list dynamically so it survives schema drift.
     */
    public function createPlayer(string $tag, int $kingdomId, int $parkId = 0): array
    {
        $template = (int) $this->pdo->query(
            'SELECT mundane_id FROM ' . DB_PREFIX . 'mundane ORDER BY mundane_id ASC LIMIT 1'
        )->fetchColumn();

        if ($template <= 0) {
            throw new RuntimeException('No template mundane row to clone.');
        }

        $persona = self::MARKER . '-' . $tag . '-' . bin2hex(random_bytes(3));

        // ork_mundane.username carries a UNIQUE key, so it must be supplied
        // fresh in the INSERT itself — cloning it and fixing it up afterwards
        // fails on the INSERT with "Duplicate entry ... for key 'username'".
        $cols = $this->pdo->query('SHOW COLUMNS FROM ' . DB_PREFIX . 'mundane')
            ->fetchAll(PDO::FETCH_COLUMN, 0);
        $cols = array_values(array_diff($cols, ['mundane_id', 'username', 'persona', 'kingdom_id', 'park_id']));
        $list = '`' . implode('`, `', $cols) . '`';

        $st = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'mundane (`username`, `persona`, `kingdom_id`, `park_id`, ' . $list . ')
             SELECT ?, ?, ?, ?, ' . $list . ' FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ?'
        );
        $st->execute([$persona, $persona, $kingdomId, $parkId, $template]);
        $id = (int) $this->pdo->lastInsertId();
        $this->mundaneIds[] = $id;

        return ['mundane_id' => $id, 'persona' => $persona];
    }

    public function createCourt(array $overrides = []): int
    {
        $row = array_merge([
            'kingdom_id'   => 0,
            'park_id'      => 0,
            'name'         => self::MARKER . '-court',
            'court_date'   => null,
            'status'       => 'published',
            'mode'         => 'plan',
            'created_by'   => 0,
        ], $overrides);

        $st = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'court (kingdom_id, park_id, name, court_date, status, mode, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $row['kingdom_id'], $row['park_id'], $row['name'], $row['court_date'],
            $row['status'], $row['mode'], $row['created_by'],
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->courtIds[] = $id;

        return $id;
    }

    public function createAward(int $courtId, int $mundaneId, array $overrides = []): int
    {
        $row = array_merge([
            'kingdomaward_id' => 1,
            'rank'            => 0,
            'status'          => 'planned',
            'sort_order'      => 0,
            'notes'           => '',
            'public_comment'  => '',
            'pass_to_local'   => 0,
            'scroll_maker_id' => null,
            'regalia_maker_id'=> null,
        ], $overrides);

        $st = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'court_award
             (court_id, mundane_id, kingdomaward_id, rank, status, sort_order, notes, public_comment,
              pass_to_local, scroll_maker_id, regalia_maker_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $courtId, $mundaneId, $row['kingdomaward_id'], $row['rank'], $row['status'],
            $row['sort_order'], $row['notes'], $row['public_comment'], $row['pass_to_local'],
            $row['scroll_maker_id'], $row['regalia_maker_id'],
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->awardIds[] = $id;

        return $id;
    }

    public function insertOfficer(int $mundaneId, int $kingdomId, int $parkId, string $role): void
    {
        // system and authorization_id are NOT NULL without defaults — match
        // ReportsFixture and pass 0 for both.
        $st = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'officer
             (kingdom_id, park_id, mundane_id, role, system, authorization_id)
             VALUES (?, ?, ?, ?, 0, 0)'
        );
        $st->execute([$kingdomId, $parkId, $mundaneId, $role]);
        $this->officerIds[] = (int) $this->pdo->lastInsertId();
    }

    public function fetchCourt(int $courtId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM ' . DB_PREFIX . 'court WHERE court_id = ?');
        $st->execute([$courtId]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function fetchAward(int $courtAwardId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM ' . DB_PREFIX . 'court_award WHERE court_award_id = ?');
        $st->execute([$courtAwardId]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function cleanup(): void
    {
        $this->deleteIn('court_award', 'court_award_id', $this->awardIds);
        $this->deleteIn('court', 'court_id', $this->courtIds);
        $this->deleteIn('officer', 'officer_id', $this->officerIds);
        $this->deleteIn('mundane', 'mundane_id', $this->mundaneIds);
        $this->awardIds = $this->courtIds = $this->officerIds = $this->mundaneIds = [];
    }

    private function deleteIn(string $table, string $pk, array $ids): void
    {
        if (!$ids) {
            return;
        }
        $this->pdo->exec(
            'DELETE FROM ' . DB_PREFIX . $table . ' WHERE ' . $pk . ' IN (' . implode(',', array_map('intval', $ids)) . ')'
        );
    }
}
```

- [ ] **Step 2: Verify the fixture loads and round-trips**

Create a throwaway check (do not commit it) at `tests/Integration/CourtFixtureSmokeTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CourtFixtureSmokeTest extends TestCase
{
    public function testFixtureCreatesAndCleansUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $fx = CourtFixture::create();
        $kid = $fx->firstKingdomId();
        $court = $fx->createCourt(['kingdom_id' => $kid]);
        $this->assertSame($kid, (int) $fx->fetchCourt($court)['kingdom_id']);
        $fx->cleanup();
        $this->assertSame([], $fx->fetchCourt($court));
    }
}
```

Run: `vendor/bin/phpunit --filter CourtFixtureSmoke`
Expected: PASS (or a skip if the DB is down — bring it up rather than accepting the skip).

- [ ] **Step 3: Delete the smoke test and commit the fixture**

```bash
rm tests/Integration/CourtFixtureSmokeTest.php
git add tests/Support/CourtFixture.php
git commit -m "Test: add CourtFixture for Court Planner integration tests"
```

---

## Task 3: 0.5 — surface published courts that were never recorded

**Files:**
- Create: `tests/Integration/CourtThread0Test.php`
- Modify: `system/lib/ork3/class.Court.php`, `orkui/model/model.Court.php`, `orkui/template/default/Court_list.tpl`

**Interfaces:**
- Consumes: `CourtFixture` (Task 2).
- Produces: `Court::getUnrecordedCourts(int $kingdom_id, int $park_id = 0): array` returning rows with keys `CourtId`, `Name`, `CourtDate`, `ParkId`, `DaysSince` (int, `0` when `CourtDate` is null), and `RecorderMundaneId` (int, `0` when unset). Model pass-through `Model_Court->get_unrecorded_courts()`. **Task 10 consumes `RecorderMundaneId` from these rows** — there is no single-court getter on the lib, so this column must be selected here.

Task 1 must be complete before this runs: the SELECT references `recorder_mundane_id`.

The predicate must include NULL dates: an undated court is exactly the case 0.1 protects, and a predicate keyed only on "in the past" would leave the document's worst case invisible.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/CourtThread0Test.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Thread 0 correctness fixes — see
 * docs/superpowers/specs/2026-09-01-court-recs-workflow-design.md §3.
 */
final class CourtThread0Test extends TestCase
{
    private CourtFixture $fixture;
    private Court $court;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->fixture = CourtFixture::create();
        $this->court = new Court();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testUnrecordedCourtsIncludesPastDatedAndUndated(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('unrec', $kid);

        $past = $this->fixture->createCourt(['kingdom_id' => $kid, 'court_date' => '2026-01-01']);
        $this->fixture->createAward($past, $player['mundane_id']);

        $undated = $this->fixture->createCourt(['kingdom_id' => $kid, 'court_date' => null]);
        $this->fixture->createAward($undated, $player['mundane_id']);

        $ids = array_column($this->court->getUnrecordedCourts($kid), 'CourtId');

        $this->assertContains($past, $ids, 'A past dated court with nothing recorded must be surfaced.');
        $this->assertContains($undated, $ids, 'An undated court with nothing recorded must be surfaced.');
    }

    public function testUnrecordedCourtsExcludesPartlyRecordedAndFuture(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('rec', $kid);

        $partly = $this->fixture->createCourt(['kingdom_id' => $kid, 'court_date' => '2026-01-01']);
        $this->fixture->createAward($partly, $player['mundane_id'], ['status' => 'staged']);

        $future = $this->fixture->createCourt(['kingdom_id' => $kid, 'court_date' => '2099-01-01']);
        $this->fixture->createAward($future, $player['mundane_id']);

        $draft = $this->fixture->createCourt(['kingdom_id' => $kid, 'court_date' => '2026-01-01', 'status' => 'draft']);
        $this->fixture->createAward($draft, $player['mundane_id']);

        $ids = array_column($this->court->getUnrecordedCourts($kid), 'CourtId');

        $this->assertNotContains($partly, $ids, 'A court with a staged row is being recorded — do not nag.');
        $this->assertNotContains($future, $ids, 'A court that has not happened yet is not overdue.');
        $this->assertNotContains($draft, $ids, 'A draft court was never published.');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter CourtThread0Test`
Expected: FAIL with `Call to undefined method Court::getUnrecordedCourts()`.

- [ ] **Step 3: Implement the lib method**

Normalize-check `system/lib/ork3/class.Court.php` first (`awk '/^\t/{c++}END{print c+0}'`). Add to `class.Court.php`:

```php
    /**
     * Published courts in this scope with nothing recorded yet — the silence
     * failure mode of the print-and-catch-up workflow (spec 0.5).
     *
     * Includes undated courts: an undated court is the exact case that stamps
     * every award with the catch-up day, so it must never be invisible here.
     */
    public function getUnrecordedCourts($kingdom_id, $park_id = 0)
    {
        $kingdom_id = (int)$kingdom_id;
        $park_id    = (int)$park_id;
        if (!valid_id($kingdom_id)) {
            return [];
        }

        $scope = $park_id > 0
            ? 'c.park_id = ' . $park_id
            : 'c.kingdom_id = ' . $kingdom_id;

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT c.court_id, c.name, c.court_date, c.park_id, c.recorder_mundane_id,
                    CASE WHEN c.court_date IS NULL OR c.court_date = \'0000-00-00\'
                         THEN 0 ELSE DATEDIFF(CURDATE(), c.court_date) END AS days_since
               FROM ' . DB_PREFIX . 'court c
              WHERE c.status = \'published\'
                AND ' . $scope . '
                AND (c.court_date IS NULL OR c.court_date = \'0000-00-00\' OR c.court_date < CURDATE())
                AND NOT EXISTS (
                    SELECT 1 FROM ' . DB_PREFIX . 'court_award ca
                     WHERE ca.court_id = c.court_id
                       AND ca.status IN (\'staged\', \'given\')
                )
              ORDER BY c.court_date IS NULL DESC, c.court_date ASC'
        );

        $out = [];
        if ($rs) {
            while ($rs->Next()) {
                $out[] = [
                    'CourtId'   => (int)$rs->court_id,
                    'Name'      => $rs->name,
                    'CourtDate' => $rs->court_date,
                    'ParkId'    => (int)$rs->park_id,
                    'DaysSince' => (int)$rs->days_since,
                    'RecorderMundaneId' => (int)$rs->recorder_mundane_id,
                ];
            }
        }

        return $out;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter CourtThread0Test`
Expected: PASS, 2 tests.

- [ ] **Step 5: Add the model pass-through**

In `orkui/model/model.Court.php`:

```php
    public function get_unrecorded_courts($kingdom_id, $park_id = 0)
    {
        return $this->Court->getUnrecordedCourts($kingdom_id, $park_id);
    }
```

(Match the file's existing property name for the domain object — read the top of the class and use whatever it already calls the `Court` instance.)

- [ ] **Step 6: Surface it on the court list**

In `orkui/controller/controller.Court.php::list()`, pass `$UnrecordedCourts` to the view from the model. Then in `orkui/template/default/Court_list.tpl`, above the court table:

```php
<?php if (!empty($UnrecordedCourts)): ?>
<div class="cp-unrecorded-banner" role="status">
    <i class="fas fa-exclamation-triangle"></i>
    <div>
        <?php foreach ($UnrecordedCourts as $uc): ?>
        <div class="cp-unrec-line">
            <a href="<?= UIR ?>Court/detail/<?= (int)$uc['CourtId'] ?>"><?= htmlspecialchars($uc['Name']) ?></a>
            <?php if (empty($uc['CourtDate'])): ?>
                &mdash; published with no date set, and nothing recorded yet.
            <?php else: ?>
                &mdash; held <?= (int)$uc['DaysSince'] ?> day<?= (int)$uc['DaysSince'] === 1 ? '' : 's' ?> ago, nothing recorded yet.
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
```

Add CSS near the other `cp-` rules, with a dark-mode block:

```css
.cp-unrecorded-banner { display:flex; gap:10px; align-items:flex-start; background:#fffaf0; border:1px solid #dd6b20; border-radius:6px; padding:10px 14px; margin-bottom:14px; color:#7b341e; font-size:14px; }
.cp-unrecorded-banner i { margin-top:2px; color:#dd6b20; }
.cp-unrec-line + .cp-unrec-line { margin-top:4px; }
html[data-theme="dark"] .cp-unrecorded-banner { background:#2d1b0e; border-color:#dd6b20; color:#fbd38d; }
```

- [ ] **Step 7: Verify in the browser**

Log in (any password; `heraldsbridge` is admin), open `index.php?Route=Court/list/kingdom/<id>` for a kingdom with a past published court that has no staged rows. Confirm the banner renders, the link works, dark mode is legible, and the layout holds at 390px.

- [ ] **Step 8: Commit**

```bash
git add tests/Support/CourtFixture.php tests/Integration/CourtThread0Test.php system/lib/ork3/class.Court.php orkui/model/model.Court.php orkui/controller/controller.Court.php orkui/template/default/Court_list.tpl
git commit -m "Court: surface published courts with nothing recorded (spec 0.5)"
```

---

## Task 4: 0.1 — make a court's date, name, event and recorder editable

**Files:**
- Modify: `system/lib/ork3/class.Court.php`, `orkui/model/model.Court.php`, `orkui/controller/controller.CourtAjax.php`, `orkui/template/default/Court_detail.tpl`, `tests/Integration/CourtThread0Test.php`

**Interfaces:**
- Consumes: `CourtFixture`, `ork_court.recorder_mundane_id` (Task 1).
- Produces: `Court::updateCourt(int $court_id, array $fields): bool` accepting keys `Name`, `CourtDate`, `EventCalendarDetailId`, `RecorderMundaneId`; endpoint `CourtAjax/update_court`. Task 9 sets the recorder through this endpoint.

`commitStagedAward` reads `CourtDate` at commit time, so a date corrected while `published` re-dates every not-yet-finalized row. That is the intended behavior and the test below pins it.

- [ ] **Step 1: Write the failing tests**

Append to `CourtThread0Test`:

```php
    public function testUpdateCourtWritesOnlyProvidedFields(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $courtId = $this->fixture->createCourt([
            'kingdom_id' => $kid,
            'name'       => 'T0CRT-original',
            'court_date' => null,
        ]);

        $ok = $this->court->updateCourt($courtId, ['CourtDate' => '2026-09-12']);
        $this->assertTrue($ok);

        $row = $this->fixture->fetchCourt($courtId);
        $this->assertSame('2026-09-12', $row['court_date']);
        $this->assertSame('T0CRT-original', $row['name'], 'A date-only update must not rewrite the name.');
    }

    public function testUpdateCourtIsRefusedOnCompleteCourts(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $courtId = $this->fixture->createCourt([
            'kingdom_id' => $kid,
            'status'     => 'complete',
            'court_date' => '2026-01-01',
        ]);

        $this->assertFalse($this->court->updateCourt($courtId, ['CourtDate' => '2026-09-12']));
        $this->assertSame('2026-01-01', $this->fixture->fetchCourt($courtId)['court_date']);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter CourtThread0Test`
Expected: FAIL with `Call to undefined method Court::updateCourt()`.

- [ ] **Step 3: Implement the lib method**

```php
    /**
     * Edit a court's own metadata (spec 0.1). Permitted in draft and published;
     * refused once complete, because finalized rows already carry the date.
     *
     * Partial by design: only the keys supplied are written.
     */
    public function updateCourt($court_id, array $fields)
    {
        $court_id = (int)$court_id;
        if (!valid_id($court_id) || !$fields) {
            return false;
        }

        $map = [
            'Name'                  => 'name',
            'CourtDate'             => 'court_date',
            'EventCalendarDetailId' => 'event_calendardetail_id',
            'RecorderMundaneId'     => 'recorder_mundane_id',
        ];

        $sets = [];
        foreach ($map as $key => $column) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $value = $fields[$key];
            if ($column === 'name') {
                $sets[] = 'name = \'' . $this->esc((string)$value) . '\'';
            } elseif ($column === 'court_date') {
                $sets[] = $value === null || $value === ''
                    ? 'court_date = NULL'
                    : 'court_date = \'' . $this->esc((string)$value) . '\'';
            } else {
                $sets[] = $column . ' = ' . ((int)$value > 0 ? (int)$value : 'NULL');
            }
        }

        if (!$sets) {
            return false;
        }

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'UPDATE ' . DB_PREFIX . 'court SET ' . implode(', ', $sets) . '
              WHERE court_id = ' . $court_id . ' AND status <> \'complete\''
        );

        return $rs && $rs->Size() >= 1;
    }
```

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/phpunit --filter CourtThread0Test`
Expected: PASS, 4 tests.

- [ ] **Step 5: Add the model pass-through and the endpoint**

Model:

```php
    public function update_court($court_id, array $fields)
    {
        return $this->Court->updateCourt($court_id, $fields);
    }
```

In `controller.CourtAjax.php`, alongside `update_court_status`:

```php
    public function update_court($p = null)
    {
        $court_id = (int)($_POST['CourtId'] ?? 0);
        if (!valid_id($court_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid court.']);
        }

        $this->requireCourtAuth($court_id);

        // Partial: only the keys the client actually sent are written.
        $fields = [];
        foreach (['Name', 'CourtDate', 'EventCalendarDetailId', 'RecorderMundaneId'] as $key) {
            if (array_key_exists($key, $_POST)) {
                $fields[$key] = $_POST[$key];
            }
        }

        if (!$fields) {
            $this->jsonOut(['status' => 1, 'error' => 'Nothing to update.']);
        }

        if (!$this->Court->update_court($court_id, $fields)) {
            $this->jsonOut(['status' => 1, 'error' => 'This court could not be updated. A completed court cannot be edited.']);
        }

        $this->jsonOut(['status' => 0]);
    }
```

- [ ] **Step 6: Add the inline hero editor**

In `Court_detail.tpl`, make the hero date and name editable when `$courtSt !== 'complete'`. Add an "Edit court details" button in `.cp-hero-actions` that opens a small modal (`cp-courtmeta-modal`) with Name, Date, and Event fields, following the existing `cp-adhoc-modal` markup pattern. The Date field uses Flatpickr with `altInput: true, altFormat: 'F j, Y'` per the global constraint. Submit posts to `CourtAjax/update_court` and reloads on `status === 0`.

- [ ] **Step 7: Verify in the browser**

Create a court with no date, open it, set a date via the new editor, confirm the hero updates and `SELECT court_date FROM ork_court WHERE court_id = ...` shows the new value. Confirm the button is absent on a completed court. Check 390px.

- [ ] **Step 8: Commit**

```bash
git add tests/Integration/CourtThread0Test.php system/lib/ork3/class.Court.php orkui/model/model.Court.php orkui/controller/controller.CourtAjax.php orkui/template/default/Court_detail.tpl
git commit -m "Court: allow editing a court's name, date, event and recorder (spec 0.1)"
```

---

## Task 5: 0.6 — make `update_award` a genuine partial update

**Files:**
- Modify: `system/lib/ork3/class.Court.php:507-530`, `orkui/controller/controller.CourtAjax.php:303-350`, `orkui/template/default/Court_detail.tpl` (`cpSaveAward`), `tests/Integration/CourtThread0Test.php`

**Interfaces:**
- Consumes: `CourtFixture`.
- Produces: `Court::updateAward(int $court_award_id, array $fields, ?int $expectedRowVersion = null): bool` where `$fields` may contain any of `Notes`, `PublicComment`, `PassToLocal`, `ScrollMakerId`, `RegaliaMakerId`. **This replaces the six-positional-parameter signature.** Threads 2 and 5 both depend on this.

This is the Critical defect: today the method writes all five columns unconditionally and the controller defaults absent POST keys to `''`/`0`, so any partial caller silently erases internal notes, the pass-to-local decision, and both maker credits.

- [ ] **Step 1: Write the failing test**

Append to `CourtThread0Test`:

```php
    public function testUpdateAwardLeavesOmittedFieldsIntact(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('partial', $kid);
        $maker  = $this->fixture->createPlayer('maker', $kid);
        $courtId = $this->fixture->createCourt(['kingdom_id' => $kid]);

        $awardId = $this->fixture->createAward($courtId, $player['mundane_id'], [
            'notes'           => 'hold until the drama settles',
            'pass_to_local'   => 1,
            'scroll_maker_id' => $maker['mundane_id'],
        ]);

        // A citation-only save, as the Record Court view will make.
        $this->assertTrue($this->court->updateAward($awardId, ['PublicComment' => 'For steadfast service.']));

        $row = $this->fixture->fetchAward($awardId);
        $this->assertSame('For steadfast service.', $row['public_comment']);
        $this->assertSame('hold until the drama settles', $row['notes'], 'Internal notes must survive a citation-only save.');
        $this->assertSame(1, (int) $row['pass_to_local'], 'Pass-to-local must survive a citation-only save.');
        $this->assertSame(
            $maker['mundane_id'],
            (int) $row['scroll_maker_id'],
            'The scroll maker credit must survive a citation-only save.'
        );
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter CourtThread0Test`
Expected: FAIL — either an ArgumentCountError, or assertion failures showing `notes` empty and `scroll_maker_id` null.

- [ ] **Step 3: Replace the lib method**

Replace `Court::updateAward` (`class.Court.php:507-530`) with:

```php
    /**
     * Field-only edit of a court award (never status — QW#4).
     *
     * PARTIAL by design (spec 0.6): only the keys present in $fields are
     * written. The previous six-positional-parameter version wrote all five
     * columns unconditionally, so any caller that did not send every field
     * silently erased internal notes, pass-to-local, and both maker credits.
     */
    public function updateAward($court_award_id, array $fields, $expectedRowVersion = null)
    {
        $court_award_id = (int)$court_award_id;
        if (!valid_id($court_award_id) || !$fields) {
            return false;
        }

        $sets = [];
        if (array_key_exists('Notes', $fields)) {
            $sets[] = 'notes = \'' . $this->esc((string)$fields['Notes']) . '\'';
        }
        if (array_key_exists('PublicComment', $fields)) {
            $sets[] = 'public_comment = \'' . $this->esc((string)$fields['PublicComment']) . '\'';
        }
        if (array_key_exists('PassToLocal', $fields)) {
            $sets[] = 'pass_to_local = ' . ((int)$fields['PassToLocal'] ? 1 : 0);
        }
        if (array_key_exists('ScrollMakerId', $fields)) {
            $sets[] = 'scroll_maker_id = ' . ((int)$fields['ScrollMakerId'] > 0 ? (int)$fields['ScrollMakerId'] : 'NULL');
        }
        if (array_key_exists('RegaliaMakerId', $fields)) {
            $sets[] = 'regalia_maker_id = ' . ((int)$fields['RegaliaMakerId'] > 0 ? (int)$fields['RegaliaMakerId'] : 'NULL');
        }

        if (!$sets) {
            return false;
        }

        $sets[] = 'row_version = row_version + 1';

        $where = 'court_award_id = ' . $court_award_id;
        if ($expectedRowVersion !== null) {
            $where .= ' AND row_version = ' . (int)$expectedRowVersion;
        }

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'UPDATE ' . DB_PREFIX . 'court_award SET ' . implode(', ', $sets) . ' WHERE ' . $where
        );

        return $rs && $rs->Size() == 1;
    }
```

- [ ] **Step 4: Update the controller to send only present keys**

In `controller.CourtAjax.php::update_award`, replace the five `?? ''` / `?? 0` reads and the positional call with:

```php
        $fields = [];
        if (array_key_exists('Notes', $_POST)) {
            $fields['Notes'] = trim((string)$_POST['Notes']);
        }
        if (array_key_exists('PublicComment', $_POST)) {
            $fields['PublicComment'] = trim((string)$_POST['PublicComment']);
        }
        if (array_key_exists('PassToLocal', $_POST)) {
            $fields['PassToLocal'] = (int)$_POST['PassToLocal'] ? 1 : 0;
        }
        if (array_key_exists('ScrollMakerId', $_POST)) {
            $fields['ScrollMakerId'] = (int)$_POST['ScrollMakerId'];
        }
        if (array_key_exists('RegaliaMakerId', $_POST)) {
            $fields['RegaliaMakerId'] = (int)$_POST['RegaliaMakerId'];
        }

        if (!$fields) {
            $this->jsonOut(['status' => 1, 'error' => 'Nothing to update.']);
        }

        $expectedRowVersion = (isset($_POST['RowVersion']) && $_POST['RowVersion'] !== '')
            ? (int)$_POST['RowVersion'] : null;

        $ok = $this->Court->update_award($court_award_id, $fields, $expectedRowVersion);
```

Update the success payload to echo back only what was written:

```php
        $this->jsonOut(array_merge(['status' => 0], $fields));
```

Update `Model_Court->update_award()` to the new `(int $court_award_id, array $fields, $expectedRowVersion = null)` signature.

- [ ] **Step 5: Update the one existing caller**

`cpSaveAward` in `Court_detail.tpl` already sends all five fields, so it keeps working unchanged. Confirm this by reading it — if it omits any field, that field must be added, because omission now means "leave alone" rather than "clear".

Search for any other caller before finishing:
```bash
grep -rn "update_award" orkui/ system/ --include=*.php --include=*.tpl
```
Every hit must either send all five fields or intend partial semantics.

- [ ] **Step 6: Run tests**

Run: `vendor/bin/phpunit --filter CourtThread0Test`
Expected: PASS, 5 tests.

- [ ] **Step 7: Verify in the browser**

Open a court, expand an award, set an internal note and a scroll maker, Save. Reopen, change only the public comment, Save. Confirm via SQL that `notes` and `scroll_maker_id` survived:
```bash
docker exec -i ork3-php8-db mariadb -u ork -psecret ork -e "SELECT notes, public_comment, pass_to_local, scroll_maker_id FROM ork_court_award WHERE court_award_id = <id>;"
```

- [ ] **Step 8: Commit**

```bash
git add tests/Integration/CourtThread0Test.php system/lib/ork3/class.Court.php orkui/model/model.Court.php orkui/controller/controller.CourtAjax.php orkui/template/default/Court_detail.tpl
git commit -m "Court: make update_award a partial field write (spec 0.6)"
```

---

## Task 6: 0.8 — responsive card collapse for the Recommendations Manager

**Files:**
- Modify: `orkui/template/revised-frontend/Recommendations_manage.tpl`, `orkui/template/revised-frontend/_rm_row.tpl`

**Interfaces:**
- Consumes: nothing.
- Produces: an `rm-` card layout below 700px. Threads 3, 4 and 6 extend it rather than owning it.

Presentation only — no JS behavior changes, no endpoint changes. The page has **zero** `@media` blocks today against a ten-column grid.

- [ ] **Step 1: Confirm the starting state**

```bash
grep -c "@media" orkui/template/revised-frontend/Recommendations_manage.tpl
```
Expected: `0`.

- [ ] **Step 2: Add the card collapse CSS**

Append to the template's inline `<style>` block:

```css
/* The overflow toggle and its help text are mobile-only — they must be hidden
   at base, or they leak onto the desktop table. */
.rm-act-more { display: none; }
.rm-act-help { display: none; }

@media (max-width: 700px) {
  .rm-grid thead { display: none; }
  .rm-grid, .rm-grid tbody, .rm-grid tr, .rm-grid td { display: block; width: 100%; }
  .rm-grid tr.rm-row {
    border: 1px solid var(--rm-line); border-radius: 8px; margin-bottom: 10px;
    padding: 10px 12px; background: var(--rm-bg); position: relative;
  }
  .rm-grid tr.rm-row td { border: none; padding: 2px 0; }
  /* Tap area is 44px; the glyph stays 22px. House pattern, stated at
     Court_detail.tpl:1058 — "coarse pointers get >=44px hit area (padding,
     not larger glyphs)". A literally 44px checkbox looks broken. */
  .rm-col-sel { position: absolute; top: 2px; right: 2px; padding: 11px; min-width: 44px; min-height: 44px; box-sizing: border-box; display: flex; align-items: center; justify-content: center; }
  .rm-col-sel input { width: 22px; height: 22px; }
  .rm-col-recip { font-size: 16px; font-weight: 700; padding-right: 40px !important; }
  .rm-col-park::before  { content: none; }
  .rm-col-award { font-size: 14px; }
  .rm-col-rank, .rm-col-rec, .rm-col-supp { display: inline-block !important; width: auto !important; margin-right: 10px; }
  .rm-col-reason { color: var(--rm-muted); font-size: 13px; }

  /* Actions: primary two inline, the rest behind an overflow toggle. */
  .rm-col-act { display: flex !important; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
  .rm-col-act .rm-act { min-height: 44px; min-width: 44px; flex: 0 0 auto; padding: 0 14px; }
  .rm-col-act .rm-act-grant, .rm-col-act .rm-act-court { flex: 1 1 auto; }
  .rm-col-act .rm-act-snooze,
  .rm-col-act .rm-act-passlocal,
  .rm-col-act .rm-act-dismiss { display: none; }
  .rm-col-act.rm-act-open .rm-act-snooze,
  .rm-col-act.rm-act-open .rm-act-passlocal,
  .rm-col-act.rm-act-open .rm-act-dismiss { display: inline-flex; }
  .rm-act-more { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; min-width: 44px; }

  /* Hover-only tooltips have no home on a phone — show the copy inline. */
  .rm-act .rm-snooze-tip,
  .rm-act .rm-passlocal-tip { position: static; display: none; }
  .rm-col-act.rm-act-open .rm-act-help { display: block; font-size: 12px; color: var(--rm-muted); margin-top: 6px; flex: 1 1 100%; }

  .rm-filterbar { flex-wrap: wrap; gap: 8px; }
  .rm-filterbar .rm-fsel, .rm-filterbar .rm-search { flex: 1 1 100%; min-height: 44px; }
  .rm-bulkbar { position: fixed; left: 0; right: 0; bottom: 0; border-radius: 0; padding: 10px 12px calc(10px + env(safe-area-inset-bottom)); flex-wrap: wrap; }
  .rm-bulkbar .rm-bulk { min-height: 44px; }
}
@media (max-width: 700px) {
  html[data-theme="dark"] .rm-grid tr.rm-row { background: var(--rm-bg2); }
}
```

- [ ] **Step 3: Add the overflow toggle and the inline help text**

In `_rm_row.tpl`, inside `<td class="rm-col-act">`, after the dismiss button, add:

```php
          <button type="button" class="rm-act rm-act-more" data-tip="More actions" aria-label="More actions" aria-expanded="false">&hellip;</button>
          <div class="rm-act-help" hidden>
            <strong>Snooze</strong> sets this aside until the Monarch or Regent changes.
            <?php if (($Context ?? '') === 'kingdom') { ?><strong>Pass down</strong> grants the local park authority to award at this level. <?php } ?>
            <strong>Dismiss</strong> removes it from the pending list.
          </div>
```

In the template's JS, add the toggle near the other `rm-tbody` click handlers:

```javascript
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var more = e.target.closest('.rm-act-more');
    if (!more) return;
    var cell = more.closest('.rm-col-act');
    var open = cell.classList.toggle('rm-act-open');
    more.setAttribute('aria-expanded', open ? 'true' : 'false');
    var help = cell.querySelector('.rm-act-help');
    if (help) help.hidden = !open;
});
```

- [ ] **Step 4: Verify at both widths**

Open `index.php?Route=Recommendations/manage/kingdom/<id>`. At 390px confirm: cards render, no horizontal page scroll, checkbox and every action ≥44px, the `…` toggle reveals snooze/pass-down/dismiss **with** the explanatory sentences, and dark mode is legible. At 768px and at desktop width confirm the table layout is unchanged.

Measure the actual rendered widths rather than trusting `resize_window`, which is frequently a no-op — load the page in a same-origin iframe sized to 390px and read `getBoundingClientRect()`, filtering to elements with `offsetParent !== null`.

- [ ] **Step 5: Commit**

```bash
git add orkui/template/revised-frontend/Recommendations_manage.tpl orkui/template/revised-frontend/_rm_row.tpl
git commit -m "Recs Manager: responsive card collapse below 700px (spec 0.8)"
```

---

## Task 7: 0.2 — stop select-all implying it selected more than it did

**Files:**
- Modify: `orkui/template/revised-frontend/Recommendations_manage.tpl` (`rmUpdateSelCount`, the footer, the `rm-selall` handler)

**Interfaces:**
- Consumes: existing `rmState.total`, `rmState.hasMore`, `rmLoadedRows()`.
- Produces: honest footer and bulk-bar copy. Thread 4's criteria-mode banner later replaces this copy.

Filtering and paging are server-side in 500-row batches, but `rm-selall` checks only loaded rows — so on a 1,240-row filtered set it silently means 500.

- [ ] **Step 1: Make the footer state the loaded/total split**

Replace the footer markup:

```php
  <div class="rm-foot">
    Showing <span id="rm-count"><?= count($Groups) ?></span> of <span id="rm-total"><?= (int)($Total ?? 0) ?></span>
    <span id="rm-loadnote" class="rm-loadnote"></span>
    &middot; <span id="rm-selcount">0</span> selected
  </div>
```

Add CSS with a dark-mode rule:

```css
.rm-loadnote { color: var(--rm-muted); font-style: italic; }
html[data-theme="dark"] .rm-loadnote { color: var(--rm-muted); }
```

- [ ] **Step 2: Update the count renderer**

Extend `rmUpdateSelCount`:

```javascript
function rmUpdateSelCount() {
    var n = rmSelected().length;
    document.getElementById('rm-selcount').textContent = n;
    var bar = document.getElementById('rm-bulkbar');
    bar.hidden = n === 0;

    // Say plainly that selection covers loaded rows only while more remain.
    var partial = !!rmState.hasMore;
    document.getElementById('rm-bulklabel').textContent =
        partial ? n + ' selected (loaded rows)' : n + ' selected';

    var note = document.getElementById('rm-loadnote');
    if (note) {
        note.textContent = partial
            ? '— select-all covers loaded rows only'
            : '';
    }
}
```

- [ ] **Step 3: Label the header checkbox**

```php
        <th class="rm-col-sel"><input type="checkbox" id="rm-selall" aria-label="Select all loaded rows" data-tip="Selects the rows loaded so far, not every row matching your filters"></th>
```

- [ ] **Step 4: Verify**

Load a scope with more than 500 pending recommendations (or temporarily lower the page size to force `hasMore`). Confirm the footer reads `Showing 500 of 1240 — select-all covers loaded rows only`, that checking the header box shows `500 selected (loaded rows)`, and that once everything is scrolled in the qualifier disappears. Confirm the tooltip uses `data-tip` and not `title`.

- [ ] **Step 5: Commit**

```bash
git add orkui/template/revised-frontend/Recommendations_manage.tpl
git commit -m "Recs Manager: select-all states that it covers loaded rows only (spec 0.2)"
```

---

## Task 8: 0.3 — print skipped awards struck through instead of dropping them

**Files:**
- Modify: `orkui/template/default/Court_detail.tpl` (`cpScriptActiveAwards`, `cpScriptCompact`, `cpScriptCitation`, print CSS)

**Interfaces:**
- Consumes: `window.courtAwards`.
- Produces: printed sheets that include cancelled rows, marked skipped. Thread 1 builds its three sheets on these builders.

`cpScriptActiveAwards()` filters `Status !== 'cancelled'`, so a reprint after a partial session silently drops previously-skipped rows — while the Complete modal promises they "resurface on the next court." Resurfacing is delivered by `prepopulate_from_last_court`, which only pulls from a **completed** court, so a published-and-abandoned court contributes nothing; this is why 0.5 matters.

- [ ] **Step 1: Stop filtering cancelled rows**

```javascript
    // Skipped rows stay on the sheet, struck through: a reprint that silently
    // dropped them would remove the only prompt to reconsider them (spec 0.3).
    function cpScriptActiveAwards() {
        return (window.courtAwards || []);
    }
```

- [ ] **Step 2: Mark them in the compact sheet**

In `cpScriptCompact`, replace the row builder:

```javascript
        var rows = awards.map(function (a, i) {
            var skipped = a.Status === 'cancelled';
            return '<tr' + (skipped ? ' class="cp-script-skipped"' : '') + '>' +
                '<td class="cp-script-num">' + (i + 1) + '</td>' +
                '<td class="cp-script-check">' + (a.Status === 'given' || a.Status === 'staged' ? '☑' : '☐') + '</td>' +
                '<td class="cp-script-recip">' + cpScriptRecipient(a) + '</td>' +
                '<td class="cp-script-award">' + cpScriptAwardLabel(a) + cpScriptPtlMark(a) +
                    (skipped ? ' <span class="cp-script-skipmark">(skipped)</span>' : '') + '</td>' +
                '</tr>';
        }).join('');
```

- [ ] **Step 3: Mark them in the citation sheet**

In `cpScriptCitation`, change the wrapper and head:

```javascript
            var skipped = a.Status === 'cancelled';
            var html = '<div class="cp-script-cite' + (skipped ? ' cp-script-skipped' : '') + '">' +
                '<div class="cp-script-cite-head">' +
                    '<span class="cp-script-cite-num">' + (i + 1) + '.</span> ' +
                    '<span class="cp-script-cite-recip">' + cpScriptRecipient(a) + '</span> ' +
                    '<span class="cp-script-cite-award">' + cpScriptAwardLabel(a) + cpScriptPtlMark(a) + '</span>' +
                    (skipped ? ' <span class="cp-script-skipmark">(skipped)</span>' : '') +
                '</div>';
```

- [ ] **Step 4: Style it for screen and paper**

Add near the other `.cp-script-` rules:

```css
.cp-script-skipped { opacity: .65; }
.cp-script-skipped .cp-script-recip,
.cp-script-skipped .cp-script-award,
.cp-script-skipped .cp-script-cite-recip,
.cp-script-skipped .cp-script-cite-award { text-decoration: line-through; }
.cp-script-skipmark { font-size: 11px; font-style: italic; color: #718096; text-decoration: none; }
```

Inside the existing `@media print` block, keep them legible on paper:

```css
    body.cp-script-open .cp-script-skipped { opacity: 1; color: #000; }
    body.cp-script-open .cp-script-skipmark { color: #000; }
```

- [ ] **Step 5: Verify**

On a court with at least one skipped award, open the script overlay in both densities. Confirm the skipped row appears, struck through, marked `(skipped)`, in both. Use the browser's print preview (do **not** trigger a native dialog that blocks automation — use print preview or a PDF export) and confirm the row is legible on paper.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/default/Court_detail.tpl
git commit -m "Court: print skipped awards struck through rather than omitting them (spec 0.3)"
```

---

## Task 9: 0.4 — thread `RowVersion` through `grant_award`

**Files:**
- Modify: `orkui/model/model.Court.php` (`stage_award`), `system/lib/ork3/class.Court.php` (`stageAward`), `orkui/controller/controller.CourtAjax.php:428-472`, `orkui/template/default/Court_detail.tpl` (`cpGrantConfirm`), `tests/Integration/CourtThread0Test.php`

**Interfaces:**
- Consumes: `CourtFixture`.
- Produces: `Court::stageAward(..., ?int $expectedRowVersion = null): bool` and a `grant_award` endpoint that returns `status: 9` on a stale token, matching `skip_award`.

`skip_award` and `set_award_status` thread the optimistic lock; `grant_award` does not, and two officers recording from two annotated printouts is an explicitly supported scenario.

- [ ] **Step 1: Write the failing test**

Append to `CourtThread0Test`:

```php
    public function testStageAwardRejectsStaleRowVersion(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('stale', $kid);
        $giver  = $this->fixture->createPlayer('giver', $kid);
        $courtId = $this->fixture->createCourt(['kingdom_id' => $kid]);
        $awardId = $this->fixture->createAward($courtId, $player['mundane_id']);

        $before = (int) $this->fixture->fetchAward($awardId)['row_version'];

        // Signature is stageAward($court_award_id, $given_by_mundane_id, $public_comment, $rank)
        // with $expectedRowVersion appended by this task as the 5th parameter.
        // First writer wins and bumps row_version.
        $this->assertTrue($this->court->stageAward($awardId, $giver['mundane_id'], '', 0, $before));

        // Return the row to an eligible status so the ONLY thing that can reject
        // the next call is the stale row_version. Without this, stageAward's own
        // `status NOT IN ('given','cancelled','staged')` guard rejects the second
        // call by itself, and the test would pass even if the row_version
        // predicate were deleted entirely.
        $this->court->unstageAward($awardId);

        // Second writer holds the now-stale token and must be refused.
        $this->assertFalse($this->court->stageAward($awardId, $giver['mundane_id'], '', 0, $before));

        $this->assertSame('staged', $this->fixture->fetchAward($awardId)['status']);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter CourtThread0Test`
Expected: FAIL — the stale second call currently succeeds (returns true), because no version predicate exists.

- [ ] **Step 3: Add the parameter to the lib and model**

The current signature is `Court::stageAward($court_award_id, $given_by_mundane_id, $public_comment, $rank)` (`class.Court.php:690`) and `Model_Court::stage_award($court_award_id, $given_by_mundane_id, $public_comment, $rank)` (`model.Court.php:133`). Append `$expectedRowVersion = null` as the fifth parameter on both. Inside, extend the WHERE exactly as `skip_award` does:

```php
        $where = 'court_award_id = ' . $court_award_id . ' AND status <> \'given\'';
        if ($expectedRowVersion !== null) {
            $where .= ' AND row_version = ' . (int)$expectedRowVersion;
        }
```

Ensure the UPDATE already contains `row_version = row_version + 1`; add it if not. Mirror the parameter onto `Model_Court->stage_award()`.

- [ ] **Step 4: Accept and honor the token in the controller**

In `CourtAjax::grant_award`, mirroring `skip_award`:

```php
        $expectedRowVersion = (isset($_POST['RowVersion']) && $_POST['RowVersion'] !== '')
            ? (int)$_POST['RowVersion'] : null;
```

Pass it through to `stage_award`, and on failure with a token present:

```php
            if ($expectedRowVersion !== null) {
                $this->jsonOut(['status' => 9, 'stale' => true, 'message' => 'This row changed — reload.']);
            }
```

- [ ] **Step 5: Send the token from the client**

In `cpGrantConfirm` in `Court_detail.tpl`, add the row's current version to the FormData, matching how the skip path does it:

```javascript
        var aw = courtAwards.filter(function (a) { return a.CourtAwardId === caid; })[0];
        if (aw && typeof aw.RowVersion !== 'undefined') {
            fd.append('RowVersion', aw.RowVersion);
        }
```

Confirm `RowVersion` is present on the `courtAwards` payload; if the controller does not already expose it, add it to the row projection the detail page serializes.

- [ ] **Step 6: Run tests**

Run: `vendor/bin/phpunit --filter CourtThread0Test`
Expected: PASS, 6 tests.

- [ ] **Step 7: Verify two-officer behavior in the browser**

Open the same published court in two tabs. Grant an award in tab A. In tab B (whose payload is now stale) grant the same award. Expected: tab B shows the non-destructive "this row changed — reload" toast, not a second grant. Confirm with SQL that `status` is `staged` once and `row_version` incremented once.

- [ ] **Step 8: Commit**

```bash
git add tests/Integration/CourtThread0Test.php system/lib/ork3/class.Court.php orkui/model/model.Court.php orkui/controller/controller.CourtAjax.php orkui/template/default/Court_detail.tpl
git commit -m "Court: thread RowVersion through grant_award (spec 0.4)"
```

---

## Task 10: 0.7 — name a recorder and tell them

**Files:**
- Modify: `system/lib/ork3/class.Court.php`, `orkui/model/model.Court.php`, `orkui/controller/controller.CourtAjax.php`, `orkui/template/default/Court_detail.tpl`, `tests/Integration/CourtThread0Test.php`

**Interfaces:**
- Consumes: `Court::updateCourt` (Task 4), `ork_court.recorder_mundane_id` (Task 1), `Court::getUnrecordedCourts` (Task 3), `Notification::Add($mundaneId, $type, $message, $link)`.
- Produces: `Court::getDefaultRecorder(int $kingdom_id, int $park_id = 0): int`.

Recording court is the Prime Minister's responsibility under Corpora. `Court::canManage` already admits `Prime Minister`, so the officer Corpora makes responsible is one the code already authorizes.

Fallback chain — **park court:** park PM → park Monarch/Regent → kingdom PM → publisher. **Kingdom court:** kingdom PM → publisher. The park chain prefers the park's own royalty over the kingdom PM deliberately: auto-assigning a kingdom officer to record a park's court is a cross-scope assignment nobody asked for.

Reuse the private helper `lookupOfficerGiver($kingdom_id, $park_id, $role, $label)` (`class.Court.php:1107`), which is generic over role — **not** `getCourtGiverOptions`, which never looks up a PM.

- [ ] **Step 1: Write the failing test**

Append to `CourtThread0Test`:

```php
    public function testDefaultRecorderPrefersParkPrimeMinister(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $pid = $this->fixture->firstParkId($kid);
        if ($pid <= 0) {
            $this->markTestSkipped('No park available in this kingdom.');
        }

        $kingdomPm = $this->fixture->createPlayer('kpm', $kid);
        $this->fixture->insertOfficer($kingdomPm['mundane_id'], $kid, 0, 'Prime Minister');

        // With only a kingdom PM, a park court falls through to it.
        $this->assertSame($kingdomPm['mundane_id'], $this->court->getDefaultRecorder($kid, $pid));

        // A park PM outranks the kingdom PM for that park's court.
        $parkPm = $this->fixture->createPlayer('ppm', $kid, $pid);
        $this->fixture->insertOfficer($parkPm['mundane_id'], $kid, $pid, 'Prime Minister');
        $this->assertSame($parkPm['mundane_id'], $this->court->getDefaultRecorder($kid, $pid));
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter CourtThread0Test`
Expected: FAIL with `Call to undefined method Court::getDefaultRecorder()`.

- [ ] **Step 3: Implement the resolver**

```php
    /**
     * The officer who should record this court's grants (spec 0.7).
     *
     * Recording court is the Prime Minister's responsibility under Corpora.
     * Park courts prefer the park's own officers over kingdom officers: a
     * kingdom PM auto-assigned to a park's court is a cross-scope assignment
     * nobody asked for.
     *
     * Returns 0 when nothing matches; the caller falls back to the publisher.
     */
    public function getDefaultRecorder($kingdom_id, $park_id = 0)
    {
        $kingdom_id = (int)$kingdom_id;
        $park_id    = (int)$park_id;

        $candidates = $park_id > 0
            ? [[$park_id, 'Prime Minister'], [$park_id, 'Monarch'], [$park_id, 'Regent'], [0, 'Prime Minister']]
            : [[0, 'Prime Minister']];

        foreach ($candidates as $c) {
            // lookupOfficerGiver returns ['mundane_id' => int, 'persona' => string,
            // 'role' => string] — lowercase keys. Reading 'MundaneId' here would
            // always miss and silently fall through to the publisher.
            $officer = $this->lookupOfficerGiver($kingdom_id, $c[0], $c[1], $c[1]);
            if (!empty($officer['mundane_id'])) {
                return (int)$officer['mundane_id'];
            }
        }

        return 0;
    }
```

`lookupOfficerGiver` is private on the same class (`class.Court.php:1107`) and returns `['mundane_id' => int, 'persona' => string, 'role' => string]`, or `null` when the seat is vacant — the lowercase keys above are correct as written.

Note it matches `o.role` **exact-case**, and officer roles are lowercased in some environments; if the lookup silently misses, that is the cause, and the fix is a case-insensitive comparison in the helper rather than a workaround here.

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/phpunit --filter CourtThread0Test`
Expected: PASS, 7 tests.

- [ ] **Step 5: Set the recorder when a court leaves draft**

First widen the lib's court-detail projection (`Court::getCourtDetail`) to select
`recorder_mundane_id` and expose it as `RecorderMundaneId`, alongside the existing keys. The
hero display in Step 6 reads the same key.

Then, in `CourtAjax::update_court_status`, when transitioning `draft → published` and
`recorder_mundane_id` is still NULL, resolve and store it:

```php
        if ($new_status === 'published') {
            // The getter is get_court_detail (model.Court.php:30) — there is no
            // get_court. Its lib projection must also be widened to select
            // recorder_mundane_id AS RecorderMundaneId, or this is always empty.
            $court = $this->Court->get_court_detail($court_id);
            if (empty($court['RecorderMundaneId'])) {
                $recorder = $this->Court->get_default_recorder($court['KingdomId'], $court['ParkId']);
                if ($recorder <= 0) {
                    $recorder = (int)$this->session->user_id;
                }
                $this->Court->update_court($court_id, ['RecorderMundaneId' => $recorder]);
            }
        }
```

Add the `get_default_recorder` model pass-through. Match the actual key names `get_court()` returns.

- [ ] **Step 6: Show and edit the recorder in the hero**

Extend the Task 4 court-details modal with a **Recorder** field — a scoped player search following the house autocomplete pattern (custom `cp-ac-dropdown` results, never jQuery UI, `&q=` not `?q=`, and `tnFixedAcPosition(input, dropdown)` called before opening since it lives in a modal). Display the current recorder in the hero meta line as `Recorder: <persona>`.

- [ ] **Step 7: Notify the recorder when a court goes unrecorded**

Add a lib method that walks `getUnrecordedCourts` for a scope and notifies each court's recorder once:

```php
    /**
     * One notification per unrecorded court, addressed to its recorder.
     * Non-blocking: a notification failure never affects court state.
     */
    public function notifyUnrecordedCourts($kingdom_id, $park_id = 0)
    {
        $sent = 0;
        foreach ($this->getUnrecordedCourts($kingdom_id, $park_id) as $c) {
            // getUnrecordedCourts returns RecorderMundaneId directly — there is
            // no single-court getter on this class, and adding one would be a
            // second query per court for no gain.
            $recorder = (int)($c['RecorderMundaneId'] ?? 0);
            if ($recorder <= 0) {
                continue;
            }
            try {
                Ork3::$Lib->notification->Add(
                    $recorder,
                    'court_awaiting_record',
                    'Court "' . $c['Name'] . '" has not been recorded yet.',
                    'Court/detail/' . (int)$c['CourtId']
                );
                $sent++;
            } catch (\Throwable $e) {
                // Non-blocking by design.
            }
        }

        return $sent;
    }
```

Call it from the Court list controller action, guarded so it fires at most once per court per day (check for an existing `court_awaiting_record` notification for that `mundane_id` and link before adding).

Also render the 0.5 unrecorded line on the recorder's own home surface, not only the kingdom/park admin surface, so the named person sees it on every sign-in.

- [ ] **Step 8: Verify end to end**

Publish a park court in a park that has a PM. Confirm `recorder_mundane_id` is that PM. Set the court date to the past, remove any staged rows, load the court list, and confirm exactly one `ork_notification` row exists for that PM with a working link:

```bash
docker exec -i ork3-php8-db mariadb -u ork -psecret ork -e "SELECT mundane_id, type, link FROM ork_notification WHERE type='court_awaiting_record' ORDER BY notification_id DESC LIMIT 5;"
```
Reload the list and confirm no duplicate is written.

- [ ] **Step 9: Commit**

```bash
git add tests/Integration/CourtThread0Test.php system/lib/ork3/class.Court.php orkui/model/model.Court.php orkui/controller/controller.CourtAjax.php orkui/controller/controller.Court.php orkui/template/default/Court_detail.tpl
git commit -m "Court: name a recorder (PM by default) and notify them of unrecorded courts (spec 0.7)"
```

---

## Task 11: Thread 0 verification sweep

**Files:** none created; this task gates the thread.

- [ ] **Step 1: Full suite**

```bash
vendor/bin/phpunit --testsuite integration 2>&1 | tail -20
vendor/bin/phpunit --testsuite unit 2>&1 | tail -20
```
Expected: no new failures. A pre-existing `class` catalog-hash failure is a known local-only issue.

- [ ] **Step 2: Lint every touched PHP file**

```bash
for f in system/lib/ork3/class.Court.php orkui/model/model.Court.php orkui/controller/controller.CourtAjax.php orkui/controller/controller.Court.php tests/Support/CourtFixture.php tests/Integration/CourtThread0Test.php; do php -l "$f"; done
```
Expected: `No syntax errors detected` for each.

- [ ] **Step 3: Confirm the forbidden file is not staged**

```bash
git diff --cached --name-only | grep -c "class.Authorization.php"
git log --oneline -11 --name-only | grep -c "class.Authorization.php\|CLAUDE.md"
```
Expected: `0` for both. If non-zero, unstage before doing anything else.

- [ ] **Step 4: Dark mode and mobile walk**

At 390px and 768px, in light and dark: the unrecorded-court banner, the court-details modal, the RM card list with its overflow toggle, and the RM footer/bulk bar. No horizontal page scroll; no interactive target under 44px.

- [ ] **Step 5: Confirm the spec's Thread 0 acceptance criteria**

Walk §15 of the spec and confirm each Thread 0 item has a passing check: court date correction re-dates unfinalized rows; partial `update_award` leaves omitted fields intact; a published NULL-date court with nothing recorded appears in the warning while a half-recorded one does not; the recorder default resolves to the park PM with the documented fallback.

---

## Self-Review

**Spec coverage.** Thread 0 items 0.1 (Task 4), 0.2 (Task 7), 0.3 (Task 8), 0.4 (Task 9), 0.5 (Task 3), 0.6 (Task 5), 0.7 (Task 10), 0.8 (Task 6), plus the §12 migration and its classification (Task 1) and the fixture the tests need (Task 2). Threads 1–6 are explicitly out of scope. No Thread 0 requirement is unassigned.

**Placeholders.** No "TBD"/"TODO"/"handle edge cases" steps. Three steps deliberately instruct the engineer to read existing code before editing — `lookupOfficerGiver`'s return shape (Task 10 Step 3), `get_court()`'s key names (Task 10 Step 5), and `cpSaveAward`'s field list (Task 5 Step 5) — because guessing those names would produce a silent bug, and the correct value is one grep away. Each says exactly what to look for and what to do with it.

**Type consistency.** `updateAward(int, array, ?int)` is defined in Task 5 and used with the same shape thereafter. `updateCourt(int, array)` is defined in Task 4 and reused in Task 10 Step 5. `getUnrecordedCourts` returns `CourtId`/`Name`/`CourtDate`/`ParkId`/`DaysSince` in Task 3 and is consumed with exactly those keys in Task 3 Step 6 and Task 10 Step 7. `getDefaultRecorder(int, int): int` returns `0` for "nothing matched", and every caller falls back to the publisher on `<= 0`.

**Ordering.** Task 1 (schema) precedes Tasks 4 and 10, which need the column. Task 2 (fixture) precedes every test. Task 5 precedes nothing in this plan but blocks Threads 2 and 5, which is why it sits early. Tasks 6, 7, 8 are independent and may be reordered or parallelized.
