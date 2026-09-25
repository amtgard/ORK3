# Survey Sharing and Attendance Credits Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Each task names the model tier and effort it runs at. This plan is executed by three Workflow scripts in `docs/superpowers/plans/2026-09-10-survey-sharing-and-credits/workflows/`, run with `Workflow({scriptPath})`:
- `sc-1-backend.js`: Tasks 1–11 plus a verifier.
- `sc-2-surfaces.js`: Tasks 12–18. Its `args` are `{scratchSurveyIds, backendNotes}`.
- `sc-3-review.js`: Task 19. Its `args` are `{base}`, the commit before Task 1.

**Goal:** Add three-section org survey lists, one-level results sharing (charts and stats only), and permanent per-org attendance credits (home park or generated event) to the survey module.

**Architecture:**
- **Schema and domain first:**
  - A response park snapshot (full consent only).
  - A report "lens" folded into the normalized filters, so every existing surface and small-group rule applies unchanged.
  - A new `SurveyCredit` domain class. Its pure coverage and precedence core is unit-tested; its engine (enable → reconcile → grant) writes attendance through a new token-free `Attendance::AddSystemCredit()` and events through `EventPlanning::CreateSystemEvent()`.
- **Then the membrane:** `Model_Survey`, the controllers and a CLI sweep.
- **Then four surfaces:** the list page, the shared credit modal plus the builder, the results page, the runner and profile.
- **Then docs and a serial browser pass.**

**Tech Stack:**
- PHP 8 / MariaDB (`sql_mode=''` in dev and prod), plain-PHP `.tpl` templates, vanilla JS IIFEs, CSS on `--ork-*` / `--sv-*` tokens.
- DataTables 1.13.8.
- PHPUnit 10: `tests/Unit` needs no DB; `tests/Integration` runs on the `ork_test` sandbox at port 19307.
- Docker dev stack on port 19080.

**Spec:** `docs/superpowers/specs/2026-09-10-survey-sharing-and-credits-design.md`. Read it with this plan; "§N" below points at it. The base module spec is `docs/superpowers/specs/2026-09-09-survey-module-design.md` ("base §N").

## Global Constraints

- **Git:**
  - Branch `feature/survey-module`.
  - Never `git add -A`; stage explicit paths and run `git diff --cached --stat` before every commit.
  - Never stage `system/lib/ork3/class.Authorization.php` (local `true ||` bypass), `CLAUDE.md` or `agent-instructions/claude.md`.
  - Never push. Never `git stash`. Never create worktrees.
- **PHP style:**
  - `.tpl` files are plain PHP (`<?= ?>`), never Smarty.
  - Before editing an existing PHP file run `awk '/^\t/{c++}END{print c+0}' <file>`. If the count is non-zero and the file is not a `.tpl`, run `tools/php-cs-fixer/php-cs-fixer.phar fix <file>` first and commit that separately (normalize-first rule). `.tpl` files keep their tabs.
- **Layering and SQL:**
  - All SQL lives in `system/lib/ork3/`. Under `orkui/` never use `$DB->`, `Ork3::$Lib`, or `new Survey(` / `new SurveyResponse(` / `new SurveyReport(` / `new SurveyCredit(` outside `orkui/model/model.Survey.php`.
  - `$this->db->Clear()` before every `DataSet` / `Execute`.
  - `(int)`-cast every id and `esc()` every string. `mysql_real_escape_string` is a no-op shim, never rely on it.
  - Check writes with `ExecuteChecked()`.
  - Read new ids with `SELECT LAST_INSERT_ID() AS new_id`. A zero id is not a duplicate-key signal: read the row back by its unique key.
- **Transactions:**
  - Use `exec('START TRANSACTION')` / `COMMIT` / `ROLLBACK`.
  - Never call a method that opens its own transaction (e.g. `CreateSystemEvent`) while one is open.
- **Consent rule (D1):** only non-test `consent='full'` responses are ever credited. Shared (rolled-down) viewers never reach rows, the individual-response panel, CSV or builder actions (D2). The server enforces both; hiding controls is not the boundary.
- **Fixed copy, word for word:**
  - Data-gate credit line: `This survey gives an attendance credit, which will appear on your public attendance record. It is only given when you choose Any ORK Data.`
  - Event name prefix: `Survey Credit - `.
  - Attendance `note`: `Survey #{survey_id}`.
  - `entry_method`: `'survey'`.
- **Status codes in JSON:** `0` ok, `1` bad request / validation, `3` not authorized, `5` not logged in. Every new mutation is outside `CSRF_EXEMPT`; `credit_status` is added to it.
- **UI rules:**
  - No native `alert/confirm/prompt`; tooltips are `data-tip`; dark mode via `html[data-theme="dark"]`.
  - Headings inside tool pages must reset `orkui.css`'s global `h1–h6` pill box (background, border, padding, radius) in **both** themes. Confirm with computed styles.
  - Tap targets are ≥ 44 px on touch widths; no horizontal scroll at 360 px.
  - Tabular data uses DataTables.
- **Dates:** human-readable, e.g. "March 3, 2026".
- **Local environment:**
  - Log in with `POST http://localhost:19080/orkui/index.php?Route=Login/login` and fields `username=heraldsbridge&password=x` into one cookie jar. `heraldsbridge` is mundane 46193, kingdom 17, park 1049, ORK admin. Curl login evicts the browser session, so run curl before browser work.
  - Run PHPUnit directly (`bin/run-unit-tests.sh` never reaches it locally): `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter <Name>`.
  - After a migration, `docker restart ork3-php8-app` (APCu schema cache). `deploy-sandbox --force-refresh` fails locally, so apply migrations to `ork_test` directly.
- **Commits:** message `Enhancement: Survey — <what>`, with the session's commit trailer.

## File Map

| File | Status | Responsibility |
|---|---|---|
| `db-migrations/2026-09-11-survey-sharing-credits.sql` | create | `results_share`, `response.park_id` (+ backfill), credit tables, `entry_method` ENUM append |
| `tools/ork-db/manifests/migration-classification.json5` | modify | classify the migration |
| `system/lib/ork3/class.SurveyResponse.php` | modify | park snapshot, `Credit` on submit, `credit_available`, audience rule ignores survey credits, `kingdomIdList` public |
| `system/lib/ork3/class.SurveyReport.php` | modify | `park_id`/`impossible` filters, `applyLens`, `redactForLens`, `sharedResults` |
| `system/lib/ork3/class.SurveyCredit.php` | create | pure coverage core + org lookups + the credit engine |
| `system/lib/ork3/class.Survey.php` | modify | `kingdomFamily`, `ResultsShare`, `resultsAccess`, `listForScope`, `decorateRows`, gate lock, `onOpened` hook |
| `system/lib/ork3/class.Attendance.php` | modify | `AddSystemCredit()` |
| `system/lib/ork3/class.EventPlanning.php` | modify | `CreateSystemEvent()` |
| `orkui/model/model.Survey.php` | modify | thin delegates + `_credit()` |
| `orkui/controller/controller.Survey.php` | modify | `index` buckets + gate, `results` context |
| `orkui/controller/controller.SurveyAjax.php` | modify | `results` context, `credit_status/enable/reconcile`, `submit.credit` |
| `bin/survey-credit-sweep.php` | create | optional cron reconcile |
| `orkui/template/default/_survey_credit_modal.tpl`, `script/survey-credit.js` | create | the shared Attendance credit modal |
| `orkui/template/default/style/survey.css` | modify | shared `.sv-overlay/.sv-modal` (moved), credit chip and note, section headings, lens strip |
| `orkui/template/default/Survey_index.tpl` | modify | three sections, shared-row actions, Credits action |
| `orkui/template/default/Survey_build.tpl`, `script/survey-build.js` | modify | Results sharing select, Attendance credit card, consent-quote credit line |
| `orkui/template/default/Survey_results.tpl`, `script/survey-results.js` | modify | shared mode + lens strip |
| `orkui/template/default/script/survey-take.js` | modify | gate credit line, chip, thank-you line |
| `orkui/template/revised-frontend/Playernew_index.tpl` | modify | "Survey credit" By label, widget chip |
| `tests/Unit/SurveyCreditTest.php` | create | pure credit functions |
| `tests/Unit/SurveyConsentTest.php`, `tests/Unit/SurveyAggregateTest.php` | modify | park snapshot; lens |
| `tests/Integration/SurveyOrgFixture.php` | create | shared org/survey fixture trait |
| `tests/Integration/SurveySharingCreditTest.php` | create | access, lists, lenses, credits |
| `docs/survey-guide.md`, `orkui/whats_new_content.php` | modify | guide sections, release note |

---

## Task 0: Baseline (owner decision, no code)

**Model:** none (the orchestrator asks the owner)

The working tree holds the uncommitted distributed-review fixes (`git status`: 30 modified files plus `db-migrations/2026-09-10-survey-review-fixes.sql`). Every task below edits some of the same files, so a task commit would sweep those hunks into a feature commit.

- [ ] **Step 1:** Ask the owner to choose one:
  - **(a)** Commit the pending review-fix work first, as its own commit(s), staged explicitly and **excluding** `class.Authorization.php`.
  - **(b)** Run this plan with no commits; every "Commit" step becomes "leave staged: no".
- [ ] **Step 2:** Record the choice at the top of the execution log, and obey it in every Commit step.

---

## Phase 1 — Data and domain

### Task 1: Migration, classification, apply

**Model:** sonnet · effort low

**Files:**
- Create: `db-migrations/2026-09-11-survey-sharing-credits.sql`
- Modify: `tools/ork-db/manifests/migration-classification.json5` (append after the `2026-09-10-survey-review-fixes.sql` entry, line 95)

**Interfaces:**
- Produces:
  - column `ork_survey.results_share ENUM('none','scoped','all')`
  - column `ork_survey_response.park_id INT NULL`
  - tables `ork_survey_credit`, `ork_survey_credit_grant`
  - `ork_attendance.entry_method` accepts `'survey'`

- [ ] **Step 1: Write the migration**

```sql
-- Survey module: results sharing (rolldown) and attendance credits.
-- Spec: docs/superpowers/specs/2026-09-10-survey-sharing-and-credits-design.md §2–§4.
-- Idempotent: ADD ... IF NOT EXISTS, CREATE TABLE IF NOT EXISTS, a guarded
-- backfill, and an append-only ENUM MODIFY that re-runs as a no-op.
-- After applying: docker restart ork3-php8-app (APCu schema cache).

ALTER TABLE ork_survey
  ADD COLUMN IF NOT EXISTS results_share ENUM('none','scoped','all') NOT NULL DEFAULT 'none' AFTER data_gate_enabled;

ALTER TABLE ork_survey_response
  ADD COLUMN IF NOT EXISTS park_id INT NULL AFTER kingdom_id,
  ADD INDEX IF NOT EXISTS idx_survey_park (survey_id, park_id);

-- Park snapshot for existing Any ORK Data rows only; never overwrites a snapshot.
UPDATE ork_survey_response r
  JOIN ork_mundane m ON m.mundane_id = r.mundane_id
   SET r.park_id = NULLIF(m.park_id, 0)
 WHERE r.consent = 'full' AND r.mundane_id IS NOT NULL AND r.park_id IS NULL;

CREATE TABLE IF NOT EXISTS ork_survey_credit (
  credit_id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  survey_id               INT UNSIGNED NOT NULL,
  grantor_type            ENUM('kingdom','park') NOT NULL,
  grantor_id              INT NOT NULL,
  mode                    ENUM('home_park','event') NOT NULL,
  event_id                INT NULL,
  event_calendardetail_id INT NULL,
  enabled_by              INT NOT NULL,
  enabled_at              DATETIME NOT NULL,
  PRIMARY KEY (credit_id),
  UNIQUE KEY uq_survey_grantor (survey_id, grantor_type, grantor_id),
  KEY idx_survey_enabled (survey_id, enabled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- One credit per player per survey; written in the same transaction as the attendance row.
CREATE TABLE IF NOT EXISTS ork_survey_credit_grant (
  survey_id      INT UNSIGNED NOT NULL,
  mundane_id     INT NOT NULL,
  credit_id      INT UNSIGNED NOT NULL,
  attendance_id  INT NOT NULL,
  PRIMARY KEY (survey_id, mundane_id),
  KEY idx_credit (credit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Append-only (see 2026-05-31-attendance-entry-method.sql): never reorder or remove.
ALTER TABLE ork_attendance
  MODIFY COLUMN entry_method ENUM('manual','signin_link','self_reg','bulk_import','survey') NOT NULL DEFAULT 'manual';
```

- [ ] **Step 2: Classify**

```json5
    "2026-09-11-survey-sharing-credits.sql": { "class": "S", "render": "full", "notes": "Survey results sharing + attendance credits: results_share, response.park_id with a guarded full-consent backfill (WHERE park_id IS NULL), two credit tables, append-only entry_method ENUM ('survey'); idempotent" },
```

- [ ] **Step 3: Apply to dev and test, restart, verify**

```bash
docker exec -i ork3-php8-db mariadb -uroot -proot ork < db-migrations/2026-09-11-survey-sharing-credits.sql
docker exec -i ork3-php8-db mariadb -uroot -proot ork < db-migrations/2026-09-11-survey-sharing-credits.sql   # second run must be silent
docker exec -i ork3-php8-test-db mariadb -uroot -proot ork_test < db-migrations/2026-09-11-survey-sharing-credits.sql
docker restart ork3-php8-app
for db in "ork3-php8-db ork" "ork3-php8-test-db ork_test"; do set -- $db
  docker exec $1 mariadb -uroot -proot $2 -Nse "SHOW TABLES LIKE 'ork_survey_credit%'; SHOW COLUMNS FROM ork_survey LIKE 'results_share'; SHOW COLUMNS FROM ork_survey_response LIKE 'park_id'; SHOW COLUMNS FROM ork_attendance LIKE 'entry_method'"
done
```
Expected, for each DB: `ork_survey_credit`, `ork_survey_credit_grant`, a `results_share` row, a `park_id` row, and an `entry_method` enum ending `'survey')`.

- [ ] **Step 4: Drift check**

```bash
php tools/ork-db/cli.php drift-check --strict 2>&1 | grep -i "unclassified\|2026-09-11" ; echo done
```
Expected: no "unclassified" line. The known `catalog hash drift` failure is local-only; ignore it. If drift-check reports the `ork_attendance` ENUM as divergent from the `2026-05-31` override render, add `tools/ork-db/templates/schema/overrides/2026-09-11-survey-sharing-credits.sql` (the migration without the `UPDATE`) and switch the entry to `"render": "override", "override": "2026-09-11-survey-sharing-credits.sql"`.

- [ ] **Step 5: Commit** (per Task 0)

```bash
git add db-migrations/2026-09-11-survey-sharing-credits.sql tools/ork-db/manifests/migration-classification.json5
git commit -m "Enhancement: Survey — migration for results sharing and attendance credits"
```

---

### Task 2: Park snapshot on responses

**Model:** sonnet · effort medium

**Files:**
- Modify: `system/lib/ork3/class.SurveyResponse.php` (`scrubForConsent()` `:89-124`; `submit()` row build `:1228-1235` and INSERT `:1242-1253`; `kingdomIdList()` `:513` becomes `public static`)
- Test: `tests/Unit/SurveyConsentTest.php`

**Interfaces:**
- Produces:
  - `scrubForConsent()` returns a `park_id` key, kept only for `full`
  - `SurveyResponse::kingdomIdList($raw): ?array` is public (used by `SurveyCredit`, Task 4)

- [ ] **Step 1: Write the failing tests** (append to `SurveyConsentTest`)

```php
    public function testParkSnapshotSurvivesOnlyFullConsent(): void
    {
        $row = [
            'mundane_id' => 5, 'kingdom_id' => 17, 'park_id' => 1049, 'tenure_months' => 40,
            'started_at' => '2026-09-10 10:00:00', 'submitted_at' => '2026-09-10 10:05:00', 'duration_seconds' => 300,
        ];
        $this->assertSame(1049, SurveyResponse::scrubForConsent($row, 'full')['park_id']);
        $this->assertNull(SurveyResponse::scrubForConsent($row, 'partial')['park_id']);
        $this->assertNull(SurveyResponse::scrubForConsent($row, 'anonymous')['park_id']);
    }

    public function testScrubAddsAMissingParkKeyAsNull(): void
    {
        $out = SurveyResponse::scrubForConsent(['mundane_id' => 5, 'submitted_at' => '2026-09-10 10:05:00'], 'full');
        $this->assertArrayHasKey('park_id', $out);
        $this->assertNull($out['park_id']);
    }

    public function testKingdomIdListIsPublicAndDecodesLists(): void
    {
        $this->assertNull(SurveyResponse::kingdomIdList(null));
        $this->assertSame([17, 4], SurveyResponse::kingdomIdList('[17,4]'));
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter SurveyConsentTest`
Expected: FAIL. The first two fail on the missing `park_id` key; the third fails with "Call to private method".

- [ ] **Step 3: Implement**

In `scrubForConsent()`, add `'park_id'` to the defaults loop and null it for non-full:

```php
        foreach (['mundane_id', 'kingdom_id', 'park_id', 'tenure_months', 'started_at', 'submitted_at', 'duration_seconds'] as $k) {
```
```php
        // partial and anonymous both lose the profile link, the home-park
        // snapshot (a park is a far smaller crowd than a kingdom; sharing spec
        // D3), the start time, the exact clock time and the duration.
        $row['mundane_id']       = null;
        $row['park_id']          = null;
```

Change `private static function kingdomIdList($raw): ?array` to `public static function kingdomIdList($raw): ?array`.

In `submit()`, add the snapshot to the row passed to `scrubForConsent`:

```php
            'kingdom_id'       => $player ? (int) $player['kingdom_id'] : null,
            'park_id'          => ($player && (int) $player['park_id'] > 0) ? (int) $player['park_id'] : null,
```

and to the INSERT:

```php
             (survey_id, consent, mundane_id, kingdom_id, park_id, tenure_months, is_test, started_at, submitted_at, duration_seconds)
             VALUES (' . $surveyId . ',
                     \'' . $storedConsent . '\',
                     ' . self::sqlInt($row['mundane_id']) . ',
                     ' . self::sqlInt($row['kingdom_id']) . ',
                     ' . self::sqlInt($row['park_id']) . ',
```

