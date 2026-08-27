# Order of the Zodiac as a Monthly Award — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a grantor designate which calendar month an Order of the Zodiac is for, and make every Zodiac surface read months instead of levels — without touching a single existing `rank` value and without breaking any API consumer.

**Architecture:** A new `zodiac_month` column on `ork_awards` and `ork_recommendations`, leaving `rank` completely alone. `rank` is never written for Zodiac by any UI path again and is never read as a month; a Zodiac is month-confirmed when `zodiac_month > 0`, which is unambiguous on day one and stays unambiguous forever. All Zodiac behaviour hangs off `Award::IsMonthlyLadder()` rather than scattered `award_id === 30` literals. Zodiac stays `is_ladder = 1` and keeps the pill machinery; only the meaning of its twelve positions changes.

**Tech Stack:** PHP 8.2, MariaDB, PHPUnit 10, plain-PHP `.tpl` templates, vanilla JS (`revised.js`), hand-written CSS (`revised.css`), NuSOAP + a JSON mirror in `orkservice/`.

**Spec:** `docs/superpowers/specs/2026-08-27-zodiac-monthly-awards-design.md`

**Depends on:** `docs/superpowers/plans/2026-08-27-kingdom-ladder-awards.md` must be complete. This plan consumes `Award::IsMonthlyLadder()` (that plan's Task 1) and relies on Zodiac already being exempted from Rule 1 (that plan's Task 8).

## Global Constraints

> **Return-contract note.** `Player::AddAward()` returns a **FLAT** shape —
> `['Status' => int, 'Error' => ..., 'Detail' => ...]`, where `Status === 0` means
> success. It is **not** the nested `['Status']['Status']` / `['Status']['Message']`
> form. Assert `(int) $result['Status']` and read the message from `Error`/`Detail`.
> Following the nested form would break every existing caller's success branching.


Everything in the companion plan's Global Constraints applies here unchanged —
**including that domain write methods are token-gated**. Every `AddAward`,
`UpdateAward`, `ReconcileAward` and `AddAwardRecommendation` call in this plan's
test code omits `Token` and is therefore incomplete as written; take a token from
`tests/Support/AuthorizedOfficerFixture.php` (extracted in the companion plan's
Task 8). Without one the call is refused before any Zodiac logic runs, so a test
can pass or fail for reasons that have nothing to do with months. In addition:

- **`rank` is never written for Zodiac by any UI path, and never read as a month.** Existing ranks stay exactly as they are. The **one** exception is the SOAP/JSON write path, which keeps accepting inbound `Rank` for backwards compatibility — see Task 9.
- **`zodiac_month`: `1`–`12` = January–December. `0` = no month recorded.** A month outside 1–12 is rejected server-side.
- **A monthless Zodiac is accepted.** Rule 1 from the companion spec does not apply — 2,024 monthless Zodiacs already exist and officers reconciling history must be able to record a grant they cannot date to a month.
- **A repeat month is accepted and never blocked.** The UI indicates it through tip copy only. 35 players already hold duplicate ranks; one holds nine.
- **No star pill for Zodiac.** The star expresses recognition past the top of a ladder; a monthly award has no top. `Award::IsMonthlyLadder()` suppresses it.
- **No bulk migration of the 1,774 legacy ranks.** No reliable rank→month mapping exists; reconciliation is the migration path. Guessing at scale would manufacture wrong history that looks authoritative.
- **Month pill labels: `J F M A M J J A S O N D`.** `data-tip` carries the full month name.
- **Repeat-month tip copy, verbatim:** *"Player already has a Zodiac for {Month}. {Award|Recommend} another?"* — "Award" on grant surfaces, "Recommend" on recommendation surfaces.
- **Both migrations must be classified in `ork-db`'s `migration-classification.json5`**, or `drift-check --strict` blocks the whole unit-test run.
- **Test baselines (updated 2026-08-27, after the sandbox DB was reseeded):**
  **unit = 211 tests / 579 assertions / 0 errors / 0 failures — FULLY GREEN.**
  **integration = 267 tests / 1 failure** (`KingdomProfileTest::testKingdomDomainReadsUsedByProfile`,
  a pre-existing officer-role capitalisation drift, unrelated to this work).
  The earlier "4 errors / 17 failures" and "~85 errors / ~84 failures" baselines are
  **obsolete**: they measured a sandbox database that was empty of kingdom/park/mundane
  fixture data, not real test debt. Task 11 repaired it with the project's own `ork-db`
  tooling. **Any new error or failure is now yours** — do not excuse one as pre-existing.

### The data these decisions rest on

Worth carrying into every task, because it is what makes the design defensible:

| Fact | Value |
|---|---|
| Zodiac grants by rank | 1193 / 339 / 131 / 55 / 27 / 13 / 7 / 4 / 2 / 1 / 1 / 1 |
| Zodiac grants by grant-date month | 254–364 each, near-uniform |
| Total grants / holders | 3,798 across 2,525 |
| Already unranked (`rank = 0`) | **2,024 — 53%** |
| Carrying a rank | 1,774 |
| Holding duplicate ranks | 35 players |

The rank curve is ladder decay; months would be flat at ~148. Reading rank as a month would relabel 1,193 grants as January in a single deploy. The grant date, by contrast, *is* a strong month signal — which is why reconciliation pre-fills from it.

---

### Task 1: The `zodiac_month` column

**Files:**
- Create: `db-migrations/2026-08-27-zodiac-month.sql`
- Modify: the `ork-db` repo's `migration-classification.json5`

**Interfaces:**
- Produces: `ork_awards.zodiac_month` and `ork_recommendations.zodiac_month`, both `TINYINT(2) NOT NULL DEFAULT 0`.

- [ ] **Step 1: Write the migration**

Create `db-migrations/2026-08-27-zodiac-month.sql`:

