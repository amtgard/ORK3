# Officer Rolls Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give officer history its own page for each kingdom and park: a public Officer History report with an Edit mode of collapsible per-office ledgers, where terms are entered as season spans and holders can be plain text.

**Architecture:** A pure period class (`OfficerTermPeriod`) owns season parsing, Winter resolution, labels and gap math, and is the only implementation of those rules; the page asks the server to parse rather than duplicating it in JS. `ork_officer_history` gains holder-name, season and an explicit `is_current` marker. Every "current term" read and write moves from `end_date IS NULL` to `is_current`. `OfficerPosition`'s history methods are the one set of write rules, and one new reader (`OfficerPosition::rolls_for_org`) feeds both the report and the ledgers.

**Tech Stack:** PHP 8 (ORK3 MVC: `orkui/` controllers/models/templates, `system/lib/ork3/` domain with all SQL), MariaDB, jQuery-era vanilla JS, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-11-04-officer-rolls-editor-design.md`

## Global Constraints

- All SQL lives in `system/lib/ork3/`; controllers and templates reach it only through `orkui/model/`.
- New templates go in `orkui/template/revised-frontend/` only, never `template/default/`. Tool pages use the `.rp-*` shell from `template/default/style/reports.css`, loaded as `Recommendations_manage.tpl:29` loads it.
- `.tpl` files are plain PHP (not Smarty). Escape output with `htmlspecialchars`.
- Light and dark mode via `html[data-theme="dark"]`; verify computed styles in both.
- No native `alert`/`confirm`/`prompt`. Use the house dialogs (`orkConfirm` / `orkAlert` in `revised.js`).
- Tooltips via `data-tip`, never `title`.
- Player search: the house `kn-ac-results` dropdown, never jQuery UI, querying `SearchAjax/universal` (global on purpose: officers often served in another kingdom first).
- Public lib methods without a token must have `_` in their name (the JSON service exposes every public method of a whitelisted class).
- `db->query()` never returns false; check `$r->__ERROR` or use `ExecuteChecked` for writes.
- Permissions are unchanged: `kingdom.officer_history.manage` (kingdom scope, legacy AUTH_EDIT) and `park.officer_history.manage` (park scope, legacy AUTH_EDIT), resolved by `OfficerPosition::PermissionKeyFor('history', $park_id)`. Any UI Edit flag uses that same key, scope and legacy role.
- Commits: stage explicit paths only (never `git add -A`); never stage `system/lib/ork3/class.Authorization.php`; check `git diff --cached` before each commit. End messages with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Test runner: `php vendor/bin/phpunit --no-coverage` (the `bin/run-unit-tests.sh` drift gate currently fails on four upstream migrations; that is pre-existing).

## Review Focus

1. **Seating someone after a backlog is entered.** Expected: only the `is_current` row for that office is closed; backfilled terms with blank ends are untouched. (Task 2 test `testSeatingClosesOnlyTheCurrentRow`.)
2. **The 2,500 backfilled current rows have a null start.** Expected: after the migration each seated officer's row is `is_current = 1` and shows "… – present"; an open row with no matching seat becomes "end unknown". (Task 2 test `testMigrationMarksOnlySeatedRowsCurrent`.)
3. **Typos in the period field** (`Sprng 23`, `Spring 23 Fall`, `Fall 23–Spring 23` reversed, an end in the future). Expected: a clear message, no save. (Task 1 tests; Task 3 `testAddRejectsAFutureSeasonalEnd`.)
4. **Two-digit years for old reigns** (`Spring 95`). Expected: 1995, not 2095. (Task 1 `testTwoDigitYearsPivot`.)
5. **An office with history but now retired or renamed**, and history rows with `position_id = 0`. Expected: still listed as its own section, under the name it was held as. (Task 4 `testRetiredAndLegacyOfficesKeepTheirSection`.)

---

### Task 0: Isolate the pending Courts sub-tab work

The working tree holds an uncommitted, already-verified change that moved the Court Planner into an Events → Courts sub-tab. It touches files this plan also edits (`Kingdomnew_index.tpl`, `Parknew_index.tpl`, `revised.js`, `revised.css`, `Admin_kingdom.tpl`, `Admin_park.tpl`, `OfficerSurfaceRemovalTest.php`, `KingdomAdminConsoleTest.php`, `ParkAdminConsoleTest.php`).

- [ ] **Step 1:** `git status --short` and confirm the modified set is exactly those files plus `class.Authorization.php` (local bypass, never staged) and untracked `.worktrees/`, `.superpowers/`.
- [ ] **Step 2:** Run `php vendor/bin/phpunit --no-coverage`. Expected: 0 failures.
- [ ] **Step 3:** Stage those nine paths explicitly, check `git diff --cached --name-only`, and commit as `Kingdom and park profiles: Court Planner moves to an Events → Courts sub-tab`.
- [ ] **Step 4:** Add `.superpowers/` to `.gitignore` in its own commit.

### Task 1: `OfficerTermPeriod`, the period rules

**Files:**
- Create: `system/lib/ork3/class.OfficerTermPeriod.php` (pure, no SQL, no `Ork3` parent, all static)
- Test: `tests/Unit/OfficerTermPeriodTest.php`
- Ensure the class is loaded wherever `class.OfficerPosition.php` is (check how `system/lib/ork3/` classes are required, e.g. `startup.php` / the lib container, and register it the same way).

**Interfaces (Produces):**
- Season keys: `'spring'|'summer'|'fall'|'winter'`.
- `OfficerTermPeriod::parse(string $text, ?int $todayYear = null): array|string`. Returns an error message string on failure, otherwise `['start' => ?array, 'end' => ?array, 'endUnknown' => bool]`, where each end is `['season' => string, 'year' => int]`.
- `OfficerTermPeriod::ordinal(?string $season, ?int $year, ?string $exactDate, string $side, ?int $otherOrdinal): ?int`. `$side` is `'start'|'end'`. Exact date wins when present.
- `OfficerTermPeriod::resolve(array $term): array`. Input keys `StartSeason, StartYear, StartDate, EndSeason, EndYear, EndDate` (any may be null). Returns `['start' => ?int, 'end' => ?int]` ordinals.
- `OfficerTermPeriod::label(array $term, bool $isCurrent): string`, e.g. `"Spring 2023 – Fall 2023"`, `"Spring 1995 – ?"`, `"Fall 2026 – present"`, `"Summer 2023 – Winter 2024"`. Exact dates render as `M j, Y`.
- `OfficerTermPeriod::ordinalLabel(int $ordinal): string`, e.g. `92 → "Winter 2023"`.
- `OfficerTermPeriod::previousSpan(int $start, ?int $end): string`, the period text to pre-fill for the term before (same length, ending at `$start`).
- `OfficerTermPeriod::currentOrdinal(?string $today = null): int`.

**The ordinal (algorithm fixed here; the spec's Winter rule depends on it):**
`ordinal = year * 4 + i` where spring `i=1`, summer `i=2`, fall `i=3`, and **late** winter (January–February of YY) is `YY*4 + 0`. **Early** winter (December YY) is `(YY+1)*4 + 0`, deliberately the same ordinal as the January–February that follows, because they are one winter. Exact date → month: Dec → `(Y+1)*4`; Jan–Feb → `Y*4`; Mar–May → `Y*4+1`; Jun–Aug → `+2`; Sep–Nov → `+3`.
Winter resolution for "Winter YY" with candidates `late = YY*4` and `early = (YY+1)*4`:
- As a **start** with a known end ordinal `E`: the largest candidate `≤ E`. If the end is also Winter, resolve the start first as `late`.
- As an **end** with a known start ordinal `S`: the smallest candidate `≥ S`, or `> S` when the start is also Winter.
- With no other end to compare against: `late`.

**Parse grammar:** case-insensitive. Season words and abbreviations: `spring|spr|sp`, `summer|sum|su`, `fall|autumn|fa|f|au`, `winter|win|wi|w`. Years are 2 or 4 digits, and the season and year may be joined (`Sp23`). Separators: `-`, `–`, `—`, `to`. Forms:
- `S1 Y1 – S2 Y2`
- `S1 – S2 Y` (shared year)
- `S Y` (single season: start = end)
- `S Y –` or `S Y – ?` (end unknown)

`present|now|current` is rejected with: `"Current terms are set from Manage Officers."`. Two-digit years pivot: `yy <= (todayYear % 100) + 1` → `2000 + yy`, else `1900 + yy`. A reversed span (resolved end < start) is rejected.

- [ ] **Step 1: Write the failing tests** in `tests/Unit/OfficerTermPeriodTest.php`:

```php
public function testSpanWithSharedYear(): void {
    self::assertSame(['start'=>['season'=>'spring','year'=>2023],'end'=>['season'=>'fall','year'=>2023],'endUnknown'=>false],
        OfficerTermPeriod::parse('Spring–Fall 23', 2026));
}
public function testAbbreviationsAndJoinedYears(): void {
    self::assertSame(OfficerTermPeriod::parse('Summer 2023 - Winter 2024', 2026), OfficerTermPeriod::parse('Su23-W24', 2026));
}
public function testTwoDigitYearsPivot(): void {
    self::assertSame(1995, OfficerTermPeriod::parse('Spring 95', 2026)['start']['year']);
    self::assertSame(2027, OfficerTermPeriod::parse('Spring 27', 2026)['start']['year']);
}
public function testSingleSeasonIsAOneSeasonTerm(): void {
    $p = OfficerTermPeriod::parse('Fall 22', 2026);
    self::assertSame($p['start'], $p['end']);
}
public function testEndUnknown(): void {
    self::assertTrue(OfficerTermPeriod::parse('Spring 95 – ?', 2026)['endUnknown']);
    self::assertTrue(OfficerTermPeriod::parse('Spring 95 -', 2026)['endUnknown']);
}
public function testRejections(): void {
    self::assertIsString(OfficerTermPeriod::parse('Sprng 23', 2026));
    self::assertIsString(OfficerTermPeriod::parse('Spring 23 Fall', 2026));
    self::assertIsString(OfficerTermPeriod::parse('Fall 23 – Spring 23', 2026));
    self::assertSame('Current terms are set from Manage Officers.', OfficerTermPeriod::parse('Fall 26 – present', 2026));
}
/** The four cases the user gave, plus both-Winter. */
public function testWinterResolvesFromDirection(): void {
    $r = fn($a, $b, $c, $d) => OfficerTermPeriod::resolve(['StartSeason'=>$a,'StartYear'=>$b,'EndSeason'=>$c,'EndYear'=>$d,'StartDate'=>null,'EndDate'=>null]);
    self::assertSame(['start'=>2023*4+2,'end'=>2024*4], $r('summer',2023,'winter',2023)); // Jun → Dec 2023
    self::assertSame(['start'=>2023*4+2,'end'=>2024*4], $r('summer',2023,'winter',2024)); // Aug 2023 → Feb 2024
    self::assertSame(['start'=>2023*4,'end'=>2023*4+2], $r('winter',2023,'summer',2023)); // early 2023
    self::assertSame(['start'=>2024*4,'end'=>2024*4+2], $r('winter',2023,'summer',2024)); // Dec 2023
    self::assertSame(['start'=>2023*4,'end'=>2024*4], $r('winter',2023,'winter',2024));
}
public function testExactDatesWin(): void {
    self::assertSame(2023*4+2, OfficerTermPeriod::ordinal('spring', 2023, '2023-07-04', 'start', null));
}
public function testLabels(): void {
    self::assertSame('Winter 2023', OfficerTermPeriod::ordinalLabel(2023*4));
    self::assertSame('Spring 1995 – ?', OfficerTermPeriod::label(['StartSeason'=>'spring','StartYear'=>1995,'EndSeason'=>null,'EndYear'=>null,'StartDate'=>null,'EndDate'=>null], false));
    self::assertSame('Fall 2026 – present', OfficerTermPeriod::label(['StartSeason'=>'fall','StartYear'=>2026,'EndSeason'=>null,'EndYear'=>null,'StartDate'=>null,'EndDate'=>null], true));
}
/** Backlog pre-fill: after Spring–Fall 23 the next row offers Fall 22–Spring 23. */
public function testPreviousSpan(): void {
    self::assertSame('Fall 2022 – Spring 2023', OfficerTermPeriod::previousSpan(2023*4+1, 2023*4+3));
    self::assertSame('Fall 2022 – Spring 2023', OfficerTermPeriod::previousSpan(2023*4+1, null)); // unknown end: one-year default
}
```

(A one-year default for an unknown-end previous span is my call: two seasons, the commonest Amtgard term.)

- [ ] **Step 2:** Run `php vendor/bin/phpunit --no-coverage tests/Unit/OfficerTermPeriodTest.php`. Expected: FAIL, class not found.
- [ ] **Step 3:** Implement the class to the interface above.
- [ ] **Step 4:** Rerun. Expected: PASS.
- [ ] **Step 5:** Commit `Officer rolls: season period parser and Winter resolution`.

### Task 2: Schema and the `is_current` marker

**Files:**
- Create: `db-migrations/2026-11-04-officer-history-rolls.sql`
- Modify: `tools/ork-db/manifests/migration-classification.json5` (class `S`, render `full`, note naming the columns and the `is_current` backfill)
- Modify: `system/lib/ork3/class.OfficerPosition.php`: `openTermStart` (~2090), `closeTermAt` (~2116), `openTerm` (~2141), `hasOtherOpenTerm` (~2429), `CloseOfficerHistoryTerm` (~3117)
- Modify: `system/lib/ork3/common.php` `record_officer_history` (~952–1025)
- Test: `tests/Integration/OfficerOccupancyTest.php` (extend; reuse its fixture and `MARKER` cleanup)

**Interfaces (Produces):** columns on `ork_officer_history`:
- `holder_name VARCHAR(120) NOT NULL DEFAULT ''`
- `start_season ENUM('spring','summer','fall','winter') NULL`
- `start_year SMALLINT NULL`
- `end_season ENUM('spring','summer','fall','winter') NULL`
- `end_year SMALLINT NULL`
- `is_current TINYINT(1) NOT NULL DEFAULT 0`
- index `idx_oh_current (kingdom_id, park_id, is_current)`

Invariant: **a row is current iff `is_current = 1`**. A current row has `end_date IS NULL` and no end season.

- [ ] **Step 1: Write failing tests** in `OfficerOccupancyTest`:
  - `testSeatingClosesOnlyTheCurrentRow`: insert a past row for office `crown_a` with `start_season='spring', start_year=1995`, null end, `is_current=0`. Seat holder A (SetOccupant), then seat holder B. Assert the 1995 row still has `end_date IS NULL` and `end_season IS NULL`; A's row has `end_date = today`, `is_current = 0`; B's row has `is_current = 1`.
  - `testOpenTermGuardIgnoresUnknownEnds`: with a past unknown-end row present, seating succeeds and exactly one row for the office has `is_current = 1`.
  - `testMigrationMarksOnlySeatedRowsCurrent`: seed an `ork_officer` seat for holder H, office `crown_b`, and two null-end history rows (one for H, one for another player). Apply the migration's UPDATE statement (read from the file and execute the `is_current` backfill section). Assert H's row has `is_current=1` and the other has `0`.
- [ ] **Step 2:** Run them. Expected: FAIL (unknown column `is_current`).
- [ ] **Step 3:** Write the migration. Use `ADD COLUMN IF NOT EXISTS` for each column and `ADD INDEX IF NOT EXISTS`. The backfill is `UPDATE ork_officer_history h JOIN ork_officer o ON o.kingdom_id=h.kingdom_id AND o.park_id=h.park_id AND o.mundane_id=h.mundane_id AND o.mundane_id>0 AND (h.position_id=o.position_id OR (h.position_id=0 AND h.role=o.role)) SET h.is_current=1 WHERE h.end_date IS NULL`, followed by a guard that leaves at most one current row per office: keep the highest `officer_history_id`. Mark the section with a `-- is_current backfill` comment for the test. Apply it to dev `ork` (`docker exec -i ork3-php8-db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" ork' < db-migrations/2026-11-04-officer-history-rolls.sql`) and rebuild the sandbox (`bin/ork-db deploy-sandbox --yes --force-refresh --skip-use-dev`). If `GoldenRenderTest` / fingerprints then report a committed-schema hash change, regenerate them with the ork-db tool's documented command (see `tools/ork-db/`), not by hand.
- [ ] **Step 4:** Switch every listed reader and writer. Matching "the open term" becomes `AND is_current = 1`. Opening a term inserts `is_current = 1`. Closing sets `end_date = :today, is_current = 0`. `hasOtherOpenTerm` checks `is_current = 1`. Then `grep -rn "end_date IS NULL" system/lib/ork3` must return only comments.
- [ ] **Step 5:** Run the three tests plus `OfficerTransitionTest`, `OfficerHistoryBackfillTest` and `OfficerAdminActionsTest`. Expected: PASS. Update any of those that encoded "open = null end" so they assert `is_current` instead.
- [ ] **Step 6:** Commit `Officer history: explicit current-term marker and season/holder columns`.

