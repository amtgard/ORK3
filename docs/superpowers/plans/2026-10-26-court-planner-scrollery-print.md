# Court Planner × Scrollery Print Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Court Planner's scroll/regalia cycling icons with stacked status chips and dropdowns, add a Scrollery Print state, and bulk-print every Scrollery Print line from the planner, then offer to mark exactly the printed lines Complete.

**Architecture:** The status vocabulary and the guarded writes live in `Court` (lib) and pass through `Model_Court` to two new CourtAjax endpoints. Turning a court line into a Scrollery scroll is one model function, `Model_Scrollery::scrolls_for_court_awards()`. Both the CourtAjax preflight and the new bare print route `Scrollery/court/{courtId}` call it, so the two cannot disagree. The planner UI (`Court_detail.tpl`) renders the chips, and talks to the preflight, the print route and the complete endpoint.

**Tech Stack:** PHP 8 (plain-PHP `.tpl`), YapoMysql, vanilla JS in the template, PHPUnit run on the host (`php vendor/bin/phpunit`), Chrome for verification.

**Spec:** `docs/superpowers/specs/2026-10-26-court-planner-scrollery-print-design.md`

## Global Constraints

- Status values are fixed:
  - scroll: `0` Not Tracked, `1` Custom Design, `2` Complete, `3` Scrollery Print
  - regalia: `0` Not Tracked, `1` Needed, `2` Complete
  - regalia rejects `3`
  - no migration, no backfill
- Label copy, exactly: `Not Tracked`, `Scrollery Print`, `Custom Design`, `Complete`, `Needed`. Dropdown hints: `Goes in the bulk print` (Scrollery Print) and `An artisan is making it` (Custom Design). Button: `Print Scrollery scrolls (N)`. Bar: `Printed N Scrollery scrolls` · `Set these scrolls to complete?` · `Not yet`.
- SQL only in `system/lib/ork3/`. Controllers reach the lib only through `orkui/model/*`. Before raw `Execute`/`DataSet`, call `$this->db->Clear()`. `DataSet()` needs `->Next()`.
- Every mutating write to `ork_court_award` bumps `row_version = row_version + 1`. A stale write answers `{status:9, stale:true}`.
- Print-route authority is `Model_Court::can_manage($uid, KingdomId, ParkId)` **alone**. Do not add Scrollery's `may_issue_for` (user decision).
- The citation always passes `RecommendationVisibility::publishable_citation($publicComment, $recReason, $recMonarchyOnly)`.
- The giver is the line's `GivenByPersona` when `GivenByMundaneId > 0`. Otherwise it is `getCourtGiverOptions($courtId)['default']['persona']`, or `''` when that is null.
- Run cap is `Scroll::MAX_PRINT_RUN` (200).
- Tooltips use `data-tip`, never `title`. No native `confirm`, `alert` or `prompt`; use in-page UI (`cpAlert` already exists). Dark mode via `html[data-theme="dark"]`. FontAwesome 7.0.1 names.
- `Court_detail.tpl` is edited in place (`template/default/`). No new entry points are added in `template/default/`.
- Commits: stage by pathspec only, run `git diff --cached` first, and never stage `system/lib/ork3/class.Authorization.php`. End each message with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- PHPUnit runs on the host: `php vendor/bin/phpunit --filter <Name>`. Integration tests need the `ork3-php8-test-db` container and skip without it.

## Review Focus

1. **A peer changes a chip while a print is out.** The complete endpoint must leave that line alone and report it as stale. This is pinned in Task 1 (`testCompleteSkipsLinesWhoseRowVersionMoved`).
2. **A Monarchy Only rec reason sits in `public_comment`.** It must never print as the citation. This is pinned in Task 2 (`testCitationWithheldWhenItIsAHiddenRecReason`).
3. **A court line whose award Scrollery can't print** (officer position, or an unnamed Custom Award). It appears in `Skipped` with a reason, never in `Scrolls`, and the dropdown disables Scrollery Print for it. This is pinned in Task 2 (`testUnprintableAwardIsSkippedWithReason`, `testUnnamedCustomAwardIsSkippedAsBlankTitle`) and checked in the browser in Task 4.
4. **Ids from another court, or lines no longer Scrollery Print, are passed to the print route or the complete call.** They are dropped or not completed. This is pinned in Task 1 (`testCompleteIgnoresLinesFromAnotherCourt`, `testCompleteIgnoresLinesNoLongerScrolleryPrint`) and Task 2 (`testForeignAndNonScrolleryLinesAreNotPrinted`).
5. **A dropdown opened inside the `overflow-x:auto` award list on a narrow screen.** It must not be clipped and must close on Escape and on an outside click. This is checked in the browser in Task 4.

