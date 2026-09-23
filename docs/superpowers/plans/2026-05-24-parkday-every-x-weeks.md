# Park Day "Every X Weeks" Recurrence — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an interval-based "Every X weeks" (biweekly / every 3 / every 4 weeks) park-day recurrence mode, anchored to a required start date, rendered correctly everywhere park-day recurrence is expanded (park calendar, kingdom calendar, legacy calendar, weather matcher).

**Architecture:** Additive only — the existing `weekly` / `week-of-month` / `monthly` modes are untouched. A new `every-x-weeks` enum value plus `start_date` and `week_interval` columns on `ork_parkday`. A single shared PHP helper (`Park::ExpandEveryXWeeks`) computes occurrences from the anchor, called by every expansion site so the stepping rule lives in one place. On save the backend derives `week_day` from `start_date` so existing weekday-aware code keeps a valid value.

**Tech Stack:** PHP 8 (legacy MVC + `yapo` ORM), MariaDB (Docker container `ork3-php8-db`), vanilla JS + jQuery (`revised.js`), Smarty-style `.tpl` templates, FullCalendar.

**Verification note:** This codebase has **no automated unit-test harness**. Per-task verification is therefore (a) `php -l` syntax lint for PHP, (b) applying migrations against the Docker DB, and (c) manual checks in the running app at `http://localhost:19080/orkui/`. Steps below reflect that reality rather than inventing a test framework.

**MEMORY rules in force for this plan:**
- Edit PHP/`.tpl`/`.js` multi-line blocks via Python (`pathlib` replace), not the Edit tool (tabs vs spaces). Single-line unambiguous edits may use Edit.
- Stage files explicitly — never `git add -A`/`.`. Never stage `class.Authorization.php` or `CLAUDE.md`.
- Dark-mode compatibility is required for all new front-end surfaces.
- Human-readable date display — never leave a raw ISO date visible.
- `$DB->Clear()` before raw `Execute`/`DataSet`.

---

### Task 1: Database migrations

**Files:**
- Create: `db-migrations/2026-05-24-add-parkday-every-x-weeks-enum.sql`
- Create: `db-migrations/2026-05-24-add-parkday-start-date-interval.sql`

- [ ] **Step 1: Write the enum migration**

Create `db-migrations/2026-05-24-add-parkday-every-x-weeks-enum.sql`:

```sql
-- Add 'every-x-weeks' interval recurrence mode to park days.
ALTER TABLE `ork_parkday`
  MODIFY `recurrence`
  enum('weekly','monthly','week-of-month','every-x-weeks')
  NOT NULL DEFAULT 'weekly';
```

- [ ] **Step 2: Write the columns migration**

Create `db-migrations/2026-05-24-add-parkday-start-date-interval.sql`:

```sql
-- Anchor date + week interval for the 'every-x-weeks' recurrence mode.
-- start_date is the first occurrence; week_interval is 2, 3, or 4.
-- '1000-01-01' is MariaDB's minimum valid DATE, used as a NOT-NULL sentinel
-- for rows that don't use this mode.
ALTER TABLE `ork_parkday`
  ADD COLUMN `start_date` DATE NOT NULL DEFAULT '1000-01-01',
  ADD COLUMN `week_interval` INT NOT NULL DEFAULT 0;
```

- [ ] **Step 3: Apply both migrations to the Docker DB**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-05-24-add-parkday-every-x-weeks-enum.sql
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-05-24-add-parkday-start-date-interval.sql
```
Expected: no output (success). 

- [ ] **Step 4: Verify the schema changed**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SHOW COLUMNS FROM ork_parkday LIKE 'start_date'; SHOW COLUMNS FROM ork_parkday LIKE 'week_interval'; SHOW COLUMNS FROM ork_parkday LIKE 'recurrence';"
```
Expected: `start_date` (date), `week_interval` (int), and `recurrence` enum now listing `every-x-weeks`.

- [ ] **Step 5: Commit**

