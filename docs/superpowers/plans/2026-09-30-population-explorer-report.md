# Population Explorer Report Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A scoped officer report, "Population Explorer", with an AND/OR nested filter builder (`Criteria | Operand | Value`), selectable output columns, DataTables results, `.xlsx` export and a shareable `&pe=` link.

**Architecture:** A new domain class `PopulationExplorer` (`system/lib/ork3/`) owns a registry of whitelisted criteria/columns, validates and normalizes a filter tree, compiles it to one SQL query (correlated subqueries / `EXISTS`), enforces scope server-side, and runs/exports it. `Model_Reports` is the thin membrane; `Controller_Reports` serves the page, a JSON run endpoint and an export download; a `.rp-*` template plus `populationexplorer.js` render the inline builder.

**Tech Stack:** PHP 8 (legacy ORK3 MVC, plain-PHP `.tpl`), MariaDB, PHPUnit (`vendor/bin/phpunit -c phpunit.xml.dist`), DataTables 1.13.8, Flatpickr, `system/lib/vendor/SimpleXlsx.php`.

**Spec:** `docs/superpowers/specs/2026-09-30-population-explorer-report-design.md`

## Global Constraints

- SQL only in `system/lib/ork3/`; `orkui/model` is the only membrane to it. No SQL in controller/template.
- `mysql_real_escape_string()` is a NO-OP: never rely on it. Every value is `(int)`-cast, regex-validated (`^\d{4}-\d{2}-\d{2}$` + `checkdate`), or looked up from a registry/known-set before reaching SQL. User text never becomes SQL.
- Limits (exact): tree depth ≤ 6, leaves ≤ 40, `IN`-list ≤ 100 items, result cap 5000 rows, share-link payload ≤ 8192 bytes, "last N months" param integer 1–60.
- Scope is derived server-side and AND-ed outside the user tree: Kingdom = `m.kingdom_id IN (Ork3::$Lib->kingdom->GetStatsKingdomIds(id))`; Park = `m.park_id = id`. Auth: global admin, or kingdom `AUTH_EDIT`, or park `AUTH_CREATE` (same as `Report::_authorizeKingdomParkReportScope`).
- No real-name or email columns, ever, in v1.
- Export is `.xlsx` only (pure-PHP `SimpleXlsx`; do NOT use `ZipArchive`; the writer lives under a gitignored `vendor` dir name, already tracked).
- `.tpl` files are plain PHP, not Smarty. The template goes in `orkui/template/default/Reports_populationexplorer.tpl` (sibling of all `Reports_*.tpl`); the ONLY new entry points are links in `revised-frontend/Kingdomnew_index.tpl` and `revised-frontend/Parknew_index.tpl`.
- Tooltips use `data-tip` (never `title`). No native `alert/confirm/prompt`. No jQuery UI autocomplete. Dates shown human-readable (Flatpickr `altInput`). Dark mode via `html[data-theme="dark"]`, verified on computed styles; phone width verified with the iframe harness.
- DB conventions if touched: `Clear()` before raw Execute/DataSet; `DataSet()` needs `->Next()`; no migration is needed for this feature.
- Git: **never stage `system/lib/ork3/class.Authorization.php`**; never `git add -A`; always `git diff --cached` before committing; stage explicit paths only. Work on branch `population-explorer`, not `master`.
- Commit messages end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## Review Focus

Failure modes the spec implies that no single task's happy-path tests would catch; each is pinned by a named test in the owning task.

1. Empty tree → all players in scope (Task 2 `testEmptyTreeCompilesToTautology`, Task 3 integration).
2. Empty nested group inside an `OR` must NOT turn the OR into "always true" (Task 1 normalizer drops empty groups; Task 2 `testEmptyChildGroupIsDroppedFromOr`).
3. Player who never signed in and `Last Sign-In Date ≠ x` / `Last Class IS NOT x` → NULL means "no match", documented (Task 2 `testNullSemanticsForNegatedComparisons`, Task 3 integration).
4. Hostile strings (`'; DROP TABLE`, `1 OR 1=1`, unicode, 10 KB blobs) in every value slot → rejected or inert (Task 1 `testInjectionAttemptsAreRejected`, Task 2 asserts the compiled SQL never contains user text).
5. A park officer tampering with `ScopeId`/`q` link to reach another park/kingdom → authorization error, never widened (Task 3 `testParkOfficerCannotReadOtherPark`).
6. >5000 matches → `Truncated=true` with true `Total` (Task 3 `testResultCapAndTotal`).
7. Revoked / stripped awards must not count as held peerage (Task 3 `testRevokedAwardsDoNotCountAsHeld`).

---

## File Structure

| File | Responsibility |
|---|---|
| `system/lib/ork3/class.PopulationExplorer.php` (create) | Registry, normalization/validation, compilation, scoped execution, export builder. |
| `orkui/model/model.Reports.php` (modify) | `population_registry`, `population_run`, `population_export` pass-throughs via `new APIModel('PopulationExplorer')`. |
| `orkui/controller/controller.Reports.php` (modify) | `population_explorer`, `population_explorer_json`, `population_explorer_export`. |
| `orkui/template/default/Reports_populationexplorer.tpl` (create) | `.rp-*` page shell; embeds registry JSON + initial state. |
| `orkui/template/default/script/populationexplorer.js` (create) | Builder state, rendering, run/export/copy-link. |
| `orkui/template/default/style/populationexplorer.css` (create) | `.pe-*` builder-only styles incl. dark mode + mobile. |
| `orkui/template/revised-frontend/Kingdomnew_index.tpl`, `Parknew_index.tpl` (modify) | One report link each. |
| `tests/Unit/PopulationExplorerTest.php` (create) | Normalizer + compiler tests (pure). |
| `tests/Integration/PopulationExplorerRunTest.php` (create) | Scoped execution, auth, parity, cap, export. |

Interface contract (shared by tasks 3–6):

```
Run request  (PHP array):  ['Token'=>string,'ScopeType'=>'Kingdom'|'Park','ScopeId'=>int,
                            'Tree'=>array,'Columns'=>string[]]
Run response (PHP array):  ['Status'=>Success()|error, 'Columns'=>[['id','label','type']],
                            'Rows'=>[['MundaneId'=>int, <colId>=>scalar|null, ...]],
                            'Total'=>int,'Truncated'=>bool,'ElapsedMs'=>int]
Error:  Status = InvalidParameter(msg) with extra key 'RulePath' => int[] (index path to bad rule) when rule-level.
Tree:   ['op'=>'AND'|'OR','children'=>[leaf|group]]   leaf = ['c'=>id,'o'=>operand,'v'=>value,'p'=>?int]
Registry (public, JSON-safe): ['criteria'=>[id=>['label','group','type','operands','param','set'?,'peerage'?]],
                               'columns'=>[id=>['label','group','type','default'?]],
                               'options'=>['class'=>[[id,name]],'award'=>[[id,name]],'order'=>[...],'park'=>[...],'kingdom'=>[...]]]
```