---

### Task 1: Status vocabulary, guarded writes, endpoints

**Files:**
- Modify: `system/lib/ork3/class.Court.php`
  - add constants and two methods near `updateAwardTrackingStatus` (~3020)
  - delete `updateAwardTrackingStatus` in Task 3, not here
- Modify: `orkui/model/model.Court.php` (pass-throughs)
- Modify: `orkui/controller/controller.CourtAjax.php` (two endpoints next to `update_award_tracking_status`, ~1186)
- Test: `tests/Integration/CourtTrackingStatusTest.php` (uses `CourtFixture`, the same style as `CourtNoteTest`)

**Interfaces:**
- Produces:
  - Constants on `Court`: `const SCROLL_NOT_TRACKED = 0; const SCROLL_CUSTOM = 1; const SCROLL_COMPLETE = 2; const SCROLL_SCROLLERY = 3; const REGALIA_NEEDED = 1;`
  - `Court::allowedTrackingStatuses(string $type): array`. Returns `[0,1,2,3]` for `scroll`, `[0,1,2]` for `regalia`, and `[]` otherwise.
  - `Court::setAwardTrackingStatus(int $courtAwardId, string $type, int $status, int $rowVersion): array`. Returns one of:
    - `['status'=>0,'newStatus'=>int,'RowVersion'=>int]` on success
    - `['status'=>1,'error'=>string]` for an invalid type or status, or an unknown line
    - `['status'=>9,'stale'=>true]` when `row_version` doesn't match

    It writes `{type}_status`, bumps `row_version`, and sets `scroll_maker_id = NULL` when `type==='scroll' && status===3`.
  - `Court::completePrintedScrolls(int $courtId, array $lines): array`. `$lines` is `[['CourtAwardId'=>int,'RowVersion'=>int], ...]`. Returns `['status'=>0,'Completed'=>int[],'Stale'=>int[]]`. Each line is one guarded `UPDATE ... SET scroll_status=2, row_version=row_version+1 WHERE court_award_id=? AND court_id=? AND scroll_status=3 AND row_version=?`. A line with 0 affected rows goes into `Stale`.
  - `Model_Court::set_award_tracking_status($id,$type,$status,$rowVersion)` and `Model_Court::complete_printed_scrolls($courtId,$lines)`, both forwarding unchanged.
  - CourtAjax `set_award_tracking_status`:
    - POST `CourtAwardId`, `Type`, `Status`, `RowVersion`
    - `requireCourtAuth`
    - returns the lib result as JSON
  - CourtAjax `complete_printed_scrolls`:
    - POST `CourtId` and `Lines` (a JSON string of the `$lines` shape)
    - `requireCourtAuth($court_id)`
    - returns the lib result

- [ ] **Step 1: Write the failing tests** in `tests/Integration/CourtTrackingStatusTest.php`:
  - `testSetsEachAllowedScrollStatus`: set `0,1,2,3` in turn, passing the returned `RowVersion` each time. The stored `scroll_status` equals each value.
  - `testRegaliaRejectsScrolleryValue`: regalia with status `3` gives `status === 1`, and the row is unchanged.
  - `testScrolleryPrintClearsScrollMaker`: create the award with `scroll_maker_id` set to a fixture player. Set scroll `3`. The stored `scroll_maker_id` is `NULL`. Setting `1` afterwards leaves it `NULL`.
  - `testStaleRowVersionIsRefused`: pass `RowVersion` + 1 and get `['status'=>9,'stale'=>true]`. The value is unchanged.
  - `testEveryWriteBumpsRowVersion`: `row_version` rises by exactly 1 per successful set.
  - `testCompleteMarksOnlyListedScrolleryLines`: three lines at `3`, with two listed. Those two are at `2`, the third stays at `3`, and `Completed` holds exactly those two ids.
  - `testCompleteSkipsLinesWhoseRowVersionMoved`: bump one line's version before the call. That line stays at `3` and is in `Stale`.
  - `testCompleteIgnoresLinesFromAnotherCourt`: a line on a second court listed under the first court's id is not changed, and is in `Stale`.
  - `testCompleteIgnoresLinesNoLongerScrolleryPrint`: a line set back to `1` with a correct version is not changed, and is in `Stale`.
