# Survey Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. This plan is executed by three Workflow scripts in `docs/superpowers/plans/2026-09-09-survey-module/workflows/`; each task names the model tier and effort it runs at.

**Goal:** Ship an in-ORK survey tool: builder, mobile-first runner with a consent data gate, Highcharts reporting with row-level data and CSV, My Amtgard widget, and a site banner.

**Architecture:** Schema and three domain classes first (definition, response intake with consent scrubbing, aggregation), each unit-tested without a DB where possible; then a thin model facade, one page controller and one JSON AJAX controller implementing the spec's fixed contract; then a shared question renderer and base stylesheet; then four surfaces built in parallel against that contract; then entry points, widget and banner; then a serial browser pass and a multi-lens review.

**Tech Stack:** PHP 8 / MariaDB / plain-PHP `.tpl` templates / vanilla JS IIFEs / CSS with `--ork-*` tokens; SortableJS 1.15.2, marked@12 + dompurify@3, Highcharts 11.4.8 (CDN, pinned); PHPUnit 10 (`tests/Unit`, `tests/Integration` on the `ork_test` sandbox); Docker dev stack on port 19080.

**Spec:** `docs/superpowers/specs/2026-09-09-survey-module-design.md` — every task argues from it. Executors read both. Section references below (§N) point at the spec.

## Global Constraints

- Branch `feature/survey-module`. Never `git add -A`; stage explicit paths. Never stage `system/lib/ork3/class.Authorization.php`, `CLAUDE.md`, or `agent-instructions/claude.md`. Never push. Never `git stash`. Never create git worktrees.
- `.tpl` files are plain PHP (`<?= ?>`), never Smarty.
- All SQL in `system/lib/ork3/`. Under `orkui/` never `$DB->`, `Ork3::$Lib`, or `new Survey(`/`new SurveyResponse(`/`new SurveyReport(`/`new SurveyTypes(` outside `orkui/model/model.Survey.php`.
- `$this->db->Clear()` before every `DataSet`/`Execute`; `(int)` cast ids; `esc()` strings; `START TRANSACTION`/`COMMIT`/`ROLLBACK` around multi-statement writes; read new ids with `SELECT LAST_INSERT_ID() AS new_id`.
- Before editing an existing PHP file run `awk '/^\t/{c++}END{print c+0}' <file>`; if non-zero run `tools/php-cs-fixer/php-cs-fixer.phar fix <file>` first (memory rule: normalize-first).
- Status codes in JSON: `0` ok, `1` bad request/validation, `3` not authorized, `5` not logged in.
- Class prefix `sv-` for all new CSS; dark mode via `html[data-theme="dark"]`; no native `alert/confirm/prompt`; tooltips via `data-tip`; tap targets ≥ 44 px; text inputs 16 px.
- Every AJAX action calls `requireLogin()` first; every manage action resolves the survey by id and checks `canManage` against that survey's own scope.
- Local login: `POST http://localhost:19080/orkui/Login/login` with `username=heraldsbridge&password=x&Action=Sign+In` into a cookie jar (auth bypass accepts any password; `heraldsbridge` is mundane 46193, kingdom 17, park 1049, ORK admin).
- After the migration: `docker restart ork3-php8-app` (APCu schema cache) and refresh the sandbox for PHPUnit.
- Commit prefix `Enhancement: Survey — <what>`; commit trailer per session instructions.

---

## Phase 1 — Foundation (workflow `survey-1-foundation.js`)

### Task 1: Migration, classification, config constants, asset dir

**Model:** sonnet · effort low

**Files:**
- Create: `db-migrations/2026-09-09-survey-module.sql` (DDL verbatim from spec §3)
- Create: `assets/survey/.gitkeep`
- Modify: `tools/ork-db/manifests/migration-classification.json5` (append one entry)
- Modify: `config.dist.php`, `config.dev.php`, `config.test.php` (two `define`s each, next to the `HTTP_HERALDRY` / `DIR_HERALDRY` lines: `config.dist.php:18`, `config.dev.php:23-56`, `config.test.php:24-55`)

**Interfaces:**
- Produces: tables `ork_survey`, `ork_survey_page`, `ork_survey_question`, `ork_survey_option`, `ork_survey_response`, `ork_survey_answer`, `ork_survey_participation`, `ork_survey_draft`, `ork_survey_dismissal`, `ork_survey_image`; constants `HTTP_SURVEY_IMAGE`, `DIR_SURVEY_IMAGE`.

- [ ] **Step 1: Write the migration** — copy the ten `CREATE TABLE IF NOT EXISTS` statements from spec §3 into the file under a header comment stating purpose, idempotency, and the APCu restart requirement.
- [ ] **Step 2: Add the constants**

```php
define('HTTP_SURVEY_IMAGE', HTTP_ASSETS . 'survey/');   // in the HTTP_* block
define('DIR_SURVEY_IMAGE', DIR_ASSETS . 'survey/');     // in the DIR_* block
```

- [ ] **Step 3: Classify**

```json5
    "2026-09-09-survey-module.sql": { "class": "S", "render": "full", "notes": "Survey module tables; CREATE TABLE IF NOT EXISTS, idempotent" }
```

- [ ] **Step 4: Apply to the dev mirror and restart**

```bash
docker exec -i ork3-php8-db mariadb -uroot -proot ork < db-migrations/2026-09-09-survey-module.sql
docker restart ork3-php8-app
docker exec ork3-php8-db mariadb -uroot -proot ork -Nse "SHOW TABLES LIKE 'ork_survey%'" | wc -l
```
Expected: `10`

- [ ] **Step 5: Refresh the PHPUnit sandbox and confirm classification**

```bash
php tools/ork-db/cli.php drift-check --strict 2>&1 | grep -i "unclassified" ; echo "exit=$?"
bin/ork-db deploy-sandbox --force-refresh --yes
docker exec ork3-php8-test-db mariadb -uroot -proot ork_test -Nse "SHOW TABLES LIKE 'ork_survey%'" | wc -l
mkdir -p assets/survey && touch assets/survey/.gitkeep
```
Expected: no "unclassified" line; second count `10`. (The known `catalog hash drift` line is a pre-existing local-only failure — ignore it.)

- [ ] **Step 6: Commit**

```bash
git add db-migrations/2026-09-09-survey-module.sql assets/survey/.gitkeep tools/ork-db/manifests/migration-classification.json5 config.dist.php config.dev.php config.test.php
git commit -m "Enhancement: Survey — schema, classification, image path constants"
```

### Task 2: `SurveyTypes` catalog (pure)

**Model:** opus · effort medium

**Files:**
- Create: `system/lib/ork3/class.SurveyTypes.php`
- Test: `tests/Unit/SurveyTypesTest.php`

**Interfaces (produces):**

```php
final class SurveyTypes
{
    public const TYPES = ['single','multi','dropdown','yesno','rating','nps','matrix','ranking',
                          'short_text','paragraph','number','date','section','image'];
    public const ANSWERABLE = [/* TYPES minus section,image */];
    public const SHOW_IF_SOURCES = ['single','dropdown','yesno','multi'];
    public const OPTION_ROLES = ['single'=>['choice'],'multi'=>['choice'],'dropdown'=>['choice'],
                                 'yesno'=>['choice'],'matrix'=>['row','column'],'ranking'=>['choice']];

    public static function isType(string $type): bool;
    public static function isAnswerable(string $type): bool;
    /** defaults table from spec §4 */
    public static function defaultSettings(string $type): array;
    /** merges defaults, coerces types, returns ['ok'=>bool,'settings'=>array,'error'=>?string] */
    public static function validateSettings(string $type, $settings): array;
    /** options to create with a new question: yesno => [['role'=>'choice','label'=>'Yes'],['role'=>'choice','label'=>'No']]; single/multi/dropdown/ranking => two 'Option 1'/'Option 2'; matrix => 2 rows + 3 columns; else [] */
    public static function seedOptions(string $type): array;
    /** minimum option counts per role from spec §4, e.g. ['choice'=>2] */
    public static function minOptions(string $type): array;
    /**
     * $question: ['type','required','settings'(array)]; $options: list of ['option_id','role','is_other','label']
     * $value: raw answer per spec §6 "Answers JSON shape" (already json_decoded)
     * returns ['ok'=>bool,'error'=>?string,'rows'=>list<array{option_id:?int,row_option_id:?int,value_text:?string,value_num:?float}>]
     * A missing/empty value on a non-required question returns ok with rows = [].
     */
    public static function validateAnswer(array $question, array $options, $value): array;
    /** $answers: [question_id => raw value]; returns false when the question/page has a show_if that is not satisfied */
    public static function isShown(array $item, array $answers): bool;
    /** true when $value (raw) selects $optionId — handles int, ['option_id'=>..], and arrays */
    public static function selects($value, int $optionId): bool;
}
```

