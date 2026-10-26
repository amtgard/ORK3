# Court Planner Deeper Tracking Statuses Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the scroll/regalia tracking states with Pending, Scrollery Print, Assigned, In Progress, Complete and Not Needed, all driven from one shared definition. Add Pending ↔ Assigned maker rules on both tabs, and add Scrolls/Regalia stacked status bars to Resource Planning.

**Architecture:**
- **Lib and data:** the lib owns the status definition as `Court::trackingStatusDefinitions()`. A data backfill moves `status=1` rows with no maker to 0.
- **Shared definition:** the Court Planner template emits the definition once as JSON. Every JS and PHP consumer reads it: labels, picker, CSS colour group, status bar, printed sheets, and the RP bars, filters and "needs an artisan".
- **Front end:** it stays inside `Court_detail.tpl`, as the Resource Planning tab did.

**Tech Stack:** PHP 8, MariaDB, vanilla ES5 JS, CSS, PHPUnit (host, test DB container `ork3-php8-test-db`), and Chrome verification against Docker :19080.

**Spec:** `docs/superpowers/specs/2026-10-26-court-planner-deeper-tracking-statuses-design.md`

## Global Constraints

- **Values (never renumber):**

  | Status | Value |
  |---|---|
  | Pending | 0 |
  | Assigned | 1 |
  | Complete | 2 |
  | Scrollery Print | 3 (scroll only) |
  | In Progress | 4 |
  | Not Needed | 5 |

- **Display order:** Pending, Scrollery Print, Assigned, In Progress, Complete, Not Needed. Regalia uses the same order without Scrollery Print.
- **Groups:**

  | Status | Group |
  |---|---|
  | Pending | `pending` |
  | Scrollery Print | `print` |
  | Assigned | `assigned` |
  | In Progress | `inprogress` |
  | Complete | `done` |
  | Not Needed | `notneeded` |

- **Hints (verbatim):**

  | Status | Hint |
  |---|---|
  | Pending | "Needed — nobody has it yet" |
  | Scrollery Print | "Goes in the bulk print" |
  | Assigned | "An artisan has agreed to make it" |
  | In Progress | "Being made" |
  | Complete | none |
  | Not Needed | "This line doesn't get one" |

- **"Needs an artisan":** the item is in group `pending`, `assigned` or `inprogress`, has no maker, and the line's `Status !== 'cancelled'`.
- **Line complete:** each item is in group `done` or `notneeded`.
- **Maker rules, on both tabs and both types:**
  - Assigning a maker while the item is Pending moves it to Assigned.
  - Clearing a maker while the item is Assigned moves it back to Pending.
  - Any other status is unchanged.
  - The status write is queued on `cpLineWrites[caid]` after the maker write.
- **Unchanged contracts:** the Scrollery flow is unchanged (`complete_printed_scrolls` keys on 3 → 2). New lines still default to 0. The Release Feature Utilization report SQL is unchanged.
- **Front-end conventions:**
  - No native dialogs.
  - Tooltips use `data-tip`.
  - Every new surface supports dark mode via `html[data-theme="dark"]`.
  - At ≤640px, nothing scrolls horizontally on the page.
- **Commit hygiene:**
  - Never stage `system/lib/ork3/class.Authorization.php` or `.github/workflows/scroll-guards.yml`.
  - Never `git add -A` or `git stash`.
  - Run `git diff --cached --stat` before each commit.
  - Messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **An existing open court after the backfill.**
   - Untouched items read Pending and count as needing an artisan.
   - Former Custom Design and Needed items with a maker read Assigned.
   - Items without a maker read Pending.
   - The Order chips, RP grid, status bar and both bars all agree.
   - Task 3's browser check covers this.
2. **Clearing a maker on In Progress.** The status stays In Progress and the item becomes flagged. Covered by Task 3.
3. **Order expand Save that both clears a maker and edits notes.** Clearing on an Assigned item moves it to Pending, with exactly one status write and no stale toast. Covered by Task 2.
4. **The printed Prep Sheet and Printing List with In Progress and Not Needed items.** Each group appears under its new label, and Not Needed items are not listed as work to do. Covered by Task 2.
5. **A regalia picker POST of 3 (Scrollery Print).** The server refuses it. Covered by the Task 1 test.

