# Online Attendance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record whether each attendance sign-in was online, capture it on every CRM entry surface and sign-in link, let a kingdom exclude online sign-ins from Voting Eligibility, and give attendance reports In-Person / Online / All views.

**Architecture:** One `online` TINYINT on `ork_attendance` and `ork_attendance_link`. Writes flow controller → model → `Attendance` domain. One static domain helper (`Attendance::TypeClause`) owns the SQL meaning of in-person/online/all; every report uses it. A kingdom config key `CountOnlineAttendance` (EAV `ork_configuration`) feeds only `Report::GetVotingEligible`. Delivered as two PRs: **Part 1 (Tasks 1–9) = PR 1 capture** on `feature/online-attendance`; **Part 2 (Tasks 10–16) = PR 2 reports** on `feature/online-attendance-reports`, branched from the PR 1 tip.

**Tech Stack:** PHP 8.2, MariaDB, PHPUnit 11 (`vendor/bin/phpunit`), plain-PHP `.tpl` templates, vanilla JS in `revised.js`, FontAwesome 7.

**Spec:** `docs/superpowers/specs/2026-11-15-online-attendance-design.md` — read it before starting; it is the binding authority and this plan argues from it.

## Global Constraints

- **Online counts everywhere in rules.** Never filter `online` in credits, class levels, active status, reactivation, ladders, dues, Active Players, Player Status Reconciliation, Inactive Parks/Kingdoms. Only `GetVotingEligible*` reads the kingdom setting.
- **The ORK makes no park status/size determination.** The setting never touches park averages or park titles.
- **SQL only in `system/lib/ork3/`.** Controllers call `orkui/model/*`; models call the lib. No `online =` literal outside `Attendance::TypeClause` and the two voting predicates in Task 7.
- **Existing rows stay `online = 0`.** No backfill.
- **Always write `0`/`1`** to `online` (Yapo drops `null`).
- **Config read:** `(int)$configs['CountOnlineAttendance']['Value'] === 1`. Seed values JSON-quoted (`'"1"'`, `'"0"'`), `var_type='fixed'`, `user_setting=1`.
- **Setting label/hint, verbatim:** label `Count Online Attendance`; hint `When off, sign-ins marked Online are not counted toward Voting Eligibility for this Kingdom. Class credits, active status, player records, other reports and attendance history are not affected.`
- **Link tooltip copy, verbatim:** `Not for an online event? Disable this.`
- **Badge (user-revised):** icon only — FA `fa-solid fa-wifi`, no text label, no pill/border; muted colour in both themes; `data-tip="Signed in online"` + `aria-label="Signed in online"`. Never `title=`.
- **No native `confirm/alert/prompt`.** Dark mode for every new control via `html[data-theme="dark"]`. No horizontal scroll at 390px.
- **Before editing a PHP file** run `awk '/^\t/{c++}END{print c+0}' <file>`. `class.Report.php` reports ~1369 tab lines — they are inside SQL string literals; edit in place, never reformat. Any other non-zero count: STOP and report.
- **Never stage `system/lib/ork3/class.Authorization.php`.** Never `git add -A`/`git add .`; `git diff --cached --stat` before every commit. Never `git stash`.
- **Never add entry points under `orkui/template/default/`.** Editing the existing Behold and Voting templates there is allowed (Tasks 5, 7).
- **Migrations** apply to both DBs: `docker exec -i ork3-php8-db mariadb -u root -proot ork < f.sql` and `docker exec -i ork3-php8-test-db mariadb -u root -proot ork_test < f.sql`; classify in `tools/ork-db/manifests/migration-classification.json5`.
- **Tests:** `vendor/bin/phpunit --filter <Name>`. A SKIP is not a PASS — if the test DB is unavailable, report it.
- **Browser checks:** app at `http://localhost:19080/orkui/`; log in as `heraldsbridge` (any password) in a dedicated cookie jar; never log out the user's session. Chrome agents run serially.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **A sign-in link created before the migration** (no `online` value) must keep working and produce `online = 0` rows → test in Task 3.
2. **Edit attendance without the Online field posted** (old cached JS, SOAP callers) must leave `online` unchanged, not reset it → test in Task 2.
3. **Garbage `Online` / `AttendanceType` values** (`'yes'`, `'2'`, `"1 OR 1"`) normalize to `1`/`0` and `all` respectively → tests in Tasks 1 and 2.
4. **Park-day lookup on a date with no park days, a park with zero park days, and a malformed date** returns `{Online:false, InPerson:false}` / an error, never a PHP warning → test in Task 4.
5. **Kingdom 20 voting results are unchanged after migration** (setting `"0"` + name match preserved), and a kingdom with the setting on sees identical numbers to today → test in Task 7.

