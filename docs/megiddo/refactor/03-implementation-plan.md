# Megiddo Refactor — Detailed Implementation Plan

This document lists every refactor target in `orkui/` with **class**, **method**, and **line range**. Targets are grouped by domain. Each target has an ID (`T-xxx`) for tracking in discovery sprints and the milestone checklist.

**Excluded from this document:** how to refactor, and whether code is duplicate or unique (discovery sprint output).

---

## Infrastructure

| ID | Class | Method / Block | Lines | Description |
|----|-------|----------------|-------|-------------|
| T-INF-01 | *(file)* | `orkui/index.php` health route | 8–21 | `SELECT 1 AS ok` liveness check |
| T-INF-02 | *(file)* | `orkui/index.php` event redirect | 69–76 | `SELECT name, kingdom_id FROM ork_event` for legacy URL |
| T-INF-03 | `Controller` | `__construct` (session token) | 40–68 | `SELECT token FROM ork_mundane` session validation |
| T-INF-04 | `Controller` | `__construct` (font prefs) | 73–85 | `SELECT basic_fonts, dyslexia_fonts FROM ork_mundane` |
| T-INF-05 | `Controller` | `index` (home kingdom) | 137–152 | Home-kingdom lookup SQL |
| T-INF-06 | `Controller` | `index` (RSVP widget) | 166–184 | RSVP count aggregate for home widget |

*File: `system/lib/system/class.Controller.php` (base class for all frontend controllers)*

---

## Admin

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-ADM-01 | `Controller_Admin` | `index` | 43–90 | YoY stats: awards, attendance, recommendations aggregates |
| T-ADM-02 | `Controller_Admin` | `permissions` | 803–1018 | Authorization listings with multi-table JOINs |
| T-ADM-03 | `Controller_Admin` | `auditlog` | 2021–2107 | Danger audit pagination; kingdom list; method filter |
| T-ADM-04 | `Controller_Admin` | `serverhealth` | 2176–2183, 2351–2561 | `SHOW GLOBAL STATUS`, `PROCESSLIST`, weather/attendance/event stats |
| T-ADM-05 | `Controller_Admin` | `ajax` → `suspendplayer` | 2207–2220 | Read `suspended_by_id, suspended` before service call |
| T-ADM-06 | `Controller_Admin` | `ajax` → `checkparkabbr` | 2265–2278 | Park abbreviation uniqueness |
| T-ADM-07 | `Controller_Admin` | `ajax` → `checkkingdomabbr` | 2312–2322 | Kingdom abbreviation uniqueness |
| T-ADM-08 | `Controller_Admin` | weather admin actions | 2567–2588 | `SELECT MAX(fetched_at) FROM ork_park_weather`; `Ork3::$Lib->weather->refresh_all_active_parks()` |
| T-ADM-09 | `Controller_Admin` | `stateofamtgard` | 2646–2659 | Attendance date range SQL; `Ork3::$Lib->stateofamtgard->getActiveKingdoms()` |
| T-ADM-10 | `Controller_AdminAjax` | `global` → `playersearch` | 29–41 | Player search SQL on `ork_mundane` |
| T-ADM-11 | `Controller_AdminAjax` | `global` → `addauth` | 55–72 | **Direct INSERT** into `ork_authorization` |
| T-ADM-12 | `Controller_AdminAjax` | `stateofamtgard` | 91+ | State-of-Amtgard JSON chart endpoints; date validation in controller |

---

## Event Subsystem