- [ ] **Step 2: Run them and confirm they fail.** Run `php vendor/bin/phpunit --filter CourtTrackingStatusTest`. Expected: FAIL (undefined method).
- [ ] **Step 3: Implement** the constants, the two lib methods, the model pass-throughs and the two endpoints as specified in Interfaces.
- [ ] **Step 4: Run them and confirm they pass.** Run `php vendor/bin/phpunit --filter CourtTrackingStatusTest`, then `php vendor/bin/phpunit --filter Court` and `php vendor/bin/phpunit --filter ReleaseUtilizationCrownTest`. Expected: all PASS.
- [ ] **Step 5: Commit.** Use the pathspec for the four files: `Enhancement: Court Planner — explicit scroll/regalia status writes with Scrollery Print and guarded bulk complete`.

### Task 2: Court lines → Scrollery scrolls (model, print route, preflight)

**Files:**
- Modify: `orkui/model/model.Scrollery.php` (new public methods next to `scrolls_for_awards_ids`, plus one static pure helper)
- Modify: `orkui/controller/controller.Scrollery.php` (new `court($action)` route next to `render`)
- Modify: `orkui/controller/controller.CourtAjax.php` (new `scrollery_print_plan`; `load_model('Scrollery')` inside it)
- Modify: `orkui/template/revised-frontend/Scrollery_render.tpl`. Add a `$Skipped` notice beside the existing `$Dropped` notice (~337), with the same class family and hidden in print. *Plan refinement of the spec's "notice page":* the planner's preflight already removes unprintable lines before the tab opens, so the route only sees lines that became unprintable in between. A screen-only notice reports those without spending a sheet of paper.
- Test: `tests/Unit/ScrolleryCourtLineTest.php` (pure helper) and `tests/Integration/ScrolleryCourtPrintTest.php`

**Interfaces:**
- Consumes: `Court::SCROLL_SCROLLERY` (Task 1), `Ork3::$Lib->court->getCourtAwards($courtId, $viewerId)`, `->getCourtDetail($courtId)` and `->getCourtGiverOptions($courtId)`; `printable_award_choices()`, `scope_context()`, `Scroll::BuildScroll()`, `Scroll::HasArt()`, `RecommendationVisibility::publishable_citation()`.
- Produces:
  - `Model_Scrollery::printable_kingdomaward_ids(int $kingdomId): array`. A map of `KingdomAwardId => true`, built from `printable_award_choices($kingdomId)`. This is the one definition of "printable" the planner uses.
  - `Model_Scrollery::scrolls_for_court_awards(int $courtId, array $courtAwardIds, int $viewerId): array`. Returns `['Scrolls'=>array<int caId, array scroll>, 'Skipped'=>array<int caId, string reason>, 'Dropped'=>int]`. `Scrolls` keeps the court's running order and is capped at `MAX_PRINT_RUN`, with the overflow counted in `Dropped`. Passing `$courtAwardIds === []` means every line of the court. Ids not on this court, and lines whose `ScrollStatus !== 3` or whose `Status` is `cancelled`, are silently left out of both maps. Reason strings, exactly:
    - `Scrollery can't print this award.`
    - `This award has no name to print.` (for a resolved blank title)
    - `The recipient could not be found.`
  - `Model_Scrollery::courtLineScrollRowForTest(array $line, array $choice, array $court, string $givenBy): array`, public static and pure. It returns the `BuildScroll()` row:
    - `AwardId` = `$choice['AwardId']`
    - `OfficerRole` = `'none'`
    - `KingdomAwardName` = `$choice['Name']`
    - `Rank` = `$line['Rank']`
    - `Date` = `$court['Date']`, or `''`
    - `Note` = `RecommendationVisibility::publishable_citation($line['PublicComment'], $line['RecReason'], $line['RecMonarchyOnly'])`
    - `GivenBy` = `$givenBy`
    - `ParkId`, `KingdomId`, `ParkName` and `KingdomName` from `$court`, the way `compose()` fills them for park and kingdom scope
  - Route `Scrollery/court/{courtId}` with `&courtLines=1,2,3` (not `&lines=`, which the render page owns as its Reason-lines switch):
    - no session → `Login/login/Scrollery/court/{id}`
    - fails `can_manage` → `deny()`
    - nothing printable → `deny()` naming the first skip reason
    - otherwise `record_print(null, $scroll, $viewerId)` for each scroll, then `render_bare('Scrollery_render.tpl', ['Scrolls'=>..., 'Skipped'=>[persona+award => reason], 'Dropped'=>..., 'MaxRun'=>Scroll::MAX_PRINT_RUN, 'AutoPrint'=>isset($_GET['print']), 'PageTitle'=>N . ' scrolls'])`
  - CourtAjax `scrollery_print_plan`:
    - POST `CourtId`
    - `requireCourtAuth`
    - returns `{status:0, Printable:[{CourtAwardId, RowVersion}], Skipped:[{CourtAwardId, Persona, AwardName, Reason}], Dropped:int}` from `scrolls_for_court_awards($courtId, [], $uid)`
    - writes nothing