```sql
-- Order of the Zodiac is granted once per calendar month, so its twelve positions
-- are months, not levels. This column records the month. `rank` is left completely
-- alone: 1,774 existing Zodiac grants carry a legacy rank and none is rewritten.
--
-- 1-12 = January-December. 0 = no month recorded.
--
-- Deliberately NOT "reuse rank plus a flag" -- that gives one column two meanings
-- gated on a second column, the same split-brain the kingdom-ladder work removed
-- from is_ladder.

ALTER TABLE ork_awards
    ADD COLUMN zodiac_month TINYINT(2) NOT NULL DEFAULT 0;

ALTER TABLE ork_recommendations
    ADD COLUMN zodiac_month TINYINT(2) NOT NULL DEFAULT 0;
```

- [ ] **Step 2: Classify it in ork-db**

Add an entry for `2026-08-27-zodiac-month.sql` to `migration-classification.json5` in the `ork-db` repo. Skipping this makes `drift-check --strict` block the entire unit-test run, which reads as an unrelated failure.

- [ ] **Step 3: Apply and verify**

```bash
docker compose exec -T ork3db mariadb -uork -psecret ork < db-migrations/2026-08-27-zodiac-month.sql
docker compose exec -T ork3testdb mariadb -uork -psecret ork_test < db-migrations/2026-08-27-zodiac-month.sql
docker compose exec ork3db mariadb -uork -psecret ork -e "
  SHOW COLUMNS FROM ork_awards LIKE 'zodiac_month';
  SHOW COLUMNS FROM ork_recommendations LIKE 'zodiac_month';"
```

Expected: both columns present, `tinyint(2)`, `NO` null, default `0`.

- [ ] **Step 4: Confirm no existing rank moved**

```bash
docker compose exec ork3db mariadb -uork -psecret ork -N -e "
  SELECT \`rank\`, COUNT(*) FROM ork_awards a
  JOIN ork_kingdomaward ka ON ka.kingdomaward_id = a.kingdomaward_id
  WHERE ka.award_id = 30 GROUP BY \`rank\` ORDER BY \`rank\`;"
```

Expected, unchanged from the table above: `0→2024, 1→1193, 2→339, 3→131, 4→55, 5→27, 6→13, 7→7, 8→4, 9→2, 10→1, 11→1, 12→1`.

- [ ] **Step 5: Run the suite**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite unit`
Expected: baseline. If `drift-check` fails, Step 2 was skipped.

- [ ] **Step 6: Commit**

```bash
git add db-migrations/2026-08-27-zodiac-month.sql
git commit -m "Enhancement: zodiac_month column on awards and recommendations"
```

---

### Task 2: Month helpers

**Files:**
- Modify: `system/lib/ork3/class.Award.php`
- Test: `tests/Unit/ZodiacMonthTest.php` (create)

**Interfaces:**
- Consumes: `Award::IsMonthlyLadder()` from the companion plan's Task 1.
- Produces:
  - `Award::MonthInitial(int $month): string` — `''` for 0 or out of range
  - `Award::MonthName(int $month): string` — `''` for 0 or out of range
  - `Award::IsValidZodiacMonth(int $month): bool` — true for 1..12 only
  - `Award::ZodiacMonthFromDate(string $date): int` — the month of a grant date, `0` if unparseable

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ZodiacMonthTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ZodiacMonthTest extends TestCase
{
    public function testMonthInitialsSpellJFMAMJJASOND(): void
    {
        $initials = '';
        for ($month = 1; $month <= 12; $month++) {
            $initials .= Award::MonthInitial($month);
        }
        $this->assertSame('JFMAMJJASOND', $initials);
    }

    public function testMonthInitialIsEmptyOutsideTheYear(): void
    {
        $this->assertSame('', Award::MonthInitial(0));
        $this->assertSame('', Award::MonthInitial(13));
        $this->assertSame('', Award::MonthInitial(-1));
    }

    public function testMonthNameGivesTheFullName(): void
    {
        $this->assertSame('January', Award::MonthName(1));
        $this->assertSame('July', Award::MonthName(7));
        $this->assertSame('December', Award::MonthName(12));
        $this->assertSame('', Award::MonthName(0));
        $this->assertSame('', Award::MonthName(13));
    }

    public function testOnlyOneThroughTwelveAreValid(): void
    {
        for ($month = 1; $month <= 12; $month++) {
            $this->assertTrue(Award::IsValidZodiacMonth($month));
        }
        $this->assertFalse(Award::IsValidZodiacMonth(0));
        $this->assertFalse(Award::IsValidZodiacMonth(13));
        $this->assertFalse(Award::IsValidZodiacMonth(-1));
    }

    public function testMonthFromGrantDate(): void
    {
        // The grant date is a strong month signal: Zodiac grant dates are near-uniform
        // at 254-364 per month, the fingerprint of a genuinely monthly award.
        $this->assertSame(3, Award::ZodiacMonthFromDate('2024-03-15'));
        $this->assertSame(12, Award::ZodiacMonthFromDate('2024-12-01 09:30:00'));
    }

    public function testMonthFromUnusableDateIsZero(): void
    {
        // '0000-00-00' is endemic in this corpus.
        $this->assertSame(0, Award::ZodiacMonthFromDate('0000-00-00'));
        $this->assertSame(0, Award::ZodiacMonthFromDate(''));
        $this->assertSame(0, Award::ZodiacMonthFromDate('not a date'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite unit --filter ZodiacMonthTest`
Expected: FAIL — `Error: Call to undefined method Award::MonthInitial()`

- [ ] **Step 3: Implement**

In `system/lib/ork3/class.Award.php`, after `IsMonthlyLadder()`:

```php
    /** @var list<string> 1-indexed in the accessors below. */
    private const MONTH_NAMES = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    /**
     * Single-letter month label for a Zodiac pill: J F M A M J J A S O N D.
     * Ambiguous on its own by design — the full name rides along in data-tip.
     */
    public static function MonthInitial(int $month): string
    {
        $name = self::MonthName($month);

        return $name === '' ? '' : substr($name, 0, 1);
    }

    public static function MonthName(int $month): string
    {
        return self::IsValidZodiacMonth($month) ? self::MONTH_NAMES[$month - 1] : '';
    }

    public static function IsValidZodiacMonth(int $month): bool
    {
        return $month >= 1 && $month <= 12;
    }

    /**
     * The month a grant date falls in — the reconciliation pre-fill. A suggestion the
     * officer confirms, never an automatic write: a monthly award is usually granted
     * at or just after the end of the month it honours.
     */
    public static function ZodiacMonthFromDate(string $date): int
    {
        if ($date === '' || strpos($date, '0000-00-00') === 0) {
            return 0;
        }
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return 0;
        }

        return (int) date('n', $timestamp);
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite unit --filter ZodiacMonthTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.Award.php tests/Unit/ZodiacMonthTest.php
git commit -m "Enhancement: Zodiac month helpers on Award"
```

---

### Task 3: Write the month on grant and recommendation

**Files:**
- Modify: `system/lib/ork3/class.Player.php:3386` (`AddAward`), `:3994` (`AddAwardRecommendation`), and `UpdateAward`
- Test: `tests/Integration/ZodiacGrantTest.php` (create)

**Interfaces:**
- Consumes: `Award::IsMonthlyLadder()`, `Award::IsValidZodiacMonth()`.
- Produces: `AddAward`, `UpdateAward` and `AddAwardRecommendation` accept a `ZodiacMonth` request key (int, optional, default 0).

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/ZodiacGrantTest.php`:

```php
    public function testGrantingAZodiacWritesTheMonthAndLeavesRankAtZero(): void
    {
        $id = $this->grant(['AwardId' => 30, 'ZodiacMonth' => 3]);

        $this->assertSame(3, $this->columnOf($id, 'zodiac_month'));
        $this->assertSame(0, $this->columnOf($id, 'rank'), 'rank is never written for Zodiac');
    }

    public function testGrantingANonZodiacLadderNeverWritesTheMonth(): void
    {
        // award_id 21 = Order of the Rose. Even if a caller passes ZodiacMonth.
        $id = $this->grant(['AwardId' => 21, 'Rank' => 4, 'ZodiacMonth' => 3]);

        $this->assertSame(0, $this->columnOf($id, 'zodiac_month'));
        $this->assertSame(4, $this->columnOf($id, 'rank'));
    }

    public function testAMonthlessZodiacIsAccepted(): void
    {
        // 2,024 already exist. Rule 1 does not apply to Zodiac.
        $result = $this->player->AddAward([
            'RecipientId' => 1, 'AwardId' => 30, 'Rank' => 0, 'ZodiacMonth' => 0,
            'Date' => '2026-01-01',
        ]);

        $this->assertSame(0, (int) $result['Status']);
    }

    public function testARepeatMonthIsAcceptedAndBothGrantsSurvive(): void
    {
        // Typically a player earns one December, but a second is legitimate.
        $this->grant(['AwardId' => 30, 'ZodiacMonth' => 12]);
        $this->grant(['AwardId' => 30, 'ZodiacMonth' => 12]);

        $this->assertSame(2, $this->countGrantsFor(30, 12));
    }

    public function testAMonthOutsideOneToTwelveIsRejected(): void
    {
        foreach ([13, 99, -1] as $month) {
            $result = $this->player->AddAward([
                'RecipientId' => 1, 'AwardId' => 30, 'ZodiacMonth' => $month,
                'Date' => '2026-01-01',
            ]);
            $this->assertNotSame(0, (int) $result['Status'], "month {$month} must be rejected");
        }
    }

    public function testALegacyRankIsUntouchedByEditingAZodiac(): void
    {
        $id = $this->grantRaw(['award_id' => 30, 'rank' => 5, 'zodiac_month' => 0]);
        $this->player->UpdateAward(['AwardsId' => $id, 'ZodiacMonth' => 7]);

        $this->assertSame(7, $this->columnOf($id, 'zodiac_month'));
        $this->assertSame(5, $this->columnOf($id, 'rank'), 'legacy rank stays legible as the level it was');
    }

    public function testRecommendingAZodiacCarriesTheMonth(): void
    {
        $recId = $this->recommend(['AwardId' => 30, 'ZodiacMonth' => 9]);

        $this->assertSame(9, $this->recColumnOf($recId, 'zodiac_month'));
        $this->assertSame(0, $this->recColumnOf($recId, 'rank'));
    }
```

Write the `grant`, `grantRaw`, `recommend`, `columnOf`, `recColumnOf` and `countGrantsFor` helpers with a `ZODGRANT` marker and a `tearDown` that deletes by it, the same shape as `EditAwardLadderTest`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter ZodiacGrantTest`
Expected: FAIL — `zodiac_month` is never written.

- [ ] **Step 3: Implement in `AddAward`**

```php
        // Zodiac positions are months, not levels. Write the month; never the rank.
        if (Award::IsMonthlyLadder($awardId)) {
            $zodiacMonth = (int) ($request['ZodiacMonth'] ?? 0);
            if ($zodiacMonth !== 0 && !Award::IsValidZodiacMonth($zodiacMonth)) {
                return [
                    'Status' => InvalidParameter(null, 'Choose a month between January and December.'),
                ];
            }
            // yapo drops null; 0 is the "no month recorded" value.
            $award->zodiac_month = $zodiacMonth;
            $award->rank = 0;
        }
```

Mirror it in `UpdateAward` — but there, do **not** write `rank`, so a legacy rank survives an edit that only adds a month.

Mirror it in `AddAwardRecommendation` against `ork_recommendations`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter ZodiacGrantTest`
Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.Player.php tests/Integration/ZodiacGrantTest.php
git commit -m "Enhancement: grant and recommend a Zodiac by calendar month"
```

---

### Task 4: Month pills

**Files:**
- Modify: `orkui/template/revised-frontend/script/revised.js` (the shared pill builder)
- Modify: `orkui/template/revised-frontend/style/revised.css`
- Modify: all eight rank-pill wraps to emit `data-monthly="1"` and `data-held-months` for Zodiac

**Interfaces:**
- Consumes: `Award::IsMonthlyLadder()`, `Award::MonthInitial()`, `Award::MonthName()`; the pill builder and `data-max-rank` / `data-current-rank` attributes from the companion plan's Task 7.
- Produces: for a monthly ladder the builder renders twelve month pills submitting `zodiac_month`, and renders **no** star pill.

