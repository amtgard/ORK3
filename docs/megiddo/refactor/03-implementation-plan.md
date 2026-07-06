# Megiddo Refactor — Detailed Implementation Plan

This document lists every refactor target in `orkui/` with **class**, **method**, and **line range**. Targets are grouped by domain. Each target has an ID (`T-xxx`) for tracking in discovery sprints and the milestone checklist.

**Excluded from this document:** how to refactor, and whether code is duplicate or unique (discovery sprint output).

---

## Infrastructure

| ID | Class | Method / Block | Lines | Description |
|----|-------|----------------|-------|-------------|
| T-INF-01 | *(file)* | `orkui/index.php` health route | 11 | `SELECT 1 AS ok` liveness check |
| T-INF-02 | *(file)* | `orkui/index.php` event redirect | 71 | `SELECT name, kingdom_id FROM ork_event` for legacy URL |
| T-INF-03 | `Controller` | `__construct` (session token) | 51–68 | `SELECT token FROM ork_mundane` session validation |
| T-INF-04 | `Controller` | `__construct` (font prefs) | 76–84 | `SELECT basic_fonts, dyslexia_fonts FROM ork_mundane` |
| T-INF-05 | `Controller` | `__construct` (home kingdom) | 97–140 | Home-kingdom lookup SQL |
| T-INF-06 | `Controller` | `__construct` (RSVP widget) | 171–172 | RSVP count aggregate for home widget |

*File: `system/lib/system/class.Controller.php` (base class for all frontend controllers)*

---

## Admin

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-ADM-01 | `Controller_Admin` | `index` | 43–90 | YoY stats: awards, attendance, recommendations aggregates |
| T-ADM-02 | `Controller_Admin` | `permissions` | 803–1018 | Authorization listings with multi-table JOINs |
| T-ADM-03 | `Controller_Admin` | `auditlog` | 1992–2088 | Danger audit pagination; kingdom list; method filter |
| T-ADM-04 | `Controller_Admin` | `serverhealth` | 2177, 2376–2454 | `SHOW GLOBAL STATUS`, `PROCESSLIST`, weather/attendance/event stats |
| T-ADM-05 | `Controller_Admin` | `ajax` → `suspendplayer` | 2209–2210 | Read `suspended_by_id, suspended` before service call |
| T-ADM-06 | `Controller_Admin` | `ajax` → `checkparkabbr` | 2270–2276 | Park abbreviation uniqueness |
| T-ADM-07 | `Controller_Admin` | `ajax` → `checkkingdomabbr` | 2317–2319 | Kingdom abbreviation uniqueness |
| T-ADM-08 | `Controller_Admin` | weather admin actions | 2567–2573+ | `SELECT MAX(fetched_at) FROM ork_park_weather`; `Ork3::$Lib->weather->refresh_all_active_parks()` |
| T-ADM-09 | `Controller_Admin` | `stateofamtgard` | 2646–2650+ | Attendance date range SQL; `Ork3::$Lib->stateofamtgard->getActiveKingdoms()` |
| T-ADM-10 | `Controller_AdminAjax` | `global` → `playersearch` | 29–41 | Player search SQL on `ork_mundane` |
| T-ADM-11 | `Controller_AdminAjax` | `global` → `addauth` | 58–64 | **Direct INSERT** into `ork_authorization` |
| T-ADM-12 | `Controller_AdminAjax` | `stateofamtgard` | 91+ | State-of-Amtgard JSON chart endpoints; date validation in controller |

---

## Event Subsystem

### `Controller_Event`

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-EVT-01 | `Controller_Event` | `index` | 55–92 | Calendar detail ownership check; RSVP counts and user status |
| T-EVT-02 | `Controller_Event` | `template` | 187–188 | RSVP counts per calendar detail |
| T-EVT-03 | `Controller_Event` | `detail` (reads) | 243–347 | Detail row, staff permissions, attendance/RSVP guards, event scope |
| T-EVT-04 | `Controller_Event` | `detail` (writes) | 391–496 | Event type UPDATE; transactional fees/links DELETE+INSERT |
| T-EVT-05 | `Controller_Event` | `detail` (schedule copy) | 519–583 | Calendar detail duplicate INSERTs |
| T-EVT-06 | `Controller_Event` | `detail` (display load) | 637–863 | Park address, event status, staff, schedule, fees, links, dietary |
| T-EVT-07 | `Controller_Event` | `detail` (new detail fees/links) | 990–1090 | Fees/links CRUD on newly created calendar detail |
| T-EVT-08 | `Controller_Event` | *(throughout)* | 29–1032 | `Ork3::$Lib->authorization`, `ghettocache` bust calls |