- [ ] **Step 4: Run unit + existing integration**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter 'SurveyConsentTest|SurveyTest'`
Expected: PASS. `SurveyTest::testThreeSubmissionsStoreConsentScrubbedColumns` still passes.

- [ ] **Step 5: Lint and commit**

```bash
php -l system/lib/ork3/class.SurveyResponse.php
git add system/lib/ork3/class.SurveyResponse.php tests/Unit/SurveyConsentTest.php
git commit -m "Enhancement: Survey — snapshot the home park on Any ORK Data responses"
```

---

### Task 3: Report lens

**Model:** sonnet · effort high

**Files:**
- Modify: `system/lib/ork3/class.SurveyReport.php`:
  - `DEFAULT_FILTERS` `:39-46`
  - `normalizeFilters()` `:104-150`
  - `isNarrowing()` `:163-170`
  - `responseWhere()` `:2051-2073`
  - new public methods after `isNarrowing()`
- Test: `tests/Unit/SurveyAggregateTest.php`

**Interfaces:**
- Consumes: a lens array from `Survey::resultsAccess()` (Task 5): `['shared'=>true]`, or `['shared'=>true,'kingdom_ids'=>int[]]`, or `['shared'=>true,'park_id'=>int]`.
- Produces:
  - `SurveyReport::applyLens($filters, array $lens): array` (normalized filters)
  - `SurveyReport::redactForLens(array $summary, array $lens): array`
  - `SurveyReport::sharedResults(int $surveyId, $filters, array $lens): array` returning `{questions, summary}`, same shape as `Model_Survey::results()`
  - filter keys `park_id` (int|null) and `impossible` (bool)

- [ ] **Step 1: Write the failing tests** (append to `SurveyAggregateTest`)

```php
    public function testApplyLensFoldsAKingdomLensIntoAnEmptyPick(): void
    {
        $f = SurveyReport::applyLens([], ['shared' => true, 'kingdom_ids' => [17, 44]]);
        $this->assertSame([17, 44], $f['kingdom_ids']);
        $this->assertFalse($f['impossible']);
        $this->assertFalse($f['include_test']);
    }

    public function testApplyLensIntersectsTheViewersKingdomPick(): void
    {
        $f = SurveyReport::applyLens(['kingdom_ids' => [44, 99]], ['shared' => true, 'kingdom_ids' => [17, 44]]);
        $this->assertSame([44], $f['kingdom_ids']);
        $this->assertFalse($f['impossible']);
    }

    public function testApplyLensMakesADisjointPickImpossibleRatherThanWidening(): void
    {
        $f = SurveyReport::applyLens(['kingdom_ids' => [99]], ['shared' => true, 'kingdom_ids' => [17]]);
        $this->assertSame([17], $f['kingdom_ids']);
        $this->assertTrue($f['impossible']);
    }

    public function testParkLensForcesFullConsentAndRefusesAnotherTier(): void
    {
        $f = SurveyReport::applyLens([], ['shared' => true, 'park_id' => 1049]);
        $this->assertSame(1049, $f['park_id']);
        $this->assertSame('full', $f['consent']);
        $this->assertFalse($f['impossible']);

        $g = SurveyReport::applyLens(['consent' => 'partial'], ['shared' => true, 'park_id' => 1049]);
        $this->assertTrue($g['impossible']);
    }

    public function testSharedLensForcesTestRowsOffEvenWithNoFilterLens(): void
    {
        $f = SurveyReport::applyLens(['include_test' => true], ['shared' => true]);
        $this->assertFalse($f['include_test']);
        $this->assertNull($f['park_id']);
        $this->assertSame([], $f['kingdom_ids']);
    }

    public function testLensKeysSurviveRenormalizingAndCountAsNarrowing(): void
    {
        $f = SurveyReport::normalizeFilters(SurveyReport::applyLens([], ['shared' => true, 'park_id' => 9]));
        $this->assertSame(9, $f['park_id']);
        $this->assertTrue(SurveyReport::isNarrowing(['park_id' => 9]));
        $this->assertTrue(SurveyReport::isNarrowing(['impossible' => true]));
        $this->assertFalse(SurveyReport::isNarrowing([]));
    }

    public function testRedactForLensHidesSurveyWideCountsOnlyUnderALens(): void
    {
        $summary = ['responses' => 12, 'starts' => 80, 'audience' => 400, 'excluded_anonymous' => 9, 'response_rate' => 0.2, 'completion' => 0.5];
        $lensed = SurveyReport::redactForLens($summary, ['shared' => true, 'kingdom_ids' => [17]]);
        $this->assertSame(12, $lensed['responses']);
        foreach (['starts', 'audience', 'excluded_anonymous', 'response_rate', 'completion'] as $k) {
            $this->assertNull($lensed[$k], $k);
        }
        $this->assertSame($summary, SurveyReport::redactForLens($summary, ['shared' => true]));
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter SurveyAggregateTest`
Expected: FAIL with "Call to undefined method SurveyReport::applyLens()".

- [ ] **Step 3: Implement**

`DEFAULT_FILTERS`, appended:

```php
        'park_id'              => null,   // lens only: Any ORK Data rows snapshotted at this park (sharing spec §2)
        'impossible'           => false,  // lens only: the viewer's picks and the lens do not overlap
```

`normalizeFilters()`, before `return $out;`:

```php
        if (!empty($filters['park_id'])) {
            $pid = (int)$filters['park_id'];
            $out['park_id'] = $pid > 0 ? $pid : null;
        }

        if (!empty($filters['impossible'])) {
            $out['impossible'] = true;
        }
```

`isNarrowing()`:

```php
        return $f['kingdom_ids'] !== []
            || $f['consent'] !== 'any'
            || $f['date_from'] !== null
            || $f['date_to'] !== null
            || $f['park_id'] !== null
            || $f['impossible'];
```

`responseWhere()`, after the `include_test` clause:

```php
        if (!empty($f['impossible'])) {
            $w[] = '1 = 0';
        }
        if (!empty($f['park_id'])) {
            $w[] = 'r.park_id = ' . (int)$f['park_id'];
        }
```

New methods, after `isNarrowing()`:

```php
    /**
     * PURE. Fold a shared viewer's lens (Survey::resultsAccess) into the filters,
     * so every surface reads one filter set and the viewer cannot widen it. The
     * result is normalized and stays valid through any later normalizeFilters().
     * A pick that does not overlap the lens becomes `impossible` (no rows) rather
     * than falling back to a wider set.
     */
    public static function applyLens($filters, array $lens): array
    {
        $f = self::normalizeFilters($filters);

        if (!empty($lens['shared'])) {
            $f['include_test'] = false;
        }

        if (!empty($lens['kingdom_ids']) && is_array($lens['kingdom_ids'])) {
            $allowed = [];
            foreach ($lens['kingdom_ids'] as $id) {
                $id = (int)$id;
                if ($id > 0 && !in_array($id, $allowed, true)) {
                    $allowed[] = $id;
                }
            }
            if ($f['kingdom_ids'] === []) {
                $f['kingdom_ids'] = $allowed;
            } else {
                $keep = array_values(array_intersect($f['kingdom_ids'], $allowed));
                if ($keep === []) {
                    $f['kingdom_ids'] = $allowed;
                    $f['impossible']  = true;
                } else {
                    $f['kingdom_ids'] = $keep;
                }
            }
        }

        if (!empty($lens['park_id'])) {
            $f['park_id'] = (int)$lens['park_id'];
            if ($f['consent'] === 'any') {
                $f['consent'] = 'full';
            } elseif ($f['consent'] !== 'full') {
                $f['impossible'] = true;
            }
        }

        return $f;
    }

    /**
     * PURE. A lens viewer sees counts for their own players only: the survey-wide
     * starts, audience, anonymous total and the rates built on them go.
     */
    public static function redactForLens(array $summary, array $lens): array
    {
        if (empty($lens['kingdom_ids']) && empty($lens['park_id'])) {
            return $summary;
        }
        foreach (['starts', 'audience', 'excluded_anonymous', 'response_rate', 'completion'] as $k) {
            if (array_key_exists($k, $summary)) {
                $summary[$k] = null;
            }
        }
        return $summary;
    }

    /** Charts and stats for a shared viewer (sharing spec §2): lens folded in, survey-wide counts removed. */
    public function sharedResults(int $surveyId, $filters, array $lens): array
    {
        $f   = self::applyLens($filters, $lens);
        $out = $this->aggregate($surveyId, $f);
        $out['summary'] = self::redactForLens($this->summary($surveyId, $f), $lens);
        return $out;
    }
```

- [ ] **Step 4: Run tests**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter 'SurveyAggregateTest|SurveyTest'`
Expected: PASS, with the existing suppression tests unchanged.

- [ ] **Step 5: Lint and commit**

```bash
php -l system/lib/ork3/class.SurveyReport.php
git add system/lib/ork3/class.SurveyReport.php tests/Unit/SurveyAggregateTest.php
git commit -m "Enhancement: Survey — report lens for shared results"
```

---

### Task 4: `SurveyCredit` pure core and org lookups

**Model:** opus · effort high

**Files:**
- Create: `system/lib/ork3/class.SurveyCredit.php` (auto-loaded by `startup.php`'s scan of `DIR_ORK3`; available as `Ork3::$Lib->surveycredit`)
- Test: `tests/Unit/SurveyCreditTest.php`

**Interfaces:**
- Consumes: `SurveyResponse::kingdomIdList()` (Task 2).
- Produces:
  - Constants: `SurveyCredit::MODES`, `COLOR_CLASS_ID = 6`, `EVENT_PREFIX = 'Survey Credit - '`, `EVENT_NAME_MAX = 100`.
  - Pure statics:
    - `noteFor(int): string`
    - `eventName(string): string`
    - `startDate(array $surveyRow): ?string` ('Y-m-d')
    - `classFor(int $lastClassId): int`
    - `grantorReaches(array $surveyRow, string $type, int $id, int $grantorKingdom, int $grantorParent): bool`
    - `coverage(array $configs, array $respondent, array $surveyRow, array $parentOf): array{credit_id:?int, no_home_park:bool}`
  - Instance methods: `parentMap(): array<int,int>`, `orgKingdom(string $type, int $id): array{0:int,1:int}`, `validGrantor(array $surveyRow, string $type, int $id): bool`.
  - Private helpers `fetchAll`, `fetchRow`, `exec`, `esc`, `ok`, `fail`, `denied` (copy the bodies from `class.Survey.php:2751-2837`).

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Pure pieces of SurveyCredit (sharing-and-credits spec §3). No DB. */
final class SurveyCreditTest extends TestCase
{
    private const KSURVEY = ['scope_type' => 'kingdom', 'scope_id' => 17, 'audience_kingdom_ids' => null];

    private static function cfg(int $id, string $type, int $gid, string $mode, string $at): array
    {
        return ['credit_id' => $id, 'grantor_type' => $type, 'grantor_id' => $gid, 'mode' => $mode, 'enabled_at' => $at];
    }

    public function testNoteFitsTheTwentyCharacterColumnForTenDigitIds(): void
    {
        $this->assertSame('Survey #42', SurveyCredit::noteFor(42));
        $this->assertLessThanOrEqual(20, strlen(SurveyCredit::noteFor(4294967295)));
    }

    public function testEventNameIsPrefixedAndCutToOneHundredMultibyteCharacters(): void
    {
        $this->assertSame('Survey Credit - Voice of the Kingdom', SurveyCredit::eventName('  Voice of the Kingdom '));
        $long = SurveyCredit::eventName(str_repeat('é', 150));
        $this->assertSame(100, mb_strlen($long));
        $this->assertStringEndsWith('…', $long);
        $this->assertStringStartsWith('Survey Credit - ', $long);
    }

    public function testStartDateIsTheLaterOfOpenedAndScheduledOpen(): void
    {
        $this->assertNull(SurveyCredit::startDate(['opened_at' => null, 'open_at' => '2026-09-01 00:00:00']));
        $this->assertSame('2026-09-05', SurveyCredit::startDate(['opened_at' => '2026-09-05 13:00:00', 'open_at' => null]));
        $this->assertSame('2026-09-20', SurveyCredit::startDate(['opened_at' => '2026-09-05 13:00:00', 'open_at' => '2026-09-20 08:00:00']));
        $this->assertSame('2026-09-05', SurveyCredit::startDate(['opened_at' => '2026-09-05 13:00:00', 'open_at' => '2026-08-01 08:00:00']));
    }

    public function testClassFallsBackToColor(): void
    {
        $this->assertSame(6, SurveyCredit::classFor(0));
        $this->assertSame(3, SurveyCredit::classFor(3));
    }

    public function testGrantorReachMatrix(): void
    {
        $park = ['scope_type' => 'park', 'scope_id' => 1049];
        $this->assertTrue(SurveyCredit::grantorReaches($park, 'park', 1049, 17, 0));
        $this->assertFalse(SurveyCredit::grantorReaches($park, 'park', 1050, 17, 0));
        $this->assertFalse(SurveyCredit::grantorReaches($park, 'kingdom', 17, 17, 0));

        $this->assertTrue(SurveyCredit::grantorReaches(self::KSURVEY, 'kingdom', 17, 17, 0));
        $this->assertTrue(SurveyCredit::grantorReaches(self::KSURVEY, 'kingdom', 90, 90, 17));  // principality of 17
        $this->assertTrue(SurveyCredit::grantorReaches(self::KSURVEY, 'park', 5, 90, 17));       // park in that principality
        $this->assertFalse(SurveyCredit::grantorReaches(self::KSURVEY, 'park', 6, 44, 0));       // another kingdom's park

        $ork = ['scope_type' => 'ork', 'scope_id' => 0, 'audience_kingdom_ids' => '[17]'];
        $this->assertTrue(SurveyCredit::grantorReaches($ork, 'kingdom', 17, 17, 0));
        $this->assertTrue(SurveyCredit::grantorReaches($ork, 'park', 5, 90, 17));
        $this->assertFalse(SurveyCredit::grantorReaches($ork, 'kingdom', 44, 44, 0));
        $this->assertTrue(SurveyCredit::grantorReaches(['scope_type' => 'ork', 'scope_id' => 0, 'audience_kingdom_ids' => null], 'kingdom', 44, 44, 0));
    }

    public function testEarliestCoveringConfigWinsWhicheverLevelCameFirst(): void
    {
        $kingdomFirst = [self::cfg(2, 'park', 1049, 'home_park', '2026-09-02 00:00:00'), self::cfg(1, 'kingdom', 17, 'home_park', '2026-09-01 00:00:00')];
        $this->assertSame(1, SurveyCredit::coverage($kingdomFirst, ['park_id' => 1049, 'kingdom_id' => 17], self::KSURVEY, [])['credit_id']);

        $parkFirst = [self::cfg(1, 'kingdom', 17, 'home_park', '2026-09-03 00:00:00'), self::cfg(2, 'park', 1049, 'home_park', '2026-09-02 00:00:00')];
        $this->assertSame(2, SurveyCredit::coverage($parkFirst, ['park_id' => 1049, 'kingdom_id' => 17], self::KSURVEY, [])['credit_id']);
    }

    public function testTiesOnEnabledAtGoToTheLowerId(): void
    {
        $tie = [self::cfg(8, 'kingdom', 17, 'home_park', '2026-09-01 00:00:00'), self::cfg(7, 'park', 1049, 'home_park', '2026-09-01 00:00:00')];
        $this->assertSame(7, SurveyCredit::coverage($tie, ['park_id' => 1049, 'kingdom_id' => 17], self::KSURVEY, [])['credit_id']);
    }

    public function testKingdomConfigCoversItsPrincipalityPlayers(): void
    {
        $c = [self::cfg(1, 'kingdom', 17, 'home_park', '2026-09-01 00:00:00')];
        $this->assertSame(1, SurveyCredit::coverage($c, ['park_id' => 5, 'kingdom_id' => 90], self::KSURVEY, [90 => 17])['credit_id']);
        $this->assertNull(SurveyCredit::coverage($c, ['park_id' => 6, 'kingdom_id' => 44], self::KSURVEY, [90 => 17])['credit_id']);
    }

    public function testOwnersEventConfigCoversVisitorsButANonOwnersDoesNot(): void
    {
        $owner = [self::cfg(1, 'kingdom', 17, 'event', '2026-09-01 00:00:00')];
        $this->assertSame(1, SurveyCredit::coverage($owner, ['park_id' => 6, 'kingdom_id' => 44], self::KSURVEY, [])['credit_id']);

        $ork = ['scope_type' => 'ork', 'scope_id' => 0, 'audience_kingdom_ids' => null];
        $this->assertNull(SurveyCredit::coverage($owner, ['park_id' => 6, 'kingdom_id' => 44], $ork, [])['credit_id']);
    }

    public function testHomeParkModeSkipsARespondentWithNoParkAndSaysWhy(): void
    {
        $c = [self::cfg(1, 'kingdom', 17, 'home_park', '2026-09-01 00:00:00')];
        $cov = SurveyCredit::coverage($c, ['park_id' => null, 'kingdom_id' => 17], self::KSURVEY, []);
        $this->assertNull($cov['credit_id']);
        $this->assertTrue($cov['no_home_park']);

        $withEvent = array_merge($c, [self::cfg(2, 'kingdom', 17, 'event', '2026-09-02 00:00:00')]);
        $this->assertSame(2, SurveyCredit::coverage($withEvent, ['park_id' => null, 'kingdom_id' => 17], self::KSURVEY, [])['credit_id']);
    }

    public function testNoConfigsCoverNobody(): void
    {
        $this->assertSame(['credit_id' => null, 'no_home_park' => false], SurveyCredit::coverage([], ['park_id' => 1, 'kingdom_id' => 17], self::KSURVEY, []));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter SurveyCreditTest`
Expected: FAIL with "Class "SurveyCredit" not found".

- [ ] **Step 3: Implement the class skeleton + pure core + lookups**

```php
<?php

/**
 * SurveyCredit — attendance credits for completing a survey
 * (docs/superpowers/specs/2026-09-10-survey-sharing-and-credits-design.md §3).
 *
 * A config (ork_survey_credit) is one org's PERMANENT promise to credit the
 * players it covers; the ledger (ork_survey_credit_grant) holds at most one
 * credit per player per survey. Only non-test `full` responses are ever
 * credited (D1): a dated public credit beside a day-stamped partial or
 * anonymous response would re-identify it.
 *
 * All credit SQL lives here. Attendance and event rows are written by
 * Attendance::AddSystemCredit() and EventPlanning::CreateSystemEvent(), which
 * trust this class to have authorized the write.
 */
class SurveyCredit
{
    public const MODES = ['home_park', 'event'];

    /** ork_class row for Color: the credit class for a player with no attendance yet. */
    public const COLOR_CLASS_ID = 6;

    public const EVENT_PREFIX = 'Survey Credit - ';

    /** ork_event.name is varchar(100). */
    public const EVENT_NAME_MAX = 100;

    private $db;

    /** @var array<int,int>|null kingdom_id => parent_kingdom_id, non-zero parents only */
    private ?array $parentMemo = null;

    /** @var array<string,array{0:int,1:int}> "type:id" => [kingdom_id, parent_kingdom_id] */
    private array $orgMemo = [];

    public function __construct()
    {
        global $DB;
        $this->db = $DB;
    }

    // -----------------------------------------------------------------------
    // Pure
    // -----------------------------------------------------------------------

    /** ork_attendance.note for a survey credit; <= 20 chars for any 10-digit id. */
    public static function noteFor(int $surveyId): string
    {
        return 'Survey #' . $surveyId;
    }

    /** "Survey Credit - {title}", cut to ork_event.name's 100 characters. */
    public static function eventName(string $title): string
    {
        $name = self::EVENT_PREFIX . trim($title);
        if (mb_strlen($name) <= self::EVENT_NAME_MAX) {
            return $name;
        }
        return rtrim(mb_substr($name, 0, self::EVENT_NAME_MAX - 1)) . '…';
    }

    /** 'Y-m-d' the survey started: DATE(opened_at), or DATE(open_at) when later; null if never opened. */
    public static function startDate(array $surveyRow): ?string
    {
        $opened = strtotime((string) ($surveyRow['opened_at'] ?? ''));
        if (!$opened) {
            return null;
        }
        $scheduled = strtotime((string) ($surveyRow['open_at'] ?? ''));
        return date('Y-m-d', ($scheduled && $scheduled > $opened) ? $scheduled : $opened);
    }

    /** The player's last class, or Color when they have no attendance. */
    public static function classFor(int $lastClassId): int
    {
        return $lastClassId > 0 ? $lastClassId : self::COLOR_CLASS_ID;
    }

    /**
     * Does the survey reach this org? The same answer decides who may grant
     * credits (§3.2) and, one level down, who may see shared results (§2).
     * $grantorKingdom is the org's kingdom (a park's own kingdom); $grantorParent
     * is that kingdom's parent, 0 for none.
     */
    public static function grantorReaches(array $surveyRow, string $type, int $id, int $grantorKingdom, int $grantorParent): bool
    {
        if ($id <= 0 || $grantorKingdom <= 0 || !in_array($type, ['kingdom', 'park'], true)) {
            return false;
        }
        $scopeId = (int) ($surveyRow['scope_id'] ?? 0);
        switch ((string) ($surveyRow['scope_type'] ?? '')) {
            case 'park':
                return $type === 'park' && $id === $scopeId;
            case 'kingdom':
                return $grantorKingdom === $scopeId || ($grantorParent > 0 && $grantorParent === $scopeId);
            case 'ork':
                $list = SurveyResponse::kingdomIdList($surveyRow['audience_kingdom_ids'] ?? null);
                return $list === null
                    || in_array($grantorKingdom, $list, true)
                    || ($grantorParent > 0 && in_array($grantorParent, $list, true));
        }
        return false;
    }

    /**
     * Which config credits this respondent (§3.2)? The earliest enabled_at wins,
     * ties to the lower credit_id. A park config covers its snapshotted park; a
     * kingdom config covers its kingdom and that kingdom's principalities; the
     * OWNER's event config covers everyone (event audiences bring visitors). A
     * home_park config cannot place a respondent with no park: it is skipped
     * (a later config may still cover them) and no_home_park reports why.
     *
     * @param list<array<string,mixed>> $configs    ork_survey_credit rows
     * @param array<string,mixed>       $respondent ['park_id'=>?int, 'kingdom_id'=>?int] from the response
     * @param array<int,int>            $parentOf   kingdom_id => parent_kingdom_id
     * @return array{credit_id:?int, no_home_park:bool}
     */
    public static function coverage(array $configs, array $respondent, array $surveyRow, array $parentOf): array
    {
        usort($configs, static function (array $a, array $b): int {
            return [(string) $a['enabled_at'], (int) $a['credit_id']] <=> [(string) $b['enabled_at'], (int) $b['credit_id']];
        });

        $park      = (int) ($respondent['park_id'] ?? 0);
        $kingdom   = (int) ($respondent['kingdom_id'] ?? 0);
        $parent    = $kingdom > 0 ? (int) ($parentOf[$kingdom] ?? 0) : 0;
        $ownerType = (string) ($surveyRow['scope_type'] ?? '');
        $ownerId   = (int) ($surveyRow['scope_id'] ?? 0);
        $noPark    = false;

        foreach ($configs as $c) {
            $type = (string) $c['grantor_type'];
            $gid  = (int) $c['grantor_id'];
            $mode = (string) $c['mode'];

            if ($mode === 'event' && $type === $ownerType && $gid === $ownerId) {
                return ['credit_id' => (int) $c['credit_id'], 'no_home_park' => false];
            }
            $mine = ($type === 'park' && $park > 0 && $park === $gid)
                || ($type === 'kingdom' && $kingdom > 0 && ($kingdom === $gid || ($parent > 0 && $parent === $gid)));
            if (!$mine) {
                continue;
            }
            if ($mode === 'home_park' && $park <= 0) {
                $noPark = true;
                continue;
            }
            return ['credit_id' => (int) $c['credit_id'], 'no_home_park' => false];
        }
        return ['credit_id' => null, 'no_home_park' => $noPark];
    }

    // -----------------------------------------------------------------------
    // Org lookups
    // -----------------------------------------------------------------------

    /** @return array<int,int> kingdom_id => parent_kingdom_id for every kingdom with a parent */
    public function parentMap(): array
    {
        if ($this->parentMemo === null) {
            $this->parentMemo = [];
            foreach ($this->fetchAll('SELECT kingdom_id, parent_kingdom_id FROM ' . DB_PREFIX . 'kingdom
                                      WHERE parent_kingdom_id > 0') as $r) {
                $this->parentMemo[(int) $r['kingdom_id']] = (int) $r['parent_kingdom_id'];
            }
        }
        return $this->parentMemo;
    }

    /** [kingdom_id, parent_kingdom_id] of an ACTIVE park or kingdom, or [0, 0]. */
    public function orgKingdom(string $type, int $id): array
    {
        $key = $type . ':' . $id;
        if (isset($this->orgMemo[$key])) {
            return $this->orgMemo[$key];
        }
        $row = null;
        if ($type === 'park' && $id > 0) {
            $row = $this->fetchRow('SELECT p.kingdom_id, COALESCE(k.parent_kingdom_id, 0) AS parent_kingdom_id
                                      FROM ' . DB_PREFIX . 'park p
                                      JOIN ' . DB_PREFIX . 'kingdom k ON k.kingdom_id = p.kingdom_id
                                     WHERE p.park_id = ' . $id . ' AND p.active = \'Active\'');
        } elseif ($type === 'kingdom' && $id > 0) {
            $row = $this->fetchRow('SELECT kingdom_id, parent_kingdom_id FROM ' . DB_PREFIX . 'kingdom
                                     WHERE kingdom_id = ' . $id . ' AND active = \'Active\'');
        }
        return $this->orgMemo[$key] = $row ? [(int) $row['kingdom_id'], (int) $row['parent_kingdom_id']] : [0, 0];
    }

    /** May this org grant credits on (and, one level down, receive shared results of) this survey? */
    public function validGrantor(array $surveyRow, string $type, int $id): bool
    {
        [$kingdom, $parent] = $this->orgKingdom($type, $id);
        return self::grantorReaches($surveyRow, $type, $id, $kingdom, $parent);
    }

    // -----------------------------------------------------------------------
    // SQL helpers (same contract as class.Survey.php)
    // -----------------------------------------------------------------------
    // fetchAll / fetchRow / exec / esc / ok / fail / denied: copy the bodies of
    // class.Survey.php:2751-2837 verbatim (exec() uses ExecuteChecked when present).
}
```

- [ ] **Step 4: Run tests**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter SurveyCreditTest`
Expected: PASS (11 tests).

- [ ] **Step 5: Lint and commit**

```bash
php -l system/lib/ork3/class.SurveyCredit.php
git add system/lib/ork3/class.SurveyCredit.php tests/Unit/SurveyCreditTest.php
git commit -m "Enhancement: Survey — credit coverage and precedence core"
```

---

### Task 5: Fixture trait, `ResultsShare`, `resultsAccess`

**Model:** opus · effort medium

**Files:**
- Create: `tests/Integration/SurveyOrgFixture.php` (trait), `tests/Integration/SurveySharingCreditTest.php`
- Modify: `system/lib/ork3/class.Survey.php`:
  - `UPDATE_FIELDS` `:48-67`
  - the `update()` switch `:537-640`
  - new `kingdomFamily()` and `resultsAccess()` after `canManage()` `:175-181`

**Interfaces:**
- Consumes: `SurveyCredit::validGrantor()` (Task 4).
- Produces:
  - `Survey::kingdomFamily(int $kingdomId): list<int>`
  - `Survey::resultsAccess(int $uid, array $surveyRow, ?array $context): ?array{level:'manage'|'shared', lens:array, label:''|'all'|'kingdom'|'park', org_name:string}`, where `$context = ['type'=>'kingdom'|'park','id'=>int]`
  - `update()` accepts `ResultsShare`
  - The fixture trait methods listed in Step 1

- [ ] **Step 1: Write the fixture trait**

```php
<?php

declare(strict_types=1);

/**
 * Org + survey fixture for the sharing-and-credits integration tests. Owns
 * every row it creates and removes them in tearDownFixture(), attendance and
 * generated events included.
 */
trait SurveyOrgFixture
{
    private PDO $pdo;

    /** @var array<string, list<int>> */
    private array $fx = ['survey' => [], 'mundane' => [], 'auth' => [], 'park' => [], 'kingdom' => []];

    private function setUpFixture(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        unset($_SESSION['is_authorized_mundane_id']);
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function tearDownFixture(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);
        $p = DB_PREFIX;
        foreach ($this->fx['survey'] as $sid) {
            $sid = (int) $sid;
            foreach ($this->pdo->query("SELECT event_id FROM {$p}survey_credit WHERE survey_id = {$sid} AND event_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $eid) {
                $this->pdo->exec("DELETE FROM {$p}attendance WHERE event_id = " . (int) $eid);
                $this->pdo->exec("DELETE FROM {$p}event_calendardetail WHERE event_id = " . (int) $eid);
                $this->pdo->exec("DELETE FROM {$p}event WHERE event_id = " . (int) $eid);
            }
            $this->pdo->exec("DELETE FROM {$p}attendance WHERE note = 'Survey #{$sid}'");
            $this->pdo->exec("DELETE FROM {$p}survey_credit_grant WHERE survey_id = {$sid}");
            $this->pdo->exec("DELETE FROM {$p}survey_credit WHERE survey_id = {$sid}");
            $this->pdo->exec("DELETE a FROM {$p}survey_answer a JOIN {$p}survey_response r ON r.response_id = a.response_id WHERE r.survey_id = {$sid}");
            foreach (['survey_response', 'survey_participation', 'survey_start', 'survey_draft', 'survey_dismissal', 'survey_activity'] as $t) {
                $this->pdo->exec("DELETE FROM {$p}{$t} WHERE survey_id = {$sid}");
            }
            $this->pdo->exec("DELETE o FROM {$p}survey_option o JOIN {$p}survey_question q ON q.question_id = o.question_id WHERE q.survey_id = {$sid}");
            $this->pdo->exec("DELETE FROM {$p}survey_question WHERE survey_id = {$sid}");
            $this->pdo->exec("DELETE FROM {$p}survey_page WHERE survey_id = {$sid}");
            $this->pdo->exec("DELETE FROM {$p}survey WHERE survey_id = {$sid}");
        }
        foreach ($this->fx['auth'] as $id) {
            $this->pdo->exec("DELETE FROM {$p}authorization WHERE authorization_id = " . (int) $id);
        }
        foreach ($this->fx['mundane'] as $id) {
            $this->pdo->exec("DELETE FROM {$p}attendance WHERE mundane_id = " . (int) $id);
            $this->pdo->exec("DELETE FROM {$p}session WHERE mundane_id = " . (int) $id);
            $this->pdo->exec("DELETE FROM {$p}mundane WHERE mundane_id = " . (int) $id);
        }
        foreach ($this->fx['park'] as $id) {
            $this->pdo->exec("DELETE FROM {$p}park WHERE park_id = " . (int) $id);
        }
        foreach ($this->fx['kingdom'] as $id) {
            $this->pdo->exec("DELETE FROM {$p}kingdom WHERE kingdom_id = " . (int) $id);
        }
        $this->fx = ['survey' => [], 'mundane' => [], 'auth' => [], 'park' => [], 'kingdom' => []];
    }

    private function kingdom(string $suffix, int $parentId = 0): int
    {
        $st = $this->pdo->prepare('INSERT INTO ' . DB_PREFIX . 'kingdom (name, abbreviation, parent_kingdom_id, active) VALUES (?, ?, ?, \'Active\')');
        $st->execute(['T11SHARE ' . $suffix . ' ' . bin2hex(random_bytes(3)), strtoupper(substr(bin2hex(random_bytes(2)), 0, 3)), $parentId]);
        return $this->fx['kingdom'][] = (int) $this->pdo->lastInsertId();
    }

    private function park(int $kingdomId, string $suffix): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'park
             (kingdom_id, name, abbreviation, url, address, city, province, postal_code,
              google_geocode, latitude, longitude, location, map_url, description, directions, active)
             VALUES (?, ?, ?, \'\', \'\', \'\', \'\', \'\', \'\', 0, 0, \'\', \'\', \'\', \'\', \'Active\')'
        );
        $st->execute([$kingdomId, 'T11SHARE ' . $suffix . ' ' . bin2hex(random_bytes(3)), strtoupper(substr(bin2hex(random_bytes(2)), 0, 3))]);
        return $this->fx['park'][] = (int) $this->pdo->lastInsertId();
    }

    private function player(string $suffix, int $parkId, int $kingdomId): int
    {
        $token = md5('T11SHARE' . $suffix . bin2hex(random_bytes(8)));
        $user  = strtolower('t11share_' . $suffix . '_' . substr($token, 0, 8));
        $st = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'mundane
             (given_name, surname, other_name, username, persona, email, park_id, kingdom_id, token,
              waiver_ext, password_expires, password_salt, xtoken, reeve_qualified_until,
              penalty_box, active, suspended)
             VALUES (\'Test\', ?, \'\', ?, ?, ?, ?, ?, ?, \'\', NOW(), \'\', ?, \'0000-00-00\', 0, 1, 0)'
        );
        $st->execute([$suffix, $user, 'T11SHARE ' . $suffix, $user . '@example.test', $parkId, $kingdomId, $token, md5($token)]);
        return $this->fx['mundane'][] = (int) $this->pdo->lastInsertId();
    }

    private function officer(int $mundaneId, string $type, int $scopeId, string $role = AUTH_CREATE): void
    {
        $st = $this->pdo->prepare('INSERT INTO ' . DB_PREFIX . 'authorization (mundane_id, park_id, kingdom_id, event_id, unit_id, role) VALUES (?, ?, ?, 0, 0, ?)');
        $st->execute([$mundaneId, $type === AUTH_PARK ? $scopeId : 0, $type === AUTH_KINGDOM ? $scopeId : 0, $role]);
        $this->fx['auth'][] = (int) $this->pdo->lastInsertId();
    }

    /**
     * A survey with one optional single-choice question, opened. 'ork' surveys
     * are created as kingdom surveys and re-scoped with SQL (creating one for
     * real needs an ORK admin).
     */
    private function openSurvey(int $ownerUid, string $scopeType, int $scopeId, array $sqlSet = []): int
    {
        $s = new Survey();
        $s->setActor($ownerUid);
        // For 'ork', $scopeId is the kingdom the survey is created under before re-scoping.
        $r = $s->create($ownerUid, $scopeType === 'ork' ? 'kingdom' : $scopeType, $scopeId, 'T11SHARE survey');
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $sid = $this->fx['survey'][] = (int) $r['SurveyId'];
        $page = (int) $this->pdo->query('SELECT page_id FROM ' . DB_PREFIX . 'survey_page WHERE survey_id = ' . $sid . ' LIMIT 1')->fetchColumn();
        $q = $s->questionAdd($sid, $page, 'single', null);
        $this->assertSame(0, $q['Status'], (string) ($q['Error'] ?? ''));
        $s->questionUpdate((int) $q['Question']['question_id'], ['Prompt' => 'T11SHARE pick one']);
        $this->assertSame(0, $s->setStatus($sid, 'open')['Status']);
        if ($scopeType === 'ork') {
            $sqlSet['scope_type'] = 'ork';
            $sqlSet['scope_id'] = 0;
        }
        foreach ($sqlSet as $col => $val) {
            $st = $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'survey SET ' . $col . ' = ? WHERE survey_id = ?');
            $st->execute([$val, $sid]);
        }
        return $sid;
    }

    /** Submit an empty (all-optional) response with the given consent. */
    private function answer(int $surveyId, int $uid, string $consent): array
    {
        $r = (new SurveyResponse())->submit($surveyId, $uid, [], $consent, 30, false);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        return $r;
    }

    private function row(int $surveyId): array
    {
        return (new Survey())->getRow($surveyId);
    }

    private function scalar(string $sql)
    {
        return $this->pdo->query($sql)->fetchColumn();
    }
}
```

- [ ] **Step 2: Write the failing access tests**

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/SurveyOrgFixture.php';

use PHPUnit\Framework\TestCase;

/** Sharing and credits (spec 2026-09-10-survey-sharing-and-credits-design.md). */
final class SurveySharingCreditTest extends TestCase
{
    use SurveyOrgFixture;

    private int $k = 0;          // home kingdom
    private int $kOther = 0;     // another root kingdom
    private int $parkA = 0;
    private int $parkB = 0;
    private int $parkOther = 0;
    private int $kOfficer = 0;
    private int $pOfficerA = 0;
    private int $pOfficerB = 0;
    private int $kOtherOfficer = 0;

    protected function setUp(): void
    {
        $this->setUpFixture();
        $this->k = $this->kingdom('home');
        $this->kOther = $this->kingdom('away');
        $this->parkA = $this->park($this->k, 'a');
        $this->parkB = $this->park($this->k, 'b');
        $this->parkOther = $this->park($this->kOther, 'x');
        $this->kOfficer = $this->player('kofficer', $this->parkA, $this->k);
        $this->officer($this->kOfficer, AUTH_KINGDOM, $this->k);
        $this->pOfficerA = $this->player('pofficera', $this->parkA, $this->k);
        $this->officer($this->pOfficerA, AUTH_PARK, $this->parkA);
        $this->pOfficerB = $this->player('pofficerb', $this->parkB, $this->k);
        $this->officer($this->pOfficerB, AUTH_PARK, $this->parkB);
        $this->kOtherOfficer = $this->player('kother', $this->parkOther, $this->kOther);
        $this->officer($this->kOtherOfficer, AUTH_KINGDOM, $this->kOther);
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture();
    }

    public function testResultsShareIsRefusedOnAParkSurveyAndValidatedElsewhere(): void
    {
        $sid = $this->openSurvey($this->pOfficerA, 'park', $this->parkA);
        $this->assertSame(1, (new Survey())->update($sid, ['ResultsShare' => 'scoped'])['Status']);
        $this->assertSame(0, (new Survey())->update($sid, ['ResultsShare' => 'none'])['Status']);

        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->assertSame(1, (new Survey())->update($ks, ['ResultsShare' => 'bogus'])['Status']);
        $this->assertSame(0, (new Survey())->update($ks, ['ResultsShare' => 'all'])['Status']);
        $this->assertSame('all', $this->row($ks)['results_share']);
    }

    public function testResultsAccessMatrix(): void
    {
        $s = new Survey();
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $parkCtx = ['type' => 'park', 'id' => $this->parkA];

        $this->assertSame('manage', $s->resultsAccess($this->kOfficer, $this->row($ks), null)['level']);
        $this->assertNull($s->resultsAccess($this->pOfficerA, $this->row($ks), $parkCtx), 'none shares nothing');

        $s->update($ks, ['ResultsShare' => 'scoped']);
        $acc = $s->resultsAccess($this->pOfficerA, $this->row($ks), $parkCtx);
        $this->assertSame('shared', $acc['level']);
        $this->assertSame(['shared' => true, 'park_id' => $this->parkA], $acc['lens']);
        $this->assertNull($s->resultsAccess($this->pOfficerA, $this->row($ks), ['type' => 'park', 'id' => $this->parkB]), 'not an officer of park B');
        $this->assertNull($s->resultsAccess($this->pOfficerA, $this->row($ks), ['type' => 'kingdom', 'id' => $this->k]), 'wrong level');
        $this->assertNull($s->resultsAccess($this->kOtherOfficer, $this->row($ks), ['type' => 'park', 'id' => $this->parkOther]), 'not reached');

        $s->update($ks, ['ResultsShare' => 'all']);
        $this->assertSame(['shared' => true], $s->resultsAccess($this->pOfficerA, $this->row($ks), $parkCtx)['lens']);

        $s->setStatus($ks, 'draft');
        $this->assertNull($s->resultsAccess($this->pOfficerA, $this->row($ks), $parkCtx), 'drafts never roll down');
    }

    public function testOrkSurveyRollsDownToKingdomsOnlyAndRespectsTheAudienceList(): void
    {
        $s = new Survey();
        $os = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['results_share' => 'scoped', 'audience_kingdom_ids' => json_encode([$this->k])]);
        $acc = $s->resultsAccess($this->kOfficer, $this->row($os), ['type' => 'kingdom', 'id' => $this->k]);
        $this->assertSame('kingdom', $acc['label']);
        $this->assertSame([$this->k], $acc['lens']['kingdom_ids']);
        $this->assertNull($s->resultsAccess($this->kOtherOfficer, $this->row($os), ['type' => 'kingdom', 'id' => $this->kOther]), 'outside the audience list');
        $this->assertNull($s->resultsAccess($this->pOfficerA, $this->row($os), ['type' => 'park', 'id' => $this->parkA]), 'ORK never rolls to parks');
    }

    public function testKingdomFamilyIncludesPrincipalities(): void
    {
        $pr = $this->kingdom('principality', $this->k);
        $fam = (new Survey())->kingdomFamily($this->k);
        sort($fam);
        $want = [$this->k, $pr];
        sort($want);
        $this->assertSame($want, $fam);
    }
}
```

- [ ] **Step 3: Run to verify failure**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter SurveySharingCreditTest`
Expected: FAIL with "Call to undefined method Survey::resultsAccess()", plus the `ResultsShare` assertions.

- [ ] **Step 4: Implement in `class.Survey.php`**

`UPDATE_FIELDS`, after `DataGateEnabled`:

```php
        'ResultsShare'             => ['results_share', 'share'],
```

`update()` switch, new case before `'color'`:

```php
                case 'share':
                    $v = (string) $raw;
                    if (!in_array($v, ['none', 'scoped', 'all'], true)) {
                        return $this->fail('Choose who may see the results.');
                    }
                    if ($v !== 'none' && (string) $survey['scope_type'] === 'park') {
                        return $this->fail('A park survey has no level below it to share results with.');
                    }
                    $sets[] = $column . ' = \'' . $v . '\'';
                    break;
```

After `canManage()`:

```php
    /** A kingdom and every principality under it (to depth 5), for lenses and list sections. */
    public function kingdomFamily(int $kingdomId): array
    {
        if ($kingdomId <= 0) {
            return [];
        }
        $ids      = [$kingdomId => true];
        $frontier = [$kingdomId];
        for ($depth = 0; $frontier && $depth < 5; $depth++) {
            $next = [];
            foreach ($this->fetchAll('SELECT kingdom_id FROM ' . DB_PREFIX . 'kingdom
                                      WHERE parent_kingdom_id IN (' . implode(',', array_map('intval', $frontier)) . ')') as $c) {
                $cid = (int) $c['kingdom_id'];
                if (!isset($ids[$cid])) {
                    $ids[$cid] = true;
                    $next[]    = $cid;
                }
            }
            $frontier = $next;
        }
        return array_keys($ids);
    }

    /**
     * Who may read this survey's results, and through what lens (sharing spec §2).
     *   manage — canManage on the survey's own scope: everything, as before.
     *   shared — one org level down (ORK -> kingdom, kingdom -> park) when
     *            results_share allows it: charts and stats only, filtered by lens.
     *   null   — nothing.
     * $context is the viewer's org from the results URL, never trusted on its
     * own: it must be the survey's direct child type, be reached by the survey
     * (SurveyCredit::validGrantor) and be an org the viewer holds CREATE on.
     *
     * @param ?array{type:string,id:int} $context
     * @return ?array{level:string, lens:array, label:string, org_name:string}
     */
    public function resultsAccess(int $uid, array $surveyRow, ?array $context): ?array
    {
        if ($this->canManage($uid, $surveyRow)) {
            return ['level' => 'manage', 'lens' => [], 'label' => '', 'org_name' => ''];
        }
        $share = (string) ($surveyRow['results_share'] ?? 'none');
        if ($share === 'none' || $context === null
            || !in_array((string) ($surveyRow['status'] ?? ''), ['open', 'closed'], true)) {
            return null;
        }
        $childType = ['ork' => 'kingdom', 'kingdom' => 'park'][(string) ($surveyRow['scope_type'] ?? '')] ?? '';
        $type      = (string) ($context['type'] ?? '');
        $id        = (int) ($context['id'] ?? 0);
        if ($childType === '' || $type !== $childType || $id <= 0) {
            return null;
        }
        if (!(new SurveyCredit())->validGrantor($surveyRow, $type, $id) || !$this->canCreate($uid, $type, $id)) {
            return null;
        }

        $name = $this->scopeName($type, $id);
        if ($share === 'all') {
            return ['level' => 'shared', 'lens' => ['shared' => true], 'label' => 'all', 'org_name' => $name];
        }
        if ($type === 'kingdom') {
            return ['level' => 'shared', 'lens' => ['shared' => true, 'kingdom_ids' => $this->kingdomFamily($id)], 'label' => 'kingdom', 'org_name' => $name];
        }
        return ['level' => 'shared', 'lens' => ['shared' => true, 'park_id' => $id], 'label' => 'park', 'org_name' => $name];
    }
```

- [ ] **Step 5: Run tests**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter 'SurveySharingCreditTest|SurveyTest'`
Expected: PASS.

- [ ] **Step 6: Lint and commit**

```bash
php -l system/lib/ork3/class.Survey.php tests/Integration/SurveyOrgFixture.php tests/Integration/SurveySharingCreditTest.php
git add system/lib/ork3/class.Survey.php tests/Integration/SurveyOrgFixture.php tests/Integration/SurveySharingCreditTest.php
git commit -m "Enhancement: Survey — results sharing setting and access rules"
```

---

### Task 6: `listForScope` and lens counts

**Model:** opus · effort medium

**Files:**
- Modify: `system/lib/ork3/class.Survey.php`:
  - extract `decorateRows()` from `listManageable()` `:433-475`
  - add `listForScope()` after `listManageable()`
- Test: `tests/Integration/SurveySharingCreditTest.php`

**Interfaces:**
- Consumes:
  - `resultsAccess()`, `kingdomFamily()` (Task 5)
  - `SurveyCredit::validGrantor()`, `orgKingdom()` (Task 4)
  - `SurveyCredit::configKeys()`: add it in this task as a tiny public method (below). Task 9 builds on the same class.
- Produces: `Survey::listForScope(int $uid, ?string $scopeType, ?int $scopeId): array{Rows: array{ork:list, kingdom:list, park:list}, Labels: array{ork:string, kingdom:string, park:string}}`. Each row is a `listManageable()` row plus:
  - `Access` (`manage|shared`)
  - `CanResults` (bool)
  - `ResultsContext` (`'Kingdom/17'|'Park/9'|null`)
  - `ResultsLabel` (`''|'all'|'kingdom'|'park'`)
  - `CreditGrantor` (`'Kingdom/17'|'Park/9'|null`)
  - `CreditOn` (bool)

- [ ] **Step 1: Write the failing tests** (append to `SurveySharingCreditTest`)

```php
    private function ids(array $rows): array
    {
        return array_map(static fn($r) => (int) $r['survey_id'], $rows);
    }

    public function testKingdomPageShowsReachedOrkOpenClosedOwnKingdomAndItsParks(): void
    {
        $ork      = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['audience_kingdom_ids' => json_encode([$this->k])]);
        $orkElse  = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['audience_kingdom_ids' => json_encode([$this->kOther])]);
        $orkDraft = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['status' => 'draft']);
        $mine     = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $parkA    = $this->openSurvey($this->pOfficerA, 'park', $this->parkA);
        $other    = $this->openSurvey($this->kOtherOfficer, 'park', $this->parkOther);

        $list = (new Survey())->listForScope($this->kOfficer, 'kingdom', $this->k);
        $this->assertContains($ork, $this->ids($list['Rows']['ork']));
        $this->assertNotContains($orkElse, $this->ids($list['Rows']['ork']));
        $this->assertNotContains($orkDraft, $this->ids($list['Rows']['ork']));
        $this->assertSame([$mine], $this->ids($list['Rows']['kingdom']));
        $this->assertSame([$parkA], $this->ids($list['Rows']['park']));
        $this->assertNotContains($other, array_merge(...array_map([$this, 'ids'], array_values($list['Rows']))));

        $orkRow = $list['Rows']['ork'][0];
        $this->assertSame('shared', $orkRow['Access']);
        $this->assertSame('Kingdom/' . $this->k, $orkRow['CreditGrantor']);
        $this->assertFalse($orkRow['CanResults'], 'results_share defaults to none');
        $this->assertSame('manage', $list['Rows']['park'][0]['Access']);
        $this->assertSame('Park/' . $this->parkA, $list['Rows']['park'][0]['CreditGrantor'], 'a kingdom acts for its park');
        $this->assertSame('Amtgard', $list['Labels']['ork']);
    }

    public function testParkPageSeesOrkOwnKingdomAndOwnParkOnly(): void
    {
        $ork   = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['results_share' => 'scoped']);
        $kings = $this->openSurvey($this->kOfficer, 'kingdom', $this->k, ['results_share' => 'scoped']);
        $away  = $this->openSurvey($this->kOtherOfficer, 'kingdom', $this->kOther);
        $mineP = $this->openSurvey($this->pOfficerA, 'park', $this->parkA);
        $sibP  = $this->openSurvey($this->pOfficerB, 'park', $this->parkB);

        $list = (new Survey())->listForScope($this->pOfficerA, 'park', $this->parkA);
        $this->assertContains($ork, $this->ids($list['Rows']['ork']));
        $this->assertSame([$kings], $this->ids($list['Rows']['kingdom']));
        $this->assertSame([$mineP], $this->ids($list['Rows']['park']));
        $all = array_merge(...array_map([$this, 'ids'], array_values($list['Rows'])));
        $this->assertNotContains($away, $all);
        $this->assertNotContains($sibP, $all);

        $kRow = $list['Rows']['kingdom'][0];
        $this->assertTrue($kRow['CanResults']);
        $this->assertSame('Park/' . $this->parkA, $kRow['ResultsContext']);
        $this->assertSame('park', $kRow['ResultsLabel']);
        $this->assertFalse($list['Rows']['ork'][0]['CanResults'], 'ORK results never reach parks');
    }

    public function testListForScopeRefusesAnOrgTheViewerCannotActFor(): void
    {
        $list = (new Survey())->listForScope($this->pOfficerA, 'park', $this->parkB);
        $this->assertSame([], array_merge(...array_values($list['Rows'])));
    }

    public function testKingdomLensKeepsTheKingdomsRowsUnderTheExistingSmallGroupRules(): void
    {
        $os = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['results_share' => 'scoped']);
        // 5 full + 2 partial + 1 anonymous at home; 2 full away.
        foreach (['full', 'full', 'full', 'full', 'full', 'partial', 'partial', 'anonymous'] as $i => $c) {
            $this->answer($os, $this->player('home' . $i, $this->parkA, $this->k), $c);
        }
        foreach (['full', 'full'] as $i => $c) {
            $this->answer($os, $this->player('away' . $i, $this->parkOther, $this->kOther), $c);
        }
        // The kingdom officer cannot manage an ORK survey, so they read it shared.
        $acc = (new Survey())->resultsAccess($this->kOfficer, $this->row($os), ['type' => 'kingdom', 'id' => $this->k]);
        $this->assertSame('shared', $acc['level']);
        $out = (new SurveyReport())->sharedResults($os, [], $acc['lens']);
        // Full rows from the kingdom count. The 2 partial rows are left out by
        // base §2 rule 5 (fewer than 5 partial rows in the kingdom under a
        // kingdom filter). Anonymous rows (no kingdom) and the away kingdom are
        // outside the lens.
        $this->assertSame(5, (int) $out['summary']['responses']);
        $this->assertFalse((bool) $out['summary']['suppressed']);
        $this->assertNull($out['summary']['starts']);
        $this->assertNull($out['summary']['excluded_anonymous']);
    }

    public function testParkLensCountsOnlyAnyOrkDataFromThatParkAndSuppressesUnderFive(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k, ['results_share' => 'scoped']);
        foreach (['full', 'full', 'partial', 'anonymous'] as $i => $c) {
            $this->answer($ks, $this->player('pa' . $i, $this->parkA, $this->k), $c);
        }
        $this->answer($ks, $this->player('pb', $this->parkB, $this->k), 'full');
        $acc = (new Survey())->resultsAccess($this->pOfficerA, $this->row($ks), ['type' => 'park', 'id' => $this->parkA]);
        $out = (new SurveyReport())->sharedResults($ks, [], $acc['lens']);
        $this->assertSame(2, (int) $out['summary']['responses']);
        $this->assertTrue((bool) $out['summary']['suppressed'], 'a lens view under 5 is suppressed');
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter SurveySharingCreditTest`
Expected: FAIL with "Call to undefined method Survey::listForScope()". The two lens tests already pass after Tasks 2, 3 and 5; keep them as regression guards.

- [ ] **Step 3: Implement**

In `class.SurveyCredit.php`, add:

```php
    /** @return array<string,true> "<survey_id>:<grantor_type>:<grantor_id>" for every config of these surveys */
    public function configKeys(array $surveyIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $surveyIds)));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach ($this->fetchAll('SELECT survey_id, grantor_type, grantor_id FROM ' . DB_PREFIX . 'survey_credit
                                  WHERE survey_id IN (' . implode(',', $ids) . ')') as $r) {
            $out[(int) $r['survey_id'] . ':' . $r['grantor_type'] . ':' . (int) $r['grantor_id']] = true;
        }
        return $out;
    }