---

## File Structure

**Created**
- `db-migrations/2026-11-15-online-attendance.sql` — two columns + config seed.
- `tests/Integration/OnlineAttendanceTest.php` — domain tests for Part 1.
- `tests/Unit/AttendanceTypeClauseTest.php` — pure helper tests.
- `tests/Integration/OnlineAttendanceReportsTest.php` — Part 2 parity tests.

**Modified (Part 1)**
- `system/lib/ork3/class.Attendance.php` — `TypeClause`, `NormalizeType`, `Online` in `AddAttendance`/`SetAttendance`/`CreateAttendanceLink`/`UseAttendanceLink`/`GetAttendanceLinks`/`GetAttendanceLinkInfo`.
- `system/lib/ork3/class.Park.php` — `GetParkDayTypeForDate`.
- `system/lib/ork3/class.Kingdom.php` — `CountsOnlineAttendance`, seed on create.
- `system/lib/ork3/class.VotingRules.php`, `system/lib/ork3/class.Report.php` (voting only in Part 1).
- `system/lib/ork3/class.Player.php`, `class.EventPlanning.php`, `class.Live.php`, `class.Report.php` (`AttendanceForDate`, `AttendanceForEvent`) — return `Online` on row lists.
- `orkui/model/model.Attendance.php`, `orkui/model/model.Park.php`.
- `orkui/controller/controller.AttendanceAjax.php`, `controller.EventAjax.php`, `controller.Attendance.php` (Behold), `controller.SignIn.php`, `controller.Kingdom.php`.
- `orkservice/Attendance/AttendanceService.definitions.php`.
- `orkui/template/revised-frontend/Parknew_index.tpl`, `Eventnew_index.tpl`, `Playernew_index.tpl`, `SignIn_index.tpl`, `Live_index.tpl`, `script/revised.js`, `style/revised.css`.
- `orkui/template/default/Attendance_behold.tpl`, `orkui/template/default/Reports_voting_eligible.tpl`.

---

# Part 1 — PR 1: Capture (`feature/online-attendance`)

### Task 1: Migration and the shared type helper

**Files:**
- Create: `db-migrations/2026-11-15-online-attendance.sql`, `tests/Unit/AttendanceTypeClauseTest.php`
- Modify: `system/lib/ork3/class.Attendance.php`, `tools/ork-db/manifests/migration-classification.json5`

**Interfaces:**
- Produces: `Attendance::NormalizeType(mixed $mode): string` → one of `'all' | 'in_person' | 'online'` (anything else → `'all'`).
- Produces: `Attendance::TypeClause(mixed $mode, string $alias = 'a'): string` → `''` | `" AND {alias}.online = 0"` | `" AND {alias}.online = 1"`. `$alias` must match `/^[a-z_][a-z0-9_]*$/i`, else `'a'`.
- Produces: columns `ork_attendance.online`, `ork_attendance_link.online`; config key `CountOnlineAttendance`.