### `Controller_EventAjax`

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-EVA-01 | `Controller_EventAjax` | `create` | 43–44 | Post-create `UPDATE ork_event SET status='draft'` |
| T-EVA-02 | `Controller_EventAjax` | `set_status` | 78–100 | Staff permission check; status UPDATE; future dates query |
| T-EVA-03 | `Controller_EventAjax` | `preview` | 134–198 | Full event preview queries (event, details, RSVP) |
| T-EVA-04 | `Controller_EventAjax` | `add_attendance` | 277–319 | Staff auth; attendance row JOIN |
| T-EVA-05 | `Controller_EventAjax` | `delete_rsvp` | 367–368 | Staff permission check |
| T-EVA-06 | `Controller_EventAjax` | `auth` | 447–509 | Player search; **direct authorization INSERT** |
| T-EVA-07 | `Controller_EventAjax` | `add_staff` | 576–662 | Staff CRUD; danger audit |
| T-EVA-08 | `Controller_EventAjax` | `remove_staff` | 730–755 | Staff DELETE; danger audit |
| T-EVA-09 | `Controller_EventAjax` | `add_schedule` | 791–908 | Schedule INSERT; schedule_lead INSERTs |
| T-EVA-10 | `Controller_EventAjax` | `update_schedule` | 954–1146 | Schedule UPDATE; lead DELETE+INSERT |
| T-EVA-11 | `Controller_EventAjax` | `heraldry` | 1193–1205 | Heraldry flag UPDATE; `Ork3::$Lib->heraldry` |
| T-EVA-12 | `Controller_EventAjax` | `copy_source_list` | 1298–1316 | Source event list query |
| T-EVA-13 | `Controller_EventAjax` | `create_with_copy` | 1392–1455+ | Event clone: read source; cascade DELETE/INSERT copies |
| T-EVA-14 | `Controller_EventAjax` | `banner` | 1741+ | Event banner upload + DB updates |

### `Controller_EventRsvpAjax`

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-RSV-01 | `Controller_EventRsvpAjax` | `counts` (private) | 14–20 | Aggregate RSVP counts |
| T-RSV-02 | `Controller_EventRsvpAjax` | `set` | 44–61 | End-date gate; INSERT ON DUPLICATE KEY UPDATE on `event_rsvp` |
| T-RSV-03 | `Controller_EventRsvpAjax` | `withdraw` | 85–88 | DELETE from `event_rsvp` |

### `Model_Event`

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-RSV-04 | `Model_Event` | `get_rsvp` | 81–82 | SELECT RSVP status |
| T-RSV-05 | `Model_Event` | `set_rsvp` / toggle | 91–113 | Transactional RSVP INSERT/UPDATE/DELETE |
| T-RSV-06 | `Model_Event` | `get_rsvp_count` | 136–137 | RSVP count aggregate |
| T-RSV-07 | `Model_Event` | `get_rsvp_list` | 148–151 | RSVP list with player JOINs |
| T-RSV-08 | `Model_Event` | `get_upcoming_rsvps` | 161–162 | Upcoming RSVP query |
| T-RSV-09 | `Model_Event` | `get_kingdom_upcoming_events` | 188–189 | Kingdom upcoming events query |

---

## Kingdom

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-KNG-01 | `Controller_Kingdom` | `park_monthly_json` | 105–106 | Park credit monthly SQL |
| T-KNG-02 | `Controller_Kingdom` | `park_averages_json` | 127–180 | Kingdom park averages; prev week/month |
| T-KNG-03 | `Controller_Kingdom` | `events_more` | 237–270 | Paginated events query |
| T-KNG-04 | `Controller_Kingdom` | `players_json` | 397–398 | Kingdom player roster SQL |
| T-KNG-05 | `Controller_Kingdom` | `profile` (officers) | 581–587 | Monarch/regent lookup from `ork_officer` |
| T-KNG-06 | `Controller_Kingdom` | `profile` (events) | 643–660 | Events list; staff permission per row |
| T-KNG-07 | `Controller_Kingdom` | `profile` (calendar) | 695–769 | Calendar items; detail batch; host coords |
| T-KNG-08 | `Controller_Kingdom` | `profile` (park days) | 851–871 | Park day queries |
| T-KNG-09 | `Controller_Kingdom` | `profile` (auth/counts) | 924–1001 | User park lookup; auth check; player count |
| T-KNG-10 | `Controller_Kingdom` | `ics` | 1149–1150 | Calendar ICS export SQL |
| T-KNG-11 | `Controller_Kingdom` | *(throughout)* | 29–1007 | `Ork3::$Lib->authorization`, `ghettocache`, `player->GetCircleAwardIds` |

