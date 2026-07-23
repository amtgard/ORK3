# DS-01: RSVP Subsystem — Discovery Design Note

**Milestone:** DS-01  
**Branch:** `megiddo/ds-01-rsvp-discovery`  
**Target IDs:** T-RSV-01 through T-RSV-09, T-INF-06  
**Depends on:** M0.1 (test framework)  
**Execution sprint:** R-01
**Test sprint:** T-01

---

## 1. Backend survey

### 1.1 Scope summary

Event RSVP is a **frontend-only subsystem** today. All read/write paths for `ork_event_rsvp` used by the UI live in `orkui/` — primarily `Model_Event`, `Controller_EventRsvpAjax`, inline SQL in `Controller_Event` and `class.Controller`, plus one staff path through `Controller_EventAjax::delete_rsvp`.

There is **no RSVP surface** in `orkservice/Event/` or `system/lib/ork3/class.Event.php`. The `EventService.function.php` file is empty; event SOAP handlers delegate to `class.Event.php`, which covers event/calendar-detail CRUD but not RSVPs.

### 1.2 Database schema

Table `ork_event_rsvp` ([`db-migrations/2026-02-28-add-event-rsvp.sql`](../../../db-migrations/2026-02-28-add-event-rsvp.sql)):

| Column | Notes |
|--------|-------|
| `rsvp_id` | PK |
| `event_calendardetail_id` | FK → `ork_event_calendardetail`, CASCADE delete |
| `mundane_id` | FK → `ork_mundane`, CASCADE delete |
| `status` | ENUM `going` \| `interested` |
| `modified` | Timestamp |

Unique key `(event_calendardetail_id, mundane_id)` — one RSVP per player per occurrence.

Performance index on `(event_calendardetail_id, status)` ([`2026-04-11-performance-indexes.sql`](../../../db-migrations/2026-04-11-performance-indexes.sql)).

### 1.3 Frontend violations (target IDs)

#### T-RSV-01 – T-RSV-03: `Controller_EventRsvpAjax`

| ID | Method | Lines | Behavior |
|----|--------|-------|----------|
| T-RSV-01 | `counts` (private) | 12–27 | SUM CASE aggregate: going + interested for one detail |
| T-RSV-02 | `set` | 29–71 | Login gate; validate status whitelist; **end-date gate** (`event_end < now()`); INSERT … ON DUPLICATE KEY UPDATE; return counts + my_status |
| T-RSV-03 | `withdraw` | 73–98 | DELETE own RSVP; return counts + empty my_status |

Called from `revised.js` (`EventRsvpAjax/set`, `EventRsvpAjax/withdraw`) for AJAX RSVP buttons on event detail.

#### T-RSV-04 – T-RSV-09: `Model_Event`

| ID | Method | Lines | Behavior |
|----|--------|-------|----------|
| T-RSV-04 | `get_rsvp` | 79–85 | SELECT status for (detail, mundane); false if none |
| T-RSV-05 | `set_rsvp` / `toggle_rsvp` | 87–119 | Transactional INSERT / UPDATE / **toggle-off DELETE** when same status re-selected |
| T-RSV-05 | `remove_rsvp` | 121–132 | yapo DELETE (staff path via EventAjax) |
| T-RSV-06 | `get_rsvp_count` | 134–144 | GROUP BY status → going, interested, total |
| T-RSV-07 | `get_rsvp_list` | 146–157 | JOIN mundane/park/kingdom + last-attendance class; ordered by status, persona |
| T-RSV-08 | `get_upcoming_rsvps` | 159–184 | Player's future RSVPs with event name/dates |
| T-RSV-09 | `get_kingdom_upcoming_events` | 186–215 | Kingdom published future events **excluding** player's existing RSVPs; LIMIT 6 |

#### T-INF-06: `class.Controller::__construct`

Lines 167–182: After `Search_Event` home widget, batch-query total RSVP count per `NextDetailId` and attach `RsvpCount` to each event summary row.

### 1.4 Related frontend SQL (out of DS-01 targets, R-01 should consume same API)

These duplicate aggregation/write patterns and should migrate to the same backend API during R-01 or closely following sprints:

| Location | Lines | Pattern |
|----------|-------|---------|
| `Controller_Event::index` | 74–98 | Batch counts by detail+status; batch user status; calls `get_rsvp_list` per detail |
| `Controller_Event::template` | 181–203 | Batch total count per detail (`_RsvpCount`) |
| `Controller_Event::detail` | 365–383 | Form POST RSVP via `set_rsvp` with ownership + date gate |
| `Controller_EventAjax::preview` | 180–204 | Same SUM CASE counts + my status as EventRsvpAjax (T-EVA-03) |
| `Controller_EventAjax::delete_rsvp` | 345–378 | Staff auth → `Model_Event::remove_rsvp` (T-EVA-05) |
| `Controller_KingdomAjax` | 782–841 | Royal-officer RSVP GROUP_CONCAT for kingdom event list (DS-06 territory) |

### 1.5 Backend references (not UI-facing, keep in domain)

| Location | Purpose |
|----------|---------|
| `class.SearchService::Search_Event` | Embeds `rsvp_going` / `rsvp_interested` subqueries in event search results — already backend-side |
| `class.Report.php` | Event attendance report RSVP count; admin “feature usage” RSVP analytics |
| `class.Player.php` merge | Dedup/update `event_rsvp` rows on player merge |

No consolidation needed for Report analytics in R-01, but new domain methods should be reusable by SearchService to avoid a third count implementation.

### 1.6 Call graph (frontend)

```
revised.js ──► EventRsvpAjax/set|withdraw
Controller_Event::index ──► toggle_rsvp, inline batch SQL, get_rsvp_list
Controller_Event::template ──► inline batch total counts
Controller_Event::detail ──► set_rsvp, get_rsvp*, get_rsvp_list
Controller_EventAjax::preview ──► inline counts (duplicate of EventRsvpAjax)
Controller_EventAjax::delete_rsvp ──► remove_rsvp
Controller_Player::index|profile ──► toggle_rsvp, get_upcoming_rsvps, get_kingdom_upcoming_events
class.Controller::__construct ──► inline batch total counts (home widget)
```

### 1.7 Behavioral inconsistencies (must resolve in R-01)

| Topic | Path A | Path B |
|-------|--------|--------|
| **Toggle-off** | `Model_Event::set_rsvp` deletes row when same status clicked again | `EventRsvpAjax::set` UPSERTs — same status stays; use `withdraw` to clear |
| **End-date gate** | `EventRsvpAjax::set`: `strtotime(event_end) < time()` (datetime) | `Controller_Event::detail` rsvp action: date-only compare on event_end/start |
| **Status values** | Both whitelist `going` \| `interested` | — |
| **Auth for writes** | EventRsvpAjax: session login only | Event detail form: ownership check (detail belongs to event) + date gate |
| **Staff delete** | `remove_rsvp` via EventAjax with AUTH_EDIT or staff `can_attendance` | — |

**Recommendation:** Unify on explicit **set**, **withdraw**, and optional **toggle** operations in domain layer. Preserve current UX per entry point until templates are updated — map each caller to the canonical operation rather than copying divergent SQL.

### 1.8 Gaps

- No `EventService` RSVP endpoints or WSDL types.
- No domain class methods on `Event` (or dedicated RSVP class).
- No PHPUnit coverage for RSVP logic.
- `EventService.function.php` is empty — RSVP handlers would follow the thin-wrapper pattern used by `ParkService.function.php` (`function SetRsvp($request) { return Ork3::$Lib->event->… }` or a dedicated lib loader).
- Search already returns per-status counts; home widget and template use **total only** — API should support both granularities in one batch call.

---

## 2. Test design

### 2.1 Backend unit tests (implement in T-01)

Add `tests/Integration/EventRsvpTest.php` (DB required) covering domain methods after move:

| Test case | Validates |
|-----------|-----------|
| `testSetRsvpInsertsGoing` | New row with status `going` |
| `testSetRsvpUpdatesStatus` | `interested` → `going` update |
| `testWithdrawRsvpDeletesRow` | Row removed; counts zero |
| `testSetRsvpRejectsEndedEvent` | End-date gate returns error (canonical rule TBD) |
| `testSetRsvpRejectsInvalidStatus` | Non-whitelist status rejected |
| `testGetRsvpCountsByDetail` | going / interested / total match seed rows |
| `testGetBatchRsvpCounts` | Multiple detail IDs in one call |
| `testGetRsvpListIncludesPlayerFields` | Persona, park/kingdom abbr, last class |
| `testGetUpcomingRsvpsExcludesPast` | Only future `event_start` |
| `testGetKingdomUpcomingEventsExcludesExistingRsvp` | Player's RSVPed details omitted |
| `testRemoveRsvpStaffRequiresAuth` | Unauthorized token rejected |
| `testToggleOffSemantics` | Document chosen behavior (toggle vs explicit withdraw) |

