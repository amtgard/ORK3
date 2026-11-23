# Online Attendance — Design

Date: 2026-11-15 · Branch: `feature/online-attendance` (from `upstream/master` 13eae87)

## Intent

Parks and kingdoms hold online sessions (Discord hangouts, online arts days) alongside
in-person park days. Today an online sign-in is indistinguishable from an in-person one.
Some kingdoms' Corpora count only in-person attendance for Corpora-driven decisions
(voting eligibility, park status), and the only existing support is a name match on
"Online" in the park or event name for one kingdom's voting rule.

This feature records whether each sign-in was online, lets officers mark it at the moment
of entry, and lets reports separate or filter it.

**The governing principle:** online attendance is real participation. It counts the same
as in-person attendance everywhere in the system's rules (class credits, levels, active
status, auto-reactivation, Paragon/ladders). Kingdoms apply in-person-only limits **through
reports**, never through rule engines.

## Goals

- An `online` flag on every attendance row, set at entry time.
- An **Online?** toggle on attendance-taking surfaces that applies to everything entered
  while it is on.
- Sign-in links carry the flag; every sign-in through the link inherits it. Park links
  default to Online with an attention-getting tooltip.
- Online is pre-selected when attendance is taken on the date of an online park day.
- A kingdom setting that limits the Corpora-driven report (Voting Eligibility) to
  in-person sign-ins.
- A report-by-report treatment (separate columns, type toggle, badge, or unchanged), as
  decided below.

## Non-goals

- No change to credits, class levels, active status, auto-reactivation, Paragon, ladders,
  dues, or any rule engine.
- No backfill: existing rows stay in-person (`online = 0`). No attempt to infer online-ness
  from park/event names or from `ork_parkday.online` for historical rows.
- No toggle on the legacy `template/default/` attendance pages (Attendance_park /
  Attendance_kingdom / Attendance_event, Event `detail` legacy action). Rows entered there
  are in-person. Exception: Behold (see below).
- No different credit value for online sign-ins.
- No park status or size determinations. The ORK does not declare a park's status today
  and must not start; the kingdom setting does not touch park averages, park titles, or the
  admin Inactive Parks/Kingdoms reports.

## Anti-goals (would make this a failure even if it works)

- An online sign-in silently costing a player class credit, active status, or reactivation.
- A report changing its numbers because of the kingdom setting without saying so on the page.
- Officers creating an event sign-in link and accidentally marking a whole event online, or
  a park link and not noticing it defaulted to Online.
- Report filtering implemented ad hoc per query so different reports disagree on what
  "in-person" means.

## Constraints

- Layers: SQL only in `system/lib/ork3/`; `orkui/model` is the membrane. Controllers and
  templates never build SQL.
- Two databases: every migration applies to `ork` and `ork_test`; classify it in
  `tools/ork-db/manifests/migration-classification.json5`.
- Yapo drops `null` on update — always write `0`/`1`, never null.
- `ork_configuration` flags: read as `(int)Value === 1` (see `Kingdom::StatsIncludesPrincipalities`).
- Tooltips use `data-tip`, never `title`. No native `alert/confirm/prompt`.
- Dark mode for every new control (`html[data-theme="dark"]`).
- The JSON service exposes every public method of `Attendance`; new request keys are
  accepted automatically there and must be validated server-side. SOAP request definitions
  (`orkservice/Attendance/AttendanceService.definitions.php`) need the new field.
- `ork_attendance` is ~3.85M rows; the `ALTER` must be a cheap column add with a default.

## Approaches considered

- **A. Column + one shared report filter, shipped as two PRs (chosen).** PR 1 captures the
  data and wires the kingdom setting into the two Corpora reports; PR 2 delivers the report
  columns/toggles. Capture is useful alone and history accrues before PR 2; each PR stays
  reviewable.
- **B. Same build, one PR.** One merge, but ~40 surfaces in one review and capture waits on
  reports.
- **C. Derive online-ness from context** (park day flag, link, "Online" in names) with no
  column. No migration, but cannot represent a Discord hangout held by an in-person park —
  the core case — and the user asked for a column. Rejected.

## Data model

### `ork_attendance.online`
`TINYINT(1) NOT NULL DEFAULT 0`, added after `entry_method`. No index in PR 1: report
filters always combine it with existing date/park/kingdom predicates that already use
indexes; revisit only if PR 2 profiling shows a need. **Not** added to `unique_attendance`
(a player cannot be both online and in-person at the same park/date/event).

### `ork_attendance_link.online`
`TINYINT(1) NOT NULL DEFAULT 0`. `UseAttendanceLink` copies it onto the row it inserts.