Operand wire tokens: `eq ne gt gte lt lte between` (date/number); `is is_not in not_in` (enum_set; `is` also bool); `has_any has_all has_none is` (peerage_set; `is` takes `yes|no`).

---

### Task 1: Registry and tree normalizer/validator

**Files:**
- Create: `system/lib/ork3/class.PopulationExplorer.php`
- Test: `tests/Unit/PopulationExplorerTest.php`

**Interfaces:**
- Consumes: `Ork3` base class (`$this->db`), `DB_PREFIX`, `Success()`, `InvalidParameter()`.
- Produces:
  - `PopulationExplorer::Registry(): array` — full registry (criteria with `sql` closures, columns with `sql` expr), keyed by id.
  - `PopulationExplorer::NormalizeTree(array $tree, ?array $known = null): array` — returns `['ok'=>true,'tree'=>normalizedTree]` or `['ok'=>false,'error'=>string,'path'=>int[]]`. `$known = ['class'=>int[],'award'=>int[],'peerage'=>['Knight'=>int[],...]]`; when null it is loaded from the DB by `LoadKnown()`.
  - `PopulationExplorer::LoadKnown(): array`.

Criteria ids (exactly these): `last_signin`, `player_since`, `signins_last_n_months`, `total_signins`, `last_class`, `classes_last_n_months`, `home_kingdom`, `home_park`, `last_signin_park`, `dues_paid`, `dues_through`, `waivered`, `active`, `suspended`, `banned`, `knighthood`, `masterhood`, `paragon`, `lesser_peerage`, `has_award`, `award_count`, `award_date_any`, `reeve_qualified`, `corpora_qualified`.
Column ids (exactly these): `persona`, `home_park`, `home_kingdom`, `last_signin`, `last_signin_park`, `last_class`, `signins_6m`, `total_signins`, `player_since`, `dues_paid`, `dues_through`, `waivered`, `active`, `knighthoods`, `masterhoods`, `paragons`, `award_count`, `reeve_qualified`, `corpora_qualified`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/PopulationExplorerTest.php`. Guard: `if (!ork3_test_db_available()) markTestSkipped` only if `new PopulationExplorer()` needs the DB (check `Ork3::__construct`; if it does not, drop the guard). Pass an explicit `$known` so no DB read is needed.

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PopulationExplorerTest extends TestCase
{
    private PopulationExplorer $pe;
    private array $known;

    protected function setUp(): void
    {
        $this->pe = new PopulationExplorer();
        $this->known = [
            'class'   => [1, 2, 7, 12],
            'award'   => [17, 18, 19, 20, 1, 2],
            'peerage' => ['Knight' => [17, 18, 19, 20], 'Master' => [1, 2], 'Paragon' => [], 'Squire' => [], 'Page' => [], 'Man-At-Arms' => []],
        ];
    }

    private function leaf(string $c, string $o, $v, ?int $p = null): array
    {
        $l = ['c' => $c, 'o' => $o, 'v' => $v];
        if ($p !== null) { $l['p'] = $p; }
        return $l;
    }

    private function norm(array $tree): array
    {
        return $this->pe->NormalizeTree($tree, $this->known);
    }

    public function testValidExampleQueryNormalizes(): void
    {
        $tree = ['op' => 'AND', 'children' => [
            $this->leaf('last_signin', 'gte', '2025-01-01'),
            $this->leaf('knighthood', 'has_any', ['17', '20', '18']),
            $this->leaf('dues_paid', 'is', 'yes'),
            ['op' => 'OR', 'children' => [
                $this->leaf('signins_last_n_months', 'gt', '5', 6),
                $this->leaf('last_class', 'is_not', '7'),
            ]],
        ]];
        $r = $this->norm($tree);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame(1, $r['tree']['children'][2]['v']);               // yes -> 1
        $this->assertSame([17, 20, 18], $r['tree']['children'][1]['v']);    // ints
        $this->assertSame(5, $r['tree']['children'][3]['children'][0]['v']);
        $this->assertSame(6, $r['tree']['children'][3]['children'][0]['p']);
    }

    public function testEmptyChildGroupIsDroppedFromOr(): void
    {
        $r = $this->norm(['op' => 'OR', 'children' => [
            $this->leaf('active', 'is', 'yes'),
            ['op' => 'AND', 'children' => []],
        ]]);
        $this->assertTrue($r['ok']);
        $this->assertCount(1, $r['tree']['children']);
    }

    public function testEmptyRootIsAllowed(): void
    {
        $r = $this->norm(['op' => 'AND', 'children' => []]);
        $this->assertTrue($r['ok']);
        $this->assertSame([], $r['tree']['children']);
    }

    /** @dataProvider badRules */
    public function testRejections(array $leaf, string $needle): void
    {
        $r = $this->norm(['op' => 'AND', 'children' => [$leaf]]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsStringIgnoringCase($needle, $r['error']);
        $this->assertSame([0], $r['path']);
    }

    public static function badRules(): array
    {
        return [
            'unknown criterion'        => [['c' => 'nope', 'o' => 'is', 'v' => 'yes'], 'criterion'],
            'operand wrong for type'   => [['c' => 'last_signin', 'o' => 'in', 'v' => [1]], 'operand'],
            'bad date format'          => [['c' => 'last_signin', 'o' => 'gte', 'v' => '01/02/2025'], 'date'],
            'impossible date'          => [['c' => 'last_signin', 'o' => 'gte', 'v' => '2025-02-30'], 'date'],
            'between wrong order'      => [['c' => 'last_signin', 'o' => 'between', 'v' => ['2025-05-01', '2025-01-01']], 'between'],
            'between not pair'         => [['c' => 'total_signins', 'o' => 'between', 'v' => [1]], 'between'],
            'non-numeric number'       => [['c' => 'total_signins', 'o' => 'gt', 'v' => 'abc'], 'number'],
            'empty IN list'            => [['c' => 'home_park', 'o' => 'in', 'v' => []], 'empty'],
            'unknown class id'         => [['c' => 'last_class', 'o' => 'is', 'v' => 9999], 'class'],
            'missing N param'          => [['c' => 'signins_last_n_months', 'o' => 'gt', 'v' => 1], 'param'],
            'N param out of range'     => [['c' => 'signins_last_n_months', 'o' => 'gt', 'v' => 1, 'p' => 61], 'param'],
            'bad bool'                 => [['c' => 'dues_paid', 'o' => 'is', 'v' => 'maybe'], 'yes'],
            'peerage id not a knight'  => [['c' => 'knighthood', 'o' => 'has_any', 'v' => [1]], 'knighthood'],
        ];
    }

    public function testInjectionAttemptsAreRejected(): void
    {
        $evil = ["1; DROP TABLE ork_mundane", "' OR '1'='1", "1 OR 1=1", str_repeat('A', 10240), "2025-01-01' --", "\0", "１２３"];
        foreach (['last_signin' => 'gte', 'total_signins' => 'gt', 'home_park' => 'is', 'last_class' => 'is', 'dues_paid' => 'is'] as $c => $o) {
            foreach ($evil as $v) {
                $r = $this->norm(['op' => 'AND', 'children' => [['c' => $c, 'o' => $o, 'v' => $v]]]);
                $this->assertFalse($r['ok'], "$c accepted: " . substr((string)$v, 0, 20));
            }
        }
    }

    public function testCaps(): void
    {
        // depth 7
        $t = $this->leaf('active', 'is', 'yes');
        for ($i = 0; $i < 7; $i++) { $t = ['op' => 'AND', 'children' => [$t]]; }
        $this->assertFalse($this->norm($t)['ok']);

        // 41 leaves
        $leaves = [];
        for ($i = 0; $i < 41; $i++) { $leaves[] = $this->leaf('active', 'is', 'yes'); }
        $this->assertFalse($this->norm(['op' => 'AND', 'children' => $leaves])['ok']);

        // 101-item IN list
        $this->assertFalse($this->norm(['op' => 'AND', 'children' => [
            $this->leaf('home_park', 'in', range(1, 101)),
        ]])['ok']);
    }

    public function testBadOpAndShape(): void
    {
        $this->assertFalse($this->norm(['op' => 'XOR', 'children' => []])['ok']);
        $this->assertFalse($this->norm(['children' => 'x'])['ok']);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter PopulationExplorerTest`