Use transactional rollback or dedicated test mundane/detail IDs from dev seed; follow `CalendarServiceTest` skip-when-DB-down pattern.

Optional pure unit tests in `tests/Unit/EventRsvpValidationTest.php` for status whitelist and date-gate helpers if extracted as static methods.

### 2.2 Service-layer tests

Extend integration tests to call SOAP/JSON wrappers once registered:

- `Event.SetRsvp`, `Event.WithdrawRsvp`, `Event.GetRsvpSummary`, etc.
- Verify response shapes match what `APIModel('Event')` callers need.

### 2.3 Infection scope (T-01, DS-7)

| Source filter | PHPUnit filter |
|---------------|----------------|
| `--filter=class.Event.php` (or `class.EventRsvp.php` if split) | `--test-framework-options="--filter=EventRsvpTest"` |
| Include `EventService.function.php` once handlers exist | Same |

Document exact filters in T-01 commit. Target ≥ current `minMsi` / `minCoveredMsi` (15) from `tools/infection/infection.json5`.

### 2.4 Frontend functional tests (implement in T-01)

Playwright/Cypress against `http://localhost:19080/orkui/` per [06-test-framework.md](./06-test-framework.md):

| Flow | Steps | Assert |
|------|-------|--------|
| Event detail AJAX RSVP | Login → event detail → click Going | Count increments; button state updates |
| Event detail Interested + withdraw | Set Interested → withdraw | Counts decrement; my_status cleared |
| Event detail form RSVP | POST rsvp action (legacy form path) | Redirect; status persisted |
| Player profile upcoming | Own profile with RSVP | List shows event; cancel removes row |
| Kingdom discovery widget | Own profile, kingdom events | Shows events without existing RSVP |
| Staff RSVP list | Event staff with attendance perm | RSVP list visible with player names |
| Home widget counts | Home page logged out/in | Event summary shows RSVP totals |

Auth: use dev admin or seeded test player credentials documented in T-01.

---

## 3. Proposed revision

### 3.1 Domain layer (`system/lib/ork3/`)

Add RSVP methods to **`class.Event.php`** (preferred — keeps event subsystem together) **or** a new **`class.EventRsvp.php`** loaded via `Ork3::$Lib` if `class.Event.php` size becomes unwieldy.

Proposed domain methods:

| Method | Replaces |
|--------|----------|
| `GetRsvpStatus($detailId, $mundaneId)` | T-RSV-04 |
| `SetRsvp($detailId, $mundaneId, $status, $options)` | T-RSV-02, T-RSV-05 (unified rules) |
| `WithdrawRsvp($detailId, $mundaneId)` | T-RSV-03 |
| `RemoveRsvp($detailId, $mundaneId, $actorMundaneId)` | T-RSV-05 remove + staff auth |
| `GetRsvpCounts($detailId)` | T-RSV-01, T-RSV-06 |
| `GetRsvpCountsBatch(array $detailIds)` | Controller index/template/home widget batch queries |
| `GetUserRsvpStatusesBatch(array $detailIds, $mundaneId)` | Controller index user status map |
| `GetRsvpList($detailId)` | T-RSV-07 |
| `GetUpcomingRsvps($mundaneId)` | T-RSV-08 |
| `GetKingdomUpcomingEventsWithoutRsvp($kingdomId, $mundaneId, $limit = 6)` | T-RSV-09 |
| `IsDetailEnded($detailId)` | Shared end-date gate |

`SetRsvp` options should include `'allow_toggle_off' => bool` to preserve both AJAX and form-post semantics until UX is unified.

### 3.2 Service layer (`orkservice/Event/`)

Add to `EventService.definitions.php`, `EventService.registration.php`, and **`EventService.function.php`** (populate — currently empty):