---

### Task 1: Lib definition, constants, server validation, backfill

**Files:**
- Modify: `system/lib/ork3/class.Court.php` (constants ~33-38, `allowedTrackingStatuses` ~3081, `setAwardTrackingStatus`, `completePrintedScrolls`)
- Modify: `orkui/model/model.Scrollery.php` (~280, ~345: constant name only), and the `model.Court.php` pass-through if one is needed
- Modify: `orkui/controller/controller.Court.php` (pass the definition to the template)
- Create: `db-migrations/2026-10-26-court-tracking-pending-backfill.sql`
- Modify: `tools/ork-db/manifests/migration-classification.json5`
- Modify tests: `tests/Integration/CourtTrackingStatusTest.php`, `tests/Integration/ScrolleryCourtPrintTest.php`, `tests/Unit/ScrolleryCourtLineTest.php` (constant renames)

**Interfaces:**
- Produces:
  - Constants: `Court::SCROLL_PENDING=0`, `SCROLL_ASSIGNED=1`, `SCROLL_COMPLETE=2`, `SCROLL_SCROLLERY=3`, `SCROLL_IN_PROGRESS=4`, `SCROLL_NOT_NEEDED=5`, and `REGALIA_PENDING=0`, `REGALIA_ASSIGNED=1`, `REGALIA_COMPLETE=2`, `REGALIA_IN_PROGRESS=4`, `REGALIA_NOT_NEEDED=5`. The old names `SCROLL_NOT_TRACKED`, `SCROLL_CUSTOM` and `REGALIA_NEEDED` are removed, and every use is renamed.
  - `public static function trackingStatusDefinitions(): array`, shaped `['scroll' => [ ['value'=>0,'label'=>'Pending','hint'=>'…','group'=>'pending'], … ], 'regalia' => [ … ]]` and listed in display order.
  - `allowedTrackingStatuses($type)` returns the `value`s from that definition.
  - The controller sets `$this->data['TrackingStatuses'] = Court::trackingStatusDefinitions()`. Go through the model if the layering gate requires it; check how `controller.Court.php` reaches other Court statics.

- [ ] **Step 1: Write failing tests** in `CourtTrackingStatusTest`:
  - `testDefinitionsListStatusesInDisplayOrder`: scroll values are `[0,3,1,4,2,5]` and regalia values are `[0,1,4,2,5]`. The labels and groups match the Global Constraints exactly.
  - `testSetsEachAllowedScrollStatus`: extended to cover 4 and 5.
  - `testSetsEachAllowedRegaliaStatus`: covers 0, 1, 2, 4 and 5.
  - `testRegaliaRejectsScrolleryValue`: keep it.
  - `testRejectsUnknownStatus`: 6 is refused for both types.
  - `testBackfillMovesUnmakeredAssignedToPending`:
    1. Create fixture lines with these combinations:
       - scroll 1 with no maker
       - scroll 1 with a maker
       - regalia 1 with no maker
       - regalia 1 with a maker
       - scroll 2 with no maker
    2. Run the migration file's SQL. Read it from `db-migrations/` and execute each statement; the test DB is disposable.
    3. Assert the outcome: only the no-maker 1s become 0. Everything else is unchanged.
- [ ] **Step 2: Run them.** `php vendor/bin/phpunit --filter CourtTrackingStatusTest` should FAIL.
- [ ] **Step 3: Implement.**
  - Constants and the definition, as above.
  - `allowedTrackingStatuses` is derived from the definition.
  - Rename constants in `model.Scrollery.php` and the tests.
  - The migration has exactly two guarded UPDATEs:
    - `UPDATE ork_court_award SET scroll_status = 0, row_version = row_version + 1 WHERE scroll_status = 1 AND scroll_maker_id IS NULL;`
    - the same for regalia
  - Add a header comment in the house migration style.
  - Classification entry: `"2026-10-26-court-tracking-pending-backfill.sql": { "class": "PB", "render": "skip", "notes": "Custom Design / Needed lines with no maker become Pending" }`.
  - Pass the controller data.