### `Controller_KingdomAjax`

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-KNA-01 | `Controller_KingdomAjax` | `kingdom` → move player | 385–418 | Kingdom/park lookup; abbreviation conflict |
| T-KNA-02 | `Controller_KingdomAjax` | `kingdom` → award recs public | 603–611 | **Direct INSERT/UPDATE** on `ork_configuration` |
| T-KNA-03 | `Controller_KingdomAjax` | `kingdom` → addauth | 631–635 | **Direct INSERT** into `ork_authorization` |
| T-KNA-04 | `Controller_KingdomAjax` | `kingdom` → checkabbr | 716–718 | Kingdom abbreviation uniqueness |
| T-KNA-05 | `Controller_KingdomAjax` | `calendar` | 759–945 | Royal officers, events, calendar items, park days |
| T-KNA-06 | `Controller_KingdomAjax` | `playersearch` | 1069–1125 | Scoped player search with abbr resolution |
| T-KNA-07 | `Controller_KingdomAjax` | `suspendplayer` | 1183 | Read suspension state from `ork_mundane` |
| T-KNA-08 | `Controller_KingdomAjax` | `banner` | 1254–1364 | Kingdom banner CRUD on `ork_kingdom` |

---

## Park

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-PRK-01 | `Controller_Park` | `profile` (events) | 181–197 | Events list; staff permission per row |
| T-PRK-02 | `Controller_Park` | `profile` (calendar) | 233–306 | Calendar items; detail batch; host coords |
| T-PRK-03 | `Controller_Park` | `profile` (roster) | 386–387 | Roster SQL (also cached via ghettocache 338–415) |
| T-PRK-04 | `Controller_Park` | `profile` (averages) | 429–457 | Monthly/weekly attendance averages |
| T-PRK-05 | `Controller_Park` | *(throughout)* | 37–507 | `Ork3::$Lib->authorization`, `weather->for_park`, `player->GetCircleAwardIds` |

### `Controller_ParkAjax`

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-PRA-01 | `Controller_ParkAjax` | `park` → playersearch | 198–251 | Abbr resolution; player search SQL |
| T-PRA-02 | `Controller_ParkAjax` | `park` → addauth | 427–431 | **Direct INSERT** into `ork_authorization` |
| T-PRA-03 | `Controller_ParkAjax` | `kingdom` → checkabbr | 602–645 | Park abbreviation uniqueness within kingdom |
| T-PRA-04 | `Controller_ParkAjax` | `banner` | 689–805 | Park banner CRUD on `ork_park` |

---

## Player

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-PLR-01 | `Controller_Player` | `profile` (custom title) | 386–387 | Custom Title sentinel award lookup |
| T-PLR-02 | `Controller_Player` | `profile` (notes count) | 402–403 | Note count query |
| T-PLR-03 | `Controller_Player` | `profile` (officers) | 418–432 | Officer roles SQL |
| T-PLR-04 | `Controller_Player` | `profile` (recommendations) | 465–466 | Recommendation query |
| T-PLR-05 | `Controller_Player` | `profile` (admin auth) | 485–506 | Admin role checks and grants |
| T-PLR-06 | `Controller_Player` | `profile` (peerage) | 599–746 | Peerage, beltline, title, association queries |
| T-PLR-07 | `Controller_Player` | `profile` (additional) | 996–997 | Additional profile SQL block |
| T-PLR-08 | `Controller_Player` | *(throughout)* | various | `Ork3::$Lib->authorization` gates |

