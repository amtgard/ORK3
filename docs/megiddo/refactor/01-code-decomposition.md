# Megiddo Refactor — Code Decomposition

This document describes how ORK3 is structured today and where business logic and database access currently live relative to the target architecture.

## Goal

During significant sprint work, business logic and database access landed in `orkui/controller` (and, in some cases, `orkui/model`). The target state:

| Layer | Location | Responsibility |
|-------|----------|----------------|
| Domain logic + data access | `system/lib/ork3/` | Business rules, SQL, transactions, validation |
| API surface | `orkservice/*` | SOAP/JSON endpoints exposing domain operations |
| Frontend | `orkui/` | Routing, presentation, auth-gated UI, thin model wrappers |

The frontend may reshape, combine, or filter API results for display. It must not duplicate domain logic already offered (or that should be offered) by a backend API.

## Repository Layout

```
ORK3/
├── orkui/                      # Frontend MVC (PHP)
│   ├── index.php               # Router; health check; legacy redirects
│   ├── controller/             # 34 Controller_*.php files
│   ├── model/                  # 20 Model_*.php wrappers
│   ├── template/               # default/ + revised-frontend/
│   └── language/
├── orkservice/                 # Backend SOAP/JSON services (85 PHP files)
│   ├── Player/, Kingdom/, Park/, Event/, Report/, Authorization/, …
│   └── Json/index.php          # JSON API entry
├── system/lib/
│   ├── ork3/                   # Domain classes (30 files)
│   └── system/                 # Framework: Controller, Model, APIModel, Session, …
├── db-migrations/
└── bin/                        # Cron scripts
```

## Request Flow (Current)

```
Browser
  → orkui/index.php (Route=Controller/method/action)
  → Controller_* (orkui/controller/)
      ├── global $DB (YapoMysql) — direct SQL  ← refactor target
      ├── Ork3::$Lib->{domain} — domain lib bypass  ← refactor target
      ├── Model_* (orkui/model/)
      │     ├── APIModel('ServiceName') → SOAP → orkservice/
      │     ├── JSONModel('ServiceName') → JSON → orkservice/Json/
      │     └── global $DB — direct SQL  ← refactor target
      └── APIModel / JSONModel (inline in some controllers)
  → template (*.tpl)
```

## Request Flow (Target)

```
Browser
  → orkui/index.php
  → Controller_* / Model_*
      └── APIModel / JSONModel only
            → orkservice/*
                  → system/lib/ork3/class.*.php
                        → $DB / yapo ORM
  → template (*.tpl)
```

Presentation-only logic (formatting dates, building HTML optgroups from structured data returned by the API, sorting a list for display) may remain in `orkui`. Domain rules (eligibility, aggregation semantics, write paths, authorization side effects) must not.

## Backend (`system/lib/ork3/`)

Domain classes loaded via `Ork3::$Lib`:

| Class | File | Primary domain |
|-------|------|----------------|
| `Player` | `class.Player.php` | Player CRUD, merge, notes, milestones |
| `Kingdom` | `class.Kingdom.php` | Kingdom stats, officers, principalities |
| `Park` | `class.Park.php` | Park operations |
| `Event` | `class.Event.php` | Event lifecycle |
| `Attendance` | `class.Attendance.php` | Sign-in, links |
| `Authorization` | `class.Authorization.php` | Roles, `HasAuthority` |
| `Report` | `class.Report.php` | Report queries |
| `Award` | `class.Award.php` | Award operations |
| `Unit` | `class.Unit.php` | Unit operations |
| `Weather` | `class.Weather.php` | Weather fetch/display |
| `Live` | `class.Live.php` | Live stats |
| `Heraldry` | `class.Heraldry.php` | Heraldry overlays |
| `Tournament` | `class.Tournament.php` | Tournaments |
| `Treasury` | `class.Treasury.php` | Dues |
| `DangerAudit` | `class.DangerAudit.php` | Audit trail |
| `GhettoCache` | `class.GhettoCache.php` | Application cache |
| `StateOfAmtgard` | `class.StateOfAmtgard.php` | State-of-Amtgard aggregates |
| `Calendar` / `CalendarItem` | `class.Calendar.php`, `class.CalendarItem.php` | Calendar |
| `SearchService` | `class.SearchService.php` | Search |
| `Map` | `class.Map.php` | Atlas |
| `Principality` | `class.Principality.php` | Principalities |
| `DataSet` | `class.DataSet.php` | Data sets |
| `Administration` | `class.Administration.php` | Admin operations |
| `EraPhoenice` | `class.EraPhoenice.php` | Era calculations |