- [ ] **Step 1: Render month pills instead of rank pills**

In the pill builder, branch before the numbered-pills loop:

```js
var monthly = wrap.getAttribute('data-monthly') === '1';
var initials = ['J','F','M','A','M','J','J','A','S','O','N','D'];
var names = ['January','February','March','April','May','June',
             'July','August','September','October','November','December'];
// Months the player already holds, e.g. "3,12,12" — repeats included.
var heldRaw = (wrap.getAttribute('data-held-months') || '').split(',');
var heldMonths = {};
heldRaw.forEach(function (m) {
    var n = parseInt(m, 10);
    if (n >= 1 && n <= 12) {
        heldMonths[n] = (heldMonths[n] || 0) + 1;
    }
});
// "Award" on grant surfaces, "Recommend" on recommendation surfaces.
var verb = wrap.getAttribute('data-verb') || 'Award';

if (monthly) {
    for (var m = 1; m <= 12; m++) {
        var pill = document.createElement('button');
        pill.type = 'button';
        pill.className = prefix + '-rank-pill ' + prefix + '-month-pill';
        pill.setAttribute('data-zodiac-month', String(m));
        pill.textContent = initials[m - 1];

        if (heldMonths[m]) {
            // A held month stays selectable. A second December is legitimate and
            // must never be gated -- indicated, never blocked.
            pill.classList.add('-held');
            pill.setAttribute('data-tip',
                'Player already has a Zodiac for ' + names[m - 1] + '. ' + verb + ' another?');
        } else {
            pill.setAttribute('data-tip', names[m - 1]);
        }
        wrap.appendChild(pill);
    }
    return; // No star pill: a monthly award has no top to go past.
}
```

The early `return` is what suppresses the star. Zodiac has no maximum, so recognition past the top is meaningless; repeat-month grants serve that purpose instead.

- [ ] **Step 2: Submit the month, not the rank**

Where the picker collects its value, read `data-zodiac-month` into a `ZodiacMonth` field when the wrap is monthly, and leave `Rank` unset.

- [ ] **Step 3: Emit the attributes on all eight wraps**

Every wrap listed in the companion plan's Task 7 gains, when the selected award is Zodiac:

```php
data-monthly="1"
data-held-months="<?= htmlspecialchars(implode(',', $heldZodiacMonths), ENT_QUOTES) ?>"
data-verb="<?= $isRecommendationSurface ? 'Recommend' : 'Award' ?>"
```

`$heldZodiacMonths` is a list of ints resolved server-side from the player's Zodiac grants.

- [ ] **Step 4: Style, both themes**

```css
.pn-month-pill,
.kn-month-pill,
.pk-month-pill {
    font-weight: 600;
    letter-spacing: 0;
}
.pn-month-pill.-held,
.kn-month-pill.-held,
.pk-month-pill.-held {
    border-color: #38a169;
    color: #276749;
}

html[data-theme="dark"] .pn-month-pill.-held,
html[data-theme="dark"] .kn-month-pill.-held,
html[data-theme="dark"] .pk-month-pill.-held {
    border-color: #68d391;
    color: #9ae6b4;
}
```

The `-held` green is the existing held state; this reuses it rather than inventing a second one.

- [ ] **Step 5: Verify in the browser**

Grant a Zodiac from the player profile. Confirm: twelve pills reading J F M A M J J A S O N D; hovering shows the full month name; a month the player already holds shows green and the repeat tip; **no star pill appears**; selecting March submits `ZodiacMonth=3` with no `Rank`. Then confirm an Order of the Rose picker is unchanged — numbered pills and a star at 10. Both themes.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/revised-frontend/script/revised.js orkui/template/revised-frontend/style/revised.css orkui/template/revised-frontend/
git commit -m "Enhancement: month pills for Order of the Zodiac"
```

---

### Task 5: Progress — counts, not levels

`GetLadderProgress` computes a set of distinct ranks and clamps to `maxRank`. For Zodiac both are wrong: duplicates are legitimate and a total can exceed 12.

**Files:**
- Modify: `system/lib/ork3/class.Player.php` (`ClassifyLadderGrants`, added by the companion plan's Task 6)
- Test: `tests/Unit/ZodiacProgressTest.php` (create)

**Interfaces:**
- Consumes: `ClassifyLadderGrants(int $awardId, int $kaMaxLevel, array $grants, bool $hasMaster): array` from the companion plan's Task 6.
- Produces: for a monthly ladder the returned array gains `'MonthsHeld' => list<int>` (distinct, ascending), `'MonthDates' => array<int, list<string>>` (month => grant dates, for the strip's tooltips) and `'Unmonthed' => int`, and `'Count' => int` replaces the meaning of `'Rank'`. `'BonusCount'` is always 0 for Zodiac.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ZodiacProgressTest.php`:

```php
    /** @param list<array{Rank: int, Date: string, ZodiacMonth: int}> $grants */
    private function zodiac(array $grants): array
    {
        return (new Player())->ClassifyLadderGrants(30, 0, $grants, false);
    }

    public function testCountIsTheTotalAndIsUncapped(): void
    {
        $grants = [];
        for ($i = 0; $i < 14; $i++) {
            $grants[] = ['Rank' => 0, 'Date' => '2024-01-01', 'ZodiacMonth' => ($i % 12) + 1];
        }

        // Not distinct months, not highest rank, and never clamped to 12.
        $this->assertSame(14, $this->zodiac($grants)['Count']);
    }

    public function testDuplicateMonthsCountTwiceButFillOneCircle(): void
    {
        $grants = [
            ['Rank' => 0, 'Date' => '2023-12-20', 'ZodiacMonth' => 12],
            ['Rank' => 0, 'Date' => '2024-12-20', 'ZodiacMonth' => 12],
            ['Rank' => 0, 'Date' => '2024-03-05', 'ZodiacMonth' => 3],
        ];
        $result = $this->zodiac($grants);

        $this->assertSame(3, $result['Count']);
        $this->assertSame([3, 12], $result['MonthsHeld']);
        $this->assertSame(
            ['2023-12-20', '2024-12-20'],
            $result['MonthDates'][12],
            'both December dates ride along for the strip tooltip'
        );
    }

    public function testMarkerIsSetWhenAnyZodiacHasNoMonth(): void
    {
        $result = $this->zodiac([
            ['Rank' => 0, 'Date' => '2024-01-01', 'ZodiacMonth' => 1],
            ['Rank' => 5, 'Date' => '2019-01-01', 'ZodiacMonth' => 0],
        ]);

        $this->assertSame(1, $result['Unmonthed']);
        $this->assertTrue($result['Approx'], '~ means "month not recorded" for Zodiac');
    }

    public function testMarkerIsClearWhenEveryZodiacHasAMonth(): void
    {
        $result = $this->zodiac([
            ['Rank' => 0, 'Date' => '2024-01-01', 'ZodiacMonth' => 1],
            ['Rank' => 0, 'Date' => '2024-02-01', 'ZodiacMonth' => 2],
        ]);

        $this->assertSame(0, $result['Unmonthed']);
        $this->assertFalse($result['Approx']);
    }

    public function testZodiacNeverHasBonusGrants(): void
    {
        // There is no "past max" for a monthly award.
        $grants = [];
        for ($m = 1; $m <= 12; $m++) {
            $grants[] = ['Rank' => 0, 'Date' => '2024-01-01', 'ZodiacMonth' => $m];
        }
        $grants[] = ['Rank' => 0, 'Date' => '2025-01-01', 'ZodiacMonth' => 1];

        $this->assertSame(0, $this->zodiac($grants)['BonusCount']);
    }

    public function testLegacyRanksAreNeverReadAsMonths(): void
    {
        // The whole point: rank 1 on 1,193 grants must not become January.
        $result = $this->zodiac([
            ['Rank' => 1, 'Date' => '2015-07-01', 'ZodiacMonth' => 0],
        ]);

        $this->assertSame([], $result['MonthsHeld']);
        $this->assertSame(1, $result['Unmonthed']);
    }

    public function testMasterZodiacStillSuppressesTheMarker(): void
    {
        $result = (new Player())->ClassifyLadderGrants(
            30,
            0,
            [['Rank' => 0, 'Date' => '2019-01-01', 'ZodiacMonth' => 0]],
            true
        );

        $this->assertFalse($result['Approx'], 'GetLadderMasterMap behaviour is preserved as-is');
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite unit --filter ZodiacProgressTest`
Expected: FAIL — `Undefined array key "Count"`

- [ ] **Step 3: Branch `ClassifyLadderGrants` for monthly ladders**

At the top of the method, before the ranked-ladder arithmetic:

```php
        if (Award::IsMonthlyLadder($awardId)) {
            $monthsHeld = [];
            $monthDates = [];
            $unmonthed  = 0;

            foreach ($grants as $grant) {
                $month = (int) ($grant['ZodiacMonth'] ?? 0);
                if (Award::IsValidZodiacMonth($month)) {
                    $monthsHeld[$month] = true;
                    // Repeats are kept here even though the strip fills one circle:
                    // the tooltip lists every grant date for that month.
                    $monthDates[$month][] = (string) $grant['Date'];
                } else {
                    // Never fall back to rank: 1,193 grants carry rank 1 and none of
                    // them means January.
                    $unmonthed++;
                }
            }
            ksort($monthsHeld);
            ksort($monthDates);

            return [
                // Total granted, uncapped. Not distinct months, not highest rank --
                // 35 players already hold duplicates and a total can exceed 12.
                'Count'      => count($grants),
                'MonthsHeld' => array_keys($monthsHeld),
                'MonthDates' => $monthDates,
                'Unmonthed'  => $unmonthed,
                // ~ keeps its shape but changes its words: for Zodiac it means
                // "month not recorded", not "rank not recorded".
                'Approx'     => $unmonthed > 0 && !$hasMaster,
                'Rank'       => count($grants),
                'MaxRank'    => 12,
                'BonusCount' => 0,
            ];
        }
```

`'Rank'` mirrors `'Count'` so callers that have not been updated still render something meaningful rather than 0.

- [ ] **Step 4: Supply `ZodiacMonth` to the classifier**

Add `awards.zodiac_month` to the `AwardsForPlayer` select (`class.Player.php:1138`) and to the row map at `:1186` as `'ZodiacMonth' => (int) $r->zodiac_month`. Task 9 depends on that key existing.

- [ ] **Step 5: Run the test to verify it passes**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite unit --filter ZodiacProgressTest`
Expected: PASS, 7 tests.

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite unit --filter LadderProgressBonusTest`
Expected: still PASS — the ranked path is untouched.

- [ ] **Step 6: Commit**

```bash
git add system/lib/ork3/class.Player.php tests/Unit/ZodiacProgressTest.php
git commit -m "Enhancement: Zodiac progress counts grants instead of levels"
```

---

### Task 6: The awards-tab month strip

**Files:**
- Modify: `orkui/template/revised-frontend/Playernew_index.tpl` (the Zodiac tile)
- Modify: `orkui/template/revised-frontend/style/revised.css`

**Interfaces:**
- Consumes: `MonthsHeld`, `Count` and `Unmonthed` from Task 5; `Award::MonthInitial()`, `Award::MonthName()`.

- [ ] **Step 1: Replace the 0–12 bar with a month strip**

Target rendering:

```
J F (M) A M J (J) A S O N (D)        3 Zodiacs
```