Expected: FAIL (`Class "PopulationExplorer" not found`).

- [ ] **Step 3: Implement registry + normalizer**

Create `system/lib/ork3/class.PopulationExplorer.php` (`class PopulationExplorer extends Ork3`; startup auto-registers it as `Ork3::$Lib->populationexplorer`). Required content:

1. Constants: `MAX_DEPTH=6, MAX_LEAVES=40, MAX_LIST=100, MAX_ROWS=5000, MAX_LINK_BYTES=8192, MAX_MONTHS=60`.
2. Operand sets: `OPS_CMP=['eq','ne','gt','gte','lt','lte','between']`, `OPS_SET=['is','is_not','in','not_in']`, `OPS_PEER=['has_any','has_all','has_none','is']`, `OPS_BOOL=['is']`, and `SQL_CMP=['eq'=>'=','ne'=>'<>','gt'=>'>','gte'=>'>=','lt'=>'<','lte'=>'<=']`.
3. `Registry()` returning, for each criterion id above: `label`, `group` (`Activity|Location|Status|Peerage|Awards|Qualifications`), `type` (`date|number|enum_set|bool|peerage_set`), `operands`, `param` (true for `signins_last_n_months`, `classes_last_n_months`), `set` for enum_set (`class` for `last_class`/`classes_last_n_months`, `park` for `home_park`/`last_signin_park`, `kingdom` for `home_kingdom`, `award` for `has_award`), `peerage` list for peerage_set (`knighthood=>['Knight']`, `masterhood=>['Master']`, `paragon=>['Paragon']`, `lesser_peerage=>['Squire','Page','Man-At-Arms']`), plus an `sql` closure `function(string $op, $v, ?int $p, array $ctx): string` (implemented in Task 2; in this task set each `sql` to `null`). Also `columns` (ids above) each with `label`, `group`, `type`, `default` (true for persona, home_park, last_signin, dues_paid) and `sql` null for now. Keep criteria and columns in two private methods `_criteria()` and `_columns()`; `Registry()` returns `['criteria'=>..,'columns'=>..]`. Use a private helper to avoid repeating operand lists.
4. `NormalizeTree(array $tree, ?array $known = null): array`:
   - `$known ??= $this->LoadKnown();`
   - Recursive `_normNode($node, $depth, &$leafCount, array $path, $registry, $known)`; a node with `children` is a group (`op` must be `AND`|`OR`, `children` must be an array, depth > `MAX_DEPTH` → error "Filter is nested too deeply"); otherwise a leaf. Leaf counter > `MAX_LEAVES` → error. Non-root groups with zero normalized children are dropped. Errors return `['ok'=>false,'error'=>msg,'path'=>$path]` where `$path` is the list of child indices from the root.
   - Per-leaf checks, in order: criterion id exists; operand in that criterion's `operands`; `param` present and integer `1..MAX_MONTHS` when the criterion has `param` (error message contains the word "param"); value normalization by type:
     - `date`: string matching `/^\d{4}-\d{2}-\d{2}$/` and `checkdate`; `between` → exactly two valid dates with first ≤ second (error text contains "between"); error text for bad dates contains "date".
     - `number`: `is_int` or string matching `/^-?\d{1,9}$/` → `(int)`; error text contains "number"; `between` as above.
     - `enum_set`: `is`/`is_not` take one scalar positive int (accept a one-element list); `in`/`not_in` take a non-empty list of positive ints, unique, ≤ `MAX_LIST` (empty → error containing "empty"; too long → error); ints must match `/^\d{1,10}$/`; when `set` is `class` or `award`, each id must be in `$known[set]` (error text contains the set name, e.g. "class").
     - `bool`: accept `yes|no|true|false|1|0` (case-insensitive strings, ints, bools) → `1|0`; otherwise error containing "yes".
     - `peerage_set`: operand `is` → bool rule above; else non-empty unique int list ≤ `MAX_LIST`, each must be in the union of `$known['peerage'][$p]` for the criterion's peerages (error text contains the criterion label lowercased e.g. "knighthood").
   - Output leaf is exactly `['c'=>..,'o'=>..,'v'=>normalized]` plus `'p'=>int` when the criterion has `param`.
5. `LoadKnown()`: `['class'=>ids from ork_class, 'award'=>ids from ork_award, 'peerage'=>map peerage enum value => ids from ork_award]` using `$this->db->query(...)` / `->next()` with `DB_PREFIX` (read-only; no user input).