```

In `class.Survey.php`, replace the decoration block at the end of `listManageable()` (from `// Resolve scope names in two queries` to `return $rows;`) with `return $this->decorateRows($rows);`, and move that block verbatim into:

```php
    /** ScopeName, ResponseCount and Locked for a list of survey rows, in two name queries. */
    private function decorateRows(array $rows): array
    {
        // …the moved block, ending in `return $rows;`
    }
```

Then add:

```php
    /**
     * The survey list for one org page, in three sections (sharing spec §1).
     * Kingdom K: ORK surveys reaching K (open/closed; ORK admins see every
     * status), K's and its principalities' surveys, and every park survey in
     * that family. Park P: ORK surveys reaching P's kingdom and kingdom surveys
     * reaching P's players (both open/closed), and P's own surveys. Unscoped:
     * everything the viewer manages, grouped by scope. Refuses (empty) an org
     * the viewer holds no CREATE authority for.
     */
    public function listForScope(int $uid, ?string $scopeType, ?int $scopeId): array
    {
        $out = [
            'Rows'   => ['ork' => [], 'kingdom' => [], 'park' => []],
            'Labels' => ['ork' => 'Amtgard', 'kingdom' => 'Kingdoms', 'park' => 'Parks'],
        ];
        if ($uid <= 0) {
            return $out;
        }

        $credit = new SurveyCredit();
        $page   = null;
        if ($scopeType === null) {
            $rows = $this->listManageable($uid);
        } else {
            $scopeId = (int) $scopeId;
            if (!in_array($scopeType, ['kingdom', 'park'], true) || !$this->canCreate($uid, $scopeType, $scopeId)) {
                return $out;
            }
            $page = ['type' => $scopeType, 'id' => $scopeId];
            $rows = $this->decorateRows($this->scopeRows($uid, $page, $credit, $out['Labels']));
        }

        $keys = $credit->configKeys(array_column($rows, 'survey_id'));
        foreach ($rows as $row) {
            $sid    = (int) $row['survey_id'];
            $manage = $this->canManage($uid, $row);
            $acc    = ($page !== null && !$manage) ? $this->resultsAccess($uid, $row, $page) : null;

            $grantor = null;
            if ($page !== null && $credit->validGrantor($row, $page['type'], $page['id'])) {
                $grantor = $page;
            } elseif ($manage && in_array((string) $row['scope_type'], ['kingdom', 'park'], true)) {
                $grantor = ['type' => (string) $row['scope_type'], 'id' => (int) $row['scope_id']];
            }

            $row['Access']         = $manage ? 'manage' : 'shared';
            $row['CanResults']     = $manage || $acc !== null;
            $row['ResultsContext'] = $acc !== null ? ucfirst($page['type']) . '/' . $page['id'] : null;
            $row['ResultsLabel']   = $acc['label'] ?? '';
            $row['CreditGrantor']  = $grantor !== null ? ucfirst($grantor['type']) . '/' . $grantor['id'] : null;
            $row['CreditOn']       = $grantor !== null && isset($keys[$sid . ':' . $grantor['type'] . ':' . $grantor['id']]);

            $out['Rows'][(string) $row['scope_type']][] = $row;
        }
        return $out;
    }

    /**
     * Raw ork_survey rows for one org page (listForScope), plus its section labels.
     *
     * @param array{type:string,id:int} $page
     * @param array<string,string>      $labels filled in place
     */
    private function scopeRows(int $uid, array $page, SurveyCredit $credit, array &$labels): array
    {
        $live  = $this->isOrkAdmin($uid) ? '' : ' AND status IN (\'open\', \'closed\')';
        $order = ' ORDER BY created_at DESC, survey_id DESC';
        $rows  = [];

        // Amtgard: ORK surveys whose audience reaches this org (JSON list, so filtered here).
        foreach ($this->fetchAll('SELECT * FROM ' . DB_PREFIX . 'survey WHERE scope_type = \'ork\'' . $live . $order) as $r) {
            if ($credit->validGrantor($r, $page['type'], $page['id'])) {
                $rows[] = $r;
            }
        }

        if ($page['type'] === 'kingdom') {
            $fam = implode(',', array_map('intval', $this->kingdomFamily($page['id'])));
            $labels['kingdom'] = $this->scopeName('kingdom', $page['id']);
            $labels['park']    = 'Parks';
            $rows = array_merge(
                $rows,
                $this->fetchAll('SELECT * FROM ' . DB_PREFIX . 'survey WHERE scope_type = \'kingdom\' AND scope_id IN (' . $fam . ')' . $order),
                $this->fetchAll('SELECT * FROM ' . DB_PREFIX . 'survey WHERE scope_type = \'park\' AND scope_id IN (
                                    SELECT park_id FROM ' . DB_PREFIX . 'park WHERE kingdom_id IN (' . $fam . '))' . $order)
            );
            return $rows;
        }

        [$kingdomId, $parentId] = $credit->orgKingdom('park', $page['id']);
        $reach = array_filter([$kingdomId, $parentId]);
        $labels['kingdom'] = $kingdomId > 0 ? $this->scopeName('kingdom', $kingdomId) : 'Kingdom';
        $labels['park']    = $this->scopeName('park', $page['id']);
        if ($reach) {
            $rows = array_merge($rows, $this->fetchAll('SELECT * FROM ' . DB_PREFIX . 'survey WHERE scope_type = \'kingdom\'
                                                         AND scope_id IN (' . implode(',', array_map('intval', $reach)) . ')' . $live . $order));
        }
        return array_merge($rows, $this->fetchAll('SELECT * FROM ' . DB_PREFIX . 'survey WHERE scope_type = \'park\'
                                                   AND scope_id = ' . (int) $page['id'] . $order));
    }
```