- [ ] **Step 1: Write the failing unit tests** in `tests/Unit/ScrolleryCourtLineTest.php`, against `courtLineScrollRowForTest`:
  - `testRowCarriesCourtDateRankAndCatalogName`: the line has `Rank` 4. The choice is `['AwardId'=>27,'Name'=>'Order of the Rose']`. The court is `['Date'=>'2026-11-07', ...]`. Assert `Rank===4`, `Date==='2026-11-07'`, `KingdomAwardName==='Order of the Rose'` and `OfficerRole==='none'`.
  - `testCitationWithheldWhenItIsAHiddenRecReason`: `PublicComment === RecReason === 'secret'` with `RecMonarchyOnly` true gives `Note === ''`.
  - `testOfficerWrittenCitationSurvives`: `PublicComment 'For valor'` with `RecReason 'secret'` and `RecMonarchyOnly` true gives `Note === 'For valor'`.
  - `testGiverPassesThrough`: `$givenBy 'Queen Alys'` gives `GivenBy === 'Queen Alys'`.
  - `testUndatedCourtLeavesDateBlank`: a court `Date` of `null` gives `Date === ''`.
- [ ] **Step 2: Write the failing integration tests** in `tests/Integration/ScrolleryCourtPrintTest.php`. Use `CourtFixture` and `firstKingdomAwardId()` for a printable award. For an unprintable one, find a kingdomaward the kingdom's `printable_award_choices()` omits; skip the test if none exists.
  - `testScrolleryLinesPrintInRunningOrder`: two lines at `scroll_status 3` give `Scrolls` keys in `sort_order`.
  - `testForeignAndNonScrolleryLinesAreNotPrinted`: a line at `1`, a line on another court and a cancelled line appear in neither map.
  - `testUnprintableAwardIsSkippedWithReason`: `Skipped[id] === "Scrollery can't print this award."`
  - `testUnnamedCustomAwardIsSkippedAsBlankTitle`: a kingdomaward on base award 94 with an empty name gives `This award has no name to print.` Skip if the sandbox has no such kingdomaward.
  - `testGiverFallsBackToCourtDefault`: a line with no `given_by_mundane_id` and a seated monarch from `insertOfficer` gives a scroll whose `givenBy` (or the key `BuildScroll` emits for it) is that persona.
- [ ] **Step 3: Run them and confirm they fail.** Run `php vendor/bin/phpunit --filter ScrolleryCourt`. Expected: FAIL (undefined method).
- [ ] **Step 4: Implement** the model methods, the route, the endpoint and the `$Skipped` notice. Reuse `possessive_pronoun_for_player`, `heraldry_url`/`issuer_title` (private, same class) and `scope_context` rather than re-deriving them. Resolve a line's choice by `KingdomAwardId` from the court kingdom's `printable_award_choices()`; no choice means "can't print". Call `Scroll::BuildScroll()`, and treat a `trim($scroll['title']) === ''` result as "no name". Call `getCourtGiverOptions` once per run, not once per line.
- [ ] **Step 5: Run them and confirm they pass.** Run `php vendor/bin/phpunit --filter Scroll`, then `php vendor/bin/phpunit --filter Court`. Expected: all PASS.
- [ ] **Step 6: Check the route with curl.** Log in under the bypass (`heraldsbridge`, one cookie jar) and set a local court line to 3. `curl -b jar 'http://localhost:19080/orkui/index.php?Route=Scrollery/court/<id>&courtLines=<caId>'` returns HTML with one certificate and no ORK nav. Logged out, the same request gives a 302 to Login.
- [ ] **Step 7: Commit.** Use the pathspec for the six files: `Enhancement: Scrollery — print a court's Scrollery Print lines in one run, with a planner preflight`.

