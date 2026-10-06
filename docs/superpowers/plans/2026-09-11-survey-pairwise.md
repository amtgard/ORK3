# Survey Pairwise Question Type Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Each task names the model tier and effort it runs at. Tasks run in order: each later task consumes names an earlier one produces.

**Goal:** Add a `pairwise` survey question type. Respondents judge random matchups of two options (or call a tie) with a size-aware encouragement bar and a required gate, and results rank the options by win %.

**Architecture:**
- **Data:** one ENUM value and no new table. Each judged matchup is one `ork_survey_answer` row (`option_id` = left, `row_option_id` = right, `value_num` = the left option's points).
- **Pure core:** `SurveyTypes` holds the band table, `pairwisePlan()` and validation. `SurveyReport` holds `aggPairwise()` and the CSV cell. `survey-render.js` mirrors the plan and adds the queue and stage helpers, pinned to the PHP by a node parity test.
- **Surfaces:** the shared renderer's widget (runner and builder preview), the builder's paste textarea plus (?) modal, and the results card (chart, callouts, DataTable).

**Tech Stack:**
- PHP 8 / MariaDB (`sql_mode=''`), plain-PHP `.tpl` templates, vanilla JS IIFEs, CSS on `--ork-*` / `--sv-*` tokens.
- Highcharts 11 (results page), DataTables 1.13.8.
- PHPUnit 10: `tests/Unit` needs no DB; `tests/Integration` runs on the `ork_test` sandbox. Node (for the JS harness).
- Docker dev stack on port 19080.

**Spec:** `docs/superpowers/specs/2026-09-11-survey-pairwise-design.md`. Read it with this plan; "§N" below points at it.

## Global Constraints

- **Git:**
  - Branch `feature/survey-module`. Other sessions commit to this branch concurrently: run `git status --short` and `git diff --cached --stat` before every commit, and commit with explicit paths (`git commit -- <paths>`).
  - Never `git add -A`. Never stage `system/lib/ork3/class.Authorization.php` (local `true ||` bypass), `CLAUDE.md` or `agent-instructions/claude.md`.
  - Never push. Never `git stash`. Never create worktrees.
- **PHP style:** `.tpl` files are plain PHP, never Smarty. Before editing an existing PHP file run `awk '/^\t/{c++}END{print c+0}' <file>`. If it is non-zero and the file is not a `.tpl`, run `tools/php-cs-fixer/php-cs-fixer.phar fix <file>` first and commit that separately. (All five PHP files this plan touches measured 0 on 2026-09-11.)
- **Layering:**
  - All SQL lives in `system/lib/ork3/`. Under `orkui/` never use `$DB->`, `Ork3::$Lib`, or `new Survey*(` outside `orkui/model/model.Survey.php`.
  - `$this->db->Clear()` before every `DataSet` / `Execute`.
- **Fixed copy, word for word (spec §6):**
  - Tier 1: `This is a great start. You can move on, but you can make our survey better by doing a few more matchups!`
  - Tier 2: `Even better! You can keep going for better results or continue.`
  - Tier 3: `Awesome! This is a great sample. Feel free to keep ranking or continue on.`
  - Tier 4: `Fantastic! You've given us a great sample size, so you can keep going or continue on. Your choice!`
  - 100%: `Whoa, you ranked them all! Incredible job, we thank you!`
  - Below tier 1, required: `{n} more matchups to go before you can continue.` (`1 more matchup to go…` when n = 1)
  - Below tier 1, optional: `Every matchup helps. Do as many as you like.`
  - Small set, required: `Finish all {M} matchups to continue.`
  - Gate errors: `Please complete at least {gate} matchups to continue.` and, on a small set, `Please finish all {M} matchups to continue.`
- **Bands (spec P5):** ≤30 small · 31–105 = 30/40/50/60 · 106–200 = 20/30/40/50 · 201–300 = 10/20/30/40 · 301+ = 10/15/20/25. The ceiling is integer math: `intdiv(pct*M + 99, 100)`.
- **UI rules:**
  - No native `alert/confirm/prompt`. Tooltips are `data-tip`. Dark mode via `html[data-theme="dark"]`; every new color is a token with a dark value.
  - Tap targets ≥ 44px on touch; no horizontal scroll at 360px. Tabular data uses DataTables.
  - Headings inside tool pages must not pick up `orkui.css`'s global `h1–h6` pill box. Check computed styles in both themes.
- **Local environment:**
  - Run PHPUnit directly: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter <Name>` (`bin/run-unit-tests.sh` never reaches it locally).
  - Log in with `POST http://localhost:19080/orkui/index.php?Route=Login/login`, `username=heraldsbridge&password=x`, into one cookie jar. `heraldsbridge` is mundane 46193, kingdom 17, ORK admin. Curl login evicts the browser session.
  - After a migration, `docker restart ork3-php8-app` (APCu schema cache).
  - Survey fixtures with credits enabled are permanent. Never enable credits on any fixture.
- **Commits:** message `Enhancement: Survey — <what>`, ending with the session's commit trailer.

## File Map

| File | Status | Responsibility |
|---|---|---|
| `db-migrations/2026-09-11-survey-pairwise.sql` | create | add `'pairwise'` to `ork_survey_question.type` |
| `tools/ork-db/manifests/migration-classification.json5` | modify | classify it |
| `system/lib/ork3/class.SurveyTypes.php` | modify | catalog entries, `PAIRWISE_*`, `pairwisePlan()`, `pairwiseGateMessage()`, `validatePairwise()` |
| `system/lib/ork3/class.Survey.php` | modify | `optionSet()` refuses duplicate labels and "Other" on pairwise |
| `system/lib/ork3/class.SurveyResponse.php` | modify | respondent definition carries `pairwise` plan |
| `system/lib/ork3/class.SurveyReport.php` | modify | `aggPairwise()`, `displayAnswer()` pairwise cell (+ `$choiceCount`) |
| `orkui/template/default/script/survey-render.js` | modify | JS plan/queue/stage core (Task 5); widget, read/write, clicks, keys (Task 6) |
| `orkui/template/default/script/survey-take.js` | modify | client gate check |
| `orkui/template/default/style/survey.css` | modify | `--sv-pw-*` tokens and widget styles |
| `orkui/template/default/script/survey-build.js` | modify | palette entry, paste textarea, readout, (?) modal |
| `orkui/template/default/style/survey-build.css` | modify | textarea, readout, warning, help modal |
| `orkui/template/default/script/survey-results.js` | modify | `specPairwise`, callouts, ranking DataTable, row cell clamp |
| `orkui/template/default/style/survey-results.css` | modify | table wrap, row-cell clamp |
| `tests/Unit/SurveyTypesTest.php` | modify | catalog + plan + validation |
| `tests/Unit/SurveyAggregateTest.php` | modify | aggregate + CSV cell |
| `tests/Unit/SurveyPairwisePlanScriptTest.php` | create | JS/PHP parity, queue, stage |
| `tests/Unit/js/survey-pairwise-harness.js` | create | loads `survey-render.js` under node |
| `tests/Integration/SurveyTest.php` | modify | option rules, gate on submit, stored rows, definition, retype |
| `bin/seed-survey-example.php` | modify | a pairwise demo question + answers |
| `docs/survey-guide.md` | modify | type row + "Pairwise questions" section (also the in-app help) |
| `orkui/whats_new_content.php` | modify | Survey release entry mentions pairwise |

---

## Phase 1: Data and pure core

### Task 1: Migration, classification, apply

**Model:** sonnet · effort low

**Files:**
- Create: `db-migrations/2026-09-11-survey-pairwise.sql`
- Modify: `tools/ork-db/manifests/migration-classification.json5` (after the `2026-09-11-survey-sharing-credits.sql` entry, ~line 96)

**Interfaces:**
- Produces: `ork_survey_question.type` accepts `'pairwise'`.

- [ ] **Step 1: Write the migration**

```sql
-- Survey module: the pairwise comparison question type.
-- Spec: docs/superpowers/specs/2026-09-11-survey-pairwise-design.md §1.
-- Idempotent: re-running the MODIFY with the same list is a no-op.
-- Answers need no schema change: one ork_survey_answer row per matchup
-- (option_id = left, row_option_id = right, value_num = left's points).
-- After applying: docker restart ork3-php8-app (APCu schema cache).

ALTER TABLE ork_survey_question
  MODIFY COLUMN type ENUM('single','multi','dropdown','yesno','rating','nps','matrix','ranking','pairwise',
                          'short_text','paragraph','number','date','section','image') NOT NULL;
```

- [ ] **Step 2: Classify**

Add after the `2026-09-11-survey-sharing-credits.sql` line:

```json5
    "2026-09-11-survey-pairwise.sql": { "class": "S", "render": "full", "notes": "Survey pairwise question type: adds 'pairwise' to ork_survey_question.type ENUM (DDL only, no data); idempotent" },
```

- [ ] **Step 3: Apply to dev and test, restart, verify**

```bash
docker exec -i ork3-php8-db mariadb -uroot -proot ork < db-migrations/2026-09-11-survey-pairwise.sql
docker exec -i ork3-php8-db mariadb -uroot -proot ork < db-migrations/2026-09-11-survey-pairwise.sql   # second run must be silent
docker exec -i ork3-php8-test-db mariadb -uroot -proot ork_test < db-migrations/2026-09-11-survey-pairwise.sql
docker restart ork3-php8-app
for db in "ork3-php8-db ork" "ork3-php8-test-db ork_test"; do set -- $db
  docker exec $1 mariadb -uroot -proot $2 -Nse "SHOW COLUMNS FROM ork_survey_question LIKE 'type'"
done
```
Expected: each DB lists an enum containing `'ranking','pairwise','short_text'`.

- [ ] **Step 4: Drift check**

```bash
php tools/ork-db/cli.php drift-check --strict 2>&1 | grep -i "unclassified\|2026-09-11-survey-pairwise" ; echo done
```
Expected: no "unclassified" line. The local `catalog hash drift` failure is known; ignore it.

- [ ] **Step 5: Commit**

```bash
git status --short && git diff --cached --stat
git add db-migrations/2026-09-11-survey-pairwise.sql tools/ork-db/manifests/migration-classification.json5
git commit -m "Enhancement: Survey — migration adds the pairwise question type" -- db-migrations/2026-09-11-survey-pairwise.sql tools/ork-db/manifests/migration-classification.json5
```

---

### Task 2: `SurveyTypes` catalog, plan and answer validation

**Model:** sonnet · effort medium

**Files:**
- Modify: `system/lib/ork3/class.SurveyTypes.php`
- Test: `tests/Unit/SurveyTypesTest.php`

**Interfaces:**
- Produces:
  - `SurveyTypes::TYPES` / `ANSWERABLE` include `'pairwise'` (right after `'ranking'`); `OPTION_ROLES['pairwise'] = ['choice']`
  - `SurveyTypes::PAIRWISE_SMALL_MAX = 30`, `SurveyTypes::PAIRWISE_BANDS` (spec §2)
  - `SurveyTypes::pairwisePlan(int $optionCount): array{possible:int, small:bool, band_pcts:list<int>, tiers:list<int>, gate:int}`
  - `SurveyTypes::pairwiseGateMessage(array $plan): string`
  - `validateAnswer()` for `pairwise` returns rows `{option_id: a, row_option_id: b, value_text: null, value_num: 1.0|0.5|0.0}`

- [ ] **Step 1: Update the catalog tests and write the failing tests**

In `tests/Unit/SurveyTypesTest.php`:

- In `testTypesAndAnswerable`, change the expected TYPES list to `['single','multi','dropdown','yesno','rating','nps','matrix','ranking','pairwise', 'short_text','paragraph','number','date','section','image']` and `assertCount(12, …)` to `assertCount(13, SurveyTypes::ANSWERABLE)`. Add `$this->assertNotContains('pairwise', SurveyTypes::SHOW_IF_SOURCES);` and `$this->assertSame(['choice'], SurveyTypes::OPTION_ROLES['pairwise']);`.
- In `testDefaultSettingsForEveryType` add `$this->assertSame([], SurveyTypes::defaultSettings('pairwise'));`.
- In `testMinOptions` add `$this->assertSame(['choice' => 3], SurveyTypes::minOptions('pairwise'));`.

Append these tests before the closing brace:

```php
    // --------------------------------------------------------------- pairwise

    /** @return list<array{option_id:int,role:string,is_other:int,label:string}> */
    private function pairwiseOptions(int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = ['option_id' => 100 + $i, 'role' => 'choice', 'is_other' => 0, 'label' => 'O' . $i];
        }
        return $out;
    }

    public function testPairwiseSeedsThreeOptionsAndDropsSettings(): void
    {
        $this->assertSame(
            [
                ['role' => 'choice', 'label' => 'Option 1'],
                ['role' => 'choice', 'label' => 'Option 2'],
                ['role' => 'choice', 'label' => 'Option 3'],
            ],
            SurveyTypes::seedOptions('pairwise')
        );
        $v = SurveyTypes::validateSettings('pairwise', ['randomize' => true]);
        $this->assertTrue($v['ok']);
        $this->assertSame([], $v['settings']);
    }

    /** Every band boundary (spec §2 worked values): 28/36, 105/120, 190/210, 300/325. */
    public function testPairwisePlanAtEveryBandBoundary(): void
    {
        $expect = [
            0  => [0, true, [], [], 0],
            1  => [0, true, [], [], 0],
            2  => [1, true, [], [], 1],
            3  => [3, true, [], [], 3],
            8  => [28, true, [], [], 28],
            9  => [36, false, [30, 40, 50, 60], [11, 15, 18, 22], 11],
            12 => [66, false, [30, 40, 50, 60], [20, 27, 33, 40], 20],
            15 => [105, false, [30, 40, 50, 60], [32, 42, 53, 63], 32],
            16 => [120, false, [20, 30, 40, 50], [24, 36, 48, 60], 24],
            20 => [190, false, [20, 30, 40, 50], [38, 57, 76, 95], 38],
            21 => [210, false, [10, 20, 30, 40], [21, 42, 63, 84], 21],
            25 => [300, false, [10, 20, 30, 40], [30, 60, 90, 120], 30],
            26 => [325, false, [10, 15, 20, 25], [33, 49, 65, 82], 33],
            30 => [435, false, [10, 15, 20, 25], [44, 66, 87, 109], 44],
        ];
        foreach ($expect as $n => [$possible, $small, $pcts, $tiers, $gate]) {
            $this->assertSame(
                ['possible' => $possible, 'small' => $small, 'band_pcts' => $pcts, 'tiers' => $tiers, 'gate' => $gate],
                SurveyTypes::pairwisePlan($n),
                'n=' . $n
            );
        }
    }

    public function testPairwiseGateMessages(): void
    {
        $this->assertSame('Please finish all 15 matchups to continue.', SurveyTypes::pairwiseGateMessage(SurveyTypes::pairwisePlan(6)));
        $this->assertSame('Please complete at least 20 matchups to continue.', SurveyTypes::pairwiseGateMessage(SurveyTypes::pairwisePlan(12)));
    }

    public function testPairwiseValidAnswerBecomesOneRowPerMatchup(): void
    {
        $q = ['type' => 'pairwise', 'required' => 0];
        $r = SurveyTypes::validateAnswer($q, $this->pairwiseOptions(3), [
            ['a' => 100, 'b' => 101, 'w' => 100],
            ['a' => 102, 'b' => 100, 'w' => 0],
            ['a' => 101, 'b' => 102, 'w' => 102],
        ]);
        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame([
            ['option_id' => 100, 'row_option_id' => 101, 'value_text' => null, 'value_num' => 1.0],
            ['option_id' => 102, 'row_option_id' => 100, 'value_text' => null, 'value_num' => 0.5],
            ['option_id' => 101, 'row_option_id' => 102, 'value_text' => null, 'value_num' => 0.0],
        ], $r['rows']);
    }

    public function testPairwiseRejectsBadEntries(): void
    {
        $q = ['type' => 'pairwise', 'required' => 0];
        $opts = $this->pairwiseOptions(3);
        $cases = [
            'unknown option' => [[['a' => 100, 'b' => 999, 'w' => 100]], 'That option is not part of this question.'],
            'same option'    => [[['a' => 100, 'b' => 100, 'w' => 100]], 'Please make your picks again.'],
            'bad winner'     => [[['a' => 100, 'b' => 101, 'w' => 102]], 'Please make your picks again.'],
            'not a list'     => ['hello', 'Please make your picks again.'],
            'missing key'    => [[['a' => 100, 'b' => 101]], 'Please make your picks again.'],
            'repeat pair'    => [[['a' => 100, 'b' => 101, 'w' => 100], ['a' => 101, 'b' => 100, 'w' => 0]], 'Each matchup may be answered only once.'],
        ];
        foreach ($cases as $label => [$value, $error]) {
            $r = SurveyTypes::validateAnswer($q, $opts, $value);
            $this->assertFalse($r['ok'], $label);
            $this->assertSame($error, $r['error'], $label);
        }
    }

    public function testPairwiseRequiredGate(): void
    {
        // 9 options = 36 matchups, gate 11.
        $opts = $this->pairwiseOptions(9);
        $pairs = [];
        for ($i = 0; $i < 9; $i++) {
            for ($j = $i + 1; $j < 9; $j++) {
                $pairs[] = ['a' => 100 + $i, 'b' => 100 + $j, 'w' => 0];
            }
        }
        $req = ['type' => 'pairwise', 'required' => 1];

        $below = SurveyTypes::validateAnswer($req, $opts, array_slice($pairs, 0, 10));
        $this->assertFalse($below['ok']);
        $this->assertSame('Please complete at least 11 matchups to continue.', $below['error']);

        $at = SurveyTypes::validateAnswer($req, $opts, array_slice($pairs, 0, 11));
        $this->assertTrue($at['ok']);
        $this->assertCount(11, $at['rows']);

        $empty = SurveyTypes::validateAnswer($req, $opts, []);
        $this->assertSame('Please complete at least 11 matchups to continue.', $empty['error']);

        $optional = SurveyTypes::validateAnswer(['type' => 'pairwise', 'required' => 0], $opts, []);
        $this->assertTrue($optional['ok']);
        $this->assertSame([], $optional['rows']);
    }

    public function testPairwiseSmallRequiredSetNeedsEveryMatchup(): void
    {
        $opts = $this->pairwiseOptions(3);   // 3 matchups
        $req = ['type' => 'pairwise', 'required' => 1];
        $two = SurveyTypes::validateAnswer($req, $opts, [
            ['a' => 100, 'b' => 101, 'w' => 100],
            ['a' => 100, 'b' => 102, 'w' => 100],
        ]);
        $this->assertSame('Please finish all 3 matchups to continue.', $two['error']);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter SurveyTypesTest`
Expected: FAIL (TYPES list mismatch, `pairwisePlan` undefined).

- [ ] **Step 3: Implement**

In `class.SurveyTypes.php`:

1. Constants: add `'pairwise'` after `'ranking'` in `TYPES` and `ANSWERABLE`. Add `'pairwise' => ['choice'],` to `OPTION_ROLES` after `'ranking'`. After `NPS_MAX` add:

```php
    /** A pairwise set at or under this many matchups shows the plain bar; a required one needs every matchup. */
    public const PAIRWISE_SMALL_MAX = 30;

    /**
     * Encouragement bands for a pairwise question by its matchup count (pairwise
     * spec P5): [upper bound or null, [tier 1..4 percent]]. Tier 1 is the gate a
     * REQUIRED respondent must reach. survey-render.js mirrors this table as
     * PW_BANDS; SurveyPairwisePlanScriptTest pins the two together.
     */
    public const PAIRWISE_BANDS = [
        [105,  [30, 40, 50, 60]],
        [200,  [20, 30, 40, 50]],
        [300,  [10, 20, 30, 40]],
        [null, [10, 15, 20, 25]],
    ];
```

2. `seedOptions()`: add a case before `'matrix'`:

```php
            case 'pairwise':
                return [
                    ['role' => 'choice', 'label' => 'Option 1'],
                    ['role' => 'choice', 'label' => 'Option 2'],
                    ['role' => 'choice', 'label' => 'Option 3'],
                ];
```

3. `minOptions()`: add before `case 'matrix':`

```php
            case 'pairwise':
                return ['choice' => 3];
```

4. `validateAnswer()`: directly after the line `$settings = $sv['ok'] ? $sv['settings'] : self::defaultSettings($type);` insert:

```php
        // Pairwise owns its empty case: a required one names the gate, not "required".
        if ('pairwise' === $type) {
            return self::validatePairwise($options, $value, $required);
        }
```

5. Add these public statics after `isAnswerable()`:

```php
    /**
     * The matchup plan for a pairwise question with $optionCount options
     * (pairwise spec §2). Tier counts are integer ceilings, so float rounding
     * can never move a threshold.
     *
     * @return array{possible: int, small: bool, band_pcts: list<int>, tiers: list<int>, gate: int}
     */
    public static function pairwisePlan(int $optionCount): array
    {
        $n = max(0, $optionCount);
        $possible = $n < 2 ? 0 : intdiv($n * ($n - 1), 2);
        if ($possible <= self::PAIRWISE_SMALL_MAX) {
            return ['possible' => $possible, 'small' => true, 'band_pcts' => [], 'tiers' => [], 'gate' => $possible];
        }
        $pcts = [];
        foreach (self::PAIRWISE_BANDS as [$max, $bandPcts]) {
            if (null === $max || $possible <= $max) {
                $pcts = $bandPcts;
                break;
            }
        }
        $tiers = [];
        foreach ($pcts as $pct) {
            $tiers[] = intdiv($pct * $possible + 99, 100);
        }
        return ['possible' => $possible, 'small' => false, 'band_pcts' => $pcts, 'tiers' => $tiers, 'gate' => $tiers[0]];
    }

    /** What a REQUIRED pairwise question says below its gate (the runner copies it). */
    public static function pairwiseGateMessage(array $plan): string
    {
        return !empty($plan['small'])
            ? 'Please finish all ' . (int) $plan['possible'] . ' matchups to continue.'
            : 'Please complete at least ' . (int) $plan['gate'] . ' matchups to continue.';
    }
```

6. Add the private validator after `validateRanking()`:

```php
    /**
     * A list of {a, b, w} matchups in answer order (pairwise spec §3): a is the
     * left option, b the right, w the winner's id or 0 for a tie. Each unordered
     * pair may appear once. A REQUIRED question must reach the plan's gate; an
     * optional one may be empty (skipped).
     *
     * @param  list<array<string, mixed>> $options
     * @param  mixed $value
     * @return array{ok: bool, error: ?string, rows: list<array<string, mixed>>}
     */
    private static function validatePairwise(array $options, $value, bool $required): array
    {
        $choiceIds = self::optionIds($options, 'choice');
        $plan = self::pairwisePlan(count($choiceIds));

        if (self::isEmptyValue($value)) {
            return $required ? self::answerError(self::pairwiseGateMessage($plan)) : self::answerOk([]);
        }
        if (!is_array($value)) {
            return self::answerError('Please make your picks again.');
        }

        $rows = [];
        $seen = [];
        foreach (array_values($value) as $entry) {
            if (
                !is_array($entry) || !isset($entry['a'], $entry['b'], $entry['w'])
                || !is_numeric($entry['a']) || !is_numeric($entry['b']) || !is_numeric($entry['w'])
            ) {
                return self::answerError('Please make your picks again.');
            }
            $a = (int) $entry['a'];
            $b = (int) $entry['b'];
            $w = (int) $entry['w'];
            if (!in_array($a, $choiceIds, true) || !in_array($b, $choiceIds, true)) {
                return self::answerError('That option is not part of this question.');
            }
            if ($a === $b || (0 !== $w && $w !== $a && $w !== $b)) {
                return self::answerError('Please make your picks again.');
            }
            $pair = min($a, $b) . ':' . max($a, $b);
            if (isset($seen[$pair])) {
                return self::answerError('Each matchup may be answered only once.');
            }
            $seen[$pair] = true;
            $rows[] = self::row($a, $b, null, $w === $a ? 1.0 : (0 === $w ? 0.5 : 0.0));
        }

        if ($required && count($rows) < $plan['gate']) {
            return self::answerError(self::pairwiseGateMessage($plan));
        }
        return self::answerOk($rows);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter SurveyTypesTest`
Expected: PASS. Then `php -l system/lib/ork3/class.SurveyTypes.php`.

- [ ] **Step 5: Commit**

```bash
git status --short && git diff --cached --stat
git add system/lib/ork3/class.SurveyTypes.php tests/Unit/SurveyTypesTest.php
git commit -m "Enhancement: Survey — pairwise type in the catalog: bands, plan and answer validation" -- system/lib/ork3/class.SurveyTypes.php tests/Unit/SurveyTypesTest.php
```

---

### Task 3: Server wiring (option rules, respondent plan) with integration tests

**Model:** sonnet · effort medium

**Files:**
- Modify: `system/lib/ork3/class.Survey.php` (`optionSet()`, after the yes/no checks near line 2089)
- Modify: `system/lib/ork3/class.SurveyResponse.php` (`renderPagesForRespondent()`, ~line 812)
- Test: `tests/Integration/SurveyTest.php`

**Interfaces:**
- Consumes: `SurveyTypes::pairwisePlan()` (Task 2)
- Produces:
  - `optionSet()` on a pairwise question fails with `“{label}” is listed twice.` or `A pairwise question cannot have an "other" option.`
  - Respondent definition question objects of type `pairwise` carry `pairwise` = `pairwisePlan(choice count)`.

- [ ] **Step 1: Write the failing integration tests**

Append to `tests/Integration/SurveyTest.php`, before the private helpers section (e.g. after `testRetypeIsRefusedOnALockedSurveyAndForAnUnknownType`):

```php
    // ------------------------------------------------------------------
    // Pairwise (pairwise spec §1, §3)
    // ------------------------------------------------------------------

    /** @return array{survey_id:int, page:int, q:int, ids:list<int>} a draft survey with one pairwise question of $n options */
    private function buildPairwise(int $n, bool $required): array
    {
        $r = $this->survey->create($this->officerId, 'kingdom', $this->kingdomId, self::MARKER . ' Pairwise');
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $surveyId = (int) $r['SurveyId'];
        $this->surveyIds[] = $surveyId;
        $page = (int) $this->pdo->query(
            'SELECT page_id FROM ' . DB_PREFIX . 'survey_page WHERE survey_id = ' . $surveyId . ' ORDER BY sort_order LIMIT 1'
        )->fetchColumn();

        $q = $this->addQuestion($surveyId, $page, 'pairwise', 'Which is better?');
        $this->assertCount(3, $this->optionIds($q, 'choice'), 'a new pairwise question seeds three options');
        if ($required) {
            $this->assertSame(0, $this->survey->questionUpdate($q, ['Required' => 1])['Status']);
        }
        $labels = [];
        for ($i = 1; $i <= $n; $i++) {
            $labels[] = ['label' => 'Item ' . $i];
        }
        $set = $this->survey->optionSet($q, 'choice', $labels);
        $this->assertSame(0, $set['Status'], (string) ($set['Error'] ?? ''));

        return ['survey_id' => $surveyId, 'page' => $page, 'q' => $q, 'ids' => $this->optionIds($q, 'choice')];
    }

    /** @return list<array{a:int,b:int,w:int}> the first $count pairs of $ids, left winning */
    private function pairwiseAnswer(array $ids, int $count): array
    {
        $out = [];
        foreach ($ids as $i => $a) {
            foreach (array_slice($ids, $i + 1) as $b) {
                if (count($out) >= $count) {
                    return $out;
                }
                $out[] = ['a' => $a, 'b' => $b, 'w' => $a];
            }
        }
        return $out;
    }

    public function testPairwiseOptionSetRefusesDuplicateLabelsAndOther(): void
    {
        $ctx = $this->buildPairwise(3, false);

        $dup = $this->survey->optionSet($ctx['q'], 'choice', [['label' => 'Hawk'], ['label' => 'Owl'], ['label' => ' hawk ']]);
        $this->assertSame(1, $dup['Status']);
        $this->assertSame('“hawk” is listed twice.', $dup['Error']);

        $other = $this->survey->optionSet($ctx['q'], 'choice', [['label' => 'A'], ['label' => 'B'], ['label' => 'C', 'is_other' => 1]]);
        $this->assertSame(1, $other['Status']);
        $this->assertSame('A pairwise question cannot have an "other" option.', $other['Error']);

        $two = $this->survey->optionSet($ctx['q'], 'choice', [['label' => 'A'], ['label' => 'B']]);
        $this->assertSame(1, $two['Status'], 'pairwise needs at least three options');
    }

    public function testPairwiseRequiredGateOnSubmitAndStoredRows(): void
    {
        // 9 options = 36 matchups: gate 11.
        $ctx = $this->buildPairwise(9, true);
        $this->assertSame(0, $this->survey->setStatus($ctx['survey_id'], 'open')['Status']);

        $below = (new SurveyResponse())->submit(
            $ctx['survey_id'], $this->players['p1'], [$ctx['q'] => $this->pairwiseAnswer($ctx['ids'], 10)], 'full', 60, false
        );
        $this->assertSame(1, $below['Status']);
        $this->assertSame('Please complete at least 11 matchups to continue.', $below['Errors'][$ctx['q']] ?? null);

        $answer = $this->pairwiseAnswer($ctx['ids'], 11);
        $answer[1]['w'] = 0;                      // one tie
        $answer[2]['w'] = $answer[2]['b'];        // one right-side win
        $ok = (new SurveyResponse())->submit(
            $ctx['survey_id'], $this->players['p1'], [$ctx['q'] => $answer], 'full', 60, false
        );
        $this->assertSame(0, $ok['Status'], (string) ($ok['Error'] ?? ''));

        $rows = $this->pdo->query(
            'SELECT a.option_id, a.row_option_id, a.value_num FROM ' . DB_PREFIX . 'survey_answer a
               JOIN ' . DB_PREFIX . 'survey_response r ON r.response_id = a.response_id
              WHERE r.survey_id = ' . $ctx['survey_id'] . ' ORDER BY a.answer_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(11, $rows);
        $this->assertSame([(int) $answer[0]['a'], (int) $answer[0]['b'], 1.0],
            [(int) $rows[0]['option_id'], (int) $rows[0]['row_option_id'], (float) $rows[0]['value_num']]);
        $this->assertSame(0.5, (float) $rows[1]['value_num']);
        $this->assertSame(0.0, (float) $rows[2]['value_num']);
    }

    public function testPairwiseDefinitionCarriesThePlan(): void
    {
        $ctx = $this->buildPairwise(12, false);
        $def = (new SurveyResponse())->definitionForRespondent($ctx['survey_id'], $this->officerId, true);
        $this->assertSame(0, $def['Status'], (string) ($def['Error'] ?? ''));
        $q = null;
        foreach ($def['Pages'] as $page) {
            foreach ($page['questions'] as $cand) {
                if ((int) $cand['question_id'] === $ctx['q']) {
                    $q = $cand;
                }
            }
        }
        $this->assertNotNull($q);
        $this->assertSame(SurveyTypes::pairwisePlan(12), $q['pairwise']);
        $this->assertSame(20, $q['pairwise']['gate']);
    }

    public function testRetypeSingleWithOtherToPairwiseClearsOther(): void
    {
        $ctx = $this->buildSurvey();   // q_single carries an "other" option
        $r = $this->survey->questionUpdate($ctx['q_single'], ['Type' => 'pairwise']);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $others = array_map(static fn ($o) => (int) $o['is_other'], $r['Question']['Options']);
        $this->assertCount(3, $others);
        $this->assertSame([0, 0, 0], $others);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter 'SurveyTest::testPairwise|SurveyTest::testRetypeSingleWithOther'`
Expected: the duplicate/other and definition tests FAIL. The retype test may already pass (retype clears `is_other` today); keep it as the pin.

- [ ] **Step 3: Implement**

`class.Survey.php`, in `optionSet()` directly after the `if ($type === 'yesno') { foreach … }` block:

```php
        if ($type === 'pairwise') {
            // A matchup of an item against itself is meaningless (pairwise spec §3),
            // and a write-in has no place in a head-to-head.
            $labels = [];
            foreach ($clean as $c) {
                if ($c['is_other']) {
                    return $this->fail('A pairwise question cannot have an "other" option.');
                }
                $key = mb_strtolower($c['label']);
                if (isset($labels[$key])) {
                    return $this->fail('“' . $c['label'] . '” is listed twice.');
                }
                $labels[$key] = true;
            }
        }
```

`class.SurveyResponse.php`, in `renderPagesForRespondent()` replace the `$questions[] = [ … ];` assignment with:

```php
                $entry = [
                    'question_id'         => (int) $q['question_id'],
                    'type'                => $q['type'],
                    'prompt'              => $q['prompt'],
                    'help_html'           => $this->markdown($q['help_md']),
                    'image_url'           => $imageUrls[(int) $q['image_id']] ?? null,
                    'required'            => (int) $q['required'],
                    'settings'            => $q['settings'],
                    'show_if_question_id' => $q['show_if_question_id'],
                    'show_if_option_id'   => $q['show_if_option_id'],
                    'options'             => $options,
                ];
                if ('pairwise' === $q['type']) {
                    // The runner's gate and bar read the server's plan, so what a
                    // respondent is told is exactly what submit enforces (spec §2).
                    $choices = 0;
                    foreach ($options as $o) {
                        if ('choice' === (string) ($o['role'] ?? 'choice')) {
                            $choices++;
                        }
                    }
                    $entry['pairwise'] = SurveyTypes::pairwisePlan($choices);
                }
                $questions[] = $entry;
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter 'SurveyTest'`
Expected: PASS (the whole class, so nothing existing regressed). `php -l` both PHP files.

- [ ] **Step 5: Commit**

```bash
git status --short && git diff --cached --stat
git add system/lib/ork3/class.Survey.php system/lib/ork3/class.SurveyResponse.php tests/Integration/SurveyTest.php
git commit -m "Enhancement: Survey — pairwise option rules, required gate on submit, plan in the respondent definition" -- system/lib/ork3/class.Survey.php system/lib/ork3/class.SurveyResponse.php tests/Integration/SurveyTest.php
```

---

### Task 4: Report aggregate and CSV cell

**Model:** sonnet · effort medium

**Files:**
- Modify: `system/lib/ork3/class.SurveyReport.php` (`aggregateType()` ~1107, new `aggPairwise()`, `attachAnswers()` ~2045, `displayAnswer()` ~2091)
- Test: `tests/Unit/SurveyAggregateTest.php`

**Interfaces:**
- Consumes: `SurveyTypes::pairwisePlan()`
- Produces:
  - `aggregateType('pairwise', $rows, $options, $settings)` → `{n, possible, judged, avg_count, avg_pct, options:[{option_id,label,appearances,wins,ties,losses,points,win_pct,rank}]}` sorted by win % desc, appearances desc, label; never-matched last with `win_pct`/`rank` null
  - `displayAnswer(string $type, array $rows, array $optionsById, int $choiceCount = 0): string`; pairwise cell `"{k} of {M}: Hawk > Owl; Wolf = Bear"`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/SurveyAggregateTest.php` (the file already has `opt()` and `row(int $responseId, ?int $optionId, ?float $valueNum, ?string $valueText, ?int $rowOptionId)` helpers):

```php
    // --------------------------------------------------------------- pairwise

    public function testAggregatePairwiseWinPctTiesAndRanks(): void
    {
        // A, B, C, D (4 options = 6 possible matchups).
        $options = [$this->opt(10, 'A'), $this->opt(11, 'B'), $this->opt(12, 'C'), $this->opt(13, 'D')];
        $rows = [
            // response 1: A beats B, A ties C, C beats B (right side wins)
            $this->row(1, 10, 1.0, null, 11),
            $this->row(1, 10, 0.5, null, 12),
            $this->row(1, 11, 0.0, null, 12),
            // response 2: B beats A, C beats A
            $this->row(2, 11, 1.0, null, 10),
            $this->row(2, 12, 1.0, null, 10),
        ];
        $a = SurveyReport::aggregateType('pairwise', $rows, $options, []);

        $this->assertSame(2, $a['n']);
        $this->assertSame(6, $a['possible']);
        $this->assertSame(5, $a['judged']);
        $this->assertSame(2.5, $a['avg_count']);
        $this->assertSame(41.7, $a['avg_pct']);   // (3/6 + 2/6) / 2

        $byLabel = [];
        foreach ($a['options'] as $o) {
            $byLabel[$o['label']] = $o;
        }
        // C: beat B, beat A, tied A = 2.5 / 3
        $this->assertSame(['appearances' => 3, 'wins' => 2, 'ties' => 1, 'losses' => 0, 'win_pct' => 83.3, 'rank' => 1],
            array_intersect_key($byLabel['C'], array_flip(['appearances', 'wins', 'ties', 'losses', 'win_pct', 'rank'])));
        // A: beat B, tied C, lost to B, lost to C = 1.5 / 4
        $this->assertSame(37.5, $byLabel['A']['win_pct']);
        // B: lost to A, lost to C, beat A = 1 / 3
        $this->assertSame(33.3, $byLabel['B']['win_pct']);
        // D never came up: unranked, last.
        $this->assertNull($byLabel['D']['win_pct']);
        $this->assertNull($byLabel['D']['rank']);
        $this->assertSame(['C', 'A', 'B', 'D'], array_column($a['options'], 'label'));
    }

    public function testAggregatePairwiseSharedRankAndTieBreaks(): void
    {
        $options = [$this->opt(10, 'Zed'), $this->opt(11, 'Amy'), $this->opt(12, 'Bo')];
        // Zed beats Bo, Amy beats Bo, Zed ties Amy: Zed and Amy both 1.5 / 2 = 75.0
        // over 2 appearances each, Bo 0 / 2.
        $rows = [
            $this->row(1, 10, 1.0, null, 12),
            $this->row(1, 11, 1.0, null, 12),
            $this->row(1, 10, 0.5, null, 11),
        ];
        $a = SurveyReport::aggregateType('pairwise', $rows, $options, []);
        $this->assertSame(['Amy', 'Zed', 'Bo'], array_column($a['options'], 'label'), 'equal win % sorts by label');
        $this->assertSame([1, 1, 3], array_column($a['options'], 'rank'), 'competition ranking: 1, 1, 3');
    }

    public function testAggregatePairwiseEmpty(): void
    {
        $a = SurveyReport::aggregateType('pairwise', [], [$this->opt(10, 'A'), $this->opt(11, 'B'), $this->opt(12, 'C')], []);
        $this->assertSame(0, $a['n']);
        $this->assertSame(3, $a['possible']);
        $this->assertNull($a['avg_pct']);
        $this->assertNull($a['avg_count']);
        $this->assertSame([null, null, null], array_column($a['options'], 'rank'));
    }

    public function testDisplayAnswerPairwiseWinnerFirst(): void
    {
        $byId = [10 => $this->opt(10, 'Hawk'), 11 => $this->opt(11, 'Owl'), 12 => $this->opt(12, 'Wolf')];
        $rows = [
            $this->row(1, 10, 1.0, null, 11),   // Hawk > Owl
            $this->row(1, 12, 0.5, null, 10),   // Wolf = Hawk
            $this->row(1, 11, 0.0, null, 12),   // Wolf > Owl
        ];
        $this->assertSame(
            '3 of 3: Hawk > Owl; Wolf = Hawk; Wolf > Owl',
            SurveyReport::displayAnswer('pairwise', $rows, $byId, 3)
        );
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter SurveyAggregateTest`
Expected: FAIL (`pairwise` falls to `['n' => 0]`; `displayAnswer` has no 4th parameter).

- [ ] **Step 3: Implement**

`aggregateType()`: add after the `ranking` case:

```php
            case 'pairwise':
                return self::aggPairwise($answerRows, $options);
```

Add after `aggRanking()`:

```php
    /**
     * pairwise (pairwise spec §7): one row per judged matchup, option_id the
     * left option, row_option_id the right, value_num the left's points (1,
     * 0.5, 0). Win % = points / appearances, ties counting half. Ranks are
     * competition ranks (1, 2, 2, 4) by win %; an option that never came up is
     * unranked and sorts last. n is respondents with at least one matchup.
     */
    private static function aggPairwise(array $rows, array $options): array
    {
        $stats = [];
        foreach ($options as $o) {
            if (($o['role'] ?? 'choice') !== 'choice') {
                continue;
            }
            $oid = (int)$o['option_id'];
            $stats[$oid] = [
                'option_id'   => $oid,
                'label'       => (string)$o['label'],
                'appearances' => 0,
                'wins'        => 0,
                'ties'        => 0,
                'losses'      => 0,
                'points'      => 0.0,
                'win_pct'     => null,
                'rank'        => null,
            ];
        }
        $plan = SurveyTypes::pairwisePlan(count($stats));

        $perResponse = [];
        $judged = 0;
        foreach ($rows as $r) {
            $left  = $r['option_id'] === null ? 0 : (int)$r['option_id'];
            $right = $r['row_option_id'] === null ? 0 : (int)$r['row_option_id'];
            if ($left === $right || !isset($stats[$left], $stats[$right]) || $r['value_num'] === null) {
                continue;   // a row for an option that no longer exists (defensive)
            }
            $p = (float)$r['value_num'];
            $judged++;
            $rid = (int)$r['response_id'];
            $perResponse[$rid] = ($perResponse[$rid] ?? 0) + 1;
            $stats[$left]['appearances']++;
            $stats[$right]['appearances']++;
            $stats[$left]['points']  += $p;
            $stats[$right]['points'] += 1.0 - $p;
            if ($p >= 1.0) {
                $stats[$left]['wins']++;
                $stats[$right]['losses']++;
            } elseif ($p <= 0.0) {
                $stats[$right]['wins']++;
                $stats[$left]['losses']++;
            } else {
                $stats[$left]['ties']++;
                $stats[$right]['ties']++;
            }
        }

        foreach ($stats as $oid => $s) {
            if ($s['appearances'] > 0) {
                $stats[$oid]['win_pct'] = round($s['points'] / $s['appearances'] * 100, 1);
            }
        }

        $list = array_values($stats);
        usort($list, static function (array $x, array $y): int {
            if (($x['win_pct'] === null) !== ($y['win_pct'] === null)) {
                return $x['win_pct'] === null ? 1 : -1;
            }
            if ($x['win_pct'] !== $y['win_pct']) {
                return $y['win_pct'] <=> $x['win_pct'];
            }
            if ($x['appearances'] !== $y['appearances']) {
                return $y['appearances'] <=> $x['appearances'];
            }
            return strcmp($x['label'], $y['label']);
        });

        $rank = 0;
        $prev = null;
        foreach ($list as $i => $s) {
            if ($s['win_pct'] === null) {
                break;
            }
            if ($prev === null || $s['win_pct'] !== $prev) {
                $rank = $i + 1;
                $prev = $s['win_pct'];
            }
            $list[$i]['rank'] = $rank;
        }

        $n = count($perResponse);
        $avgPct = null;
        if ($n > 0 && $plan['possible'] > 0) {
            $sum = 0.0;
            foreach ($perResponse as $count) {
                $sum += min(1.0, $count / $plan['possible']);
            }
            $avgPct = round($sum / $n * 100, 1);
        }

        return [
            'n'         => $n,
            'possible'  => $plan['possible'],
            'judged'    => $judged,
            'avg_count' => $n > 0 ? round($judged / $n, 1) : null,
            'avg_pct'   => $avgPct,
            'options'   => $list,
        ];
    }
```

`displayAnswer()`: change the signature to

```php
    public static function displayAnswer(string $type, array $rowsForQuestion, array $optionsById, int $choiceCount = 0): string
```

update its docblock with `@param int $choiceCount the question's choice-option count (pairwise needs it for "k of M")`, and add a case before `default:`:

```php
            case 'pairwise':
                $label = static function (?int $id) use ($optionsById): string {
                    return isset($optionsById[(int)$id]) ? (string)$optionsById[(int)$id]['label'] : ('#' . (int)$id);
                };
                $parts = [];
                foreach ($rowsForQuestion as $r) {
                    $a = $label($r['option_id']);
                    $b = $label($r['row_option_id']);
                    $p = (float)$r['value_num'];
                    // Winner first: "Hawk > Owl"; a tie keeps the shown order.
                    $parts[] = $p >= 1.0 ? $a . ' > ' . $b : ($p <= 0.0 ? $b . ' > ' . $a : $a . ' = ' . $b);
                }
                return count($rowsForQuestion) . ' of ' . SurveyTypes::pairwisePlan($choiceCount)['possible'] . ': '
                    . implode('; ', $parts);
```

`attachAnswers()`: after the `$optionsById` loop add

```php
        $choiceCounts = [];
        foreach ($options as $qid => $list) {
            foreach ($list as $o) {
                if ((string)$o['role'] === 'choice') {
                    $choiceCounts[(int)$qid] = ($choiceCounts[(int)$qid] ?? 0) + 1;
                }
            }
        }
```

and change the `displayAnswer(...)` call to pass `$choiceCounts[$qid] ?? 0` as the 4th argument.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter 'SurveyAggregateTest|SurveyTest'`
Expected: PASS. `php -l system/lib/ork3/class.SurveyReport.php`.

- [ ] **Step 5: Commit**

```bash
git status --short && git diff --cached --stat
git add system/lib/ork3/class.SurveyReport.php tests/Unit/SurveyAggregateTest.php
git commit -m "Enhancement: Survey — pairwise results: win %, shared ranks, average share of matchups, CSV cell" -- system/lib/ork3/class.SurveyReport.php tests/Unit/SurveyAggregateTest.php
```

---

### Task 5: JS core in `survey-render.js` and the node parity test

**Model:** sonnet · effort medium

**Files:**
- Modify: `orkui/template/default/script/survey-render.js` (constants after `NPS_MAX` ~line 237; functions in a new "pairwise core" block before `// ------------------------------------------------------------- type bodies`; exports ~line 1072)
- Create: `tests/Unit/js/survey-pairwise-harness.js`
- Create: `tests/Unit/SurveyPairwisePlanScriptTest.php`

**Interfaces:**
- Consumes: `SurveyTypes::pairwisePlan()` (the parity target)
- Produces on `window.SvRender`:
  - `pairwisePlan(optionCount) -> {possible, small, band_pcts, tiers, gate}` (same shape and key order as PHP)
  - `pairwiseGateMessage(plan) -> string`
  - `pairwiseStage(plan, doneCount, required) -> {level: 0..4, message: string, complete: bool}`
  - `pairwiseQueue(ids, done, rand|null) -> [{a, b}]`
  - `pairKey(a, b) -> 'min:max'`
  - `PW_BANDS`, `PW_SMALL_MAX`, `PW_TIER_MESSAGES` (4 strings), `PW_DONE_MESSAGE`, `PW_OPTIONAL_MESSAGE`

- [ ] **Step 1: Write the harness**

`tests/Unit/js/survey-pairwise-harness.js`:

```js
'use strict';
/* Loads survey-render.js under node with a bare window/document stub and
   prints JSON for SurveyPairwisePlanScriptTest: the JS plan for every option
   count 0..60, queue properties, and stage outcomes. */
var fs = require('fs');
var path = require('path');
var vm = require('vm');

var src = fs.readFileSync(path.join(__dirname, '../../../orkui/template/default/script/survey-render.js'), 'utf8');
var sandbox = { document: { addEventListener: function () {} } };
sandbox.window = sandbox;
vm.createContext(sandbox);
vm.runInContext(src, sandbox);
var R = sandbox.SvRender;

/* Deterministic PRNG so the queue checks never flake. */
function mulberry32(a) {
    return function () {
        a |= 0; a = a + 0x6D2B79F5 | 0;
        var t = Math.imul(a ^ a >>> 15, 1 | a);
        t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
        return ((t ^ t >>> 14) >>> 0) / 4294967296;
    };
}
function shares(m, n) { return m.a === n.a || m.a === n.b || m.b === n.a || m.b === n.b; }

var out = { plans: {}, queue: {}, stage: {} };
for (var n = 0; n <= 60; n++) { out.plans[n] = R.pairwisePlan(n); }

var ids = [11, 12, 13, 14, 15, 16, 17, 18];
var q = R.pairwiseQueue(ids, [], mulberry32(7));
var keys = {};
q.forEach(function (m) { keys[R.pairKey(m.a, m.b)] = true; });
out.queue.count = q.length;
out.queue.unique = Object.keys(keys).length;
out.queue.flipped = q.some(function (m) { return m.a > m.b; });

var done = [{ a: 12, b: 11, w: 12 }, { a: 13, b: 18, w: 0 }];
var q2 = R.pairwiseQueue(ids, done, mulberry32(9));
out.queue.afterDone = q2.length;
out.queue.containsDone = q2.some(function (m) { var k = R.pairKey(m.a, m.b); return k === '11:12' || k === '13:18'; });

var links = 0, repeats = 0;
for (var seed = 1; seed <= 50; seed++) {
    var qs = R.pairwiseQueue(ids, [], mulberry32(seed));
    for (var i = 1; i < qs.length; i++) { links++; if (shares(qs[i], qs[i - 1])) { repeats++; } }
}
out.queue.repeatRate = repeats / links;
out.queue.preview = R.pairwiseQueue([1, 2, 3], [], null);

var small = R.pairwisePlan(6);    // 15 matchups
var big = R.pairwisePlan(12);     // 66: tiers 20, 27, 33, 40
out.stage.smallReq0 = R.pairwiseStage(small, 0, true);
out.stage.smallOpt5 = R.pairwiseStage(small, 5, false);
out.stage.smallOpt10 = R.pairwiseStage(small, 10, false);
out.stage.small15 = R.pairwiseStage(small, 15, true);
out.stage.bigReq19 = R.pairwiseStage(big, 19, true);
out.stage.bigOpt0 = R.pairwiseStage(big, 0, false);
out.stage.big20 = R.pairwiseStage(big, 20, true);
out.stage.big27 = R.pairwiseStage(big, 27, true);
out.stage.big33 = R.pairwiseStage(big, 33, false);
out.stage.big40 = R.pairwiseStage(big, 40, false);
out.stage.big66 = R.pairwiseStage(big, 66, false);
out.gate = [R.pairwiseGateMessage(small), R.pairwiseGateMessage(big)];

process.stdout.write(JSON.stringify(out));
```

- [ ] **Step 2: Write the failing PHPUnit wrapper**

`tests/Unit/SurveyPairwisePlanScriptTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * survey-render.js's pairwise core under node (tests/Unit/js/survey-pairwise-harness.js).
 * The builder recomputes the plan in the browser from a JS copy of
 * SurveyTypes::PAIRWISE_BANDS; this pins that copy to the PHP for every option
 * count 0..60, and checks the queue and the encouragement stages (spec §2, §4, §6).
 */
final class SurveyPairwisePlanScriptTest extends TestCase
{
    private static ?array $out = null;

    private function harness(): array
    {
        if (self::$out !== null) {
            return self::$out;
        }
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }
        $raw = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/js/survey-pairwise-harness.js') . ' 2>&1');
        $res = json_decode(trim((string) $raw), true);
        $this->assertIsArray($res, 'harness output: ' . $raw);
        return self::$out = $res;
    }

    public function testJsPlanMatchesPhpForEveryOptionCountUpTo60(): void
    {
        $out = $this->harness();
        for ($n = 0; $n <= 60; $n++) {
            $this->assertSame(SurveyTypes::pairwisePlan($n), $out['plans'][$n], 'n=' . $n);
        }
    }

    public function testQueueCoversEveryPairOnceWithRandomSides(): void
    {
        $q = $this->harness()['queue'];
        $this->assertSame(28, $q['count']);
        $this->assertSame(28, $q['unique']);
        $this->assertTrue($q['flipped'], 'some matchups show the higher id on the left');
        $this->assertSame(26, $q['afterDone']);
        $this->assertFalse($q['containsDone'], 'answered pairs never come back, in either order');
    }

    public function testQueueRarelyRepeatsAnOptionBackToBack(): void
    {
        // A plain shuffle of 8 options repeats an option in ~44% of consecutive matchups.
        $this->assertLessThan(0.10, $this->harness()['queue']['repeatRate']);
    }

    public function testPreviewQueueKeepsAuthoredOrder(): void
    {
        $this->assertSame([['a' => 1, 'b' => 2], ['a' => 1, 'b' => 3], ['a' => 2, 'b' => 3]], $this->harness()['queue']['preview']);
    }

    public function testStagesAndMessages(): void
    {
        $s = $this->harness()['stage'];
        $this->assertSame(['level' => 0, 'message' => 'Finish all 15 matchups to continue.', 'complete' => false], $s['smallReq0']);
        $this->assertSame(['level' => 1, 'message' => '', 'complete' => false], $s['smallOpt5']);
        $this->assertSame(['level' => 2, 'message' => '', 'complete' => false], $s['smallOpt10']);
        $this->assertSame(['level' => 4, 'message' => 'Whoa, you ranked them all! Incredible job, we thank you!', 'complete' => true], $s['small15']);
        $this->assertSame(['level' => 0, 'message' => '1 more matchup to go before you can continue.', 'complete' => false], $s['bigReq19']);
        $this->assertSame(['level' => 0, 'message' => 'Every matchup helps. Do as many as you like.', 'complete' => false], $s['bigOpt0']);
        $this->assertSame('This is a great start. You can move on, but you can make our survey better by doing a few more matchups!', $s['big20']['message']);
        $this->assertSame(1, $s['big20']['level']);
        $this->assertSame('Even better! You can keep going for better results or continue.', $s['big27']['message']);
        $this->assertSame('Awesome! This is a great sample. Feel free to keep ranking or continue on.', $s['big33']['message']);
        $this->assertSame("Fantastic! You've given us a great sample size, so you can keep going or continue on. Your choice!", $s['big40']['message']);
        $this->assertSame(4, $s['big40']['level']);
        $this->assertTrue($s['big66']['complete']);
    }

    public function testGateMessagesMatchThePhp(): void
    {
        $this->assertSame(
            [SurveyTypes::pairwiseGateMessage(SurveyTypes::pairwisePlan(6)), SurveyTypes::pairwiseGateMessage(SurveyTypes::pairwisePlan(12))],
            $this->harness()['gate']
        );
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter SurveyPairwisePlanScriptTest`
Expected: FAIL (harness throws: `R.pairwisePlan is not a function`).

- [ ] **Step 4: Implement the core**

In `survey-render.js`, after `var NPS_MAX = 10;` add:

```js
    /* Pairwise (pairwise spec §2): mirrors SurveyTypes::PAIRWISE_SMALL_MAX and
       PAIRWISE_BANDS the way OTHER_MAX mirrors OTHER_MAX_LENGTH.
       SurveyPairwisePlanScriptTest pins this copy to the PHP. */
    var PW_SMALL_MAX = 30;
    var PW_BANDS = [
        [105,  [30, 40, 50, 60]],
        [200,  [20, 30, 40, 50]],
        [300,  [10, 20, 30, 40]],
        [null, [10, 15, 20, 25]]
    ];
    var PW_TIER_MESSAGES = [
        'This is a great start. You can move on, but you can make our survey better by doing a few more matchups!',
        'Even better! You can keep going for better results or continue.',
        'Awesome! This is a great sample. Feel free to keep ranking or continue on.',
        'Fantastic! You\'ve given us a great sample size, so you can keep going or continue on. Your choice!'
    ];
    var PW_DONE_MESSAGE = 'Whoa, you ranked them all! Incredible job, we thank you!';
    var PW_OPTIONAL_MESSAGE = 'Every matchup helps. Do as many as you like.';
```

Before the `// ------------------------------------------------------------- type bodies` divider add:

```js
    // ---------------------------------------------------------- pairwise core

    /** SurveyTypes::pairwisePlan(), key for key (integer ceilings, never floats). */
    function pairwisePlan(optionCount) {
        var n = Math.max(0, parseInt(optionCount, 10) || 0);
        var possible = n < 2 ? 0 : n * (n - 1) / 2;
        var pcts = [], tiers = [], i;
        if (possible <= PW_SMALL_MAX) {
            return { possible: possible, small: true, band_pcts: [], tiers: [], gate: possible };
        }
        for (i = 0; i < PW_BANDS.length; i++) {
            if (PW_BANDS[i][0] === null || possible <= PW_BANDS[i][0]) { pcts = PW_BANDS[i][1].slice(); break; }
        }
        for (i = 0; i < pcts.length; i++) { tiers.push(Math.floor((pcts[i] * possible + 99) / 100)); }
        return { possible: possible, small: false, band_pcts: pcts, tiers: tiers, gate: tiers[0] };
    }

    /** SurveyTypes::pairwiseGateMessage(): what a required question says below its gate. */
    function pairwiseGateMessage(plan) {
        return plan.small
            ? 'Please finish all ' + plan.possible + ' matchups to continue.'
            : 'Please complete at least ' + plan.gate + ' matchups to continue.';
    }

    /**
     * Where a respondent stands (spec §6). level 0-4 picks the bar colour;
     * message is the line under the bar ('' for none); complete once every
     * matchup is judged. Small sets colour by thirds, larger ones by tier.
     */
    function pairwiseStage(plan, doneCount, required) {
        var k = Math.max(0, parseInt(doneCount, 10) || 0), level = 0, i, left;
        if (plan.possible > 0 && k >= plan.possible) {
            return { level: 4, message: PW_DONE_MESSAGE, complete: true };
        }
        if (plan.small) {
            level = k * 3 >= plan.possible * 2 ? 2 : (k * 3 >= plan.possible ? 1 : 0);
            return { level: level, message: required ? 'Finish all ' + plan.possible + ' matchups to continue.' : '', complete: false };
        }
        for (i = 0; i < plan.tiers.length; i++) { if (k >= plan.tiers[i]) { level = i + 1; } }
        if (level > 0) { return { level: level, message: PW_TIER_MESSAGES[level - 1], complete: false }; }
        left = plan.gate - k;
        return {
            level: 0,
            message: required
                ? left + (left === 1 ? ' more matchup' : ' more matchups') + ' to go before you can continue.'
                : PW_OPTIONAL_MESSAGE,
            complete: false
        };
    }

    function pairKey(a, b) {
        a = parseInt(a, 10); b = parseInt(b, 10);
        return a < b ? a + ':' + b : b + ':' + a;
    }

    function sharesOption(m, n) { return m.a === n.a || m.a === n.b || m.b === n.a || m.b === n.b; }

    /**
     * The matchups still to judge (spec §4): every unordered pair of `ids` not
     * already in `done`, Fisher-Yates shuffled with a coin toss for sides, then
     * one greedy pass that swaps a later pair forward when the next one would
     * repeat an option from the matchup before it. rand = null keeps authored
     * order and sides (the builder preview).
     */
    function pairwiseQueue(ids, done, rand) {
        var seen = {}, out = [], i, j, k, t, prev;
        (done || []).forEach(function (m) { seen[pairKey(m.a, m.b)] = true; });
        for (i = 0; i < ids.length; i++) {
            for (j = i + 1; j < ids.length; j++) {
                if (!seen[pairKey(ids[i], ids[j])]) { out.push({ a: ids[i], b: ids[j] }); }
            }
        }
        if (!rand) { return out; }
        for (i = out.length - 1; i > 0; i--) {
            j = Math.floor(rand() * (i + 1));
            t = out[i]; out[i] = out[j]; out[j] = t;
        }
        for (i = 0; i < out.length; i++) {
            if (rand() < 0.5) { t = out[i].a; out[i].a = out[i].b; out[i].b = t; }
        }
        prev = (done && done.length) ? done[done.length - 1] : null;
        for (i = 0; i < out.length; i++) {
            if (prev && sharesOption(out[i], prev)) {
                for (k = i + 1; k < out.length; k++) {
                    if (!sharesOption(out[k], prev)) { t = out[i]; out[i] = out[k]; out[k] = t; break; }
                }
            }
            prev = out[i];
        }
        return out;
    }
```

In the `window.SvRender = { … }` export object add:

```js
        pairwisePlan: pairwisePlan,
        pairwiseGateMessage: pairwiseGateMessage,
        pairwiseStage: pairwiseStage,
        pairwiseQueue: pairwiseQueue,
        pairKey: pairKey,
        PW_SMALL_MAX: PW_SMALL_MAX,
        PW_BANDS: PW_BANDS,
        PW_TIER_MESSAGES: PW_TIER_MESSAGES,
        PW_DONE_MESSAGE: PW_DONE_MESSAGE,
        PW_OPTIONAL_MESSAGE: PW_OPTIONAL_MESSAGE,
```

Also update the file's header comment's "Public API" list with one line per new export.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `node --check orkui/template/default/script/survey-render.js && ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter 'SurveyPairwisePlanScriptTest|SurveyTypesTest'`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git status --short && git diff --cached --stat
git add orkui/template/default/script/survey-render.js tests/Unit/js/survey-pairwise-harness.js tests/Unit/SurveyPairwisePlanScriptTest.php
git commit -m "Enhancement: Survey — pairwise plan, queue and stages in the shared renderer, pinned to the PHP" -- orkui/template/default/script/survey-render.js tests/Unit/js/survey-pairwise-harness.js tests/Unit/SurveyPairwisePlanScriptTest.php
```

---

## Phase 2: Surfaces

### Task 6: Runner widget, client gate, styles

**Model:** opus · effort high

**Files:**
- Modify: `orkui/template/default/script/survey-render.js`
- Modify: `orkui/template/default/script/survey-take.js` (`validateQuestion()` ~line 406)
- Modify: `orkui/template/default/style/survey.css`

**Interfaces:**
- Consumes: Task 5's core; `q.pairwise` from the respondent definition (Task 3)
- Produces:
  - `SvRender.question()` renders `type: 'pairwise'` (both `take` and `preview`)
  - `SvRender.read(root, q)` → `[{a, b, w}, …]` in answer order, or `undefined` before the first pick
  - `SvRender.write(root, q, value)` restores a list and rebuilds the queue
  - Every pick and undo dispatches a bubbling `change` from `.sv-pw`, so the runner's existing `onStageChange → collect → draftSaveSoon` path records it
  - CSS tokens `--sv-pw-0` … `--sv-pw-4` (light + dark)

Markup contract (add it to the header comment's "HTML STRUCTURE PER TYPE" and "read() CONTRACT" sections):

```
pairwise ─ two options at a time, a Tie between, a progress bar under
  <div class="sv-choice-hint" id="sv-hint-12-N">Pick the one you prefer…</div>
  <div class="sv-pw" data-pw="svqN_12" role="group" aria-labelledby="sv-p-12" …>
    <div class="sv-pw-stage" [data-chose="a|b|tie"] [hidden when complete]>
      <button type="button" class="sv-pw-pick sv-pw-a" data-pw-pick="a"><span class="sv-pw-label">Hawk</span></button>
      <button type="button" class="sv-pw-tie" data-pw-pick="tie">Tie</button>
      <button type="button" class="sv-pw-pick sv-pw-b" data-pw-pick="b"><span class="sv-pw-label">Owl</span></button>
    </div>
    <p class="sv-pw-done" tabindex="-1" [hidden until complete]>…100% message…</p>
    <div class="sv-pw-progress">
      <div class="sv-pw-bar" role="progressbar" aria-valuemin="0" aria-valuemax="M"
           aria-valuenow="k" aria-valuetext="k of M matchups" data-level="0-4">
        <span class="sv-pw-fill" style="width:…%"></span>
        <span class="sv-pw-tick" style="left:…%"></span> ×4   (sets over 30 only)
      </div>
      <div class="sv-pw-meta"><span class="sv-pw-count">12 of 66 matchups</span>
        <button type="button" class="sv-pw-undo" [hidden]>Undo</button></div>
      <p class="sv-pw-msg" aria-live="polite">stage message</p>
    </div>
  </div>
read(): [{a, b, w}, …] in answer order (w = winner id, 0 = tie); undefined before the first pick.
```

- [ ] **Step 1: Renderer, catalog and ctx changes**

In `survey-render.js`:
1. Add `'pairwise'` to the `ANSWERABLE` array after `'ranking'`.
2. In `question()`, add `preview: preview,` to the `ctx` object literal.
3. In `hintFor()`, add as the first line: `if (type === 'pairwise') { return 'Pick the one you prefer, or call it a tie. The arrow keys work too.'; }`
4. In `question()`'s `switch (type)`, add `case 'pairwise':  body = bodyPairwise(q, state, ctx); break;` after `ranking`.
5. In `read()`, add `case 'pairwise':  return readPairwise(root);`. In `write()`, add `case 'pairwise':  writePairwise(root, q, value); break;`.
6. Add `.sv-pw` to `INVALID_TARGETS`.

- [ ] **Step 2: The widget**

Add after `bodyRanking()`:

```js
    // ------------------------------------------------------------- pairwise

    var PW = {};                  // data-pw key -> the live state of one rendered pairwise question
    var PW_FLASH_MS = 140;        // how long the picked side stays lit before the next matchup

    function pwIds(q) {
        return optionsOf(q, 'choice').map(function (o) { return parseInt(o.option_id, 10); });
    }

    /** A restored answer, cleaned: known ids, a != b, w in {a, b, 0}, each pair once, order kept. */
    function pwClean(value, ids) {
        var known = {}, seen = {}, out = [];
        ids.forEach(function (id) { known[id] = true; });
        (Array.isArray(value) ? value : []).forEach(function (m) {
            var a, b, w, k;
            if (!m || typeof m !== 'object') { return; }
            a = parseInt(m.a, 10); b = parseInt(m.b, 10); w = parseInt(m.w, 10);
            if (!known[a] || !known[b] || a === b || !(w === a || w === b || w === 0)) { return; }
            k = pairKey(a, b);
            if (seen[k]) { return; }
            seen[k] = true;
            out.push({ a: a, b: b, w: w });
        });
        return out;
    }

    function pwState(el) { return el ? (PW[el.getAttribute('data-pw')] || null) : null; }

    /** Everything the widget shows, derived from its state (the string render and repaints share it). */
    function pwView(st) {
        var k = st.done.length, m = st.queue[0] || null;
        var stage = pairwiseStage(st.plan, k, st.required);
        return {
            labelA: m ? st.labels[m.a] : '',
            labelB: m ? st.labels[m.b] : '',
            pct: st.plan.possible ? Math.min(100, k / st.plan.possible * 100) : 0,
            count: k + ' of ' + st.plan.possible + ' matchups',
            level: stage.level,
            message: m ? stage.message : '',
            complete: !m
        };
    }

    function bodyPairwise(q, state, ctx) {
        var ids = pwIds(q), labels = {}, st, v, i, html;
        optionsOf(q, 'choice').forEach(function (o) { labels[parseInt(o.option_id, 10)] = String(o.label || ''); });
        st = {
            plan: (q.pairwise && typeof q.pairwise === 'object') ? q.pairwise : pairwisePlan(ids.length),
            required: ctx.required,
            labels: labels,
            done: pwClean(state, ids),
            queue: [],
            busy: false
        };
        st.queue = pairwiseQueue(ids, st.done, ctx.preview ? null : Math.random);
        PW[ctx.name] = st;
        v = pwView(st);

        html = ctx.hint ? '<div class="sv-choice-hint" id="' + ctx.hintId + '">' + escapeHtml(ctx.hint) + '</div>' : '';
        html += '<div class="sv-pw" data-pw="' + ctx.name + '" role="group" aria-labelledby="' + ctx.promptId + '"' +
                ctx.req + ctx.desc + '>';
        html += '<div class="sv-pw-stage"' + (v.complete ? ' hidden' : '') + '>';
        html += '<button type="button" class="sv-pw-pick sv-pw-a" data-pw-pick="a"' + ctx.tab + '>' +
                '<span class="sv-pw-label">' + escapeHtml(v.labelA) + '</span></button>';
        html += '<button type="button" class="sv-pw-tie" data-pw-pick="tie"' + ctx.tab + '>Tie</button>';
        html += '<button type="button" class="sv-pw-pick sv-pw-b" data-pw-pick="b"' + ctx.tab + '>' +
                '<span class="sv-pw-label">' + escapeHtml(v.labelB) + '</span></button>';
        html += '</div>';
        html += '<p class="sv-pw-done" tabindex="-1"' + (v.complete ? '' : ' hidden') + '>' +
                '<i class="fas fa-trophy" aria-hidden="true"></i> ' + escapeHtml(PW_DONE_MESSAGE) + '</p>';
        html += '<div class="sv-pw-progress">';
        html += '<div class="sv-pw-bar" role="progressbar" aria-label="Matchups done" aria-valuemin="0" aria-valuemax="' +
                st.plan.possible + '" aria-valuenow="' + st.done.length + '" aria-valuetext="' + escapeHtml(v.count) +
                '" data-level="' + v.level + '">';
        html += '<span class="sv-pw-fill" style="width:' + v.pct.toFixed(2) + '%"></span>';
        for (i = 0; i < st.plan.tiers.length; i++) {
            html += '<span class="sv-pw-tick" aria-hidden="true" style="left:' +
                    (st.plan.tiers[i] / st.plan.possible * 100).toFixed(2) + '%"></span>';
        }
        html += '</div>';
        html += '<div class="sv-pw-meta"><span class="sv-pw-count" aria-hidden="true">' + escapeHtml(v.count) + '</span>' +
                '<button type="button" class="sv-pw-undo"' + (st.done.length ? '' : ' hidden') + ctx.tab + '>' +
                '<i class="fas fa-rotate-left" aria-hidden="true"></i> Undo</button></div>';
        html += '<p class="sv-pw-msg" aria-live="polite">' + escapeHtml(v.message) + '</p>';
        html += '</div></div>';
        return html;
    }

    /** Repaint in place: the buttons stay put, so focus never drops to <body> mid-question. */
    function pwPaint(el, st, moveFocus) {
        var v = pwView(st);
        var stage = el.querySelector('.sv-pw-stage');
        var done = el.querySelector('.sv-pw-done');
        var bar = el.querySelector('.sv-pw-bar');
        var undo = el.querySelector('.sv-pw-undo');
        var msg = el.querySelector('.sv-pw-msg');
        var active = document.activeElement;
        var wasHidden = stage.hidden;

        el.querySelector('.sv-pw-a .sv-pw-label').textContent = v.labelA;
        el.querySelector('.sv-pw-b .sv-pw-label').textContent = v.labelB;
        stage.hidden = v.complete;
        done.hidden = !v.complete;
        el.querySelector('.sv-pw-fill').style.width = v.pct.toFixed(2) + '%';
        bar.setAttribute('aria-valuenow', String(st.done.length));
        bar.setAttribute('aria-valuetext', v.count);
        bar.setAttribute('data-level', String(v.level));
        el.querySelector('.sv-pw-count').textContent = v.count;
        undo.hidden = st.done.length === 0;
        if (msg.textContent !== v.message) { msg.textContent = v.message; }

        if (!moveFocus) { return; }
        if (v.complete) {
            done.focus();
            return;
        }
        if (wasHidden || active === undo && undo.hidden) { el.querySelector('.sv-pw-a').focus(); }
        // The focused button's label changed under it; say the new matchup (the module's one polite region).
        rankLive(v.labelA + ' or ' + v.labelB + '?');
    }

    function reducedMotion() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function pairwisePick(el, side) {
        var st = pwState(el), m, stage;
        if (!st || st.busy || !st.queue.length) { return; }
        m = st.queue.shift();
        st.done.push({ a: m.a, b: m.b, w: side === 'a' ? m.a : (side === 'b' ? m.b : 0) });
        fireChange(el);                       // recorded now, whatever the animation does
        if (reducedMotion()) { pwPaint(el, st, true); return; }
        stage = el.querySelector('.sv-pw-stage');
        st.busy = true;
        stage.setAttribute('data-chose', side);
        window.setTimeout(function () {
            st.busy = false;
            stage.removeAttribute('data-chose');
            pwPaint(el, st, true);
        }, PW_FLASH_MS);
    }

    function pairwiseUndo(el) {
        var st = pwState(el), last;
        if (!st || st.busy || !st.done.length) { return; }
        last = st.done.pop();
        st.queue.unshift({ a: last.a, b: last.b });
        fireChange(el);
        pwPaint(el, st, true);
    }

    function readPairwise(root) {
        var st = pwState(root.querySelector('.sv-pw'));
        if (!st || !st.done.length) { return undefined; }
        return st.done.map(function (m) { return { a: m.a, b: m.b, w: m.w }; });
    }

    function writePairwise(root, q, value) {
        var el = root.querySelector('.sv-pw'), st = pwState(el), ids = pwIds(q);
        if (!st) { return; }
        st.done = pwClean(value, ids);
        st.queue = pairwiseQueue(ids, st.done, root.classList.contains('sv-q-preview') ? null : Math.random);
        pwPaint(el, st, false);
    }
```

- [ ] **Step 3: Clicks and keys**

In `onDocClick()`, before the `var rankBtn = …` line:

```js
        var pwBtn = t.closest('[data-pw-pick], .sv-pw-undo');
        if (pwBtn) {
            var pw = pwBtn.closest('.sv-pw');
            if (pw && !pw.closest('.sv-q-preview')) {
                if (pwBtn.classList.contains('sv-pw-undo')) {
                    pairwiseUndo(pw);
                } else {
                    pairwisePick(pw, pwBtn.getAttribute('data-pw-pick'));
                }
            }
            e.preventDefault();
            return;
        }
```

After `onDocClick()` add, and register it next to the click listener:

```js
    /* ← picks left, → picks right, ↓ ties: only while focus is on the matchup
       itself, so arrow keys never get taken from a text field or the page. */
    function onDocKeydown(e) {
        var t = e.target, side, pw;
        if (!t || !t.closest || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) { return; }
        if (!t.closest('.sv-pw-stage')) { return; }
        side = e.key === 'ArrowLeft' ? 'a' : (e.key === 'ArrowRight' ? 'b' : (e.key === 'ArrowDown' ? 'tie' : null));
        if (!side) { return; }
        pw = t.closest('.sv-pw');
        if (!pw || pw.closest('.sv-q-preview')) { return; }
        e.preventDefault();
        pairwisePick(pw, side);
    }

    document.addEventListener('keydown', onDocKeydown, false);
```

- [ ] **Step 4: The runner's client gate**

In `survey-take.js` `validateQuestion()`, add `plan` to the `var count, min, max, rows, answeredRows, k;` line, and directly after `if (!R.isAnswerable(type)) { return null; }` insert:

```js
        /* Pairwise owns its empty case too: a required one names the gate, as
           SurveyTypes::validatePairwise() does, rather than "required". */
        if (type === 'pairwise') {
            plan = q.pairwise || R.pairwisePlan((q.options || []).filter(function (o) {
                return String(o.role || 'choice') === 'choice';
            }).length);
            count = Array.isArray(value) ? value.length : 0;
            return required && count < plan.gate ? R.pairwiseGateMessage(plan) : null;
        }
```

- [ ] **Step 5: Styles**

In `survey.css`, add to the `.sv-root, .sv-scope { … }` token block:

```css
    /* Pairwise progress (pairwise spec §6): red → yellow → yellow-green → green → dark green. */
    --sv-pw-0: #e53e3e;
    --sv-pw-1: #ecc94b;
    --sv-pw-2: #9ae66e;
    --sv-pw-3: #38a169;
    --sv-pw-4: #276749;
    --sv-pw-track: var(--sv-line-2);
```

and to the `html[data-theme="dark"] .sv-root, html[data-theme="dark"] .sv-scope { … }` block:

```css
    --sv-pw-0: #f56565;
    --sv-pw-1: #ecc94b;
    --sv-pw-2: #b5e48c;
    --sv-pw-3: #68d391;
    --sv-pw-4: #38a169;
    --sv-pw-track: #1f2733;
```

After the ranking rules (the `.sv-rank*` block) add:

```css
/* --------------------------------------------------------------- pairwise */

.sv-pw { display: flex; flex-direction: column; gap: 12px; }

.sv-pw-stage {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
    align-items: stretch;
    gap: 10px;
}
.sv-pw-pick {
    min-height: 88px;
    padding: 12px 14px;
    border: 1px solid var(--sv-line-3);
    border-radius: var(--sv-radius-l3);
    background: var(--sv-l3);
    color: var(--sv-text);
    font: inherit;
    font-size: var(--ork-font-size-base);
    font-weight: 600;
    line-height: 1.35;
    text-align: center;
    overflow-wrap: anywhere;
    cursor: pointer;
    transition: border-color .12s, background-color .12s, transform .12s;
}
.sv-pw-pick:hover { border-color: var(--sv-accent); background: var(--sv-l3-hover); }
.sv-pw-tie {
    align-self: center;
    min-height: 44px;
    min-width: 64px;
    padding: 0 14px;
    border: 1px dashed var(--sv-line-3);
    border-radius: 999px;
    background: transparent;
    color: var(--sv-text-body);
    font: inherit;
    font-size: var(--ork-font-size-sm);
    cursor: pointer;
}
.sv-pw-tie:hover { border-style: solid; border-color: var(--sv-accent); color: var(--sv-text); }
.sv-pw-pick:focus-visible,
.sv-pw-tie:focus-visible,
.sv-pw-undo:focus-visible,
.sv-pw-done:focus-visible { outline: 2px solid var(--sv-accent); outline-offset: 2px; }

.sv-pw-stage[data-chose="a"] .sv-pw-a,
.sv-pw-stage[data-chose="b"] .sv-pw-b,
.sv-pw-stage[data-chose="tie"] .sv-pw-pick {
    border-color: var(--sv-accent);
    background: var(--sv-accent-soft);
    transform: scale(.98);
}

.sv-pw-done {
    margin: 0;
    padding: 14px;
    border-radius: var(--sv-radius-l3);
    background: var(--sv-accent-soft);
    color: var(--sv-text);
    font-weight: 600;
    text-align: center;
}
.sv-pw-done .fa-trophy { color: var(--sv-star-on); }

.sv-pw-bar {
    position: relative;
    height: 10px;
    border-radius: 999px;
    background: var(--sv-pw-track);
    overflow: visible;
}
.sv-pw-fill {
    display: block;
    height: 100%;
    border-radius: 999px;
    background: var(--sv-pw-0);
    transition: width .2s ease, background-color .2s ease;
}
.sv-pw-bar[data-level="1"] .sv-pw-fill { background: var(--sv-pw-1); }
.sv-pw-bar[data-level="2"] .sv-pw-fill { background: var(--sv-pw-2); }
.sv-pw-bar[data-level="3"] .sv-pw-fill { background: var(--sv-pw-3); }
.sv-pw-bar[data-level="4"] .sv-pw-fill { background: var(--sv-pw-4); }
.sv-pw-tick {
    position: absolute;
    top: -3px;
    bottom: -3px;
    width: 2px;
    margin-left: -1px;
    border-radius: 1px;
    background: var(--sv-text-muted);
    opacity: .7;
}

.sv-pw-meta { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.sv-pw-count { font-size: var(--ork-font-size-sm); color: var(--sv-text-muted); font-variant-numeric: tabular-nums; }
.sv-pw-undo {
    min-height: 32px;
    padding: 0 6px;
    border: 0;
    background: none;
    color: var(--sv-accent);
    font: inherit;
    font-size: var(--ork-font-size-sm);
    cursor: pointer;
}
.sv-pw-msg { margin: 0; min-height: 1.4em; font-size: var(--ork-font-size-sm); color: var(--sv-text-body); }

@media (max-width: 480px) {
    .sv-pw-stage { grid-template-columns: minmax(0, 1fr); }
    .sv-pw-pick { min-height: 64px; }
    .sv-pw-tie { justify-self: center; }
}
@media (pointer: coarse) {
    .sv-pw-undo { min-height: 44px; padding: 0 10px; }
}
@media (prefers-reduced-motion: reduce) {
    .sv-pw-pick, .sv-pw-fill { transition: none; }
    .sv-pw-stage[data-chose] .sv-pw-pick { transform: none; }
}
```

Keep the fill color tokens out of text: stages are carried by the message text and the tick marks, not color alone.

- [ ] **Step 6: Verify**

```bash
node --check orkui/template/default/script/survey-render.js && node --check orkui/template/default/script/survey-take.js
ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter 'SurveyPairwisePlanScriptTest|SurveyCreditPanelScriptTest'
```
Expected: clean, PASS.

Smoke test in a browser (headless Playwright, or the dev browser if logged in). Build a fixture with `docker exec ork3-php8-app php /var/www/ork.amtgard.com/bin/seed-survey-example.php --kingdom=17 --title="Pairwise smoke $(date +%s)"` (draft, no responses). Task 9 adds a pairwise question to the seed; until then, add one in the builder (`index.php?Route=Survey/build/<id>`) with 9+ options. For preview, open `index.php?Route=Survey/take/<id>/preview` (managers only; nothing is saved). For the draft-resume check, open the survey (`setStatus` open via the builder) and use `Survey/take/<id>`; `allow_resume` defaults to 1. Check that:
- Picking left, right and Tie advances the matchup and the count.
- Undo walks back.
- ← / → / ↓ work while a matchup button has focus.
- The bar color and message change at the thresholds.
- The draft survives a reload (allow_resume on).

- [ ] **Step 7: Commit**

```bash
git status --short && git diff --cached --stat
git add orkui/template/default/script/survey-render.js orkui/template/default/script/survey-take.js orkui/template/default/style/survey.css
git commit -m "Enhancement: Survey — pairwise runner: matchups, Tie, Undo, arrow keys, encouragement bar and required gate" -- orkui/template/default/script/survey-render.js orkui/template/default/script/survey-take.js orkui/template/default/style/survey.css
```

---

### Task 7: Builder: paste textarea, readout, warning, (?) modal

**Model:** opus · effort high

**Files:**
- Modify: `orkui/template/default/script/survey-build.js`
- Modify: `orkui/template/default/style/survey-build.css`

**Interfaces:**
- Consumes: `SvRender.pairwisePlan`, `SvRender.PW_BANDS`, `SvRender.PW_TIER_MESSAGES`, `SvRender.PW_DONE_MESSAGE`, `SvRender.PW_OPTIONAL_MESSAGE` (Task 5); `option_set` (existing; Task 3's duplicate/other refusals come back as `data.error`)
- Produces: a pairwise card whose options are one `.svb-pw-lines` textarea saved through `save('opts:<qid>:choice', 'option_set', …)`

- [ ] **Step 1: Catalog entries**

- `TYPE_META`: add after `ranking`: `pairwise:   { label: 'Pairwise',        icon: 'fa-code-compare',      hint: 'Pick the better of two, many times.' },`
- `ROLE_UI_BY_TYPE`: add `pairwise: { choice: { other: false, min: 3 } }` and update the comment above it.
- `FIELD_DEFS`: add `pairwise: [],` after `ranking`.
- `typeEditorHtml()`: add `case 'pairwise': return pairwiseEditorHtml(q);` before `case 'matrix':`.
- `locOf()`: before the `} else if (node.classList.contains('svb-opts')) {` branch add
  ```js
        } else if (node.classList.contains('svb-pw-lines')) {
            sel2 = '.svb-pw-lines';
  ```

- [ ] **Step 2: Editor, readout and commit**

Add after `matrixEditorHtml()`:

```js
    /* ------------------------------------------------------- pairwise editor */

    /** One option per line: trimmed, list bullets stripped, blanks dropped, 255 chars max. */
    function pairwiseLines(text) {
        return String(text || '').split(/\r\n|\r|\n/).map(function (line) {
            return line.replace(/^\s*[-*•]\s+/, '').trim().slice(0, 255);
        }).filter(function (line) { return line !== ''; });
    }

    function pairwiseReadout(n) {
        var p = SvRender.pairwisePlan(n);
        if (n < 3) { return 'Add at least 3 options, one per line.'; }
        return n + ' options → ' + p.possible.toLocaleString() + ' matchups · required respondents do ' +
               (p.small ? 'all ' + p.possible : 'at least ' + p.gate.toLocaleString());
    }

    function pairwiseWarning(n) {
        return n > 30
            ? 'Over 30 options makes for a long question: ' + n + ' options is ' +
              SvRender.pairwisePlan(n).possible.toLocaleString() + ' matchups.'
            : '';
    }

    /**
     * Pairwise options are one textarea, one per line (pairwise spec §5), so a
     * list can be pasted in one go. The readout under it recomputes the plan
     * live; the (?) explains the gate and the bands with this question's numbers.
     */
    function pairwiseEditorHtml(q) {
        var qid   = parseInt(q.question_id, 10);
        var lines = optionsOf(q, 'choice').map(function (o) { return String(o.label || ''); });
        var id    = 'svb-pw-lines-' + qid;
        var warn  = pairwiseWarning(lines.length);
        var html  = '<div class="svb-field svb-pw">';
        html += '<label class="svb-label" for="' + id + '">Options, one per line</label>';
        html += '<textarea class="sv-textarea svb-pw-lines svb-autogrow" id="' + id + '" rows="' +
                Math.min(14, Math.max(4, lines.length + 1)) + '" spellcheck="true">' + esc(lines.join('\n')) + '</textarea>';
        html += '<div class="svb-pw-foot">';
        html += '<p class="svb-hint svb-pw-readout" aria-live="polite">' + esc(pairwiseReadout(lines.length)) + '</p>';
        html += '<button type="button" class="svb-icon-btn svb-pw-help" data-act="pw-help" ' +
                'data-tip="How pairwise questions work" aria-label="How pairwise questions work">' +
                '<i class="fas fa-circle-question" aria-hidden="true"></i></button>';
        html += '</div>';
        html += '<p class="svb-pw-warn"' + (warn ? '' : ' hidden') + '>' +
                '<i class="fas fa-triangle-exclamation" aria-hidden="true"></i> <span>' + esc(warn) + '</span></p>';
        if (S.locked) { html += '<p class="svb-hint">Wording fixes only, while the survey is open.</p>'; }
        html += '</div>';
        return html;
    }

    function paintPairwiseReadout(area) {
        var wrap = area.closest('.svb-pw'), n = pairwiseLines(area.value).length, warn, text;
        if (!wrap) { return; }
        el('.svb-pw-readout', wrap).textContent = pairwiseReadout(n);
        warn = el('.svb-pw-warn', wrap);
        text = pairwiseWarning(n);
        el('span', warn).textContent = text;
        warn.hidden = text === '';
    }

    /**
     * Save the textarea through option_set (replace-all). Unlocked, a line that
     * matches a saved label reuses that option's id, so reordering or inserting
     * lines keeps ids; locked, line i is option i and only wording may change.
     * A duplicate, too few lines, or a locked line-count change is held inline
     * and the pill reads "Not saved" until it is fixed.
     */
    function commitPairwise(questionId) {
        var card  = cardEl(questionId);
        var area  = card ? el('.svb-pw-lines', card) : null;
        var q     = questionById(questionId);
        var key   = 'opts:' + questionId + ':choice';
        var seen  = {}, byLabel = {}, used = {}, dup = null, problem = '', lines, saved, list;
        if (!area || !q) { return; }

        lines = pairwiseLines(area.value);
        saved = optionsOf(q, 'choice');
        lines.forEach(function (l) {
            var k = l.toLowerCase();
            if (seen[k] && dup === null) { dup = l; }
            seen[k] = true;
        });
        if (dup !== null) {
            problem = '“' + dup + '” is listed twice.';
        } else if (lines.length < 3) {
            problem = 'A pairwise question needs at least 3 options.';
        } else if (S.locked && lines.length !== saved.length) {
            problem = 'While the survey is open you can fix wording, but not add or remove options.';
        }
        if (problem) {
            held[key] = { msg: '', loc: locOf(area) };
            fieldError(area, problem);
            refreshPill();
            return;
        }
        delete held[key];
        fieldError(area, '');

        if (S.locked) {
            list = lines.map(function (l, i) {
                return { option_id: parseInt(saved[i].option_id, 10), label: l, value_num: null, is_other: 0 };
            });
        } else {
            saved.forEach(function (o) {
                var k = String(o.label || '').trim();
                var oid = parseInt(o.option_id, 10) || 0;
                if (oid && !Object.prototype.hasOwnProperty.call(byLabel, k)) { byLabel[k] = oid; }
            });
            list = lines.map(function (l) {
                var oid = Object.prototype.hasOwnProperty.call(byLabel, l) ? byLabel[l] : 0;
                if (oid && used[oid]) { oid = 0; }
                if (oid) { used[oid] = true; }
                return { option_id: oid, label: l, value_num: null, is_other: 0 };
            });
        }

        // S follows the textarea at once, so a preview drawn before the reply shows what was typed.
        q.options = list.map(function (o, i) {
            return { option_id: o.option_id, question_id: q.question_id, role: 'choice', sort_order: i,
                     label: o.label, value_num: null, is_other: 0 };
        });

        save(key, 'option_set', {
            QuestionId: questionId,
            Role:       'choice',
            Options:    JSON.stringify(list)
        }, function (data) {
            var cur = questionById(questionId);
            if (!cur) { return; }
            cur.options = data.options || [];
            if (parseInt(questionId, 10) !== sel) { refreshCard(questionId); }
        }, { node: area });
        refreshPill();
    }
```

- [ ] **Step 3: Wire input and paste**

In `onCanvasInput()`, before the `svb-optlabel` branch:

```js
        if (t.classList && t.classList.contains('svb-pw-lines') && q) {
            paintPairwiseReadout(t);
            commitPairwise(q.question_id);
            return;
        }
```

At the top of `onCanvasPaste()` (right after the `var` line):

```js
        /* A pasted list lands clean: bullets stripped and blank lines dropped,
           the same rule the option-row paste uses. */
        if (t.classList && t.classList.contains('svb-pw-lines') && q) {
            cb   = e.clipboardData || window.clipboardData;
            text = cb ? String(cb.getData('text') || '') : '';
            if (!/[\r\n]/.test(text) && !/^\s*[-*•]\s+/.test(text)) { return; }
            e.preventDefault();
            lines = pairwiseLines(text).join('\n');
            start = typeof t.selectionStart === 'number' ? t.selectionStart : t.value.length;
            end   = typeof t.selectionEnd === 'number' ? t.selectionEnd : t.value.length;
            t.value = t.value.slice(0, start) + lines + t.value.slice(end);
            try { t.setSelectionRange(start + lines.length, start + lines.length); } catch (err) { /* not text */ }
            autoGrow(t);
            fire(t, 'input');
            return;
        }
```

- [ ] **Step 4: The (?) modal**

In `handleAct()`'s `switch (act)`, add:

```js
            case 'pw-help':
                if (!q) { return; }
                area = el('.svb-pw-lines', cardEl(q.question_id));
                openModal('How pairwise questions work',
                          pairwiseHelpHtml(area ? pairwiseLines(area.value).length : optionsOf(q, 'choice').length));
                break;
```

Add after `commitPairwise()`:

```js
    /** The (?) explanation (pairwise spec §5 Help), built from this question's plan. */
    function pairwiseHelpHtml(n) {
        var plan = SvRender.pairwisePlan(n), bands = SvRender.PW_BANDS, lo = 31, rows = '', sentence, stages, i;

        if (n < 3) {
            sentence = 'Add at least 3 options to see this question\'s numbers.';
        } else if (plan.small) {
            sentence = 'Your ' + n + ' options make ' + plan.possible + ' matchups. Respondents see a plain progress bar, ' +
                       'and a required question asks for all ' + plan.possible + '.';
        } else {
            sentence = 'Your ' + n + ' options make ' + plan.possible.toLocaleString() + ' matchups. Required respondents do at least ' +
                       plan.gate + ' (' + plan.band_pcts[0] + '%), then see encouragement at ' +
                       plan.tiers[1] + ', ' + plan.tiers[2] + ' and ' + plan.tiers[3] + '.';
        }

        rows += '<tr' + (n >= 3 && plan.small ? ' class="svb-pw-here"' : '') + '><th scope="row">30 or fewer</th>' +
                '<td colspan="4">A plain bar; required means every matchup</td></tr>';
        for (i = 0; i < bands.length; i++) {
            var hi = bands[i][0];
            var here = n >= 3 && !plan.small && plan.possible >= lo && (hi === null || plan.possible <= hi);
            rows += '<tr' + (here ? ' class="svb-pw-here"' : '') + '><th scope="row">' +
                    (hi === null ? lo + ' or more' : lo + '–' + hi) + '</th>' +
                    bands[i][1].map(function (p) { return '<td>' + p + '%</td>'; }).join('') + '</tr>';
            lo = (hi || 0) + 1;
        }

        stages = '<li data-level="0"><span class="svb-pw-swatch" aria-hidden="true"></span><span><strong>Before the first mark:</strong> ' +
                 '“8 more matchups to go before you can continue.” (required) or “' + esc(SvRender.PW_OPTIONAL_MESSAGE) + '” (optional)</span></li>';
        SvRender.PW_TIER_MESSAGES.forEach(function (m, idx) {
            stages += '<li data-level="' + (idx + 1) + '"><span class="svb-pw-swatch" aria-hidden="true"></span><span>“' + esc(m) + '”</span></li>';
        });
        stages += '<li data-level="4"><span class="svb-pw-swatch" aria-hidden="true"></span><span><strong>At 100%:</strong> “' +
                  esc(SvRender.PW_DONE_MESSAGE) + '”</span></li>';

        return '<div class="svb-pw-help sv-scope">' +
            '<p>Respondents see two options at a time and pick the one they prefer, or call it a tie. A win scores 1 point, ' +
            'a tie ½ to each, a loss 0. Results rank the options by <strong>win %</strong>: points divided by the matchups ' +
            'the option appeared in.</p>' +
            '<p>Every respondent gets their own random order of matchups, with sides picked at random, so no option is ' +
            'favored by where it sits in your list.</p>' +
            '<h3>How many they\'re asked to do</h3>' +
            '<p>' + esc(sentence) + '</p>' +
            '<div class="svb-pw-tablewrap"><table class="svb-pw-bands"><thead><tr><th scope="col">Matchups</th>' +
            '<th scope="col">Can continue</th><th scope="col">Even better</th><th scope="col">Awesome</th>' +
            '<th scope="col">Fantastic</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
            '<h3>Required or optional</h3>' +
            '<p>A <strong>required</strong> pairwise question keeps Next locked until the respondent reaches “Can continue”. ' +
            'With 30 matchups or fewer, that means every one. An <strong>optional</strong> question can be skipped, and any ' +
            'matchups a respondent does still count.</p>' +
            '<h3>What respondents see</h3>' +
            '<ol class="svb-pw-stages">' + stages + '</ol>' +
            '<p class="svb-hint">Keep it to 30 options or fewer when you can. Past that, each respondent covers a small ' +
            'slice of the matchups, so the ranking needs more respondents to settle.</p>' +
            '</div>';
    }
```

- [ ] **Step 5: Styles**

Append to `survey-build.css`:

```css
/* ------------------------------------------------ pairwise option entry */

.svb-pw { display: flex; flex-direction: column; gap: 6px; }
.svb-pw-lines { width: 100%; min-height: 96px; line-height: 1.5; box-sizing: border-box; }
.svb-pw-foot { display: flex; align-items: flex-start; gap: 6px; }
.svb-pw-readout { flex: 1 1 auto; margin: 0; }
.svb-pw-warn {
    display: flex;
    gap: 6px;
    align-items: baseline;
    margin: 0;
    font-size: var(--ork-font-size-sm);
    color: var(--sv-warn-text);
}

/* The (?) modal. Its h3s already drop orkui.css's pill box via .svb-modal-body h3. */
.svb-pw-help { display: flex; flex-direction: column; gap: 10px; font-size: var(--ork-font-size-base); line-height: 1.5; }
.svb-pw-help p { margin: 0; text-align: left; }
.svb-pw-help h3 { font-size: var(--ork-font-size-base); margin-top: 6px; }
.svb-pw-tablewrap { overflow-x: auto; }
.svb-pw-bands { width: 100%; border-collapse: collapse; font-size: var(--ork-font-size-sm); font-variant-numeric: tabular-nums; }
.svb-pw-bands th,
.svb-pw-bands td { padding: 6px 8px; border-bottom: 1px solid var(--sv-line-2); text-align: left; white-space: nowrap; }
.svb-pw-bands thead th { color: var(--sv-text-muted); font-weight: 600; }
.svb-pw-bands tr.svb-pw-here > * { background: var(--sv-accent-soft); font-weight: 600; }
.svb-pw-stages { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 6px; }
.svb-pw-stages li { display: flex; gap: 8px; align-items: baseline; }
.svb-pw-swatch { flex: 0 0 auto; width: 12px; height: 12px; border-radius: 3px; background: var(--sv-pw-0); transform: translateY(1px); }
.svb-pw-stages li[data-level="1"] .svb-pw-swatch { background: var(--sv-pw-1); }
.svb-pw-stages li[data-level="2"] .svb-pw-swatch { background: var(--sv-pw-2); }
.svb-pw-stages li[data-level="3"] .svb-pw-swatch { background: var(--sv-pw-3); }
.svb-pw-stages li[data-level="4"] .svb-pw-swatch { background: var(--sv-pw-4); }
```

If the modal body is not already inside `.sv-scope` (`Survey_build.tpl:161` puts `sv-scope` on `.svb-modal-panel`), the `--sv-*` tokens still resolve. Confirm `--sv-pw-1` computes in the modal in both themes.

- [ ] **Step 6: Verify**

`node --check orkui/template/default/script/survey-build.js`. In the builder (fixture from Task 6's seed command, draft):
- Add a Pairwise element: the card shows three lines and "3 options → 3 matchups · required respondents do all 3".
- Paste a bulleted 20-line list: the bullets are gone, the readout says 190 / 38, and the pill saves.
- Type a duplicate line: the inline error appears and the pill says "Not saved". Removing it saves.
- 31+ lines: the warning shows.
- (?) opens the modal with the 106–200 row highlighted. Focus is trapped and returns to the (?) on close.
- Retype single → pairwise keeps the options. Open the survey and confirm the textarea refuses a line-count change but saves a wording fix.
- Dark mode: textarea, warning, modal table and swatches.

- [ ] **Step 7: Commit**

```bash
git status --short && git diff --cached --stat
git add orkui/template/default/script/survey-build.js orkui/template/default/style/survey-build.css
git commit -m "Enhancement: Survey — pairwise builder card: paste one option per line, live matchup readout, (?) explainer" -- orkui/template/default/script/survey-build.js orkui/template/default/style/survey-build.css
```

---

### Task 8: Results card

**Model:** sonnet · effort medium

**Files:**
- Modify: `orkui/template/default/script/survey-results.js`
- Modify: `orkui/template/default/style/survey-results.css`

**Interfaces:**
- Consumes: Task 4's aggregate shape; `displayAnswer` pairwise strings in `row.answers`

- [ ] **Step 1: Chart, label, callouts**

- `typeLabel()`: add `pairwise: 'Pairwise'` to the map.
- `specFor()`: add `case 'pairwise': return specPairwise(q, theme);`.
- Add after `specRanking()`:

```js
    /* Win % in rank order (pairwise spec §7): the server already sorted the
       options, never-matched ones last with a null bar. */
    function specPairwise(q, theme) {
        var opts = ((q.agg && q.agg.options) || []).slice();
        var cfg = baseCfg(theme, 'bar');
        cfg.chart.height = barHeight(opts.length, 1);
        cfg.xAxis.categories = opts.map(function (o) { return o.label; });
        cfg.yAxis.min = 0;
        cfg.yAxis.max = 100;
        cfg.yAxis.labels.format = '{value}%';
        cfg.yAxis.title = { text: 'Win % (a tie counts half)', style: { color: theme.muted, fontSize: '11px' } };
        cfg.plotOptions.bar = {
            borderRadius: 4,
            dataLabels  : {
                enabled  : true,
                style    : labelStyle(theme),
                formatter: function () { return this.y === null ? null : num(this.y, 1) + '%'; }
            }
        };
        cfg.tooltip.formatter = function () {
            var o = opts[this.point.index] || {};
            return '<b>' + esc(o.label) + '</b><br/>Win %: <b>' + num(o.win_pct, 1) + '%</b><br/>' +
                'Won ' + (o.wins || 0) + ' · Tied ' + (o.ties || 0) + ' · Lost ' + (o.losses || 0) + '<br/>' +
                'Matchups: <b>' + (o.appearances || 0) + '</b>';
        };
        cfg.series = [{
            name : 'Win %',
            color: theme.colors[0],
            data : opts.map(function (o) { return o.win_pct === null ? null : o.win_pct; })
        }];
        return cfg;
    }
```

- `calloutsFor()`: add before `default:`

```js
            case 'pairwise':
                out.push(['Possible matchups', num(a.possible)],
                    ['Average % of matchups', a.avg_pct === null || a.avg_pct === undefined ? '—' : num(a.avg_pct, 1) + '%'],
                    ['Avg per respondent', a.avg_count === null || a.avg_count === undefined ? '—' : num(a.avg_count, 1) + ' of ' + num(a.possible)],
                    ['Matchups judged', num(a.judged)]);
                break;
```

- In `cardHtml()`, make the tall chart class include pairwise: `(q.type === 'matrix' || q.type === 'ranking' || q.type === 'pairwise' ? ' svr-chart-tall' : '')`, and after `html += calloutsFor(q);` add `if (q.type === 'pairwise') { html += pairwiseTableHtml(q); }`.

- [ ] **Step 2: Ranking DataTable**

Add near `cardHtml()`:

```js
    /* The ranking as a sortable table (tabular data = DataTables). Rank sorts
       ascending by default; never-matched options sort last on every column. */
    function pairwiseTableHtml(q) {
        var opts = (q.agg && q.agg.options) || [];
        var html = '<div class="svr-pw-tablewrap"><table class="display svr-pw-table" style="width:100%" ' +
            'aria-labelledby="svr-title-' + q.question_id + '"><thead><tr>' +
            '<th scope="col">Rank</th><th scope="col">Option</th><th scope="col">Win %</th>' +
            '<th scope="col"><abbr title="Wins">W</abbr></th><th scope="col"><abbr title="Ties">T</abbr></th>' +
            '<th scope="col"><abbr title="Losses">L</abbr></th><th scope="col">Matchups</th></tr></thead><tbody>';
        opts.forEach(function (o, i) {
            var unranked = o.rank === null || o.rank === undefined;
            html += '<tr>' +
                '<td data-order="' + (unranked ? 100000 + i : o.rank) + '">' + (unranked ? '—' : o.rank) + '</td>' +
                '<td>' + esc(o.label) + '</td>' +
                '<td data-order="' + (unranked ? -1 : o.win_pct) + '">' + (unranked ? '—' : num(o.win_pct, 1) + '%') + '</td>' +
                '<td>' + (o.wins || 0) + '</td><td>' + (o.ties || 0) + '</td><td>' + (o.losses || 0) + '</td>' +
                '<td>' + (o.appearances || 0) + '</td></tr>';
        });
        return html + '</tbody></table></div>';
    }

    function destroyPairwiseTables() {
        (state.pwTables || []).forEach(function (t) { try { t.destroy(); } catch (e) { /* gone */ } });
        state.pwTables = [];
    }

    function initPairwiseTables(host) {
        var jq = window.jQuery;   // not `$`: this file's `$` is getElementById
        if (!jq || !jq.fn || !jq.fn.DataTable) { return; }
        Array.prototype.forEach.call(host.querySelectorAll('.svr-pw-table'), function (table) {
            var many = table.tBodies[0] && table.tBodies[0].rows.length > 25;
            state.pwTables.push(jq(table).DataTable({
                order       : [[0, 'asc']],
                paging      : many,
                pageLength  : 25,
                searching   : many,
                info        : false,
                lengthChange: false,
                autoWidth   : false
            }));
        });
    }
```

Add `pwTables: []` to the `state` object. In `renderCards()`, call `destroyPairwiseTables();` before `host.innerHTML = …` and `initPairwiseTables(host);` after it.

- [ ] **Step 3: Row table cell clamp**

In `rowArray()`, give pairwise answers a clamping class: replace the answer span line with

```js
                '<span class="svr-cell-answer' + ((questionById(qid) || {}).type === 'pairwise' ? ' svr-cell-clamp' : '') + '">' +
                esc(answerText(questionById(qid), v)) + '</span>');
```

Append to `survey-results.css`:

```css
/* Pairwise (pairwise spec §7): the ranking table under the chart, and a
   long matchup list clamped in the rows table (the response panel shows it all). */
.svr-pw-tablewrap { margin-top: 12px; overflow-x: auto; }
.svr-pw-table td:nth-child(n+3),
.svr-pw-table th:nth-child(n+3) { text-align: right; font-variant-numeric: tabular-nums; }
.svr-pw-table abbr { text-decoration: none; cursor: help; }
.svr-cell-clamp {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
```

- [ ] **Step 4: Verify**

`node --check orkui/template/default/script/survey-results.js`. On a seeded open survey with responses (Task 9's seed, or answer the Task 6 fixture a few times as different players), check:
- The results card shows the chart, four callouts and the table with Rank ascending.
- Sorting by Win % works and the "—" rows stay last.
- The Summary-for-sharing toggle, filters (a narrowing filter below MIN_CELL hides the card) and cross-tab (pairwise shows no split) all behave.
- Rows table cells clamp to 3 lines, and the response panel shows the full list.
- CSV export has the `k of M: …` cell.
- Dark mode.

- [ ] **Step 5: Commit**

```bash
git status --short && git diff --cached --stat
git add orkui/template/default/script/survey-results.js orkui/template/default/style/survey-results.css
git commit -m "Enhancement: Survey — pairwise results card: win % chart, matchup callouts, ranking table" -- orkui/template/default/script/survey-results.js orkui/template/default/style/survey-results.css
```

---

## Phase 3: Content, verification, review

### Task 9: Seed, guide, release note

**Model:** sonnet · effort low

**Files:**
- Modify: `bin/seed-survey-example.php` (plan after `q9` ~line 216; answers after the ranking block ~line 578)
- Modify: `docs/survey-guide.md` (types table ~line 60, new section after the table's two follow-on paragraphs ~line 72)
- Modify: `orkui/whats_new_content.php` (the `3.5.6 Survey` entry body, line 23)

- [ ] **Step 1: Seed question**

After the `q9` ranking spec in `seed_plan()`:

```php
                [
                    'key'     => 'q9p',
                    'type'    => 'pairwise',
                    'prompt'  => 'Which event should the kingdom add next?',
                    'help'    => 'Pick the one you would rather attend, or call it a tie. Do as many as you like.',
                    'choices' => [
                        ['label' => 'Spring war'], ['label' => 'Tournament of champions'], ['label' => 'Quest weekend'],
                        ['label' => 'Fall feast'], ['label' => 'Camping campaign'], ['label' => 'Fighter practice weekend'],
                        ['label' => 'Arts & Sciences faire'], ['label' => 'Newcomer demo day'], ['label' => 'Youth day'],
                    ],
                ],
```

(9 options = 36 matchups, so the demo shows the tiered bar.) After the ranking answer block in `seed_answers()`:

```php
    // 9p. pairwise — each respondent judges a random share of the 36 matchups;
    // a hidden strength per event decides most picks, so the ranking is real.
    if (!$skip()) {
        $strength = [
            'Spring war' => 9, 'Tournament of champions' => 8, 'Quest weekend' => 7, 'Fall feast' => 6,
            'Camping campaign' => 5, 'Fighter practice weekend' => 4, 'Arts & Sciences faire' => 3,
            'Newcomer demo day' => 2, 'Youth day' => 2,
        ];
        $labels = array_keys($strength);
        $pairs = [];
        foreach ($labels as $i => $x) {
            foreach (array_slice($labels, $i + 1) as $y) {
                $pairs[] = [$x, $y];
            }
        }
        shuffle($pairs);
        $list = [];
        foreach (array_slice($pairs, 0, random_int(4, count($pairs))) as [$x, $y]) {
            if (random_int(0, 1) === 1) {
                [$x, $y] = [$y, $x];
            }
            $roll = random_int(1, $strength[$x] + $strength[$y] + 2);
            $idX = $b['q9p']['choice'][$x];
            $idY = $b['q9p']['choice'][$y];
            $list[] = ['a' => $idX, 'b' => $idY, 'w' => $roll <= 2 ? 0 : ($roll - 2 <= $strength[$x] ? $idX : $idY)];
        }
        $a[$b['q9p']['id']] = $list;
    }
```

Verify: `docker exec ork3-php8-app php /var/www/ork.amtgard.com/bin/seed-survey-example.php --kingdom=17 --dry-run` lists `q9p pairwise`. Then run a real seed with `--title="Pairwise demo $(date +%Y%m%d%H%M)" --responses=60 --open` and note the survey id for Task 10. It must print no error, and the results page must show the pairwise card.

- [ ] **Step 2: Guide**

In `docs/survey-guide.md`'s types table, add after the Ranking row:

```md
| Pairwise | Two options at a time: the player picks one or calls a tie. Results rank every option by how often it wins. See **Pairwise questions** below |
```

After the skip-logic paragraph (before `### Structure lock`) add:

```md
### Pairwise questions

Pairwise asks "this or that?" over and over, instead of making anyone sort a long list at once. Type or
paste the options into the box, one per line. The (?) beside the box explains everything below with
your question's own numbers.

- **Scoring.** A win is 1 point, a tie is ½ to each option, a loss is 0. An option's **win %** is its
  points divided by the matchups it appeared in, and results rank options by win %.
- **Random for everyone.** Each player gets their own random order of matchups, with sides picked at
  random.
- **How many matchups.** N options make N × (N − 1) ÷ 2 matchups: 10 options is 45, 30 options is 435.
  Players aren't asked to do them all. A progress bar shows how far they've got and cheers them on at
  four marks:

| Matchups | Can continue | Even better | Awesome | Fantastic |
|---|---|---|---|---|
| 30 or fewer | every matchup, if required (a plain bar) | | | |
| 31–105 | 30% | 40% | 50% | 60% |
| 106–200 | 20% | 30% | 40% | 50% |
| 201–300 | 10% | 20% | 30% | 40% |
| 301 or more | 10% | 15% | 20% | 25% |

- **Required or optional.** A required pairwise question won't let the player move on until they reach
  "Can continue". An optional one can be skipped, and whatever matchups the player did still count.
- **Keep it short.** Over 30 options the builder warns you. It still works, but each player covers a
  small slice of the matchups, so you need more players for the ranking to settle.
- **Results** show the possible matchups, the average share of matchups each player did, and every
  option's win % as a chart and a sortable table. The CSV lists each player's matchups, winner first.
```

- [ ] **Step 3: Release note**

In `orkui/whats_new_content.php`, in the `3.5.6 Survey` item's `body`, after the sentence ending `…with filters and CSV export.` insert: ` A pairwise question type ranks a long list two options at a time, with a progress bar that tells respondents when they've done enough.` (No version bump: the module has not shipped.)

`php -l orkui/whats_new_content.php bin/seed-survey-example.php`.

- [ ] **Step 4: Commit**

```bash
git status --short && git diff --cached --stat
git add bin/seed-survey-example.php docs/survey-guide.md orkui/whats_new_content.php
git commit -m "Enhancement: Survey — pairwise in the demo seed, the guide and the release note" -- bin/seed-survey-example.php docs/survey-guide.md orkui/whats_new_content.php
```

---

### Task 10: Serial browser verification

**Model:** opus · effort high

Browser agents run one at a time. Use headless Playwright + Chromium from the repo's `node_modules` (`playwright` is installed; browsers in `~/Library/Caches/ms-playwright`). Chrome may have no logged-in session, and agents do not type passwords. Get a session cookie from `POST http://localhost:19080/orkui/index.php?Route=Login/login` (`username=heraldsbridge&password=x`) and add it to the Playwright context. Write the driver in the session scratchpad, not the repo. `code.highcharts.com` 403s the HeadlessChrome UA, so set a normal Chrome user agent string on the context.

Fixtures:
- **Results and runner:** the survey Task 9 seeded (open, 60 responses).
- **Builder and gate:** a fresh draft seed (`--title="Pairwise builder $(date +%s)"`, no `--open`). In the builder, mark its pairwise question Required, paste 12 more lines (21 options = 210 matchups, gate 21), then open the runner in preview.
- **A normal respondent pass:** open a third seed as `--open` with no responses and take it as heraldsbridge.

Never enable credits on any fixture.

- [ ] **Step 1:** Builder at 1280px, light then dark, with screenshots:
  - The textarea, readout, >30 warning (paste 35 lines, then trim back) and duplicate hold.
  - The (?) modal with the correct band highlighted, focus trap and Escape.
  - Retype away and back.
- [ ] **Step 2:** Runner at 1280px and 360px (iframe harness or a 360px Playwright viewport), light and dark:
  - Pick left, right and Tie; Undo (repeatedly, including from the 100% state).
  - ← → ↓ keys; reduced motion (`page.emulateMedia({reducedMotion: 'reduce'})`).
  - Every color stage and message, word for word, at each tier count.
  - Next below the gate shows `Please complete at least 21 matchups to continue.`, and at the gate it passes.
  - A reload mid-question resumes with the same done count.
  - 100% shows the trophy message. No horizontal scroll at 360px, and tap targets are ≥ 44px.
- [ ] **Step 3:** Results: the chart, callouts, table sort, Summary for sharing, a narrowing filter below MIN_CELL, the rows-table clamp, the response panel, and a CSV download containing `of 36:`.
- [ ] **Step 4:** Collect findings with screenshots. Fix every confirmed finding inline (one commit per theme, explicit paths), re-run the affected checks, and repeat until clean.

---

### Task 11: Review gate

**Model:** opus · effort high

- [ ] **Step 1: Full checks**

```bash
ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter 'Survey'
for f in system/lib/ork3/class.Survey*.php bin/seed-survey-example.php orkui/whats_new_content.php; do php -l "$f"; done
for f in orkui/template/default/script/survey-*.js tests/Unit/js/survey-pairwise-harness.js; do node --check "$f"; done
grep -rnE '\$DB->|Ork3::\$Lib|new Survey(Response|Report|Credit)?\(' orkui --include='*.php' --include='*.tpl' | grep -v 'orkui/model/model.Survey.php' || echo "layering clean"
```
Expected: all Survey tests green (previous count + the new ones), lint clean, "layering clean".

- [ ] **Step 2: Code review**

Dispatch one `feature-dev:code-reviewer` subagent over `git diff <commit before Task 1>..HEAD` with the spec as context. Ask for correctness bugs, a11y, dark mode, and spec drift (copy strings, band math, gate parity). Fix every confirmed finding and re-run Step 1.

- [ ] **Step 3: Update the project memory** (`project_survey_module.md`): pairwise shipped on the branch, the fixture ids, and anything non-obvious found in verification.

---

## Spec coverage (self-review)

| Spec | Task |
|---|---|
| §1 schema + row mapping | 1, 2, 3 |
| §2 catalog, bands, plan, integer ceiling, JS mirror + parity | 2, 5 |
| §3 answer shape, validation messages, optionSet rules, retype | 2, 3 |
| §4 randomization, soft no-repeat, resume, undo re-queue | 5, 6 |
| §5 builder paste textarea, readout, >30 warning, locked rule, duplicate hold, (?) modal, palette entry, preview | 6 (preview), 7 |
| §6 runner stage, keys, reduced motion, Undo, bar colors/ticks/messages, 100%, gate on Next, dark tokens | 5, 6 |
| §7 aggregate, callouts, chart, DataTable, suppression/filters unchanged, shared viewers, CSV cell | 4, 8 |
| §8 seed, guide, release note | 9 |
| §9 unit, parity, integration, browser | 2, 3, 4, 5, 10 |
| §10 acceptance | 10, 11 |
| §11 out of scope (no cross-tab, no skip button) | none needed; pairwise stays out of `CROSSTAB_*` and `SHOW_IF_SOURCES` (asserted in Task 2) |
