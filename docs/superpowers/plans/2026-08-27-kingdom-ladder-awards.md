# Kingdom-Specific Ladder Awards Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a kingdom ladder-ify any of its own awards with its own max rank, make every ladder-aware surface honour those kingdom ladders, and lock the 16 official Amtgard ladders against modification.

**Architecture:** Replace five competing "is this a ladder?" spellings with two static SQL helpers on `Award` (`LadderSql()` effective, `OfficialLadderSql()` official) plus a PHP `MaxRankFor()` resolver, following the `OfficerPosition::DisplayTitleSql()` pattern this branch already used for officer titles. No schema change — `ork_kingdomaward.is_ladder` and `.max_level` already exist and already hold the live data; what changes is that they are read authoritatively and gain a writer. Rank *display* is decoupled from rank *offering*, which makes un-laddering forward-only and needs no migration.

**Tech Stack:** PHP 8.2, MariaDB, PHPUnit 10, plain-PHP `.tpl` templates, vanilla JS (`revised.js`), hand-written CSS (`revised.css`).

**Spec:** `docs/superpowers/specs/2026-08-27-kingdom-ladder-awards-design.md`

## Global Constraints

- **Layer separation is enforced by hooks and pre-commit.** SQL lives only in `system/lib/ork3/class.*.php`. `orkui/model/model.*.php` is the only layer that may say `new Domain()` or `Ork3::$Lib->x`. Controllers and `.tpl` files may not do either. Escape hatch `ORK3_ALLOW_LAYER_VIOLATION=1` is not to be used in this plan.
- **`.tpl` files are PLAIN PHP, not Smarty.** Use `<?php if (): ?>` / `<?= ?>`. `{$var}` and `{if}` render literally.
- **Always call `$DB->Clear()` before a raw `Execute()`/`DataSet()`** — stale PDO bindings cause silent save failures. Never nest a `Clear()` + query inside a `while ($rs->Next())` loop.
- **`$DB->DataSet()` needs a manual `->Next()`** before reading fields.
- **yapo drops `null` from UPDATE/INSERT.** Assign `''` (or `0`) to clear a column, never `null`.
- **`mysql_real_escape_string()` is a no-op shim.** Cast ids with `(int)` or pre-validate with a regex.
- **Tooltips are `data-tip`, never native `title=`.**
- **Dark mode is mandatory for new CSS.** Every new colour-bearing rule needs an `html[data-theme="dark"]` counterpart.
- **Editing PHP — normalise first.** Run `awk '/^\t/{c++} END{print c+0}' <file>`; if non-zero, run php-cs-fixer on that file *before* editing it.
- **NEVER stage `system/lib/ork3/class.Authorization.php`** — it carries a local `true ||` auth bypass. Stage files explicitly; never `git add -A`.
- **Do not commit** `CLAUDE.md`, `agent-instructions/claude.md`, `assets/cms-media/`, or `db-migrations/amtgard-specs/`.
- **Max Rank hard ceiling is 12.** Default is 10. `max_level = 0` means unspecified and resolves to 10.
- **Star pill glyph is `✱`** (U+2731). Star copy, verbatim: *"The standard cap for this award is {max} — but don't let that stop you from recognizing someone!"*
- **Rule 1 rejection message, verbatim:** *"{Award} is a ranked award — choose a rank, or use ✱ if they have already reached {max}."*
- **Official-lock tooltip, verbatim:** *"Standard Amtgard ladder award — this can't be changed."*
- **Walker (`award_id = 31`) stays excluded from ladder reports.** Unchanged by this plan.
- **Test baseline before any change: 184 tests, 427 assertions, 4 errors, 17 failures.** These pre-existing errors/failures are not yours. Any *new* error or failure is. Re-run and compare against this baseline, never against zero.
- **Tests run on the HOST, not in Docker.** Host is PHP 8.5 / PHPUnit 11.5.56; the
  `ork3app` container is PHP 8.1 and has no `vendor/bin/phpunit` at all. Run:
  `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite unit` (add `--filter <TestName>` to scope).
  The DB containers are `ork3db` (schema `ork`, port 19306) and `ork3testdb`
  (schema `ork_test`, port 19307) — PHPUnit uses the test one.
  `bin/run-unit-tests.sh` is the unfiltered sign-off command; it runs
  `ork-db drift-check --strict` first, which fails the whole run if a new
  `db-migrations/` file is unclassified.

---

### Task 1: The ladder predicate helpers

Adds the four static helpers every later task calls. Nothing else changes yet, so the suite must stay exactly at baseline.

**Files:**
- Modify: `system/lib/ork3/class.Award.php` (add helpers after `GetLadderMasterMap()`, which ends at line 40)
- Test: `tests/Unit/LadderPredicateTest.php` (create)

**Interfaces:**
- Consumes: `Award::GetLadderMasterMap()` — existing, returns `array<int, array{MasterAwardIds: list<int>, LadderName: string, MasterName: string, MaxRank: int}>` keyed by `award_id`. 14 entries. Zodiac (30) has `MaxRank => 12`; every other entry is 10.
- Produces:
  - `Award::LadderSql(string $ka = 'ka', string $a = 'a'): string`
  - `Award::OfficialLadderSql(string $a = 'a'): string`
  - `Award::MaxRankFor(int $awardId, int $kaMaxLevel = 0): int`
  - `Award::IsMonthlyLadder(int $awardId): bool`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/LadderPredicateTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The ladder predicate helpers that replace five competing spellings of
 * "is this a ladder?" (kingdom-ladder-awards spec, section 1).
 */
final class LadderPredicateTest extends TestCase
{
    public function testLadderSqlIsAdditiveOverBothColumns(): void
    {
        $this->assertSame(
            'GREATEST(IFNULL(ka.is_ladder, 0), IFNULL(a.is_ladder, 0))',
            Award::LadderSql()
        );
    }

    public function testLadderSqlHonoursTableAliases(): void
    {
        $this->assertSame(
            'GREATEST(IFNULL(kaw.is_ladder, 0), IFNULL(aw.is_ladder, 0))',
            Award::LadderSql('kaw', 'aw')
        );
    }

    public function testOfficialLadderSqlKeysOnTheAwardTableOnly(): void
    {
        $this->assertSame('a.is_ladder = 1', Award::OfficialLadderSql());
        $this->assertSame('aw.is_ladder = 1', Award::OfficialLadderSql('aw'));
    }

    public function testMaxRankForZodiacIsTwelve(): void
    {
        // The special case currently written out three times: GetLadderMasterMap,
        // GetLadderProgress:1636, and Playernew_reconcile.tpl:185.
        $this->assertSame(12, Award::MaxRankFor(30));
    }

    public function testMaxRankForOtherOfficialLaddersIsTen(): void
    {
        $this->assertSame(10, Award::MaxRankFor(21));  // Order of the Rose
        $this->assertSame(10, Award::MaxRankFor(243)); // Order of Battle
    }

    public function testOfficialMaxRankIgnoresKingdomMaxLevel(): void
    {
        // ka.max_level is 0 on all official rows and must never override the map.
        $this->assertSame(12, Award::MaxRankFor(30, 5));
        $this->assertSame(10, Award::MaxRankFor(21, 7));
    }

    public function testKingdomLadderUsesItsOwnMaxLevel(): void
    {
        $this->assertSame(7, Award::MaxRankFor(0, 7));
        $this->assertSame(12, Award::MaxRankFor(0, 12));
    }

    public function testUnspecifiedMaxLevelFallsBackToTen(): void
    {
        $this->assertSame(10, Award::MaxRankFor(0, 0));
        $this->assertSame(10, Award::MaxRankFor(9999, 0));
    }

    public function testMaxRankForClampsToTwelve(): void
    {
        $this->assertSame(12, Award::MaxRankFor(0, 40));
    }

    public function testMaxRankForRejectsNegativeMaxLevel(): void
    {
        $this->assertSame(10, Award::MaxRankFor(0, -3));
    }

    public function testWalkerAndFlameFallThroughToTen(): void
    {
        // Neither is in GetLadderMasterMap. Flame's correct value is 10; the helper
        // makes that explicit instead of accidental.
        $this->assertSame(10, Award::MaxRankFor(31)); // Walker
        $this->assertSame(10, Award::MaxRankFor(34)); // Order of the Flame
    }

    public function testOnlyZodiacIsAMonthlyLadder(): void
    {
        $this->assertTrue(Award::IsMonthlyLadder(30));
        foreach (array_keys(Award::GetLadderMasterMap()) as $awardId) {
            if ($awardId === 30) {
                continue;
            }
            $this->assertFalse(
                Award::IsMonthlyLadder($awardId),
                "Award {$awardId} must not be a monthly ladder"
            );
        }
        $this->assertFalse(Award::IsMonthlyLadder(0));
        $this->assertFalse(Award::IsMonthlyLadder(31));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite unit --filter LadderPredicateTest`
Expected: FAIL — `Error: Call to undefined method Award::LadderSql()`

- [ ] **Step 3: Write the implementation**

In `system/lib/ork3/class.Award.php`, immediately after the closing `}` of `GetLadderMasterMap()` (line 40), insert:

```php
    /**
     * Effective-ladder SQL predicate: a kingdom may raise an award to ladder status,
     * but can never lower an official one. Additive by construction.
     *
     * Sole spelling of "is this a ladder?" for SQL. Do not fork it.
     */
    public static function LadderSql(string $ka = 'ka', string $a = 'a'): string
    {
        return 'GREATEST(IFNULL(' . $ka . '.is_ladder, 0), IFNULL(' . $a . '.is_ladder, 0))';
    }

    /**
     * Official-ladder SQL predicate — the 16 Amtgard orders. Cross-kingdom
     * comparisons (the global Ladder Grid) key on this, never on LadderSql().
     */
    public static function OfficialLadderSql(string $a = 'a'): string
    {
        return $a . '.is_ladder = 1';
    }

    /**
     * Resolve an award's maximum rank.
     *
     * Cannot be done in SQL: the official ladders' maxes live in GetLadderMasterMap(),
     * not in the database, and ka.max_level is 0 on every official row — so a SQL-only
     * COALESCE(NULLIF(ka.max_level, 0), 10) would silently demote Zodiac from 12 to 10.
     *
     * @param int $awardId     ork_award.award_id; 0 for a pure kingdom award
     * @param int $kaMaxLevel  ork_kingdomaward.max_level; 0 means unspecified
     */
    public static function MaxRankFor(int $awardId, int $kaMaxLevel = 0): int
    {
        $map = self::GetLadderMasterMap();
        if (isset($map[$awardId]['MaxRank'])) {
            return (int) $map[$awardId]['MaxRank'];
        }
        if ($kaMaxLevel > 0) {
            return min(12, $kaMaxLevel);
        }
        return 10;
    }