### Kingdom setting `CountOnlineAttendance`
`ork_configuration`, `CFG_KINGDOM`, key `CountOnlineAttendance`, default `'1'` (counted).
Seeded for every existing kingdom by migration and in `Kingdom` create (mirror how
`IncludePrincipalityInStatistics` is seeded at `class.Kingdom.php:417`). Kingdom 20 is
seeded `'0'` (see Voting below). Principalities have their own row; a park follows its
own `kingdom_id`'s setting.

Label: **Count Online Attendance**. Hint (in `revised.js` `keyHints`, ~line 5734):
> When off, sign-ins marked Online are not counted toward Voting Eligibility for this
> Kingdom. Class credits, active status, player records, other reports and attendance
> history are not affected.

Domain accessor: `Kingdom::CountsOnlineAttendance($kingdomId): bool`, memoized per request
like `StatsIncludesPrincipalities`.

## The shared filter (domain layer)

One helper owns what "in-person / online / all" means, e.g.
`Attendance::TypeClause(string $mode, string $alias = 'a'): string` returning `''`,
`"AND {alias}.online = 0"` or `"AND {alias}.online = 1"`, with `$mode` whitelisted to
`all|in_person|online` (anything else → `all`). Every report that filters by type calls it;
no report writes `online = ` by hand. Builder decides exact name/location (class.Attendance
or a small static helper), but there is exactly one.

## PR 1 — Capture

### Entry surfaces and the toggle

| Surface | Change |
|---|---|
| Park profile "Enter Attendance" modal (`Parknew_index.tpl:2084`, `revised.js:~10814`) | **Online?** switch in the modal header area next to the date, applying to Search-tab adds and Recent-tab quick-adds. Sent as `Online=0/1` with each add. Stays set while the modal is open; resets when reopened (then re-derived from the park-day rule). |
| Event page add-attendance form (`Eventnew_index.tpl:1817`, `:2335`; `revised.js:~9276`) | Same switch, default off. |
| Player profile "Add Attendance" modal (`revised.js:~16211`) | Same switch; default re-derived from the park-day rule when park + date are both chosen. |
| Behold batch entry (`default/Attendance_behold.tpl`, linked from the park profile) | One switch for the whole batch. This is the one legacy-theme page that gets it because the CRM links to it. |
| Edit attendance (`AttendanceAjax/attendance/{id}/edit`; `revised.js:~10933`, `~16092`; `Eventnew_index.tpl:~3478`) | Online checkbox in the edit form so mistakes can be fixed. `SetAttendance` accepts `Online`. |
| Self-registration welcome credit (`Player::SelfRegister`) | Always `online = 0`. |
| Legacy default-theme attendance pages | No toggle; rows are `online = 0`. |

Domain: `AddAttendance` and `SetAttendance` accept `Online` (cast to `0/1`; missing →
`0` on add, unchanged on edit). The controller → model → domain path passes it through;
SOAP `AddAttendanceRequest` / `SetAttendanceRequest` gain an optional `Online` int.

### Online park-day suggestion

When a park attendance surface's date changes (and on open), the client checks the park's
park days for that date:

- If an **online** park day occurs on that date and **no in-person** park day does → the
  switch turns on, with a small hint "Online park day".
- If both an online and an in-person park day occur that date → switch stays as-is (off on
  open) and the hint reads "This park has an online and an in-person park day on this date."
- Otherwise → off.
- A manual flip clears the hint; it comes back only when the rule re-derives (date change
  or reopening the form).

Occurrence matching reuses the existing recurrence logic (`ork_parkday_next_occurrences`
in `common.php` / `Park::CalculateNextParkDay`); the server supplies the park's park days
with `online` to the page (the park profile already ships `PkConfig.calParkDays`). The
builder decides whether matching runs server-side (ship a per-date answer) or client-side,
but the recurrence math must not be re-implemented divergently in JS — prefer a server
endpoint or server-precomputed dates.

The suggestion only sets the default; the officer can always flip it.

### Sign-in links

- `CreateAttendanceLink` accepts `Online`; stores it on the link.
- **Park links** (park profile Sign-in Link panel, `Parknew_index.tpl:~2190`,
  `revised.js:~11849`; kingdom-side park link JS `~12355`): the Online? switch defaults
  **on**. When the panel opens, a tooltip flashes on the switch for ~4 seconds:
  "Not for an online event? Disable this." (uses the existing tooltip styling, shown
  programmatically, then hidden; reappears on hover via `data-tip`).
- **Event links** (`revised.js:~12128`): switch defaults **off**, no flash.
- The link list (`GetAttendanceLinks`, `revised.js:~12196` and the park list) shows an
  "Online" badge on online links.
- `UseAttendanceLink` copies `online` from the link to the row. The player-facing sign-in
  page (`SignIn_index.tpl`) shows "Online sign-in" when the link is online.

### Badges on lists (PR 1)

Rows with `online = 1` show a small wifi icon (icon only, no label; `data-tip` and
`aria-label` "Signed in online") on: player attendance tab, per-date and per-event attendance lists,
event occurrence attendee list, Live dashboard ticker. Lists keep counting all rows. CSV
exports that list attendance rows gain an `Online` column (Yes/blank).