```php
<?php if (Award::IsMonthlyLadder((int) $lp['AwardId'])): ?>
    <div class="pn-zodiac-strip">
        <?php foreach (range(1, 12) as $month): ?>
            <?php
            $held = in_array($month, $lp['MonthsHeld'], true);
            $tip  = Award::MonthName($month);
            if ($held && !empty($lp['MonthDates'][$month])) {
                $tip .= ' — ' . implode(', ', $lp['MonthDates'][$month]);
            }
            ?>
            <span class="pn-zodiac-month<?= $held ? ' -held' : '' ?>"
                  data-tip="<?= htmlspecialchars($tip, ENT_QUOTES) ?>">
                <?= Award::MonthInitial($month) ?>
            </span>
        <?php endforeach; ?>
        <span class="pn-zodiac-count">
            <?= (int) $lp['Count'] ?> Zodiac<?= (int) $lp['Count'] === 1 ? '' : 's' ?>
        </span>
    </div>
<?php else: ?>
    <?php /* existing 0-maxRank progress bar, unchanged */ ?>
<?php endif; ?>
```

A repeat is **not** drawn twice — the circle means "has at least one". A player with two Decembers reads as one filled `D` and a count that exceeds the filled circles, which is exactly the information a bar cannot carry.

- [ ] **Step 2: Style, both themes**

```css
.pn-zodiac-strip {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px;
}
.pn-zodiac-month {
    width: 22px;
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    font-size: 11px;
    font-weight: 600;
    color: #a0aec0;
    border: 1px solid transparent;
}
.pn-zodiac-month.-held {
    background: var(--pn-accent, #2c5282);
    color: #fff;
}
.pn-zodiac-count {
    margin-left: 8px;
    font-size: 12px;
    color: #4a5568;
    font-variant-numeric: tabular-nums;
}

html[data-theme="dark"] .pn-zodiac-month {
    color: #718096;
}
html[data-theme="dark"] .pn-zodiac-count {
    color: #a0aec0;
}
```

`--pn-accent` is the player's accent colour, already set by the hero's dominant-colour pass.

- [ ] **Step 3: Verify in the browser**