### Task 3: Planner chips and dropdown

**Files:**
- Modify: `orkui/template/default/Court_detail.tpl`:
  - CSS: grid ~627/633, header ~651, cells ~692–700, mobile ~1180–1215, dark block ~849
  - PHP: `cp_track_label` ~58, stats ~1390–1404, About legend ~1484–1491, row markup ~1677–1681, Scroll Maker field ~1806–1819
  - JS: `cpTrackLabel` ~2940, `cpUpdateTracking`/`cpApplyTrackState` ~2950–2980, `cpRefreshStatusBar` ~2984, Printing List ~2889–2920, JS row builder ~4385, Prep Sheet `cpSheetPrep` ~5555
- Modify: `orkui/controller/controller.Court.php` `detail()` (~121): `load_model('Scrollery')` and set `ScrolleryPrintable` on each award
- Modify: `orkui/controller/controller.CourtAjax.php` `court_state` (~1076): the same flag on `awards_full`. Delete `update_award_tracking_status`.
- Modify: `system/lib/ork3/class.Court.php`: delete `updateAwardTrackingStatus`
- Modify: `orkui/template/default/Court_record.tpl`: if it shows scroll status anywhere, route it through the new labels; otherwise confirm value 3 renders harmlessly

**Interfaces:**
- Consumes:
  - `set_award_tracking_status` and its `{status,newStatus,RowVersion}` / `{status:9,stale:true}` responses (Task 1)
  - `Model_Scrollery::printable_kingdomaward_ids()` (Task 2)
  - the existing stale handler the planner uses for `status:9`; find the code that shows the "row changed — reload" message and reuse it
- Produces:
  - each award object carries `ScrolleryPrintable: bool`
  - the chip markup is `<button type="button" class="cp-chip" data-type="scroll|regalia" data-status="N" data-caid="ID">`, with an icon (`fa-scroll` / `fa-medal`), the label and a caret, inside a `.cp-cell-tracking` column
  - `window.cpTrackLabel(type, status)` returns the exact labels from Global Constraints; the PHP `cp_track_label()` matches it
  - `window.cpScrolleryCount()` returns the number of non-cancelled lines with `ScrollStatus === 3`, for Task 4's button

- [ ] **Step 1: Columns.** In `.cp-row-grid` (both templates), replace the two `30px` scroll/regalia tracks with one track of about `118px`. Replace the two header cells with one `Scroll / Regalia` header. Replace the two row cells (PHP and JS builder) with one `.cp-cell-tracking` cell holding two stacked chips (`flex-direction:column; gap:3px`). In the ≤640px card layout the chips sit side by side in a full-width row.
- [ ] **Step 2: Chip styling,** light and dark:
  - per-status colours: Not Tracked grey, Custom Design purple, Scrollery Print blue, Needed red, Complete green
  - per-density sizes
  - the chip is a `<button>`; check that `orkui.css` global button rules don't override its padding, border or background, by comparing computed styles in both themes
- [ ] **Step 3: Dropdown.** One shared, `position:fixed` popover, positioned from the chip's `getBoundingClientRect()` the way `.cp-ac-dropdown` is.
  - It lists that type's states, with the current one marked and the two hint lines.
  - Scrollery Print is disabled with the `data-tip` `Scrollery can't print this award` when the line's `ScrolleryPrintable` is false. Leave it enabled when the line is already at 3, so the officer can move it off.
  - Behaviour:
    - a chip click stops propagation, so the row doesn't expand
    - Escape, an outside click and scroll/resize close it
    - ↑/↓ move between options, and Enter or a click picks
    - focus returns to the chip
  - Picking posts to `set_award_tracking_status` with the award's `RowVersion`. On `status 0`, update the chip, `award.ScrollStatus`/`RegaliaStatus` and `award.RowVersion`, then call `cpRefreshStatusBar()`. On `9`, call the planner's stale handler. Otherwise show `cpAlert(error)`.