- [ ] **Step 1: Write the failing tests** (`tests/Unit/SurveyTypesTest.php`, `declare(strict_types=1)`, `final class SurveyTypesTest extends TestCase`). Cover at least:

```php
public function testDefaultSettingsRating(): void
{
    $this->assertSame(['min'=>1,'max'=>5,'min_label'=>'','max_label'=>'','icon'=>'star'], SurveyTypes::defaultSettings('rating'));
}
public function testValidateSettingsRejectsRatingMaxBelowMin(): void
{
    $r = SurveyTypes::validateSettings('rating', ['min'=>5,'max'=>2]);
    $this->assertFalse($r['ok']);
}
public function testValidateAnswerSingleAcceptsKnownOption(): void
{
    $q = ['type'=>'single','required'=>1,'settings'=>[]];
    $opts = [['option_id'=>10,'role'=>'choice','is_other'=>0,'label'=>'A'],['option_id'=>11,'role'=>'choice','is_other'=>1,'label'=>'Other']];
    $r = SurveyTypes::validateAnswer($q, $opts, 10);
    $this->assertTrue($r['ok']);
    $this->assertSame(10, $r['rows'][0]['option_id']);
}
public function testValidateAnswerSingleOtherRequiresText(): void
{
    $q = ['type'=>'single','required'=>1,'settings'=>[]];
    $opts = [['option_id'=>10,'role'=>'choice','is_other'=>0,'label'=>'A'],['option_id'=>11,'role'=>'choice','is_other'=>1,'label'=>'Other']];
    $this->assertFalse(SurveyTypes::validateAnswer($q, $opts, ['option_id'=>11,'other'=>''])['ok']);
    $ok = SurveyTypes::validateAnswer($q, $opts, ['option_id'=>11,'other'=>'Bardic']);
    $this->assertSame('Bardic', $ok['rows'][0]['value_text']);
}
public function testValidateAnswerSingleRejectsForeignOption(): void { /* option 99 => ok=false */ }
public function testMultiHonoursMinMaxSelect(): void { /* min_select 2 with 1 chosen => false; max_select 2 with 3 chosen => false */ }
public function testRequiredMissingFails_NotRequiredMissingOk(): void { /* rating required null => false; not required null => ok, rows [] */ }
public function testNpsRange(): void { /* 11 => false; 10 => ok value_num 10.0 */ }
public function testMatrixRowsAndColumns(): void { /* value {rowId: colId}; foreign col => false; require_all_rows with one missing => false */ }
public function testRankingProducesRankRows(): void { /* [12,10,11] => rows value_num 1,2,3 in that option order; duplicate option => false; rank_all with missing => false */ }
public function testTextMaxLength(): void { /* short_text max_length 5 with 'abcdef' => false */ }
public function testNumberStepAndBounds(): void { /* min 0 max 10 step 1: 11 => false; 2.5 => false; 7 => ok */ }
public function testDateIsoOnly(): void { /* '2026-02-30' => false; '2026-02-28' => ok value_text */ }
public function testIsShownQuestionLevel(): void
{
    $q = ['show_if_question_id'=>5,'show_if_option_id'=>50];
    $this->assertTrue(SurveyTypes::isShown($q, [5=>50]));
    $this->assertTrue(SurveyTypes::isShown($q, [5=>[49,50]]));
    $this->assertFalse(SurveyTypes::isShown($q, [5=>49]));
    $this->assertFalse(SurveyTypes::isShown($q, []));
    $this->assertTrue(SurveyTypes::isShown(['show_if_question_id'=>null,'show_if_option_id'=>null], []));
}
public function testSeedOptionsYesNo(): void { /* two choice options labelled Yes / No */ }
```