| SOAP method | Request fields | Response |
|-------------|----------------|----------|
| `Event.GetRsvpStatus` | EventCalendarDetailId, MundaneId | StatusType + Status |
| `Event.SetRsvp` | EventCalendarDetailId, MundaneId, Status, Token? | StatusType + counts + MyStatus |
| `Event.WithdrawRsvp` | EventCalendarDetailId, Token | StatusType + counts |
| `Event.RemoveRsvp` | EventCalendarDetailId, TargetMundaneId, Token | StatusType |
| `Event.GetRsvpCounts` | EventCalendarDetailId | Going, Interested, Total |
| `Event.GetRsvpSummaryBatch` | EventCalendarDetailIds[], MundaneId? | Array of count + user status per detail |
| `Event.GetRsvpList` | EventCalendarDetailId, Token | RsvpPlayerList |
| `Event.GetUpcomingRsvps` | MundaneId | UpcomingRsvpList |
| `Event.GetKingdomEventsWithoutRsvp` | KingdomId, MundaneId, Limit? | EventSummaryList |

JSON equivalents via existing `orkservice/Json/` routing if AJAX controllers switch to `JSONModel('Event')`.

Staff authorization for `RemoveRsvp` and `GetRsvpList`: mirror current `HasAuthority(AUTH_EVENT, …, AUTH_EDIT)` OR `event_staff.can_attendance` check — implement once in domain, not in controllers.

### 3.3 Frontend replacement (R-01)

| File | Change |
|------|--------|
| `Model_Event` | Replace T-RSV-04–09 bodies with `APIModel('Event')` calls; delete `$DB` usage |
| `Controller_EventRsvpAjax` | Thin JSON controller calling `JSONModel`/`APIModel`; delete `counts()` private SQL |
| `class.Controller::__construct` | Replace T-INF-06 inline SQL with batch API call (or enrich Search response server-side) |
| `Controller_Event::index` | Replace inline batch SQL with `GetRsvpSummaryBatch` |
| `Controller_Event::template` | Same batch API for `_RsvpCount` |
| `Controller_EventAjax::preview` | Replace inline RSVP block with API call |

Keep `Controller_EventRsvpAjax` as the JSON endpoint for `revised.js` unless routes move to Json service — either way, no `$DB` in controller.

### 3.4 SearchService follow-up (optional, same sprint or DS-04)

Refactor `SearchService::Search_Event` RSVP subqueries to call `GetRsvpCounts` internally — eliminates a fourth count implementation. Low risk if domain method is shared.

### 3.5 Migration order (R-01)

**Post-rebase (RB-D1, 2026-07-09):** §1 line ranges verified against `orkui/` at base `e6417645` (`origin/master`). No upstream gap closures; §3 revision unchanged.

1. Implement domain methods + unit/integration tests (green suite).
2. Register EventService SOAP/JSON endpoints.
3. Switch `Model_Event` RSVP methods to API (single choke point).
4. Switch `Controller_EventRsvpAjax` and inline controller SQL.
5. Run milestone-scoped Infection; add frontend functional tests.
6. Audit: `rg '\$DB->' orkui/ -g '*Event*'` and `rg 'event_rsvp' orkui/` → zero write/read paths for RSVP.

### 3.6 Out of scope for R-01

- `Controller_KingdomAjax` royal RSVP aggregation (DS-06).
- Report/admin analytics RSVP queries in `class.Report.php`.
- Player merge RSVP SQL (stays in domain; already backend-side).

---

## 4. Open decisions for R-01

1. **Canonical toggle behavior** — explicit withdraw only (match AJAX) vs toggle-off on repeat set (match form post)?
2. **Canonical end-date rule** — datetime vs calendar-date gate?
3. **Dedicated `EventRsvp` class vs methods on `Event`** — decide based on `class.Event.php` line count after addition.
4. **Home widget** — extend Search API to include total RSVP count vs separate batch call from Controller?

---

## Related documents

| Doc | Purpose |
|-----|---------|
| [03-implementation-plan.md](./03-implementation-plan.md) | Target ID inventory |
| [04-milestone-checklist.md](./04-milestone-checklist.md) | DS-01 tracking |
| [06-test-framework.md](./06-test-framework.md) | Test and Infection commands |
| [validations/v-01-rsvp-validation.md](./validations/v-01-rsvp-validation.md) | Phase 1.6 — canary URLs + test mutation boundaries (V-01) |