- [ ] **Step 4: Scroll Maker.** When a line is at 3, hide the expand panel's Scroll Maker block and clear its text and hidden inputs locally; show it again for other values. This must also hold after a heartbeat reconcile.
- [ ] **Step 5: Other readers.**
  - **Stats bar (PHP and JS):** counts for all four scroll states and three regalia states.
  - **Printing List:** Scrollery Print and Custom Design lines first, then Complete, then hide Not Tracked. Update the empty-state hint to point at the chip.
  - **Prep Sheet:** Scrollery Print lines group under `Scrollery` instead of `(unassigned)`; ☑ only for status 2.
  - **About legend:** show the four scroll chips and three regalia chips.
  - **Heartbeat:** the `awards_full` reconcile re-renders chips from `ScrollStatus`/`RegaliaStatus`/`RowVersion`/`ScrolleryPrintable`.
- [ ] **Step 6: Remove the cycle.** Delete `cpUpdateTracking`, the CourtAjax `update_award_tracking_status` endpoint and `Court::updateAwardTrackingStatus`. Grep confirms no callers remain: `grep -rn "update_award_tracking_status\|updateAwardTrackingStatus\|cpUpdateTracking" orkui system` gives no output.
- [ ] **Step 7: Run the tests.** Run `php vendor/bin/phpunit --filter Court`. Expected: PASS. Run `php -l` on each modified PHP file. Expected: no syntax errors.
- [ ] **Step 8: Commit.** Use the pathspec: `Enhancement: Court Planner — scroll and regalia status chips with a picker; Scrollery Print state`.

### Task 4: Bulk print button, complete bar, browser verification

**Files:**
- Modify: `orkui/template/default/Court_detail.tpl`: list toolbar (`.cp-list-toolbar`) button, bar markup above `.cp-award-list`, and JS

**Interfaces:**
- Consumes:
  - `window.cpScrolleryCount()` and the chip re-render (Task 3)
  - `scrollery_print_plan` and the route `Scrollery/court/{courtId}&courtLines=` (Task 2)
  - `complete_printed_scrolls` (Task 1)

- [ ] **Step 1: Button.** Add `Print Scrollery scrolls (N)` (icon `fa-print`) to the list toolbar for any court that isn't complete.
  - When N is 0 it is disabled, with `data-tip="Set a scroll chip to Scrollery Print to add it here."`
  - N updates from every chip change and every heartbeat.
- [ ] **Step 2: Print flow.** On click, POST `scrollery_print_plan`.
  - If `Printable` is empty, `cpAlert` lists the `Skipped` reasons and nothing opens.
  - Otherwise, open `UIR + 'Scrollery/court/' + courtId + '&courtLines=' + ids.join(',')` in a new tab, synchronously on the click so it isn't popup-blocked. Open a blank tab first and set its location when the preflight resolves.
  - Store `Printable` (ids and `RowVersion`s) in memory as the pending run.
- [ ] **Step 3: The bar.**
  - It reads `Printed N Scrollery scrolls.`, then `Set these scrolls to complete?` (primary) and `Not yet`.
  - When `Skipped` is non-empty it adds a second line, `K lines weren't printed:`, followed by `Persona — Award: reason` items.
  - When `Dropped > 0` it adds `D more weren't included — print again after completing these.`
  - Yes POSTs `complete_printed_scrolls` with the pending run. It updates the `Completed` lines' chips to 2 and bumps their `RowVersion` by 1. A non-empty `Stale` adds `S lines changed since printing and were left as they were.`, and the heartbeat reconciles the rest.
  - Not yet clears the pending run and hides the bar.
  - Light and dark styles.
- [ ] **Step 4: Run the tests.** Run `php vendor/bin/phpunit --filter "Court|Scroll"`. Expected: PASS. Run `php -l orkui/template/default/Court_detail.tpl`. Expected: clean.
- [ ] **Step 5: Verify in Chrome** on a local court at `http://localhost:19080/orkui/index.php?Route=Court/detail/<id>`, logged in under the bypass. Take the Chrome steps serially, in one tab. Check each item and screenshot it in both themes:
  - the stacked chips
  - the dropdown opens without clipping, closes on Escape and on an outside click, and works from the keyboard
  - Scrollery Print is disabled on an officer-position line
  - setting Scrollery Print hides and clears Scroll Maker
  - the button count tracks chip changes
  - the print tab shows one page per line
  - the bar lists skipped lines
  - Yes turns exactly the printed chips green; with a chip changed in a second tab before Yes, that line is reported stale and left alone
  - the stats bar, Printing List and Prep Sheet show the new states
  - the phone width layout works, via the iframe harness (`resize_window` is a no-op)
- [ ] **Step 6: Commit.** Use the pathspec: `Enhancement: Court Planner — bulk Scrollery print with "Set these scrolls to complete?"`.