- [ ] **Step 4: Run tests**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter 'SurveySharingCreditTest|SurveyTest|SurveyPureTest'`
Expected: PASS, and `listManageable` behaviour is unchanged.

- [ ] **Step 5: Lint and commit**

```bash
php -l system/lib/ork3/class.Survey.php system/lib/ork3/class.SurveyCredit.php
git add system/lib/ork3/class.Survey.php system/lib/ork3/class.SurveyCredit.php tests/Integration/SurveySharingCreditTest.php
git commit -m "Enhancement: Survey — three-section survey lists per org"
```

---

### Task 7: `Attendance::AddSystemCredit()`

**Model:** sonnet · effort medium

**Files:**
- Modify: `system/lib/ork3/class.Attendance.php` (new public method after `AddAttendance()`, `:168`)
- Test: `tests/Integration/SurveySharingCreditTest.php`

**Interfaces:**
- Produces: `Attendance::AddSystemCredit(array $r): array{Status:int, Error:string, AttendanceId?:int}`. The keys of `$r`:
  - `MundaneId`, `ClassId`, `Date` ('Y-m-d')
  - `ParkId`, `KingdomId`, `EventId`, `EventCalendarDetailId` (all ints, 0 = none)
  - `Credits` (float), `Note` (≤ 20), `ByWhomId`, `EntryMethod` (only `'survey'` accepted)

- [ ] **Step 1: Write the failing test**

```php
    public function testAddSystemCreditWritesEveryColumnAndBustsNothingElse(): void
    {
        $uid = $this->player('credit', $this->parkA, $this->k);
        $r = Ork3::$Lib->attendance->AddSystemCredit([
            'MundaneId' => $uid, 'ClassId' => 6, 'Date' => '2026-09-05', 'ParkId' => $this->parkA, 'KingdomId' => $this->k,
            'EventId' => 0, 'EventCalendarDetailId' => 0, 'Credits' => 1, 'Note' => 'Survey #1', 'ByWhomId' => $this->kOfficer,
            'EntryMethod' => 'survey',
        ]);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $row = $this->pdo->query('SELECT * FROM ' . DB_PREFIX . 'attendance WHERE attendance_id = ' . (int) $r['AttendanceId'])->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('2026-09-05', $row['date']);
        $this->assertSame('survey', $row['entry_method']);
        $this->assertSame('Survey #1', $row['note']);
        $this->assertSame((string) $this->kOfficer, (string) $row['by_whom_id']);
        $this->assertSame('2026', (string) $row['date_year']);
        $this->assertSame('9', (string) $row['date_month']);
        $this->assertNotSame('0', (string) $row['date_week3']);
        $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'attendance WHERE attendance_id = ' . (int) $r['AttendanceId']);
    }

    public function testAddSystemCreditRefusesAnyOtherEntryMethodOrABadRequest(): void
    {
        $uid = $this->player('credit2', $this->parkA, $this->k);
        $base = ['MundaneId' => $uid, 'ClassId' => 6, 'Date' => '2026-09-05', 'ParkId' => $this->parkA, 'KingdomId' => $this->k,
                 'EventId' => 0, 'EventCalendarDetailId' => 0, 'Credits' => 1, 'Note' => 'x', 'ByWhomId' => 1];
        $this->assertSame(1, Ork3::$Lib->attendance->AddSystemCredit($base + ['EntryMethod' => 'manual'])['Status']);
        $this->assertSame(1, Ork3::$Lib->attendance->AddSystemCredit(['Date' => 'nope', 'EntryMethod' => 'survey'] + $base)['Status']);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter 'testAddSystemCredit'`
Expected: FAIL with "Call to undefined method Attendance::AddSystemCredit()".

- [ ] **Step 3: Implement** (normalize-first check on `class.Attendance.php` before editing)

```php
    /**
     * Insert one attendance credit on the SYSTEM's behalf (survey credits,
     * docs/superpowers/specs/2026-09-10-survey-sharing-and-credits-design.md §3.3).
     *
     * NO TOKEN AND NO AUTHORITY CHECK: the caller has already decided the credit
     * is authorized (SurveyCredit checks the officer who switched the config
     * on, whose id arrives as ByWhomId). Only the entry methods listed below
     * may be written here. Every NOT NULL column is named because production
     * runs sql_mode='' (an omitted column would silently become '' or 0).
     * Does not open a transaction: the caller's transaction covers it.
     */
    public function AddSystemCredit(array $r): array
    {
        $mundaneId = (int) ($r['MundaneId'] ?? 0);
        $classId   = (int) ($r['ClassId'] ?? 0);
        $ts        = strtotime((string) ($r['Date'] ?? ''));
        $entry     = (string) ($r['EntryMethod'] ?? '');
        $credits   = (float) ($r['Credits'] ?? 0);
        if ($mundaneId <= 0 || $classId <= 0 || !$ts || $credits <= 0 || !in_array($entry, ['survey'], true)) {
            return ['Status' => 1, 'Error' => 'Invalid system credit request.'];
        }

        $day   = date('Y-m-d', $ts);
        $parts = $this->_computeDatePartitions($day);
        $esc   = static function ($v): string {
            return str_replace(["'", '\\'], ["''", '\\\\'], (string) $v);
        };

        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            'INSERT INTO ' . DB_PREFIX . 'attendance
             (mundane_id, class_id, date, date_year, date_month, date_week3, date_week6,
              park_id, kingdom_id, event_id, event_calendardetail_id, credits,
              persona, flavor, note, by_whom_id, entry_method, entered_at)
             VALUES (' . $mundaneId . ', ' . $classId . ", '" . $day . "', "
            . (int) $parts['date_year'] . ', ' . (int) $parts['date_month'] . ', '
            . (int) $parts['date_week3'] . ', ' . (int) $parts['date_week6'] . ', '
            . (int) ($r['ParkId'] ?? 0) . ', ' . (int) ($r['KingdomId'] ?? 0) . ', '
            . (int) ($r['EventId'] ?? 0) . ', ' . (int) ($r['EventCalendarDetailId'] ?? 0) . ', '
            . sprintf('%.2f', $credits) . ", '', '', '"
            . $esc(mb_substr((string) ($r['Note'] ?? ''), 0, 20)) . "', "
            . (int) ($r['ByWhomId'] ?? 0) . ", '" . $entry . "', '" . date('Y-m-d H:i:s') . "')"
        );
        if (!$ok) {
            return ['Status' => 1, 'Error' => 'The credit could not be saved.'];
        }

        $this->db->Clear();
        $rs = $this->db->DataSet('SELECT LAST_INSERT_ID() AS new_id');
        $id = ($rs && $rs->Next()) ? (int) $rs->new_id : 0;
        if ($id <= 0) {
            return ['Status' => 1, 'Error' => 'The credit could not be saved.'];
        }

        $this->bustPlayerAttendanceCaches($mundaneId);
        return ['Status' => 0, 'Error' => '', 'AttendanceId' => $id];
    }
```

- [ ] **Step 4: Run tests**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter 'testAddSystemCredit'`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
php -l system/lib/ork3/class.Attendance.php
git add system/lib/ork3/class.Attendance.php tests/Integration/SurveySharingCreditTest.php
git commit -m "Enhancement: Survey — token-free system attendance credit"
```

---

### Task 8: `EventPlanning::CreateSystemEvent()`

**Model:** sonnet · effort medium

**Files:**
- Modify: `system/lib/ork3/class.EventPlanning.php` (new public method after `CreateEventWithCopy()`, which ends at `:1496`)
- Test: `tests/Integration/SurveySharingCreditTest.php`

**Interfaces:**
- Produces: `EventPlanning::CreateSystemEvent(array $r): array{Status:int, Error:string, EventId?:int, DetailId?:int}`. The keys of `$r`:
  - `KingdomId` (ignored when `ParkId` > 0; derived from the park), `ParkId`
  - `Name`, `Date` ('Y-m-d')
  - `Description`, `Url`, `UrlName`

- [ ] **Step 1: Write the failing test**

```php
    public function testCreateSystemEventMakesAOneDayPublishedParkEvent(): void
    {
        $r = Ork3::$Lib->eventplanning->CreateSystemEvent([
            'KingdomId' => $this->kOther,            // ignored for a park event
            'ParkId' => $this->parkA, 'Name' => 'Survey Credit - T11SHARE', 'Date' => '2026-09-05',
            'Description' => 'desc', 'Url' => 'javascript:alert(1)', 'UrlName' => 'Take the survey',
        ]);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $e = $this->pdo->query('SELECT * FROM ' . DB_PREFIX . 'event WHERE event_id = ' . (int) $r['EventId'])->fetch(PDO::FETCH_ASSOC);
        $d = $this->pdo->query('SELECT * FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = ' . (int) $r['DetailId'])->fetch(PDO::FETCH_ASSOC);
        try {
            $this->assertSame((string) $this->k, (string) $e['kingdom_id'], 'kingdom comes from the park');
            $this->assertSame((string) $this->parkA, (string) $e['park_id']);
            $this->assertSame('published', $e['status']);
            $this->assertSame('2026-09-05 00:00:00', $d['event_start']);
            $this->assertSame('2026-09-05 23:59:59', $d['event_end']);
            $this->assertSame((string) $this->parkA, (string) $d['at_park_id']);
            $this->assertSame('Other', $d['event_type']);
            $this->assertSame('', $d['url'], 'non-http(s) URLs are dropped');
        } finally {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_id = ' . (int) $r['EventId']);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event WHERE event_id = ' . (int) $r['EventId']);
        }
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter testCreateSystemEvent`
Expected: FAIL with "Call to undefined method EventPlanning::CreateSystemEvent()".

- [ ] **Step 3: Implement** (normalize-first check on `class.EventPlanning.php`)

```php
    /**
     * Create a published one-day event + occurrence on the SYSTEM's behalf
     * (survey credit events, sharing-and-credits spec §3.4).
     *
     * NO TOKEN AND NO AUTHORITY CHECK: the caller (SurveyCredit) has already
     * authorized it. Raw inserts like CreateEventWithCopy: every NOT NULL column
     * named (sql_mode=''), no geocoding call, scope caches busted. A park event's
     * kingdom is always the park's own. The one-day window keeps the attendance
     * pages from offering it as "currently happening". Opens its own
     * transaction: never call it inside another.
     */
    public function CreateSystemEvent(array $r): array
    {
        $parkId    = (int) ($r['ParkId'] ?? 0);
        $kingdomId = (int) ($r['KingdomId'] ?? 0);
        $name      = mb_substr(trim((string) ($r['Name'] ?? '')), 0, 100);
        $ts        = strtotime((string) ($r['Date'] ?? ''));
        if ($parkId > 0) {
            $this->db->Clear();
            $pk = $this->db->DataSet('SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = ' . $parkId . ' LIMIT 1');
            $kingdomId = ($pk && $pk->Next()) ? (int) $pk->kingdom_id : 0;
        }
        if ($kingdomId <= 0 || $name === '' || !$ts) {
            return ['Status' => 1, 'Error' => 'Invalid system event request.'];
        }

        $day = date('Y-m-d', $ts);
        $url = trim((string) ($r['Url'] ?? ''));
        if ($url !== '' && !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            $url = '';
        }

        $this->db->Clear();
        $this->db->Execute('START TRANSACTION');
        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            'INSERT INTO ' . DB_PREFIX . "event (kingdom_id, park_id, mundane_id, unit_id, name, has_heraldry, status)
             VALUES (" . $kingdomId . ', ' . $parkId . ", 0, 0, '" . $this->sq($name) . "', 0, 'published')"
        );
        $eventId = $ok ? $this->lastId() : 0;
        if ($eventId <= 0) {
            $this->db->Clear();
            $this->db->Execute('ROLLBACK');
            return ['Status' => 1, 'Error' => 'The event could not be created.'];
        }

        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            'INSERT INTO ' . DB_PREFIX . "event_calendardetail
             (event_id, at_park_id, current, price, event_start, event_end, description, url, url_name,
              address, province, postal_code, city, country, map_url, map_url_name,
              google_geocode, location, latitude, longitude, event_type)
             VALUES (" . $eventId . ', ' . ($parkId > 0 ? $parkId : 'NULL') . ", 1, 0, '"
            . $day . " 00:00:00', '" . $day . " 23:59:59', '" . $this->sq((string) ($r['Description'] ?? '')) . "', '"
            . $this->sq($url) . "', '" . $this->sq(mb_substr((string) ($r['UrlName'] ?? ''), 0, 40)) . "',
              '', '', '', '', '', '', '', '', '', 0, 0, 'Other')"
        );
        $detailId = $ok ? $this->lastId() : 0;
        if ($detailId <= 0) {
            $this->db->Clear();
            $this->db->Execute('ROLLBACK');
            return ['Status' => 1, 'Error' => 'The event occurrence could not be created.'];
        }

        $this->db->Clear();
        $this->db->Execute('COMMIT');
        $this->bustEventScopeCaches($eventId);

        return ['Status' => 0, 'Error' => '', 'EventId' => $eventId, 'DetailId' => $detailId];
    }

    private function lastId(): int
    {
        $this->db->Clear();
        $rs = $this->db->DataSet('SELECT LAST_INSERT_ID() AS new_id');
        return ($rs && $rs->Next()) ? (int) $rs->new_id : 0;
    }