- [ ] **Step 4: Run** `php vendor/bin/phpunit --filter 'CourtTrackingStatusTest|Scrollery|Court'`. Everything should PASS, and the Scrollery tests should be unchanged in behaviour. Also run `php -l` on each changed PHP file.
- [ ] **Step 5: Apply the migration to the local dev DB** `ork` through `mariadb`, per `reference_startup_commands`. It is a data fix the app needs to read correctly. Note the before and after counts in the report.
- [ ] **Step 6: Commit.** `Enhancement: Court tracking — Pending/Assigned/In Progress/Not Needed statuses, one definition, backfill`

### Task 2: One shared definition on the page, and Order-side consumers

**Files:**
- Modify: `orkui/template/default/Court_detail.tpl`, which covers the following:
  - PHP: `cp_track_label` ~63 and `cp_track_chip`, and the status bar `$_stat_html`
  - JS: `cpTrackLabel` ~3408, `CP_TRACK_STATES`/`CP_TRACK_HINTS` ~3416, the picker `cpOpenTrackMenu`, `cpRefreshStatusBar` ~3855, `cpTogglePrintingList` ~3354, Prep Sheet ~7095, the About legend ~1631, the court script, and `cpSaveAward`
  - the chip and dot CSS ~742-794
- Check: `orkui/template/default/Court_record.tpl` (~2076). If it shows labels, they must come from the definition.

**Interfaces:**
- Consumes: `$TrackingStatuses` from Task 1.
- Produces:
  - `window.CP_TRACK_DEF`, the JSON of the definition.
  - `cpTrackDef(type, value)` returns the entry, or the Pending entry if the value is unknown.
  - `cpTrackGroup(type, value)`.
  - `cpTrackLabel(type, value)`, rebuilt on the definition.
  - `CP_TRACK_STATES`, derived from the definition in display order.
  - `cpNeedsArtisan(a, type)`.
  - `cpLineComplete(a)`.
  - `cpApplyMakerRule(caid, type, hadMaker, hasMaker)`, which queues the Pending ↔ Assigned status write.
  - Chips and picker dots carry `data-group`, and all colour CSS keys on `data-group`, not on numbers.

- [ ] **Step 1: Emit the definition.**
  - A PHP helper `cp_track_def()` reads `$TrackingStatuses`.
  - Rebuild `cp_track_label` and `cp_track_chip` on it. The chip gets `data-group`.
  - Add one `<script>` assignment `window.CP_TRACK_DEF = …json_encode…`, placed before the first script that uses it.
- [ ] **Step 2: Rebuild the JS readers on it.** This covers `cpTrackLabel`, `CP_TRACK_STATES`, the picker options and hints, and `cpApplyTrackState`, which sets `data-group`. Move the old Scrollery-printable disable rule over unchanged. Add `cpTrackDef`, `cpTrackGroup`, `cpNeedsArtisan` and `cpLineComplete`.
- [ ] **Step 3: Colours.**
  - Replace the per-number chip and dot rules with per-group rules, in light and dark themes, for both types:
    - pending: slate grey
    - print: the existing blue
    - assigned: the existing purple
    - inprogress: amber
    - done: the existing green
    - notneeded: pale grey with a hatched background
  - Verify contrast with the dataviz skill's guidance (`Skill: dataviz`).
- [ ] **Step 4: Order consumers.**
  - **Status bar.** Update both the PHP `$_stat_html` and `cpRefreshStatusBar`. Counts are per status from the definition, using its labels.
  - **Printing List and Prep Sheet.** Group by the definition's labels. Pending, Assigned and In Progress are work to do. Scrollery Print stays its own group. Complete is done. Not Needed is left out of the to-do sheets.
  - **About legend and court script.** Use the new wording and the new statuses.
- [ ] **Step 5: Maker rules on the Order expand Save** (`cpSaveAward`).
  - After a successful save that changed a maker, call `cpApplyMakerRule`. For each changed maker type:
    - assigning to a Pending item queues a status write to Assigned
    - clearing from an Assigned item queues a status write to Pending
  - Use the same `cpLineWrites` chaining and the `set_award_tracking_status` → `cpSetLineTracking` path. The rule must read the status and RowVersion from the model at its turn.