    /**
     * Order of the Zodiac is granted once per calendar month, so its twelve positions
     * are months rather than levels. It is the only award of that nature.
     *
     * A name for a fact several call sites need — not a taxonomy over a family that
     * does not exist. See 2026-08-27-zodiac-monthly-awards-design.md.
     */
    public static function IsMonthlyLadder(int $awardId): bool
    {
        return $awardId === 30;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite unit --filter LadderPredicateTest`
Expected: PASS, 12 tests.

Then run the whole suite and confirm it is still exactly at baseline (184 tests / 427 assertions / 4 errors / 17 failures):

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite unit`

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.Award.php tests/Unit/LadderPredicateTest.php
git commit -m "Enhancement: ladder predicate helpers on Award"
```

---

### Task 2: Retire the hardcoded kingdom-ladder ID list

`Award::pseudoLadderKingdomAwardIds()` (`class.Award.php:545`) hardcodes 24 primary keys that are exactly `SELECT kingdomaward_id FROM ork_kingdomaward WHERE is_ladder = 1`. The column replaces the list.

**Two things make this task safe, and both are required.** The equivalence proof is a manual pre-flight against real data (the `ork_test` database has **zero** `ork_kingdomaward` rows, so it cannot prove anything about production's 24). The automated test seeds its own rows and proves the *predicate* classifies correctly.

**Files:**
- Modify: `system/lib/ork3/class.Award.php:545` (delete `pseudoLadderKingdomAwardIds()`), and `groupAwardOptions()` in the same file
- Modify: `tests/Unit/AwardOptionGroupsTest.php:26-36` (`testPseudoLadderIds`) and `:100-107` (`mirrorPseudoLadderIds`)
- Test: `tests/Integration/LadderPredicateSqlTest.php` (create)

**Interfaces:**
- Consumes: `Award::LadderSql()` from Task 1.
- Produces: `Award::groupAwardOptions()` keeps its existing signature and return shape, but gains two distinct ladder groups. Its returned array keys become: `'Official Ladder Awards'`, `'Kingdom Ladder Awards'`, plus the existing non-ladder group keys unchanged.

- [ ] **Step 1: Pre-flight — prove the two sets are identical on real data**

This is a read-only query against the dev database. It is not a test; it is the evidence that deleting the array is behaviour-preserving.

```bash
docker compose exec ork3db mariadb -uork -psecret ork -N -e "
  SELECT GROUP_CONCAT(kingdomaward_id ORDER BY kingdomaward_id) 
  FROM ork_kingdomaward WHERE is_ladder = 1;"
```

Expected, sorted: `94,5813,6045,6050,6171,6283,6297,6310,6311,6403,6411,6430,6574,6577,6628,6771,7055,7067,7070,7084,7249,7254,7273,7277` — 24 ids, the same multiset as the hardcoded array.

**If the sets differ, STOP and report.** Do not proceed; the refactor is not behaviour-preserving and the spec's premise needs revisiting.

- [ ] **Step 2: Write the failing test**

Create `tests/Integration/LadderPredicateSqlTest.php`. It seeds its own rows because `ork_test` ships with no `ork_kingdomaward` data:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Proves Award::LadderSql() classifies rows the way the deleted
 * pseudoLadderKingdomAwardIds() array did: kingdom ladders are found by column,
 * and an official ladder can never be lowered by its kingdom row.
 */
final class LadderPredicateSqlTest extends TestCase
{
    private const MARKER = 'LADSQL';

    private PDO $pdo;

    /** @var list<int> */
    private array $kingdomAwardIds = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // award_id 21 (Order of the Rose) is an official ladder: ork_award.is_ladder = 1.
        // award_id 0 stands for a pure kingdom award with no Amtgard parent.
        $this->kingdomAwardIds = [
            'officialUnflagged' => $this->seedKingdomAward(21, 0), // official, ka says no
            'kingdomLadder'     => $this->seedKingdomAward(0, 1),  // kingdom ladder
            'plainKingdom'      => $this->seedKingdomAward(0, 0),  // ordinary kingdom award
        ];
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pdo->exec("DELETE FROM ork_kingdomaward WHERE name LIKE '" . self::MARKER . "%'");
    }

    private function seedKingdomAward(int $awardId, int $isLadder): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_kingdomaward (kingdom_id, award_id, name, is_ladder, max_level)
             VALUES (0, :award_id, :name, :is_ladder, 0)'
        );
        $stmt->execute([
            ':award_id'  => $awardId,
            ':name'      => self::MARKER . '-' . $awardId . '-' . $isLadder . '-' . uniqid(),
            ':is_ladder' => $isLadder,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function effectiveLadderFor(int $kingdomAwardId): int
    {
        $sql = 'SELECT ' . Award::LadderSql() . ' AS eff
                FROM ork_kingdomaward ka
                LEFT JOIN ork_award a ON a.award_id = ka.award_id
                WHERE ka.kingdomaward_id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $kingdomAwardId]);

        return (int) $stmt->fetchColumn();
    }

    public function testKingdomLadderIsFoundByColumn(): void
    {
        $this->assertSame(1, $this->effectiveLadderFor($this->kingdomAwardIds['kingdomLadder']));
    }

    public function testOrdinaryKingdomAwardIsNotALadder(): void
    {
        $this->assertSame(0, $this->effectiveLadderFor($this->kingdomAwardIds['plainKingdom']));
    }

    public function testOfficialLadderCannotBeLoweredByItsKingdomRow(): void
    {
        // Requirement 1: the 16 official ladders must never be un-toggleable.
        // GREATEST makes it arithmetically impossible even with ka.is_ladder = 0.
        $this->assertSame(1, $this->effectiveLadderFor($this->kingdomAwardIds['officialUnflagged']));
    }

    public function testLadderSqlFindsEveryRowTheHardcodedListNamed(): void
    {
        // The 24 ids the deleted array held. On a production-shaped database these
        // are exactly the rows LadderSql() returns; on a sparse test database the
        // assertion is the weaker but still meaningful "no flagged row is missed".
        $rows = $this->pdo->query(
            'SELECT ka.kingdomaward_id
             FROM ork_kingdomaward ka
             LEFT JOIN ork_award a ON a.award_id = ka.award_id
             WHERE ka.is_ladder = 1'
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($rows as $kingdomAwardId) {
            $this->assertSame(
                1,
                $this->effectiveLadderFor((int) $kingdomAwardId),
                "kingdomaward_id {$kingdomAwardId} is flagged but LadderSql() missed it"
            );
        }
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter LadderPredicateSqlTest`
Expected: PASS already, because Task 1 shipped `LadderSql()`. That is fine — this test is the safety net that must be **green before and after** the deletion in Step 4. Confirm it is green now, then proceed.

- [ ] **Step 4: Delete the hardcoded list and rewrite the grouping**

In `system/lib/ork3/class.Award.php`, delete the whole `pseudoLadderKingdomAwardIds()` method at line 545.

Then rewrite `groupAwardOptions()` so it groups on the effective-ladder flag the query now supplies, instead of testing membership of the deleted array. Replace the membership test with:

```php
        $isOfficialLadder = (int) ($award['is_ladder'] ?? 0) === 1;
        $isKingdomLadder  = !$isOfficialLadder
            && (int) ($award['ka_is_ladder'] ?? 0) === 1;

        if ($isOfficialLadder) {
            $groups['Official Ladder Awards'][] = $award;
            continue;
        }
        if ($isKingdomLadder) {
            $groups['Kingdom Ladder Awards'][] = $award;
            continue;
        }
```

Requirement 4 (official and kingdom ladders visibly distinguishable) is why these are two groups rather than one.

- [ ] **Step 5: Update the tests that asserted the deleted array**

In `tests/Unit/AwardOptionGroupsTest.php`, replace `testPseudoLadderIds()` (lines 26-36) with a test of the new grouping, and delete `mirrorPseudoLadderIds()` (lines 100-107) along with any remaining call to it:

```php
    public function testLadderAwardsSplitIntoOfficialAndKingdomGroups(): void
    {
        // Assert MEMBERSHIP, not just that the two groups differ. A test that only
        // checks the keys exist and the arrays are not identical still passes when
        // the classification is swapped — which is the exact rule this is guarding.
        $groups = $this->mirrorCategorizeSampleAwards();

        $officialIds = array_column($groups['Official Ladder Awards'] ?? [], 'KingdomAwardId');
        $kingdomIds  = array_column($groups['Kingdom Ladder Awards'] ?? [], 'KingdomAwardId');

        $this->assertContains(
            self::SAMPLE_OFFICIAL_KAID,
            $officialIds,
            'an award backed by a.is_ladder=1 belongs in the official group'
        );
        $this->assertNotContains(
            self::SAMPLE_OFFICIAL_KAID,
            $kingdomIds,
            'requirement 1: an official ladder must never fall into the kingdom bucket'
        );

        $this->assertContains(
            self::SAMPLE_KINGDOM_KAID,
            $kingdomIds,
            'an award raised only by ka.is_ladder=1 belongs in the kingdom group'
        );
        $this->assertNotContains(
            self::SAMPLE_KINGDOM_KAID,
            $officialIds,
            'a kingdom ladder must not be presented as an Amtgard order'
        );
    }

    public function testAnAwardThatIsBothOfficialAndKingdomFlaggedGroupsAsOfficial(): void
    {
        // The tie-break. GREATEST() makes both flags 1 for an official award, so the
        // classifier must resolve the overlap in official's favour (requirement 1).
        $groups = $this->mirrorCategorizeSampleAwards();

        $this->assertContains(
            self::SAMPLE_BOTH_FLAGGED_KAID,
            array_column($groups['Official Ladder Awards'] ?? [], 'KingdomAwardId')
        );
        $this->assertNotContains(
            self::SAMPLE_BOTH_FLAGGED_KAID,
            array_column($groups['Kingdom Ladder Awards'] ?? [], 'KingdomAwardId')
        );
    }
```

Give the test class three id constants — `SAMPLE_OFFICIAL_KAID`,
`SAMPLE_KINGDOM_KAID`, `SAMPLE_BOTH_FLAGGED_KAID` — and update
`mirrorCategorizeSampleAwards()`'s sample rows to cover all three cases:
one row official only (`IsLadder => 1`), one kingdom only (effective-ladder
but `IsLadder => 0`), and one flagged BOTH ways. The third is what proves
the tie-break; without it requirement 1 is untested at the PHP layer.

- [ ] **Step 6: Confirm nothing else calls the deleted method**

```bash
grep -rn 'pseudoLadderKingdomAwardIds' --include='*.php' --include='*.tpl' .
```

Expected: no matches. If any remain, convert each to the effective-ladder flag before continuing.

- [ ] **Step 7: Run the tests**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite unit --filter AwardOptionGroupsTest`
Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter LadderPredicateSqlTest`
Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite unit`
Expected: the first two PASS; the full suite is at baseline with no new failures.

- [ ] **Step 8: Commit**

```bash
git add system/lib/ork3/class.Award.php tests/Unit/AwardOptionGroupsTest.php tests/Integration/LadderPredicateSqlTest.php
git commit -m "Enhancement: read kingdom ladders from the column, retire hardcoded id list"
```

---

### Task 2A: The missing `ork_kingdomaward` ladder columns

**Added mid-execution.** The spec asserts "No schema change. Both columns exist
and both hold live data." That is true of dev and prod, and **false of a fresh
build**: `tools/ork-db/rendered/sandbox.sql` defines `ork_kingdomaward` with
`is_title`, `title_class`, `kingdom_id`, `award_id`, `name`, `reign_limit` and
`month_limit` — and **no `is_ladder`, no `max_level`**. No migration anywhere in
the repo adds them. They reached prod by direct database access, which is the
same route the spec says produced the 24 flagged rows.

`drift-check` does not catch this, so nothing would surface it until a fresh
environment silently failed. Every remaining task in this plan reads or writes
those two columns, so the contract has to be made real and reproducible.

**Files:**
- Create: `db-migrations/2026-08-27-kingdomaward-ladder-columns.sql`
- Modify: `tools/ork-db/manifests/migration-classification.json5`

**Interfaces:**
- Produces: `ork_kingdomaward.is_ladder TINYINT(1) NOT NULL DEFAULT 0` and
  `ork_kingdomaward.max_level TINYINT(1) NOT NULL DEFAULT 0` on every environment,
  including fresh sandbox builds. **Both types are copied from the live dev
  schema** — the whole point of this migration is parity with prod, so a fresh
  build must not get a wider type than the database it is mirroring.

- [ ] **Step 1: Write the migration**

Create `db-migrations/2026-08-27-kingdomaward-ladder-columns.sql`:

```sql
-- ork_kingdomaward.is_ladder / max_level exist in production but were never
-- added by a tracked migration -- they arrived through direct database access,
-- the same route that produced the 24 rows currently flagged is_ladder = 1.
-- A fresh build from ork-db therefore lacks both columns entirely, and every
-- kingdom-ladder surface reads or writes them.
--
-- Idempotent: safe to re-run on dev and prod, where the columns already exist.
-- No backfill -- the 24 live rows already carry their values, and a fresh build
-- correctly starts with none.

ALTER TABLE `ork_kingdomaward`
    ADD COLUMN IF NOT EXISTS `is_ladder` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `max_level` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `disabled`  TINYINT(1) NOT NULL DEFAULT 0;
```

`disabled` has the same problem and is **more urgent**: award soft-delete, added
by commit `95944b80` earlier on this branch, already writes it
(`class.Kingdom.php:384,509,544`). It is absent from the canonical schema and
from every migration, so this PR currently ships code that writes a column a
fresh deploy would not have. It is the same table and the same class of gap, so
it belongs in the same migration rather than a second one.

- [ ] **Step 2: Classify it**

Add to the map in `tools/ork-db/manifests/migration-classification.json5`,
following the format of the surrounding entries:

```json5
    "2026-08-27-kingdomaward-ladder-columns.sql": { "class": "S", "render": "full", "notes": "Adds is_ladder/max_level to ork_kingdomaward; both exist in prod via direct DB access but were never migrated, so fresh builds lacked them. Idempotent ADD COLUMN IF NOT EXISTS, no backfill." },
```

An unclassified file in `db-migrations/` makes `drift-check --strict` fail,
which fails the whole `bin/run-unit-tests.sh` run.

- [ ] **Step 3: Verify it is genuinely idempotent**

Both databases already have the columns, so a correct migration is a no-op:

```bash
docker compose exec -T ork3db mariadb -uork -psecret ork < db-migrations/2026-08-27-kingdomaward-ladder-columns.sql
docker compose exec -T ork3testdb mariadb -uork -psecret ork_test < db-migrations/2026-08-27-kingdomaward-ladder-columns.sql
```

Both must succeed with no error. Then confirm the columns and, critically, that
the 24 flagged rows were not disturbed:

```bash
docker compose exec -T ork3db mariadb -uork -psecret ork -N -e "
  SELECT COUNT(*) FROM ork_kingdomaward WHERE is_ladder = 1;"
```

Expected: **24**, unchanged.

- [ ] **Step 4: Confirm migration coverage still passes**

```bash
php tools/ork-db/cli.php drift-check --strict 2>&1 | grep 'migration coverage'
```

Expected: `OK    migration coverage (91 files classified)` — one more than the
90 it reported before this task. The two `FAIL` lines about `class` catalog-hash
drift and live mirror drift are a known pre-existing local-only condition; they
are not yours and must not be "fixed".

- [ ] **Step 5: Commit**

```bash
git add db-migrations/2026-08-27-kingdomaward-ladder-columns.sql tools/ork-db/manifests/migration-classification.json5
git commit -m "Enhancement: migrate ork_kingdomaward ladder columns into the tracked schema"
```

---

### Task 3: Converge every ladder predicate onto the helpers

`Kingdom::GetAwardList` (`class.Kingdom.php:301`) is the headline defect: it **filters** on `ka.is_ladder` but **selects** `a.is_ladder`. One method, two definitions. This task converges all five spellings.

**Files:**
- Modify: `system/lib/ork3/class.Kingdom.php:301` (`GetAwardList`)
- Modify: `system/lib/ork3/class.Player.php:1143` (`AwardsForPlayer`, the `COALESCE(alias.is_ladder, a.is_ladder)` select and the matching `order by` at `:1159`)
- Modify: `system/lib/ork3/class.Report.php` — every `a.is_ladder` / `ka.is_ladder` occurrence
- Test: `tests/Integration/LadderPredicateSqlTest.php` (extend)

**Interfaces:**
- Consumes: `Award::LadderSql()`, `Award::OfficialLadderSql()` from Task 1.
- Produces: `Kingdom::GetAwardList` gains `ka_is_ladder` and `max_level` in its selected columns, and its `is_ladder` column now carries the **effective** value. `Player::AwardsForPlayer` rows gain `ka_is_ladder`; its `IsLadder` response key becomes effective (see Task 12 for the API consequence).

- [ ] **Step 1: Inventory every call site**

```bash
grep -rn 'is_ladder' system/lib/ork3/ orkui/ --include='*.php' --include='*.tpl'
```

Record the list. Each occurrence resolves to exactly one of three outcomes, and you must state which for every line:
- **effective** → `Award::LadderSql()`
- **official** → `Award::OfficialLadderSql()` (cross-kingdom comparison: the global Ladder Grid, and only that)
- **a plain column read on an already-resolved row** → leave alone

- [ ] **Step 2: Write the failing test**

Append to `tests/Integration/LadderPredicateSqlTest.php`:

```php
    public function testGetAwardListSelectsAndFiltersOnTheSameDefinition(): void
    {
        // The headline defect: GetAwardList filtered on ka.is_ladder but selected
        // a.is_ladder, so one method answered "is this a ladder?" two ways.
        $source = file_get_contents(__DIR__ . '/../../system/lib/ork3/class.Kingdom.php');
        $start  = strpos($source, 'public function GetAwardList');
        $this->assertNotFalse($start, 'GetAwardList not found');

        $end  = strpos($source, "\n    public function", $start + 10);
        $body = substr($source, $start, $end - $start);

        $this->assertStringContainsString(
            'Award::LadderSql(',
            $body,
            'GetAwardList must resolve ladders through the shared helper'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\ba\.is_ladder\b/',
            $body,
            'GetAwardList must not spell the predicate by hand'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\bka\.is_ladder\b(?!.*IFNULL)/',
            $body,
            'GetAwardList must not filter on the bare kingdom column'
        );
    }
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter testGetAwardListSelectsAndFiltersOnTheSameDefinition`
Expected: FAIL — "GetAwardList must resolve ladders through the shared helper"

- [ ] **Step 4: Convert `Kingdom::GetAwardList`**

In the SELECT list, replace the `a.is_ladder` projection with the effective predicate and expose the raw kingdom column plus the max alongside it, so callers can tell official from kingdom (requirement 4) and resolve max rank:

```php
                    ' . Award::LadderSql() . ' as is_ladder,
                    IFNULL(ka.is_ladder, 0) as ka_is_ladder,
                    IFNULL(a.is_ladder, 0) as official_is_ladder,
                    IFNULL(ka.max_level, 0) as max_level,
```

In the WHERE/HAVING clause, replace the bare `ka.is_ladder` filter with `Award::LadderSql()`. Note that `LadderSql()` is not a bare column, so if it sits in a `WHERE` alongside an aggregate it may need to move to `HAVING` against the `is_ladder` alias — check the existing clause structure and keep the query shape it already has.

- [ ] **Step 5: Convert `Player::AwardsForPlayer`**

At `class.Player.php:1143`, the current select is:

```php
						COALESCE(alias.is_ladder, a.is_ladder) as is_ladder,
```

An alias award still wins when present, but the kingdom row must now be able to raise a non-alias award. Replace with:

```php
						GREATEST(
							IFNULL(COALESCE(alias.is_ladder, a.is_ladder), 0),
							IFNULL(ka.is_ladder, 0)
						) as is_ladder,
						IFNULL(ka.is_ladder, 0) as ka_is_ladder,
						IFNULL(COALESCE(alias.is_ladder, a.is_ladder), 0) as official_is_ladder,
						IFNULL(ka.max_level, 0) as ka_max_level,
```

Apply the same `GREATEST(...)` expression to the first term of the `order by` at line 1159, so ladder awards keep sorting together now that more of them qualify.

- [ ] **Step 6: Convert the report predicates**

For each `is_ladder` occurrence in `system/lib/ork3/class.Report.php`, apply the outcome you recorded in Step 1. The global Ladder Grid is the **only** surface that stays official-only — it compares across kingdoms, and kingdom ladders are not comparable (two kingdoms' "Order of the Hunter" are different rows). Everything else becomes effective.

- [ ] **Step 7: Run the tests**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter LadderPredicateSqlTest`
Expected: PASS.

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite unit`
Expected: baseline, no new failures.

- [ ] **Step 8: Verify the queries actually run**

A converted query that parses in PHP can still be invalid SQL. Load each affected surface and confirm no error:

```bash
curl -s -b /tmp/ork.jar 'http://localhost:19080/orkui/index.php?Route=Kingdom/awards&KingdomId=1' -o /dev/null -w '%{http_code}\n'
```

(Log in first per `reference_local_curl_auth_session`; the app entrypoint is `/orkui/index.php`, not `/index.php`.)

- [ ] **Step 9: Commit**

```bash
git add system/lib/ork3/class.Kingdom.php system/lib/ork3/class.Player.php system/lib/ork3/class.Report.php tests/Integration/LadderPredicateSqlTest.php
git commit -m "Enhancement: converge ladder predicates onto Award::LadderSql"
```

---

### Task 4: Make `Kingdom::EditAward` write the ladder flag and max rank

`ork_kingdomaward.is_ladder` has **no writer anywhere in the codebase** — all 24 live rows came from direct database access. This task gives it one, and locks the official 16.

**Files:**
- Modify: `system/lib/ork3/class.Kingdom.php:395` (`EditAward`)
- Test: `tests/Integration/EditAwardLadderTest.php` (create)

**Interfaces:**
- Consumes: `Award::MaxRankFor()` from Task 1.
- Produces: `Kingdom::EditAward($request)` accepts two new request keys, `IsLadder` (0|1) and `MaxLevel` (int). Returns the existing response shape: `['Status' => ['Status' => int, 'Message' => string]]`, `Status.Status === 0` on success.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/EditAwardLadderTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ork_kingdomaward.is_ladder gains its first writer, and the official 16 are locked.
 */
final class EditAwardLadderTest extends TestCase
{
    private const MARKER = 'EDITLAD';

    private PDO $pdo;
    private Kingdom $kingdom;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->kingdom = new Kingdom();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM ork_kingdomaward WHERE name LIKE '" . self::MARKER . "%'");
    }

    private function seed(int $awardId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_kingdomaward (kingdom_id, award_id, name, is_ladder, max_level)
             VALUES (1, :award_id, :name, 0, 0)'
        );
        $stmt->execute([':award_id' => $awardId, ':name' => self::MARKER . '-' . uniqid()]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{is_ladder: int, max_level: int} */
    private function readBack(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT is_ladder, max_level FROM ork_kingdomaward WHERE kingdomaward_id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return ['is_ladder' => (int) $row['is_ladder'], 'max_level' => (int) $row['max_level']];
    }

    public function testAKingdomCanLadderifyItsOwnAward(): void
    {
        $id = $this->seed(0);
        $this->kingdom->EditAward([
            'KingdomAwardId' => $id, 'KingdomId' => 1, 'IsLadder' => 1, 'MaxLevel' => 7,
        ]);

        $this->assertSame(['is_ladder' => 1, 'max_level' => 7], $this->readBack($id));
    }

    public function testMaxRankAboveTwelveIsClampedServerSide(): void
    {
        // Rule 2. max="12" client-side is the first line of defence, not the only one.
        $id = $this->seed(0);
        $this->kingdom->EditAward([
            'KingdomAwardId' => $id, 'KingdomId' => 1, 'IsLadder' => 1, 'MaxLevel' => 40,
        ]);

        $this->assertSame(12, $this->readBack($id)['max_level']);
    }

    public function testUnladderingIsAllowedOnAKingdomAward(): void
    {
        $id = $this->seed(0);
        $this->kingdom->EditAward([
            'KingdomAwardId' => $id, 'KingdomId' => 1, 'IsLadder' => 1, 'MaxLevel' => 7,
        ]);
        $this->kingdom->EditAward([
            'KingdomAwardId' => $id, 'KingdomId' => 1, 'IsLadder' => 0, 'MaxLevel' => 7,
        ]);

        $this->assertSame(0, $this->readBack($id)['is_ladder']);
    }

    public function testEditAwardRefusesToClearTheLadderFlagOnAnOfficialAward(): void
    {
        // Requirement 1, second line of defence. award_id 21 = Order of the Rose.
        $id = $this->seed(21);
        $this->pdo->exec("UPDATE ork_kingdomaward SET is_ladder = 1 WHERE kingdomaward_id = {$id}");

        $this->kingdom->EditAward([
            'KingdomAwardId' => $id, 'KingdomId' => 1, 'IsLadder' => 0, 'MaxLevel' => 10,
        ]);

        $this->assertSame(1, $this->readBack($id)['is_ladder']);
    }

    public function testEditAwardRefusesAMaxLevelWriteOnAnOfficialAward(): void
    {
        // The official ladders' shape belongs to Amtgard: one kingdom running Order of
        // the Rose to 12 while others run it to 10 makes ladder reports incomparable.
        $id = $this->seed(21);

        $this->kingdom->EditAward([
            'KingdomAwardId' => $id, 'KingdomId' => 1, 'IsLadder' => 1, 'MaxLevel' => 12,
        ]);

        $this->assertSame(0, $this->readBack($id)['max_level']);
        $this->assertSame(10, Award::MaxRankFor(21, $this->readBack($id)['max_level']));
    }

    public function testUnladderingDoesNotTouchGrantedRanks(): void
    {
        // Rank display is a property of the grant; rank offering is a property of the
        // award. Un-ticking Ladder is forward-only by construction.
        $id = $this->seed(0);
        $this->kingdom->EditAward([
            'KingdomAwardId' => $id, 'KingdomId' => 1, 'IsLadder' => 1, 'MaxLevel' => 10,
        ]);
        $this->pdo->exec(
            "INSERT INTO ork_awards (mundane_id, kingdomaward_id, `rank`, date)
             VALUES (1, {$id}, 4, '2020-01-01')"
        );

        $this->kingdom->EditAward([
            'KingdomAwardId' => $id, 'KingdomId' => 1, 'IsLadder' => 0, 'MaxLevel' => 10,
        ]);

        $stmt = $this->pdo->prepare('SELECT `rank` FROM ork_awards WHERE kingdomaward_id = :id');
        $stmt->execute([':id' => $id]);
        $this->assertSame(4, (int) $stmt->fetchColumn());

        $this->pdo->exec("DELETE FROM ork_awards WHERE kingdomaward_id = {$id}");
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter EditAwardLadderTest`
Expected: FAIL — `is_ladder` stays 0 because nothing writes it.

- [ ] **Step 3: Implement the writer**

In `Kingdom::EditAward` (`class.Kingdom.php:395`), after the existing kingdom-ownership authorization at lines 395-401 (reuse it; no new permission and no new endpoint), read the award's official status and set the two fields:

```php
        // Is this row one of the 16 official Amtgard ladders? If so its ladder
        // configuration belongs to Amtgard, not to the kingdom (requirement 1).
        $this->db->Clear();
        $officialRs = $this->db->DataSet(
            'select IFNULL(a.is_ladder, 0) as official_is_ladder
             from ' . DB_PREFIX . 'kingdomaward ka
             left join ' . DB_PREFIX . 'award a on a.award_id = ka.award_id
             where ka.kingdomaward_id = ' . (int) $request['KingdomAwardId']
        );
        $officialLadder = false;
        if ($officialRs && $officialRs->Next()) {
            $officialLadder = (int) $officialRs->official_is_ladder === 1;
        }

        if (!$officialLadder) {
            // yapo drops null, so write 0 rather than null to clear the flag.
            $award->is_ladder = isset($request['IsLadder']) && (int) $request['IsLadder'] === 1 ? 1 : 0;

            $maxLevel = (int) ($request['MaxLevel'] ?? 0);
            if ($maxLevel < 0) {
                $maxLevel = 0;
            }
            $award->max_level = min(12, $maxLevel); // Rule 2
        }
```

Guarding both writes behind `!$officialLadder` is what makes the official rows reject the flag *and* the max — the spec's deliberate reading of requirement 1. `GREATEST` in `LadderSql()` is the first line of defence, this rejection is the second, and the disabled control in Task 5 is the third.

Ladder and Title? are mutually exclusive — if `IsLadder` is being set to 1, clear `is_title` in the same write.

- [ ] **Step 4: Run the test to verify it passes**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter EditAwardLadderTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.Kingdom.php tests/Integration/EditAwardLadderTest.php
git commit -m "Enhancement: EditAward writes kingdom ladder flag and max rank"
```

---

### Task 5: Manage Awards modal — Ladder and Max Rank controls

**Files:**
- Modify: `orkui/template/revised-frontend/partials/_kingdom_admin_modals.tpl` (Manage Awards row markup, its per-row save JS, and the `<details class="ka-help">` instructions box)
- Modify: `orkui/template/revised-frontend/style/admin-console.css`
- Modify: `orkui/controller/controller.Kingdom.php` (pass the two new fields through to the model on the existing per-row save endpoint)

**Interfaces:**
- Consumes: `Kingdom::EditAward` request keys `IsLadder` and `MaxLevel` from Task 4; the `is_ladder`, `ka_is_ladder`, `official_is_ladder` and `max_level` columns added to `Kingdom::GetAwardList` in Task 3.
- Produces: no new interface. Uses the existing per-row save and the existing `kingdom.award.edit` permission.

- [ ] **Step 1: Normalise the files before editing**

```bash
for f in orkui/template/revised-frontend/partials/_kingdom_admin_modals.tpl \
         orkui/controller/controller.Kingdom.php; do
  echo "$f: $(awk '/^\t/{c++} END{print c+0}' "$f") tab-indented lines"
done
```

If a file reports non-zero, run php-cs-fixer on that file before editing it.

- [ ] **Step 2: Add the two controls to the award row**

Between the existing **Title?** and **Class** cells, add:

```php
<td class="ka-award-ladder-cell">
    <input type="checkbox"
           class="ka-award-ladder"
           data-kaid="<?= (int) $aw['KingdomAwardId'] ?>"
           <?= (int) $aw['is_ladder'] === 1 ? 'checked' : '' ?>
           <?= (int) $aw['official_is_ladder'] === 1 ? 'disabled' : '' ?>
           <?= (int) $aw['official_is_ladder'] === 1
               ? 'data-tip="Standard Amtgard ladder award — this can\'t be changed."'
               : '' ?>>
</td>
<td class="ka-award-maxrank-cell">
    <input type="number"
           class="ka-award-maxrank"
           min="1" max="12" step="1"
           value="<?= (int) ($aw['max_level'] ?: 10) ?>"
           data-kaid="<?= (int) $aw['KingdomAwardId'] ?>"
           <?= (int) $aw['is_ladder'] !== 1 || (int) $aw['official_is_ladder'] === 1
               ? 'disabled' : '' ?>
           <?= (int) $aw['official_is_ladder'] === 1
               ? 'data-tip="Standard Amtgard ladder award — this can\'t be changed."'
               : '' ?>>
</td>
```

Add matching `<th>Ladder</th>` and `<th>Max Rank</th>` header cells in the same positions.

Note `data-tip`, never native `title=` — house rule.

- [ ] **Step 3: Wire the row behaviour**

In the modal's JS block, inside the existing per-row change handler:

```js
// Max Rank is only meaningful while Ladder is ticked.
document.querySelectorAll('.ka-award-ladder').forEach(function (cb) {
    cb.addEventListener('change', function () {
        var row = cb.closest('tr');
        var max = row.querySelector('.ka-award-maxrank');
        var title = row.querySelector('.ka-award-title');
        if (max) {
            max.disabled = !cb.checked;
        }
        // Ladder and Title? are mutually exclusive.
        if (cb.checked && title) {
            title.checked = false;
        }
    });
});

document.querySelectorAll('.ka-award-title').forEach(function (tb) {
    tb.addEventListener('change', function () {
        var row = tb.closest('tr');
        var ladder = row.querySelector('.ka-award-ladder');
        if (tb.checked && ladder && !ladder.disabled) {
            ladder.checked = false;
            var max = row.querySelector('.ka-award-maxrank');
            if (max) {
                max.disabled = true;
            }
        }
    });
});
```

In the per-row save payload builder, add the two fields:

```js
payload.IsLadder = row.querySelector('.ka-award-ladder').checked ? 1 : 0;
payload.MaxLevel = parseInt(row.querySelector('.ka-award-maxrank').value, 10) || 10;
```

- [ ] **Step 4: Pass the fields through the controller**

In `controller.Kingdom.php`, on the existing award-save branch, add `IsLadder` and `MaxLevel` to the request array handed to the model. No new endpoint and no new permission — `Kingdom::EditAward` already authorizes against the award's own kingdom.

- [ ] **Step 5: Style the cells, both themes**

In `orkui/template/revised-frontend/style/admin-console.css`:

```css
.ka-award-ladder-cell,
.ka-award-maxrank-cell {
    text-align: center;
    white-space: nowrap;
}
.ka-award-maxrank {
    width: 60px;
    text-align: center;
}
.ka-award-maxrank:disabled,
.ka-award-ladder:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

html[data-theme="dark"] .ka-award-maxrank {
    background: #2d3748;
    color: #e2e8f0;
    border-color: #4a5568;
}
html[data-theme="dark"] .ka-award-maxrank:disabled,
html[data-theme="dark"] .ka-award-ladder:disabled {
    opacity: 0.4;
}
```

- [ ] **Step 6: Add the Ladder entry to the instructions box**

In the Manage Awards `<details class="ka-help">` block, add:

> **Ladder** — a ladder award is granted in ranks, and players climb it over time. The standard Amtgard orders are set by Amtgard and locked here. You can turn any of your kingdom's own awards into a ladder and set its Max Rank (up to 12). Un-ticking Ladder only stops *new* ranks being offered — ranks already granted keep showing exactly as they are.

- [ ] **Step 7: Verify in the browser, both themes**

Open Kingdom Admin → Manage Awards. Confirm:
- an official row shows Ladder ticked-and-disabled, Max Rank disabled, and the lock tooltip on hover
- a kingdom row toggles Ladder, which enables/disables Max Rank
- ticking Ladder unticks Title? and vice versa
- Max Rank refuses a value above 12
- a saved row survives a reload
- the row reads correctly in dark mode

- [ ] **Step 8: Commit**

```bash
git add orkui/template/revised-frontend/partials/_kingdom_admin_modals.tpl orkui/template/revised-frontend/style/admin-console.css orkui/controller/controller.Kingdom.php
git commit -m "Enhancement: Ladder and Max Rank controls in Manage Awards"
```

---

### Task 6: Bonus grants in `GetLadderProgress`

An unranked ladder grant currently means one thing — *a historical record whose rank was never captured*. The star pill (Task 7) introduces a second, legitimate kind: recognition past the top. This task teaches `GetLadderProgress` to tell them apart, and must land **before** the star pill so no star grant is ever misread as broken data.

**Files:**
- Modify: `system/lib/ork3/class.Player.php:1553-1650` (`GetLadderProgress`)
- Test: `tests/Unit/LadderProgressBonusTest.php` (create)

**Interfaces:**
- Consumes: `Award::MaxRankFor()` from Task 1; `AwardsForPlayer` rows, which already carry `Rank` and `Date` on the same row (`class.Player.php:1173-1174`) — no new query is needed.
- Produces: each `$progress[$awardId]` entry gains `'BonusCount' => int` and keeps `'Rank'`, `'MaxRank'`, `'Approx'`, `'HasMaster'`, `'Name'`, `'Short'`. `'RankSet'` and `'UnrankedCount'` remain internal and are still unset before return.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/LadderProgressBonusTest.php`. It exercises the classification as a pure function over award rows, mirroring the shape `GetLadderProgress` consumes:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Bonus grants: an unranked ladder grant dated after the player reached max rank is
 * deliberate recognition, not a broken record. It must not set ~ or inflate the rank.
 */
final class LadderProgressBonusTest extends TestCase
{
    /**
     * @param list<array{Rank: int, Date: string}> $grants
     * @return array{Rank: int, Approx: bool, BonusCount: int, MaxRank: int}
     */
    private function progressFor(array $grants, int $awardId = 21): array
    {
        $player = new Player();

        return $player->ClassifyLadderGrants($awardId, 0, $grants, false);
    }

    public function testUnrankedGrantAfterMaxIsBonus(): void
    {
        $grants = [];
        for ($rank = 1; $rank <= 10; $rank++) {
            $grants[] = ['Rank' => $rank, 'Date' => '2020-0' . min(9, $rank) . '-01'];
        }
        $grants[] = ['Rank' => 0, 'Date' => '2024-01-01'];
        $grants[] = ['Rank' => 0, 'Date' => '2025-01-01'];

        $result = $this->progressFor($grants);

        $this->assertSame(2, $result['BonusCount']);
        $this->assertFalse($result['Approx'], 'bonus grants must not set the ~ marker');
        $this->assertSame(10, $result['Rank'], 'bonus grants must not inflate the rank');
    }

    public function testUnrankedGrantBeforeMaxIsStillUnreconciled(): void
    {
        $grants = [
            ['Rank' => 0, 'Date' => '2019-01-01'],
        ];
        for ($rank = 1; $rank <= 10; $rank++) {
            $grants[] = ['Rank' => $rank, 'Date' => '2020-0' . min(9, $rank) . '-01'];
        }

        $result = $this->progressFor($grants);

        $this->assertSame(0, $result['BonusCount']);
        $this->assertTrue($result['Approx'], 'a pre-max unranked grant is still broken data');
    }

    public function testTieOnTheMaxRankDateCountsAsUnreconciled(): void
    {
        // The conservative side of the line: a false "needs reconciling" is a
        // dismissible prompt; a false "bonus" silently hides a broken record.
        $grants = [];
        for ($rank = 1; $rank <= 9; $rank++) {
            $grants[] = ['Rank' => $rank, 'Date' => '2020-0' . $rank . '-01'];
        }
        $grants[] = ['Rank' => 10, 'Date' => '2021-06-01'];
        $grants[] = ['Rank' => 0,  'Date' => '2021-06-01'];

        $result = $this->progressFor($grants);

        $this->assertSame(0, $result['BonusCount']);
        $this->assertTrue($result['Approx']);
    }

    public function testPlayerWhoNeverReachedMaxHasNoBonusGrants(): void
    {
        // Matches today's behaviour exactly for the entire legacy corpus.
        $grants = [
            ['Rank' => 1, 'Date' => '2020-01-01'],
            ['Rank' => 2, 'Date' => '2020-02-01'],
            ['Rank' => 0, 'Date' => '2024-01-01'],
        ];

        $result = $this->progressFor($grants);

        $this->assertSame(0, $result['BonusCount']);
        $this->assertTrue($result['Approx']);
    }

    public function testKingdomLadderUsesItsOwnMaxRank(): void
    {
        $grants = [];
        for ($rank = 1; $rank <= 5; $rank++) {
            $grants[] = ['Rank' => $rank, 'Date' => '2020-0' . $rank . '-01'];
        }
        $grants[] = ['Rank' => 0, 'Date' => '2024-01-01'];

        $player = new Player();
        $result = $player->ClassifyLadderGrants(0, 5, $grants, false);

        $this->assertSame(5, $result['MaxRank']);
        $this->assertSame(1, $result['BonusCount'], 'max is 5 here, so the late unranked grant is bonus');
    }

    public function testHasMasterStillSuppressesTheMarker(): void
    {
        $grants = [
            ['Rank' => 1, 'Date' => '2020-01-01'],
            ['Rank' => 0, 'Date' => '2020-02-01'],
        ];

        $player = new Player();
        $result = $player->ClassifyLadderGrants(21, 0, $grants, true);

        $this->assertFalse($result['Approx'], 'existing HasMaster suppression is unchanged');
    }

    public function testPreExistingRankAboveMaxIsStillClampedForDisplay(): void
    {
        // Characterisation, not a new rule: the tile has always clamped a display
        // rank to max, and Playernew_index.tpl:2127 clamps again downstream. The
        // spec's "ranks above max are not rewritten" is about the ork_awards.rank
        // column, which nothing here writes — asserted in EditAwardLadderTest.
        $grants = [['Rank' => 14, 'Date' => '2015-01-01']];

        $result = $this->progressFor($grants);

        $this->assertSame(10, $result['Rank']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite unit --filter LadderProgressBonusTest`
Expected: FAIL — `Error: Call to undefined method Player::ClassifyLadderGrants()`

- [ ] **Step 3: Extract the classification into a testable method**

Add to `system/lib/ork3/class.Player.php`, just above `GetLadderProgress` (line 1553):

```php
    /**
     * Classify one award's grants for a player into rank, bonus grants and the ~ marker.
     *
     * A bonus grant is an unranked grant dated later than the date the player first
     * reached max rank — deliberate recognition past the top of the ladder, not a
     * record whose rank was never captured. Ties on that date count as unreconciled:
     * a false "needs reconciling" is a dismissible prompt, whereas a false "bonus"
     * silently hides a genuinely broken record.
     *
     * @param list<array{Rank: int|string, Date: string}> $grants
     * @return array{Rank: int, MaxRank: int, Approx: bool, BonusCount: int}
     */
    public function ClassifyLadderGrants(
        int $awardId,
        int $kaMaxLevel,
        array $grants,
        bool $hasMaster
    ): array {
        $maxRank = Award::MaxRankFor($awardId, $kaMaxLevel);

        $rankSet    = [];
        $topRank    = 0;
        $maxReached = null;

        foreach ($grants as $grant) {
            $rank = (int) $grant['Rank'];
            if ($rank <= 0) {
                continue;
            }
            $rankSet[$rank] = true;
            if ($rank > $topRank) {
                $topRank = $rank;
            }
            if ($rank >= $maxRank) {
                $date = (string) $grant['Date'];
                if ($maxReached === null || $date < $maxReached) {
                    $maxReached = $date;
                }
            }
        }

        $unrankedCount = 0;
        $bonusCount    = 0;
        foreach ($grants as $grant) {
            if ((int) $grant['Rank'] > 0) {
                continue;
            }
            // Strictly later than the max-rank date; a tie is unreconciled.
            if ($maxReached !== null && (string) $grant['Date'] > $maxReached) {
                $bonusCount++;
            } else {
                $unrankedCount++;
            }
        }

        $effectiveCount = count($rankSet) + $unrankedCount;

        return [
            // Identical to the clamp this replaces (was class.Player.php:1623).
            // Bonus grants are simply absent from $unrankedCount, so they no longer
            // inflate $effectiveCount. Nothing else about the arithmetic changes.
            'Rank'       => min($maxRank, max($topRank, $effectiveCount)),
            'MaxRank'    => $maxRank,
            'Approx'     => ($effectiveCount > $topRank) && !$hasMaster,
            'BonusCount' => $bonusCount,
        ];
    }
```

The `Rank` arithmetic is byte-for-byte the behaviour of the line it replaces. The only change is which grants reach `$unrankedCount`: bonus grants are counted separately and never inflate the total. The tile still clamps a display rank to `maxRank` — that is pre-existing and correct (`Playernew_index.tpl:2127` clamps too). The spec's "ranks above max are not rewritten" is a guarantee about the `ork_awards.rank` **column**, which no path in this plan writes; it is asserted in Task 4.

- [ ] **Step 4: Call it from `GetLadderProgress`**

Replace the aggregation loop at lines 1612-1640 so it collects the raw grants per award and then calls `ClassifyLadderGrants()`. Delete the inline `(($lpAid === 30) ? 12 : 10)` at line 1636 — that is now `Award::MaxRankFor()`'s job, and this is one of the three duplicated Zodiac special cases the spec retires.

Pass `ka_max_level` through from the query (added in Task 3) as the `$kaMaxLevel` argument, so kingdom ladders resolve their own max.

- [ ] **Step 5: Run the test to verify it passes**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite unit --filter LadderProgressBonusTest`
Expected: PASS, 7 tests.

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite unit`
Expected: baseline, no new failures.

- [ ] **Step 6: Commit**

```bash
git add system/lib/ork3/class.Player.php tests/Unit/LadderProgressBonusTest.php
git commit -m "Enhancement: distinguish bonus ladder grants from unreconciled ones"
```

---

### Task 7: The star pill

**Files:**
- Modify: `orkui/template/revised-frontend/style/revised.css:1337` (add `-star` to the existing grouped pill selector)
- Modify: `orkui/template/revised-frontend/script/revised.js` (the shared pill builder behind all eight pickers)
- Test: `tests/Unit/StarPillTest.php` (create — covers the server-side decision; the DOM is verified in the browser)

**Interfaces:**
- Consumes: `Award::MaxRankFor()` from Task 1; `BonusCount` from Task 6.
- Produces: the pill builder reads `data-max-rank` and `data-current-rank` off the pills wrap and renders a trailing `✱` pill with `data-rank="0"` when `currentRank >= maxRank`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/StarPillTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The star expresses recognition past the top of a ladder. It submits an unranked
 * grant — never max + 1 — so no grant ever carries a rank above the award's max.
 */
final class StarPillTest extends TestCase
{
    public function testStarIsOfferedAtMaxRank(): void
    {
        $this->assertTrue(Award::OffersStar(21, 0, 10));
    }

    public function testStarIsNotOfferedBelowMaxRank(): void
    {
        $this->assertFalse(Award::OffersStar(21, 0, 9));
        $this->assertFalse(Award::OffersStar(21, 0, 0));
    }

    public function testStarIsOfferedAboveMaxRank(): void
    {
        // Imported records can already exceed max; they still get the star.
        $this->assertTrue(Award::OffersStar(21, 0, 14));
    }

    public function testStarIsAvailableOnOfficialLaddersDespiteTheirLockedMax(): void
    {
        // Locking the shape of an official ladder does not restrict recognising
        // someone who has finished one.
        $this->assertTrue(Award::OffersStar(21, 0, 10));
        $this->assertTrue(Award::OffersStar(30, 0, 12));
        $this->assertFalse(Award::OffersStar(30, 0, 10), 'Zodiac max is 12, not 10');
    }

    public function testStarOnAKingdomLadderUsesTheKingdomMax(): void
    {
        $this->assertTrue(Award::OffersStar(0, 5, 5));
        $this->assertFalse(Award::OffersStar(0, 5, 4));
        $this->assertTrue(Award::OffersStar(0, 12, 12));
        $this->assertFalse(Award::OffersStar(0, 12, 11));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite unit --filter StarPillTest`
Expected: FAIL — `Error: Call to undefined method Award::OffersStar()`

- [ ] **Step 3: Add the predicate**

In `system/lib/ork3/class.Award.php`, after `MaxRankFor()`:

```php
    /**
     * Whether the star pill should be offered: the recipient is already at or past
     * the top of this ladder, so further recognition is expressed as an unranked
     * grant rather than an out-of-range rank number.
     */
    public static function OffersStar(int $awardId, int $kaMaxLevel, int $currentRank): bool
    {
        return $currentRank >= self::MaxRankFor($awardId, $kaMaxLevel);
    }
```

- [ ] **Step 4: Render the pill**

In `revised.css`, add `-star` to the existing grouped pill selector at line 1337 (`.pn-rank-pill, .kn-rank-pill, .pk-rank-pill`) so it inherits the 36px circle, then add the modifier and its dark counterpart:

```css
.pn-rank-star,
.kn-rank-star,
.pk-rank-star {
    border-color: #b7791f;
    color: #b7791f;
    font-size: 16px;
    line-height: 1;
}
.pn-rank-star.-selected,
.kn-rank-star.-selected,
.pk-rank-star.-selected {
    background: #b7791f;
    color: #fff;
}
.pn-rank-star-note,
.kn-rank-star-note,
.pk-rank-star-note {
    margin-top: 6px;
    font-size: 12px;
    color: #4a5568;
}

html[data-theme="dark"] .pn-rank-star,
html[data-theme="dark"] .kn-rank-star,
html[data-theme="dark"] .pk-rank-star {
    border-color: #d69e2e;
    color: #d69e2e;
}
html[data-theme="dark"] .pn-rank-star.-selected,
html[data-theme="dark"] .kn-rank-star.-selected,
html[data-theme="dark"] .pk-rank-star.-selected {
    background: #d69e2e;
    color: #1a202c;
}
html[data-theme="dark"] .pn-rank-star-note,
html[data-theme="dark"] .kn-rank-star-note,
html[data-theme="dark"] .pk-rank-star-note {
    color: #a0aec0;
}
```

In `revised.js`, in the shared pill builder, after the numbered pills loop:

```js
var maxRank     = parseInt(wrap.getAttribute('data-max-rank'), 10) || 10;
var currentRank = parseInt(wrap.getAttribute('data-current-rank'), 10) || 0;

// Numbered pills run 1..maxRank, not a hardcoded 10.
for (var r = 1; r <= maxRank; r++) { /* existing pill construction */ }

if (currentRank >= maxRank) {
    var star = document.createElement('button');
    star.type = 'button';
    star.className = prefix + '-rank-pill ' + prefix + '-rank-star';
    star.setAttribute('data-rank', '0');       // unranked, never maxRank + 1
    star.setAttribute('data-tip', 'Recognise them again, past the top of the ladder');
    star.textContent = '✱';
    star.addEventListener('click', function () {
        var note = wrap.parentNode.querySelector('.' + prefix + '-rank-star-note');
        if (note) {
            note.textContent = 'The standard cap for this award is ' + maxRank +
                ' — but don’t let that stop you from recognizing someone!';
            note.hidden = false;
        }
    });
    wrap.appendChild(star);
}
```

- [ ] **Step 5: Feed the two data attributes on all eight pickers**

Each wrap gets `data-max-rank` and `data-current-rank`. The eight wraps are:

| Wrap class | Surface |
|---|---|
| `pn-rank-pills` | Grant award — player profile |
| `pn-rec-rank-pills` | Recommend award — player profile |
| `pn-edit-rank-pills` | Edit an existing award |
| `pn-edit-reconcile-rank-pills` | Reconcile a historical award |
| `kn-rank-pills` | Grant — kingdom profile |
| `kn-rec-rank-pills` | Recommend — kingdom profile |
| `pk-rank-pills` | Grant — park profile |
| `pk-rec-rank-pills` | Recommend — park profile |

All eight are built by the same `revised.js` builder, so the star and the real max rank are implemented once. Confirm none of them still hardcodes 10:

```bash
grep -rn 'rank-pills' orkui/template/revised-frontend/ | grep -v '\.css:'
grep -rn 'r <= 10\|i <= 10' orkui/template/revised-frontend/script/revised.js
```

The second grep must return nothing.

- [ ] **Step 6: Run the test to verify it passes**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite unit --filter StarPillTest`
Expected: PASS, 5 tests.

- [ ] **Step 7: Verify in the browser**

On a player at 9/10 of an official order: no star. At 10/10: star present, and selecting it shows the cap note and submits `rank=0`. On a 12-rank kingdom ladder: twelve numbered pills, star only at 12. Check both themes.

- [ ] **Step 8: Commit**

```bash
git add system/lib/ork3/class.Award.php orkui/template/revised-frontend/style/revised.css orkui/template/revised-frontend/script/revised.js tests/Unit/StarPillTest.php
git commit -m "Enhancement: star pill for recognition past the top of a ladder"
```

---

### Task 8: Rule 1 — a ladder grant must carry a rank

**Files:**
- Modify: `system/lib/ork3/class.Player.php:3386` (`AddAward`)
- Test: `tests/Integration/LadderGrantRuleTest.php` (create)

**Interfaces:**
- Consumes: `Award::MaxRankFor()`, `Award::OffersStar()`, `Award::LadderSql()`.
- Produces: `Player::AddAward` returns the existing shape `['Status' => ['Status' => int, 'Message' => string]]` and now returns a non-zero `Status` with the Rule 1 message when a below-max ladder grant arrives unranked.

**Where this lives.** The spec says `Model_Player::add_player_award`, but that method (`orkui/model/model.Player.php:121-128`) is a four-line membrane wrapper over `Player::AddAward` and the layering rule forbids business logic there. `Player::AddAward` is the domain method every one of the four callers funnels through, so it is both the correct layer and the same single choke point. All four callers still inherit the rule:

- `orkui/controller/controller.Award.php:107` (Award/kingdom, Award/park)
- `orkui/controller/controller.Player.php:132`
- `orkui/controller/controller.Admin.php:1126`

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/LadderGrantRuleTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Rule 1: a ladder grant must carry a rank in 1..max, unless the recipient is
 * already at or past max — the star path.
 */
final class LadderGrantRuleTest extends TestCase
{
    private Player $player;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->player = new Player();
    }

    public function testUnrankedLadderGrantIsRejectedForAPlayerBelowMax(): void
    {
        $result = $this->player->AddAward([
            'RecipientId' => 1, 'AwardId' => 21, 'Rank' => 0, 'Date' => '2026-01-01',
        ]);

        $this->assertNotSame(0, (int) $result['Status']['Status']);
        $this->assertStringContainsString('is a ranked award', $result['Status']['Message']);
        $this->assertStringContainsString("\u{2731}", $result['Status']['Message']);
    }

    public function testTheRejectionMessageNamesTheAwardAndTheMax(): void
    {
        $result = $this->player->AddAward([
            'RecipientId' => 1, 'AwardId' => 21, 'Rank' => 0, 'Date' => '2026-01-01',
        ]);

        $this->assertStringContainsString('10', $result['Status']['Message']);
        $this->assertStringContainsString('choose a rank', $result['Status']['Message']);
    }

    public function testRankedLadderGrantIsAccepted(): void
    {
        $result = $this->player->AddAward([
            'RecipientId' => 1, 'AwardId' => 21, 'Rank' => 3, 'Date' => '2026-01-01',
        ]);

        $this->assertSame(0, (int) $result['Status']['Status']);
    }

    public function testNonLadderGrantIsUnaffectedByRuleOne(): void
    {
        // award_id 1 = Master Rose, a peerage, not a ladder.
        $result = $this->player->AddAward([
            'RecipientId' => 1, 'AwardId' => 1, 'Rank' => 0, 'Date' => '2026-01-01',
        ]);

        $this->assertSame(0, (int) $result['Status']['Status']);
    }
}
```

Seed and tear down the recipient's award history in `setUp`/`tearDown` the way `EditAwardLadderTest` does, so "below max" and "at max" are controlled rather than inherited from whatever the fixture database happens to hold.

- [ ] **Step 2: Run the test to verify it fails**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter LadderGrantRuleTest`
Expected: FAIL — the unranked grant is accepted.

- [ ] **Step 3: Implement Rule 1**

In `Player::AddAward` (`class.Player.php:3386`), before the insert:

```php
        // Rule 1: an unranked grant of an effective ladder award is allowed only when
        // the recipient is already at or past max — the star path. Granting a ladder
        // award with no rank to someone below max is the mistake that produced the
        // rankless ladder grants this feature exists to stop.
        $rank = (int) ($request['Rank'] ?? 0);
        if ($rank === 0 && $this->IsEffectiveLadder($awardId, $kingdomAwardId)) {
            $maxRank     = Award::MaxRankFor($awardId, $kaMaxLevel);
            $currentRank = $this->CurrentLadderRank((int) $request['RecipientId'], $awardId, $kingdomAwardId);

            if (!Award::OffersStar($awardId, $kaMaxLevel, $currentRank)) {
                return [
                    'Status' => InvalidParameter(null, sprintf(
                        '%s is a ranked award — choose a rank, or use %s if they have already reached %d.',
                        $awardName,
                        "\u{2731}",
                        $maxRank
                    )),
                ];
            }
        }
```

Add the two private helpers alongside it. `IsEffectiveLadder()` resolves the award row through `Award::LadderSql()`; `CurrentLadderRank()` returns the player's highest granted rank for that award. Both need `$this->db->Clear()` before their query and a manual `->Next()` before reading fields.

Zodiac is exempt from Rule 1 — a monthless Zodiac is accepted, because 2,024 already exist. Guard the check with `!Award::IsMonthlyLadder($awardId)`. The companion Zodiac plan depends on this exemption already being in place.

- [ ] **Step 4: Run the test to verify it passes**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter LadderGrantRuleTest`
Expected: PASS.

- [ ] **Step 5: Verify all four callers inherit it**

Grant an unranked ladder award from each of the four surfaces (player profile, kingdom profile, park profile, admin) and confirm each is rejected with the same message.

- [ ] **Step 6: Commit**

```bash
git add system/lib/ork3/class.Player.php tests/Integration/LadderGrantRuleTest.php
git commit -m "Enhancement: reject unranked ladder grants below max rank"
```

---

### Task 9: Grant-from-recommendation bucketing

`Kingdomnew_recommendations_panel.tpl:75` buckets rows with `(int)$rec['Rank'] > 0 ? 'below' : 'nonladder'` — using *rank present* as a proxy for *is a ladder*. A kingdom-ladder recommendation has no rank today, so it files under "Non-Ladder Awards & Titles" and the officer is told to Grant-or-Delete a ranked award as if it were flat.

**Files:**
- Modify: `orkui/template/revised-frontend/Kingdomnew_recommendations_panel.tpl:75` (bucketing) and `:111` (the Grant button's rank passthrough)
- Modify: `system/lib/ork3/class.Report.php` (`recommended_awards`, `recommended_awards_count`) so the rows carry the effective-ladder flag
- Test: `tests/Integration/RecommendationBucketTest.php` (create)

**Interfaces:**
- Consumes: the effective `is_ladder` column added to the recommendation queries in Task 3.
- Produces: recommendation rows carry `IsLadder` (effective, 0|1), `MaxRank` (int) and `KaMaxLevel` (int).

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/RecommendationBucketTest.php` asserting that a recommendation for a kingdom-ladder award buckets as `below`, not `nonladder`:

```php
    public function testKingdomLadderRecommendationBucketsAsBelow(): void
    {
        $rec = ['Rank' => 0, 'IsLadder' => 1];
        $this->assertSame('below', $this->bucketFor($rec));
    }

    public function testGenuineNonLadderRecommendationStillBucketsAsNonladder(): void
    {
        $rec = ['Rank' => 0, 'IsLadder' => 0];
        $this->assertSame('nonladder', $this->bucketFor($rec));
    }

    public function testRankedLadderRecommendationStillBucketsAsBelow(): void
    {
        $rec = ['Rank' => 4, 'IsLadder' => 1];
        $this->assertSame('below', $this->bucketFor($rec));
    }
```

`bucketFor()` mirrors the template's expression, the way `AwardOptionGroupsTest::mirrorCategorizeSampleAwards()` mirrors its subject.

- [ ] **Step 2: Run the test to verify it fails**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter RecommendationBucketTest`
Expected: FAIL — the kingdom-ladder row buckets as `nonladder`.

- [ ] **Step 3: Move the bucketing onto the effective-ladder flag**

At `Kingdomnew_recommendations_panel.tpl:75`, replace:

```php
$bucket = (int)$rec['Rank'] > 0 ? 'below' : 'nonladder';
```

with:

```php
// Bucket on what the award *is*, not on whether this row happens to carry a rank.
// A kingdom-ladder recommendation has no rank yet and must not file as non-ladder.
$bucket = (int) $rec['IsLadder'] === 1 ? 'below' : 'nonladder';
```

- [ ] **Step 4: Make the Grant button carry the max rank**

At line 111, the Grant button passes the rec's `Rank` straight into the grant. Add `data-max-rank` and `data-ka-max-level` so the grant modal's pill builder (Task 7) can offer the right number of pills and the star:

```php
data-max-rank="<?= (int) $rec['MaxRank'] ?>"
data-ka-max-level="<?= (int) $rec['KaMaxLevel'] ?>"
```

- [ ] **Step 5: Supply the fields from the report queries**

In `Report::recommended_awards` and `recommended_awards_count`, add the effective-ladder column and `ka.max_level` to the select. Both take `IncludeLadder`/`LadderMinimum`; make those honour the effective ladder too, so a kingdom ladder is included wherever an official one would be. Callers to check: `controller.Player.php:248`, `controller.PlayerAjax.php:641`, `controller.Kingdom.php:135` and `:138`, and the count at `:423`.

- [ ] **Step 6: Run the test to verify it passes**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter RecommendationBucketTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add orkui/template/revised-frontend/Kingdomnew_recommendations_panel.tpl system/lib/ork3/class.Report.php tests/Integration/RecommendationBucketTest.php
git commit -m "Enhancement: bucket kingdom-ladder recommendations as ladder awards"
```

---

### Task 10: Reconciliation and the awards-tab tile

**Files:**
- Modify: `orkui/template/revised-frontend/Playernew_reconcile.tpl:124` (the explanatory copy) and `:185` (the `($aid === 30) ? 12 : 10` duplicate)
- Modify: `orkui/template/revised-frontend/Playernew_index.tpl` (the ladder tile)
- Modify: `orkui/template/revised-frontend/style/revised.css`

**Interfaces:**
- Consumes: `BonusCount` and `MaxRank` from `ClassifyLadderGrants()` (Task 6); `Award::MaxRankFor()` (Task 1).
- Produces: no new interface.

- [ ] **Step 1: Retire the third Zodiac duplicate**

At `Playernew_reconcile.tpl:185`, replace `($aid === 30) ? 12 : 10` with the value already resolved server-side and passed into the template. Templates are presentation; the max rank is a domain fact and must arrive resolved, not be recomputed here. This is the last of the three scattered copies.

Confirm none remain:

```bash
grep -rn '=== 30\|== 30' orkui/template/ system/lib/ork3/ | grep -i 'zodiac\|12 : 10\|rank'
```

Expected: no matches.

- [ ] **Step 2: Exclude bonus grants from the reconciliation list**

Filter the list to grants where `Rank === 0` **and** the grant is not classified bonus. The page tells the player these are records "not matched to your official award history yet" (`:124`) — actively wrong about a deliberate star grant, and an invitation to assign it a rank it should never have.

- [ ] **Step 3: Show bonus grants on the tile**

Add a quiet `✱N` beside the rank when `BonusCount > 0`:

```php
<?php if ((int) $lp['BonusCount'] > 0): ?>
    <span class="pn-ladder-bonus"
          data-tip="<?= (int) $lp['BonusCount'] ?> further recognition<?= (int) $lp['BonusCount'] === 1 ? '' : 's' ?> past the top of this ladder">
        &#10033;<?= (int) $lp['BonusCount'] ?>
    </span>
<?php endif; ?>
```

A player at 10/10 recognised twice more now shows **10 / 10 ✱2** instead of today's `~10 / 10`, which implied broken data.

- [ ] **Step 4: Style it, both themes**

```css
.pn-ladder-bonus {
    margin-left: 4px;
    font-size: 11px;
    color: #b7791f;
    white-space: nowrap;
}

html[data-theme="dark"] .pn-ladder-bonus {
    color: #d69e2e;
}
```

- [ ] **Step 5: Separate official and kingdom ladder tiles**

Group the awards-tab tiles into two labelled groups — "Ladder Awards" and "{Kingdom} Ladder Awards" — so requirement 4 holds on the profile as well as in the pickers.

- [ ] **Step 6: Verify in the browser**

Find a player with an unranked ladder grant dated after they hit max. Confirm: no `~`, rank reads at max, `✱N` present, and the grant is absent from Reconcile. Then find one with a pre-max unranked grant and confirm nothing changed for them. Both themes.

- [ ] **Step 7: Commit**

```bash
git add orkui/template/revised-frontend/Playernew_reconcile.tpl orkui/template/revised-frontend/Playernew_index.tpl orkui/template/revised-frontend/style/revised.css
git commit -m "Enhancement: bonus grants on the ladder tile, excluded from reconciliation"
```

---

### Task 11: Reports and the Ladder Grid

**Files:**
- Modify: `system/lib/ork3/class.Report.php` (`kingdom_awards`, the Ladder Grid builder)
- Modify: the Ladder Grid template
- Test: `tests/Integration/LadderGridTest.php` (extend — the file already exists)

**Interfaces:**
- Consumes: `Award::LadderSql()`, `Award::OfficialLadderSql()`.
- Produces: the kingdom-scoped grid's column list gains kingdom entries keyed on `kingdomaward_id`; the global grid's column list is unchanged.

- [ ] **Step 1: Write the failing test**

Extend `tests/Integration/LadderGridTest.php`:

```php
    public function testKingdomScopedGridAppendsKingdomLadderColumns(): void
    {
        $columns = $this->gridColumnsFor(['KingdomId' => 1]);
        $kingdomColumns = array_filter($columns, fn ($c) => ($c['Scope'] ?? '') === 'kingdom');

        $this->assertNotEmpty($kingdomColumns, 'kingdom ladders must appear in the kingdom grid');
        foreach ($kingdomColumns as $column) {
            $this->assertArrayHasKey('KingdomAwardId', $column);
            $this->assertGreaterThan(0, (int) $column['KingdomAwardId']);
        }
    }

    public function testKingdomColumnsComeAfterTheOfficialOnes(): void
    {
        $columns = array_values($this->gridColumnsFor(['KingdomId' => 1]));
        $scopes  = array_map(fn ($c) => $c['Scope'] ?? 'official', $columns);
        $firstKingdom = array_search('kingdom', $scopes, true);

        if ($firstKingdom === false) {
            $this->markTestSkipped('No kingdom ladders in this fixture.');
        }
        $this->assertNotContains(
            'official',
            array_slice($scopes, $firstKingdom),
            'official columns must all precede the kingdom group'
        );
    }

    public function testGlobalGridStaysOfficialOnly(): void
    {
        // Kingdom ladders are not comparable across kingdoms: columns are keyed on
        // award_id, which kingdom ladders lack (0, or the shared 94 placeholder).
        // Two kingdoms' "Order of the Hunter" are different rows.
        $columns = $this->gridColumnsFor([]);
        foreach ($columns as $column) {
            $this->assertNotSame('kingdom', $column['Scope'] ?? 'official');
        }
    }

    public function testWalkerRemainsExcluded(): void
    {
        foreach ([[], ['KingdomId' => 1]] as $request) {
            $ids = array_column($this->gridColumnsFor($request), 'AwardId');
            $this->assertNotContains(31, $ids, 'Walker stays excluded from ladder reports');
        }
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter LadderGridTest`
Expected: FAIL — no kingdom columns.

- [ ] **Step 3: Implement**

In the grid builder, keep the official columns exactly as they are (keyed on `award_id`, selected with `Award::OfficialLadderSql()`, Walker excluded). When a `KingdomId` is supplied, append a second group selected with `Award::LadderSql()` and keyed on `kingdomaward_id`, each column tagged `'Scope' => 'kingdom'`.

Give `Report::kingdom_awards` the effective predicate for its `IncludeLadder`/`LadderMinimum` handling.

- [ ] **Step 4: Separate the groups visually**

In the grid template, render the kingdom columns after the official ones under their own header, so requirement 4 holds here too. Add a dark-mode counterpart for any new colour.

- [ ] **Step 5: Run the test to verify it passes**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter LadderGridTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add system/lib/ork3/class.Report.php orkui/template/revised-frontend/ tests/Integration/LadderGridTest.php
git commit -m "Enhancement: kingdom ladder columns in the kingdom-scoped Ladder Grid"
```

---

### Task 12: API compatibility for the widened `IsLadder`

`Player::AwardsForPlayer` feeds the `AwardElementType` struct consumed over both SOAP and the JSON API (`orkservice/Json/index.php`). Task 3 widened its `IsLadder` from official-only to effective, so a consumer filtering `IsLadder == 1` starts seeing kingdom ladders. That is the point of the feature, but the consumer did not ask for it — so the old meaning must stay reachable.

**Files:**
- Modify: `system/lib/ork3/class.Player.php:1186` (the `AwardsForPlayer` response map)
- Modify: `orkservice/Player/PlayerService.definitions.php:189-210` (`AwardElementType`)
- Test: `tests/Integration/AwardApiCompatTest.php` (create)

**Interfaces:**
- Consumes: the `official_is_ladder` and `ka_is_ladder` columns added in Task 3.
- Produces: `AwardsForPlayer` response rows gain `'IsOfficialLadder' => int` and `'MaxRank' => int`. `'IsLadder'` and `'Rank'` keep their existing keys and types.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/AwardApiCompatTest.php`:

```php
    public function testIsOfficialLadderPreservesTheOldMeaning(): void
    {
        $row = $this->awardRowFor($this->officialLadderGrantId);
        $this->assertSame(1, (int) $row['IsOfficialLadder']);

        $row = $this->awardRowFor($this->kingdomLadderGrantId);
        $this->assertSame(0, (int) $row['IsOfficialLadder'], 'a kingdom ladder is not official');
        $this->assertSame(1, (int) $row['IsLadder'], 'but it is an effective ladder');
    }

    public function testRankIsTheRawColumnAndIsNeverReinterpreted(): void
    {
        $row = $this->awardRowFor($this->rankedGrantId);
        $this->assertSame(4, (int) $row['Rank']);
    }

    public function testExistingKeysAreAllStillPresent(): void
    {
        // Additive only. Any key a consumer reads today must survive.
        $row = $this->awardRowFor($this->rankedGrantId);
        foreach ([
            'AwardsId', 'AwardId', 'MundaneId', 'Rank', 'Date', 'GivenById', 'Note',
            'ParkId', 'KingdomId', 'EventId', 'Name', 'KingdomAwardName',
            'CustomAwardName', 'IsLadder', 'IsTitle', 'TitleClass',
            'ParkName', 'KingdomName', 'EventName', 'GivenBy',
        ] as $key) {
            $this->assertArrayHasKey($key, $row, "API key {$key} must not disappear");
        }
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter AwardApiCompatTest`
Expected: FAIL — `IsOfficialLadder` is absent.

- [ ] **Step 3: Add the fields**

In the `AwardsForPlayer` response map (`class.Player.php:1186`), beside the existing `'IsLadder' => $r->is_ladder`:

```php
                        // IsLadder is now the effective ladder (kingdom ladders included).
                        // IsOfficialLadder keeps the pre-2026-08 meaning for consumers
                        // that need to compare across kingdoms.
                        'IsOfficialLadder' => (int) $r->official_is_ladder,
                        'MaxRank' => Award::MaxRankFor((int) $r->award_id, (int) $r->ka_max_level),
```

Add both to `AwardElementType` in `orkservice/Player/PlayerService.definitions.php` as `xsd:int`.

Note the response map already emits keys that are absent from the WSDL struct (`KingdomAwardId`, `OfficerRole`, `Peerage`, `AliasAwardId`, `EnteredById`, `EnteredBy`). Additive fields are the established pattern here and are demonstrably tolerated by both transports: JSON consumers receive the extra keys, SOAP consumers filter to the declared type.

- [ ] **Step 4: Run the test to verify it passes**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter AwardApiCompatTest`
Expected: PASS.

- [ ] **Step 5: Verify both transports actually serve it**

```bash
curl -s 'http://localhost:19080/orkservice/Json/index.php?method=AwardsForPlayer&MundaneId=1' | head -c 600
curl -s 'http://localhost:19080/orkservice/Player/PlayerService.php?wsdl' | grep -c 'IsOfficialLadder'
```

The JSON response must contain `IsOfficialLadder`; the WSDL grep must return 1.

- [ ] **Step 6: Commit**

```bash
git add system/lib/ork3/class.Player.php orkservice/Player/PlayerService.definitions.php tests/Integration/AwardApiCompatTest.php
git commit -m "Enhancement: expose IsOfficialLadder and MaxRank for API consumers"
```

---

## Done criteria

- [ ] Full suite at baseline: `ENVIRONMENT=TEST ./vendor/bin/phpunit` → no new errors or failures beyond 4 errors / 17 failures.
- [ ] `grep -rn 'pseudoLadderKingdomAwardIds' .` → no matches.
- [ ] `grep -rn '12 : 10' orkui/ system/` → no matches (all three Zodiac duplicates retired).
- [ ] `bin/check-layering.sh` passes.
- [ ] `./vendor/bin/php-cs-fixer fix --dry-run --diff` clean on every touched PHP file.
- [ ] Manage Awards, a player profile with a kingdom ladder, the recommendations panel, and the Ladder Grid all render correctly in **both** themes and at a narrow width.
- [ ] `class.Authorization.php` is **not** in any commit: `git log --stat <first-commit>..HEAD | grep Authorization` → no matches.
