# Park Day "Every X Weeks" Recurrence — Design

**Date:** 2026-05-24
**Status:** Approved (pending spec review)

## Goal

Add a fourth park-day recurrence mode, **"Every X weeks"** (interval-based:
biweekly, every 3 weeks, every 4 weeks), to the Park Days tool on the Parknew
profile. Unlike the existing modes, an interval cadence needs a concrete
reference point, so creating one of these park days requires an **example start
date** that anchors the sequence. Every rollup surface that expands park-day
recurrence into calendar occurrences (park calendar, kingdom calendar, legacy
calendar) must render the new sequence correctly.

## Background — existing recurrence modes

The `ork_parkday` table already supports three modes via the `recurrence` enum:

- `weekly` — "Every Monday" (uses `week_day`)
- `week-of-month` — "Every 2nd Tuesday" (uses `week_day` + `week_of_month`)
- `monthly` — "Monthly on the 15th" (uses `month_day`)

This is purely additive; none of the three existing modes change.

## New mode: `every-x-weeks`

- New segmented-control option **"Every X weeks"** in the add/edit modal,
  alongside the three existing options.
- When selected, the modal shows exactly two extra fields:
  - **Interval** — preset dropdown: `Every 2 weeks (biweekly)`, `Every 3 weeks`,
    `Every 4 weeks`.
  - **Start date** — date picker. This is the *first occurrence*. The weekday is
    derived from this date (no separate weekday dropdown for this mode), and the
    cadence is computed forward and backward from it.
- All other fields (purpose, time, description, location: park / alternate /
  online) behave identically to the other modes.

## Data model

Table: `ork_parkday`. Two migration files under `db-migrations/` following the
existing `YYYY-MM-DD-*.sql` convention.

1. **Extend the enum** to add `'every-x-weeks'`:
   ```sql
   ALTER TABLE `ork_parkday`
     MODIFY `recurrence`
     enum('weekly','monthly','week-of-month','every-x-weeks')
     NOT NULL DEFAULT 'weekly';
   ```
2. **Add columns**:
   ```sql
   ALTER TABLE `ork_parkday`
     ADD COLUMN `start_date` DATE NOT NULL DEFAULT '1000-01-01',
     ADD COLUMN `week_interval` INT NOT NULL DEFAULT 0;
   ```
   - `start_date` — the anchor / first occurrence.
   - `week_interval` — holds 2, 3, or 4.
   - (`'1000-01-01'` is MariaDB's minimum valid DATE, used as a NOT-NULL
     sentinel for rows that don't use this mode; with `sql_mode=''` `'0000-00-00'`
     also works, but the explicit minimum avoids relying on a relaxed mode.)

On save, when `recurrence === 'every-x-weeks'`, the backend also derives and
stores `week_day` from `start_date` (`date('l', strtotime($start_date))`) so that
existing weekday-aware label/calendar code keeps a populated value. `week_of_month`
and `month_day` stay 0.

## Save path — `system/lib/ork3/class.Park.php`

- `AddParkDay()` and `EditParkDay()`: when recurrence is `every-x-weeks`, persist
  `start_date`, `week_interval`, and the derived `week_day`; leave
  `week_of_month`/`month_day` at 0. Validate that `start_date` is a real date and
  `week_interval` ∈ {2,3,4}; reject otherwise.
- `GetParkDays()`: include `StartDate` and `WeekInterval` in the returned rows.
- Audit logging: include the two new fields in the before/after snapshots.
- Follow MEMORY rules: `$DB->Clear()` before raw execute if used; yapo drops
  `null`, so never assign null to clear — use appropriate defaults.

## Occurrence expansion — update ALL four sites

A new interval mode must be added to every place that turns a park-day row into
dates. The stepping rule is the same everywhere:

> Anchor at `start_date`. Step size is `week_interval * 7` days. To list
> occurrences in a window, find the first occurrence `≥ window_start` of the form
> `start_date + k·(week_interval·7) days` (k ≥ 0; if `start_date` is after
> `window_start`, the first occurrence is `start_date` itself), then add
> `week_interval·7` days until past `window_end`.

Helper: a single shared computation is preferable to four copies. Where practical,
add a static helper on `class.Park.php` (e.g. `ExpandEveryXWeeks($startDate,
$interval, $rangeStart, $rangeEnd): array<Y-m-d>`) and call it from the PHP sites.
The template loop can call the same helper. (If wiring the helper into the
template proves awkward, an inline copy that matches the helper's logic exactly is
acceptable — but the algorithm must be identical.)