### `Controller_Event`

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-EVT-01 | `Controller_Event` | `index` | 51–98 | Calendar detail ownership check; RSVP counts and user status |
| T-EVT-02 | `Controller_Event` | `template` | 181–194 | RSVP counts per calendar detail |
| T-EVT-03 | `Controller_Event` | `detail` (reads) | 241–363 | Detail row, staff permissions, attendance/RSVP guards, event scope |
| T-EVT-04 | `Controller_Event` | `detail` (writes) | 391–496 | Event type UPDATE; transactional fees/links DELETE+INSERT |
| T-EVT-05 | `Controller_Event` | `detail` (schedule copy) | 515–584 | Calendar detail duplicate INSERTs |
| T-EVT-06 | `Controller_Event` | `detail` (display load) | 636–927 | Park address, event status, staff, schedule, fees, links, dietary |
| T-EVT-07 | `Controller_Event` | `detail` (new detail fees/links) | 988–1091 | Fees/links CRUD on newly created calendar detail |
| T-EVT-08 | `Controller_Event` | *(throughout)* | 29–1090 | `Ork3::$Lib->authorization`, `ghettocache` bust calls |

### `Controller_EventAjax`

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-EVA-01 | `Controller_EventAjax` | `create` | 5–51 | Post-create `UPDATE ork_event SET status='draft'` |
| T-EVA-02 | `Controller_EventAjax` | `set_status` | 54–115 | Staff permission check; status UPDATE; future dates query |
| T-EVA-03 | `Controller_EventAjax` | `preview` | 119–252 | Full event preview queries (event, details, RSVP) |
| T-EVA-04 | `Controller_EventAjax` | `add_attendance` | 254–343 | Staff auth; attendance row JOIN |
| T-EVA-05 | `Controller_EventAjax` | `delete_rsvp` | 345–379 | Staff permission check |
| T-EVA-06 | `Controller_EventAjax` | `auth` | 414–548 | Player search; **direct authorization INSERT** (addauth 493–528) |
| T-EVA-07 | `Controller_EventAjax` | `add_staff` | 552–702 | Staff CRUD; danger audit |
| T-EVA-08 | `Controller_EventAjax` | `remove_staff` | 704–771 | Staff DELETE; danger audit |
| T-EVA-09 | `Controller_EventAjax` | `add_schedule` | 773–929 | Schedule INSERT; schedule_lead INSERTs |
| T-EVA-10 | `Controller_EventAjax` | `update_schedule` | 982–1168 | Schedule UPDATE; lead DELETE+INSERT |
| T-EVA-11 | `Controller_EventAjax` | `heraldry` | 1171–1260 | Heraldry flag UPDATE; `Ork3::$Lib->heraldry` |
| T-EVA-12 | `Controller_EventAjax` | `copy_source_list` | 1262–1331 | Source event list query |
| T-EVA-13 | `Controller_EventAjax` | `create_with_copy` | 1334–1738+ | Event clone: read source; cascade DELETE/INSERT copies |
| T-EVA-14 | `Controller_EventAjax` | `banner` | 1741–1881 | Event banner upload + DB updates |

### `Controller_EventRsvpAjax`

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-RSV-01 | `Controller_EventRsvpAjax` | `counts` (private) | 12–27 | Aggregate RSVP counts |
| T-RSV-02 | `Controller_EventRsvpAjax` | `set` | 29–71 | End-date gate; INSERT ON DUPLICATE KEY UPDATE on `event_rsvp` |
| T-RSV-03 | `Controller_EventRsvpAjax` | `withdraw` | 73–98 | DELETE from `event_rsvp` |

### `Model_Event`

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-RSV-04 | `Model_Event` | `get_rsvp` | 79–85 | SELECT RSVP status |
| T-RSV-05 | `Model_Event` | `set_rsvp` / toggle | 88–115 | Transactional RSVP INSERT/UPDATE/DELETE |
| T-RSV-05 | `Model_Event` | `remove_rsvp` | 121–132 | yapo DELETE (staff path via EventAjax) |
| T-RSV-06 | `Model_Event` | `get_rsvp_count` | 134–144 | RSVP count aggregate |
| T-RSV-07 | `Model_Event` | `get_rsvp_list` | 146–157 | RSVP list with player JOINs |
| T-RSV-08 | `Model_Event` | `get_upcoming_rsvps` | 159–184 | Upcoming RSVP query |
| T-RSV-09 | `Model_Event` | `get_kingdom_upcoming_events` | 186–215 | Kingdom upcoming events query |