### Task 3: One set of write rules

**Files:**
- Modify: `system/lib/ork3/class.OfficerPosition.php` `AddHistoryTerm` (~2462), `EditHistoryTerm` (~2598), `DeleteHistoryTerm` (~2718)
- Modify: `system/lib/ork3/class.Kingdom.php` and `class.Park.php` `Add|Edit|DeleteOfficerHistory`: replace their bodies with calls to the `OfficerPosition` methods (same request keys, so `KingdomId`/`ParkId` map straight through), keeping them public for the JSON service
- Modify: `orkui/controller/controller.OfficerAdminAjax.php` `actionAddHistory` / `actionEditHistory` (pass the new fields)
- Test: `tests/Integration/OfficerOccupancyTest.php`

**Interfaces:**
- Consumes: Task 1's `parse`, `resolve` and `currentOrdinal`; Task 2's columns.
- Produces: request keys accepted by Add and Edit: `PositionId` (Add only), `MundaneId` (int, 0 allowed), `HolderName` (string), `Period` (string, parsed by `OfficerTermPeriod::parse`), `StartDate`, `EndDate` (exact `YYYY-MM-DD`, optional), `Note`. Add returns `Success($history_id)`.

Rules:
- A holder is required: `MundaneId > 0` or a trimmed `HolderName` of 1–120 characters. When `MundaneId > 0`, `holder_name` is stored as `''`.
- A start is required on Add: a period start or a `StartDate`.
- Add never creates a current row (`is_current = 0` always; a blank end means end unknown). Drop the old "already has an open term" refusal from Add, because blank-end adds are no longer current.
- Reject an end whose resolved ordinal is greater than `currentOrdinal()`, with `"A term cannot end in the future."`. Reject end < start.
- **Edit on a current row:** only `Period` start / `StartDate` and `Note` may change. Any holder or end field returns `"The current term is managed from Manage Officers."`.
- **Edit on a past row:** holder, period, exact dates and note may change. A holder change audits prior and new `MundaneId`/`HolderName`.
- **Delete on a current row** is refused with the same message as Edit.