### Kingdom setting wired into Voting Eligibility (PR 1)

- **Voting Eligibility** (`Report::GetVotingEligible`, `GetVotingEligibleForPlayer`):
  - Remove `'ExcludeOnline' => true` from kingdom 20 in `VotingRules`; the migration seeds
    kingdom 20's `CountOnlineAttendance = '0'` so behavior is preserved.
  - Exclusion applies when the kingdom's setting is off. The exclusion predicate becomes
    `a.online = 0 AND <existing name match>` — i.e. a row is excluded if it is flagged
    online **or** its park/event name contains "Online" (keeps historical exclusions for
    kingdom 20; harmless for others since it only runs when the setting is off).
  - Report text (`Reports_voting_eligible.tpl:171`) replaces "Events that include
    'Online' in the event name are not included" with "Online sign-ins are not counted
    (Kingdom setting)." The per-player "excluded online" count stays.
  - Voting rules remain otherwise in `VotingRules`; only the online switch moves.
- The voting report and the player-profile voting badge show a visible note when the
  setting is excluding online sign-ins.
- Inactive Parks / Inactive Kingdoms (admin-only, list already-inactive groups) are **not**
  affected by the setting; they keep counting all attendance.

## PR 2 — Reports

Decided treatment per surface (confirmed with the user):

**Follows the kingdom setting:** Voting Eligibility + player voting badge (done in PR 1).
Nothing else reads the setting.

**Separate In-Person / Online / Total columns:** Attendance report (kingdom and park, by
period — `AttendanceSummary`, `GetDistinctPlayerStats`, `GetMonthlyChartData`; chart
becomes stacked); Feature Utilization (adds an online sign-in line alongside signin_link /
self_reg).

**In-Person / Online / All toggle (default All):** Park Attendance Explorer; New Player
Attendance; park and kingdom attendance averages (park profile, kingdom profile); admin
Top Parks / Active Kingdoms; State of Amtgard (one page-level toggle feeding every query);
Population Explorer (an "Attendance type" filter applied to sign-in-derived fields and
CSV).

**Always in-person:** park profile "on the field today" count (`GetParkFieldCountToday`).

**Count everything, unchanged:** Active Players; Player Status Reconciliation;
auto-reactivation; class credits, Class Masters, Guilds; ladder grid "recently active";
Weekly Recap; admin dashboard active count; admin Inactive Parks / Inactive Kingdoms; rosters and last-sign-in columns; unit
activity; RSVP last attended; Weather / server-health active-park checks; qualification
test stats.

The toggle is a shared segmented control (In-Person / Online / All) in the `.rp-*` report
shell, passed as `AttendanceType=all|in_person|online`, validated server-side by the
shared filter.

## Migration (PR 1)

`db-migrations/2026-11-xx-online-attendance.sql`:
1. `ALTER TABLE ork_attendance ADD COLUMN online TINYINT(1) NOT NULL DEFAULT 0 AFTER entry_method;`
2. `ALTER TABLE ork_attendance_link ADD COLUMN online TINYINT(1) NOT NULL DEFAULT 0;`
3. Seed `CountOnlineAttendance` for every kingdom (`'1'`), kingdom 20 `'0'`, idempotent.

Classified in `migration-classification.json5`; applied to both `ork` and `ork_test`.

## Testing

- Unit/integration: `AddAttendance`/`SetAttendance`/`UseAttendanceLink` persist and
  inherit `online`; `CreateAttendanceLink` stores it; the shared filter's whitelist;
  `CountsOnlineAttendance`; voting eligibility with the setting on/off (flag-only row,
  name-only row, both, neither).
- Park-day suggestion: online-only day, in-person-only day, both same weekday, monthly and
  every-x-weeks recurrences.
- Browser: each entry surface toggles and saves; park link defaults on and flashes the
  tooltip; event link defaults off; sign-in through an online link produces an online row;
  dark mode on every new control; phone width on the park modal.
- PR 2: parity — for each report, All equals the pre-change number; In-Person + Online
  equals All.

## Left to the builder

- Exact helper names and placement for the shared filter and setting accessor.
- Whether park-day matching is a small endpoint or precomputed dates shipped with the page.
- Visual design of the switch and badge within existing CRM styles.
- Whether the Online switch sits in the modal header or beside the date row, as long as it
  is visible before the first add.

## My calls (not explicitly decided by the user)

- Event sign-in links default off; only park links default on and flash the tooltip.
- Legacy default-theme pages get no toggle (Behold excepted).
- Voting exclusion keeps the old name match OR'd with the flag so kingdom 20's history
  doesn't change.
- When a park has both online and in-person park days on the date, no auto-suggest.
- Edit attendance can flip the flag.
- No index on `online` until profiling asks for one.