**R-01 complete (2026-07-09):** T-RSV-01…T-RSV-09, T-INF-06 migrated to `class.Event.php` / EventService; `orkui/` RSVP paths use `APIModel('Event')`.

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
| T-KNG-08 | `Controller_Kingdom` | `profile` (park days) | 862–915 | Park day queries |
| T-KNG-09 | `Controller_Kingdom` | `profile` (auth/counts) | 923–1008 | User park lookup; auth check; player count |
| T-KNG-10 | `Controller_Kingdom` | `ics` | 1134–1199 | Calendar ICS export SQL |
| T-KNG-11 | `Controller_Kingdom` | *(throughout)* | 29–1007 | `Ork3::$Lib->authorization`, `ghettocache`, `player->GetCircleAwardIds` |

### `Controller_KingdomAjax`

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-KNA-01 | `Controller_KingdomAjax` | `kingdom` → move player | 384–400 | Kingdom/park lookup; abbreviation conflict |
| T-KNA-02 | `Controller_KingdomAjax` | `kingdom` → award recs public | 603–611 | **Direct INSERT/UPDATE** on `ork_configuration` |
| T-KNA-03 | `Controller_KingdomAjax` | `kingdom` → addauth | 615–654 | **Direct INSERT** into `ork_authorization` |
| T-KNA-04 | `Controller_KingdomAjax` | `kingdom` → checkabbr | 715–721 | Kingdom abbreviation uniqueness |
| T-KNA-05 | `Controller_KingdomAjax` | `calendar` | 759–945 | Royal officers, events, calendar items, park days |
| T-KNA-06 | `Controller_KingdomAjax` | `playersearch` | 1032–1143 | Scoped player search with abbr resolution |
| T-KNA-07 | `Controller_KingdomAjax` | `suspendplayer` | 1183 | Read suspension state from `ork_mundane` |
| T-KNA-08 | `Controller_KingdomAjax` | `banner` | 1225–1376 | Kingdom banner CRUD on `ork_kingdom` |

---

## Park

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-PRK-01 | `Controller_Park` | `profile` (events) | 136–218 | Events list; staff permission per row |
| T-PRK-02 | `Controller_Park` | `profile` (calendar) | 233–306 | Calendar items; detail batch; host coords |
| T-PRK-03 | `Controller_Park` | `profile` (roster) | 386–387 | Roster SQL (also cached via ghettocache 338–415) |
| T-PRK-04 | `Controller_Park` | `profile` (averages) | 429–457 | Monthly/weekly attendance averages |
| T-PRK-05 | `Controller_Park` | *(throughout)* | 37–507 | `Ork3::$Lib->authorization`, `weather->for_park`, `player->GetCircleAwardIds` |

### `Controller_ParkAjax`

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-PRA-01 | `Controller_ParkAjax` | `park` → playersearch | 178–268 | Abbr resolution; player search SQL |
| T-PRA-02 | `Controller_ParkAjax` | `park` → addauth | 411–450 | **Direct INSERT** into `ork_authorization` |
| T-PRA-03 | `Controller_ParkAjax` | `kingdom` → checkabbr | 602–645 | Park abbreviation uniqueness within kingdom |
| T-PRA-04 | `Controller_ParkAjax` | `banner` | 657–817 | Park banner CRUD on `ork_park` |

---

## Player

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-PLR-01 | `Controller_Player` | `profile` (custom title) | 386–393 | Custom Title sentinel award lookup |
| T-PLR-02 | `Controller_Player` | `profile` (notes count) | 403–404 | Note count query |
| T-PLR-03 | `Controller_Player` | `profile` (officers) | 418–443 | Officer roles SQL |
| T-PLR-04 | `Controller_Player` | `profile` (recommendations) | 411–413 | Lazy AJAX — no raw SQL (Model_Reports backend) |
| T-PLR-05 | `Controller_Player` | `profile` (admin auth) | 486–529 | Admin role checks and grants |
| T-PLR-06 | `Controller_Player` | `profile` (peerage) | 600–764 | Peerage, beltline, title, association queries |
| T-PLR-07 | `Controller_Player` | `reconcile` (award map) | 998–1004 | Kingdomaward map SQL |
| T-PLR-08 | `Controller_Player` | *(throughout)* | various | `Ork3::$Lib->authorization` gates |