- [ ] **Step 1: Write the failing unit test** `AttendanceTypeClauseTest`:
  - `testNormalize`: `'all'→'all'`, `'in_person'→'in_person'`, `'online'→'online'`, `''→'all'`, `null→'all'`, `'ONLINE'→'all'`, `"online' OR 1"→'all'`.
  - `testClause`: `TypeClause('all')===''`; `TypeClause('in_person')===' AND a.online = 0'`; `TypeClause('online','att')===' AND att.online = 1'`; `TypeClause('online','a; DROP')===' AND a.online = 1'`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter AttendanceTypeClauseTest` — expect FAIL (undefined method).
- [ ] **Step 3: Implement** both as `public static` on `Attendance` (static so `Report`, `StateOfAmtgard`, `PopulationExplorer` can call `Attendance::TypeClause()` without an instance).
- [ ] **Step 4: Run** the test — PASS.
- [ ] **Step 5: Write the migration** (idempotent):
  1. `ALTER TABLE ork_attendance ADD COLUMN IF NOT EXISTS online TINYINT(1) NOT NULL DEFAULT 0 AFTER entry_method;`
  2. `ALTER TABLE ork_attendance_link ADD COLUMN IF NOT EXISTS online TINYINT(1) NOT NULL DEFAULT 0 AFTER credits;`
  3. Seed `CountOnlineAttendance` for every kingdom lacking it, following `db-migrations/2026-06-03-add-include-principality-in-statistics-config.sql` exactly (LEFT JOIN guard), value `'"1"'`.
  4. `UPDATE ork_configuration SET value='"0"' WHERE type='Kingdom' AND id=20 AND \`key\`='CountOnlineAttendance';`
- [ ] **Step 6: Apply to both DBs; verify** `SHOW COLUMNS FROM ork_attendance LIKE 'online'` and `SELECT value FROM ork_configuration WHERE \`key\`='CountOnlineAttendance' AND id=20` → `"0"`, in both `ork` and `ork_test`. Run the migration a second time — no error.
- [ ] **Step 7: Classify** the migration in `migration-classification.json5` following neighbouring entries (schema + config seed).
- [ ] **Step 8: Commit** the four files.

### Task 2: Domain + service writes carry `Online`

**Files:**
- Modify: `system/lib/ork3/class.Attendance.php` (`AddAttendance` :66, `SetAttendance` :170), `orkui/model/model.Attendance.php` (`add_attendance` :22, `update_attendance` :37), `orkservice/Attendance/AttendanceService.definitions.php` (`AddAttendanceRequest` :126, `SetAttendanceRequest` :146)
- Test: `tests/Integration/OnlineAttendanceTest.php` (uses `AttendanceFixture`)