- [ ] **Step 1: Failing tests:**
  - `testAddAcceptsAPlainTextHolderAndSeasonPeriod`: Add `HolderName='Sir Bram the Elder', Period='Spring–Fall 95'`. Assert the row has `mundane_id=0`, the holder name, `start_season=spring, start_year=1995, end_season=fall, end_year=1995, is_current=0`.
  - `testAddWithBlankEndIsUnknownNotCurrent`: `Period='Spring 95 –'`. Assert `is_current=0`, a second such Add for the same office succeeds, and the office's current row is unchanged.
  - `testAddRejectsAFutureSeasonalEnd`: `Period='Spring 26 – Fall 30'` returns status ≠ 0.
  - `testAddRequiresAHolder`: neither `MundaneId` nor `HolderName` returns status ≠ 0.
  - `testEditCannotChangeTheCurrentHolderOrEnd` and `testDeleteRefusesTheCurrentRow`.
  - `testEditLinksAPlainTextHolderAndAudits`: edit a plain-text row to `MundaneId=<seeded>`. Assert `mundane_id` is set and an `ork_danger_audit` row for `OfficerPosition::EditHistoryTerm` carries both holders.
  - Replace the old `testAddHistoryTermRefusesASecondOpenTerm` with `testAddNeverOpensACurrentTerm`.
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement. Keep the existing token, permission (`PermissionKeyFor('history', …)` on the row's own scope for Edit/Delete) and kingdom-derivation checks exactly as they are.
- [ ] **Step 4:** Rerun those, plus `OfficerAuthorizationTest`, `RbacAuthorizationTest` and `OfficerAdminGateTest`. Expected: PASS.
- [ ] **Step 5:** Commit `Officer history: plain-text holders, season periods, current terms owned by Manage Officers`.

### Task 4: The rolls reader

**Files:**
- Modify: `system/lib/ork3/class.OfficerPosition.php`: add `public function rolls_for_org(int $kingdom_id, int $park_id): array`
- Modify: `orkui/model/model.OfficerPosition.php`: add `public function rolls(int $kingdom_id, int $park_id): array`
- Modify: `system/lib/ork3/class.Kingdom.php` / `class.Park.php` `GetOfficerHistory`: also return `HolderName`, `StartSeason`, `StartYear`, `EndSeason`, `EndYear`, `IsCurrent`, `PeriodLabel`
- Modify: `orkui/template/revised-frontend/partials/_officer_details_modal.tpl`: "present" is `IsCurrent`, not an empty `EndDate`. Plain-text holders render unlinked.
- Test: `tests/Integration/OfficerRollsReaderTest.php`

**Interfaces (Produces):** `rolls_for_org` returns
`['Offices' => [ ['Key' => string, 'PositionId' => int, 'Title' => string, 'Classification' => ?string, 'Retired' => bool, 'TermCount' => int, 'GapCount' => int, 'OverlapCount' => int, 'Terms' => [Term…]] ]]`

where `Term` = `['OfficerHistoryId','MundaneId','Persona','HolderName','IsCurrent','StartSeason','StartYear','EndSeason','EndYear','StartDate','EndDate','PeriodLabel','Note','StartOrdinal','EndOrdinal','FlagAfter' => null|['Type'=>'gap'|'overlap','Label'=>string]]`.

- Offices follow `OfficerPosition::sort_order_sql` order for the org's kingdom, then any office that appears only in history (retired, renamed, or `position_id = 0` grouped by `role`) after them, titled by the newest row's `display_label`.
- Terms are sorted newest first: current first, then by `StartOrdinal` desc, with unknown starts last.
- `FlagAfter` sits on a term and describes the space between it and the next-older term. It is a gap when `newer.StartOrdinal - older.EndOrdinal >= 2` (one whole season missing), labelled `"Gap: <ordinalLabel(older.End+1)> – <ordinalLabel(newer.Start-1)>"`. It is an overlap when `newer.StartOrdinal < older.EndOrdinal`, labelled `"Overlap with <older holder>"`. Rows with an unknown ordinal on the facing end are not flagged.
- No token is needed: public data. The underscore name keeps it off the JSON service.

- [ ] **Step 1: Failing tests** `testOfficesInOrgOrderWithCounts`, `testTermsNewestFirstWithCurrentOnTop`, `testGapNeedsAWholeMissingSeason` (Fall 22–Spring 23 then Spring 23–Fall 23: no gap; Spring–Fall 21 then Spring–Fall 23: gap `"Gap: Winter 2022 – Winter 2023"`), `testOverlapFlagged`, `testRetiredAndLegacyOfficesKeepTheirSection`, `testParkScopeReadsOnlyThatPark`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement. Order offices in PHP after one query each for positions and history; the lists are at most a few hundred rows.
- [ ] **Step 4:** Rerun those plus `OfficerHistoryRowShapeTest`. Expected: PASS.
- [ ] **Step 5:** Commit `Officer history: one rolls reader for the report and the ledgers`.

### Task 5: The Officer History page

**Files:**
- Modify: `orkui/controller/controller.Reports.php`: add `public function officer_history($params = null)`. Scope comes from `$this->request->KingdomId` / `ParkId`, as `player_award_recommendations` does (~205). Data: `Rolls`, `ScopeType`, `ScopeId`, `ScopeName`, `KingdomId`, `CanEditRolls`, `EditMode` (`&edit=1` and `CanEditRolls`). Template `Reports_officer_history.tpl`. Add `'officer_history'` to the action list near line 14 if that list gates routing.
- Modify: `orkui/controller/controller.OfficerAdminAjax.php`: add actions `rolls` (returns `rolls_for_org`) and `parseperiod` (POST `Period`, returns `{status:0, Label, StartOrdinal, EndOrdinal, PreviousSpan}` or `{status:1, error}`), both in the `history` permission family. Pass `HolderName` and `Period` through `actionAddHistory` / `actionEditHistory`.
- Create: `orkui/template/revised-frontend/Reports_officer_history.tpl`
- Create: `orkui/template/revised-frontend/script/officer-rolls.js` (loaded only in edit mode)
- Create: `orkui/template/revised-frontend/style/officer-rolls.css` (`.or-*` classes; light and dark)
- Modify: `Kingdomnew_index.tpl` and `Parknew_index.tpl` Reports tabs: add `<li><a href="<?= UIR ?>Reports/officer_history&KingdomId=…">Officer History</a></li>` (and `&ParkId=` on the park).
- Test: `tests/Integration/OfficerRollsPageTest.php`

**Interfaces:** Consumes Task 4 (`Model_OfficerPosition::rolls`), Task 3's request keys, and Task 1 (via `parseperiod`).

`CanEditRolls` = `has_permission_or_authority($uid, $this->OfficerPosition->permission_key_for('history', $parkId), $scope, $scopeId, AUTH_EDIT)`, exactly the server gate.

Page behaviour (spec, "The edit mode" and "The public report"):
- **Shell:** `.rp-root` → `.rp-header` (icon `fa-scroll`, title "Officer History", scope chip linking to the profile, and an "Edit the rolls" / "Done" ghost button when `CanEditRolls`) → `.rp-context` (one line) → one collapsible section per office (`<details class="or-office">`, `<summary>` with title, term count, gap/overlap counts). The first section is open, the rest closed.
- **View mode:** each section is a compact list: Holder · Period, a "present" pill on the current term, and quiet "unrecorded" rows for gaps.
- **Edit mode:** each section's table has columns Holder · Period · Note · actions. Rows edit in place; Delete uses `orkConfirm`; current rows show only start and note editable, with a "Set from Manage Officers" `data-tip`.
- **Entry row:** the last row of every section. The holder field searches `SearchAjax/universal` in a `kn-ac-results` dropdown, and free text is accepted (a small "plain text: not linked to a player" hint when no match is picked). The period field calls `parseperiod` debounced 250 ms and shows the read-back label or error under it. Note field. Enter saves via `addhistory`, re-fetches `rolls`, re-renders that section, focuses the new entry row's holder, and pre-fills its period with the last `PreviousSpan`.
- **Plain-text rows:** a "Link to player" action opens the same search inline and saves via `edithistory` with `MundaneId`.
- **Phone width (≤600px):** rows stack, and the entry row's fields go full width.

- [ ] **Step 1: Failing tests** in `OfficerRollsPageTest`, render-style like `OrkAdminDashboardAccessTest::renderDashboard`:
  - `testViewModeHasNoEditControls`
  - `testEditButtonFollowsTheHistoryGate`: a source-level assertion that the controller computes `CanEditRolls` with `permission_key_for('history'` and `AUTH_EDIT`
  - `testEditModeRendersAnEntryRowPerOffice`
  - `testCurrentTermRowIsNotDeletable`
  - `testReportsTabsLinkTheNewPage` (both profile templates contain `Reports/officer_history&KingdomId=`)
  - `testOfficerAdminAjaxRoutesTheNewActionsUnderHistory` (`'rolls' => 'history'`, `'parseperiod' => 'history'`)
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement the controller, ajax actions, template, CSS and JS.
- [ ] **Step 4:** Rerun. Expected: PASS. Then curl as an editor and as a non-editor: `Reports/officer_history&KingdomId=17` → 200 for both; `&edit=1` shows the entry rows only for the editor; `OfficerAdminAjax/officer/17/parseperiod` with `Period=Su23-W24` returns `"Summer 2023 – Winter 2024"`.
- [ ] **Step 5:** Commit `Officer History page: public report with office-ledger edit mode`.

### Task 6: Retire the modal editor

**Files:**
- Modify: `orkui/template/revised-frontend/partials/_correct_the_rolls.tpl`: replace the form, list and script with a short explanation and a link to `Reports/officer_history&KingdomId=…[&ParkId=…]&edit=1` (open in the same tab).
- Modify: `partials/_manage_officers.tpl` only if it references removed `cr*` functions.
- Test: `tests/Integration/OfficerSurfaceRemovalTest.php`: add `testCorrectTheRollsTabLinksToTheRollsPage` (the partial contains `Reports/officer_history` and no `addhistory`, `crAdd(`, or `<form`).

- [ ] **Step 1:** Write the failing test.
- [ ] **Step 2:** Run it. Expected: FAIL.
- [ ] **Step 3:** Rewrite the partial. Then `grep -rn "crAdd\|crLoad\|crPopulateOfficeSelect" orkui` must return nothing.
- [ ] **Step 4:** Rerun the test. Expected: PASS.
- [ ] **Step 5:** Commit `Manage Officers: Correct the Rolls tab now opens the Officer History editor`.

### Task 7: Whole-feature verification

- [ ] **Step 1:** Full suite: `php vendor/bin/phpunit --no-coverage`. Expected: 0 failures.
- [ ] **Step 2:** In Chrome on kingdom 17, in edit mode, enter five Monarch terms newest-to-oldest from the keyboard only, including a plain-text holder and one with an unknown end. Confirm the pre-fill offers the previous span, a deliberate gap is flagged, and Delete uses the house dialog. Then open view mode and check the same terms read correctly.
- [ ] **Step 3:** Repeat Step 2 for a park (74) with one term.
- [ ] **Step 4:** Check both themes (computed colours of section headers, entry row, flags and pills) and a ~390px-wide iframe harness for phone layout.
- [ ] **Step 5:** Seat a new Monarch from Manage Officers. Confirm the previous current term closes with today's date and none of the backfilled terms changed.
- [ ] **Step 6:** Delete the verification rows you created. Update the memory note on global player-search exceptions (`reference_playersearch_unscoped_exceptions.md`) with this third exception: officer-history holders.