### `Controller_PlayerAjax`

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-PLA-01 | `Controller_PlayerAjax` | `check_username` / `username_check_payload` | 26–37 | Username uniqueness check |
| T-PLA-02 | `Controller_PlayerAjax` | `player` (awards) | 376–400 | Max award rank; persona lookup |
| T-PLA-03 | `Controller_PlayerAjax` | `merge` | 547–571 | Player kingdom/park lookup; merge auth mirror |
| T-PLA-04 | `Controller_PlayerAjax` | `save_my_email` | 723–726 | **Direct UPDATE** `ork_mundane SET email` |
| T-PLA-05 | `Controller_PlayerAjax` | `add_second` | 754–759 | Persona lookup |
| T-PLA-06 | `Controller_PlayerAjax` | `banner` | 832–1000 | Player banner CRUD on `ork_mundane`, `ork_mundane_design` |

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
| T-RPT-01 | `Controller_Reports` | `ladder_grid` | 1064–1302 | Scope name queries; ladder grid multi-query assembly |
| T-RPT-02 | `Controller_Reports` | *(throughout)* | 77–1409 | `Ork3::$Lib->authorization`, `park->GetParkKingdomId`, `ghettocache` |
| T-RPT-03 | `Model_Reports` | `get_attendance_dates` | 140–153 | Distinct attendance dates SQL |
| T-RPT-04 | `Model_Reports` | `_all_voting_rules` | 336–474 | **Hardcoded kingdom voting eligibility rules** |
| T-RPT-05 | `Model_Reports` | `_voting_rules` | 324–328 | Single-kingdom rule lookup |
| T-RPT-06 | `Model_Reports` | `supported_voting_kingdom_ids` | 319–322 | Supported kingdom list from rules |
| T-RPT-07 | `Model_Reports` | `get_voting_eligible` | 477–490 | Voting eligibility (uses `_voting_rules`) |
| T-RPT-08 | `Model_Reports` | `get_voting_eligible_for_player` | 492–499 | Per-player voting eligibility |
| T-RPT-09 | `Model_Reports` | `kingdom_officer_directory` | 519–529 | `Ork3::$Lib->kingdom->StatsIncludesPrincipalities`, `GetPrincipalities` |

### `Model_Award`

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-AWD-01 | `Model_Award` | `fetch_award_option_list` | 15–117 | Pseudo-ladder IDs, peerage categorization, HTML `<optgroup>` generation |

---

## Search

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-SRC-01 | `Controller_Search` | `unitactivity` | 103–112 | Unit activity SQL; `Ork3::$Lib->ghettocache` (97–112) |
| T-SRC-02 | `Controller_SearchAjax` | `universal` | 5–169 | Abbr resolution; universal player search; ORK admin flag |

---

## Attendance & Sign-In

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-ATT-01 | `Controller_AttendanceAjax` | `park` → `add` | 36–39 | **Direct UPDATE** `ork_mundane SET active=1` on sign-in |
| T-ATT-02 | `Controller_AttendanceAjax` | `attendance` → `edit` | 162–164 | Editor persona lookup |
| T-ATT-03 | `Controller_AttendanceAjax` | `weather_at` | 86, 119 | `Ork3::$Lib->weather->archive_for_date/coords` |
| T-ATT-04 | `Controller_Attendance` | *(throughout)* | 53–229 | `Ork3::$Lib->authorization`, `event->GetActiveEventsAtScope` |
| T-ATT-05 | `Model_Attendance` | `get_adjacent_park_dates` | 143–160 | Prev/next attendance dates for park |
| T-ATT-06 | `Model_Attendance` | cache | 70–77 | `Ork3::$Lib->ghettocache` |
| T-SIN-01 | `Controller_SignIn` | `index` (event name) | 44–46 | Event name lookup for link scope |
| T-SIN-02 | `Controller_SignIn` | `index` (last class) | 104 | Last attendance class query |
| T-SIN-03 | `Controller_SignIn` | `index` (credits) | 123–135 | Per-class credit totals from attendance + reconciliation |
| T-SIN-04 | `Controller_SignIn` | `index` (levels) | 139–162 | **Class level calculation** (thresholds 5/12/21/34/53) |
| T-QR-01 | `Controller_QR` | `link` | 20–26 | Attendance link token validation SQL |