Sites to update:

1. **`orkui/template/revised-frontend/Parknew_index.tpl`** (~lines 156–210) —
   90-day pre-render into `$pkCalParkDays`. Add an `every-x-weeks` branch using
   the stepping rule, bounded by the existing 90-day end date.

2. **`orkui/controller/controller.KingdomAjax.php`** (~lines 792–862) — kingdom
   calendar AJAX endpoint. Add an `every-x-weeks` branch that expands within the
   requested `[start, end]` range, emitting the same `events[]` shape (title,
   start with time, url, color `#b7791f`, type `park-day`).

3. **`system/lib/ork3/class.Park.php` `CalculateNextParkDay()`** (~lines 521–544)
   — single-next-occurrence utility used by the legacy calendar. Add an
   `every-x-weeks` case returning the next occurrence `≥ $from_date` per the
   stepping rule.

4. **`system/lib/ork3/class.Calendar.php` `_park_days()`** (~lines 48–94) — legacy
   calendar loop. Add the interval step (`+{week_interval} weeks`) for the new
   mode so its `while` loop advances correctly.

### Weather matcher — `system/lib/ork3/class.Weather.php`

`parks_playing_on()` decides via SQL whether a park is "playing" on a given date.
For `every-x-weeks`, membership is "weekday matches AND
`(datediff(date, start_date) / 7) % week_interval == 0` AND `date >= start_date`".
Add this condition so weather/"playing today" checks stay correct. (Note: the
calendar-R2 spec is dropping weather display, but `parks_playing_on()` still
exists and must remain correct; if it is confirmed dead during implementation,
document that instead of editing it.)

## Rendering — human-readable labels

Label logic lives in `Parknew_index.tpl` (`pk_ordinal()` + switch, ~lines 675–687
and 913–917) and the Kingdom controller's text description (~lines 627–671).
Add a case for `every-x-weeks`:

- interval 2 → **"Every other {Weekday}"** (e.g. "Every other Monday")
- interval 3 → **"Every 3 weeks on {Weekday}s"**
- interval 4 → **"Every 4 weeks on {Weekday}s"**

Weekday is taken from the stored `week_day` (derived from `start_date`).

## Front-end — `revised.js` + modal template

- **Modal** (`Parknew_index.tpl`, ~lines 2405–2547): add the "Every X weeks"
  segmented option; add the Interval dropdown and Start-date picker, both inside a
  wrapper shown only for this mode.
- **`pkUpdateRecurrenceFields()`** (`revised.js`, ~lines 11525–11532): add a
  branch — for `every-x-weeks` show interval + start-date, hide weekday /
  week-of-month / month-day.
- **`pkOpenEditDayModal(card)`** (~lines 11609–11673): populate from new card data
  attributes `data-start-date` and `data-week-interval`.
- **Schedule card markup** (~lines 710–724): emit `data-start-date` and
  `data-week-interval` so edit round-trips.
- **Save handler** (~lines 11704–11751): include `StartDate` and `WeekInterval` in
  the FormData; when mode is `every-x-weeks`, validate a start date is chosen and
  an interval is selected before submitting.
- **Date picker**: use the project's human-readable date pattern — flatpickr with
  `altInput: true` + a friendly `altFormat` (e.g. `'F j, Y'`) if flatpickr is
  available on the page; otherwise a styled native `<input type=date>`. Never
  leave a raw ISO value visible (per project convention).
- **Dark mode**: the new fields (dropdown, date input, labels, wrapper) must be
  dark-mode compatible on first delivery — walk them in dark mode before done.

## Out of scope

- No changes to the three existing recurrence modes.
- No "every X months" or arbitrary-day-count intervals — week intervals only,
  fixed presets 2/3/4.
- No backfill of `start_date` for existing rows (they don't use the new mode).

## Testing / verification

- Migrations apply cleanly against the Docker MariaDB (`ork3-php8-db`).
- Create a biweekly, every-3-week, and every-4-week park day; confirm:
  - Label renders correctly on the Parknew schedule.
  - Park calendar (90-day) shows occurrences at the right interval from the
    start date.
  - Kingdom calendar AJAX returns the same occurrences within a requested range.
  - Edit round-trips interval + start date without drift.
- Start date in the past and in the future both anchor correctly (occurrences
  align to `start_date` mod interval).
- Verify in the running app (Docker, port 19080) and in dark mode.