- [ ] **Step 2: Run to verify failure**: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter SurveyTypesTest` — Expected: errors "Class SurveyTypes not found".
- [ ] **Step 3: Implement `class.SurveyTypes.php`** exactly per the interface and spec §4 (settings defaults, required semantics, answer normalisation into rows). No DB, no globals. Text values trimmed; `value_text` of an "other" write-in capped at 255.
- [ ] **Step 4: Run tests** — Expected: all PASS. `php -l system/lib/ork3/class.SurveyTypes.php` — Expected: No syntax errors.
- [ ] **Step 5: Commit** — `git add system/lib/ork3/class.SurveyTypes.php tests/Unit/SurveyTypesTest.php && git commit -m "Enhancement: Survey — question type catalog with validation and show-if"`

### Task 3: `Survey` domain (definition, lifecycle, auth, images)

**Model:** opus · effort medium — runs in parallel with Tasks 4 and 5 after Task 2.

**Files:**
- Create: `system/lib/ork3/class.Survey.php`
- Reference: `system/lib/ork3/class.QualTest.php:7-64, 452-586, 2578-2657` (DB idiom, canManage, transaction, publish guards); `class.Banner.php:7-95, 284-300` (upload, entityMeta); `orkui/controller/controller.QualTestAjax.php:57-60` (Parsedown safe mode).

**Interfaces (produces):** every method returns a QualTest-style envelope `['Status'=>0|1|3, 'Error'=>string, ...payload]` unless noted.

```php
class Survey
{
    public function __construct();                                   // global $DB
    public function isOrkAdmin(int $uid): bool;
    public function canCreate(int $uid, string $scopeType, int $scopeId): bool;   // §1 table
    public function canManage(int $uid, array $surveyRow): bool;                  // scope from the row, never from a request
    /** @return list<array{scope_type:string,scope_id:int,name:string}> ork admin => ['ork',0,'All of Amtgard'] + every kingdom + every park; officers => their kingdoms (+principalities) / parks */
    public function manageableScopes(int $uid): array;
    public function create(int $uid, string $scopeType, int $scopeId, string $title): array;  // +SurveyId; creates page 1; slug via random_int
    public function get(int $surveyId): array;        // +Survey, Pages, Questions (each with Options), Images, Locked
    public function getRow(int $surveyId): ?array;    // raw ork_survey row or null
    public function getBySlug(string $slug): ?array;
    public function update(int $surveyId, array $fields): array;    // whitelist of spec §6 `update` fields; '' => NULL for nullable columns; validates AccentColor '#rrggbb', dates, JSON int array
    public function setStatus(int $surveyId, string $status): array;  // open: >=1 answerable + every question valid (settings + minOptions + show_if precedence); sets opened_at first time, closed_at
    public function isStructureLocked(array $surveyRow): bool;       // opened_at !== null
    public function cloneSurvey(int $surveyId, int $uid): array;     // +SurveyId; copies pages/questions/options/images (files copied), status draft, new slug, title "Copy of …"
    public function delete(int $surveyId): array;                    // draft with zero responses only; removes image files
    /** @return list<array> rows + ResponseCount + ScopeName; $scopeType/$scopeId null => everything the user may manage */
    public function listManageable(int $uid, ?string $scopeType = null, ?int $scopeId = null): array;
    public function pageAdd(int $surveyId): array;                   // +Page
    public function pageUpdate(int $pageId, array $fields): array;
    public function pageDelete(int $pageId): array;                  // refuses last page; moves questions to previous page
    public function pageReorder(int $surveyId, array $pageIds): array;
    public function questionAdd(int $surveyId, int $pageId, string $type, ?int $afterQuestionId): array; // +Question (with seeded Options)
    public function questionUpdate(int $questionId, array $fields): array;  // Prompt, HelpMd, ImageId, Required, Settings(array), ShowIfQuestionId, ShowIfOptionId; validates via SurveyTypes
    public function questionDelete(int $questionId): array;
    public function questionReorder(int $pageId, array $questionIds): array;
    public function questionMove(int $questionId, int $pageId, int $index): array;
    public function optionSet(int $questionId, string $role, array $options): array;  // +Options; replace-all, keep ids given; enforces minOptions; yesno keeps exactly 2
    public function imageAdd(int $surveyId, int $uid, string $tmpPath, string $clientName): array; // +ImageId, Url, Width, Height; JPEG/PNG, 2 MB, GD clamp 1600px
    public function imageDelete(int $imageId): array;
    public function imageUrl(array $imageRow): string;               // HTTP_SURVEY_IMAGE . sprintf('%06d', id) . '.' . ext
    public function renderMarkdown(?string $md): string;             // Parsedown safe mode; '' for null
    public function scopeName(string $scopeType, int $scopeId): string;
}
```
Structural mutations (`page*`, `question*` except `questionUpdate` copy fields, `optionSet` when ids change/count changes) return `['Status'=>1,'Error'=>'Survey structure is locked because it has been opened.']` when locked.

- [ ] **Step 1: Write a characterization test** `tests/Unit/SurveyPureTest.php` for the DB-free pieces: `renderMarkdown('**a** <script>x</script>')` contains `<strong>a</strong>` and no `<script>`; `imageUrl(['image_id'=>7,'ext'=>'png'])` ends with `/survey/000007.png`. Run — Expected: class not found.
- [ ] **Step 2: Implement the class.** Slug: `substr(strtr(base64_encode(random_bytes(8)), '+/', 'aa'), 0, 8)` lowercased and filtered to `[a-z0-9]`, retried while a row exists. `setStatus('open')` validation returns `['Status'=>1,'Errors'=>[question_id=>msg]]`. Kingdom-scope `canCreate` uses `Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $scopeId, AUTH_CREATE)`; park uses `AUTH_PARK`; `ork` requires `isOrkAdmin` (`HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN)`).
- [ ] **Step 3: Smoke against the dev mirror** (temporary script in the scratchpad, not committed):

```bash
docker exec ork3-php8-app php -r 'require "/var/www/html/startup.php"; $s=new Survey(); $r=$s->create(46193,"kingdom",17,"Smoke"); var_dump($r["Status"], $r["SurveyId"]); $q=$s->questionAdd($r["SurveyId"], $s->get($r["SurveyId"])["Pages"][0]["page_id"], "yesno", null); var_dump(count($q["Question"]["Options"])); var_dump($s->setStatus($r["SurveyId"],"open")["Status"]); var_dump($s->isStructureLocked($s->getRow($r["SurveyId"]))); var_dump($s->questionAdd($r["SurveyId"], 0, "single", null)["Status"]);'
```
Expected: `int(0)`, an id, `int(2)`, `int(0)`, `bool(true)`, `int(1)`. Then delete the smoke survey rows by id.
- [ ] **Step 4: Tests + lint** — `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter SurveyPureTest` PASS; `php -l` clean.
- [ ] **Step 5: Commit** — `git add system/lib/ork3/class.Survey.php tests/Unit/SurveyPureTest.php && git commit -m "Enhancement: Survey — definition, lifecycle, authorization and images domain"`

### Task 4: `SurveyResponse` domain (eligibility, drafts, consent, submit)

**Model:** opus · effort high — parallel with Tasks 3 and 5.

**Files:**
- Create: `system/lib/ork3/class.SurveyResponse.php`
- Test: `tests/Unit/SurveyConsentTest.php`
- Reference: spec §1 audience, §2 consent table, §6 respondent contract; `class.Player.php:4635` (`get_earliest_attendance_date`); `class.QualTest.php:604-690` in the AJAX controller for the spoof-check idea.

**Interfaces (produces):**

```php
class SurveyResponse
{
    public const CONSENTS = ['full','partial','anonymous'];
    public function __construct();
    public function tenureMonths(int $uid): int;                     // player_since_override ?? earliest attendance; 0 when unknown
    /** @return array{eligible:bool,reason:string} reasons: 'ok','closed','not_open_yet','scope','inactive','tenure','completed','banned' */
    public function eligibility(array $surveyRow, int $uid): array;
    /** respondent view per §6 `definition`; $preview true bypasses eligibility for managers (caller checks canManage) */
    public function definitionForRespondent(int $surveyId, int $uid, bool $preview): array;   // +Survey, Pages, Draft, Eligible, Reason
    public function draftSave(int $surveyId, int $uid, array $answers, int $pageIndex): array;
    public function draftLoad(int $surveyId, int $uid): ?array;      // ['answers'=>array,'page_index'=>int,'started_at'=>string]
    public function draftDelete(int $surveyId, int $uid): void;
    /** @return array{ok:bool,errors:array<int,string>,rows:list<array>} evaluates show_if, required, per-type validation via SurveyTypes; hidden questions discarded */
    public function validateSubmission(array $definition, array $answers): array;
    /** PURE. $row has mundane_id, kingdom_id, tenure_months, started_at, submitted_at ('Y-m-d H:i:s'), duration_seconds; returns the row per spec §2 table */
    public static function scrubForConsent(array $row, string $consent): array;
    public function submit(int $surveyId, int $uid, array $answers, string $consent, int $durationSeconds, bool $isTest): array; // +ThanksHtml
    /** @return list<array{survey_id,title,description,scope_label,close_at,in_progress}> open + eligible, ordered close_at asc nulls last */
    public function availableFor(int $uid): array;
    public function bannerFor(int $uid): ?array;                     // one open show_banner survey, eligible, not dismissed, else null
    public function dismissBanner(int $surveyId, int $uid): void;
}
```

- [ ] **Step 1: Write the failing consent tests**

```php
final class SurveyConsentTest extends TestCase
{
    private function row(): array
    {
        return ['mundane_id'=>46193,'kingdom_id'=>17,'tenure_months'=>87,'started_at'=>'2026-09-09 14:02:11',
                'submitted_at'=>'2026-09-09 14:09:40','duration_seconds'=>449];
    }
    public function testFullKeepsEverything(): void { $this->assertSame($this->row(), SurveyResponse::scrubForConsent($this->row(), 'full')); }
    public function testPartialDropsIdentityAndTruncatesDay(): void
    {
        $r = SurveyResponse::scrubForConsent($this->row(), 'partial');
        $this->assertNull($r['mundane_id']); $this->assertSame(17, $r['kingdom_id']); $this->assertSame(87, $r['tenure_months']);
        $this->assertNull($r['started_at']); $this->assertSame('2026-09-09 00:00:00', $r['submitted_at']); $this->assertSame(449, $r['duration_seconds']);
    }
    public function testAnonymousDropsAll(): void
    {
        $r = SurveyResponse::scrubForConsent($this->row(), 'anonymous');
        foreach (['mundane_id','kingdom_id','tenure_months','started_at','duration_seconds'] as $k) { $this->assertNull($r[$k], $k); }
        $this->assertSame('2026-09-09 00:00:00', $r['submitted_at']);
    }
    public function testUnknownConsentThrows(): void { $this->expectException(InvalidArgumentException::class); SurveyResponse::scrubForConsent($this->row(), 'x'); }
}
```
Run — Expected: class not found.
- [ ] **Step 2: Implement.** `submit()` is one transaction in this order: reload survey row → `eligibility` (unless `$isTest` and manager) → build definition → `validateSubmission` → consent = `$isTest ? 'full' : ($survey['data_gate_enabled'] ? $consent : 'anonymous')` → `scrubForConsent` → INSERT response → INSERT answers (one multi-row statement) → `INSERT INTO ork_survey_participation` (skip for `$isTest`) → DELETE draft → `UPDATE ork_survey SET response_count = response_count + 1` (skip for test) → COMMIT. Any `Status != 0` → ROLLBACK. `started_at` comes from the draft when present, else `NOW()`. Kingdom scope match includes `parent_kingdom_id` one level. `definitionForRespondent` renders `welcome_md`/`thanks_md`/`help_md`/`description_md` to HTML with `Survey::renderMarkdown`, applies `randomize` (stable per draft: seed = crc32(survey_id . uid)), and never emits `settings` keys the runner does not need beyond those in §4.
- [ ] **Step 3: Smoke on the dev mirror** with the Task 3 smoke survey pattern: create + one `rating` question + open; `submit(id, 46193, [qid=>4], 'partial', 30, false)` → `Status 0`; `SELECT mundane_id, kingdom_id, submitted_at FROM ork_survey_response` → `NULL, 17, <today> 00:00:00`; second submit → `Status 1` reason completed; `SELECT * FROM ork_survey_participation` → one row with two columns only. Clean up rows.
- [ ] **Step 4: Tests + lint** PASS / clean.
- [ ] **Step 5: Commit** — `git add system/lib/ork3/class.SurveyResponse.php tests/Unit/SurveyConsentTest.php && git commit -m "Enhancement: Survey — response intake with consent data gate"`

### Task 5: `SurveyReport` domain (aggregation, rows, CSV)

**Model:** opus · effort medium — parallel with Tasks 3 and 4.

**Files:**
- Create: `system/lib/ork3/class.SurveyReport.php`
- Test: `tests/Unit/SurveyAggregateTest.php`

**Interfaces (produces):**

```php
class SurveyReport
{
    public const DEFAULT_FILTERS = ['kingdom_ids'=>[], 'consent'=>'any', 'date_from'=>null, 'date_to'=>null, 'crosstab_question_id'=>null, 'include_test'=>false];
    public function __construct();
    public static function normalizeFilters($filters): array;       // coerce JSON/array to DEFAULT_FILTERS shape
    public function summary(int $surveyId, array $filters): array;   // §5 shape incl. excluded_anonymous (rows dropped ONLY because kingdom_ids is set)
    public function aggregate(int $surveyId, array $filters): array; // ['questions'=>[...]] per §4 "Aggregate shape" (+ 'crosstab' when set)
    /** PURE. $answerRows: list of ['response_id','option_id','row_option_id','value_text','value_num']; $options: list of option rows for the question */
    public static function aggregateType(string $type, array $answerRows, array $options, array $settings): array;
    public function rows(int $surveyId, array $filters, int $offset, int $limit): array;  // ['total'=>int,'columns'=>[...],'rows'=>[...]] per §5
    public function csv(int $surveyId, array $filters): string;      // header + rows, RFC 4180, UTF-8 BOM
    public static function displayAnswer(string $type, array $rowsForQuestion, array $optionsById): string; // "A; B" / "4" / "Row: Col | Row: Col" / "1. X 2. Y"
}
```

- [ ] **Step 1: Write the failing aggregate tests** — one test per type using `aggregateType` with hand-built rows; assert the exact numbers:
  - single: 3 rows for opt 10, 1 for 11 → counts 3/1, pct 75/25, `n=4`, `other_texts` from is_other rows.
  - multi: respondents 3 (distinct response_id), opt 10 chosen by 3, opt 11 by 1 → pct 100/33.3, `mean_selected` 1.333.
  - rating 1–5: values [5,4,4,2] → mean 3.75, median 4, distribution keys 1..5 all present.
  - nps: values [10,9,7,3,0] → promoters 2, passives 1, detractors 2, score 0.
  - matrix: 2 rows × 3 columns with `value_num` 1,2,3 → per-row counts and weighted_mean.
  - ranking: 3 options, responses ranks [A1 B2 C3], [B1 A2 C3] → mean_rank A 1.5, first_count A 1 B 1, Borda A 5 B 5 C 2.
  - number: [1,2,3,4,100] → mean 22, median 3, min 1, max 100, 10 bins with sum of counts 5.
  - date: 3 dates over 2 months → by_month 2 entries.
  - text: n only.
  Run — Expected: class not found.
- [ ] **Step 2: Implement.** SQL side: one query for responses matching filters (consent, kingdom, date, is_test), one query for all answers of those responses joined to options, group in PHP by question and call `aggregateType`. Cross-tab: partition response ids by their answer to `crosstab_question_id` and call `aggregateType` per partition for choice/rating/nps questions. `rows()` masks per consent (`persona`, `mundane_id` only for `full`; `kingdom`, `tenure_years = floor(months/12)` for full/partial). `summary.completion = responses / (responses + open drafts)`; `median_duration` over rows with non-null duration.
- [ ] **Step 3: Tests + lint** PASS / clean.
- [ ] **Step 4: Commit** — `git add system/lib/ork3/class.SurveyReport.php tests/Unit/SurveyAggregateTest.php && git commit -m "Enhancement: Survey — aggregation, row-level data and CSV domain"`

### Task 6: Model facade, page controller, AJAX controller, help doc

**Model:** sonnet · effort medium — after Tasks 3–5.

**Files:**
- Create: `orkui/model/model.Survey.php`, `orkui/controller/controller.Survey.php`, `orkui/controller/controller.SurveyAjax.php`, `docs/survey-guide.md`
- Reference: `orkui/model/model.QualTest.php` (facade shape), `orkui/controller/controller.QualTest.php:5-60, 270-319` (page actions, arg splitting, CSV export with `exit`), `controller.QualTestAjax.php:1-90` (guards, help), `system/lib/system/class.Controller.php:23-34` (`no_authorization`).

**Interfaces:**
- Consumes: every method in Tasks 3–5.
- Produces: `Model_Survey` with snake_case one-line delegates (`can_manage`, `get`, `list_manageable`, `definition_for_respondent`, `submit`, `results`, `rows`, `csv`, `banner_for`, `available_for`, …); routes `Survey/index[/Kingdom|Park/{id}]`, `Survey/build/{id}`, `Survey/take/{id}[/preview]`, `Survey/s/{slug}`, `Survey/results/{id}`, `Survey/export/{id}?filters=<json>`; every `SurveyAjax/<action>` in spec §6 with exactly those POST names and response keys. Page controllers set `$this->data`: index → `Surveys`, `Scopes`, `ScopeType`, `ScopeId`, `ScopeName`, `IsOrkAdmin`; build → `Survey` (from `get`), `SurveyId`; take → `SurveyId`, `IsPreview`, `CanManage`; results → `SurveyId`, `Survey`, `Kingdoms` (for the filter, from `manageableScopes` restricted to kingdoms), `Questions`.

- [ ] **Step 1: Model** — one private factory per domain class; no logic.
- [ ] **Step 2: Page controller** — constructor `load_model('Survey')`; `index` checks the user can manage at least one scope (else `no_authorization`); `build`/`results`/`export` check `can_manage`; `take` renders for any logged-in user (eligibility is enforced by the AJAX `definition` call and again by `submit`); `s($slug)` resolves and sets `$this->data['SurveyId']` then `$this->template = 'Survey_take.tpl'`; `export` streams `text/csv` with `Content-Disposition: attachment; filename="survey-{id}.csv"` and `exit`.
- [ ] **Step 3: AJAX controller** — `jsonOut`, `requireLogin`, `requireManage($surveyId)` (loads row, `can_manage`, else `status 3`), `requireUnlocked`; one method per §6 action; `Filters`, `Answers`, `Options`, `PageIds`, `QuestionIds`, `AudienceKingdomIds` decoded with `json_decode(..., true)` and rejected with `status 1` when not an array; `image_upload` requires `is_uploaded_file($_FILES['Image']['tmp_name'])`.
- [ ] **Step 4: `docs/survey-guide.md`** — 80–150 lines: building, question types, skip logic, audience, the data gate (quote the fixed copy), what reporting shows per consent level, CSV.
- [ ] **Step 5: Curl every action once** (login jar as in Global Constraints). Minimum sequence, each with `Expected:`:

```bash
J=/tmp/sv-cookies.txt; U=http://localhost:19080/orkui
curl -s -c $J -b $J -X POST -d "username=heraldsbridge&password=x&Action=Sign+In" $U/Login/login -o /dev/null
curl -s -b $J -X POST $U/index.php?Route=SurveyAjax/scopes | head -c 300                       # {"status":0,"scopes":[...]}
SID=$(curl -s -b $J -X POST -d "ScopeType=kingdom&ScopeId=17&Title=Curl+Survey" "$U/index.php?Route=SurveyAjax/create" | python3 -c 'import sys,json;print(json.load(sys.stdin)["survey_id"])')
PID=$(curl -s -b $J -X POST -d "SurveyId=$SID" "$U/index.php?Route=SurveyAjax/get" | python3 -c 'import sys,json;print(json.load(sys.stdin)["pages"][0]["page_id"])')
curl -s -b $J -X POST -d "SurveyId=$SID&PageId=$PID&Type=rating" "$U/index.php?Route=SurveyAjax/question_add"   # {"status":0,"question":{...}}
curl -s -b $J -X POST -d "SurveyId=$SID&Status=open" "$U/index.php?Route=SurveyAjax/set_status"                  # {"status":0,...}
curl -s -b $J -X POST -d "SurveyId=$SID&Preview=0" "$U/index.php?Route=SurveyAjax/definition" | head -c 400      # eligible true
curl -s -b $J -X POST -d "SurveyId=$SID&Answers={\"<qid>\":4}&Consent=anonymous&DurationSeconds=12&IsTest=0" "$U/index.php?Route=SurveyAjax/submit"  # {"status":0,"thanks_html":...}
curl -s -b $J -X POST -d "SurveyId=$SID&Filters={}" "$U/index.php?Route=SurveyAjax/results" | head -c 600         # summary.responses 1
curl -s -b $J "$U/index.php?Route=Survey/export/$SID" | head -3                                                   # CSV header + 1 row
curl -s -b $J -o /dev/null -w '%{http_code}\n' "$U/index.php?Route=Survey/build/$SID"                            # 200
```
Then `docker exec ork3-php8-db mariadb -uroot -proot ork -e "SELECT consent, mundane_id, submitted_at FROM ork_survey_response WHERE survey_id=$SID"` → `anonymous NULL <today> 00:00:00`. Leave the curl survey in place (the browser phase reuses it); note its id in the task report.
- [ ] **Step 6: Lint + commit** — `php -l` on the three PHP files; `git add orkui/model/model.Survey.php orkui/controller/controller.Survey.php orkui/controller/controller.SurveyAjax.php docs/survey-guide.md && git commit -m "Enhancement: Survey — model facade, page and AJAX controllers, help guide"`

### Task 7: Integration test + foundation verification

**Model:** opus · effort medium (verifier) · fixer opus · effort medium

**Files:**
- Create: `tests/Integration/SurveyTest.php`
- Reference: `tests/Integration/BannerTest.php:1-40` (skip when DB unavailable, fixture style), `tests/Support/` fixtures.

- [ ] **Step 1: Write the integration test** on the sandbox, using a fixture helper that creates a kingdom officer with `AUTH_KINGDOM/AUTH_CREATE` (see `BannerFixture::createGrantorWithAuth`) and three player accounts in that kingdom. Cases, in one class with cleanup in `tearDown` (delete `ork_survey%` rows by survey id, images by id):
  1. `create` by the officer → status 0; by an EDIT-only officer → `canCreate` false.
  2. add page 2, `single` with options + `is_other`, `multi`, `rating`, `matrix`, `ranking`, `paragraph`; `setStatus('open')` → 0; `questionAdd` after open → 1 (locked); `questionUpdate` prompt after open → 0.
  3. three submits (full / partial / anonymous) from the three players → stored columns match spec §2; `ork_survey_participation` has three rows and only two columns (`SHOW COLUMNS`); second submit by player 1 → eligibility `completed`.
  4. `summary` → responses 3, consent split 1/1/1; kingdom filter → `excluded_anonymous` 1; `aggregate` for the single question → counts as submitted; `rows` → persona present only on the full row; `csv` → 4 lines.
  5. `canManage` false for an officer of another kingdom; `cloneSurvey` → new draft with same question count and `response_count` 0.
- [ ] **Step 2: Run** `bin/run-unit-tests.sh 2>&1 | tail -30` — Expected: `SurveyTest`, `SurveyTypesTest`, `SurveyConsentTest`, `SurveyAggregateTest`, `SurveyPureTest` all pass (the pre-existing unrelated failures on this machine are the baseline described in memory; compare against `git stash`-free baseline by running the same command filtered: `--filter 'Survey'`).
- [ ] **Step 3: Verifier (separate agent, fixes nothing)** — re-runs the Task 6 curl sequence from a clean jar, tries the negative cases (`set_status` on someone else's survey → 3, `submit` with a foreign option id → 1, `definition` for a park-scoped survey from a player in another park → `{status:1, error:'Survey not found.'}` (out-of-scope surveys stay unpublished; see spec §6)), greps `orkui/` for `\$DB->|Ork3::\$Lib|new Survey(|new SurveyResponse(|new SurveyReport(|new SurveyTypes(` (only `orkui/model/model.Survey.php` may match), runs `php -l` on every new PHP file, and returns a findings list.
- [ ] **Step 4: Fixer** applies every finding, re-runs Steps 2–3, commits `Enhancement: Survey — integration test and foundation fixes`.

---

## Phase 2 — Surfaces (workflow `survey-2-surfaces.js`)

### Task 8: Base stylesheet and shared question renderer

**Model:** opus · effort medium — first in Phase 2; Tasks 9–13 consume it.

**Files:**
- Create: `orkui/template/default/style/survey.css`, `orkui/template/default/script/survey-render.js`
- Reference: `orkui/template/default/style/tokens.css` (`--ork-*`), `reports.css:8-21, 712+` (token scoping and dark-mode pattern), `Playernew_index.tpl:246-330` (mobile breakpoints in use).

**Interfaces (produces):**

```js
window.SvRender = {
  // q: question object from SurveyAjax/definition or /get (type, question_id, prompt, help_html, image_url, required, settings, options[])
  // state: current raw answer value (spec §6 shape) or undefined
  // mode: 'take' | 'preview'  (preview renders inert controls with tabindex=-1 and class sv-q-preview)
  question(q, state, mode) -> HTML string   // root: <div class="sv-q sv-q-<type>" data-qid="…">
  read(rootEl, q) -> raw value | undefined   // inverse of question(); returns undefined when nothing answered
  write(rootEl, q, value) -> void            // restore a draft value
  setError(rootEl, message|null) -> void     // renders/clears <div class="sv-q-error" role="alert">
  block(q) -> HTML string                    // 'section' and 'image' types
  escape(s) -> string
};
```
CSS contract: `.sv-root` (page column, `max-width:720px`), `--sv-accent` (defaults to `var(--ork-blue-primary)`, overridden inline by the runner from `accent_color`), `.sv-card`, `.sv-btn`/`.sv-btn-primary`/`.sv-btn-ghost` (min-height 44px), `.sv-progress` + `.sv-progress-bar`, `.sv-q`, `.sv-q-prompt`, `.sv-q-help`, `.sv-q-required`, `.sv-choice` (label wrapping a 24px control, min-height 44px), `.sv-scale` (rating/NPS button row, wraps at 420px), `.sv-matrix` (table; at ≤700px collapses to stacked rows with column labels repeated), `.sv-rank` (list with ▲▼ buttons and drag handle), `.sv-input`/`.sv-textarea`/`.sv-select` (16px font), `.sv-q-error`, `.sv-consent` (three `.sv-consent-opt` cards), `.sv-notice`. Dark-mode block for every colour. No `.rp-*` rules and nothing copied from `reports.css`.

- [ ] **Step 1: Write the renderer with a self-check harness** — a scratchpad HTML page that loads `survey-render.js`, renders one of every type with a sample state, calls `read()` and asserts round-trip equality via `console.assert`; open it with Claude-in-Chrome once and confirm zero assertion failures in the console.
- [ ] **Step 2: Write `survey.css`** per the contract; verify with the same harness at 360 px width that no element exceeds the viewport (`document.documentElement.scrollWidth <= innerWidth`).
- [ ] **Step 3: Commit** — `git add orkui/template/default/style/survey.css orkui/template/default/script/survey-render.js && git commit -m "Enhancement: Survey — shared question renderer and base stylesheet"`

### Task 9: Survey list page

**Model:** sonnet · effort medium — parallel with Tasks 10–13.

**Files:**
- Create: `orkui/template/default/Survey_index.tpl`
- Reference: `orkui/template/default/Reports_attendance.tpl:194-260` (shell markup), memory note on `.rp-*` shell (header → context → stats → body with sidebar filters + table area), `QualTest_manage.tpl` (a modal built without native dialogs).

- [ ] **Step 1: Build the page** per spec §7: `.rp-root` with header "Surveys" + scope chip + `+ New Survey` (`.rp-btn-ghost`), context strip, stats row (total, open, drafts, responses), sidebar with status filter pills and an "About Surveys" card (links the help modal via `SurveyAjax/help` `Doc=surveys`), table area listing `$Surveys` with actions `Build`, `Results`, `Preview`, `Clone`, `Copy link`, `Archive` as `.rp-row-btn`s. The New Survey modal: title input + scope `<select>` from `$Scopes`; on submit `SurveyAjax/create` then redirect to `Survey/build/{id}`. Clone/Archive post to `clone`/`set_status`; "Copy link" uses `navigator.clipboard.writeText` with a `.sv-notice` fallback. Empty state via `.rp-empty-state`.
- [ ] **Step 2: Verify** — `curl -s -b $J "$U/index.php?Route=Survey/index/Kingdom/17" | grep -c 'rp-root'` → `1`; page renders the Task 6 curl survey; a non-manager gets the `no_authorization` error block.
- [ ] **Step 3: Commit** — `git add orkui/template/default/Survey_index.tpl && git commit -m "Enhancement: Survey — manage list page"`

### Task 10: Builder

**Model:** opus · effort high — parallel with Tasks 9, 11–13.

**Files:**
- Create: `orkui/template/default/Survey_build.tpl`, `orkui/template/default/style/survey-build.css`, `orkui/template/default/script/survey-build.js`
- Reference: spec §7 Builder; `SvRender` (Task 8); SortableJS 1.15.2 from cdnjs; `marked@12` + `dompurify@3` from jsDelivr (as `Playernew_index.tpl:3932-3933`); `Playernew_index.tpl:4106` for the `filemtime` include idiom.

- [ ] **Step 1: Template** — links `reports.css`, `survey.css`, `survey-build.css`; emits `window.SvConfig = { uir: '<?= UIR ?>', surveyId: <?= (int)$SurveyId ?>, survey: <?= json_encode($Survey) ?> }`; markup: `.rp-root > .rp-header` (inline-editable title, status pill, actions: Settings, Preview, Open/Close, Results, Copy link, Help) then `.svb-layout` = `.svb-canvas` (page cards, each ending in a `.svb-add-element` button) + `.svb-settings` (drawer, hidden). No side inspector, no palette, no floating toolbar. Loads scripts in order: SortableJS, marked, DOMPurify, `survey-render.js`, `survey-build.js`.
- [ ] **Step 2: `survey-build.js`** — single IIFE; state = `SvConfig.survey`. The canvas is the editor (spec §7 Builder): `renderCanvas()` draws pages as `.svb-page` cards (inline title/description inputs, "+ Add page" divider); each question is a `.svb-item` that renders via `SvRender.question(q, undefined, 'preview')` when NOT selected and via `renderEditCard(q)` when selected. `renderEditCard` produces, per type, the inline editors from a `EDITORS` table keyed by type: borderless prompt textarea (auto-grow) + "Add help text" → Markdown textarea with toolbar and preview; option rows (`.svb-opt`: control glyph, drag handle, label input, × remove) + "+ Add option" / "+ Add 'Other'" links (Enter commits and appends the next row; Backspace on an empty label removes); matrix grid with "+ Row" / "+ Column" and per-column weight inputs; rating end controls (count 3–10, min/max labels, star/number toggle); NPS end labels; text/number/date inline limit line; section heading/body; image Replace/Remove + caption. Card footer toolbar: type `<select>`, Required toggle, Duplicate, Delete, ⋯ menu (show-if pickers limited to earlier `SHOW_IF_SOURCES` questions, randomize, min/max select, require-all-rows, rank-all, Add image). `select(id)` re-renders only the previously selected and newly selected cards. `save(action, fields)` debounced 400 ms with a `.svb-savestate` pill; label edits batch into one `option_set` per question; prompt/help/required/settings into `question_update`. Sortable on each page's question list (`group:'questions'`, `handle:'.svb-handle'`, `onEnd` → `question_reorder` / `question_move`) and on option rows (→ `option_set`). "+ Add Element" button below each page's last card (and a small between-cards variant when a card is selected) → `question_add` with `Type=single` → the new card opens selected with the prompt focused; the footer Type `<select>` lists all 12 question types + Section + Image and retypes in place via `question_update` (`Type` is accepted by `question_update` only while the survey is unlocked — add that field to the domain/AJAX if missing, preserving prompt and, where applicable, options). `image_upload` via hidden `<input type=file>` + FormData. Open/Close via `set_status` with an inline non-native confirm strip. `locked` → add/remove/reorder/type controls disabled with `data-tip` "Locked: this survey has been opened"; labels and prompts stay editable. Settings drawer (title, description, welcome/thanks Markdown + image, audience, schedule, data gate, banner, progress, resume, accent) → `update`.
- [ ] **Step 3: `survey-build.css`** — layout only (`.svb-*`), reuse `.sv-*` and `.rp-*` for everything else; dark mode block; 900 px and 420 px breakpoints.
- [ ] **Step 4: Verify** with Claude-in-Chrome on the Task 6 survey's **clone** (unlocked): add one of each type, set settings, reorder across pages, set a show-if, upload an image, switch to Settings and set welcome/thanks + banner; reload and confirm persistence via `SurveyAjax/get`; console has zero errors; at 390 px the sheet opens and closes.
- [ ] **Step 5: Commit** — `git add orkui/template/default/Survey_build.tpl orkui/template/default/style/survey-build.css orkui/template/default/script/survey-build.js && git commit -m "Enhancement: Survey — builder"`

### Task 11: Runner (take page)

**Model:** opus · effort medium — parallel with Tasks 9, 10, 12, 13.

**Files:**
- Create: `orkui/template/default/Survey_take.tpl`, `orkui/template/default/script/survey-take.js`
- Reference: spec §7 Runner, §2 consent copy (verbatim), §6 `definition`/`draft_save`/`submit`.

- [ ] **Step 1: Template** — links only `survey.css`; emits `SvConfig = { uir, surveyId, preview: <?= $IsPreview ? 'true':'false' ?>, canManage }`; markup: `.sv-root` with `.sv-header` (title, `.sv-progress`), `#sv-stage` (JS-rendered), preview strip when `$IsPreview`.
- [ ] **Step 2: `survey-take.js`** — IIFE; on load POST `definition` (with `Preview`); if `!eligible` render `.sv-notice` with the mapped reason text; else build a screen list: welcome (if `welcome_html`) → visible pages (re-evaluated after each answer with `SvTypesJs.isShown` — a JS port of `SurveyTypes::isShown` for question and page level, kept inside this file) → consent card (if `data_gate_enabled`) → submit → thank-you (`thanks_html`). Page render: `SvRender.question(q, answers[q.question_id], 'take')` per visible question; `Back`/`Next`; on Next: `SvRender.read` each, client-side required check with `SvRender.setError` + focus first error + `aria-live` region; `draft_save` when `allow_resume`; `sessionStorage` mirror of answers so a reload mid-page restores instantly, then the server draft wins. Duration = `Date.now() - startedAt` (from draft `started_at` when resumed). On submit `{status:1, errors}` → jump to the page containing the first error and show messages; `status 5` → notice "Your session expired — log in again to continue"; `status 0` → thank-you screen, clear sessionStorage. Preview mode: "Submit as test" checkbox on the consent card sets `IsTest=1`.
- [ ] **Step 3: Verify** with Claude-in-Chrome: take the Task 6 clone end-to-end at desktop and 390 px; reload mid-survey and confirm resume; validation on a required question; each consent path once (three test players are not available in the browser — use `IsTest` for two of them and confirm rows in DB); dark mode; no horizontal scroll (`scrollWidth <= innerWidth`) at 360 px.
- [ ] **Step 4: Commit** — `git add orkui/template/default/Survey_take.tpl orkui/template/default/script/survey-take.js && git commit -m "Enhancement: Survey — mobile-first runner with data gate"`

### Task 12: Results page

**Model:** opus · effort medium — parallel with Tasks 9–11, 13. Load the `dataviz` skill before writing chart code.

**Files:**
- Create: `orkui/template/default/Survey_results.tpl`, `orkui/template/default/style/survey-results.css`, `orkui/template/default/script/survey-results.js`
- Reference: spec §7 Results table; `Reports_release_utilization.tpl:469-540` (CDN Highcharts + the most complete dark-mode helper); `Reports_attendance.tpl:194-260` (shell + DataTables includes); `SurveyAjax/results`, `/rows`; `Survey/export`.

- [ ] **Step 1: Template** — `.rp-root` shell: header (title, scope chip, actions: Export CSV, Builder, Preview), context strip, `.rp-stats-row` (responses, completion, median time, consent split), `.rp-body` = `.rp-sidebar` filter card (kingdom multi-select from `$Kingdoms`, consent select, date from/to, cross-tab select of choice questions, "include test responses" checkbox, Apply) + `.rp-main` with `#svr-cards` and below it `#svr-rows` (`table.dataTable`). Scripts: `https://code.highcharts.com/11.4.8/highcharts.js` **after** `orkui.js` (already in the theme), DataTables as in `Reports_attendance.tpl:215-217`, `survey-results.js`. Emits `SvConfig = { uir, surveyId, questions }`.
- [ ] **Step 2: `survey-results.js`** — IIFE; `SV_COLORS` palette; `svIsDark()`, `svChartTheme()` (transparent background, axis/grid/legend/tooltip colours as in `Reports_release_utilization.tpl:471-540`); `load()` POSTs `results` with the filter JSON, renders one `.rp-chart-card` per question with the §7 chart via a `CHART_BY_TYPE` table (`bar`, `column`, `stackedBar`, `nps`, `matrix`, `ranking`, `histogram`, `line`, `text`), the `n` badge, `other_texts` collapsible, and the "N anonymous responses are excluded by the kingdom filter" note from `summary.excluded_anonymous`; cross-tab renders stacked series per cross-tab option; charts are created only after their container is visible; `window.matchMedia` / a `MutationObserver` on `html[data-theme]` redraws on theme change. Rows: server-side DataTable paging through `rows` (`Offset`/`Limit=100`), persona cell is `<a href="Player/profile/{id}">` only when `mundane_id` present, else `—`. Export button builds `Survey/export/{id}?filters=<encoded JSON>`.
- [ ] **Step 3: `survey-results.css`** — `.svr-*` only: card grid (`.rp-charts-row` override to `row` + `wrap`), NPS score tile, text response list; dark mode.
- [ ] **Step 4: Verify** with Claude-in-Chrome on the Task 6 survey (which has ≥ 3 responses after Task 11): every chart type present renders without Highcharts error #13, tooltips readable in dark mode, filter by kingdom shows the exclusion note, cross-tab switches series, rows table pages, CSV downloads with the filtered count.
- [ ] **Step 5: Commit** — `git add orkui/template/default/Survey_results.tpl orkui/template/default/style/survey-results.css orkui/template/default/script/survey-results.js && git commit -m "Enhancement: Survey — results with Highcharts, row-level data and CSV"`

### Task 13: Entry points, My Amtgard widget, site banner

**Model:** sonnet · effort medium — parallel with Tasks 9–12 (touches only files no other Phase 2 task touches).

**Files:**
- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl:928-935` (add a "Surveys" `.kn-report-group` inside the `$CanManageKingdom` gate: `Manage Surveys` → `Survey/index/Kingdom/<?= $kingdom_id ?>`), `Parknew_index.tpl:1290-1305` (same with `Park/<?= $park_id ?>`), `revised-frontend/Admin_index.tpl:283-295` (add `<li>` `Survey/index` "Surveys — Build and analyse player surveys", icon `fa-poll`)
- Modify: `orkui/template/revised-frontend/Playernew_index.tpl:1557` (insert `<div id="pna-surveys-body"></div>` as the first child of `.pna-sidebar`) and the block at `:7451` (fetch `SurveyAjax/available`, render a `.pna-card` "Available Surveys" with `.pna-feed-row`s: `.pna-feed-label` title, `.pna-feed-sub` scope label + "closes Mon D", right-aligned link "Continue"/"Take survey" → `Survey/take/{id}`; render nothing when empty)
- Modify: `system/lib/system/class.Controller.php:121` (after the What's New block: `$this->data['SurveyBanner'] = null; if ($_uid > 0 && substr(get_class($this), -4) !== 'Ajax') { $this->load_model('Survey'); $this->data['SurveyBanner'] = $this->Survey->banner_for($_uid); }`)
- Modify: `orkui/template/default/default.theme:185` (after `#ork-env-banner`: `#ork-survey-banner` markup gated on `!empty($SurveyBanner)`: `<i class="fas fa-poll">` + `<?= htmlspecialchars($SurveyBanner['title']) ?>` + description + `<a class="svb-cta" href="<?= UIR ?>Survey/take/<?= (int)$SurveyBanner['survey_id'] ?>">Take survey</a>` + `<button class="svb-close" aria-label="Dismiss">×</button>` whose click POSTs `SurveyAjax/dismiss_banner` and removes the element) and the theme `<style>` block (`.svb-*` rules: flex row, wraps at 600 px, light + `html[data-theme="dark"]` colours, 44 px tap targets)

- [ ] **Step 1: Normalize-first check** on each of the six files (`awk` tab count), fixer if needed.
- [ ] **Step 2: Apply the six edits** with `python3` replace scripts that print `found: True`.
- [ ] **Step 3: Verify** — `php -l` on `class.Controller.php`; curl the profile page `Player/profile/46193` and grep `pna-surveys-body` → 1; set `show_banner=1` on the Task 6 survey (`SurveyAjax/update`) and curl `Kingdom/index/17` → contains `ork-survey-banner`; `dismiss_banner` → next curl does not; an AJAX route (`SurveyAjax/available`) never includes the banner markup; kingdom 17 profile Admin Tasks contains `Survey/index/Kingdom/17`.
- [ ] **Step 4: Commit** — `git add orkui/template/revised-frontend/Kingdomnew_index.tpl orkui/template/revised-frontend/Parknew_index.tpl orkui/template/revised-frontend/Admin_index.tpl orkui/template/revised-frontend/Playernew_index.tpl system/lib/system/class.Controller.php orkui/template/default/default.theme && git commit -m "Enhancement: Survey — entry points, Available Surveys widget and site banner"`

### Task 14: Serial browser verification of Phase 2

**Model:** opus · effort medium (verifier, fixes nothing) · fixer opus · effort medium

- [ ] **Step 1: Verifier** walks spec §10 criteria 2, 4, 5, 8, 9, 10 in Claude-in-Chrome, **serially** (one browser session): list → builder → runner (desktop, 390 px, 360 px) → results (light, dark) → widget → banner. Records each failure with page, viewport, theme, and console output. Also runs the curl matrix from Task 6 once more.
- [ ] **Step 2: Fixer** applies every finding, re-verifies the affected surface, commits `Enhancement: Survey — browser verification fixes`.

---

## Phase 3 — Review and close-out (workflow `survey-3-review.js`)

### Task 15: Multi-lens review, adversarial verification, fixes, final verification

**Model:** reviewers opus · effort medium; refuters opus · effort low (two per finding); fixers opus · effort medium; final verifier opus · effort high

- [ ] **Step 1: Reviewers (parallel, structured findings)** over `git diff master...HEAD`: (a) security + consent leakage — IDOR on every AJAX action, scope from request vs row, participation/response joinability, draft privacy, `is_test` abuse, CSV injection, image upload; (b) layering + CSS reuse — forbidden patterns under `orkui/`, duplicated rules across `survey*.css` and `reports.css`, hardcoded colours where a token exists; (c) mobile — 360/390 px, tap targets, keyboards (`inputmode`), matrix collapse, bottom sheet; (d) dark mode + print — every new surface incl. Highcharts and the banner; (e) correctness — show-if edge cases, structure lock gaps, `response_count` drift, timezone of `submitted_at` truncation, ranking/matrix aggregation, cross-tab with anonymous rows; (f) accessibility — labels, `aria-live`, focus order, keyboard-only ranking.
- [ ] **Step 2: Dedup** by file+line (plain code) then **refute** each finding with two independent low-effort agents; keep findings ≥ 1 non-refuted with evidence.
- [ ] **Step 3: Fix** confirmed findings grouped by file ownership (domain / controllers / builder / runner / results / entry points) in parallel; each fixer commits its group.
- [ ] **Step 4: Final verifier** (fixes nothing): `bin/run-unit-tests.sh --filter Survey` green, `php -l` all, layering grep clean, curl matrix, serial browser smoke of the four pages in both themes, `git status --porcelain` shows nothing unexpected, `class.Authorization.php` unstaged, no push happened. Returns a ≤ 200-word close-out naming anything that would embarrass the owner in review.

### Task 16: Release note and PR

**Model:** sonnet · effort low

**Files:**
- Modify: `orkui/whats_new_content.php` (new release entry at the top: version bump, date, items "Surveys" with a two-sentence body; do **not** change existing entries)

- [ ] **Step 1: Add the release entry**, bump `WHATS_NEW_VERSION` and `ORK_VERSION` per the file's own comments.
- [ ] **Step 2: Commit** — `git add orkui/whats_new_content.php && git commit -m "Enhancement: Survey — release note"`
- [ ] **Step 3: Open the PR** to `baltinerdist/ORK3-tobias` (memory: default fork destination) with `gh pr create --title "Enhancement: Survey module" --body "$(cat <<'EOF' … EOF)"` — summary of spec §Goal, the data-gate behaviour, migration + classification note, test plan (unit, integration, curl, browser matrix), and the required footer. **Only after the user says to push.**

## Phase 4 — Density pass (workflow `survey-4-density.js`)

### Task 17: Builder and survey chrome density

**Model:** opus · effort medium (implementer, alone, browser allowed) · verifier opus · effort medium · fixer opus · effort medium

**Files:**
- Modify: `orkui/template/default/style/survey-build.css`, `orkui/template/default/script/survey-build.js`, `orkui/template/default/Survey_build.tpl` (Reports layout: `.rp-body` = settings `.rp-sidebar` + canvas `.rp-main`); and only where a target in spec §7 "Density" names them: `survey.css` (desktop type scale), `Survey_index.tpl`, `Survey_results.tpl`, `survey-results.css`.
- Reference: spec §7 Builder → **Density** bullet (the targets); the owner's reference is Google Forms' question card (drag handle top-centre, filled prompt field with the type picker top-right, radio glyph + label + × rows, "Add option or add 'Other'", slim footer with duplicate/delete/divider/Required switch/⋯). Take inspiration, do not copy.

- [x] **Step 1: Measure before** — with Claude-in-Chrome on `Survey/build/<unlocked clone of 999012>` at 1280 px, record for the selected card: card padding, prompt field height, type picker height and position, option row height, footer height, and the rendered pixel size of the type select and required control. Paste the numbers.
- [x] **Step 2: Restructure the selected-card markup** in `survey-build.js` `renderEditCard`: move the Type `<select>` into a `.svb-card-top` row beside the prompt field (icon + label + caret, 34 px), add the centred `.svb-handle` above it, make the prompt a filled borderless `textarea`, render option rows as `.svb-opt` at 36 px (glyph, label input, ×), and the footer as `.svb-card-foot` (duplicate, delete, divider, Required `.svb-switch`, ⋯). Keep every existing action, keyboard behaviour and autosave path; only the layout changes.
- [x] **Step 2b: Reports layout with a settings sidebar** — restructure `Survey_build.tpl` to `.rp-root > .rp-header + .rp-body > .rp-sidebar + .rp-main`; move every field from the settings drawer into sidebar `.rp-filter-card` sections per spec §7 (Basics, Screens, Audience, Schedule, Privacy, Promotion, Experience, then About This Tool); make each `.rp-filter-card-header` a `<button aria-expanded>` that toggles its body, persisting per-survey state in `localStorage` (`sv-build-sections-{id}`), Basics open by default on desktop, all collapsed ≤ 900 px; delete the drawer markup, its open/close JS and its CSS; keep the canvas as `.rp-main` content at max 860 px. Reuse `reports.css` for the shell and sidebar — no re-declared `.rp-*` rules.
- [x] **Step 3: Rewrite the sizing rules** in `survey-build.css` to the §7 Density targets (padding, heights, the app's 11/12/13/14/20 px type scale from `tokens.css`/`reports.css`, 30–32 px controls in the drawer and header), delete rules that only existed to support the old large footer controls, and keep dark mode and the 900/420 px breakpoints.
- [x] **Step 4: Retype every survey stylesheet** (`survey.css`, `survey-build.css`, `survey-results.css`, inline styles in the four `Survey_*.tpl`) onto the app scale: 13 px base, 12 px small, 11 px uppercase section labels, 14 px prompts/card titles, 20 px page titles; delete the 15/16/22/26 px sizes; keep 16 px only on real form controls at ≤ 700 px in the runner. Trim list and results headers (30 px buttons, 12 px labels) without changing touch-width tap targets.
- [x] **Step 5: Measure after** — repeat Step 1 and paste both columns side by side; every target in §7 Density must be met in both themes; console clean; `node --check` on the JS.
- [x] **Step 6: Commit** — `git add` the touched files explicitly, `git commit -m "Enhancement: Survey — compact builder chrome"`.

---

## Plan Self-Review Notes

**Spec coverage:** §1 → T3 (auth, lifecycle, lock), T4 (eligibility); §2 → T4 + T11 (copy) + T12 (masking); §3 → T1; §4 → T2 (+ T8 rendering, T12 charts); §5 → T2–T6, T8; §6 → T6; §7 → T9–T13; §8 → T4 (transaction), T6, T11, T12; §9 → T2, T4, T5, T7, T14; §10 → T7, T14, T15; §11 → all; §12 → T12 (Highcharts order), T1 (APCu), T13 (banner query). Nothing in the spec lacks a task.

**Type consistency:** domain envelopes use `Status`/`Error` + PascalCase payload keys (`SurveyId`, `Question`, `Options`) and the AJAX layer emits snake_case JSON per §6; the model facade is where the rename happens (documented in T6). `SurveyTypes::isShown` and the runner's JS port must agree — T15 lens (e) checks it explicitly.

**Known weak points (deliberate):** T10 is the largest single agent task (builder JS); it runs at opus/high and gets a dedicated browser verification in T14. Browser verification is serial by necessity (single Chrome session) and is the slowest step of Phase 2. The integration test in T7 depends on `bin/ork-db deploy-sandbox --force-refresh` having run in T1.

**Risk / rollback:** the migration only creates tables; reverting the branch leaves ten empty-or-orphaned `ork_survey_*` tables that can be dropped in one statement. No existing table is altered. The only edits to shared files are additive (a widget div and fetch, an Admin Tasks group, a banner block, one base-controller lookup guarded by `$_uid > 0`).