---

## Unit, Banner, Misc

| ID | Class | Method | Lines | Description |
|----|-------|--------|-------|-------------|
| T-UNT-01 | `Controller_UnitAjax` | `banner` | 9–150 | Unit banner CRUD on `ork_unit` |
| T-UNT-02 | `Controller_Unit` | *(officer grant)* | 150 | `Ork3::$Lib->dangeraudit->audit` on auth add |
| T-UNT-03 | `Controller_Unit` | *(throughout)* | 220–265 | `Ork3::$Lib->authorization`, `player->player_info` |
| T-WN-01 | `Controller_WnAjax` | `dismiss` | 17–19 | **Direct INSERT** `ork_whats_new_seen` |

---

## Service-Layer Bypass (`Ork3::$Lib` only — no `$DB` in controller)

These controllers use models/services for data but still call domain libs directly. Grouped for discovery sprint **T-LIB-*** tracking.

| ID | Class | Method / Area | Lines | Lib / Call |
|----|-------|---------------|-------|------------|
| T-LIB-01 | `Controller_Live` | `index`, `recent` | 39, 50 | `live->stats()`, `live->recent()` |
| T-LIB-02 | `Controller_Weather` | all actions | 34–76 | `weather->daily_summary`, `play_for_date`, etc. |
| T-LIB-03 | `Controller_CalendarItemAjax` | edit gate | 108–110 | `authorization->HasAuthority` |
| T-LIB-04 | `Controller_Tournament` | index | 33 | `authorization->HasAuthority` |
| T-LIB-05 | `Controller_EraPhoenice` | `emit`, `holidays` | 58–93 | `EraPhoenice` static date math |

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

---

## Test Sprint Groupings (Phase 1.5)

Each test sprint implements the test plan from its matching discovery sprint (see [04-milestone-checklist.md](./04-milestone-checklist.md)). Test milestones use IDs **T-01 … T-14** (distinct from refactor target IDs like `T-RSV-01`).

| Sprint | Depends on | Target IDs | Theme |
|--------|------------|------------|-------|
| T-01 | DS-01 | T-RSV-*, T-INF-06 | RSVP subsystem tests |
| T-02 | DS-02 | T-ADM-11, T-KNA-03, T-PRA-02, T-EVA-06 | Authorization INSERT tests |
| T-03 | DS-03 | Banner target IDs | Banner CRUD tests |
| T-04 | DS-04 | T-EVA-01 – T-EVA-13 | EventAjax core tests |
| T-05 | DS-05 | T-EVT-* | Event controller detail tests |
| T-06 | DS-06 | Kingdom target IDs | Kingdom profile & AJAX tests |
| T-07 | DS-07 | Park target IDs | Park profile & AJAX tests |
| T-08 | DS-08 | Admin target IDs | Admin dashboard & health tests |
| T-09 | DS-09 | Player target IDs | Player profile & AJAX tests |
| T-10 | DS-10 | T-RPT-*, T-AWD-01 | Reports, voting rules, awards tests |
| T-11 | DS-11 | Search target IDs | Search & player search tests |
| T-12 | DS-12 | T-ATT-*, T-SIN-*, T-QR-01 | Attendance & sign-in tests |
| T-13 | DS-13 | T-INF-*, T-WN-01 | Infrastructure & misc tests |
| T-14 | DS-14 | T-LIB-* + HasAuthority cross-cut | Ork3::$Lib service migration tests |

Each test sprint delivers: backend tests, frontend functional tests (when applicable), and passing milestone-scoped Infection — **not** production refactor.