### `Controller_PlayerAjax`

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-PLA-01 | `Controller_PlayerAjax` | `check_username` | 33–35 | Username uniqueness check |
| T-PLA-02 | `Controller_PlayerAjax` | `player` (awards) | 378–395 | Max award rank; persona lookup |
| T-PLA-03 | `Controller_PlayerAjax` | `merge` | 548–594 | Player kingdom/park lookup; merge auth mirror |
| T-PLA-04 | `Controller_PlayerAjax` | `save_my_email` | 724–726 | **Direct UPDATE** `ork_mundane SET email` |
| T-PLA-05 | `Controller_PlayerAjax` | `add_second` | 755–756 | Persona lookup |
| T-PLA-06 | `Controller_PlayerAjax` | `banner` | 854–988 | Player banner CRUD on `ork_mundane`, `ork_mundane_design` |

### `Model_Player`

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-PLM-01 | `Model_Player` | `bust_player_roster_caches` | 75–76 | Kingdom/park lookup for cache bust |
| T-PLM-02 | `Model_Player` | `edit_note` | 20 | `Ork3::$Lib->player->EditNote` bypass |
| T-PLM-03 | `Model_Player` | cache helpers | 39–86 | `Ork3::$Lib->ghettocache` get/bust |
| T-PLM-04 | `Model_Player` | milestone/date helpers | 222–246 | `Ork3::$Lib->player->GetCustomMilestones`, attendance date helpers |

---

## Reports & Business Rules

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-RPT-01 | `Controller_Reports` | `ladder_grid` | 1064–1284 | Scope name queries; ladder grid multi-query assembly |
| T-RPT-02 | `Controller_Reports` | *(throughout)* | 77–1409 | `Ork3::$Lib->authorization`, `park->GetParkKingdomId`, `ghettocache` |
| T-RPT-03 | `Model_Reports` | `get_attendance_dates` | 145–146 | Distinct attendance dates SQL |
| T-RPT-04 | `Model_Reports` | `_all_voting_rules` | 334–474 | **Hardcoded kingdom voting eligibility rules** |
| T-RPT-05 | `Model_Reports` | `_voting_rules` | 324–328 | Single-kingdom rule lookup |
| T-RPT-06 | `Model_Reports` | `supported_voting_kingdom_ids` | 319–322 | Supported kingdom list from rules |
| T-RPT-07 | `Model_Reports` | `get_voting_eligible` | 477+ | Voting eligibility (uses `_voting_rules`) |
| T-RPT-08 | `Model_Reports` | `get_voting_eligible_for_player` | 492+ | Per-player voting eligibility |
| T-RPT-09 | `Model_Reports` | `kingdom_officer_directory` | 519–520 | `Ork3::$Lib->kingdom->StatsIncludesPrincipalities`, `GetPrincipalities` |

### `Model_Award`

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-AWD-01 | `Model_Award` | `fetch_award_option_list` | 15–117 | Pseudo-ladder IDs, peerage categorization, HTML `<optgroup>` generation |

---

## Search

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-SRC-01 | `Controller_Search` | `unitactivity` | 108–109 | Unit activity SQL; `Ork3::$Lib->ghettocache` (97–112) |
| T-SRC-02 | `Controller_SearchAjax` | `universal` | 29–148 | Abbr resolution; universal player search; ORK admin flag |

---

## Attendance & Sign-In

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-ATT-01 | `Controller_AttendanceAjax` | `park` | 35–39 | **Direct UPDATE** `ork_mundane SET active=1` on sign-in |
| T-ATT-02 | `Controller_AttendanceAjax` | `attendance` | 161–162 | Editor persona lookup |
| T-ATT-03 | `Controller_AttendanceAjax` | `weather_at` | 86, 119 | `Ork3::$Lib->weather->archive_for_date/coords` |
| T-ATT-04 | `Controller_Attendance` | *(throughout)* | 53–229 | `Ork3::$Lib->authorization`, `event->GetActiveEventsAtScope` |
| T-ATT-05 | `Model_Attendance` | `get_adjacent_park_dates` | 148–156 | Prev/next attendance dates for park |
| T-ATT-06 | `Model_Attendance` | cache | 70 | `Ork3::$Lib->ghettocache` |
| T-SIN-01 | `Controller_SignIn` | `index` (event name) | 43–44 | Event name lookup for link scope |
| T-SIN-02 | `Controller_SignIn` | `index` (last class) | 103–104 | Last attendance class query |
| T-SIN-03 | `Controller_SignIn` | `index` (credits) | 122–130 | Per-class credit totals from attendance + reconciliation |
| T-SIN-04 | `Controller_SignIn` | `index` (levels) | 137–149 | **Class level calculation** (thresholds 5/12/21/34/53) |
| T-QR-01 | `Controller_QR` | `link` | 19–20 | Attendance link token validation SQL |