- [ ] **Step 6: Verify.**
  - Run `php -l`.
  - Render a court page with curl and run `node --check` on every inline script.
  - In Chrome (own tab):
    - the picker lists the six states for scrolls and five for regalia, with hints
    - the chip colours are correct in both themes
    - an Order Save assigning a maker to a Pending item moves it to Assigned, and a Save clearing it moves it back
    - the status bar counts, the Printing List and the Prep Sheet show In Progress and Not Needed correctly
  - Snapshot and restore the DB rows you touch.
- [ ] **Step 7: Commit.** `Enhancement: Court Planner — new tracking statuses on the Order, from one shared definition`

### Task 3: Resource Planning — status bars, filters, maker rules, end-to-end verification

**Files:**
- Modify: `orkui/template/default/Court_detail.tpl`, covering the RP helpers `cpRpTrackVal` ~5889, `cpRpNeeds*` ~5893, `cpRpMatches` ~5899, `cpRpCounts` ~5916, `cpRpSummaryHtml` ~5943, `cpRpTrackAssigned`/`cpRpSetMaker` ~6242, and the RP CSS

**Interfaces:**
- Consumes everything Task 2 produces (`CP_TRACK_DEF`, `cpTrackDef`, `cpTrackGroup`, `cpNeedsArtisan`, `cpLineComplete`, `cpApplyMakerRule`), plus the RP machinery: `cpRpInvalidate`, `cpRpRender`, and the filter state.
- Produces:
  - `cpRpBarsHtml(counts)`, which replaces `cpRpSummaryHtml`.
  - Filter keys `all`, `needs`, `complete`, and `type:value` (e.g. `regalia:4`) for a bar block.

- [ ] **Step 1: Rebuild the RP predicates on the shared helpers.**
  - `cpRpNeeds*` becomes `cpNeedsArtisan`, line complete becomes `cpLineComplete`, and `cpRpTrackVal` reads the definition.
  - `cpRpTrackAssigned` becomes `cpApplyMakerRule`, which gives both directions: assign Pending → Assigned, and clear Assigned → Pending.
  - Keep the Scrollery Print guard.
- [ ] **Step 2: The bars.**
  - **Layout.** Two rows, "Scrolls N" and "Regalia N", each with a full-width bar.
  - **Blocks.** One block per status present, in display order with Not Needed last, sized by count. Inside a block, one slice per line, separated by 1px gaps in the page background colour.
  - **Legend.** A single row: the swatch, the label, and the "scrolls · regalia" counts.
  - **Needs an artisan.** The "N need an artisan" callout acts as a filter.
  - **Empty court.** Show the "No awards yet" track.
  - **Skipped lines.** Excluded, with the existing note.
  - **Labels and focus.** Each block is a `<button>` labelled "Scrolls: Assigned, 4 of 23". Each slice has a `data-tip` reading "Recipient — Award (rank) · maker".
  - **Clicking a block or slice** sets the filter to `type:value`. The active block gets an outline, and a removable chip above the grid shows "Regalia · In Progress ×". Clicking the active block again clears it.
  - **Filter buttons** are reduced to All · Needs an artisan · Complete lines.
  - **Phones (≤640px).** A taller bar, a wrapping legend, and tap-to-filter.
  - **Dark mode.** Every surface is covered.
- [ ] **Step 3: Verify in Chrome** on a draft court with mixed statuses. Snapshot first and restore everything.
  1. The bars' block order and counts match the grid and the Order status bar.
  2. Click Regalia → In Progress: the grid filters and the chip shows; click again to clear.
  3. Hover a slice: the tip shows the recipient and award.
  4. On RP, assign a maker to a Pending item: it becomes Assigned on both tabs, and the bar moves.
  5. Clear the maker on an Assigned item: Pending. On an In Progress item: it stays In Progress and is flagged.
  6. Change a status on the Order: the RP bars update.
  7. A peer change through the heartbeat (force the tab visible) moves the bars.
  8. Mark both items Not Needed: the line counts as complete and drops from "Needs an artisan".
  9. Check both themes, 375px through the iframe harness, and keyboard focus on the blocks.
  10. No new console errors (the `cpApplyHeroColor` one already exists).
- [ ] **Step 4: Commit.** `Enhancement: Court Planner — Scrolls and Regalia status bars on Resource Planning`
