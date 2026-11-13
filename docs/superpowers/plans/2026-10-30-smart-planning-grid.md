# Smart Planning Grid Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the Ladder Award Grid report into an interactive court-building tool ("Smart Court") with recommendation matching, planning guidance columns, and a New Players box.

**Architecture:** The report gains two public, non-sensitive date columns and an on-demand New Players list through `Report`. Officer-only data (recs, other-court lines, this court's lines) comes from one court-authorized endpoint, `CourtAjax/smart_grid_state`, and never enters the shared grid cache. All writes reuse `create_court`, `add_award`, and `remove_award`. Client logic splits into a pure, node-tested core and a DOM layer.

**Tech Stack:** PHP 8, MariaDB, PHPUnit integration tests against `ork_test`, jQuery + DataTables 1.13, vanilla JS, `node --test`.

**Spec:** `docs/superpowers/specs/2026-10-30-smart-planning-grid-design.md`. Executors read it in full. It decides behavior, and this plan decides structure.

## Global Constraints

- SQL only in `system/lib/ork3/`; `orkui/model` is the only membrane; controllers stay thin.
- `$this->db->Clear()` before every raw `DataSet`/`Execute`; `DataSet()` rows need `->Next()`.
- `.tpl` files are plain PHP. Never Smarty syntax.
- Never stage `system/lib/ork3/class.Authorization.php`. Never `git add -A`. Stage named files only and read `git diff --cached` before each commit.
- No native `confirm`/`alert`/`prompt`. Tooltips use `data-tip`, never `title`.
- Dark mode via `html[data-theme="dark"]` for every new surface. Verify computed styles in both themes.
- FontAwesome names: `fa-arrows-up-to-line`, `fa-hourglass-half`, `fa-crown`, `fa-clipboard`, `fa-square-check`, `fa-up-right-from-square`.
- Tooltip copy, verbatim: `If your park or region cannot give awards above a certain amount, set that limit here and any cell at or above that limit will gray out.`
- Crown tooltip, verbatim: `Already on Court: {Name}`.
- New Players subhead, verbatim: `Players without awards, with at least two sign-ins in the past 90 days.`
- Toggle label, verbatim: `Show recent players without awards?`
- Date text in `YYYY-MM-DD`, as the requester specified.
- Test commands: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist <file>` and `node --test tests/js/`. Run `php -l` on every PHP file touched. Never run `bin/run-ork-db-checks.sh`.
- No migrations.

## Review Focus

1. A stale or corrupt `localStorage` entry (bad JSON, a court that is gone, complete, or no longer authorized) must be discarded with a message, never crash or resume into a dead court.
2. Persona or award names containing `'`, `"`, `<`, `&` must render as text on the card, in New Players, and in tooltips, and must survive the CSV.
3. A rec dismissed or granted after loading: `add_award` refuses, the row stays unsaved with the message, and rec data refreshes. It is never saved without its rec.
4. A court that became `complete` after the card opened: every remaining row stays unsaved with the server's message, and saved rows stay saved.
5. A kingdom grid of 2,000+ rows must stay responsive under rapid clicking: a click repaints only its own cell.

---

### Task 1: Report data for the new columns and New Players

**Files:**
- Modify: `system/lib/ork3/class.Report.php` (`GetLadderAwardGrid` from line 8446)
- Modify: `orkui/model/model.Reports.php` (after `ladder_award_grid`, line 455)
- Modify: `orkui/controller/controller.Reports.php` (after `ladder_grid`, line 1678)
- Test: `tests/Integration/LadderGridTest.php`

**Interfaces:**
- Produces, on each `GridRows[]` row: `LastAwardDate` (`?string`), `LastAwardLabel` (`string`), `LastSignIn` (`?string`).
- Produces, on the assembly: `KingdomId` (`int`, the resolved kingdom, also on the early empty returns after it is known).
- Produces, on pseudo columns in `LadderAwards`: `KingdomAwardIds` (`list<int>`).
- Produces: `Report::GetLadderGridNewPlayers(array $request): array` returning `['Status'=>Success(), 'Players'=>list<array{MundaneId:int,Persona:string,ParkId:int,ParkName:string,Suspended:int,LastAwardDate:?string,LastAwardLabel:string,LastSignIn:?string}>]`, or `['Status'=>BadToken(),'Players'=>[]]`.
- Produces: `Model_Reports::ladder_grid_new_players(int $kingdomId, int $parkId): array`.
- Produces: route `Reports/ladder_new_players&KingdomId=&ParkId=`, JSON `{status:0, players:[...]}`, `{status:5}` when logged out.

- [ ] **Step 1: Write failing tests in `LadderGridTest`**

- `testGridRowsCarryLastAwardAndLastSignIn`: a player with a Rose-like ladder award at rank 3 dated `2026-04-06` and a later non-ladder award, plus attendance on two dates. Assert `LastAwardDate` is the later date, `LastAwardLabel` is that award's display name, `LastSignIn` is the max date.
- `testLastAwardIgnoresRevokedAndPlaceholderDates`: a newer revoked award and a `0000-00-00` award do not win.
- `testLastAwardLabelStripsOrderPrefixAndAppendsRank`: award named `Order of the Rose` rank 1 gives label `Rose 1`.
- `testLastAwardTieUsesHighestAwardRow`: two awards on one date, higher `awards_id` wins.
- `testPlayerWithNoSignInHasNullLastSignIn`.
- `testNewPlayersRequiresTwoDistinctSignInDates`: no ladder award and sign-ins on 2 dates qualifies; 1 date does not; 2 rows on 1 date (different parks) does not; sign-ins 91+ days ago do not count.
- `testNewPlayersExcludesAnyoneOnTheMainGrid`: a player holding a ladder award is absent. A player with only a non-ladder award is present.
- `testNewPlayersRespectsScopeAndActive`: other park's player and an inactive player are absent.
- `testNewPlayersIncludesSuspendedFlag`.
- `testNewPlayersRequiresValidSession`: bad token gives `Status` BadToken and no players.
- `testAssemblyReportsResolvedKingdomForParkOnlyRequest`.

- [ ] **Step 2: Run and confirm failure**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist tests/Integration/LadderGridTest.php`
Expected: new tests FAIL (missing keys / undefined method).

- [ ] **Step 3: Implement**

- Add `private function ladderGridRecency(array $mundaneIds): array` keyed by mundane id. Last award uses `ROW_NUMBER() OVER (PARTITION BY ma.mundane_id ORDER BY ma.date DESC, ma.awards_id DESC)` over non-revoked rows with `ma.date >= '1980-01-01' AND ma.date <= CURDATE()`. Name is `COALESCE(NULLIF(ma.custom_name,''), alias.name, NULLIF(ka.name,''), a.name)`, with `alias` joined on `alias_award_id`. Label strips `/^Order of (?:the )?/i` and appends the rank when rank > 0. Last sign-in is `MAX(date)` with `date >= '1980-01-01'`.
- Merge into `$playerData` before caching. Add `'v' => 2` to the `ghettocache->key([...])` array so old cached rows cannot be served.
- `GetLadderGridNewPlayers`: call `$this->GetLadderAwardGrid($request)` first, propagate its bad-token status, and exclude its `GridRows` mundane ids in PHP so "without awards" stays identical to what the main grid hides. Membership query joins `mundane` to `attendance` for the last 90 days, scoped like `$locationClause`, `m.active = 1`, `GROUP BY m.mundane_id HAVING COUNT(DISTINCT a.date) >= 2`. Then use `ladderGridRecency`. Sort by persona.
- Action `ladder_new_players` echoes JSON and `exit`s, in the style of `jsonOut`.

- [ ] **Step 4: Run tests and lint**

Run the Step 2 command, then `php -l` on the three PHP files.
Expected: all PASS, no syntax errors.

- [ ] **Step 5: Measure performance on the local `ork` database**

Time the largest kingdom's page before and after using `curl -s -o /dev/null -w '%{time_total}'` with cache flushed. Record both in the task report. A rise above about 300 ms needs a fix before commit.

- [ ] **Step 6: Commit** the four files.

### Task 2: Court authority data and `smart_grid_state`

**Files:**
- Modify: `system/lib/ork3/class.Court.php`
- Modify: `orkui/model/model.Court.php`
- Modify: `orkui/controller/controller.CourtAjax.php`
- Modify: `orkui/controller/controller.Reports.php` (`ladder_grid`)
- Create: `tests/Integration/CourtSmartGridTest.php`

**Interfaces:**
- Consumes: `LadderAwards[..]['KingdomAwardIds']`, assembly `KingdomId` (Task 1).
- Produces: `public static function Court::sortSmartGridRecs(array $recs): array`. Order: `IsFallback` false first, `SecondsCount` desc, `DateRecommended` asc, `RecommendationsId` asc.
- Produces: `Court::getSmartGridColumns(int $kingdom_id, array $ladderAwards): array` returning `['ColumnAwards'=>array<string,int>, 'KaToColumn'=>array<int,string>]`.
- Produces: `Court::getSmartGridState(int $kingdom_id, int $park_id, int $court_id, int $caller_uid, string $caller_token): array` returning:
  - `Recs`: list of `{RecommendationsId, MundaneId, KingdomAwardId, Rank, SecondsCount, IsFallback}` already sorted.
  - `OtherCourtLines`: list of `{MundaneId, KingdomAwardId, CourtId, CourtName}`.
  - `CourtLines`: list of `{CourtAwardId, MundaneId, Persona, AwardName, KingdomAwardId, Rank, RecommendationsId, Status}`.
- Produces: model `get_smart_grid_columns`, `get_smart_grid_state` as one-line delegates.
- Produces: `POST CourtAjax/smart_grid_state` with `KingdomId, ParkId, CourtId`, returning `{status:0, state:{...}}`.
- Produces: template data `$SmartCourt`: `null` unless `can_manage`, else `{KingdomId, ParkId, ColumnAwards, KaToColumn, Courts:[{CourtId,Name,CourtDate,Status,AwardCount}], Events:[{EventCalendarDetailId,Name,EventStart}]}`.

- [ ] **Step 1: Write failing tests**

- `testSortPrefersSecondsThenOldest`: a 4-month-old rec with 0 seconds and a 1-month-old rec with 14 seconds, the newer comes first; on equal seconds the older comes first; on equal date the lower id comes first.
- `testSortPutsFallbackLast`: a snoozed rec with 20 seconds sorts after a plain rec with 0.
- `testStateExcludesRankZeroAlreadyHasAndCoveredByMaster`.
- `testStateExcludesRecFromAnotherKingdomsAward`.
- `testMonarchyOnlyRecHiddenFromParkOfficer` (use the pattern in `CourtMonarchyOnlyTest`), visible to a caller who may see it.
- `testStateNeverContainsReasonOrRecommender`: assert the JSON of `Recs` has no `Reason`, `RecommendedByName`, or the fixture's reason text.
- `testRecOnAnotherOpenCourtIsFallback`.
- `testOtherCourtLinesCoverKingdomAndParkCourtsOnly`: lines from a park court and the kingdom court appear; a `complete` court, a `cancelled` line, and this court do not.
- `testCourtLinesExcludeCancelled`.
- `testColumnsResolveRealAndPseudoLadders`: numeric column maps to this kingdom's lowest `kingdomaward_id`; pseudo key maps via `KingdomAwardIds`.
- `testInheritedOnlyPseudoColumnHasNoColumnAward`: a column whose ids all belong to a parent kingdom is absent from `ColumnAwards`.

- [ ] **Step 2: Run and confirm failure**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist tests/Integration/CourtSmartGridTest.php`
Expected: FAIL (undefined methods).

- [ ] **Step 3: Implement the lib and model**

Read uncollapsed rows from `Ork3::$Lib->report->PlayerAwardRecommendations` with `RequestedBy` and `Token`, exactly as `getPendingRecommendations` builds the request. Do not call `getPendingRecommendations`. `IsFallback` = `IsSnoozed` or the rec has a live line on another open court. Filter recs to `kingdomaward` rows in `$kingdom_id` with one query. Recs already on a live line of this court are dropped. `OtherCourtLines`: `court_award` join `court` where `c.kingdom_id = $kingdom_id`, `c.status != 'complete'`, `ca.status != 'cancelled'`, `c.court_id != $court_id`. When `$court_id > 0`, `CourtLines` comes from `getCourtAwards($court_id, $caller_uid)` filtered and projected; otherwise `[]`.

- [ ] **Step 4: Add the endpoint**

`smart_grid_state`: if `CourtId > 0`, use `requireCourtAuth` and take scope from the court, ignoring posted scope. Otherwise `requireLogin` and `can_manage` on posted `KingdomId`/`ParkId`, returning `status:3` when false. Token comes from `$this->session->token`.

- [ ] **Step 5: Set `$SmartCourt` in `ladder_grid`**

Inside `$_uid > 0` and resolved kingdom > 0, load the Court model, check `can_manage($_uid, $kid, $park_id)`. Courts come from `get_court_list($kid, $park_id, false)` minus `complete`. Events from `get_upcoming_events($kid)`.

- [ ] **Step 6: Add a test for the endpoint rule by curl**

Log in per `reference_local_curl_auth_session`, then confirm: a non-officer gets `status:3`, an officer gets `status:0`, and a court in another scope is refused by `requireCourtAuth`. Paste output in the report.

- [ ] **Step 7: Run all and lint**

Run the Step 2 command plus `tests/Integration/CourtMonarchyOnlyTest.php`, and `php -l` on touched files.
Expected: PASS.

- [ ] **Step 8: Commit** the five files.

### Task 3: Pure client logic

**Files:**
- Create: `orkui/template/default/script/lg-smart-court-core.js`
- Create: `tests/js/lg-smart-court-core.test.cjs`

**Interfaces:**
- Produces global `LgSmartCore` (also `module.exports`), where a row is `{id, mundaneId, col, rank, saved, recId, status, courtAwardId}`, an unsaved row has `saved:false`, and a rec is a `Recs` entry plus `col`:
  - `maxRank(awardName): number`: 12 when `/zodiac/i` matches, else 10.
  - `effectiveRank(held, rows, mundaneId, col): number`: highest of held and rows for that cell.
  - `nextRank(held, rows, mundaneId, col): number`: `effectiveRank + 1`.
  - `cellCount(rows, mundaneId, col): number`: drives `+N`.
  - `removeRow(rows, id): rows`: drops the row, and later unsaved rows of that cell shift down one rank each.
  - `assignRecs(rows, recs): rows`: saved rows keep `recId` and claim it; unsaved rows in order take the first unclaimed rec with equal `mundaneId`, `col`, and `rank`, else `recId:null`.
  - `recsAbove(recs, mundaneId, col, held): list`: recs with `rank > held`, in given order.
  - `isOverLimit(rank, limit): bool`: `limit > 0 && rank > limit`.
  - `isAtLimit(effective, limit): bool`: `limit > 0 && effective >= limit`.
  - `ageFraction(dateStr, todayStr): number|null`: days/365 clamped to 0..1.
  - `isOverdue(lastSignIn, lastAward, todayStr): bool`: sign-in within 60 days and award over 180 days ago; false when either is null.
  - `findDuplicates(rows, courtLines, colOf): list<id>`: unsaved rows whose mundane, column, and rank match a live line.
  - `parseStored(json, scopeKey): object|null`: null on bad JSON, wrong version, wrong scope, or wrong shape.

- [ ] **Step 1: Write failing node tests**

Cover: repeat next-rank from 2 gives 3, 4, 5; next rank continues past saved court lines; `assignRecs` gives a 4th and 5th their own recs; two recs at one rank use the first in sorted order; a rec for 5 never links to a line at 4; removing Rose 3 of pending 3/4/5 leaves 3/4 and rematches; removing never renumbers saved rows; saved rows keep their rec; limit boundary at, below, and above; `isOverdue` at 60/61 and 180/181 days; `ageFraction` at 0, 365, 1000, and null; `maxRank` for `Order of the Zodiac`; `parseStored` rejects `'{bad'`, `null`, a wrong scope, and a non-array `rows`.

- [ ] **Step 2: Run and confirm failure**

Run: `node --test tests/js/`
Expected: FAIL (cannot find module).

- [ ] **Step 3: Implement** as an IIFE with no DOM access.

- [ ] **Step 4: Run tests**

Run: `node --test tests/js/`
Expected: all PASS.

- [ ] **Step 5: Commit** both files.

### Task 4: Report template, columns, CSV, New Players box

**Files:**
- Modify: `orkui/template/default/Reports_laddergrid.tpl`
- Modify: `orkui/template/default/style/reports.css`

**Interfaces:**
- Consumes: Task 1 row fields and route; `LgSmartCore.ageFraction`.
- Produces for Task 5:
  - `tr`: `data-mundane-id`, `data-persona`, `data-suspended`, `data-last-award`, `data-last-signin`.
  - award `td`: `data-col`, `data-rank`, `data-master` ("1" or absent).
  - name cell contains `<span class="lg-name-flags"></span>`.
  - `window.LgGrid = { table, newTable (null until loaded), awardColumns, isKingdom, refresh() }`, where `awardColumns` is `{key: {Name, DisplayName, KnightGroup}}` in display order.
  - `$.fn.dataTable.ext.search` respects `window.LgGrid.hideSuspended` for both tables.

- [ ] **Step 1: Add the two columns**

Add header cells after Persona and Park, with `rowspan="2"`. Each cell: `<td data-order="YYYYMMDD">` (0 when missing, so missing sorts oldest), containing `<span class="lg-age" data-date="...">`. Missing shows a dash. Sticky columns must still work.

- [ ] **Step 2: Fix column-sensitive code**

Check `columnDefs` targets and the default order. In CSV, add `Last Award` and `Last Sign-in` after Mundane ID, and read award cells from `.lg-cell-rank, .lg-cell-master` so Smart Court markers can never leak into the export.

- [ ] **Step 3: Add the gradient**

On load and after New Players renders, set `--lg-age` on each `.lg-age` from `LgSmartCore.ageFraction` with today from the browser. CSS: `background: hsl(calc(120 * (1 - var(--lg-age))) 70% 82%)`, with a dark variant around 26% lightness and light text. Measure contrast in both themes.

- [ ] **Step 4: Add the toggle and New Players box**

Pill beside Include Inactive. First use fetches `Reports/ladder_new_players`, then renders once. Thead is produced by one PHP closure shared with the main table. Rows are built with DOM APIs and `textContent` so names cannot inject HTML. Subhead copy is in Global Constraints. Show an empty state and a load-failure message. `lg-search` filters both tables.

- [ ] **Step 5: Verify in the browser**

On the local app, on a park and a kingdom grid, as a logged-in non-officer: sort both date columns, toggle New Players, run Export CSV and inspect the columns, check both themes, confirm no console errors. Include a persona containing `'` and `&` for Review Focus 2.

- [ ] **Step 6: Commit** both files.

### Task 5: Smart Court interface

**Files:**
- Create: `orkui/template/default/script/lg-smart-court.js`
- Modify: `orkui/template/default/Reports_laddergrid.tpl` (button, modal, bar, card markup, `<script>` config only when `$SmartCourt`)
- Modify: `orkui/template/default/style/reports.css`

**Interfaces:**
- Consumes: `window.LgGrid` (Task 4), `LgSmartCore` (Task 3), `$SmartCourt` and `smart_grid_state` (Task 2), `CourtAjax/create_court|add_award|remove_award`.
- Config: `window.LgSmartConfig = {KingdomId, ParkId, ColumnAwards, KaToColumn, Courts, Events, Uir}`, emitted only when `$SmartCourt` is set. Script is loaded with `?v=filemtime` like `reports.css`.

Follow the spec's sections for behavior and the approved layout. Use `lg-sc-` class names. The POST helper follows `post()` in `Court_detail.tpl`.

- [ ] **Step 1: Button, modal, and entry**

Modal per spec, including run/plan mode and Flatpickr `altInput`. Courts and Events from config. New courts stay as a draft in memory until first Save. Header button toggles off and leaves unsaved rows in storage.

- [ ] **Step 2: Load state and paint**

Call `smart_grid_state`. Map recs and lines to columns via `KaToColumn`, dropping unmapped ones. `paintAll()` on load and limit change. `paintCell(td)` on a click, which must not iterate other rows. Paint adds (R) with `data-tip` naming rank and +1 count, crown at top left with the verbatim tooltip (comma-joining courts), `+N`, gray state, and hourglass in `.lg-name-flags`. `LgGrid.hideSuspended = true` plus a redraw on entry, false on exit. Master, top-rank, and unmapped cells are not clickable, with a `data-tip` reason.

- [ ] **Step 3: Single tooltip node**

One fixed-position body-level node, delegated on `[data-tip]`, instant, wraps at 240px, stays on screen. The grid's `overflow-x` wrapper would clip `::after`. Covers Ladder Limit (?) too.

- [ ] **Step 4: Card**

Rows, five-row viewport, X per spec (saved only while `planned`), R marker, red limit icon, Clear All two-click (unsaved only), FAB minimize with shrink animation and count, `prefers-reduced-motion`. Use `textContent` for every name.

- [ ] **Step 5: Save**

Lock the button. Create the court if needed, on first Save. Re-fetch state and run `LgSmartCore.findDuplicates`; duplicates stay unsaved with a message. Save rows serially through `add_award` with `RecommendationsId` (0 when none), the rec's `KingdomAwardId` or `ColumnAwards[col]`, rank, `PassToLocal` 0, `Notes` empty. On a rejected row, keep it unsaved with the server's error and continue; if it had a rec, refresh state afterward. After a run with no failures: green, check icons, new-tab button to `Court/detail/{id}`.

- [ ] **Step 6: Persistence**

`localStorage` key `lgSmartCourt:<Park|Kingdom>:<id>`, version 1, holding court choice, limit, and unsaved rows. On load, `parseStored`. A bad entry is removed. A saved court missing from `Courts` or refused by `smart_grid_state` clears storage and shows a message. Rows for players now hidden as suspended or duplicated on the court drop with a message.

- [ ] **Step 7: Print and mobile**

`@media print` hides button, bar, card, FAB, and markers. Under 640px the card fits the viewport and all controls stay reachable.

- [ ] **Step 8: Browser verification**

On a local park grid as an officer, walk the whole spec flow and report each: new court; existing court; repeat clicks; limit gray and red icon; X on unsaved and saved; Clear All; Save and green state; continue adding; new tab opens the court; a linked line shows the rec badge and inherited reason in the planner; a 4th and 5th use separate recs; minimize and restore; refresh resumes; suspended rows hidden; crown from a second court; dark mode; console clean. Then the Review Focus cases: corrupt `localStorage`, a dismissed rec, a court marked `complete` during a session, a name with `<`, and click timing on the largest kingdom grid.

- [ ] **Step 9: Commit** the three files.

### Task 6: Release notes and final check

**Files:**
- Modify: `orkui/whats_new_content.php`
- Modify: `orkui/template/default/docs_modal.tpl`

**Interfaces:**
- Consumes: the finished feature.

- [ ] **Step 1: Add a What's New entry**

Per `reference_whats_new_release_notes`: new item at the top, bump `WHATS_NEW_VERSION` and `ORK_VERSION`.

- [ ] **Step 2: Add an in-app doc article**

Per `reference_in_app_docs`: an article covering Smart Court, Ladder Limit Assist, (R), crown, hourglass, and New Players. Validate with `node --check` and the `ORKDOC_DATA` require check.

- [ ] **Step 3: Run the full suites**

Run: `ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist` and `node --test tests/js/`
Expected: no new failures against master. Report any pre-existing ones by name.

- [ ] **Step 4: Commit** both files.