- [ ] **Step 4: Run tests to verify pass**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter PopulationExplorerTest`
Expected: PASS (all).

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.PopulationExplorer.php tests/Unit/PopulationExplorerTest.php
git diff --cached --stat
git commit -m "Add Population Explorer registry and filter-tree validator

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: SQL compilation (criteria fragments, columns, tree walk)

**Files:**
- Modify: `system/lib/ork3/class.PopulationExplorer.php`
- Test: `tests/Unit/PopulationExplorerTest.php` (append)

**Interfaces:**
- Consumes: Task 1 `Registry()`, `NormalizeTree()`.
- Produces:
  - `CompileTree(array $normalizedTree, array $ctx = []): string` — SQL boolean expression using alias `m` (= `ork_mundane`); `1=1` for an empty root.
  - `$ctx` keys: `'accountScope'` (SQL boolean over alias `ac` = `ork_account`, built by Task 3; unit tests pass `'ac.kingdom_id = 1'`).
  - `ColumnSelectSql(string $colId, array $ctx): string` — SQL expression (no alias).
  - All criteria/columns now have working `sql`.

Fragment rules (aliases: `m` mundane, `a` attendance, `w` awards, `ka` kingdomaward, `aw` award, `s` split, `ac` account; table names via `DB_PREFIX`):

| Criterion | Expression / fragment |
|---|---|
| `last_signin` | `(SELECT MAX(a.date) FROM att a WHERE a.mundane_id = m.mundane_id)` compared via `_cmp(date)` |
| `player_since` | `COALESCE(m.player_since_override, (SELECT MIN(a.date) FROM att a WHERE a.mundane_id = m.mundane_id))` `_cmp(date)` |
| `signins_last_n_months` | `(SELECT COUNT(*) FROM att a WHERE a.mundane_id = m.mundane_id AND a.date >= DATE_SUB(CURDATE(), INTERVAL N MONTH))`, N = `(int)$p`, `_cmp(number)` |
| `total_signins` | `(SELECT COUNT(*) FROM att a WHERE a.mundane_id = m.mundane_id)` `_cmp(number)` |
| `last_class` | `(SELECT a.class_id FROM att a WHERE a.mundane_id = m.mundane_id AND a.class_id > 0 ORDER BY a.date DESC, a.attendance_id DESC LIMIT 1)` `_set` |
| `classes_last_n_months` | `is`/`in` → `EXISTS (… a.class_id IN (ids) AND a.date >= DATE_SUB(CURDATE(), INTERVAL N MONTH))`; `is_not`/`not_in` → `NOT EXISTS (same)` |
| `home_kingdom` / `home_park` | `m.kingdom_id` / `m.park_id` `_set` |
| `last_signin_park` | `(SELECT a.park_id FROM att a WHERE a.mundane_id = m.mundane_id ORDER BY a.date DESC, a.attendance_id DESC LIMIT 1)` `_set` |
| `dues_paid` | `EXISTS (SELECT 1 FROM split s JOIN account ac ON ac.account_id = s.account_id WHERE s.src_mundane_id = m.mundane_id AND s.is_dues = 1 AND s.dues_through >= CURDATE() AND {accountScope})`; value 0 → `NOT EXISTS (...)` |
| `dues_through` | `(SELECT MAX(s.dues_through) FROM split s JOIN account ac … AND s.is_dues = 1 AND {accountScope})` `_cmp(date)` |
| `waivered` / `active` / `suspended` / `banned` | `m.waivered` / `m.active` / `m.suspended` / `m.penalty_box` `= 1|0` |
| `knighthood` / `masterhood` / `paragon` / `lesser_peerage` | held-award predicate, see below |
| `has_award` | held-award predicate with no peerage filter |
| `award_count` | `(SELECT COUNT(*) FROM <held-awards> )` `_cmp(number)` |
| `award_date_any` | `EXISTS (<held-awards> AND w.date <op> 'YYYY-MM-DD')` (date comparator incl. `between`) |
| `reeve_qualified` / `corpora_qualified` | `(m.reeve_qualified = 1 AND (m.reeve_qualified_until IS NULL OR m.reeve_qualified_until >= CURDATE()))` (same for corpora); value 0 → `NOT (…)`. **Verify against `Report::GetReeveQualified` / `GetCorporaQualified` and match their definition exactly** — if they differ, follow them and note it in the commit message. |

Held-award base (private helper `_heldAwardsFrom(string $extraWhere = '')`): `FROM {awards} w LEFT JOIN {kingdomaward} ka ON ka.kingdomaward_id = w.kingdomaward_id JOIN {award} aw ON aw.award_id = COALESCE(NULLIF(w.award_id, 0), ka.award_id) WHERE w.mundane_id = m.mundane_id AND w.revoked = 0 AND COALESCE(w.stripped_from, 0) = 0 {extraWhere}`. **Before relying on it, confirm against the existing Knights/Masters report (`Report::ClassMasters`, `knights_list` path) which awards count as held and which column identifies the award; if the join keys differ, use theirs and say so in the commit.**

Peerage/award set operands (award ids = `ork_award.award_id`, normalized ints): `has_any` → `EXISTS (SELECT 1 {held} AND aw.award_id IN (ids))`; `has_none` → `NOT EXISTS (same)`; `has_all` → `(SELECT COUNT(DISTINCT aw.award_id) {held} AND aw.award_id IN (ids)) = N` (N = list length); `is` yes/no → `EXISTS/NOT EXISTS (SELECT 1 {held} AND aw.peerage IN ('Knight'))` with the peerage literals taken from the criterion's `peerage` list (constants, never user input). For `has_award`, `is` is not offered (operands `has_any, has_all, has_none` only).

Comparison helpers:
```php
private function _cmp(string $expr, string $type, string $op, $v): string
{
    $q = function ($x) use ($type): string {
        if ($type === 'date') {
            if (!is_string($x) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $x)) { throw new InvalidArgumentException('bad date'); }
            return "'" . $x . "'";
        }
        return (string)(int)$x;
    };
    if ($op === 'between') { return "$expr BETWEEN " . $q($v[0]) . ' AND ' . $q($v[1]); }
    return "$expr " . self::SQL_CMP[$op] . ' ' . $q($v);
}