**Interfaces:**
- Consumes: Task 1 columns.
- Produces: request key `Online` on `AddAttendance`/`SetAttendance` — normalized `(int)!empty($v) && (string)$v !== '0'` → `0|1`. Add: missing → `0`. Set: **missing → unchanged**.
- Produces: `Model_Attendance::add_attendance($token, $date, $park_id, $detail_id, $mundane_id, $class_id, $credits, int $online = 0)`; `update_attendance($token, $attendance_id, $date, $credits, $class_id, $mundane_id, ?int $online = null)` (null = don't send key).
- Produces: fixture helper `AttendanceFixture::fetchOnline(int $attendanceId): int`.

- [ ] **Step 1: Write failing tests** in `OnlineAttendanceTest`:
  - `testAddAttendanceDefaultsInPerson` — add without `Online` → `fetchOnline === 0`.
  - `testAddAttendanceOnline` — `'Online' => 1` → `1`; `'Online' => 'yes'` → `1`; `'Online' => '0'` → `0`.
  - `testSetAttendanceFlipsOnline` — add online, `SetAttendance` with `'Online' => 0` → `0`.
  - `testSetAttendanceWithoutOnlineKeepsValue` — add online, `SetAttendance` with no `Online` key → still `1`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter OnlineAttendanceTest` — FAIL.
- [ ] **Step 3: Implement** in `AddAttendance` (set `$this->attendance->online` alongside `entry_method`) and `SetAttendance` (set only when `array_key_exists('Online', $request)`; also add `online` to the `$attendance` audit snapshot). Add `fetchOnline` to `AttendanceFixture`. Thread the new model params; add an optional `Online` int to both SOAP request definitions.
- [ ] **Step 4: Run** — PASS. Also run `vendor/bin/phpunit --filter 'AttendanceWriteTest|AttendanceSignInTest'` — no regressions.
- [ ] **Step 5: Commit.**

### Task 3: Sign-in links carry `Online`

**Files:**
- Modify: `system/lib/ork3/class.Attendance.php` (`CreateAttendanceLink` :503, `GetAttendanceLinkInfo` :621, `UseAttendanceLink` :675, `GetAttendanceLinks` :929), `orkui/controller/controller.AttendanceAjax.php` (`link()` create branch ~:233–252), `orkui/model/model.Attendance.php` (`create_attendance_link` passes `$args` through — confirm)
- Test: `tests/Integration/OnlineAttendanceTest.php`

**Interfaces:**
- Produces: `CreateAttendanceLink` request key `Online` (same normalization; missing → `0`).
- Produces: `GetAttendanceLinks` rows gain `'Online' => int`; `GetAttendanceLinkInfo` result gains `'Online' => int`.
- Produces: `UseAttendanceLink` writes `online = (int)$this->attendance_link->online`.
- Produces: fixture `AttendanceFixture::insertParkLink(..., int $online = 0)` (new trailing param).

- [ ] **Step 1: Write failing tests:**
  - `testCreateParkLinkStoresOnline` — officer creates a park link with `'Online'=>1`; `GetAttendanceLinks` for the park returns `Online === 1`.
  - `testUseOnlineLinkWritesOnlineRow` — `insertParkLink(..., online: 1)`, player uses it → row `online = 1`, `entry_method = 'signin_link'`.
  - `testLegacyLinkWritesInPersonRow` — `insertParkLink` with default → row `online = 0` (Review Focus 1).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.** Controller create branch reads `$_POST['Online']` and adds it to `$args`.
- [ ] **Step 4: Run** `--filter 'OnlineAttendanceTest|AttendanceSignInTest'` — PASS.
- [ ] **Step 5: Commit.**

### Task 4: Online park-day lookup

**Files:**
- Modify: `system/lib/ork3/class.Park.php`, `orkui/model/model.Park.php`, `orkui/controller/controller.AttendanceAjax.php` (`park()` new action `daytype`)
- Test: `tests/Integration/OnlineAttendanceTest.php`

**Interfaces:**
- Produces: `Park::GetParkDayTypeForDate(int $parkId, string $date): array` → `['Status'=>Success(), 'Online'=>bool, 'InPerson'=>bool]`; invalid date → `InvalidParameter()` status. Uses `GetParkDays` rows and `ork_parkday_next_occurrences($pd, $date, 1, 1)`; a park day matches when the returned array is `[$date]`. A row's `Online` key from `GetParkDays` decides which bucket (add `'Online' => (int)$parkday->online` to `GetParkDays` rows if absent).
- Produces: `Model_Park::get_parkday_type_for_date(int $park_id, string $date): array`.
- Produces: `GET AttendanceAjax/park/{id}/daytype?date=YYYY-MM-DD` → `{"status":0,"online":bool,"inPerson":bool}`; bad date → `{"status":1,"error":"Invalid date"}`. Public (park days are public data).

- [ ] **Step 1: Write failing tests** (insert `ork_parkday` rows directly; clean up in tearDown):
  - weekly online-only Tuesday → on a Tuesday `Online=true, InPerson=false`; on Wednesday both false.
  - weekly online + weekly in-person same weekday → both true.
  - `every-x-weeks` online with `start_date` and `week_interval=2` → true on start+14d, false on start+7d.
  - park with zero park days → both false; `'2026-13-40'` → non-zero status (Review Focus 4).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** domain, model, controller action (validate date with the same regex as `getday`).
- [ ] **Step 4: Run** — PASS. Curl: `curl -s 'http://localhost:19080/orkui/index.php?Route=AttendanceAjax/park/<parkWithOnlineDay>/daytype&date=<matching date>'` → `"online":true`.
- [ ] **Step 5: Commit.**

### Task 5: Online? switch on the officer entry surfaces

**Files:**
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl` (Enter Attendance modal ~:2084–2112), `orkui/template/revised-frontend/Eventnew_index.tpl` (~:1817, ~:2335), `orkui/template/revised-frontend/script/revised.js` (park modal ~:10814/:11350; event add ~:9276; player-profile modal ~:16211/:16324; edit forms ~:10933, ~:16092; `Eventnew_index.tpl` edit ~:3478), `orkui/template/revised-frontend/style/revised.css`, `orkui/controller/controller.AttendanceAjax.php` (`park()` add, `attendance()` edit), `orkui/controller/controller.EventAjax.php` (`add_attendance` ~:171), `orkui/controller/controller.Attendance.php` (`behold` ~:117–155), `orkui/template/default/Attendance_behold.tpl`

**Interfaces:**
- Consumes: Task 2 model params; Task 4 `daytype` endpoint.
- Produces: POST field `Online=0|1` on `AttendanceAjax/park/{id}/add`, `AttendanceAjax/attendance/{id}/edit` (edit sends it only when the checkbox exists), `EventAjax/add_attendance/...`, Behold form.
- Produces: shared JS `ornOnlineSwitch(container, {initial:bool, hintEl?:Element}) → {get():bool, set(bool, reason?:string)}` in `revised.js`, and CSS `.orn-online-switch`, `.orn-online-hint` (light + dark).

- [ ] **Step 1: Build `ornOnlineSwitch`** — a labeled switch "Online?" (`role="switch"`, `aria-checked`, ≥44px hit area), plus an optional hint line. Dark-mode rules under `html[data-theme="dark"]`.
- [ ] **Step 2: Park modal.** Place the switch in the date row (`.pk-att-date-row`) so it is visible before the first add. On modal open and on every date change, call `daytype`: `online && !inPerson` → `set(true, 'Online park day')`; `online && inPerson` → `set(false, 'This park has an online and an in-person park day today.')`; else `set(false)`. The officer's manual flip wins until the date changes. Search-tab Add and Recent-tab quick-add both send `Online`.
- [ ] **Step 3: Event add form** — switch defaults off; no park-day lookup; sends `Online`.
- [ ] **Step 4: Player-profile Add Attendance modal** — switch; when both park and date are chosen, run the same `daytype` rule.
- [ ] **Step 5: Edit forms** (park, player, event) — an Online checkbox pre-filled from the row's `Online` (Task 6 supplies it); send `Online` on save.
- [ ] **Step 6: Behold** — one checkbox "Online?" in the form; `controller.Attendance.php` passes `Online` to every `AddAttendance` in the loop.
- [ ] **Step 7: Controllers** read `(int)!empty($_POST['Online'])` and pass to the model.
- [ ] **Step 8: Verify via curl** (test account cookie jar): POST park add with `Online=1` → DB row `online=1`; edit without `Online` → unchanged; edit with `Online=0` → `0`.
- [ ] **Step 9: Browser check** (single Chrome agent): park modal on a date of an online park day shows the switch on with the hint; flip off, add → row `online=0`; dark mode computed styles; 390px no horizontal scroll.
- [ ] **Step 10: Commit.**

### Task 6: Online flows to lists; badges and CSV

**Files:**
- Modify: `system/lib/ork3/class.Player.php` (`GetPlayerAttendanceList` :593, `AttendanceForPlayer` :1087), `system/lib/ork3/class.Report.php` (`AttendanceForDate` :2023, `AttendanceForEvent` :1952), `system/lib/ork3/class.EventPlanning.php` (`GetAttendanceDisplayRow` :1059, occurrence attendee list in `GetOccurrencePageData` :273), `system/lib/ork3/class.Live.php` (`recent` :225), `orkui/controller/controller.AttendanceAjax.php` (`getday` entries), templates `Playernew_index.tpl` (~:7731), `Parknew_index.tpl`, `Eventnew_index.tpl`, `Live_index.tpl`, `script/revised.js`, `style/revised.css`
- Test: `tests/Integration/OnlineAttendanceTest.php`

**Interfaces:**
- Produces: every listed row array gains `'Online' => int` (select `a.online`); `getday` entries gain `'Online'`.
- Produces: PHP helper for templates `ork_online_badge(): string` in `system/lib/ork3/common.php` returning the badge HTML (Global Constraints copy), and JS `ornOnlineBadge(): string` in `revised.js` returning the same markup; CSS `.orn-online-badge` light + dark.

- [ ] **Step 1: Write failing tests:** after inserting one online and one in-person row for a player, `AttendanceForPlayer` returns both with correct `Online`; `AttendanceForDate` likewise.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement** the selects. **Step 4: Run** — PASS.
- [ ] **Step 5: Render the badge** on: player attendance tab, park day list (`getday`-driven), per-date and per-event attendance lists, event occurrence attendee list, Live ticker. Counts unchanged.
- [ ] **Step 6: CSV.** Find attendance-row exports with `grep -rn "text/csv" orkui/controller | xargs` and keep only those whose rows come from the methods above or other `ork_attendance` row lists; add an `Online` column (`Yes` / empty). List the ones changed in the commit message.
- [ ] **Step 7: Browser check** both themes; badge never uses `title`.
- [ ] **Step 8: Commit.**

### Task 7: Kingdom setting + Voting Eligibility

**Files:**
- Modify: `system/lib/ork3/class.Kingdom.php` (create seed ~:417; new accessor near `StatsIncludesPrincipalities` :750), `system/lib/ork3/class.VotingRules.php` (kingdom 20 ~:69), `system/lib/ork3/class.Report.php` (`GetVotingEligible` :4395 — rule merge ~:4402, `$exclude_online` :4421, `$online_clause` ~:4484–4497, `$online_count_join` ~:4645–4657), `orkui/template/default/Reports_voting_eligible.tpl` (:171 copy), `orkui/template/revised-frontend/script/revised.js` (`keyLabels`/`keyHints` ~:5727–5740, checkbox renderer branch ~:5797), `tests/Integration/VotingRulesTest.php`
- Test: `tests/Integration/OnlineAttendanceTest.php`, `VotingRulesTest`

**Interfaces:**
- Produces: `Kingdom::CountsOnlineAttendance(int $kingdomId): bool` — memoized per request; **missing row → `true`**.
- Behaviour: in `GetVotingEligible`, when `!array_key_exists('ExcludeOnline', $request)` and `$kingdom_id > 0`, set `$exclude_online = !Ork3::$Lib->kingdom->CountsOnlineAttendance($kingdom_id)` (independent of the `AttendanceMode` rule-merge guard). `VotingRules[20]` no longer has `ExcludeOnline`.
- Predicates (the only hand-written `online` SQL besides `TypeClause`): exclusion `AND a.online = 0 AND (<existing name checks>)`; excluded count `AND (a.online = 1 OR _oe.name LIKE '%Online%' OR _op.name LIKE '%Online%')`.

- [ ] **Step 1: Write failing tests** (use `ReportsFixture`; add `insertAttendance(..., int $online = 0)` trailing param and `setKingdomConfig(int $kingdomId, string $key, string $jsonValue)` helper restoring the original in cleanup):
  - `testCountsOnlineDefaultsTrueWhenMissing`.
  - `testVotingExcludesFlaggedOnlineWhenSettingOff` — player in a kingdom with setting `"0"`: 6 in-person days + 1 online day; `att_count` (or the report's attendance figure) excludes the online one and `online_excluded_count === 1`.
  - `testVotingCountsOnlineWhenSettingOn` — same data, setting `"1"` → online day counted, no excluded column.
  - `testNameMatchStillExcludedWhenSettingOff` — row at a park named `%Online%` with `online=0` → excluded (Review Focus 5).
  - Update `VotingRulesTest` expectations for kingdom 20 rules (no `ExcludeOnline` key).
- [ ] **Step 2: Run** `--filter 'OnlineAttendanceTest|VotingRulesTest'` — FAIL.
- [ ] **Step 3: Implement** accessor, kingdom-create seed (`add_config(..., 'fixed', ..., 'CountOnlineAttendance', '1')` beside `IncludePrincipalityInStatistics`), VotingRules edit, Report predicates.
- [ ] **Step 4: Report copy** at `Reports_voting_eligible.tpl:171`: replace `Events that include "Online" in the event name are not included in attendance.` with `Online sign-ins are not counted (Kingdom setting).` Leave the per-player excluded-count column.
- [ ] **Step 4b: Player-profile voting badge** — `GetVotingEligibleForPlayer` already routes through `GetVotingEligible` (~:8353), so the rule applies automatically; return `'ExcludeOnline' => bool` in its result and, where the badge renders on `Playernew_index.tpl`, append ` Online sign-ins are not counted (Kingdom setting).` to the badge's `data-tip` when true.
- [ ] **Step 5: Settings UI** — add label/hint (verbatim copy) and render `CountOnlineAttendance` with the same checkbox branch as `IncludePrincipalityInStatistics` (share the branch: `cfg.Key === 'IncludePrincipalityInStatistics' || cfg.Key === 'CountOnlineAttendance'`). Confirm `controller.Kingdom.php` ~:490 passes it through (it is only filtered for the principality key).
- [ ] **Step 6: Run** tests — PASS. Then parity check: for kingdom 20 and one kingdom with setting on, run the voting report via curl before/after on `ork` and diff the eligible player IDs — identical (setting on: identical; kingdom 20: identical because all existing rows are `online=0` and the name match is preserved).
- [ ] **Step 7: Browser check** the kingdom settings dialog: toggle saves, reloads checked state, hint shows; dark mode.
- [ ] **Step 8: Commit.**

### Task 8: Sign-in link UI — default, flash, badge, player page

**Files:**
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl` (Sign-in Link panel ~:2190), `script/revised.js` (park link create ~:11849, kingdom-side park link ~:12355, event link create ~:12128, link lists ~:12196 and the park list), `orkui/template/revised-frontend/SignIn_index.tpl`, `orkui/controller/controller.SignIn.php` (pass `Online` from link info), `style/revised.css`

**Interfaces:**
- Consumes: Task 3 `Online` on create/list/info; Task 5 `ornOnlineSwitch`; Task 6 `ornOnlineBadge`.

- [ ] **Step 1: Park link panel** — `ornOnlineSwitch` initial **on**; sends `Online` on create. When the panel becomes visible, programmatically show the switch's tooltip (`Not for an online event? Disable this.`) for 4 s using the existing `data-tip` tooltip styling (add a `.is-flashing` class that forces it visible + a subtle pulse; respect `prefers-reduced-motion`), then remove the class; hover still shows it via `data-tip`.
- [ ] **Step 2: Kingdom-side park link JS** (~:12355) — same default/flash.
- [ ] **Step 3: Event link** — switch initial **off**, no flash.
- [ ] **Step 4: Link lists** — `ornOnlineBadge()` beside online links.
- [ ] **Step 5: Player sign-in page** — when link info `Online === 1`, show a line `Online sign-in` with the badge above the class picker.
- [ ] **Step 6: Browser check:** park panel opens with switch on and tooltip visible ~4 s; event panel switch off; create online link → list badge → open link as the test player → sign in → DB row `online=1`. Dark mode; 390px.
- [ ] **Step 7: Commit.**

### Task 9: PR 1 verification and PR

- [ ] **Step 1:** `vendor/bin/phpunit --testsuite unit` and `--filter 'OnlineAttendance|AttendanceSignIn|AttendanceWrite|VotingRules|EventAttendanceAjax'` — all PASS, no SKIPs.
- [ ] **Step 2:** `php -l` every changed PHP file; `grep -rn "online = \|\.online=" system/lib/ork3 | grep -v TypeClause` → only the Task 7 voting predicates.
- [ ] **Step 3:** Run the `layers-review` skill on the branch; fix all real findings.
- [ ] **Step 4:** Push `feature/online-attendance` to `origin`; open PR to `amtgard/ORK3` titled `Enhancement: Online attendance — mark sign-ins as online`, body summarizing Part 1 and noting reports follow in a second PR.

---

# Part 2 — PR 2: Reports (`feature/online-attendance-reports`, from PR 1 tip)

Every task here: the domain method accepts request key `AttendanceType`, normalizes with `Attendance::NormalizeType`, and appends `Attendance::TypeClause($type, '<alias>')` to **every** `ork_attendance` subquery it runs. The page passes `AttendanceType` from a shared control. Tests live in `tests/Integration/OnlineAttendanceReportsTest.php` and follow one pattern per method, seeding one online and two in-person rows in a fixture park:
- `all` result equals the result with the key omitted (pre-change behaviour);
- `in_person` + `online` counts sum to `all`;
- `online` count equals the seeded online rows.

### Task 10: Shared type control

**Files:** `script/revised.js`, `style/reports.css`
- **Produces:** `ornAttendanceTypeControl(container, {value:'all', onChange(fn)})` — segmented `In-Person | Online | All`, value in the URL query (`&AttendanceType=`), ≥44px targets, light + dark, fits 390px.
- [ ] Build, browser-check on a scratch report page in both themes, commit.

### Task 11: Attendance report — In-Person / Online / Total columns

**Files:** `class.Report.php` (`AttendanceSummary` :1847, `GetDistinctPlayerStats` :2843, `GetMonthlyChartData` :2902), `controller.Reports.php` (~:405–415), `Reports_attendance.tpl`
- **Produces:** each row/period gains `InPerson`, `Online`, `Total` (Total = the existing figure). Chart becomes stacked In-Person + Online.
- [ ] Tests (parity pattern on the three methods) → implement → template columns + stacked chart → browser check → commit.

### Task 12: Toggle on explorer-style reports

**Files:** `class.Report.php` (`ParkAttendanceAllParks` :3354, `ParkAttendanceSinglePark` :3738, `GetNewPlayerAttendance` :3484, `GetNewPlayerAttendanceByKingdom` :3658, `GetTopParksByAttendance` :2682, `GetActiveKingdomsSummary` :2790), `model.Reports.php` (:388, :397), `controller.Reports.php` (:955 and the new-player action), `controller.Admin.php` (:1977), templates `Reports_parkattendanceexplorer.tpl`, `Reports_newplayerattendance.tpl`, `Admin_newplayerattendance.tpl`, the admin top-parks template
- [ ] Tests per method → implement → mount Task 10 control (default All) → browser check → commit.

### Task 13: Park and kingdom attendance averages

**Files:** `class.Report.php` (`GetKingdomParkAverages` :2426, `GetKingdomParkMonthlyAverages` :2503, `GetKingdomExtendedParkAverages` :2555), `class.ParkProfile.php` (`GetParkAttendanceAverages` :214), `controller.Kingdom.php` (:73), `controller.Park.php` (:222), `Kingdomnew_index.tpl`, `Parknew_index.tpl`
- Cache keys for these methods must include the type (`GetKingdomParkAverages` caches per request — include `$type` in the key).
- Default All; these are informational, never tied to park status (spec non-goal).
- [ ] Tests → implement → control on the kingdom Parks tab and park profile stats → browser check → commit.

### Task 14: State of Amtgard page toggle

**Files:** `class.StateOfAmtgard.php` (`getKingdomSignIns` :15, `getClassSignIns` :202, `getParksAnalysis` :329, `getPlayerStats` :631, `getPlayerCohorts` :982, `getPlayerLongevity` :1118, `getNewPlayerRetention` :1396, `GetPageBootstrap` :1562, dispatch :1588), `controller.AdminAjax.php` (:101–117), `StateOfAmtgard_index.tpl`
- One page-level control; every AJAX call carries `AttendanceType`; any server cache key includes it.
- [ ] Tests on `getKingdomSignIns` and `getPlayerStats` (parity pattern) → implement all listed methods → browser check → commit.

### Task 15: Population Explorer "Attendance type" filter

**Files:** `class.PopulationExplorer.php` (`_attSub` :604, `_lastSigninExpr` :614, `_signinsInMonthsExpr` :644, `_lastClassExpr` :654, `_lastSigninParkExpr` :664), `controller.Reports.php` (:1341, JSON :1439, CSV :1479), `Reports_populationexplorer.tpl`
- The filter applies to every sign-in-derived expression and to CSV output. Validate through `NormalizeType` only (memory: the explorer compiles filters to scoped SQL — do not bypass its validation path).
- [ ] Tests via `PopulationExplorerProbe` → implement → browser check → commit.

### Task 16: Feature Utilization line, field-today in-person, PR

**Files:** `class.Report.php` (`ReleaseFeatureUtilization` :6132, near :7133–7151; `GetParkFieldCountToday` :8372), `Reports_release_utilization.tpl`, `Parknew_index.tpl` (field-today label)
- Feature Utilization adds `Online sign-ins (30 days)` beside the sign-in-link and self-reg figures.
- `GetParkFieldCountToday` always uses `TypeClause('in_person')`.
- [ ] Tests (field count excludes the seeded online row; utilization online figure equals seeded rows) → implement → commit.
- [ ] Run full unit suite + `--filter OnlineAttendanceReports`; `layers-review` skill; push `feature/online-attendance-reports`; open PR `Enhancement: Online attendance in reports` against `amtgard/ORK3`, noting it stacks on PR 1.