Check a player with duplicate months (35 exist) — one filled circle, count higher than the filled count. Check a player with only legacy ranks — no filled circles, `~` present. Both themes, and at a narrow width where twelve circles must wrap rather than scroll the page sideways.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/Playernew_index.tpl orkui/template/revised-frontend/style/revised.css
git commit -m "Enhancement: Zodiac month strip on the awards tab"
```

---

### Task 7: Reconciliation by month

**Files:**
- Modify: `orkui/template/revised-frontend/Playernew_reconcile.tpl`
- Modify: `system/lib/ork3/class.Player.php` (`ReconcileAward`)
- Test: `tests/Integration/ZodiacReconcileTest.php` (create)

**Interfaces:**
- Consumes: `Award::ZodiacMonthFromDate()`, `Award::IsMonthlyLadder()`.
- Produces: `Player::ReconcileAward` accepts `ZodiacMonth` and writes it instead of `Rank` when the award is a monthly ladder.

- [ ] **Step 1: Write the failing test**

```php
    public function testOnlyMonthlessZodiacsAreListed(): void
    {
        $withMonth = $this->grantRaw(['award_id' => 30, 'zodiac_month' => 4]);
        $without   = $this->grantRaw(['award_id' => 30, 'zodiac_month' => 0]);

        $ids = array_column($this->reconcilableFor(1), 'AwardsId');

        $this->assertContains($without, $ids);
        $this->assertNotContains($withMonth, $ids);
    }

    public function testALegacyRankedZodiacIsStillReconcilable(): void
    {
        // 1,774 grants carry a rank and no month. A rank is not a month.
        $id = $this->grantRaw(['award_id' => 30, 'rank' => 5, 'zodiac_month' => 0]);

        $this->assertContains($id, array_column($this->reconcilableFor(1), 'AwardsId'));
    }

    public function testThePrefillComesFromTheGrantDate(): void
    {
        $id  = $this->grantRaw(['award_id' => 30, 'zodiac_month' => 0, 'date' => '2024-03-28']);
        $row = $this->rowFor($this->reconcilableFor(1), $id);

        $this->assertSame(3, (int) $row['SuggestedMonth']);
    }

    public function testReconcilingWritesTheMonthAndLeavesTheLegacyRank(): void
    {
        $id = $this->grantRaw(['award_id' => 30, 'rank' => 5, 'zodiac_month' => 0]);
        $this->player->ReconcileAward(['AwardsId' => $id, 'ZodiacMonth' => 3]);

        $this->assertSame(3, $this->columnOf($id, 'zodiac_month'));
        $this->assertSame(5, $this->columnOf($id, 'rank'));
    }

    public function testBonusExclusionDoesNotApplyToZodiac(): void
    {
        // There is no "past max" for a monthly award: a Zodiac is reconcilable
        // exactly when it has no month, whatever its date.
        $id = $this->grantRaw(['award_id' => 30, 'zodiac_month' => 0, 'date' => '2030-01-01']);

        $this->assertContains($id, array_column($this->reconcilableFor(1), 'AwardsId'));
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter ZodiacReconcileTest`
Expected: FAIL — the list is still rank-based.

- [ ] **Step 3: Rebuild the Zodiac section of the page**

- List Zodiac grants where `zodiac_month = 0`, whatever their rank.
- Replace the `min=1 max=12` rank input with a **month picker**.
- Pre-fill it from `Award::ZodiacMonthFromDate($grant['Date'])`. The grant dates are near-uniform across the twelve months, which is what makes them a usable signal — but a monthly award is usually granted at or just after the end of the month it honours, so this is a **suggestion the officer confirms, never an automatic write**.
- Show the legacy rank read-only as context — *"recorded as level 5"* — so the officer can see what the old system captured without being invited to treat it as a month.

- [ ] **Step 4: Update the explanatory copy**

The page currently says these are records "not matched to your official award history yet" (`:124`). For Zodiac, say the month was never recorded — not that the record is unmatched.

- [ ] **Step 5: Write the month in `ReconcileAward`**

```php
        if (Award::IsMonthlyLadder($awardId)) {
            $month = (int) ($request['ZodiacMonth'] ?? 0);
            if (!Award::IsValidZodiacMonth($month)) {
                return ['Status' => InvalidParameter(null, 'Choose a month between January and December.')];
            }
            $award->zodiac_month = $month;
            // The legacy rank is left exactly as it is.
        }
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter ZodiacReconcileTest`
Expected: PASS, 5 tests.

- [ ] **Step 7: Commit**

```bash
git add orkui/template/revised-frontend/Playernew_reconcile.tpl system/lib/ork3/class.Player.php tests/Integration/ZodiacReconcileTest.php
git commit -m "Enhancement: reconcile Zodiacs to a month instead of a rank"
```

---

### Task 8: Reports

**Files:**
- Modify: `system/lib/ork3/class.Report.php`
- Modify: the Ladder Grid template
- Test: `tests/Integration/LadderGridTest.php` (extend)

**Interfaces:**
- Consumes: `Award::IsMonthlyLadder()`, `Award::MonthName()`, and `Count` from Task 5.

- [ ] **Step 1: Write the failing test**

```php
    public function testZodiacColumnShowsTheTotalCountNotTheHighestRank(): void
    {
        // Highest rank is meaningless for a monthly award and misleading for the
        // 35 players who hold duplicates.
        $cell = $this->gridCellFor($this->playerWithThreeZodiacs, 30);
        $this->assertSame(3, (int) $cell['Value']);
    }

    public function testWalkerRemainsExcludedFromTheGrid(): void
    {
        $this->assertNotContains(31, array_column($this->gridColumnsFor([]), 'AwardId'));
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter LadderGridTest`
Expected: FAIL — the cell holds the highest rank.

- [ ] **Step 3: Implement**

- Anywhere a Zodiac appears individually, show the **month** — "Zodiac (March)" — falling back to the grant date when no month is recorded.
- Anywhere a count appears, show the **total granted**.
- Ladder Grid: the Zodiac column shows the total count rather than a rank number. Walker (31) stays excluded, unchanged.
- Zodiac lists sort **chronologically by grant date**, not by rank.

- [ ] **Step 4: Run the test to verify it passes**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter LadderGridTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.Report.php orkui/template/revised-frontend/ tests/Integration/LadderGridTest.php
git commit -m "Enhancement: Zodiac reports show months and totals"
```

---

### Task 9: API backwards compatibility

People drive reports and spreadsheets off the SOAP and JSON APIs. Zodiac data must stay usable for a client written before this change and never silently reinterpreted for a client written after it.

**This task amends the spec.** Spec §1 says `rank` is never written for Zodiac again. That holds for every UI path. The SOAP/JSON write path is carved out: it keeps accepting inbound `Rank` and keeps storing it exactly as it does today. A legacy integration that grants Zodiacs must not start failing, and its grants land as monthless — the same state as the 2,024 monthless Zodiacs already in the corpus, reconcilable through Task 7.

**Files:**
- Modify: `system/lib/ork3/class.Player.php:1186` (the `AwardsForPlayer` response map)
- Modify: `orkservice/Player/PlayerService.definitions.php` — `AwardElementType` (`:189`) and the three write structs at `:460`, `:482`, `:535`
- Test: `tests/Integration/ZodiacApiCompatTest.php` (create)

**Interfaces:**
- Consumes: `zodiac_month` from Task 1; the `'ZodiacMonth'` row key added in Task 5 Step 4.
- Produces: `AwardsForPlayer` rows gain `'ZodiacMonth' => int` and `'ZodiacMonthName' => string`. The three write structs gain an optional `ZodiacMonth` (`xsd:int`). `Rank` keeps its key, its type and its exact current meaning in both directions.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/ZodiacApiCompatTest.php`:

```php
    public function testRankIsTheRawColumnAndIsNeverTheMonth(): void
    {
        // A client reading Rank must never be handed a month behind that name.
        $id  = $this->grantRaw(['award_id' => 30, 'rank' => 5, 'zodiac_month' => 3]);
        $row = $this->apiRowFor($id);

        $this->assertSame(5, (int) $row['Rank']);
        $this->assertSame(3, (int) $row['ZodiacMonth']);
    }

    public function testANewZodiacReportsRankZero(): void
    {
        // Not a new failure mode: 2,024 of 3,798 existing Zodiac grants (53%)
        // already carry rank 0, so any consumer that cannot handle it is already
        // broken today.
        $id = $this->grantRaw(['award_id' => 30, 'rank' => 0, 'zodiac_month' => 7]);

        $this->assertSame(0, (int) $this->apiRowFor($id)['Rank']);
        $this->assertSame(7, (int) $this->apiRowFor($id)['ZodiacMonth']);
    }

    public function testMonthNameIsSuppliedSoConsumersNeedNoLookupTable(): void
    {
        $id = $this->grantRaw(['award_id' => 30, 'zodiac_month' => 7]);
        $this->assertSame('July', $this->apiRowFor($id)['ZodiacMonthName']);
    }

    public function testAMonthlessZodiacReportsZeroAndAnEmptyName(): void
    {
        $id  = $this->grantRaw(['award_id' => 30, 'zodiac_month' => 0]);
        $row = $this->apiRowFor($id);

        $this->assertSame(0, (int) $row['ZodiacMonth']);
        $this->assertSame('', $row['ZodiacMonthName']);
    }

    public function testANonZodiacAwardReportsZeroMonth(): void
    {
        $id = $this->grantRaw(['award_id' => 21, 'rank' => 4, 'zodiac_month' => 0]);

        $this->assertSame(0, (int) $this->apiRowFor($id)['ZodiacMonth']);
        $this->assertSame(4, (int) $this->apiRowFor($id)['Rank']);
    }

    public function testALegacyClientGrantingAZodiacByRankStillSucceeds(): void
    {
        // The carve-out. An integration written before this change must not break.
        $result = $this->player->AddAward([
            'RecipientId' => 1, 'AwardId' => 30, 'Rank' => 4, 'Date' => '2026-01-01',
            'ApiClient' => true,
        ]);

        $this->assertSame(0, (int) $result['Status']);
    }

    public function testALegacyRankIsStoredAsARankAndNeverAsAMonth(): void
    {
        $id = $this->grantViaApi(['AwardId' => 30, 'Rank' => 4]);

        $this->assertSame(4, $this->columnOf($id, 'rank'));
        $this->assertSame(0, $this->columnOf($id, 'zodiac_month'),
            'inbound Rank must never be silently reinterpreted as April');
    }

    public function testEveryExistingApiKeySurvives(): void
    {
        $row = $this->apiRowFor($this->grantRaw(['award_id' => 30, 'rank' => 4]));
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

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter ZodiacApiCompatTest`
Expected: FAIL — `ZodiacMonth` is absent from the response.

- [ ] **Step 3: Add the read fields**

In the `AwardsForPlayer` response map (`class.Player.php:1186`), beside `'Rank' => $r->rank`:

```php
                        // Additive. `Rank` above is the raw column and keeps its exact
                        // meaning: a legacy Zodiac level, or 0. It is never the month.
                        // Consumers that want the month read ZodiacMonth.
                        'ZodiacMonth' => (int) $r->zodiac_month,
                        'ZodiacMonthName' => Award::MonthName((int) $r->zodiac_month),
```

Add both to `AwardElementType` (`orkservice/Player/PlayerService.definitions.php:189`) as `xsd:int` and `xsd:string`.

The response map already emits keys absent from the WSDL struct (`KingdomAwardId`, `OfficerRole`, `Peerage`, `AliasAwardId`, `EnteredById`, `EnteredBy`), so additive fields are the established pattern here: JSON consumers receive the extra keys, SOAP consumers filter to the declared type.

- [ ] **Step 4: Add the optional write field**

Add `'ZodiacMonth' => array('name' => 'ZodiacMonth', 'type' => 'xsd:int')` to the three write structs at `:460`, `:482` and `:535`. It is optional; omitting it means 0, which is the accepted monthless state.

- [ ] **Step 5: Carve out the legacy write path**

In `AddAward`, the Zodiac branch from Task 3 sets `$award->rank = 0`. Make that conditional so an inbound `Rank` from an API client is preserved:

```php
        if (Award::IsMonthlyLadder($awardId)) {
            $zodiacMonth = (int) ($request['ZodiacMonth'] ?? 0);
            if ($zodiacMonth !== 0 && !Award::IsValidZodiacMonth($zodiacMonth)) {
                return ['Status' => InvalidParameter(null, 'Choose a month between January and December.')];
            }
            $award->zodiac_month = $zodiacMonth;

            // UI paths never write a Zodiac rank. The SOAP/JSON API keeps accepting
            // one for backwards compatibility: an integration written before this
            // change must not start failing. Such a grant is monthless and shows up
            // in reconciliation, exactly like the 2,024 monthless Zodiacs already
            // in the corpus. An inbound Rank is stored as a rank and is NEVER
            // reinterpreted as a month -- a client sending Rank=5 meaning "fifth
            // Zodiac" must not silently receive May.
            $award->rank = empty($request['ApiClient']) ? 0 : (int) ($request['Rank'] ?? 0);
        }
```

The four UI callers (`controller.Award.php:107`, `controller.Player.php:132`, `controller.Admin.php:1126`, and the recommendation grant path) do not set `ApiClient`. The SOAP and JSON service functions do.

- [ ] **Step 6: Run the test to verify it passes**

Run: `ENVIRONMENT=TEST ./vendor/bin/phpunit --testsuite integration --filter ZodiacApiCompatTest`
Expected: PASS, 8 tests.

- [ ] **Step 7: Verify both transports serve it**

```bash
curl -s 'http://localhost:19080/orkservice/Json/index.php?method=AwardsForPlayer&MundaneId=1' \
  | python3 -c "import json,sys; r=json.load(sys.stdin)['Awards'][0]; print(sorted(r.keys()))"
curl -s 'http://localhost:19080/orkservice/Player/PlayerService.php?wsdl' | grep -c 'ZodiacMonth'
```

The JSON keys must include both `Rank` and `ZodiacMonth`; the WSDL grep must be ≥ 4 (one read struct, three write structs).

- [ ] **Step 8: Document the change for integrators**

Add a short note to the release notes (see `reference_whats_new_release_notes`) stating: `Rank` is unchanged for every award including Zodiac; new Zodiac grants made through the ORK carry `ZodiacMonth` (1–12) and `Rank = 0`; API clients granting a Zodiac may keep sending `Rank` or switch to `ZodiacMonth`; and `IsLadder` now includes kingdom ladders, with `IsOfficialLadder` preserving the old meaning.

- [ ] **Step 9: Commit**

```bash
git add system/lib/ork3/class.Player.php orkservice/Player/PlayerService.definitions.php tests/Integration/ZodiacApiCompatTest.php
git commit -m "Enhancement: Zodiac month over the API, Rank kept backwards compatible"
```

---

## Done criteria

- [ ] Full suite green against baseline: `ENVIRONMENT=TEST ./vendor/bin/phpunit`
- [ ] Rank distribution query from Task 1 Step 4 returns **exactly** the pre-change numbers — no legacy rank moved.
- [ ] `grep -rn '=== 30\|== 30' orkui/ system/lib/` → no award-id literals; every Zodiac branch goes through `Award::IsMonthlyLadder()`.
- [ ] `bin/check-layering.sh` passes; `php-cs-fixer --dry-run` clean on every touched file.
- [ ] A Zodiac grant, recommendation, edit, reconcile, tile and report all render months in **both** themes and at a narrow width.
- [ ] No star pill appears on any Zodiac surface.
- [ ] JSON API returns `Rank` **and** `ZodiacMonth`; a legacy `Rank`-only grant still succeeds.
- [ ] `class.Authorization.php` is not in any commit.

## Deferred, deliberately

- **Bulk migration of the 1,774 legacy ranks.** No reliable mapping exists; reconciliation is the migration path.
- **Whether Master Zodiac should mean "has all twelve months".** A corpora question, not a software one. `GetLadderMasterMap()` keeps mapping Zodiac → Master Zodiac (award 8) and holding the master award keeps suppressing `~`, unchanged.
- **Enforcing one Zodiac per kingdom per month.** The award is described as monthly, but nothing here restricts a kingdom to a single recipient in a month.