private function _set(string $expr, string $op, $v): string
{
    $ids = implode(',', array_map('intval', (array)$v));
    switch ($op) {
        case 'is':     return "$expr = " . (int)$v;
        case 'is_not': return "$expr <> " . (int)$v;
        case 'in':     return "$expr IN ($ids)";
        case 'not_in': return "$expr NOT IN ($ids)";
    }
    throw new InvalidArgumentException('bad operand');
}
```
NULL semantics: `ne`, `is_not`, `not_in` on a NULL expression evaluate to NULL (= no match). This is intentional (spec §3.2); do not coalesce.

Column select expressions (alias `m`, `k`=kingdom, `p`=park are always joined by Task 3's query): `persona` → `m.persona`; `home_park` → `p.name`; `home_kingdom` → `k.name`; `last_signin`/`player_since`/`signins_6m` (`N=6` count)/`total_signins`/`dues_through` as the fragments above; `last_signin_park` → `(SELECT p2.name FROM park p2 WHERE p2.park_id = (<last_signin_park expr>))`; `last_class` → `(SELECT c.name FROM class c WHERE c.class_id = (<last_class expr>))`; `dues_paid` → `CASE WHEN EXISTS(...) THEN 1 ELSE 0 END`; `waivered`/`active` → `m.waivered`/`m.active`; `knighthoods`/`masterhoods`/`paragons` → `(SELECT GROUP_CONCAT(DISTINCT aw.name ORDER BY aw.name SEPARATOR ', ') {held} AND aw.peerage = 'Knight'|'Master'|'Paragon')`; `award_count` → count of held; `reeve_qualified`/`corpora_qualified` → `CASE WHEN <fragment> THEN 1 ELSE 0 END`.

- [ ] **Step 1: Write the failing tests** (append to `PopulationExplorerTest`)

```php
    private function sql(array $tree, array $ctx = ['accountScope' => 'ac.kingdom_id = 1']): string
    {
        $n = $this->norm($tree);
        $this->assertTrue($n['ok'], $n['error'] ?? '');
        return $this->pe->CompileTree($n['tree'], $ctx);
    }

    public function testEmptyTreeCompilesToTautology(): void
    {
        $this->assertSame('1=1', $this->sql(['op' => 'AND', 'children' => []]));
    }

    public function testPrecedenceIsExplicit(): void
    {
        $s = $this->sql(['op' => 'AND', 'children' => [
            $this->leaf('active', 'is', 'yes'),
            ['op' => 'OR', 'children' => [
                $this->leaf('waivered', 'is', 'yes'),
                $this->leaf('suspended', 'is', 'no'),
            ]],
        ]]);
        $this->assertSame('((m.active = 1) AND ((m.waivered = 1) OR (m.suspended = 0)))', $s);
    }

    public function testEmptyChildGroupIsDroppedFromOrCompiled(): void
    {
        $s = $this->sql(['op' => 'OR', 'children' => [
            $this->leaf('active', 'is', 'yes'),
            ['op' => 'AND', 'children' => []],
        ]]);
        $this->assertSame('((m.active = 1))', $s);
    }

    public function testNullSemanticsForNegatedComparisons(): void
    {
        $s = $this->sql(['op' => 'AND', 'children' => [$this->leaf('last_class', 'is_not', 7)]]);
        $this->assertStringContainsString('<> 7', $s);
        $this->assertStringNotContainsString('COALESCE((SELECT a.class_id', $s); // no NULL-coalescing: NULL = no match
    }

    public function testBetweenAndInRender(): void
    {
        $s = $this->sql(['op' => 'AND', 'children' => [
            $this->leaf('last_signin', 'between', ['2025-01-01', '2025-06-30']),
            $this->leaf('home_park', 'in', [3, 4, 5]),
        ]]);
        $this->assertStringContainsString("BETWEEN '2025-01-01' AND '2025-06-30'", $s);
        $this->assertStringContainsString('m.park_id IN (3,4,5)', $s);
    }

    public function testNMonthsParamAndNotExists(): void
    {
        $s = $this->sql(['op' => 'AND', 'children' => [
            $this->leaf('signins_last_n_months', 'gt', 5, 6),
            $this->leaf('classes_last_n_months', 'not_in', [7], 3),
        ]]);
        $this->assertStringContainsString('INTERVAL 6 MONTH', $s);
        $this->assertStringContainsString('> 5', $s);
        $this->assertStringContainsString('NOT EXISTS', $s);
        $this->assertStringContainsString('INTERVAL 3 MONTH', $s);
    }

    public function testPeerageOperands(): void
    {
        $any  = $this->sql(['op' => 'AND', 'children' => [$this->leaf('knighthood', 'has_any', [17, 20])]]);
        $all  = $this->sql(['op' => 'AND', 'children' => [$this->leaf('knighthood', 'has_all', [17, 20])]]);
        $none = $this->sql(['op' => 'AND', 'children' => [$this->leaf('knighthood', 'has_none', [17])]]);
        $this->assertStringContainsString('aw.award_id IN (17,20)', $any);
        $this->assertStringContainsString('COUNT(DISTINCT aw.award_id)', $all);
        $this->assertStringContainsString(') = 2', $all);
        $this->assertStringContainsString('NOT EXISTS', $none);
        $this->assertStringContainsString('w.revoked = 0', $any);
        $this->assertStringContainsString('stripped_from', $any);
    }

    public function testDuesUsesAccountScope(): void
    {
        $s = $this->sql(['op' => 'AND', 'children' => [$this->leaf('dues_paid', 'is', 'yes')]], ['accountScope' => 'ac.park_id = 42']);
        $this->assertStringContainsString('ac.park_id = 42', $s);
        $this->assertStringContainsString('s.is_dues = 1', $s);
    }

    public function testCompiledSqlNeverContainsUserText(): void
    {
        $n = $this->norm(['op' => 'AND', 'children' => [$this->leaf('last_signin', 'gte', '2025-01-01')]]);
        $s = $this->pe->CompileTree($n['tree'], ['accountScope' => '1=1']);
        $this->assertDoesNotMatchRegularExpression('/DROP|--|;/', $s);
    }

    public function testEveryColumnCompiles(): void
    {
        $cols = array_keys($this->pe->Registry()['columns']);
        foreach ($cols as $c) {
            $expr = $this->pe->ColumnSelectSql($c, ['accountScope' => 'ac.kingdom_id = 1']);
            $this->assertNotSame('', $expr, $c);
        }
    }

    public function testEveryCriterionHasASqlBuilder(): void
    {
        foreach ($this->pe->Registry()['criteria'] as $id => $def) {
            $this->assertIsCallable($def['sql'], $id);
        }
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter PopulationExplorerTest`
Expected: new tests FAIL (`CompileTree` undefined / `sql` null).

- [ ] **Step 3: Implement** the helpers, every `sql` closure per the table, `CompileTree` (recursive; group → `'(' . implode(' OR '|' AND ', $parts) . ')'`; leaf → `'(' . sql . ')'`; empty root → `1=1`), and `ColumnSelectSql`. Table names via `DB_PREFIX . 'attendance'` etc. in a small private `_t(string $name): string`. Wrap fragment building in try/catch for `InvalidArgumentException` only inside `Run` (Task 3), not here.

- [ ] **Step 4: Run tests to verify pass**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter PopulationExplorerTest`
Expected: PASS (all Task 1 + Task 2 tests).

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.PopulationExplorer.php tests/Unit/PopulationExplorerTest.php
git diff --cached --stat
git commit -m "Compile Population Explorer filter trees to scoped SQL

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Scoped execution, authorization, registry options

**Files:**
- Modify: `system/lib/ork3/class.PopulationExplorer.php`
- Test: `tests/Integration/PopulationExplorerRunTest.php` (create)

**Interfaces:**
- Consumes: Tasks 1–2; `ReportsFixture` (`tests/Support/ReportsFixture.php`: `create()`, `firstKingdomId()`, `parkIdInKingdom($k)`, `secondParkIdInKingdom($k,$exclude)`, `createPlayer($parkId,$suffix)` → `['mundane_id','token',…]`, `insertScopedAuth($mundaneId,$parkId,$kingdomId,$role)`, `insertGlobalAdmin($mundaneId)`, `insertAttendance($mundaneId,$parkId,$kingdomId,$date)`, `insertDues($mundaneId,$parkId,$kingdomId)`, `insertLadderAward(...)`, `cleanup()`); `Ork3::$Lib->kingdom->GetStatsKingdomIds($id)`; `Ork3::$Lib->authorization`.
- Produces:
  - `Run(array $request): array` per the interface contract (top of this plan).
  - `PublicRegistry(string $scopeType, int $scopeId): array` — criteria/columns without `sql` plus `options` (class/award/order/park/kingdom lists scoped to the viewer).
  - `AuthorizeScope(string $token, string $scopeType, int $scopeId): ?array` — null when allowed, else an error Status array.

Implementation notes:
- `AuthorizeScope`: same logic as `Report::_authorizeKingdomParkReportScope` (private there, so reimplement with a comment pointing to it): `IsAuthorized($token)` → `BadToken()`; global admin (`HasAuthority($id, AUTH_ADMIN, 0, AUTH_ADMIN)` or `AUTH_CREATE`) → ok; `Park` → `HasAuthority($id, AUTH_PARK, $scopeId, AUTH_CREATE)`; `Kingdom` → `HasAuthority($id, AUTH_KINGDOM, $scopeId, AUTH_EDIT)`; otherwise `NoAuthorization()`. Anything else for ScopeType → `InvalidParameter`.
- `Run`: auth → columns (keep only known ids; always prepend `persona`; preserve request order; dedupe) → `NormalizeTree` (on failure return `['Status'=>InvalidParameter($err),'RulePath'=>$path]`) → build `$ctx['accountScope']` (Kingdom: `ac.kingdom_id IN (ids) OR ac.park_id IN (SELECT park_id FROM {park} WHERE kingdom_id IN (ids))`, wrapped in parentheses, ids from `GetStatsKingdomIds` cast `(int)`; Park: `(ac.park_id = P)`) → scope clause (`m.kingdom_id IN (ids)` / `m.park_id = P`) → `SELECT m.mundane_id, <col exprs AS c_<colId>> FROM {mundane} m LEFT JOIN {kingdom} k ON k.kingdom_id = m.kingdom_id LEFT JOIN {park} p ON p.park_id = m.park_id WHERE <scope> AND <tree> GROUP BY m.mundane_id ORDER BY m.persona, m.mundane_id LIMIT 5000` and a separate `SELECT COUNT(*) …` with the same WHERE for `Total`. `Truncated = Total > 5000`. Wrap compile in try/catch `InvalidArgumentException` → `InvalidParameter`. `ElapsedMs` from `microtime`. Log SQL via `logtrace('PopulationExplorer::Run', [...])` like `GetPlayerRoster` (never into the response).
- `PublicRegistry`: strip `sql`; options: `class` all `ork_class` rows; `award` all non-deprecated awards (id, name, peerage); `order` = awards grouped by peerage for Knight/Master/Paragon/Squire/Page/Man-At-Arms; `park` = parks in scope (Kingdom scope: parks of the stats kingdoms; Park scope: just that park; also include all active parks for global admins scoped to that kingdom — still limited to the chosen kingdom scope); `kingdom` = stats kingdoms for Kingdom scope, the park's kingdom for Park scope.

- [ ] **Step 1: Write the failing integration tests**

Create `tests/Integration/PopulationExplorerRunTest.php` following `LadderGridTest`'s setUp/tearDown (skip if `!ork3_test_db_available()`; `ReportsFixture::create()`; `cleanup()` in tearDown). Build a helper `req(token, scopeType, scopeId, tree, columns)`. Tests (each uses fixture players created via `createPlayer`, `insertAttendance` with explicit dates, `insertDues`, `insertLadderAward`/direct SQL via `$this->fixture->pdo()` for peerage awards):

```php
public function testEmptyTreeReturnsAllPlayersInScope();            // count equals SELECT COUNT(*) of mundane in park
public function testLastSigninFilterMatchesRosterLastSignIn();      // parity: players returned by last_signin >= D equal GetPlayerRoster rows whose LastSignIn >= D (same park scope)
public function testDuesPaidParityWithRoster();                     // dues_paid is yes == GetPlayerRoster(['DuesPaid'=>true]) set for the park
public function testActiveAndSuspendedFlags();
public function testNegatedComparisonsExcludeNullPlayers();         // player with no sign-ins is excluded by last_signin ne X and by last_class is_not X
public function testRevokedAwardsDoNotCountAsHeld();                // insert a Knight-peerage award row with revoked=1 -> knighthood has_any must NOT match; then revoked=0 -> matches
public function testPeerageParityWithKnightsReport();               // knighthood is yes set == players in the existing knights report for the kingdom (use the same service the knights_list path uses)
public function testParkOfficerCannotReadOtherPark();               // park officer token + ScopeId of another park -> Status != success (NoAuthorization), no Rows
public function testKingdomOfficerScopedToKingdom();
public function testGlobalAdminMayChooseAnyKingdom();
public function testBadTokenRejected();
public function testInvalidTreeReturnsRulePath();                   // RulePath === [0]
public function testColumnsAreWhitelistedAndPersonaForced();        // unknown column ignored; persona present; no real-name keys anywhere in Rows
public function testResultCapAndTotal();                            // temporarily lower cap via a protected/overridable MAX_ROWS accessor OR insert >cap rows; assert Truncated=true and Total reflects true count. Prefer an optional request key 'RowCap' honored only when smaller than MAX_ROWS, so the test needs no 5000 rows
public function testPublicRegistryHasNoSqlAndScopedOptions();       // no 'sql' key anywhere (json_encode succeeds); park options limited to scope
```

- [ ] **Step 2: Run to verify failure**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter PopulationExplorerRunTest`
Expected: FAIL (`Run` undefined).

- [ ] **Step 3: Implement** `AuthorizeScope`, `Run`, `PublicRegistry` (see notes). Resolve the peerage/held-award and reeve/corpora "verify against existing report" notes from Task 2 here if the parity tests expose differences, adjusting the fragments and keeping Task 2 unit tests green.

- [ ] **Step 4: Run all PopulationExplorer tests**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter PopulationExplorer`
Expected: PASS (unit + integration).

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.PopulationExplorer.php tests/Integration/PopulationExplorerRunTest.php
git diff --cached --stat
git commit -m "Run Population Explorer queries with server-enforced scope

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Model and controller (page + JSON + share-link decode)

**Files:**
- Modify: `orkui/model/model.Reports.php`
- Modify: `orkui/controller/controller.Reports.php`
- Test: `tests/Integration/PopulationExplorerControllerTest.php` (create; model-level, following `KingdomAjaxTest`/`ModelEventDelegationTest` style)

**Interfaces:**
- Consumes: `PopulationExplorer::Run`, `PublicRegistry`, `AuthorizeScope`, `NormalizeTree`.
- Produces:
  - `Model_Reports::population_registry(string $token, string $scopeType, int $scopeId): array`
  - `Model_Reports::population_run(array $request): array`
  - `Model_Reports::population_export(array $request): array` (Task 6 implements the domain side; for now return `['Status'=>InvalidParameter('Not implemented')]`… **no**: add the method in Task 6, not here).
  - `Controller_Reports::population_explorer($params = null)` — sets `$this->template = 'Reports_populationexplorer.tpl'`, `page_title = 'Population Explorer'`; scope from `$this->request->KingdomId` / `ParkId` else session `park_id` / `kingdom_id` (park wins when both come from session, matching `index()`); sets `$this->data` keys `pe_scope_type`, `pe_scope_id`, `pe_scope_name`, `pe_registry` (PublicRegistry), `pe_initial` (decoded, **re-validated** `q` link state or `null`), `pe_no_scope` (true when no scope). Sets `$this->data['menu']['reports']['url']` to the Park/Kingdom reports tab like `beltline_explorer`. Not added to `$public_reports` (login required).
  - `Controller_Reports::population_explorer_json()` — POST only; `Content-Type: application/json`; JSON body fields `ScopeType`, `ScopeId`, `Tree`, `Columns` (decode `php://input`); token from `$this->session->token`; not logged in → `{status:5,error:'Not logged in'}`; returns `{status:0, columns, rows, total, truncated, elapsed_ms}` or `{status:<n>, error, rule_path?}`; `exit` at end like `set_player_active_json`. `rows[].Persona` is passed through `stripslashes`.
  - Share-link decode helper (private in controller): `q` = base64url JSON of `{tree, columns}`; reject if > 8192 bytes or invalid base64/JSON (→ `pe_initial=null` and `pe_link_error` message shown by the template); on success run `NormalizeTree` via the model and only keep the tree if `ok`.

- [ ] **Step 1: Write failing tests** for: the model delegating (`population_run` returns the domain response for a fixture officer); the link decode rejecting oversized payload, bad base64 and bad JSON, and invalid trees (extract the decode into a small public static helper on `PopulationExplorer`—`DecodeLink(string $q): array` returning `['ok'=>bool,'state'=>?array,'error'=>?string]`—so it is unit-testable; the controller calls it). Add `testDecodeLinkRejectsOversize`, `testDecodeLinkRoundTrip`, `testDecodeLinkRejectsGarbage`, `testDecodeLinkRevalidatesTree` to `PopulationExplorerTest` (add `DecodeLink` + `EncodeLink` to the domain class; `EncodeLink(array $state): string` = rtrim(strtr(base64_encode(json_encode($state)), '+/', '-_'), '=')).

- [ ] **Step 2: Run to verify failure**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter PopulationExplorer`
Expected: new tests FAIL.

- [ ] **Step 3: Implement** `EncodeLink`/`DecodeLink` in the domain class, the two model methods (`$this->PopulationExplorer = new APIModel('PopulationExplorer')` in the constructor), and the controller methods as specified. Follow `beltline_explorer` / `set_player_active_json` conventions.

- [ ] **Step 4: Run tests; smoke the endpoints with curl**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter PopulationExplorer` → PASS.
Then log in under the dev bypass (one cookie jar, user `heraldsbridge`; see memory `reference_local_curl_auth_session`) and:
`curl -s -b jar -H 'Content-Type: application/json' -d '{"ScopeType":"Kingdom","ScopeId":<id>,"Tree":{"op":"AND","children":[]},"Columns":["persona"]}' 'http://localhost:19080/orkui/index.php?Route=Reports/population_explorer_json'`
Expected: JSON with `status:0` and rows.

- [ ] **Step 5: Commit**

```bash
git add orkui/model/model.Reports.php orkui/controller/controller.Reports.php system/lib/ork3/class.PopulationExplorer.php tests/Unit/PopulationExplorerTest.php tests/Integration/PopulationExplorerControllerTest.php
git diff --cached --stat
git commit -m "Wire Population Explorer page, JSON run endpoint and share-link decode

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Template, builder JS and CSS (runs in parallel with Task 6 once Task 4's JSON contract exists)

**Files:**
- Create: `orkui/template/default/Reports_populationexplorer.tpl`
- Create: `orkui/template/default/script/populationexplorer.js`
- Create: `orkui/template/default/style/populationexplorer.css`

**Interfaces:**
- Consumes: controller `$data` keys from Task 4 (`pe_scope_type`, `pe_scope_id`, `pe_scope_name`, `pe_registry`, `pe_initial`, `pe_link_error`, `pe_no_scope`); `POST Reports/population_explorer_json` (JSON in/out); export URL `Reports/population_explorer_export` (Task 6).
- Produces: the page. Global `window.PE = {registry, scope:{type,id,name}, initial, urls:{run, export, page}}` is set by the template; `populationexplorer.js` reads only that.

Requirements (from spec §5; read it before building):
- Template: `.rp-root`/`.rp-header` (icon `fa-users-viewfinder`, title "Population Explorer", scope chip linking to the org), `.rp-context` strip, `.rp-stats-row` (Results, % of scope, Run time), then `.rp-main` with three cards: Filters, Columns, Results. `pe_no_scope` → `.rp-empty-state` ("no scope"). Link `reports.css` with `filemtime`, plus `populationexplorer.css` and `populationexplorer.js` (also `filemtime`). Load DataTables 1.13.8 + Buttons the same way as `Reports_roster.tpl`. Embed `window.PE` using `json_encode(..., JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)`.
- Builder (vanilla JS, no jQuery UI): state `{op, children}`; recursive render; groups have an AND/OR segmented toggle, **+ Add rule**, **+ Add group** (disabled with `data-tip` at UI depth 3); rules are `[Criteria select grouped by group][Operand select][Value control][remove]`; changing criterion resets operand/value; the `N` months input shows for `param` criteria; connector label shows the group's AND/OR between rows. Value controls: Flatpickr `altInput` dates (two for `between`), number inputs (two for `between`), chip multi-select (custom `.pe-chip-*` dropdown, not jQuery UI; reposition with `tnPositionAcFixed()` pattern if inside a modal—not applicable here but keep the list scrollable) for class/award/order/park/kingdom from `registry.options`, Yes/No toggle, peerage criteria show chips of the order awards with `is` offering Yes/No.
- Columns card: grouped checkboxes from `registry.columns`, Persona locked on, defaults from `default`, Reset link.
- Run: button posts `{ScopeType, ScopeId, Tree, Columns}`; on success fill stats row and a DataTables table (Persona links to `<?=UIR?>Player/profile/<id>`); inline per-rule error using `rule_path`; truncated banner "Showing 5,000 of N — narrow your filter"; loading state; `.rp-empty-state` for zero rows.
- Copy link: `EncodeLink`-compatible base64url of `{tree, columns}` into `&pe=` on the page URL (`navigator.clipboard` with a textarea fallback; confirm via an inline toast, no native dialogs). On load, if `PE.initial` exists, populate the builder and auto-run.
- Export: submits a hidden form POST to `PE.urls.export` with the JSON state.
- CSS: `.pe-*` only builder pieces (group block with left accent bar/indent, segmented toggle, rule row grid, chips, toast); dark mode for each via `html[data-theme="dark"] .pe-…`; ≤ 600px: rule rows stack (criteria on top, operand + value below), no horizontal scroll, ≥44px tap targets; use existing CSS variables (`--ork-card-bg`, `--ork-border`, `--ork-input-bg`, `--ork-input-border`) before hardcoding colors. Tooltips via `data-tip` only.

- [ ] **Step 1: Build the page against the live JSON endpoint** (server from Task 4).
- [ ] **Step 2: Verify in Chrome (verification only):** build the spec's example query with a real kingdom scope, run, confirm counts vs `curl` of the JSON endpoint; check console is clean (`read_console_messages`).
- [ ] **Step 3: Dark mode:** toggle `html[data-theme="dark"]`; read computed styles for group, toggle, chip, rule-row, toast, table; fix any `orkui.css` global control overrides.
- [ ] **Step 4: Phone width** via the iframe harness (memory `reference_iframe_responsive_harness`; `resize_window` is a no-op): no horizontal scroll, rows stack, tap targets OK.
- [ ] **Step 5: Commit**

```bash
git add orkui/template/default/Reports_populationexplorer.tpl orkui/template/default/script/populationexplorer.js orkui/template/default/style/populationexplorer.css
git diff --cached --stat
git commit -m "Add Population Explorer builder page

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: `.xlsx` export

**Files:**
- Modify: `system/lib/ork3/class.PopulationExplorer.php`
- Modify: `orkui/model/model.Reports.php`
- Modify: `orkui/controller/controller.Reports.php`
- Test: `tests/Integration/PopulationExplorerRunTest.php` (append)

**Interfaces:**
- Consumes: `Run`; `system/lib/vendor/SimpleXlsx.php` (read its API first; see `class.TournamentExport.php` for a working builder that returns a temp-file path).
- Produces:
  - `PopulationExplorer::BuildExport(array $request): array` → `['Status'=>…, 'Path'=>string temp .xlsx, 'Filename'=>string]` or an error Status. Reuses `Run` (same auth, validation, cap); header row = column labels; a trailing note row "Showing first 5,000 of N matches" when truncated; date columns written as real dates, Yes/No for 0/1 flags.
  - `Model_Reports::population_export(array $request): array` pass-through.
  - `Controller_Reports::population_explorer_export()` — POST only, login required, reads the same JSON state from a `payload` form field (the template's hidden form), scope via the same validated path; streams with `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, `Content-Disposition: attachment; filename="population-explorer-YYYY-MM-DD.xlsx"`, `readfile` then `@unlink`.

- [ ] **Step 1: Failing tests** — `testExportBuildsReadableXlsxWithHeaderAndRows` (open the zip entries with `file_get_contents` on the STORE-packed file or a minimal reader to assert the header labels appear in `xl/sharedStrings.xml`/sheet XML, depending on SimpleXlsx output), `testExportTruncatedAddsNoteRow` (use `RowCap`), `testExportRequiresAuth`.
- [ ] **Step 2: Run to verify failure** — `--filter PopulationExplorerRunTest`.
- [ ] **Step 3: Implement** domain builder, model method, controller action.
- [ ] **Step 4: Run tests** — PASS; then download via curl with the cookie jar and open the file (`unzip -l` may not work on STORE-packed output? it does; verify with `python3 -c "import zipfile…"`).
- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.PopulationExplorer.php orkui/model/model.Reports.php orkui/controller/controller.Reports.php tests/Integration/PopulationExplorerRunTest.php
git diff --cached --stat
git commit -m "Add Population Explorer .xlsx export

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Entry points, What's New, performance, full QA

**Files:**
- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl` (reports tab, beside the Beltline Explorer link around line 850)
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl` (reports tab; find the sibling report list)
- Modify: the What's New release-notes source (read memory `reference_whats_new_release_notes.md` for the exact file/format)
- Modify: in-app docs only if memory `reference_in_app_docs.md` says reports are documented there.

- [ ] **Step 1: Add links.** Kingdom: `<li><a href="<?= UIR ?>Reports/population_explorer&KingdomId=<?= $kingdom_id ?>"><i class="fas fa-users-viewfinder"></i> Population Explorer</a></li>` inside the same permission block as the neighbouring report links (confirm the condition around line 847–851 — that block is the officer-only one). Park: same link with `&ParkId=<?= $park_id ?>` using the variable names that file already uses; follow the neighbouring `<li>` markup exactly.
- [ ] **Step 2: What's New entry** in the file/format from the memory reference.
- [ ] **Step 3: Performance.** Using the real `ork` DB (not `ork_test`), against the largest kingdom (~15.8k players; find its id with `SELECT kingdom_id, COUNT(*) FROM ork_mundane GROUP BY 1 ORDER BY 2 DESC LIMIT 1`), time each single criterion and the spec's example query via CLI (`docker exec -i ork3-php8-app php -r 'chdir("/var/www/ork.amtgard.com"); require "/var/www/ork.amtgard.com/startup.php"; …'` calling `Ork3::$Lib->populationexplorer->Run(...)` with an admin token, or temporarily calling the compile + `EXPLAIN`). Target: each < 2 s warm. If one is slower, rewrite its fragment (or confirm which index it uses via `EXPLAIN`) and re-run the unit/integration tests. Record the timing table in the PR description; do NOT add indexes without checking the migration-classification gate.
- [ ] **Step 4: Full test run.** `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist` → no new failures vs. master baseline; run `php vendor/bin/php-cs-fixer` per `reference_psr12_tooling` on the new PHP files only (normalize-first; tab count).
- [ ] **Step 5: Browser QA** (per `/browser-testing` conventions; serial agents; Chrome for verification): build the spec's example query end to end; Export opens in a spreadsheet reader; copy the share link, open it in a fresh session as the same officer → identical result; as a park officer, edit the link to another park's `ParkId` → blocked; dark mode; phone width.
- [ ] **Step 6: Layers check.** Run `grep -n "SELECT\|mysql_" orkui/controller/controller.Reports.php orkui/template/default/Reports_populationexplorer.tpl` → no new SQL outside `system/lib/ork3/`.
- [ ] **Step 7: Commit and report**

```bash
git add orkui/template/revised-frontend/Kingdomnew_index.tpl orkui/template/revised-frontend/Parknew_index.tpl <whats-new file>
git diff --cached --stat
git commit -m "Link Population Explorer from Kingdom and Park reports tabs

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Self-Review (done)

- **Spec coverage:** §3 architecture → Tasks 1–4; §3.1 registry → T1; §3.2 validation/compile → T1–2; §3.3 scope/auth → T3; §3.4 execution/cap/semantics → T2–3; §3.5 share link/export → T4, T6; §4 catalog → T1 id lists + T2 table; §5 UI → T5; §6 errors → T3/T4/T5; §7 testing → T1–3 tests, T7 perf/QA; §8 rollout (links, What's New, no migration) → T7.
- **Placeholder scan:** none; items marked "verify against existing report" are explicit verification steps with a named test (`testPeerageParityWithKnightsReport`, `testDuesPaidParityWithRoster`), not deferred work.
- **Type consistency:** `NormalizeTree`/`CompileTree`/`Run`/`PublicRegistry`/`AuthorizeScope`/`EncodeLink`/`DecodeLink`/`BuildExport` and operand tokens are identical in every task; request/response contract is defined once at the top.
- **Parallelism:** T1→T2→T3 are sequential (same file). T4 follows T3. T5 and T6 can run in parallel after T4 (disjoint files except `model/controller`, which T6 touches by appending separate methods — run T6's controller edit after T4 lands to avoid conflicts). T7 last.