Supporting: `common.php`, `wx_safety_helpers.php`, `pride_gradients.php`, `class.Ork3.php` (loader).

## Backend API (`orkservice/`)

Services follow a consistent file pattern per domain:

| Service | Path | Registration |
|---------|------|--------------|
| Player | `orkservice/Player/` | `PlayerService.php`, `.function.php`, `.definitions.php` |
| Kingdom | `orkservice/Kingdom/` | same pattern |
| Park | `orkservice/Park/` | same pattern |
| Event | `orkservice/Event/` | same pattern |
| Report | `orkservice/Report/` | same pattern |
| Authorization | `orkservice/Authorization/` | same pattern |
| Award | `orkservice/Award/` | same pattern |
| Attendance | `orkservice/Attendance/` | same pattern |
| Unit | `orkservice/Unit/` | same pattern |
| Calendar | `orkservice/Calendar/` | same pattern |
| Search | `orkservice/Search/` | same pattern |
| Heraldry | `orkservice/Heraldry/` | same pattern |
| Tournament | `orkservice/Tournament/` | same pattern |
| Treasury | `orkservice/Treasury/` | same pattern |
| Principality | `orkservice/Principality/` | same pattern |
| Map | `orkservice/Map/` | same pattern |
| DataSet | `orkservice/DataSet/` | same pattern |
| Notification | `orkservice/Notification/` | same pattern |
| Pronoun | `orkservice/Pronoun/` | same pattern |

Existing tests (partial coverage): `PlayerService.test.php`, `KingdomService.test.php`, `ParkService.test.php`, `ReportService.test.php`, `CalendarService.test.php`, `EventService.test.php`, plus `AuthorizationService.testrig.php`.

## Frontend (`orkui/`)

### Controllers (34 files)

All live under `orkui/controller/controller.{Name}.php` as class `Controller_{Name}`.

**Controllers with direct `$DB` access (19 + infrastructure):**

| Controller | Primary concern |
|------------|-----------------|
| `Admin` | Dashboard YoY stats, permissions listings, audit log, server health, abbreviation checks |
| `AdminAjax` | Global player search, direct authorization INSERT |
| `Event` | RSVP reads, event detail CRUD (fees, links, schedule, staff) |
| `EventAjax` | Event AJAX surface (~1,455 lines; staff, schedule, copy, heraldry, auth INSERT) |
| `EventRsvpAjax` | RSVP set/withdraw/counts |
| `Kingdom` | Profile aggregates, JSON endpoints, ICS export |
| `KingdomAjax` | Calendar, player search, auth INSERT, config, banner, move player |
| `Park` | Profile aggregates, roster SQL, attendance averages |
| `ParkAjax` | Player search, auth INSERT, abbreviation check, banner |
| `Player` | Profile SQL (officers, peerage, titles, notes) |
| `PlayerAjax` | Username check, merge auth, email UPDATE, banner |
| `Reports` | Ladder grid assembly (bypasses ReportService) |
| `Search` | Unit activity SQL |
| `SearchAjax` | Universal player search SQL |
| `SignIn` | Event name lookup, class credit totals |
| `AttendanceAjax` | Active-player UPDATE on sign-in |
| `UnitAjax` | Unit banner CRUD |
| `QR` | Attendance link token validation |
| `WnAjax` | What's-new seen INSERT |

**Infrastructure with direct `$DB` access:**

| File | Concern |
|------|---------|
| `orkui/index.php` | Health check (`SELECT 1`), legacy event redirect |
| `system/lib/system/class.Controller.php` | Session token validation, font prefs, home-kingdom lookup, RSVP widget counts |