---

## Unit, Banner, Misc

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-UNT-01 | `Controller_UnitAjax` | `banner` | 36–138 | Unit banner CRUD on `ork_unit` |
| T-UNT-02 | `Controller_Unit` | *(officer grant)* | 150 | `Ork3::$Lib->dangeraudit->audit` on auth add |
| T-UNT-03 | `Controller_Unit` | *(throughout)* | 220–265 | `Ork3::$Lib->authorization`, `player->player_info` |
| T-WN-01 | `Controller_WnAjax` | `dismiss` | 18–19 | **Direct INSERT** `ork_whats_new_seen` |

---

## Service-Layer Bypass (`Ork3::$Lib` only — no `$DB` in controller)

These controllers use models/services for data but still call domain libs directly. Grouped for discovery sprint **T-LIB-*** tracking.

| ID | Class | Method / Area | Lines | Lib / Call |
|----|-------|---------------|-------|------------|
| T-LIB-01 | `Controller_Live` | `index`, `recent` | 39, 50 | `live->stats()`, `live->recent()` |
| T-LIB-02 | `Controller_Weather` | all actions | 34–76 | `weather->daily_summary`, `play_for_date`, etc. |
| T-LIB-03 | `Controller_CalendarItemAjax` | edit gate | 108–110 | `authorization->HasAuthority` |
| T-LIB-04 | `Controller_Tournament` | index | 33 | `authorization->HasAuthority` |
| T-LIB-05 | `Controller_EraPhoenice` | *(date math)* | — | `EraPhoenice` lib (verify at discovery) |

*Note: `HasAuthority` appears in 15+ controllers. Discovery sprint DS-12 will treat authorization gating as a cross-cutting API design question.*

---

## Target Summary

| Category | Target count |
|----------|-------------|
| Infrastructure | 6 |
| Admin | 12 |
| Event subsystem | 23 |
| Kingdom | 19 |
| Park | 9 |
| Player | 18 |
| Reports & rules | 10 |
| Search | 2 |
| Attendance & sign-in | 11 |
| Unit & misc | 4 |
| Ork3::$Lib bypass | 5+ (many more via HasAuthority) |
| **Total tracked IDs** | **~119** |

---

## Discovery Sprint Groupings

Targets are batched into discovery sprints (see [04-milestone-checklist.md](./04-milestone-checklist.md)). Suggested groupings:

| Sprint | Target IDs | Theme |
|--------|------------|-------|
| DS-01 | T-RSV-* | RSVP subsystem |
| DS-02 | T-ADM-11, T-KNA-03, T-PRA-02, T-EVA-06 | Authorization INSERT bypass |
| DS-03 | T-PLA-06, T-PRA-04, T-KNA-08, T-UNT-01, T-EVA-14 | Banner CRUD |
| DS-04 | T-EVA-01 – T-EVA-13 | EventAjax core |
| DS-05 | T-EVT-* | Event controller detail |
| DS-06 | T-KNG-*, T-KNA-* (except banner/auth) | Kingdom profile & AJAX |
| DS-07 | T-PRK-*, T-PRA-01/03 | Park profile & AJAX |
| DS-08 | T-ADM-* (except auth INSERT) | Admin dashboard & health |
| DS-09 | T-PLR-*, T-PLA-*, T-PLM-* | Player profile & AJAX |
| DS-10 | T-RPT-*, T-AWD-01 | Reports, voting rules, awards |
| DS-11 | T-SRC-*, T-SRC-02, T-ADM-10, T-KNA-06, T-PRA-01 | Search & player search |
| DS-12 | T-ATT-*, T-SIN-*, T-QR-01 | Attendance & sign-in |
| DS-13 | T-INF-*, T-WN-01 | Infrastructure & misc |
| DS-14 | T-LIB-* + HasAuthority cross-cut | Ork3::$Lib service migration |

Each discovery sprint produces: backend survey, test plan, proposed API revision — **not** implementation.