```bash
git add db-migrations/2026-05-24-add-parkday-every-x-weeks-enum.sql db-migrations/2026-05-24-add-parkday-start-date-interval.sql
git commit -m "Enhancement: DB migrations for park-day every-x-weeks recurrence

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Shared occurrence helper + legacy next-occurrence

**Files:**
- Modify: `system/lib/ork3/class.Park.php` (add `ExpandEveryXWeeks`; extend `CalculateNextParkDay` ~lines 521-544)

`yapo` auto-maps columns, so no model field declarations are needed for the new columns.

- [ ] **Step 1: Add the shared expansion helper**

Insert a new static method into `class.Park.php` immediately **before** `public static function CalculateNextParkDay(`. Use Python to edit:

```python
import pathlib
p = pathlib.Path('system/lib/ork3/class.Park.php')
t = p.read_text()
needle = "\tpublic static function CalculateNextParkDay("
assert needle in t, "anchor not found"
helper = '''\tpublic static function ExpandEveryXWeeks( $start_date, $week_interval, DateTime $range_start, DateTime $range_end )
\t{
\t\t// Returns Y-m-d occurrences in [range_start, range_end) on the
\t\t// "every X weeks" cadence anchored at $start_date. Half-open interval:
\t\t// includes range_start, excludes range_end.
\t\t$out = [];
\t\t$interval = (int)$week_interval;
\t\tif ( $interval < 1 ) return $out;
\t\t$step   = $interval * 7;
\t\t$anchor = DateTime::createFromFormat( 'Y-m-d', substr( (string)$start_date, 0, 10 ) );
\t\tif ( !$anchor ) return $out;
\t\t$anchor->setTime( 0, 0, 0 );
\t\t$rs = clone $range_start; $rs->setTime( 0, 0, 0 );
\t\t$re = clone $range_end;   $re->setTime( 0, 0, 0 );
\t\t$cur = clone $anchor;
\t\tif ( $cur < $rs ) {
\t\t\t$daysBehind  = (int)$cur->diff( $rs )->days;
\t\t\t$stepsToSkip = intdiv( $daysBehind, $step ) * $step;
\t\t\tif ( $stepsToSkip > 0 ) $cur->modify( "+{$stepsToSkip} days" );
\t\t\twhile ( $cur < $rs ) $cur->modify( "+{$step} days" );
\t\t}
\t\twhile ( $cur < $re ) {
\t\t\t$out[] = $cur->format( 'Y-m-d' );
\t\t\t$cur->modify( "+{$step} days" );
\t\t}
\t\treturn $out;
\t}

'''
t = t.replace(needle, helper + needle, 1)
p.write_text(t)
print("done")
```

- [ ] **Step 2: Extend `CalculateNextParkDay` signature + add the new case**

The current signature is `CalculateNextParkDay( $recurrence, $week_of_month, $month_day, $week_day, $from_date = null )`. Add two trailing optional params and a new `case`. Use Python:

```python
import pathlib
p = pathlib.Path('system/lib/ork3/class.Park.php')
t = p.read_text()

old_sig = "public static function CalculateNextParkDay( $recurrence, $week_of_month, $month_day, $week_day, $from_date = null )"
new_sig = "public static function CalculateNextParkDay( $recurrence, $week_of_month, $month_day, $week_day, $from_date = null, $start_date = null, $week_interval = 0 )"
assert old_sig in t
t = t.replace(old_sig, new_sig, 1)

# Add the new case after the monthly case's return.
old_case = '''\t\t\tcase 'monthly':
\t\t\t\treturn date( "Y-m-d", strtotime( date( "F $month_day, Y", $from_date ), $from_date ) );
\t\t}'''
new_case = '''\t\t\tcase 'monthly':
\t\t\t\treturn date( "Y-m-d", strtotime( date( "F $month_day, Y", $from_date ), $from_date ) );
\t\t\tcase 'every-x-weeks':
\t\t\t\t$interval = max( 1, (int)$week_interval );
\t\t\t\t$step     = $interval * 7;
\t\t\t\t$anchor   = strtotime( substr( (string)$start_date, 0, 10 ) );
\t\t\t\tif ( $anchor === false ) return date( "Y-m-d", $from_date );
\t\t\t\tif ( $anchor >= $from_date ) return date( "Y-m-d", $anchor );
\t\t\t\t$daysBehind = floor( ( $from_date - $anchor ) / 86400 );
\t\t\t\t$cycles     = (int)ceil( ( $daysBehind + 1 ) / $step );
\t\t\t\treturn date( "Y-m-d", strtotime( "+" . ( $cycles * $step ) . " days", $anchor ) );
\t\t}'''
assert old_case in t
t = t.replace(old_case, new_case, 1)
p.write_text(t)
print("done")
```

- [ ] **Step 3: Lint**

Run: `docker exec ork3-php8 php -l system/lib/ork3/class.Park.php`
Expected: `No syntax errors detected`. (If the container name differs, run `php -l system/lib/ork3/class.Park.php` on the host.)

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.Park.php
git commit -m "Enhancement: ExpandEveryXWeeks helper + CalculateNextParkDay interval case

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Save path — AddParkDay / EditParkDay / GetParkDays

**Files:**
- Modify: `system/lib/ork3/class.Park.php` (`AddParkDay` ~147, `EditParkDay` ~224, `GetParkDays` ~546)

Backend persists `start_date` + `week_interval`, and when the mode is `every-x-weeks` derives and stores `week_day` from `start_date`.

- [ ] **Step 1: Add field handling to `AddParkDay`**

In `AddParkDay`, the block sets `$this->parkday->month_day = $request[ 'MonthDay' ];` then `time`. Insert the new fields right after the `month_day` assignment. Use Python:

```python
import pathlib
p = pathlib.Path('system/lib/ork3/class.Park.php')
t = p.read_text()

# AddParkDay: the month_day line appears first in the file (Add before Edit).
add_old = '''\t\t\t$this->parkday->month_day = $request[ 'MonthDay' ];
\t\t\t$this->parkday->time = $request[ 'Time' ];
\t\t\t$this->parkday->purpose = $request[ 'Purpose' ];
\t\t\t$this->parkday->description = $request[ 'Description' ];
\t\t\t$this->parkday->alternate_location = $request[ 'AlternateLocation' ];
\t\t\t$this->parkday->online = (int)( $request[ 'Online' ] ?? 0 );

\t\t\tif ( !empty( $request[ 'Online' ] ) ) {
\t\t\t\tlogtrace( 'AddParkDay.Online', null );'''
add_new = '''\t\t\t$this->parkday->month_day = $request[ 'MonthDay' ];
\t\t\t$this->parkday->start_date = ( !empty( $request[ 'StartDate' ] ) ) ? substr( $request[ 'StartDate' ], 0, 10 ) : '1000-01-01';
\t\t\t$this->parkday->week_interval = (int)( $request[ 'WeekInterval' ] ?? 0 );
\t\t\tif ( $request[ 'Recurrence' ] === 'every-x-weeks' && !empty( $request[ 'StartDate' ] ) ) {
\t\t\t\t$this->parkday->week_day = date( 'l', strtotime( substr( $request[ 'StartDate' ], 0, 10 ) ) );
\t\t\t}
\t\t\t$this->parkday->time = $request[ 'Time' ];
\t\t\t$this->parkday->purpose = $request[ 'Purpose' ];
\t\t\t$this->parkday->description = $request[ 'Description' ];
\t\t\t$this->parkday->alternate_location = $request[ 'AlternateLocation' ];
\t\t\t$this->parkday->online = (int)( $request[ 'Online' ] ?? 0 );

\t\t\tif ( !empty( $request[ 'Online' ] ) ) {
\t\t\t\tlogtrace( 'AddParkDay.Online', null );'''
assert add_old in t, "AddParkDay anchor not found"
t = t.replace(add_old, add_new, 1)
p.write_text(t)
print("AddParkDay done")
```

- [ ] **Step 2: Add field handling to `EditParkDay`**

`EditParkDay` has the same assignment lines but is followed by `if ( !empty( $request[ 'Online' ] ) ) {` **without** the `logtrace`. Replace that distinct variant:

```python
import pathlib
p = pathlib.Path('system/lib/ork3/class.Park.php')
t = p.read_text()

edit_old = '''\t\t\t$this->parkday->month_day = $request[ 'MonthDay' ];
\t\t\t$this->parkday->time = $request[ 'Time' ];
\t\t\t$this->parkday->purpose = $request[ 'Purpose' ];
\t\t\t$this->parkday->description = $request[ 'Description' ];
\t\t\t$this->parkday->alternate_location = $request[ 'AlternateLocation' ];
\t\t\t$this->parkday->online = (int)( $request[ 'Online' ] ?? 0 );

\t\t\tif ( !empty( $request[ 'Online' ] ) ) {
\t\t\t\t$this->parkday->address = \'\';'''
edit_new = '''\t\t\t$this->parkday->month_day = $request[ 'MonthDay' ];
\t\t\t$this->parkday->start_date = ( !empty( $request[ 'StartDate' ] ) ) ? substr( $request[ 'StartDate' ], 0, 10 ) : '1000-01-01';
\t\t\t$this->parkday->week_interval = (int)( $request[ 'WeekInterval' ] ?? 0 );
\t\t\tif ( $request[ 'Recurrence' ] === 'every-x-weeks' && !empty( $request[ 'StartDate' ] ) ) {
\t\t\t\t$this->parkday->week_day = date( 'l', strtotime( substr( $request[ 'StartDate' ], 0, 10 ) ) );
\t\t\t}
\t\t\t$this->parkday->time = $request[ 'Time' ];
\t\t\t$this->parkday->purpose = $request[ 'Purpose' ];
\t\t\t$this->parkday->description = $request[ 'Description' ];
\t\t\t$this->parkday->alternate_location = $request[ 'AlternateLocation' ];
\t\t\t$this->parkday->online = (int)( $request[ 'Online' ] ?? 0 );

\t\t\tif ( !empty( $request[ 'Online' ] ) ) {
\t\t\t\t$this->parkday->address = \'\';'''
assert edit_old in t, "EditParkDay anchor not found"
t = t.replace(edit_old, edit_new, 1)
p.write_text(t)
print("EditParkDay done")
```

- [ ] **Step 3: Return the new fields from `GetParkDays`**

Add `StartDate` and `WeekInterval` to the returned row. Use Python:

```python
import pathlib
p = pathlib.Path('system/lib/ork3/class.Park.php')
t = p.read_text()
old = "\t\t\t\t\t'MonthDay'          => $parkday->month_day,\n\t\t\t\t\t'Time'              => $parkday->time,"
new = "\t\t\t\t\t'MonthDay'          => $parkday->month_day,\n\t\t\t\t\t'StartDate'         => $parkday->start_date,\n\t\t\t\t\t'WeekInterval'      => (int)$parkday->week_interval,\n\t\t\t\t\t'Time'              => $parkday->time,"
assert old in t, "GetParkDays anchor not found"
t = t.replace(old, new, 1)
p.write_text(t)
print("done")
```

- [ ] **Step 4: Lint**

Run: `docker exec ork3-php8 php -l system/lib/ork3/class.Park.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.Park.php
git commit -m "Enhancement: persist start_date/week_interval; derive week_day for every-x-weeks

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Parknew template — modal fields, label, card data, 90-day pre-render

**Files:**
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl` (modal ~2426-2434, label ~682-687, card ~710-724, pre-render ~163-203)

- [ ] **Step 1: Add the "Every X weeks" recurrence option + its fields to the modal**

Replace the recurrence segmented group and add interval + start-date rows after it. Use Python:

```python
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Parknew_index.tpl')
t = p.read_text()

old = '''\t\t\t\t<div class="pk-seg-group">
\t\t\t\t\t\t<button type="button" class="pk-seg-btn pk-seg-active" data-group="recurrence" data-val="weekly">Weekly</button>
\t\t\t\t\t\t<button type="button" class="pk-seg-btn" data-group="recurrence" data-val="week-of-month">Week of Month</button>
\t\t\t\t\t\t<button type="button" class="pk-seg-btn" data-group="recurrence" data-val="monthly">Monthly</button>
\t\t\t\t\t</div>
\t\t\t\t\t<input type="hidden" id="pk-addday-recurrence" value="weekly" />
\t\t\t\t</div>'''
new = '''\t\t\t\t<div class="pk-seg-group">
\t\t\t\t\t\t<button type="button" class="pk-seg-btn pk-seg-active" data-group="recurrence" data-val="weekly">Weekly</button>
\t\t\t\t\t\t<button type="button" class="pk-seg-btn" data-group="recurrence" data-val="every-x-weeks">Every X Weeks</button>
\t\t\t\t\t\t<button type="button" class="pk-seg-btn" data-group="recurrence" data-val="week-of-month">Week of Month</button>
\t\t\t\t\t\t<button type="button" class="pk-seg-btn" data-group="recurrence" data-val="monthly">Monthly</button>
\t\t\t\t\t</div>
\t\t\t\t\t<input type="hidden" id="pk-addday-recurrence" value="weekly" />
\t\t\t\t</div>

\t\t\t\t<div class="pk-addday-field" id="pk-addday-interval-row" style="display:none">
\t\t\t\t\t<label for="pk-addday-interval">Interval</label>
\t\t\t\t\t<select id="pk-addday-interval">
\t\t\t\t\t\t<option value="2">Every 2 weeks (biweekly)</option>
\t\t\t\t\t\t<option value="3">Every 3 weeks</option>
\t\t\t\t\t\t<option value="4">Every 4 weeks</option>
\t\t\t\t\t</select>
\t\t\t\t</div>

\t\t\t\t<div class="pk-addday-field" id="pk-addday-startdate-row" style="display:none">
\t\t\t\t\t<label for="pk-addday-startdate">Start Date <span style="color:#e53e3e">*</span> <span style="color:#a0aec0;font-weight:400;font-size:11px">(first occurrence — sets the cadence)</span></label>
\t\t\t\t\t<input type="date" id="pk-addday-startdate" />
\t\t\t\t</div>'''
assert old in t, "modal recurrence anchor not found"
t = t.replace(old, new, 1)
p.write_text(t)
print("done")
```

Note on the date input: a native `<input type=date>` shows a localized (human-readable) value, satisfying the no-raw-ISO rule while keeping the submitted value as `YYYY-MM-DD`. It is styled in Step 5 for dark mode.

- [ ] **Step 2: Add the human-readable label case**

Add an `every-x-weeks` case to the recurrence-text switch (~line 682). Use Python:

```python
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Parknew_index.tpl')
t = p.read_text()
old = '''\t\t\t\t\tcase 'week-of-month': $recText = 'Every ' . pk_ordinal($day['WeekOfMonth']) . ' ' . $day['WeekDay']; break;
\t\t\t\t\t\t\t\tcase 'monthly':       $recText = 'Monthly on the ' . pk_ordinal($day['MonthDay']); break;'''
new = '''\t\t\t\t\tcase 'week-of-month': $recText = 'Every ' . pk_ordinal($day['WeekOfMonth']) . ' ' . $day['WeekDay']; break;
\t\t\t\t\t\t\t\tcase 'every-x-weeks':
\t\t\t\t\t\t\t\t\t$_wi = (int)($day['WeekInterval'] ?? 0);
\t\t\t\t\t\t\t\t\t$recText = ($_wi === 2)
\t\t\t\t\t\t\t\t\t\t? 'Every other ' . $day['WeekDay']
\t\t\t\t\t\t\t\t\t\t: 'Every ' . $_wi . ' weeks on ' . $day['WeekDay'] . 's';
\t\t\t\t\t\t\t\t\tbreak;
\t\t\t\t\t\t\t\tcase 'monthly':       $recText = 'Monthly on the ' . pk_ordinal($day['MonthDay']); break;'''
assert old in t, "label switch anchor not found"
t = t.replace(old, new, 1)
p.write_text(t)
print("done")
```

- [ ] **Step 3: Emit new card data attributes**

Add `data-startdate` and `data-interval` to the schedule card. Use Python:

```python
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Parknew_index.tpl')
t = p.read_text()
old = '''\t\t\t\t\tdata-monthday="<?= (int)($day['MonthDay'] ?? 0) ?>"
\t\t\t\t\tdata-time="<?= htmlspecialchars($day['Time'] ?? '') ?>"'''
new = '''\t\t\t\t\tdata-monthday="<?= (int)($day['MonthDay'] ?? 0) ?>"
\t\t\t\t\tdata-startdate="<?= htmlspecialchars($day['StartDate'] ?? '') ?>"
\t\t\t\t\tdata-interval="<?= (int)($day['WeekInterval'] ?? 0) ?>"
\t\t\t\t\tdata-time="<?= htmlspecialchars($day['Time'] ?? '') ?>"'''
assert old in t, "card data-attr anchor not found"
t = t.replace(old, new, 1)
p.write_text(t)
print("done")
```

- [ ] **Step 4: Add the `every-x-weeks` branch to the 90-day pre-render**

Add a new `case` before the `monthly` case in the pre-render switch (~line 192). Use Python:

```python
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Parknew_index.tpl')
t = p.read_text()
old = '''\t\t\t\tbreak;
\t\t\tcase 'monthly':
\t\t\t\t$_pdMd = (int)$_pd['MonthDay'];'''
new = '''\t\t\t\tbreak;
\t\t\tcase 'every-x-weeks':
\t\t\t\t$_pdEnd1 = (clone $_pd_end)->modify('+1 day'); // helper end is exclusive; keep day 90 inclusive
\t\t\t\t$_pdOccs = Park::ExpandEveryXWeeks($_pd['StartDate'] ?? '', (int)($_pd['WeekInterval'] ?? 0), $_pd_today, $_pdEnd1);
\t\t\t\tbreak;
\t\t\tcase 'monthly':
\t\t\t\t$_pdMd = (int)$_pd['MonthDay'];'''
assert old in t, "pre-render monthly anchor not found"
t = t.replace(old, new, 1)
p.write_text(t)
print("done")
```

Confirm `Park` is referenceable in this template scope. Run:
```bash
grep -n "Park::" orkui/template/revised-frontend/Parknew_index.tpl | head
```
Expected: at least the new line. If `Park` is not already imported/used in templates, fall back to an inline copy of the helper's loop here (anchor at `$_pd['StartDate']`, step `interval*7` days, push dates `>= $_pd_today && <= $_pd_end`) — the algorithm must match `ExpandEveryXWeeks` exactly. (Other ORK templates call `Ork3::$Lib->...`; verify whether `Park` resolves before relying on the static call.)

- [ ] **Step 5: Dark-mode styling for the date input**

The native date picker needs `color-scheme` so its calendar widget and text invert in dark mode. Find the existing `<style>` block in this template (search for `.pk-addday-field`) and add a rule. Use Python to append inside the style block — locate a stable existing selector:

```python
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Parknew_index.tpl')
t = p.read_text()
anchor = "#pk-addday-overlay"  # confirm this selector exists in the template's CSS
assert anchor in t, "expected pk-addday CSS in template"
# Append a dark-mode color-scheme rule near the modal styles.
addition = '''
#pk-addday-startdate, #pk-addday-interval { width:100%; }
body.dark-mode #pk-addday-startdate { color-scheme: dark; }
'''
# Insert right before the FIRST occurrence of the anchor's CSS rule open brace context is risky;
# instead append to the template's main <style> close. Find the last "</style>" and inject before it.
idx = t.rfind("</style>")
assert idx != -1, "no </style> found"
t = t[:idx] + addition + t[idx:]
p.write_text(t)
print("done")
```

Verify the dark-mode body class name actually used by the app (it may be `dark`, `dark-mode`, or a `data-theme` attr). Run:
```bash
grep -rn "dark-mode\|data-theme\|body.dark" orkui/template/revised-frontend/Parknew_index.tpl | head
```
Adjust the selector in the addition to match what the codebase uses before committing.

- [ ] **Step 6: Lint the template's PHP**

Run: `docker exec ork3-php8 php -l orkui/template/revised-frontend/Parknew_index.tpl`
Expected: `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add orkui/template/revised-frontend/Parknew_index.tpl
git commit -m "Enhancement: Parknew every-x-weeks modal fields, label, card data, calendar pre-render

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: revised.js — field toggle, edit population, save payload + validation

**Files:**
- Modify: `orkui/template/revised-frontend/script/revised.js` (`pkUpdateRecurrenceFields` ~11525, `pkOpenAddDayModal` ~11549, `pkOpenEditDayModal` ~11635, save handler ~11708-11728)

- [ ] **Step 1: Toggle interval + start-date fields in `pkUpdateRecurrenceFields`**

Use Python:

```python
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/script/revised.js')
t = p.read_text()
old = '''    function pkUpdateRecurrenceFields(recurrence) {
        var weekdayRow  = gid('pk-addday-weekday-row');
        var weekofRow   = gid('pk-addday-weekof-row');
        var monthdayRow = gid('pk-addday-monthday-row');
        if (weekdayRow)  weekdayRow.style.display  = (recurrence === 'weekly' || recurrence === 'week-of-month') ? '' : 'none';
        if (weekofRow)   weekofRow.style.display   = (recurrence === 'week-of-month') ? '' : 'none';
        if (monthdayRow) monthdayRow.style.display = (recurrence === 'monthly') ? '' : 'none';
    }'''
new = '''    function pkUpdateRecurrenceFields(recurrence) {
        var weekdayRow   = gid('pk-addday-weekday-row');
        var weekofRow    = gid('pk-addday-weekof-row');
        var monthdayRow  = gid('pk-addday-monthday-row');
        var intervalRow  = gid('pk-addday-interval-row');
        var startdateRow = gid('pk-addday-startdate-row');
        // For every-x-weeks the weekday is derived from the start date, so the
        // weekday dropdown is hidden in that mode.
        if (weekdayRow)   weekdayRow.style.display   = (recurrence === 'weekly' || recurrence === 'week-of-month') ? '' : 'none';
        if (weekofRow)    weekofRow.style.display    = (recurrence === 'week-of-month') ? '' : 'none';
        if (monthdayRow)  monthdayRow.style.display  = (recurrence === 'monthly') ? '' : 'none';
        if (intervalRow)  intervalRow.style.display  = (recurrence === 'every-x-weeks') ? '' : 'none';
        if (startdateRow) startdateRow.style.display = (recurrence === 'every-x-weeks') ? '' : 'none';
    }'''
assert old in t, "pkUpdateRecurrenceFields anchor not found"
t = t.replace(old, new, 1)
p.write_text(t)
print("done")
```

- [ ] **Step 2: Reset the new fields when opening the Add modal**

In `pkOpenAddDayModal`, after the `descEl` reset, clear interval/startdate. Use Python:

```python
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/script/revised.js')
t = p.read_text()
old = '''        var descEl = gid('pk-addday-desc');
        if (descEl) descEl.value = '';
        overlay.querySelectorAll('.pk-seg-btn[data-group="purpose"]').forEach(function(btn) {'''
new = '''        var descEl = gid('pk-addday-desc');
        if (descEl) descEl.value = '';
        var intervalEl = gid('pk-addday-interval');
        if (intervalEl) intervalEl.value = '2';
        var startdateEl = gid('pk-addday-startdate');
        if (startdateEl) startdateEl.value = '';
        overlay.querySelectorAll('.pk-seg-btn[data-group="purpose"]').forEach(function(btn) {'''
assert old in t, "pkOpenAddDayModal anchor not found"
t = t.replace(old, new, 1)
p.write_text(t)
print("done")
```

- [ ] **Step 3: Populate the new fields when opening the Edit modal**

In `pkOpenEditDayModal`, after the `monthdayEl` population, set interval/startdate from card data. Use Python:

```python
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/script/revised.js')
t = p.read_text()
old = '''        var monthdayEl = gid('pk-addday-monthday');
        if (monthdayEl) monthdayEl.value = card.dataset.monthday || '1';

        // Time'''
new = '''        var monthdayEl = gid('pk-addday-monthday');
        if (monthdayEl) monthdayEl.value = card.dataset.monthday || '1';

        // Interval + start date (every-x-weeks)
        var intervalEl = gid('pk-addday-interval');
        if (intervalEl) intervalEl.value = card.dataset.interval && parseInt(card.dataset.interval, 10) >= 2 ? card.dataset.interval : '2';
        var startdateEl = gid('pk-addday-startdate');
        if (startdateEl) {
            var sd = card.dataset.startdate || '';
            // Guard against the '1000-01-01' NOT-NULL sentinel from non-interval rows.
            startdateEl.value = (sd && sd.indexOf('1000-01-01') === -1) ? sd.substring(0, 10) : '';
        }

        // Time'''
assert old in t, "pkOpenEditDayModal anchor not found"
t = t.replace(old, new, 1)
p.write_text(t)
print("done")
```

- [ ] **Step 4: Validate + send the new fields in the save handler**

Add validation for `every-x-weeks` and append `StartDate`/`WeekInterval` to the FormData. Use Python (two replacements):

```python
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/script/revised.js')
t = p.read_text()

# 4a: validation — after the time-required check.
old_val = '''                if (!time)       { if (fb) { fb.textContent = 'Time is required.';       fb.style.display = ''; fb.className = 'pk-addday-err'; } return; }
                saveBtn.disabled = true;'''
new_val = '''                if (!time)       { if (fb) { fb.textContent = 'Time is required.';       fb.style.display = ''; fb.className = 'pk-addday-err'; } return; }
                var startDate = gid('pk-addday-startdate') ? gid('pk-addday-startdate').value.trim() : '';
                if (recurrence === 'every-x-weeks' && !startDate) {
                    if (fb) { fb.textContent = 'A start date is required for the "every X weeks" cadence.'; fb.style.display = ''; fb.className = 'pk-addday-err'; }
                    return;
                }
                saveBtn.disabled = true;'''
assert old_val in t, "save validation anchor not found"
t = t.replace(old_val, new_val, 1)

# 4b: payload — after the MonthDay append.
old_fd = '''                fd.append('MonthDay',          gid('pk-addday-monthday') ? gid('pk-addday-monthday').value : 0);
                var locType = pkGetAddDayLocType();'''
new_fd = '''                fd.append('MonthDay',          gid('pk-addday-monthday') ? gid('pk-addday-monthday').value : 0);
                fd.append('StartDate',         startDate);
                fd.append('WeekInterval',      gid('pk-addday-interval') ? gid('pk-addday-interval').value : 0);
                var locType = pkGetAddDayLocType();'''
assert old_fd in t, "save FormData anchor not found"
t = t.replace(old_fd, new_fd, 1)
p.write_text(t)
print("done")
```

- [ ] **Step 5: Syntax-check the JS**

Run: `node --check orkui/template/revised-frontend/script/revised.js`
Expected: no output (valid). If `node` is unavailable, load the park page and confirm no console `SyntaxError`.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/revised-frontend/script/revised.js
git commit -m "Enhancement: park-day modal JS for every-x-weeks interval + start date

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Kingdom calendar AJAX expansion

**Files:**
- Modify: `orkui/controller/controller.KingdomAjax.php` (SQL ~793, expansion ~846-860)

- [ ] **Step 1: Add the new columns to the SQL select**

Use Python:

```python
import pathlib
p = pathlib.Path('orkui/controller/controller.KingdomAjax.php')
t = p.read_text()
old = '''\t\t\tSELECT pd.park_id, pd.recurrence, pd.week_day, pd.week_of_month,
\t\t\t       pd.month_day, pd.time, pd.purpose, p.abbreviation AS park_abbr'''
new = '''\t\t\tSELECT pd.park_id, pd.recurrence, pd.week_day, pd.week_of_month,
\t\t\t       pd.month_day, pd.start_date, pd.week_interval, pd.time, pd.purpose, p.abbreviation AS park_abbr'''
assert old in t, "KingdomAjax SQL anchor not found"
t = t.replace(old, new, 1)
p.write_text(t)
print("done")
```

- [ ] **Step 2: Add the `every-x-weeks` expansion branch**

Insert a new `elseif` after the `monthly` branch closes (before the `}` that ends the `while ($pdResult->Next())` body). Use Python — anchor on the end of the monthly branch:

```python
import pathlib
p = pathlib.Path('orkui/controller/controller.KingdomAjax.php')
t = p.read_text()
old = '''\t\t\t\t\t\t$curMonth->modify('first day of next month');
\t\t\t\t\t}
\t\t\t\t}
\t\t\t}
\t\t}

\t\techo json_encode(['status' => 0, 'events' => $events]);'''
new = '''\t\t\t\t\t\t$curMonth->modify('first day of next month');
\t\t\t\t\t}
\t\t\t\t} elseif ($rec === 'every-x-weeks') {
\t\t\t\t\t$occs = Park::ExpandEveryXWeeks($pdResult->start_date, (int)$pdResult->week_interval, $rangeStart, $rangeEnd);
\t\t\t\t\tforeach ($occs as $occ) {
\t\t\t\t\t\t$events[] = ['title'=>$title,'start'=>$occ.$timeStr,'url'=>$url,'color'=>'#b7791f','type'=>'park-day'];
\t\t\t\t\t}
\t\t\t\t}
\t\t\t}
\t\t}

\t\techo json_encode(['status' => 0, 'events' => $events]);'''
assert old in t, "KingdomAjax expansion-end anchor not found"
t = t.replace(old, new, 1)
p.write_text(t)
print("done")
```

Confirm `Park` is available in this controller (it uses `class.Park.php` indirectly). Run:
```bash
grep -n "Park::\|class.Park\|require.*Park\|use .*Park" orkui/controller/controller.KingdomAjax.php | head
```
If `Park` is not loaded here, add `require_once` for `system/lib/ork3/class.Park.php` near the top, matching how other `system/lib/ork3` classes are included in this controller. (Check an existing `require_once`/autoload pattern first.)

- [ ] **Step 3: Lint**

Run: `docker exec ork3-php8 php -l orkui/controller/controller.KingdomAjax.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add orkui/controller/controller.KingdomAjax.php
git commit -m "Enhancement: kingdom calendar expands every-x-weeks park days

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Kingdom controller text label

**Files:**
- Modify: `orkui/controller/controller.Kingdom.php` (recurrence switch ~640-648, SQL ~628, row array ~656-668)

- [ ] **Step 1: Add `start_date`/`week_interval` to the SQL and an `every-x-weeks` label case**

Use Python (two replacements):

```python
import pathlib
p = pathlib.Path('orkui/controller/controller.Kingdom.php')
t = p.read_text()

# 1a: SQL columns
old_sql = '''\t\t\tSELECT pd.parkday_id, pd.park_id, pd.recurrence, pd.week_day,
\t\t\t       pd.week_of_month, pd.month_day, pd.time, pd.purpose, p.name AS park_name, p.abbreviation AS park_abbr'''
new_sql = '''\t\t\tSELECT pd.parkday_id, pd.park_id, pd.recurrence, pd.week_day,
\t\t\t       pd.week_of_month, pd.month_day, pd.start_date, pd.week_interval, pd.time, pd.purpose, p.name AS park_name, p.abbreviation AS park_abbr'''
assert old_sql in t, "Kingdom SQL anchor not found"
t = t.replace(old_sql, new_sql, 1)

# 1b: label case (insert before the monthly case)
old_lbl = '''\t\t\t\t\tcase 'monthly':      $recText = 'Monthly, day ' . (int)$pdResult->month_day; break;'''
new_lbl = '''\t\t\t\t\tcase 'every-x-weeks':
\t\t\t\t\t\t$wi = (int)$pdResult->week_interval;
\t\t\t\t\t\t$recText = ($wi === 2) ? 'Every other ' . $pdResult->week_day : 'Every ' . $wi . ' weeks on ' . $pdResult->week_day . 's';
\t\t\t\t\t\tbreak;
\t\t\t\t\tcase 'monthly':      $recText = 'Monthly, day ' . (int)$pdResult->month_day; break;'''
assert old_lbl in t, "Kingdom label anchor not found"
t = t.replace(old_lbl, new_lbl, 1)
p.write_text(t)
print("done")
```

- [ ] **Step 2: Lint**

Run: `docker exec ork3-php8 php -l orkui/controller/controller.Kingdom.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.Kingdom.php
git commit -m "Enhancement: kingdom park-day list labels every-x-weeks cadence

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Legacy calendar (`class.Calendar.php`)

**Files:**
- Modify: `system/lib/ork3/class.Calendar.php` (`_park_days` ~49-91)

- [ ] **Step 1: Select the new columns and pass them to `CalculateNextParkDay`, with interval stepping**

Use Python (three edits in one script):

```python
import pathlib
p = pathlib.Path('system/lib/ork3/class.Calendar.php')
t = p.read_text()

# 1a: SQL columns
old_sql = "\t\t\t\t\t\tpark.name, park.park_id, recurrence, week_of_month, week_day, month_day, purpose, description, time "
new_sql = "\t\t\t\t\t\tpark.name, park.park_id, recurrence, week_of_month, week_day, month_day, start_date, week_interval, purpose, description, time "
assert old_sql in t, "Calendar SQL anchor not found"
t = t.replace(old_sql, new_sql, 1)

# 1b: pass start_date + week_interval to CalculateNextParkDay
old_call = "$date = Park::CalculateNextParkDay($parkdays->recurrence, $parkdays->week_of_month, $parkdays->month_day, $parkdays->week_day, $currdate);"
new_call = "$date = Park::CalculateNextParkDay($parkdays->recurrence, $parkdays->week_of_month, $parkdays->month_day, $parkdays->week_day, $currdate, $parkdays->start_date, $parkdays->week_interval);"
assert old_call in t, "Calendar call anchor not found"
t = t.replace(old_call, new_call, 1)

# 1c: stepping — advance by the interval for every-x-weeks
old_step = '''\t\t\t\t\tcase 'weekly': $currdate = strtotime("+1 week", $currdate); break;
\t\t\t\t\tcase 'monthly': 
\t\t\t\t\tcase 'week-of-month': $currdate = strtotime("+1 month", $currdate); break;'''
new_step = '''\t\t\t\t\tcase 'weekly': $currdate = strtotime("+1 week", $currdate); break;
\t\t\t\t\tcase 'every-x-weeks': $currdate = strtotime("+" . max(1, (int)$parkdays->week_interval) . " weeks", $currdate); break;
\t\t\t\t\tcase 'monthly': 
\t\t\t\t\tcase 'week-of-month': $currdate = strtotime("+1 month", $currdate); break;'''
assert old_step in t, "Calendar stepping anchor not found"
t = t.replace(old_step, new_step, 1)
p.write_text(t)
print("done")
```

- [ ] **Step 2: Lint**

Run: `docker exec ork3-php8 php -l system/lib/ork3/class.Calendar.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add system/lib/ork3/class.Calendar.php
git commit -m "Enhancement: legacy calendar expands every-x-weeks park days

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Weather "playing today" matcher

**Files:**
- Modify: `system/lib/ork3/class.Weather.php` (two SQL blocks ~386-388 and ~592-594)

For `every-x-weeks`, a park is "playing" on `$date` when the weekday matches AND the date is on the interval from the anchor: `DATEDIFF(date, start_date) % (week_interval*7) = 0` AND `date >= start_date`.

- [ ] **Step 1: Add the interval condition to both SQL blocks**

Both blocks share the exact 3-line OR clause, so a `replace_all`-style replacement is safe. Use Python:

```python
import pathlib
p = pathlib.Path('system/lib/ork3/class.Weather.php')
t = p.read_text()
old = '''\t\t\t(pd.recurrence = 'weekly'        AND pd.week_day = '$dow_name') OR
\t\t\t(pd.recurrence = 'week-of-month' AND pd.week_day = '$dow_name' AND pd.week_of_month = $wom) OR
\t\t\t(pd.recurrence = 'monthly'       AND pd.month_day = $dom)'''
new = '''\t\t\t(pd.recurrence = 'weekly'        AND pd.week_day = '$dow_name') OR
\t\t\t(pd.recurrence = 'week-of-month' AND pd.week_day = '$dow_name' AND pd.week_of_month = $wom) OR
\t\t\t(pd.recurrence = 'monthly'       AND pd.month_day = $dom) OR
\t\t\t(pd.recurrence = 'every-x-weeks' AND pd.week_day = '$dow_name' AND pd.week_interval > 0 AND '$ymd' >= pd.start_date AND MOD(DATEDIFF('$ymd', pd.start_date), pd.week_interval * 7) = 0)'''
count = t.count(old)
assert count == 2, f"expected 2 weather SQL blocks, found {count}"
t = t.replace(old, new)

# Both functions need $ymd defined. Add it next to each $wom assignment.
old_wom = "\t\t$wom       = (int)ceil($dom / 7);     // 1..5 — which Nth-weekday of the month today is"
new_wom = old_wom + "\n\t\t$ymd       = date('Y-m-d', $ts);      // canonical date for interval math"
# The first function (parks_playing_on) has the commented $wom; the second may differ.
print("wom-with-comment present:", old_wom in t)
p.write_text(t)
print("done; verify \\$ymd is defined in BOTH SQL-bearing functions")
```

- [ ] **Step 2: Ensure `$ymd` is defined in BOTH functions before its SQL**

The Python above only appends `$ymd` next to the commented `$wom` line (function 1). Read the second block's surrounding function (~line 575-594) and confirm a `$ymd`/`$dow_name`/`$wom` are computed there; if `$ymd` is missing, add `$ymd = date('Y-m-d', $ts);` (or the local timestamp variable that function uses) right after that function computes `$dow_name`. Verify:
```bash
grep -n "\$ymd\|\$dow_name\|\$wom\|every-x-weeks" system/lib/ork3/class.Weather.php
```
Expected: an `$ymd` assignment appears before each `every-x-weeks` SQL line. Fix the second function inline if not.

- [ ] **Step 3: Lint**

Run: `docker exec ork3-php8 php -l system/lib/ork3/class.Weather.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.Weather.php
git commit -m "Enhancement: weather 'playing today' matches every-x-weeks park days

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: End-to-end verification (running app + dark mode)

**Files:** none (verification only)

- [ ] **Step 1: Confirm the app is up**

Run: `docker-compose -f docker-compose.php8.yml up -d` then open `http://localhost:19080/orkui/`.

- [ ] **Step 2: Create a biweekly park day**

On a park you can admin: Park profile → Schedule → Add Park Day → select **Every X Weeks** → Interval = "Every 2 weeks (biweekly)" → pick a **Start Date** (e.g. today) → set Time → Save.
Expected: page reloads; the schedule card reads **"Every other {Weekday}"** (weekday matching the start date).

- [ ] **Step 3: Verify the start-date validation**

Add Park Day → Every X Weeks → leave Start Date blank → Save.
Expected: inline error "A start date is required for the 'every X weeks' cadence." and no save.

- [ ] **Step 4: Verify the park calendar**

On the same park profile, open the calendar view.
Expected: occurrences appear exactly every 2 weeks from the start date, aligned to the chosen weekday, within the 90-day window.

- [ ] **Step 5: Verify every-3 and every-4**

Repeat Step 2 with Interval 3 and 4 (different weekdays/purposes).
Expected labels: **"Every 3 weeks on {Weekday}s"**, **"Every 4 weeks on {Weekday}s"**. Calendar spacing matches.

- [ ] **Step 6: Verify edit round-trip**

Click the pencil on a biweekly card.
Expected: modal opens with **Every X Weeks** selected, the correct Interval, and the correct Start Date populated (not blank, not `1000-01-01`). Change interval to 3, save, confirm the label/calendar update.

- [ ] **Step 7: Verify the kingdom calendar rollup**

Open the kingdom that owns the park → Events/calendar tab; pan to a month containing expected occurrences.
Expected: the park day appears on the correct interval dates (AJAX endpoint `Kingdom/KingdomAjax/calendar/{id}?start=&end=`). Also confirm the kingdom's park-day text list shows the new cadence label.

- [ ] **Step 8: Dark mode walk**

Toggle dark mode. Re-open the Add/Edit Park Day modal.
Expected: the Interval dropdown, Start Date input (incl. its calendar-picker icon), labels, and helper text are all legible — no light-on-light or dark-on-dark. Fix any contrast issue in the template `<style>` block and re-commit Task 4 if needed.

- [ ] **Step 9: Confirm existing modes still work**

Create/verify one Weekly and one Week-of-month park day.
Expected: unchanged behavior, correct labels and calendar placement.

- [ ] **Step 10: Final review**

Run `git log --oneline` to confirm the commit series, and `git diff master --stat` to confirm only the intended files changed (no `CLAUDE.md`, no `class.Authorization.php`).

---

## Out of scope (do not implement)
- Changes to weekly / week-of-month / monthly behavior.
- "Every X months" or arbitrary day intervals.
- Backfilling `start_date` for existing rows.