```

(If a private `lastId()` already exists in `EventPlanning`, reuse it instead: `grep -n "function lastId\|LAST_INSERT_ID" system/lib/ork3/class.EventPlanning.php`.)

- [ ] **Step 4: Run tests**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter testCreateSystemEvent`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
php -l system/lib/ork3/class.EventPlanning.php
git add system/lib/ork3/class.EventPlanning.php tests/Integration/SurveySharingCreditTest.php
git commit -m "Enhancement: Survey — token-free one-day system event"
```

---

### Task 9: Credit engine (`status`, `enable`, `reconcile`, `grantFor`, events)

**Model:** opus · effort high

**Files:**
- Modify: `system/lib/ork3/class.SurveyCredit.php`
- Test: `tests/Integration/SurveySharingCreditTest.php`

**Interfaces:**
- Consumes:
  - `Attendance::AddSystemCredit()` (Task 7), `EventPlanning::CreateSystemEvent()` (Task 8)
  - `Attendance::GetPlayerLastClass(['MundaneId'=>int]): int`
  - `Survey::canCreate()`, `canManage()`, `scopeName()`, `setActor()`, `logActivity()`, `getRow()`
- Produces (all public on `SurveyCredit`; envelopes use `Status` / `Error`):
  - Config reads:
    - `configs(int $surveyId): list<array>`
    - `hasConfigs(int $surveyId): bool`
    - `surveysWithConfigs(): list<int>`
  - `status(int $uid, int $surveyId, ?array $grantor): array` → `['Status'=>0, 'Credit'=>{survey_title, survey_status, event_name, start_date, gate_enabled, configs[], mine|null, pending}]`, or Status 1/3
  - `enable(int $uid, int $surveyId, ?array $grantor, string $mode, bool $confirm): array` → `['Status'=>0, 'CreditId', 'Granted', 'SkippedNoPark', 'Pending']`
  - `reconcileAs(int $uid, int $surveyId, ?array $grantor): array` (authorized wrapper) and `reconcile(int $surveyId): array{Granted, SkippedNoPark, Pending}`
  - `grantFor(int $surveyId, int $uid): string` → `'granted'|'pending'|'none'`
  - `onOpened(int $surveyId): void`
  - `creditAvailableFor(array $surveyRow, int $uid): bool`
  - `$grantor` is always `['type'=>'kingdom'|'park','id'=>int]` or `null`.

> **Amended by the Tasks 1–11 fixer** (the code below is the original draft; the repo is authoritative):
> - `enable()` checks the grantor before authority: an org the survey does not reach (or a null / non-org grantor) is status 1, and a real grantor without `canCreate` is status 3 (spec §5). A private `isGrantor()` holds the grantor half of `canActFor()`.
> - `status()`'s `pending` counts an owed response only when its winning config (coverage over every config) is one the viewer is shown, so a hidden config's count never leaks.
> - New `creditAvailableMap(array $surveyRows, int $uid): array<int,bool>` resolves a list in at most three queries; `creditAvailableFor()` delegates to it and `SurveyResponse::availableFor()` uses it (no per-survey query pair).

- [ ] **Step 1: Write the failing tests** (append)

```php
    private function credit(): SurveyCredit
    {
        return new SurveyCredit();
    }

    private function grants(int $surveyId): array
    {
        return $this->pdo->query('SELECT g.mundane_id, a.* FROM ' . DB_PREFIX . 'survey_credit_grant g
                                  JOIN ' . DB_PREFIX . 'attendance a ON a.attendance_id = g.attendance_id
                                  WHERE g.survey_id = ' . $surveyId . ' ORDER BY g.mundane_id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testEnableBackfillsAnyOrkDataOnlyAtTheHomeParkOnTheDayTaken(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $full = $this->player('full', $this->parkA, $this->k);
        $this->answer($ks, $full, 'full');
        $this->answer($ks, $this->player('part', $this->parkA, $this->k), 'partial');
        $this->answer($ks, $this->player('anon', $this->parkA, $this->k), 'anonymous');
        $submitted = (string) $this->scalar('SELECT DATE(submitted_at) FROM ' . DB_PREFIX . 'survey_response WHERE survey_id = ' . $ks . ' AND mundane_id = ' . $full);

        $r = $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertSame(1, $r['Granted']);

        $g = $this->grants($ks);
        $this->assertCount(1, $g);
        $this->assertSame((string) $full, (string) $g[0]['mundane_id']);
        $this->assertSame($submitted, $g[0]['date']);
        $this->assertSame((string) $this->parkA, (string) $g[0]['park_id']);
        $this->assertSame((string) $this->k, (string) $g[0]['kingdom_id']);
        $this->assertSame('6', (string) $g[0]['class_id'], 'no prior attendance: Color');
        $this->assertSame('Survey #' . $ks, $g[0]['note']);
        $this->assertSame('survey', $g[0]['entry_method']);
        $this->assertSame((string) $this->kOfficer, (string) $g[0]['by_whom_id']);
        $this->assertSame('1.00', number_format((float) $g[0]['credits'], 2));
    }

    public function testEnableIsPermanentAndRefusedTwiceOrUnconfirmedOrGateOff(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $g = ['type' => 'kingdom', 'id' => $this->k];
        $this->assertSame(1, $this->credit()->enable($this->kOfficer, $ks, $g, 'home_park', false)['Status'], 'needs confirmation');
        $this->assertSame(1, $this->credit()->enable($this->kOfficer, $ks, $g, 'teleport', true)['Status'], 'unknown mode');
        $this->assertSame(3, $this->credit()->enable($this->pOfficerA, $ks, $g, 'home_park', true)['Status'], 'not a kingdom officer');
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $ks, $g, 'home_park', true)['Status']);
        $this->assertSame(1, $this->credit()->enable($this->kOfficer, $ks, $g, 'event', true)['Status'], 'configs are permanent');

        $gateOff = $this->openSurvey($this->kOfficer, 'kingdom', $this->k, ['data_gate_enabled' => 0]);
        $this->assertSame(1, $this->credit()->enable($this->kOfficer, $gateOff, $g, 'home_park', true)['Status']);
    }

    public function testLiveGrantAfterEnableAndReconcileIsIdempotent(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $uid = $this->player('live', $this->parkB, $this->k);
        $this->answer($ks, $uid, 'full');
        $this->assertSame('granted', $this->credit()->grantFor($ks, $uid));
        $this->assertSame('granted', $this->credit()->grantFor($ks, $uid), 'second call is a no-op');
        $this->assertCount(1, $this->grants($ks));
        $this->assertSame(['Granted' => 0, 'SkippedNoPark' => 0, 'Pending' => 0], $this->credit()->reconcile($ks));
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'attendance WHERE note = \'Survey #' . $ks . '\''));
    }

    public function testOneCreditPerPlayerWhenKingdomAndParkBothGrant(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $uid = $this->player('both', $this->parkA, $this->k);
        $this->answer($ks, $uid, 'full');
        $this->assertSame(0, $this->credit()->enable($this->pOfficerA, $ks, ['type' => 'park', 'id' => $this->parkA], 'home_park', true)['Status']);
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'event', true)['Status']);
        $g = $this->grants($ks);
        $this->assertCount(1, $g);
        $this->assertSame('0', (string) $g[0]['event_id'], 'the park config came first');
    }

    public function testEventModeCreatesOneEventDatedTheStartAndCreditsVisitors(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $visitor = $this->player('visitor', $this->parkOther, $this->kOther);
        $this->answer($ks, $this->player('local', $this->parkA, $this->k), 'full');
        // A visitor can only answer an event-audience survey; the coverage rule is what's under test here.
        $this->pdo->exec('INSERT INTO ' . DB_PREFIX . "survey_response (survey_id, consent, mundane_id, kingdom_id, park_id, is_test, submitted_at)
                          VALUES ({$ks}, 'full', {$visitor}, {$this->kOther}, {$this->parkOther}, 0, NOW())");

        $r = $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'event', true);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertSame(2, $r['Granted']);

        $cfg = $this->pdo->query('SELECT * FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $ks)->fetch(PDO::FETCH_ASSOC);
        $this->assertGreaterThan(0, (int) $cfg['event_calendardetail_id']);
        $start = SurveyCredit::startDate($this->row($ks));
        foreach ($this->grants($ks) as $g) {
            $this->assertSame($start, $g['date']);
            $this->assertSame((string) $cfg['event_id'], (string) $g['event_id']);
        }
        $name = (string) $this->scalar('SELECT name FROM ' . DB_PREFIX . 'event WHERE event_id = ' . (int) $cfg['event_id']);
        $this->assertSame('Survey Credit - T11SHARE survey', $name);
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'event WHERE name = ' . $this->pdo->quote($name) . ' AND event_id = ' . (int) $cfg['event_id']));
    }

    public function testDraftOwnerConfigGetsItsEventOnFirstOpen(): void
    {
        $s = new Survey();
        $r = $s->create($this->kOfficer, 'kingdom', $this->k, 'T11SHARE draft');
        $sid = $this->fx['survey'][] = (int) $r['SurveyId'];
        $this->assertSame(0, $this->credit()->enable($this->kOfficer, $sid, ['type' => 'kingdom', 'id' => $this->k], 'event', true)['Status']);
        $this->assertNull($this->scalar('SELECT event_id FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $sid) ?: null);

        $page = (int) $this->scalar('SELECT page_id FROM ' . DB_PREFIX . 'survey_page WHERE survey_id = ' . $sid . ' LIMIT 1');
        $q = $s->questionAdd($sid, $page, 'single', null);
        $s->questionUpdate((int) $q['Question']['question_id'], ['Prompt' => 'T11SHARE q']);
        $this->credit()->onOpened($sid);   // Task 10 wires this into setStatus(); called directly here
        $this->assertNull($this->scalar('SELECT event_id FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $sid) ?: null, 'still a draft: no start date');
        $s->setStatus($sid, 'open');
        $this->credit()->onOpened($sid);
        $this->assertGreaterThan(0, (int) $this->scalar('SELECT event_id FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $sid));
    }

    public function testNonOwnerCannotEnableOnADraftAndStatusHidesUnrelatedConfigs(): void
    {
        $os = $this->openSurvey($this->kOfficer, 'ork', $this->k, ['status' => 'draft']);
        $this->assertSame(1, $this->credit()->enable($this->kOfficer, $os, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true)['Status']);

        $open = $this->openSurvey($this->kOfficer, 'ork', $this->k);
        $this->credit()->enable($this->kOtherOfficer, $open, ['type' => 'kingdom', 'id' => $this->kOther], 'home_park', true);
        $st = $this->credit()->status($this->kOfficer, $open, ['type' => 'kingdom', 'id' => $this->k]);
        $this->assertSame(0, $st['Status']);
        $this->assertSame([], $st['Credit']['configs'], "another kingdom's config is not shown");
        $this->assertTrue($st['Credit']['mine']['can_enable']);
        $this->assertArrayHasKey('home_park', $st['Credit']['mine']['preview']);
    }

    public function testCreditAvailableForFollowsCoverage(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $uid = $this->player('avail', $this->parkB, $this->k);
        $this->assertFalse($this->credit()->creditAvailableFor($this->row($ks), $uid));
        $this->credit()->enable($this->pOfficerA, $ks, ['type' => 'park', 'id' => $this->parkA], 'home_park', true);
        $this->assertFalse($this->credit()->creditAvailableFor($this->row($ks), $uid), 'park A does not cover park B');
        $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $this->assertTrue($this->credit()->creditAvailableFor($this->row($ks), $uid));
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter SurveySharingCreditTest`
Expected: FAIL with "Call to undefined method SurveyCredit::enable()" (and the others).

- [ ] **Step 3: Implement** (append to `class.SurveyCredit.php`, above the SQL helpers)

```php
    // -----------------------------------------------------------------------
    // Configs
    // -----------------------------------------------------------------------

    /** @return list<array<string,mixed>> every config of a survey, earliest first */
    public function configs(int $surveyId): array
    {
        return $this->fetchAll('SELECT * FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . (int) $surveyId
            . ' ORDER BY enabled_at ASC, credit_id ASC');
    }

    public function hasConfigs(int $surveyId): bool
    {
        return $this->fetchRow('SELECT 1 AS ok FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . (int) $surveyId . ' LIMIT 1') !== null;
    }

    /** @return list<int> surveys with at least one config (the cron sweep's work list) */
    public function surveysWithConfigs(): array
    {
        return array_map('intval', array_column(
            $this->fetchAll('SELECT DISTINCT survey_id FROM ' . DB_PREFIX . 'survey_credit ORDER BY survey_id'),
            'survey_id'
        ));
    }

    // -----------------------------------------------------------------------
    // Panel
    // -----------------------------------------------------------------------

    /**
     * Everything the Attendance credit panel shows (§3.6). A manager of the
     * survey sees every config; anyone acting for $grantor sees the configs
     * related to their org (their own, their kingdom chain, parks under their
     * kingdom, and the owner's). Other kingdoms' configs never show, because
     * their credit counts would leak how many of that kingdom answered.
     */
    public function status(int $uid, int $surveyId, ?array $grantor): array
    {
        $survey = $this->survey()->getRow($surveyId);
        if ($survey === null) {
            return $this->fail('Survey not found.');
        }
        $manage = $this->survey()->canManage($uid, $survey);
        $acting = $this->canActFor($uid, $survey, $grantor);
        if (!$manage && !$acting) {
            return $this->denied('You cannot manage attendance credits for this survey.');
        }
        if (!$acting) {
            $grantor = null;
        }

        $configs  = $this->configs($surveyId);
        $parentOf = $this->parentMap();
        $counts   = [];
        foreach ($this->fetchAll('SELECT credit_id, COUNT(*) AS n FROM ' . DB_PREFIX . 'survey_credit_grant
                                  WHERE survey_id = ' . (int) $surveyId . ' GROUP BY credit_id') as $r) {
            $counts[(int) $r['credit_id']] = (int) $r['n'];
        }

        $visible = [];
        foreach ($configs as $c) {
            if ($manage || $this->related($c, $grantor, $survey)) {
                $visible[] = $this->configOut($c, $counts[(int) $c['credit_id']] ?? 0);
            }
        }

        $mine = null;
        if ($grantor !== null) {
            $own = null;
            foreach ($configs as $c) {
                if ($c['grantor_type'] === $grantor['type'] && (int) $c['grantor_id'] === (int) $grantor['id']) {
                    $own = $c;
                }
            }
            $problem = $own !== null ? '' : $this->enableProblem($survey, $grantor);
            $mine = [
                'grantor_type'   => $grantor['type'],
                'grantor_id'     => (int) $grantor['id'],
                'name'           => $this->survey()->scopeName($grantor['type'], (int) $grantor['id']),
                'config_id'      => $own !== null ? (int) $own['credit_id'] : null,
                'can_enable'     => $own === null && $problem === '',
                'blocked_reason' => $problem,
                'covered_by'     => $own === null ? $this->coveredBy($configs, $grantor, $survey) : null,
                'preview'        => $own === null ? [
                    'home_park' => $this->preview($survey, $configs, $grantor, 'home_park', $parentOf),
                    'event'     => $this->preview($survey, $configs, $grantor, 'event', $parentOf),
                ] : null,
            ];
        }

        return $this->ok(['Credit' => [
            'survey_title'  => (string) $survey['title'],
            'survey_status' => (string) $survey['status'],
            'event_name'    => self::eventName((string) $survey['title']),
            'start_date'    => self::startDate($survey),
            'gate_enabled'  => !empty($survey['data_gate_enabled']),
            'configs'       => $visible,
            'mine'          => $mine,
            'pending'       => $this->pendingCount($survey, $configs, $parentOf),
        ]]);
    }

    /** Turn credits on for $grantor (§3.1: permanent), then backfill. */
    public function enable(int $uid, int $surveyId, ?array $grantor, string $mode, bool $confirm): array
    {
        $survey = $this->survey()->getRow($surveyId);
        if ($survey === null) {
            return $this->fail('Survey not found.');
        }
        if (!in_array($mode, self::MODES, true)) {
            return $this->fail('Choose where the credit is given.');
        }
        if (!$confirm) {
            return $this->fail('Confirm that you understand this cannot be undone.');
        }
        if (!$this->canActFor($uid, $survey, $grantor)) {
            return $this->denied('You cannot turn on credits for this survey.');
        }
        $problem = $this->enableProblem($survey, $grantor);
        if ($problem !== '') {
            return $this->fail($problem);
        }

        $sid = (int) $survey['survey_id'];
        if (!$this->exec('INSERT INTO ' . DB_PREFIX . 'survey_credit
                          (survey_id, grantor_type, grantor_id, mode, enabled_by, enabled_at)
                          VALUES (' . $sid . ', \'' . $grantor['type'] . '\', ' . (int) $grantor['id'] . ', \''
                          . $mode . '\', ' . $uid . ', \'' . date('Y-m-d H:i:s') . '\')')) {
            return $this->fail('Credits are already on for this organization.');
        }
        // Read back by the unique key: LAST_INSERT_ID() is not a duplicate signal.
        $row = $this->fetchRow('SELECT credit_id FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $sid
            . ' AND grantor_type = \'' . $grantor['type'] . '\' AND grantor_id = ' . (int) $grantor['id']);
        $creditId = $row ? (int) $row['credit_id'] : 0;

        $res = $this->reconcile($sid);

        $log = $this->survey();
        $log->setActor($uid);
        $log->logActivity($sid, 'credit', [
            'credit_id' => $creditId, 'grantor_type' => $grantor['type'], 'grantor_id' => (int) $grantor['id'],
            'mode' => $mode, 'backfilled' => $res['Granted'],
        ]);

        return $this->ok(['CreditId' => $creditId] + $res);
    }

    /** reconcile() for a panel viewer: a manager, or someone acting for $grantor. */
    public function reconcileAs(int $uid, int $surveyId, ?array $grantor): array
    {
        $survey = $this->survey()->getRow($surveyId);
        if ($survey === null) {
            return $this->fail('Survey not found.');
        }
        if (!$this->survey()->canManage($uid, $survey) && !$this->canActFor($uid, $survey, $grantor)) {
            return $this->denied('You cannot manage attendance credits for this survey.');
        }
        return $this->ok($this->reconcile($surveyId));
    }

    // -----------------------------------------------------------------------
    // Engine
    // -----------------------------------------------------------------------

    /**
     * Post every owed credit (§3.5). Idempotent: the ledger holds one row per
     * player per survey, so re-running changes nothing. Creates missing events first.
     *
     * @return array{Granted:int, SkippedNoPark:int, Pending:int}
     */
    public function reconcile(int $surveyId): array
    {
        $out    = ['Granted' => 0, 'SkippedNoPark' => 0, 'Pending' => 0];
        $survey = $this->survey()->getRow($surveyId);
        if ($survey === null) {
            return $out;
        }
        $configs = $this->withEvents($this->configs($surveyId), $survey);
        if (!$configs) {
            return $out;
        }
        $byId     = array_column($configs, null, 'credit_id');
        $parentOf = $this->parentMap();

        foreach ($this->owedResponses($surveyId) as $r) {
            $cov = self::coverage($configs, $r, $survey, $parentOf);
            if ($cov['credit_id'] === null) {
                $out['SkippedNoPark'] += $cov['no_home_park'] ? 1 : 0;
                continue;
            }
            $res = $this->grant($survey, $byId[$cov['credit_id']], $r);
            if ($res === 'granted') {
                $out['Granted']++;
            } elseif ($res === 'pending') {
                $out['Pending']++;
            }
        }
        return $out;
    }

    /** The live grant after a submit commits (§3.5). Never throws for a missing config. */
    public function grantFor(int $surveyId, int $uid): string
    {
        $survey = $this->survey()->getRow($surveyId);
        $configs = $survey ? $this->configs($surveyId) : [];
        if (!$configs) {
            return 'none';
        }
        if ($this->isGranted($surveyId, $uid)) {
            return 'granted';
        }
        $r = $this->fetchRow('SELECT response_id, mundane_id, park_id, kingdom_id, submitted_at
                                FROM ' . DB_PREFIX . 'survey_response
                               WHERE survey_id = ' . (int) $surveyId . ' AND mundane_id = ' . (int) $uid . '
                                 AND consent = \'full\' AND is_test = 0 LIMIT 1');
        if ($r === null) {
            return 'none';
        }
        $configs = $this->withEvents($configs, $survey);
        $cov = self::coverage($configs, $r, $survey, $this->parentMap());
        if ($cov['credit_id'] === null) {
            return 'none';
        }
        $res = $this->grant($survey, array_column($configs, null, 'credit_id')[$cov['credit_id']], $r);
        return $res === 'pending' ? 'pending' : 'granted';
    }

    /** First (and any) transition to open: give event configs their event now that a start date exists. */
    public function onOpened(int $surveyId): void
    {
        $survey = $this->survey()->getRow($surveyId);
        if ($survey !== null) {
            $this->withEvents($this->configs($surveyId), $survey);
        }
    }

    /** Would this player be credited if they chose Any ORK Data? (the runner's credit line) */
    public function creditAvailableFor(array $surveyRow, int $uid): bool
    {
        $configs = $this->configs((int) $surveyRow['survey_id']);
        if (!$configs || empty($surveyRow['data_gate_enabled'])) {
            return false;
        }
        $p = $this->fetchRow('SELECT park_id, kingdom_id FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int) $uid);
        if ($p === null) {
            return false;
        }
        return self::coverage($configs, $p, $surveyRow, $this->parentMap())['credit_id'] !== null;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function survey(): Survey
    {
        return new Survey();
    }

    private function canActFor(int $uid, array $survey, ?array $grantor): bool
    {
        return $grantor !== null
            && in_array($grantor['type'] ?? '', ['kingdom', 'park'], true)
            && $this->validGrantor($survey, (string) $grantor['type'], (int) $grantor['id'])
            && $this->survey()->canCreate($uid, (string) $grantor['type'], (int) $grantor['id']);
    }

    /** '' when $grantor may switch credits on now, else the reason shown in the panel. */
    private function enableProblem(array $survey, array $grantor): string
    {
        if (empty($survey['data_gate_enabled'])) {
            return 'Turn on the data gate (Privacy) so respondents can choose Any ORK Data; credits are only given to them.';
        }
        $isOwner = (string) $survey['scope_type'] === $grantor['type'] && (int) $survey['scope_id'] === (int) $grantor['id'];
        if (!$isOwner && !in_array((string) $survey['status'], ['open', 'closed'], true)) {
            return 'Credits can be turned on once this survey is open.';
        }
        $own = $this->fetchRow('SELECT 1 AS ok FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . (int) $survey['survey_id']
            . ' AND grantor_type = \'' . $grantor['type'] . '\' AND grantor_id = ' . (int) $grantor['id']);
        return $own !== null ? 'Credits are already on for this organization.' : '';
    }

    /** Is config $c shown to someone acting for $grantor? */
    private function related(array $c, ?array $grantor, array $survey): bool
    {
        if ((string) $c['grantor_type'] === (string) $survey['scope_type'] && (int) $c['grantor_id'] === (int) $survey['scope_id']) {
            return true;   // the owner's config
        }
        if ($grantor === null) {
            return false;
        }
        $type = (string) $c['grantor_type'];
        $id   = (int) $c['grantor_id'];
        if ($type === $grantor['type'] && $id === (int) $grantor['id']) {
            return true;
        }
        [$gk, $gp] = $this->orgKingdom($grantor['type'], (int) $grantor['id']);
        if ($type === 'kingdom' && ($id === $gk || ($gp > 0 && $id === $gp))) {
            return true;   // a kingdom above the viewer
        }
        if ($type === 'park' && $grantor['type'] === 'kingdom') {
            [$pk, $pp] = $this->orgKingdom('park', $id);
            return $pk === (int) $grantor['id'] || $pp === (int) $grantor['id'];   // a park below the viewer
        }
        return false;
    }

    /** An earlier config that already covers ALL of $grantor's players, as {credit_id, name}. */
    private function coveredBy(array $configs, array $grantor, array $survey): ?array
    {
        [$gk, $gp] = $this->orgKingdom($grantor['type'], (int) $grantor['id']);
        foreach ($configs as $c) {   // earliest first
            $type  = (string) $c['grantor_type'];
            $id    = (int) $c['grantor_id'];
            $owner = $type === (string) $survey['scope_type'] && $id === (int) $survey['scope_id'];
            $above = $type === 'kingdom' && ($grantor['type'] === 'park' ? ($id === $gk || ($gp > 0 && $id === $gp)) : ($gp > 0 && $id === $gp));
            if (($owner && $c['mode'] === 'event') || $above) {
                return ['credit_id' => (int) $c['credit_id'], 'name' => $this->survey()->scopeName($type, $id)];
            }
        }
        return null;
    }

    /** Dry run: how many owed responses a new config for $grantor would credit now. */
    private function preview(array $survey, array $configs, array $grantor, string $mode, array $parentOf): array
    {
        $hyp = ['credit_id' => PHP_INT_MAX, 'grantor_type' => $grantor['type'], 'grantor_id' => (int) $grantor['id'],
                'mode' => $mode, 'enabled_at' => '9999-12-31 23:59:59'];
        $n = 0;
        $noPark = 0;
        foreach ($this->owedResponses((int) $survey['survey_id']) as $r) {
            $cov = self::coverage(array_merge($configs, [$hyp]), $r, $survey, $parentOf);
            if ($cov['credit_id'] === PHP_INT_MAX) {
                $n++;
            } elseif ($cov['credit_id'] === null && $mode === 'home_park'
                && self::coverage([$hyp], $r, $survey, $parentOf)['no_home_park']) {
                $noPark++;
            }
        }
        return ['eligible_now' => $n, 'no_home_park' => $noPark];
    }

    private function pendingCount(array $survey, array $configs, array $parentOf): int
    {
        if (!$configs) {
            return 0;
        }
        $n = 0;
        foreach ($this->owedResponses((int) $survey['survey_id']) as $r) {
            $n += self::coverage($configs, $r, $survey, $parentOf)['credit_id'] !== null ? 1 : 0;
        }
        return $n;
    }

    /** Non-test Any ORK Data responses whose player has no credit for this survey yet. */
    private function owedResponses(int $surveyId): array
    {
        return $this->fetchAll(
            'SELECT r.response_id, r.mundane_id, r.park_id, r.kingdom_id, r.submitted_at
               FROM ' . DB_PREFIX . 'survey_response r
               LEFT JOIN ' . DB_PREFIX . 'survey_credit_grant g ON g.survey_id = r.survey_id AND g.mundane_id = r.mundane_id
              WHERE r.survey_id = ' . (int) $surveyId . ' AND r.is_test = 0 AND r.consent = \'full\'
                AND r.mundane_id IS NOT NULL AND g.mundane_id IS NULL
              ORDER BY r.response_id'
        );
    }

    private function isGranted(int $surveyId, int $uid): bool
    {
        return $this->fetchRow('SELECT 1 AS ok FROM ' . DB_PREFIX . 'survey_credit_grant
                                WHERE survey_id = ' . (int) $surveyId . ' AND mundane_id = ' . (int) $uid) !== null;
    }

    /** $configs with every event config given its event where the survey has a start date. */
    private function withEvents(array $configs, array $survey): array
    {
        foreach ($configs as $i => $c) {
            if ($c['mode'] === 'event') {
                $configs[$i] = $this->ensureEvent($c, $survey);
            }
        }
        return $configs;
    }

    private function ensureEvent(array $config, array $survey): array
    {
        $detailId = (int) ($config['event_calendardetail_id'] ?? 0);
        if ($detailId > 0 && $this->fetchRow('SELECT 1 AS ok FROM ' . DB_PREFIX . 'event_calendardetail
                                              WHERE event_calendardetail_id = ' . $detailId) !== null) {
            return $config;
        }
        $start = self::startDate($survey);
        if ($start === null) {
            return $config;
        }
        $isPark = $config['grantor_type'] === 'park';
        $title  = (string) $survey['title'];
        $r = Ork3::$Lib->eventplanning->CreateSystemEvent([
            'KingdomId'   => $isPark ? 0 : (int) $config['grantor_id'],
            'ParkId'      => $isPark ? (int) $config['grantor_id'] : 0,
            'Name'        => self::eventName($title),
            'Date'        => $start,
            'Description' => 'Attendance credit for completing the survey "' . $title . '". Credits are entered automatically '
                . 'for respondents who chose to link their answers to their ORK profile.',
            'Url'         => (defined('UIR') ? UIR : HTTP_UI_REMOTE . 'index.php?Route=') . 'Survey/s/' . (string) $survey['slug'],
            'UrlName'     => 'Take the survey',
        ]);
        if ((int) ($r['Status'] ?? 1) !== 0) {
            $this->logFailure((int) $survey['survey_id'], 0, 'create_event', (string) ($r['Error'] ?? ''));
            return $config;
        }
        $this->exec('UPDATE ' . DB_PREFIX . 'survey_credit SET event_id = ' . (int) $r['EventId']
            . ', event_calendardetail_id = ' . (int) $r['DetailId'] . ' WHERE credit_id = ' . (int) $config['credit_id']);
        $config['event_id']                = (int) $r['EventId'];
        $config['event_calendardetail_id'] = (int) $r['DetailId'];
        return $config;
    }

    /** One credit: attendance row + ledger row in one transaction. @return 'granted'|'already'|'pending' */
    private function grant(array $survey, array $config, array $response): string
    {
        $sid = (int) $survey['survey_id'];
        $uid = (int) $response['mundane_id'];

        if ($config['mode'] === 'event') {
            $occ = (int) ($config['event_calendardetail_id'] ?? 0) > 0 ? $this->fetchRow(
                'SELECT e.event_id, e.kingdom_id, COALESCE(cd.at_park_id, 0) AS at_park_id, DATE(cd.event_start) AS d
                   FROM ' . DB_PREFIX . 'event_calendardetail cd
                   JOIN ' . DB_PREFIX . 'event e ON e.event_id = cd.event_id
                  WHERE cd.event_calendardetail_id = ' . (int) $config['event_calendardetail_id']
            ) : null;
            if ($occ === null) {
                return 'pending';
            }
            $where = ['Date' => (string) $occ['d'], 'ParkId' => (int) $occ['at_park_id'], 'KingdomId' => (int) $occ['kingdom_id'],
                      'EventId' => (int) $occ['event_id'], 'EventCalendarDetailId' => (int) $config['event_calendardetail_id']];
        } else {
            $park = $this->fetchRow('SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = ' . (int) $response['park_id']);
            if ($park === null) {
                return 'pending';
            }
            $where = ['Date' => substr((string) $response['submitted_at'], 0, 10), 'ParkId' => (int) $response['park_id'],
                      'KingdomId' => (int) $park['kingdom_id'], 'EventId' => 0, 'EventCalendarDetailId' => 0];
        }

        $class = self::classFor((int) Ork3::$Lib->attendance->GetPlayerLastClass(['MundaneId' => $uid]));

        $this->exec('START TRANSACTION');
        $att = Ork3::$Lib->attendance->AddSystemCredit($where + [
            'MundaneId' => $uid, 'ClassId' => $class, 'Credits' => 1, 'Note' => self::noteFor($sid),
            'ByWhomId' => (int) $config['enabled_by'], 'EntryMethod' => 'survey',
        ]);
        if ((int) $att['Status'] !== 0) {
            $this->exec('ROLLBACK');
            $this->logFailure($sid, $uid, 'attendance', (string) $att['Error']);
            return 'pending';
        }
        if (!$this->exec('INSERT INTO ' . DB_PREFIX . 'survey_credit_grant (survey_id, mundane_id, credit_id, attendance_id)
                          VALUES (' . $sid . ', ' . $uid . ', ' . (int) $config['credit_id'] . ', ' . (int) $att['AttendanceId'] . ')')) {
            $this->exec('ROLLBACK');
            return $this->isGranted($sid, $uid) ? 'already' : 'pending';   // a concurrent grant won
        }
        $this->exec('COMMIT');
        return 'granted';
    }

    private function configOut(array $c, int $granted): array
    {
        $eventLabel = '';
        if ((int) ($c['event_id'] ?? 0) > 0) {
            $e = $this->fetchRow('SELECT name FROM ' . DB_PREFIX . 'event WHERE event_id = ' . (int) $c['event_id']);
            $eventLabel = $e ? (string) $e['name'] : '';
        }
        return [
            'credit_id'               => (int) $c['credit_id'],
            'grantor_type'            => (string) $c['grantor_type'],
            'grantor_id'              => (int) $c['grantor_id'],
            'grantor_name'            => $this->survey()->scopeName((string) $c['grantor_type'], (int) $c['grantor_id']),
            'mode'                    => (string) $c['mode'],
            'event_id'                => (int) ($c['event_id'] ?? 0) ?: null,
            'event_calendardetail_id' => (int) ($c['event_calendardetail_id'] ?? 0) ?: null,
            'event_label'             => $eventLabel,
            'enabled_at'              => (string) $c['enabled_at'],
            'granted'                 => $granted,
        ];
    }

    /** One structured line; quoted literals in DB messages are redacted like SurveyResponse::rollback(). */
    private function logFailure(int $surveyId, int $uid, string $stage, string $error): void
    {
        error_log('[survey-credit] grant failed ' . json_encode([
            'survey_id' => $surveyId, 'uid' => $uid, 'stage' => $stage,
            'db_error'  => preg_replace("/'[^']*'/", "'?'", $error),
        ]));
    }
```

- [ ] **Step 4: Run tests**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter 'SurveySharingCreditTest|SurveyCreditTest'`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
php -l system/lib/ork3/class.SurveyCredit.php
git add system/lib/ork3/class.SurveyCredit.php tests/Integration/SurveySharingCreditTest.php
git commit -m "Enhancement: Survey — attendance credit engine (enable, backfill, live grant, events)"
```

---

### Task 10: Hooks (gate lock, open, submit, runner flags, audience rule)

**Model:** sonnet · effort medium

**Files:**
- Modify: `system/lib/ork3/class.Survey.php`:
  - `update()`: the gate lock, before the `foreach (self::UPDATE_FIELDS …)` loop
  - `setStatus()`: after `logActivity(... 'status' ...)`
- Modify: `system/lib/ork3/class.SurveyResponse.php`:
  - `submit()` return `:1320-1327`
  - `definitionForRespondent()` eligible branch `:703-722`
  - `widgetRow()` `:1553-1564`
  - `attendedRecently()` `:385-396`
  - `audienceCount()` recent-months clause `:487-494`
- Test: `tests/Integration/SurveySharingCreditTest.php`

**Interfaces:**
- Consumes: `SurveyCredit::hasConfigs()`, `onOpened()`, `grantFor()`, `creditAvailableFor()` (Task 9).
- Produces:
  - `submit()` returns `Credit` (`granted|pending|none`)
  - `definitionForRespondent()['Survey']['credit_available']` (bool)
  - `availableFor()` rows gain `credit_available` (bool)

- [ ] **Step 1: Write the failing tests** (append)

```php
    public function testSubmitGrantsAfterCommitAndReportsIt(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $this->assertSame('granted', $this->answer($ks, $this->player('s1', $this->parkA, $this->k), 'full')['Credit']);
        $this->assertSame('none', $this->answer($ks, $this->player('s2', $this->parkA, $this->k), 'partial')['Credit']);
        $this->assertSame('none', $this->answer($ks, $this->player('s3', $this->parkA, $this->k), 'anonymous')['Credit']);
        $this->assertCount(1, $this->grants($ks));
    }

    public function testGateCannotBeTurnedOffOnceCreditsExist(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $this->assertSame(1, (new Survey())->update($ks, ['DataGateEnabled' => 0])['Status']);
        $this->assertSame('1', (string) $this->row($ks)['data_gate_enabled']);
    }

    public function testOpeningCreatesTheEventThroughSetStatus(): void
    {
        $s = new Survey();
        $sid = $this->fx['survey'][] = (int) $s->create($this->kOfficer, 'kingdom', $this->k, 'T11SHARE hook')['SurveyId'];
        $page = (int) $this->scalar('SELECT page_id FROM ' . DB_PREFIX . 'survey_page WHERE survey_id = ' . $sid . ' LIMIT 1');
        $q = $s->questionAdd($sid, $page, 'single', null);
        $s->questionUpdate((int) $q['Question']['question_id'], ['Prompt' => 'T11SHARE q']);
        $this->credit()->enable($this->kOfficer, $sid, ['type' => 'kingdom', 'id' => $this->k], 'event', true);
        $s->setStatus($sid, 'open');
        $this->assertGreaterThan(0, (int) $this->scalar('SELECT event_id FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $sid));
    }

    public function testRunnerSeesCreditAvailableOnlyWhenCovered(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $uid = $this->player('runner', $this->parkA, $this->k);
        $def = (new SurveyResponse())->definitionForRespondent($ks, $uid, false);
        $this->assertFalse($def['Survey']['credit_available']);
        $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $def = (new SurveyResponse())->definitionForRespondent($ks, $uid, false);
        $this->assertTrue($def['Survey']['credit_available']);
        $rows = array_values(array_filter((new SurveyResponse())->availableFor($uid), static fn($r) => $r['survey_id'] === $ks));
        $this->assertTrue($rows[0]['credit_available']);
    }

    public function testSurveyCreditsDoNotCountAsRecentAttendance(): void
    {
        $ks = $this->openSurvey($this->kOfficer, 'kingdom', $this->k);
        $this->credit()->enable($this->kOfficer, $ks, ['type' => 'kingdom', 'id' => $this->k], 'home_park', true);
        $uid = $this->player('recent', $this->parkA, $this->k);
        $this->answer($ks, $uid, 'full');   // earns a survey credit dated today

        $recent = $this->openSurvey($this->kOfficer, 'kingdom', $this->k, ['audience_recent_months' => 6]);
        $e = (new SurveyResponse())->eligibility($this->row($recent), $uid);
        $this->assertFalse($e['eligible']);
        $this->assertSame('recent_attendance', $e['reason']);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter SurveySharingCreditTest`
Expected: FAIL on the missing `Credit` / `credit_available` keys, the gate update returning 0, the event not created, and the recent-attendance rule.

- [ ] **Step 3: Implement**

`Survey::update()`, before the `foreach`:

```php
        // A credit config promises a credit to respondents who choose Any ORK
        // Data; without the gate nobody can (sharing spec §3.5).
        if (array_key_exists('DataGateEnabled', $fields) && !$this->truthy($fields['DataGateEnabled'])
            && (new SurveyCredit())->hasConfigs($surveyId)) {
            return $this->fail('This survey gives attendance credits, which need respondents to be able to choose Any ORK Data.');
        }
```

`Survey::setStatus()`, inside the existing `else` branch that runs `purgeStaleDrafts` (the open branch):

```php
        } else {
            $this->purgeStaleDrafts($surveyId);
            // Event-mode credit configs get their event once a start date exists (§3.4).
            (new SurveyCredit())->onOpened($surveyId);
        }
```

`SurveyResponse::submit()`, between the `COMMIT` and the `return`:

```php
        // Attendance credit (sharing spec §3.5): after the commit, so a credit
        // problem can never cost the player their response. Only Any ORK Data
        // earns one (D1).
        $credit = 'none';
        if (!$isTest && 'full' === $storedConsent) {
            try {
                $credit = (new SurveyCredit())->grantFor($surveyId, $uid);
            } catch (\Throwable $e) {
                error_log('[survey-credit] grant threw ' . json_encode(['survey_id' => $surveyId, 'uid' => $uid, 'error' => get_class($e)]));
                $credit = 'pending';
            }
        }
```
and add `'Credit' => $credit,` to the returned array.

`definitionForRespondent()`, after `$public = $this->publicSurveyFields($survey, $imageUrls);`:

```php
        // The data gate's credit line (§3.7). In preview the builder sees it whenever any config exists.
        $credit = new SurveyCredit();
        $public['credit_available'] = $preview
            ? $credit->hasConfigs($surveyId)
            : $credit->creditAvailableFor($survey, $uid);
```

`widgetRow()`, add:

```php
            'credit_available' => (new SurveyCredit())->creditAvailableFor($survey, $uid),
```

`attendedRecently()` and the `audienceCount()` recent clause: add `AND a.entry_method <> 'survey'` (alias `ar` in `audienceCount`) right after the date condition, with the comment `// a survey credit is not attendance (sharing spec §3.5)`.

- [ ] **Step 4: Run tests**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter 'Survey'`
Expected: PASS for every Survey* unit and integration test.

- [ ] **Step 5: Lint and commit**

```bash
php -l system/lib/ork3/class.Survey.php system/lib/ork3/class.SurveyResponse.php
git add system/lib/ork3/class.Survey.php system/lib/ork3/class.SurveyResponse.php tests/Integration/SurveySharingCreditTest.php
git commit -m "Enhancement: Survey — credits hook into submit, open, the data gate and the audience rule"
```

---

## Phase 2 — Membrane

### Task 11: Model, controllers, CLI sweep

**Model:** sonnet · effort medium

**Files:**
- Modify: `orkui/model/model.Survey.php` (delegates + `_credit()` factory)
- Modify: `orkui/controller/controller.Survey.php` (`index()` `:36-73`, `results()` `:163-195`)
- Modify: `orkui/controller/controller.SurveyAjax.php`:
  - `CSRF_EXEMPT` `:27-30`
  - `submit()` `:612-627`
  - `results()` `:648-658`
  - new `orgParam()` and the three credit actions
- Create: `bin/survey-credit-sweep.php`

**Interfaces:**
- Consumes: everything in Tasks 3, 5, 6 and 9.
- Produces:
  - Template vars:
    - `Survey_index.tpl`: `$Buckets` (listForScope shape), `$Surveys` (flattened rows, for the stats row)
    - `Survey_results.tpl`: `$ResultsAccess` (resultsAccess shape), `$ResultsContext` (`'Kingdom/17'|'Park/9'|''`), `$OwnerName`
  - JSON:
    - `results` gains `access` and `summary.lens` (`{label}` for a shared viewer, from `SurveyReport::sharedResults()`; `null` for a manager)
    - `submit` gains `credit`
    - `credit_status` returns `{status:0, credit:{…§5}}`
    - `credit_enable` / `credit_reconcile` return `{status:0, credit_id?, granted, skipped_no_park, pending}`

- [ ] **Step 1: Model delegates** (append before the factories)

```php
    // -----------------------------------------------------------------------
    // Org sections, sharing, credits (sharing-and-credits spec)
    // -----------------------------------------------------------------------

    public function list_for_scope(int $uid, ?string $scopeType, ?int $scopeId): array
    {
        return $this->_survey()->listForScope($uid, $scopeType, $scopeId);
    }

    public function results_access(int $uid, array $surveyRow, ?array $context): ?array
    {
        return $this->_survey()->resultsAccess($uid, $surveyRow, $context);
    }

    public function shared_results(int $surveyId, $filters, array $lens): array
    {
        return $this->_report()->sharedResults($surveyId, $filters, $lens);
    }

    public function credit_status(int $uid, int $surveyId, ?array $grantor): array
    {
        return $this->_credit()->status($uid, $surveyId, $grantor);
    }

    public function credit_enable(int $uid, int $surveyId, ?array $grantor, string $mode, bool $confirm): array
    {
        return $this->_credit()->enable($uid, $surveyId, $grantor, $mode, $confirm);
    }

    public function credit_reconcile(int $uid, int $surveyId, ?array $grantor): array
    {
        return $this->_credit()->reconcileAs($uid, $surveyId, $grantor);
    }
```
and the factory:
```php
    private function _credit(): SurveyCredit
    {
        return new SurveyCredit();
    }
```

- [ ] **Step 2: `Controller_Survey::index`**

After the existing `$scopes = …` / `no_authorization` block, add the org gate, and replace the `Surveys` line:

```php
        // An org page needs CREATE on that org (sharing spec §1); the picker's
        // scope list stays the create-modal's source.
        if ($scopeType !== null && !$this->Survey->is_ork_admin($uid) && !$this->Survey->can_create($uid, $scopeType, (int) $scopeId)) {
            $this->no_authorization('', 'You do not have permission to see surveys for this ' . $scopeType . '.');
            return;
        }
        $buckets = $this->Survey->list_for_scope($uid, $scopeType, $scopeId);
        $this->data['Buckets'] = $buckets;
        $this->data['Surveys'] = array_merge($buckets['Rows']['ork'], $buckets['Rows']['kingdom'], $buckets['Rows']['park']);
```

- [ ] **Step 3: `Controller_Survey::results`**

Replace the id parse and the `can_manage` gate:

```php
        // Survey/results/{id}[/Kingdom|Park/{orgId}] — segments past the third
        // collapse into this one string, so split before reading the id.
        $parts    = explode('/', trim((string) $id, '/'));
        $surveyId = (int) preg_replace('/[^0-9]/', '', $parts[0] ?? '');
        $context  = null;
        if (isset($parts[1]) && in_array($parts[1], ['Kingdom', 'Park'], true)) {
            $context = ['type' => strtolower($parts[1]), 'id' => (int) preg_replace('/[^0-9]/', '', $parts[2] ?? '')];
        }

        $row = $this->Survey->get_row($surveyId);
        if ($row === null) {
            $this->data['Error'] = 'Survey not found.';
            return;
        }
        $access = $this->Survey->results_access($uid, $row, $context);
        if ($access === null) {
            $this->no_authorization('', 'You do not have permission to view results for this survey.');
            return;
        }
```
and, where `$kingdoms` is built, skip it under a kingdom lens, then pass the new vars:

```php
        $kingdoms = [];
        if (empty($access['lens']['kingdom_ids']) && empty($access['lens']['park_id'])) {
            foreach ($this->Survey->kingdoms_present($surveyId) as $k) {
                // …existing body unchanged…
            }
        }
        $this->data['ResultsAccess']  = $access;
        $this->data['ResultsContext'] = $context !== null ? ucfirst($context['type']) . '/' . $context['id'] : '';
        $this->data['OwnerName']      = $this->Survey->scope_name((string) $row['scope_type'], (int) $row['scope_id']) ?: 'All of Amtgard';
```

- [ ] **Step 4: `Controller_SurveyAjax`**

`CSRF_EXEMPT`: add `'credit_status'`.

Helper:

```php
    /** 'Kingdom/17' | 'Park/1049' -> ['type'=>'kingdom'|'park','id'=>int], anything else -> null. */
    private function orgParam($raw): ?array
    {
        if (!preg_match('~^(Kingdom|Park)/(\d+)$~', trim((string) $raw), $m)) {
            return null;
        }
        return ['type' => strtolower($m[1]), 'id' => (int) $m[2]];
    }
```

`results()`:

```php
    public function results($p = null)
    {
        $uid      = $this->requireLogin();
        $surveyId = (int) ($_POST['SurveyId'] ?? 0);
        $row      = $this->Survey->get_row($surveyId);
        if ($row === null) {
            $this->jsonOut(['status' => 1, 'error' => 'Survey not found.']);
        }
        // Managers read everything; one org level down reads charts and stats
        // through the lens the domain decides (sharing spec §2).
        $access = $this->Survey->results_access($uid, $row, $this->orgParam($_POST['Context'] ?? ''));
        if ($access === null) {
            $this->jsonOut(['status' => 3, 'error' => 'You do not have permission to view results for this survey.']);
        }
        $filters = $this->jsonField('Filters', []);
        $out = $access['level'] === 'manage'
            ? $this->Survey->results($surveyId, $filters)
            : $this->Survey->shared_results($surveyId, $filters, $access['lens']);

        // summary.lens is {label} for a shared viewer (spec §5), null for a manager.
        $this->jsonOut([
            'status'    => 0,
            'summary'   => $out['summary'] + ['lens' => null],
            'questions' => $out['questions'],
            'access'    => $access['level'],
        ]);
    }
```

`submit()`: change the final line to `$this->jsonOut(['status' => 0, 'thanks_html' => $r['ThanksHtml'], 'credit' => $r['Credit'] ?? 'none']);`.

New actions:

```php
    // =========================================================================
    // Attendance credits (sharing-and-credits spec §3, §5)
    // =========================================================================

    public function credit_status($p = null)
    {
        $uid = $this->requireLogin();
        $r   = $this->Survey->credit_status($uid, (int) ($_POST['SurveyId'] ?? 0), $this->orgParam($_POST['Grantor'] ?? ''));
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut(['status' => 0, 'credit' => $r['Credit']]);
    }

    public function credit_enable($p = null)
    {
        $uid = $this->requireLogin();
        $r   = $this->Survey->credit_enable(
            $uid,
            (int) ($_POST['SurveyId'] ?? 0),
            $this->orgParam($_POST['Grantor'] ?? ''),
            (string) ($_POST['Mode'] ?? ''),
            $this->truthy($_POST['Confirm'] ?? 0)
        );
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut(['status' => 0, 'credit_id' => $r['CreditId'], 'granted' => $r['Granted'],
                        'skipped_no_park' => $r['SkippedNoPark'], 'pending' => $r['Pending']]);
    }

    public function credit_reconcile($p = null)
    {
        $uid = $this->requireLogin();
        $r   = $this->Survey->credit_reconcile($uid, (int) ($_POST['SurveyId'] ?? 0), $this->orgParam($_POST['Grantor'] ?? ''));
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut(['status' => 0, 'granted' => $r['Granted'], 'skipped_no_park' => $r['SkippedNoPark'], 'pending' => $r['Pending']]);
    }
```

- [ ] **Step 5: CLI sweep** (`bin/survey-credit-sweep.php`)

The sweep requires the site's public host (`HTTP_HOST` in the environment or `--host=`) and exits 2 without one: the config builds every URL from `$_SERVER['HTTP_HOST']`, which a CLI run lacks, and a credit event the sweep creates links to its survey through it.

```php
#!/usr/bin/env php
<?php

/**
 * Posts owed survey attendance credits
 * (docs/superpowers/specs/2026-09-10-survey-sharing-and-credits-design.md §3.5).
 *
 * Credits are normally posted when a player submits, when an officer turns a
 * credit on, and when the Credits panel opens; this sweep repairs any grant
 * that failed in between. Reconcile is idempotent, so running it often is
 * harmless. Optional cron:
 *
 *     # /etc/cron.d/ork-survey-credit-sweep
 *     15 * * * * www-data HTTP_HOST=ork.amtgard.com /usr/bin/php /var/www/ORK3/bin/survey-credit-sweep.php >> /var/log/ork-survey-credit-sweep.log 2>&1
 *
 * The site's public host is required (HTTP_HOST in the environment, or
 * --host=ork.amtgard.com). The config builds every URL from
 * $_SERVER['HTTP_HOST'], which a CLI run does not have, and a credit event the
 * sweep creates links to its survey through it; without a host that link would
 * be dropped. Add HTTPS=on too when the site is served over TLS and its config
 * is scheme-aware. Exits 2 when the host is missing or malformed.
 */

$host = '';
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (strncmp($arg, '--host=', 7) === 0) {
        $host = substr($arg, 7);
    }
}
if ($host === '') {
    $host = (string) (getenv('HTTP_HOST') ?: '');
}
$host = strtolower(trim($host));
if (!preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::\d{1,5})?$/', $host)) {
    fwrite(STDERR, "survey-credit-sweep: set the site's public host with HTTP_HOST=ork.amtgard.com or --host=ork.amtgard.com"
        . " (credit events link to their survey through it).\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = $host;

require_once dirname(__DIR__) . '/startup.php';

$credit = Ork3::$Lib->surveycredit;
foreach ($credit->surveysWithConfigs() as $surveyId) {
    $r = $credit->reconcile($surveyId);
    if ($r['Granted'] > 0 || $r['Pending'] > 0) {
        fprintf(
            STDOUT,
            "[%s] survey=%d granted=%d pending=%d skipped_no_park=%d\n",
            date('Y-m-d H:i:s'),
            $surveyId,
            $r['Granted'],
            $r['Pending'],
            $r['SkippedNoPark']
        );
    }
}
exit(0);
```

- [ ] **Step 6: Lint + layering grep**

```bash
php -l orkui/model/model.Survey.php orkui/controller/controller.Survey.php orkui/controller/controller.SurveyAjax.php bin/survey-credit-sweep.php
grep -rnE '\$DB->|Ork3::\$Lib|new (Survey|SurveyResponse|SurveyReport|SurveyCredit)\(' orkui --include='*.php' --include='*.tpl' | grep -v 'orkui/model/model.Survey.php' | grep -i survey
docker exec ork3-php8-app php /var/www/ork.amtgard.com/bin/survey-credit-sweep.php; echo "exit=$?"                                  # no host
docker exec -e HTTP_HOST=localhost:19080 ork3-php8-app php /var/www/ork.amtgard.com/bin/survey-credit-sweep.php; echo "exit=$?"
```
Expected: lint clean; the grep prints nothing new (the known `default.theme` `ListSessionsForToken` hit is pre-existing and not survey-related); the sweep exits 2 with no host (the `HTTP_HOST` message on stderr) and 0 with `-e HTTP_HOST=localhost:19080` (or `--host=localhost`).

- [ ] **Step 7: Curl contract check** (dev mirror; run before any browser work)

```bash
J=$(mktemp); B=http://localhost:19080/orkui/index.php
curl -s -c $J -b $J -X POST "$B?Route=Login/login" -d 'username=heraldsbridge&password=x' -o /dev/null
TOKEN=$(curl -s -c $J -b $J "$B?Route=Survey/build/999051" | grep -o 'csrf *: *"[0-9a-f]\{64\}"' | grep -o '[0-9a-f]\{64\}')
curl -s -b $J -X POST "$B?Route=SurveyAjax/credit_status" -F SurveyId=999051 -F Grantor=Kingdom/17 | head -c 600; echo
curl -s -b $J -X POST "$B?Route=SurveyAjax/credit_enable" -F SurveyId=999051 -F Grantor=Kingdom/17 -F Mode=home_park -F Confirm=1; echo   # no token
curl -s -b $J -X POST "$B?Route=SurveyAjax/results" -F SurveyId=999051 -F Context=Kingdom/17 -F 'Filters={}' | head -c 200; echo
curl -s -b $J "$B?Route=Survey/index/Kingdom/17" -o /dev/null -w '%{http_code}\n'
```
Expected:
- `credit_status` returns `{"status":0,"credit":{…"mine":{…"can_enable":true…`.
- `credit_enable` with no token returns `{"status":3,"csrf":true,…}`.
- `results` returns `"access":"manage"` and `summary.lens` `null` (heraldsbridge is an ORK admin).
- The index returns `200`.

Do **not** enable a credit on fixture 999051 here: configs are permanent. Enable-path curl belongs to a scratch survey. Create one in the builder, e.g. by cloning 999051 (`SurveyAjax/clone` with the token), then call `credit_enable` on the clone with `-H "X-CSRF-Token: $TOKEN"`, expecting `{"status":0,"credit_id":…,"granted":0,…}`. Check it with `docker exec ork3-php8-db mariadb -uroot -proot ork -e "SELECT * FROM ork_survey_credit ORDER BY credit_id DESC LIMIT 1"`.

- [ ] **Step 8: Commit**

```bash
git add orkui/model/model.Survey.php orkui/controller/controller.Survey.php orkui/controller/controller.SurveyAjax.php bin/survey-credit-sweep.php
git commit -m "Enhancement: Survey — sharing and credit endpoints, org-page gate, credit sweep CLI"
```

---

## Phase 3 — Surfaces

Tasks 12–16 touch disjoint files and may run in parallel after Task 11. Task 12 must land before 13 and 14 are verified, since both open its modal. Every surface: light and dark mode, 360 px with no horizontal scroll, no native dialogs.

### Task 12: Shared modal CSS + Attendance credit modal

**Model:** opus · effort medium

**Files:**
- Modify: `orkui/template/default/style/survey.css`. Move the `.sv-overlay`, `.sv-open`, `.sv-modal*` rules out of `Survey_index.tpl`'s inline `<style>` into `survey.css` verbatim (with their dark-mode variants), and delete them from the template.
- Create: `orkui/template/default/_survey_credit_modal.tpl`, `orkui/template/default/script/survey-credit.js`

**Interfaces:**
- Consumes:
  - `SurveyAjax/credit_status`, `credit_enable`, `credit_reconcile` (Task 11)
  - `window.SvCreditConfig = {uir, csrf}`, emitted by the including page before the include
- Produces:
  - `window.SvCredit.open({surveyId:int, grantor:'Kingdom/17'|'Park/9'|'', title:string, onChange?:function})`
  - The include: `<?php include __DIR__ . '/_survey_credit_modal.tpl'; ?>` (the precedent is `Kingdomnew_index.tpl:3233`)

- [ ] **Step 1: Move the modal CSS**

```bash
grep -n "sv-overlay\|\.sv-modal\|\.sv-open" orkui/template/default/Survey_index.tpl
```
Cut every matched rule block (and its `html[data-theme="dark"]` twin) into a new `/* ---- Modal (shared: list page, builder credit panel) ---- */` section at the end of `survey.css`. The list page already links `survey.css` (`Survey_index.tpl:40`), and so does the builder (`Survey_build.tpl:66`).

- [ ] **Step 2: Write `_survey_credit_modal.tpl`**

```php
<?php
/**
 * Attendance credit modal (sharing-and-credits spec §3.6), shared by the
 * survey list and the builder. Include once per page after survey.css, and
 * emit window.SvCreditConfig = {uir, csrf} before it. Open it with
 * SvCredit.open({surveyId, grantor, title, onChange}).
 */
?>
<div class="sv-overlay" id="sv-credit-overlay">
	<div class="sv-modal sv-scope sv-credit-modal" role="dialog" aria-modal="true" aria-labelledby="sv-credit-heading">
		<h4 class="sv-modal-title" id="sv-credit-heading">Attendance credit</h4>
		<div class="sv-modal-body" id="sv-credit-body" aria-live="polite">Loading&hellip;</div>
		<div class="sv-modal-footer">
			<button type="button" class="sv-modal-btn sv-modal-cancel" id="sv-credit-close">Close</button>
			<button type="button" class="sv-modal-btn sv-modal-primary" id="sv-credit-enable" hidden disabled>Turn on credits</button>
		</div>
	</div>
</div>
<script src="<?= HTTP_TEMPLATE ?>default/script/survey-credit.js?v=<?= filemtime(__DIR__ . '/script/survey-credit.js') ?>"></script>
```

(If `.sv-modal-primary` does not exist after Step 1, use the primary-button class the list page's New Survey modal uses: `grep -n "sv-modal-btn" orkui/template/default/Survey_index.tpl`.)

- [ ] **Step 3: Write `survey-credit.js`**

```js
/*
  survey-credit.js — the Attendance credit modal (sharing-and-credits spec §3.6).
  Shared by the survey list and the builder. Configured by
  window.SvCreditConfig = {uir, csrf}; opened with
  SvCredit.open({surveyId, grantor, title, onChange}). No native dialogs;
  focus is trapped while open and restored on close. Configs are permanent,
  so the only mutation is "Turn on credits", gated by an explicit checkbox.
*/
(function () {
    'use strict';

    var CFG = window.SvCreditConfig || {};
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
        'August', 'September', 'October', 'November', 'December'];
    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';
    var MODE_LABEL = {
        home_park: 'At the player’s home park, on the day they took the survey',
        event: null   // built from the status payload: event name + start date
    };

    var ov, body, enableBtn, closeBtn;
    var current = null;      // {surveyId, grantor, title, onChange}
    var data = null;         // last credit_status payload
    var lastFocus = null;
    var reconciled = false;  // one automatic reconcile per open

    function $(id) { return document.getElementById(id); }

    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function longDate(ymd) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(ymd || ''));
        return m ? MONTHS[parseInt(m[2], 10) - 1] + ' ' + parseInt(m[3], 10) + ', ' + m[1] : '';
    }

    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

    function post(action, fields) {
        var fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        return fetch(CFG.uir + 'SurveyAjax/' + action, {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-CSRF-Token': CFG.csrf || '' }
        })
            .then(function (r) { return r.json(); })
            .catch(function () { return { status: 1, error: 'Could not reach the server. Try again.' }; });
    }

    function errorHtml(j) {
        if (j && j.csrf) {
            return '<div class="sv-notice sv-notice-error" role="alert">Your security token expired. ' +
                '<a href="" class="sv-notice-link" data-sv-credit-reload>Reload the page</a> and try again.</div>';
        }
        return '<div class="sv-notice sv-notice-error" role="alert">' + esc((j && j.error) || 'Something went wrong.') + '</div>';
    }

    function configLine(c) {
        var where;
        if (c.mode === 'event') {
            where = c.event_id
                ? 'at the event <a href="' + esc(CFG.uir + 'Event/detail/' + c.event_id + '/' + c.event_calendardetail_id) + '">' + esc(c.event_label) + '</a>'
                : 'at an event created when the survey opens';
        } else {
            where = 'at players’ home parks';
        }
        return '<li><strong>' + esc(c.grantor_name) + '</strong>: ' + where + ', since ' + esc(longDate(c.enabled_at)) +
            ' (' + plural(c.granted | 0, 'credit', 'credits') + ')</li>';
    }

    function warningText(mode) {
        var p = (data.mine.preview || {})[mode] || { eligible_now: 0, no_home_park: 0 };
        var t = plural(p.eligible_now | 0, 'player who already chose Any ORK Data will', 'players who already chose Any ORK Data will') +
            ' get a credit now. ';
        if (data.survey_status === 'open' || data.survey_status === 'draft') {
            t += 'While this survey is open, everyone who completes it with Any ORK Data and is covered by this credit will get one automatically. ';
        }
        t += 'This can’t be turned off or changed, and credits already given stay on players’ records.';
        if (mode === 'home_park' && (p.no_home_park | 0) > 0) {
            t += ' ' + plural(p.no_home_park | 0, 'player', 'players') + ' can’t be credited at a home park because they have none.';
        }
        return t;
    }

    function render(extraHtml) {
        var html = extraHtml || '';
        var mine = data.mine;
        var evDate = data.start_date ? longDate(data.start_date) : 'the day this survey opens';

        html += '<p class="sv-credit-lead"><strong>' + esc(current.title || data.survey_title) + '</strong></p>';
        if (data.configs.length) {
            html += '<p class="sv-credit-sub">This survey’s credits</p><ul class="sv-credit-list">' +
                data.configs.map(configLine).join('') + '</ul>';
        }

        enableBtn.hidden = true;
        enableBtn.disabled = true;

        if (!mine) {
            if (!data.configs.length) {
                html += '<p>No attendance credits yet. Kingdoms and parks turn them on for their own players from their survey list.</p>';
            }
        } else if (mine.config_id) {
            html += '<p>Credits are on for <strong>' + esc(mine.name) + '</strong>.</p>';
        } else if (!data.gate_enabled || !mine.can_enable) {
            html += '<div class="sv-notice sv-notice-warn">' + esc(mine.blocked_reason) + '</div>';
        } else {
            if (mine.covered_by) {
                html += '<p class="sv-credit-sub">Your players are already covered by ' + esc(mine.covered_by.name) +
                    '’s credit. Turning one on here only reaches players it does not cover.</p>';
            }
            html += '<fieldset class="sv-credit-modes"><legend>Give <strong>' + esc(mine.name) + '</strong> players the credit</legend>' +
                '<label class="sv-credit-mode"><input type="radio" name="sv-credit-mode" value="home_park"> <span>' + esc(MODE_LABEL.home_park) + '</span></label>' +
                '<label class="sv-credit-mode"><input type="radio" name="sv-credit-mode" value="event"> <span>At a new event “' +
                esc(data.event_name) + '” on ' + esc(evDate) + '</span></label></fieldset>' +
                '<p class="sv-credit-warning" id="sv-credit-warning" hidden></p>' +
                '<label class="sv-check sv-credit-confirm"><input type="checkbox" class="sv-check-input" id="sv-credit-ack"> ' +
                '<span>I understand this can’t be undone</span></label>';
            enableBtn.hidden = false;
        }
        body.innerHTML = html;
    }

    function selectedMode() {
        var r = body.querySelector('input[name="sv-credit-mode"]:checked');
        return r ? r.value : '';
    }

    function syncEnable() {
        var mode = selectedMode();
        var ack = $('sv-credit-ack');
        var warn = $('sv-credit-warning');
        if (warn) {
            warn.hidden = !mode;
            warn.textContent = mode ? warningText(mode) : '';
        }
        enableBtn.disabled = !(mode && ack && ack.checked);
    }

    function load(extraHtml) {
        var forSurvey = current.surveyId;
        return post('credit_status', { SurveyId: forSurvey, Grantor: current.grantor || '' }).then(function (j) {
            // The modal may have been closed, or reopened for another survey, while this was in flight.
            if (!current || current.surveyId !== forSurvey) { return; }
            if (!j || j.status !== 0) { body.innerHTML = errorHtml(j); return; }
            data = j.credit;
            render(extraHtml);
            if ((data.pending | 0) > 0 && !reconciled) {
                reconciled = true;
                post('credit_reconcile', { SurveyId: current.surveyId, Grantor: current.grantor || '' }).then(function (r) {
                    if (r && r.status === 0 && (r.granted | 0) > 0) {
                        load('<div class="sv-notice" role="status">Posted ' + plural(r.granted | 0, 'owed credit', 'owed credits') + '.</div>');
                        if (current.onChange) { current.onChange(); }
                    }
                });
            }
        });
    }

    function enable() {
        var mode = selectedMode();
        if (!mode || !$('sv-credit-ack') || !$('sv-credit-ack').checked) { return; }
        enableBtn.disabled = true;
        enableBtn.classList.add('sv-is-busy');
        post('credit_enable', { SurveyId: current.surveyId, Grantor: current.grantor || '', Mode: mode, Confirm: 1 }).then(function (j) {
            enableBtn.classList.remove('sv-is-busy');
            if (!j || j.status !== 0) {
                body.insertAdjacentHTML('afterbegin', errorHtml(j));
                syncEnable();
                return;
            }
            var msg = 'Credits are on. Posted ' + plural(j.granted | 0, 'credit', 'credits') + '.';
            if ((j.pending | 0) > 0) { msg += ' ' + plural(j.pending | 0, 'credit is', 'credits are') + ' still pending and will be retried.'; }
            load('<div class="sv-notice" role="status">' + esc(msg) + '</div>');
            if (current.onChange) { current.onChange(); }
        });
    }

    function focusables() {
        return Array.prototype.filter.call(ov.querySelectorAll(FOCUSABLE), function (el) { return el.offsetParent !== null; });
    }

    function close() {
        ov.classList.remove('sv-open');
        document.removeEventListener('keydown', onKey, true);
        if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
        current = null;
        data = null;
    }

    function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); close(); return; }
        if (e.key !== 'Tab') { return; }
        var f = focusables();
        if (!f.length) { return; }
        if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    }

    function open(opts) {
        ov = $('sv-credit-overlay');
        body = $('sv-credit-body');
        enableBtn = $('sv-credit-enable');
        closeBtn = $('sv-credit-close');
        if (!ov) { return; }
        current = opts;
        reconciled = false;
        lastFocus = document.activeElement;
        body.innerHTML = 'Loading…';
        enableBtn.hidden = true;
        ov.classList.add('sv-open');
        document.addEventListener('keydown', onKey, true);
        closeBtn.focus();
        load('');
    }

    document.addEventListener('click', function (e) {
        if (!ov || !ov.classList.contains('sv-open')) { return; }
        if (e.target === ov || e.target.closest('#sv-credit-close')) { close(); }
        else if (e.target.closest('#sv-credit-enable')) { enable(); }
        else if (e.target.closest('[data-sv-credit-reload]')) { e.preventDefault(); window.location.reload(); }
    });
    document.addEventListener('change', function (e) {
        if (ov && ov.classList.contains('sv-open') && (e.target.name === 'sv-credit-mode' || e.target.id === 'sv-credit-ack')) { syncEnable(); }
    });

    window.SvCredit = { open: open };
}());
```

- [ ] **Step 4: Styles** (append to `survey.css`, `sv-` prefix, both themes)

```css
/* ---- Attendance credit modal (sharing-and-credits spec §3.6) ---- */
.sv-credit-modal { max-width: 560px; }
.sv-credit-lead { margin: 0 0 8px; font-size: 14px; }
.sv-credit-sub { margin: 10px 0 4px; font-size: 12px; color: var(--ork-text-secondary); }
.sv-credit-list { margin: 0 0 12px; padding-left: 18px; font-size: 13px; line-height: 1.5; }
.sv-credit-modes { border: 0; margin: 8px 0; padding: 0; }
.sv-credit-modes legend { font-size: 13px; margin-bottom: 6px; padding: 0; }
.sv-credit-mode { display: flex; gap: 8px; align-items: flex-start; min-height: 32px; padding: 6px 8px; border: 1px solid var(--sv-l3-border, #cbd5e0); border-radius: 4px; margin-bottom: 6px; background: var(--sv-l3, #fff); cursor: pointer; font-size: 13px; }
.sv-credit-mode input { margin-top: 2px; }
.sv-credit-warning { margin: 8px 0; padding: 8px 10px; border-left: 3px solid var(--ork-warning, #b7791f); background: var(--ork-warning-bg, #fffaf0); font-size: 12px; line-height: 1.5; }
html[data-theme="dark"] .sv-credit-mode { background: #2d3748; border-color: #4a5568; }
html[data-theme="dark"] .sv-credit-warning { background: rgba(214, 158, 46, 0.12); color: var(--ork-text); }
@media (max-width: 700px), (pointer: coarse) { .sv-credit-mode { min-height: 44px; align-items: center; } }
```

(Check each `--ork-*` / `--sv-*` token exists with `grep -n "\-\-ork-warning\|\-\-sv-l3" orkui/template/default/style/tokens.css orkui/template/default/style/survey.css`. Replace any that is missing with its nearest existing token; never a new hard-coded colour when a token exists.)

- [ ] **Step 5: Verify statically and commit**

```bash
node --check orkui/template/default/script/survey-credit.js
php -l orkui/template/default/_survey_credit_modal.tpl
grep -c "sv-overlay" orkui/template/default/Survey_index.tpl   # only markup uses remain, no CSS rules
git add orkui/template/default/_survey_credit_modal.tpl orkui/template/default/script/survey-credit.js orkui/template/default/style/survey.css orkui/template/default/Survey_index.tpl
git commit -m "Enhancement: Survey — shared Attendance credit modal"
```

(The modal is verified in the browser through Tasks 13 and 14.)

---

### Task 13: Survey list — three sections

**Model:** sonnet · effort high

**Files:**
- Modify: `orkui/template/default/Survey_index.tpl`:
  - the inline `<style>` block (`#sv-table` selectors)
  - the table area `:432-540`
  - the inline script (DataTable init `:580+`, status pills)

**Interfaces:**
- Consumes: `$Buckets` / `$Surveys` (Task 11), `SvCredit.open` (Task 12).
- Produces: `section.sv-list-section[data-sv-section=ork|kingdom|park]`, each with `table.sv-survey-table#sv-table-{key}`.

> **Already in place (Tasks 1–11 fixer):** `Survey::listForScope()` sends `ResponseCount`/`response_count` as `null` on a shared row unless `results_share = 'all'`, and the current single-table template already hides the manager's action set and the Build title link on shared rows (`$_shared`), shows `—` (`data-order="-1"`) for a withheld count, and totals the Responses stat over `Access = 'manage'` rows only. Keep all three when restructuring into sections; Step 3 adds the shared set beside the existing `$_shared` guard.

- [ ] **Step 1: Selectors to classes.** The page now has up to three tables, so ids cannot carry the styling. In the inline `<style>`, replace every `#sv-table` with `#theme_container .sv-survey-table`. That is ID + class, which still outranks `reports.css`'s `.rp-table-area table.dataTable` rules and their dark-mode variants, the reason the old comment gives for using an ID. Replace `#theme_container #sv-table` with `#theme_container .sv-survey-table`, and `html[data-theme="dark"] #theme_container #sv-table…` likewise. Update the comment to say "class + #theme_container".

```bash
grep -c "#sv-table" orkui/template/default/Survey_index.tpl   # must be 0 after the edit
```

- [ ] **Step 2: Sections markup.** Replace the single `<table … id="sv-table">…</table>` (and the `$_total === 0` empty state, which now applies only to unscoped pages with no rows anywhere) with a loop. Keep the existing `<thead>` and the existing row markup verbatim inside the loop; only the action cell changes (Step 3).

```php
<?php
$_sec_icon  = ['ork' => 'fa-globe', 'kingdom' => 'fa-crown', 'park' => 'fa-campground'];
$_sec_empty = [
	'ork'     => 'No Amtgard-wide surveys right now.',
	'kingdom' => 'No kingdom surveys right now.',
	'park'    => 'No park surveys yet.',
];
foreach (['ork', 'kingdom', 'park'] as $_key):
	$_rows = $Buckets['Rows'][$_key] ?? [];
	if ($ScopeType === null && !$_rows) { continue; }   // unscoped: only sections with rows
?>
			<section class="sv-list-section" data-sv-section="<?=$_key?>" aria-labelledby="sv-sec-<?=$_key?>">
				<h2 class="sv-list-section-title" id="sv-sec-<?=$_key?>">
					<i class="fas <?=$_sec_icon[$_key]?>" aria-hidden="true"></i>
					<?=htmlspecialchars($Buckets['Labels'][$_key])?>
					<span class="sv-list-section-count"><?=count($_rows)?></span>
				</h2>
<?php if (!$_rows): ?>
				<p class="sv-list-section-empty"><?=$_sec_empty[$_key]?></p>
<?php else: ?>
				<table class="sv-survey-table dataTable" id="sv-table-<?=$_key?>" role="table" style="width:100%">
					<!-- existing <thead> unchanged -->
					<tbody role="rowgroup">
<?php foreach ($_rows as $_row): /* existing per-row variables and <tr> markup unchanged, see Step 3 for the actions cell */ ?>
<?php endforeach; ?>
					</tbody>
				</table>
<?php endif; ?>
			</section>
<?php endforeach; ?>
```

For shared rows whose `results_share !== 'all'`, the Responses cell shows `—`, with `data-order="-1"`:

```php
	$_resp_cell = ($_row['Access'] === 'shared' && ($_row['results_share'] ?? 'none') !== 'all')
		? '<td class="dt-right" data-label="Responses" data-order="-1">&mdash;</td>'
		: '<td class="dt-right" data-label="Responses" data-order="' . (int) $_row['ResponseCount'] . '">' . number_format((int) $_row['ResponseCount']) . '</td>';
```

- [ ] **Step 3: Row actions.** Wrap the existing action buttons (Build, Results, Preview, Clone, Copy link, Archive) in `<?php if ($_row['Access'] === 'manage'): ?> … <?php endif; ?>`. Add the shared set and the Credits button, reusing the existing `.sv-row-btn` + `.sv-row-btn-label` markup so the icon-only desktop rule still applies:

```php
<?php if ($_row['Access'] === 'shared'): ?>
	<?php if ($_row['status'] === 'open'): ?>
		<a class="sv-row-btn" href="<?=UIR?>Survey/s/<?=htmlspecialchars((string)$_row['slug'])?>" data-tip="Take this survey"><i class="fas fa-pen-to-square" aria-hidden="true"></i><span class="sv-row-btn-label">Take</span></a>
	<?php endif; ?>
	<?php if (!empty($_row['CanResults'])): ?>
		<a class="sv-row-btn" href="<?=UIR?>Survey/results/<?=$_sid?>/<?=htmlspecialchars((string)$_row['ResultsContext'])?>"
		   data-tip="<?=$_row['ResultsLabel'] === 'all' ? 'Results (shared: all respondents)' : 'Results (your ' . htmlspecialchars($_row['ResultsLabel']) . ')'?>"><i class="fas fa-chart-bar" aria-hidden="true"></i><span class="sv-row-btn-label"><?=$_row['ResultsLabel'] === 'all' ? 'Results' : 'Results (your ' . htmlspecialchars($_row['ResultsLabel']) . ')'?></span></a>
	<?php endif; ?>
<?php endif; ?>
<?php if (!empty($_row['CreditGrantor']) && $_row['status'] !== 'archived'): ?>
		<button type="button" class="sv-row-btn<?=!empty($_row['CreditOn']) ? ' sv-row-btn-on' : ''?>" data-sv-credit="<?=$_sid?>"
		        data-sv-grantor="<?=htmlspecialchars((string)$_row['CreditGrantor'])?>" data-sv-title="<?=htmlspecialchars((string)$_row['title'])?>"
		        data-tip="<?=!empty($_row['CreditOn']) ? 'Attendance credit is on' : 'Attendance credit'?>"><i class="fas fa-award" aria-hidden="true"></i><span class="sv-row-btn-label">Credits</span></button>
<?php endif; ?>
```

Actions-column `data-tip`s must be right-anchored (house rule). Reuse whatever right-anchor attribute or class the existing row buttons carry (`grep -n "data-tip" orkui/template/default/Survey_index.tpl | head`).

- [ ] **Step 4: Include the modal.** Before the page's inline `<script>`:

```php
<script>window.SvCreditConfig = { uir: <?=json_encode(UIR)?>, csrf: <?=json_encode((string)($SurveyCsrf ?? ''))?> };</script>
<?php include __DIR__ . '/_survey_credit_modal.tpl'; ?>
```

- [ ] **Step 5: Script.**
  - Initialise one DataTable per `.sv-survey-table`, with the existing options unchanged, collecting them in `var dts = []`.
  - The status-pill handler loops `dts.forEach(function (dt) { dt.column(2).search(term, true, false).draw(); })`, keeping its current regex/term logic.
  - Every other reference to the single `dt` (or `#sv-table`) becomes a loop over `dts`.
  - Add the Credits handler:

```js
	document.addEventListener('click', function (e) {
		var b = e.target.closest('[data-sv-credit]');
		if (!b || !window.SvCredit) { return; }
		window.SvCredit.open({
			surveyId: parseInt(b.getAttribute('data-sv-credit'), 10),
			grantor: b.getAttribute('data-sv-grantor') || '',
			title: b.getAttribute('data-sv-title') || '',
			onChange: function () { b.classList.add('sv-row-btn-on'); b.setAttribute('data-tip', 'Attendance credit is on'); }
		});
	});
```

- [ ] **Step 6: Section styles** (inline `<style>` of the template, since they are list-page specific). The `h2` must defeat the global `orkui.css` pill box in both themes:

```css
.sv-list-section { margin: 0 0 22px; }
#theme_container .sv-list-section-title,
html[data-theme="dark"] #theme_container .sv-list-section-title {
	display: flex; align-items: center; gap: 8px; margin: 0 0 10px; padding: 0;
	background: none; border: 0; border-radius: 0; box-shadow: none;
	font-size: 14px; font-weight: 700; color: var(--rp-text-body, var(--ork-text));
}
.sv-list-section-count {
	font-size: 11px; font-weight: 700; padding: 1px 8px; border-radius: 10px;
	background: var(--ork-surface-light, #edf2f7); color: var(--ork-text-secondary);
}
.sv-list-section-empty { margin: 0; padding: 12px 14px; font-size: 13px; color: var(--ork-text-secondary);
	border: 1px dashed var(--rp-border-mid); border-radius: 8px; }
.sv-row-btn.sv-row-btn-on { color: var(--ork-accent, #b7791f); }
html[data-theme="dark"] .sv-list-section-count { background: #2d3748; }
```

- [ ] **Step 7: Browser check** (serial, see Task 18 for the harness): `Survey/index/Kingdom/17` and `Survey/index/Park/1049` as heraldsbridge, light and dark, 1280 px and 360 px. Read the `h2`'s **computed** `background-color`, `border-top-width` and `padding-top` (all transparent/0) in both themes. Open Credits on a shared row: the modal loads, Tab cycles inside it, Escape closes and focus returns.

- [ ] **Step 8: Commit**

```bash
git add orkui/template/default/Survey_index.tpl
git commit -m "Enhancement: Survey — list page in Amtgard / Kingdom / Park sections"
```

---

### Task 14: Builder — Results sharing and Attendance credit card

**Model:** sonnet · effort medium

**Files:**
- Modify: `orkui/template/default/Survey_build.tpl` (emit `SvCreditConfig` and include the modal before `survey-build.js`, `:173-185`)
- Modify: `orkui/template/default/script/survey-build.js`:
  - `CONSENT_COPY` `:109-116`
  - `consentQuote()` `:2139-2150`
  - the Privacy section `:2069-2075`
  - a new `credit` section after it
  - a click handler

**Interfaces:**
- Consumes: `update` with `ResultsShare` (Task 5); `SvCredit.open` (Task 12); `S.survey.results_share` and `S.survey.scope_type` from `SurveyAjax/get` (`SELECT *` carries the new column).

- [ ] **Step 1: Copy.** Add to `CONSENT_COPY`:

```js
        credit:  'This survey gives an attendance credit, which will appear on your public attendance record. It is only given when you choose Any ORK Data.'
```
and replace the last line of `consentQuote()`, currently `return html + '</ul></div>';`, with:

```js
        return html + '</ul><p class="svb-consent-lead">' + esc(CONSENT_COPY.credit) +
               ' <span class="svb-hint">(shown when this survey gives a credit)</span></p></div>';
```

- [ ] **Step 2: Results sharing select** in the Privacy section, after `consentQuote(s)`, only for `ork` and `kingdom` surveys. It autosaves through the existing `data-sv-field` path, since `saveSurveyField` sends `node.value` for selects:

```js
        if (s.scope_type === 'ork' || s.scope_type === 'kingdom') {
            var down = s.scope_type === 'ork' ? 'kingdoms' : 'parks';
            var each = s.scope_type === 'ork' ? 'Each kingdom sees its own players' : 'Each park sees its own players';
            body += selectRow('Share results with ' + down,
                [['none', 'Don’t share'], ['scoped', each], ['all', 'Every ' + down.replace(/s$/, '') + ' sees all results']],
                s.results_share || 'none', 'data-sv-field="ResultsShare"', false,
                'Shared ' + down + ' see charts and stats only — never names, individual responses or the spreadsheet.');
        }
```

- [ ] **Step 3: Attendance credit card.** After the Privacy `section(...)` call:

```js
        /* Attendance credit (sharing-and-credits spec §3.6). The card only opens
           the shared modal; the owner acts for its own org, and an ORK survey's
           owner just reads what kingdoms and parks have turned on. */
        body  = '<p class="svb-hint">Give respondents who choose Any ORK Data an attendance credit — at their home park, or at a generated “Survey Credit” event. Once on, it can’t be turned off.</p>';
        body += '<button type="button" class="sv-btn" id="svb-credit-open"><i class="fas fa-award" aria-hidden="true"></i> ' +
                (s.scope_type === 'ork' ? 'See credits' : 'Set up attendance credit') + '</button>';
        html += section('credit', 'Attendance credit', 'fa-award', body);
```
and in the settings click delegation:

```js
        if (e.target.closest('#svb-credit-open') && window.SvCredit) {
            window.SvCredit.open({
                surveyId: SURVEY_ID,
                grantor: S.survey.scope_type === 'ork' ? '' : (S.survey.scope_type === 'kingdom' ? 'Kingdom/' : 'Park/') + S.survey.scope_id,
                title: S.survey.title
            });
        }
```

- [ ] **Step 4: Gate lock message.** When `update` refuses `DataGateEnabled=0`, the existing save error path shows `data.error` next to the checkbox and the pill reads "Not saved". Confirm it re-checks the box: after a failed save of a checkbox field, set `node.checked = !node.checked`. If `saveSurveyField` has no failure hook, add `{ node: node, onFail: function () { if (node.type === 'checkbox') { node.checked = !node.checked; } } }` and call `opts.onFail` from `save()`'s error branch.

- [ ] **Step 5: Template include** (`Survey_build.tpl`, before the `survey-build.js` script tag):

```php
<script>window.SvCreditConfig = { uir: <?=json_encode(UIR)?>, csrf: <?=json_encode((string)($SurveyCsrf ?? ''))?> };</script>
<?php include __DIR__ . '/_survey_credit_modal.tpl'; ?>
```

- [ ] **Step 6: Verify and commit**

```bash
node --check orkui/template/default/script/survey-build.js
git add orkui/template/default/Survey_build.tpl orkui/template/default/script/survey-build.js
git commit -m "Enhancement: Survey — builder results-sharing setting and attendance credit card"
```
Browser: on the draft fixture 999048 (a kingdom survey), the select saves and reloads with its value, the credit card opens the modal, and the Privacy quote shows the credit line in light and dark. On a park survey the select is absent.

---

### Task 15: Results page — shared mode

**Model:** sonnet · effort medium

**Files:**
- Modify: `orkui/template/default/Survey_results.tpl`:
  - header actions `:137-141`
  - filters form `:201-262`
  - rows section `:283-292`
  - panel `:296-306`
  - `SvConfig` `:310-315`
- Modify: `orkui/template/default/script/survey-results.js`:
  - `post('results', …)` `:1849`
  - `initRows()` `:1569`
  - the summary/apply paths that call `initRows` or reload the table (`:1818`, `:2086-2087`, `:2128`)
  - listeners on removed elements
- Modify: `orkui/template/default/style/survey-results.css` (the lens strip)

**Interfaces:**
- Consumes: `$ResultsAccess`, `$ResultsContext`, `$OwnerName` (Task 11); `results` → `access` and `summary.lens` (`{label}` for a shared viewer; the lens strip reads the label and org name from `$ResultsAccess`).

- [ ] **Step 1: Template.** At the top: `$_svr_shared = ($ResultsAccess['level'] ?? 'manage') === 'shared';` and `$_svr_lens = $ResultsAccess['label'] ?? '';`. Wrap these in `<?php if (!$_svr_shared): ?> … <?php endif; ?>`:
  - the `#svr-export` link and `#svr-print` button (keep `#svr-summary-toggle`)
  - the include-test `<label class="svr-check">` row
  - the whole Responses block around `#svr-rows`
  - the `#svr-panel` aside
  - the kingdom filter field, when `$_svr_lens === 'kingdom' || $_svr_lens === 'park'`

Add the lens strip right after the header:

```php
<?php if ($_svr_shared):
	$_org = htmlspecialchars((string) ($ResultsAccess['org_name'] ?? ''));
	$_lens_text = [
		'kingdom' => 'Showing responses from players of ' . $_org . ' who chose Any ORK Data or My Kingdom and How Long I’ve Been Playing. Anonymous responses can’t be attributed to a kingdom.',
		'park'    => 'Showing responses from ' . $_org . ' players who chose Any ORK Data. Other responses can’t be attributed to a park.',
		'all'     => 'Shared by ' . htmlspecialchars((string) $OwnerName) . ': all respondents.',
	][$_svr_lens] ?? '';
?>
	<div class="rp-context svr-lens" role="note">
		<i class="fas fa-share-nodes rp-context-icon" aria-hidden="true"></i>
		<span><?=$_lens_text?> Charts and stats only; individual responses stay with the survey’s owners.</span>
	</div>
<?php endif; ?>
```

`SvConfig` gains:

```php
	access   : <?=json_encode($_svr_shared ? 'shared' : 'manage')?>,
	context  : <?=json_encode((string) ($ResultsContext ?? ''))?>,
```

- [ ] **Step 2: Script.** Near the top: `var SHARED = (window.SvConfig || {}).access === 'shared';`.
  - The `results` post adds `Context: SvConfig.context || ''`.
  - `initRows()` starts with `if (SHARED) { return; }`. Every `state.table.ajax.reload` call site is guarded by `state.table &&`.
  - Every listener attached to `svr-export`, `svr-print`, `svr-include-test`, `svr-panel-*` or the kingdom select checks that the element exists first (`var el = $('svr-export'); if (el) { … }`).
  - The export-link updater returns early when the link is absent.
  - `readFilters()` must not read `include_test` / `kingdom_ids` from missing controls: default to `false` / `[]`.

- [ ] **Step 3: Style** (`survey-results.css`):

```css
.svr-lens { border-left: 3px solid var(--ork-accent, #3182ce); }
html[data-theme="dark"] .svr-lens { background: rgba(49, 130, 206, 0.10); }
```

- [ ] **Step 4: Verify and commit**

```bash
node --check orkui/template/default/script/survey-results.js
php -l orkui/template/default/Survey_results.tpl
git add orkui/template/default/Survey_results.tpl orkui/template/default/script/survey-results.js orkui/template/default/style/survey-results.css
git commit -m "Enhancement: Survey — shared results view with lens strip"
```

Browser (Task 18 covers it in full):
- A kingdom-lens view of an ORK survey set to `scoped` shows no rows table, Export or Print, and 0 console errors.
- The filters apply.
- A hand-typed `SurveyAjax/rows` POST as that viewer returns status 3.

---

### Task 16: Runner, My Amtgard widget, player page label

**Model:** sonnet · effort medium

**Files:**
- Modify: `orkui/template/default/script/survey-take.js`:
  - copy constants `:69-77`
  - the first-screen notice `:673`
  - the consent card `:780-803`
  - `renderThanks()` `:821`
  - the submit success branch `:1150-1154`
- Modify: `orkui/template/default/style/survey.css` (`.sv-credit-note`, `.sv-chip-credit`)
- Modify: `orkui/template/revised-frontend/Playernew_index.tpl`:
  - the "By" cell `:7419-7432`
  - the widget row `:7488-7493`
  - widget CSS `:246-328` and its dark block `:388+`

**Interfaces:**
- Consumes: `definition.survey.credit_available`, `submit.credit` (Task 11), `available[].credit_available`.

- [ ] **Step 1: Runner copy** (next to `GATE_NOTE`; fixed wording, spec §3.7):

```js
    var CREDIT_NOTE = 'This survey gives an attendance credit, which will appear on your public attendance record. ' +
        'It is only given when you choose ';
    var CREDIT_CHIP = 'Earns an attendance credit';
```

- [ ] **Step 2: Consent card.** After the closing `</div>` of `.sv-consent` (inside `if (gate)`):

```js
            if (s.credit_available) {
                html += '<p class="sv-credit-note"><i class="fas fa-award" aria-hidden="true"></i> ' +
                    esc(CREDIT_NOTE) + '<strong>Any ORK Data</strong>.</p>';
            }
```

- [ ] **Step 3: First-screen chip.** Where the first-screen notice is emitted (`:673`, the function returning the `GATE_NOTE` paragraph), prepend the chip when `def.survey.credit_available`:

```js
        var chip = (def && def.survey && def.survey.credit_available)
            ? '<span class="sv-chip sv-chip-credit"><i class="fas fa-award" aria-hidden="true"></i> ' + esc(CREDIT_CHIP) + '</span> '
            : '';
```
and return `chip + <existing paragraph>`. Without a data gate there is no credit, and `credit_available` is false server-side anyway.

- [ ] **Step 4: Thank-you line.** `renderThanks(html, credit)` gains an optional second argument. After the `sv-intro` div:

```js
        if (credit === 'granted') {
            out += '<p class="sv-credit-note"><i class="fas fa-award" aria-hidden="true"></i> Your attendance credit has been added.</p>';
        } else if (credit === 'pending') {
            out += '<p class="sv-credit-note"><i class="fas fa-award" aria-hidden="true"></i> Your attendance credit will be added shortly.</p>';
        }
```
and the submit success branch calls `renderThanks(r.thanks_html || s.thanks_html || '', r.credit || 'none');`.

- [ ] **Step 5: Runner styles** (`survey.css`):

```css
/* ---- Attendance credit (sharing-and-credits spec §3.7) ---- */
.sv-credit-note { display: flex; gap: 8px; align-items: flex-start; margin: 12px 0 0; padding: 8px 10px;
	border-radius: 4px; background: var(--sv-l2, #f5f8fb); font-size: 13px; line-height: 1.5; }
.sv-credit-note i { margin-top: 3px; color: var(--sv-accent, var(--ork-accent)); }
.sv-chip-credit { display: inline-flex; align-items: center; gap: 6px; padding: 2px 10px; border-radius: 12px;
	font-size: 12px; font-weight: 600; background: var(--sv-l2, #f5f8fb); border: 1px solid var(--sv-l3-border, #cbd5e0); }
html[data-theme="dark"] .sv-credit-note,
html[data-theme="dark"] .sv-chip-credit { background: #26303f; border-color: #4a5568; }
```
(Verify the `--sv-*` names against the top of `survey.css`; use the real L2/L3 tokens from the light-mode surface ladder.)

- [ ] **Step 6: Player page.** "By" cell, after the `self_reg` branch:

```js
						} else if (d.EntryMethod === 'survey') {
							byCell = '<em style="color:var(--ork-text-muted)">Survey credit</em>';
```
(Check that `GetPlayerAttendanceList` returns `EntryMethod` for these rows; it already does for `self_reg`.) The widget row gets a chip after the title label:

```js
								+ (sv.credit_available ? '<span class="pna-survey-credit"><i class="fas fa-award" aria-hidden="true"></i> Credit</span>' : '')
```
with CSS in the widget block, plus its dark twin in the dark block:

```css
.pna-survey-credit { display: inline-flex; align-items: center; gap: 4px; margin-left: 6px; padding: 0 6px; border-radius: 8px; font-size: 11px; font-weight: 600; background: var(--ork-surface-light, #edf2f7); color: var(--ork-text-secondary); }
html[data-theme="dark"] .pna-survey-credit { background: #2d3748; }
```

- [ ] **Step 7: Verify and commit**

```bash
node --check orkui/template/default/script/survey-take.js
git add orkui/template/default/script/survey-take.js orkui/template/default/style/survey.css orkui/template/revised-frontend/Playernew_index.tpl
git commit -m "Enhancement: Survey — credit line on the data gate, chips, thank-you note, Survey credit label"
```

---

## Phase 4 — Docs, verification, review

### Task 17: Guide, release note, spec file list

**Model:** sonnet · effort low

**Files:**
- Modify: `docs/survey-guide.md` (served in-app through `SurveyAjax/help`)
- Modify: `orkui/whats_new_content.php`: extend this branch's existing Survey entry (`grep -n "Survey" orkui/whats_new_content.php`); do not add a second entry.
- Modify: `docs/superpowers/specs/2026-09-10-survey-sharing-and-credits-design.md` §6: note any file the implementation touched that the list omits.

- [ ] **Step 1: Guide sections.** Add two sections to `docs/survey-guide.md`, in its existing voice (plain, second person, short paragraphs):
  - **"Surveys from other levels"**: the Amtgard / Kingdom / Park sections.
  - **"Sharing results with kingdoms and parks"**: the three options; one level only; charts and stats only; the kingdom view counts Any ORK Data and kingdom-and-years responses, the park view counts Any ORK Data only; "fewer than 5" still applies.
  - **"Attendance credits"**:
    - who can turn them on
    - the two modes and the dates they use
    - only Any ORK Data earns a credit, and respondents are told so on the consent screen
    - one credit per player per survey, and the first org to switch it on wins
    - it can't be turned off
    - credits are posted automatically, and officers can still delete an individual credit like any other
    - known effects: class progress counts one attendance per day, credits count toward park attendance, and a credit is public on the player's record.
- [ ] **Step 2: Release note.** Add two bullets to the Survey entry: "Kingdoms and parks see surveys from every level, and can be shared results for their own players" and "Reward respondents with an attendance credit at their home park or a Survey Credit event".
- [ ] **Step 3: Commit**

```bash
php -l orkui/whats_new_content.php
git add docs/survey-guide.md orkui/whats_new_content.php docs/superpowers/specs/2026-09-10-survey-sharing-and-credits-design.md
git commit -m "Enhancement: Survey — guide and release note for sharing and credits"
```

---

### Task 18: Serial browser verification

**Model:** sonnet · effort medium · **serial only** (one browser session; `resize_window` is a no-op, so measure widths in a same-origin iframe harness)

**Setup:**
- Run curl work (Task 11 Step 7) before this task: curl login evicts the browser session.
- If Claude-in-Chrome has no logged-in session, use headless Playwright + Chromium from the repo's `node_modules`, with a session from `POST index.php?Route=Login/login`. Precedent: the session-scratchpad `driver.js` from the base module's verification. `code.highcharts.com` 403s the HeadlessChrome user agent, so set a normal UA.
- Fixtures:
  - an ORK-scoped clone of 999051, with `results_share='scoped'` and responses from kingdom 17
  - a kingdom-17 survey with `results_share='scoped'`
  - one scratch survey per credit mode

  Create them through the builder or `SurveyAjax` as heraldsbridge. Configs are permanent, so never enable credits on 999048/999049/999051.
- Non-admin views need a kingdom officer and a park officer of kingdom 17. Find them with `SELECT mundane_id, kingdom_id, park_id, role FROM ork_authorization WHERE kingdom_id = 17 OR park_id IN (SELECT park_id FROM ork_park WHERE kingdom_id = 17) LIMIT 10`. The auth bypass accepts any password.

**Checks.** Each check runs in light and dark, at 1280 px and at 360 px (iframe harness, `scrollWidth <= clientWidth`, filtered to `offsetParent !== null`):

- [ ] **1. List sections.**
  - `Survey/index/Kingdom/17` as the kingdom officer: three sections with correct membership; shared rows show Take / Results (your kingdom) / Credits; the Responses column is "—" where it should be.
  - `Survey/index/Park/{park}` as the park officer: no sibling park, no other kingdom.
  - Section `h2` computed styles show no pill box in either theme.
- [ ] **2. Shared results.**
  - Kingdom lens and park lens pages: the lens strip text is correct; no rows table, Export, Print or panel; filters work.
  - "Too few responses to show" appears when the lens view is under 5.
  - Console clean (0 errors).
  - A manual `fetch('SurveyAjax/rows', …)` from that page returns status 3.
- [ ] **3. Credits modal** (list page and builder):
  - The warning count matches `SELECT COUNT(*)` of owed full responses.
  - The button stays disabled until a mode and the checkbox are both chosen.
  - Enable succeeds and the "Posted N credits" notice appears.
  - Re-opening shows "Credits are on for …" and no form.
  - Tab is trapped, Escape closes, focus is restored.
  - At 360 px the modal fits with 44 px targets.
  - DB check: `SELECT a.date, a.park_id, a.event_id, a.class_id, a.note, a.entry_method FROM ork_attendance a JOIN ork_survey_credit_grant g ON g.attendance_id = a.attendance_id WHERE g.survey_id = <id>`.
- [ ] **4. Event mode.** The generated event appears on the kingdom or park calendar for the start date, named "Survey Credit - {title}", one day long, with no "currently happening" prompt on `Attendance/park` today.
- [ ] **5. Runner.**
  - A covered player sees the chip on the first screen and the credit line under the consent options.
  - Submitting with Any ORK Data shows "Your attendance credit has been added."
  - Submitting with partial shows no credit line on the thank-you screen and creates no attendance row.
- [ ] **6. Player page.** The credit shows "Survey credit" in the By column; the My Amtgard widget shows the Credit chip for a covered open survey.
- [ ] **7. Builder.**
  - The Results sharing select persists across a reload and is absent on a park survey.
  - Unchecking the data gate on a credited survey shows the refusal and re-checks the box.
  - The consent quote carries the credit line.
- [ ] **8.** Record findings with screenshots in the session scratchpad. Every finding is fixed (polish rule: ALL findings) and re-verified before Task 19.

---

### Task 19: Review gate

**Model:** opus · effort high

- [ ] **Step 1: Suites and lint**

```bash
ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter 'Survey'
for f in $(git diff --name-only master... -- '*.php' '*.tpl'); do php -l "$f" >/dev/null || echo "LINT FAIL $f"; done
for f in $(git diff --name-only master... -- '*.js'); do node --check "$f" || echo "NODE FAIL $f"; done
```
Expected: all green, no FAIL lines.

- [ ] **Step 2:** Run the `layers-review` skill on the branch diff (CSS reuse scan, dark mode, mobile, no SQL outside `system/lib/ork3/`), then the `polish` skill. Fix **all** confirmed findings, not a subset. Check each finding's scope before acting: it must be introduced by this plan's commits.
- [ ] **Step 3: Privacy re-check** against spec D1/D2. Grep that nothing outside `SurveyCredit::grant()` inserts into `ork_survey_credit_grant`. Confirm `grantFor` is only reached for `consent='full'`. Confirm `rows` / `export` / builder actions still use `requireManage`:

```bash
grep -rn "survey_credit_grant" system/lib/ork3 | grep -i insert
grep -n "requireManage\|results_access" orkui/controller/controller.SurveyAjax.php
```
- [ ] **Step 4:** Update the project memory file `project_survey_module.md` with a short "Sharing and credits (2026-09-11)" paragraph: spec and plan paths, D1–D6 decisions, fixture survey ids created, and anything non-obvious learned. Report to the owner: what shipped, test counts, anything skipped, and that nothing is pushed.

---

## Spec coverage (self-review)

| Spec | Task |
|---|---|
| §1 list sections, gates, row actions | 6, 11 (index gate), 13 |
| §2 `results_share` setting, builder select | 1, 5, 14 |
| §2 `resultsAccess`, one level, audience reach | 5 |
| §2 lens enforcement, narrowing, redaction | 3, 6 (counts) |
| §2 park snapshot (full only) + backfill | 1, 2 |
| §2 shared page UI + server refusal of rows/export | 11, 15, 18 |
| §3.1 config + ledger tables, permanence | 1, 9 |
| §3.2 valid grantors, coverage, precedence | 4, 9 |
| §3.3 credit row (class, date, note, entry_method, by_whom) | 7, 9 |
| §3.3 player page label | 16 |
| §3.4 event creation, one-day, published, at_park_id, self-heal | 8, 9, 10 |
| §3.5 live grant, reconcile, cron, audience exclusion, gate lock, activity log | 9, 10, 11 |
| §3.6 panel | 9 (`status`), 12, 13, 14 |
| §3.7 runner line, chips, thank-you | 10, 16 |
| §4 migration + classification | 1 |
| §5 AJAX contract | 11 |
| §7 error handling | 9, 11, 12 |
| §8 testing | 2–10, 18 |
| §9 acceptance criteria | 18, 19 |
| §10 risks (documented for users) | 17 |