**Controllers using models/services only (relative clean templates):**

`Attendance`, `Award`, `Atlas`, `CalendarItemAjax`, `EraPhoenice`, `Heraldry`, `Live`, `Login`, `Principality`, `Recap`, `ReleaseNotes`, `SelfReg`, `Tournament`, `Unit`, `Weather`

Note: several of these still call `Ork3::$Lib` directly (see below).

### Models (20 files)

Thin wrappers over `APIModel`/`JSONModel` in most cases. Exceptions with direct SQL or domain logic:

| Model | Issue |
|-------|-------|
| `Model_Event` | Full RSVP subsystem in SQL (lines 79–215) |
| `Model_Reports` | `get_attendance_dates` SQL; voting rules hardcoded (lines 319–475) |
| `Model_Attendance` | Adjacent park date SQL (lines 148–156) |
| `Model_Player` | Cache-bust kingdom/park lookup SQL (lines 75–76); several `Ork3::$Lib->player` calls |
| `Model_Award` | Award categorization + HTML optgroup generation (lines 15–117) |

### Frontend bypass of service layer (`Ork3::$Lib`)

Direct domain-lib calls from `orkui` bypass `orkservice/*`:

| Lib | Call sites (representative) |
|-----|----------------------------|
| `authorization` | Nearly all controllers — `HasAuthority` gates |
| `ghettocache` | Kingdom, Park, Player, Event, Reports, Search, Attendance models |
| `weather` | Weather, Park, AttendanceAjax, Admin |
| `live` | Live |
| `player` | Park, Kingdom, Unit, Model_Player |
| `kingdom` | Model_Reports |
| `park` | Reports |
| `event` | Attendance |
| `dangeraudit` | EventAjax, ParkAjax, Unit |
| `heraldry` | EventAjax |
| `stateofamtgard` | Admin |

These should eventually be reachable only through service APIs (or a dedicated auth/session service for `HasAuthority` if retained at the edge).

## Tables Touched from Frontend (Direct SQL)

Most frequently accessed from `orkui`:

`ork_event`, `ork_event_calendardetail`, `ork_event_rsvp`, `ork_event_staff`, `ork_event_schedule`, `ork_event_schedule_lead`, `ork_event_fees`, `ork_event_links`, `ork_authorization`, `ork_mundane`, `ork_mundane_design`, `ork_mundane_note`, `ork_attendance`, `ork_class_reconciliation`, `ork_park`, `ork_kingdom`, `ork_officer`, `ork_unit`, `ork_configuration`, `ork_danger_audit`, `ork_whats_new_seen`, `ork_attendance_link`, `ork_awards`, `ork_recommendations`, `ork_park_weather`

## Cross-Cutting Violation Patterns

1. **Direct `$DB` in controllers/models** — highest priority; 19 controllers, 5 models, base `Controller`, `index.php`.
2. **Authorization bypass** — `INSERT INTO ork_authorization` in AdminAjax, KingdomAjax, ParkAjax, EventAjax instead of `AuthorizationService`.
3. **Duplicated subsystems** — RSVP (Model_Event + EventRsvpAjax + Controller_Event + base Controller); banner CRUD (PlayerAjax, ParkAjax, KingdomAjax, UnitAjax).
4. **Business rules in frontend** — voting rules (`Model_Reports`), award categorization (`Model_Award`), class level thresholds (`Controller_SignIn`), ladder grid SQL (`Controller_Reports`).
5. **`Ork3::$Lib` from frontend** — domain operations without service boundary.

## Related Documents

- [02-requirements.md](./02-requirements.md) — refactor requirements
- [03-implementation-plan.md](./03-implementation-plan.md) — per-target inventory with class/method/lines
- [04-milestone-checklist.md](./04-milestone-checklist.md) — milestones and discovery sprints
- [05-development-steering.md](./05-development-steering.md) — branch, test, mutation, and commit rules
- [07-agent-milestone-prompt.md](./07-agent-milestone-prompt.md) — agent prompt template for milestone work
