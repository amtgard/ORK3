# DS-14: Ork3::$Lib Service Migration — Discovery Design Note

**Milestone:** DS-14  
**Branch:** `megiddo/ds-14-lib-service-discovery`  
**Target IDs:** T-LIB-01 through T-LIB-05; cross-cutting `HasAuthority` and remaining `Ork3::$Lib` bypass patterns  
**Depends on:** M0.1, DS-02 (authorization domain), prior DS-* domain surveys  
**Execution sprint:** R-14
**Test sprint:** T-14

---

## 1. Backend survey

### 1.1 Scope summary

After `$DB` removal, the largest remaining frontend violation class is **direct `Ork3::$Lib->{domain}`** calls — domain logic and data access bypassing `orkservice/*`. This sprint catalogs explicit targets (T-LIB-*) and the **cross-cutting authorization gate** pattern used in 20+ controller files and several templates.

| Category | Approx. call sites (`orkui/`) | Primary libs |
|----------|-------------------------------|--------------|
| **HasAuthority gates** | ~128 PHP + ~20 template | `authorization` |
| **GhettoCache read/bust** | ~55 | `ghettocache` |
| **Player helpers** | ~28 | `player` |
| **Kingdom helpers** | ~14 | `kingdom` |
| **Weather** | ~16 | `weather` |
| **Live dashboard** | 2 JSON actions | `live` |
| **Audit / heraldry / SoA** | ~8 | `dangeraudit`, `heraldry`, `stateofamtgard` |
| **Era Phoenice** | static class (not `$Lib`) | `EraPhoenice` in `class.EraPhoenice.php` |

**Call chain today:** Controller → `Ork3::$Lib->{lib}->{method}()` → domain SQL/cache. Clean reference: pages that use only `APIModel` / `JSONModel` (e.g. much of `Controller_Recap`).

**R-14 role:** Establish **shared JSON/SOAP surfaces** and migration policy for lib bypasses that are not owned by a single domain R-* sprint. Domain-specific lib calls (e.g. `kingdom->GetFamilyKingdomIds` in KingdomAjax) may move in R-06/R-07 but must follow the same API rules defined here.

### 1.2 Explicit targets (T-LIB-01 – T-LIB-05)

#### T-LIB-01: `Controller_Live` — `stats`, `recent`

| Lines | Behavior |
|-------|----------|
| 39, 50 | `Ork3::$Lib->live->stats()` and `->recent()` — JSON endpoints session-gated in controller |

**Existing backend:** `system/lib/ork3/class.Live.php` — full SQL aggregation, internal ghettocache (60s/20s TTL). **No** LiveService or JSON registration.

**Gap:** Expose `Live.GetStats`, `Live.GetRecent` on JSON API; controller calls `JSONModel('Live')`. Domain stays authoritative; controller keeps session gate only.

#### T-LIB-02: `Controller_Weather` — all actions

| Lines | Behavior |
|-------|----------|
| 34–37, 49, 75–76 | `daily_summary`, `play_for_date`, `upcoming_events_with_forecast`, `freshness_phrase`, `strip_severities`, `day` JSON |

**Existing backend:** `class.Weather.php` (~1500 lines) — cache reads, Open-Meteo integration, archive helpers. Also called from `Controller_Park` (T-PRK-05), `Controller_AttendanceAjax` (DS-12), admin refresh (DS-08).

**Gap:** WeatherService JSON wrapping existing methods. Single migration fixes T-LIB-02 and unblocks DS-07/DS-12 weather paths.

#### T-LIB-03: `Controller_CalendarItemAjax` — edit gate

| Lines | Behavior |
|-------|----------|
| 108–110 | `HasAuthority` for park CREATE or kingdom CREATE on calendar row |

**Existing backend:** `Authorization::HasAuthority` — domain-only; **not** on AuthorizationService JSON (SOAP has `GetAuthorizations` only).

**Gap:** Shared `Authorization.HasAuthority` JSON endpoint (see §1.3).

#### T-LIB-04: `Controller_Tournament` — index gate

| Lines | Behavior |
|-------|----------|
| 33 | `HasAuthority($uid, AUTH_PARK, session park_id, AUTH_EDIT)` for tournament admin UI flag |

**Gap:** Same HasAuthority API; set `$this->data['CanEdit']` from model call.

#### T-LIB-05: `Controller_EraPhoenice` — date math

| Lines | Behavior |
|-------|----------|
| 58–92 | Static calls `EraPhoenice::fromDate`, `format`, `holiday`, etc. — public CORS JSON |

**Existing backend:** `class.EraPhoenice.php` — pure static date math, no DB. Loaded as PHP class, **not** via `Ork3::$Lib` loader.

**Gap:** Register `EraPhoeniceService` JSON (today/holidays/date) for consistency with mORK consumers; controller becomes thin proxy **or** route moves to `orkservice/Json/` entirely. Not a `$DB` issue — service-boundary issue for third-party API stability.

### 1.3 Cross-cutting: `HasAuthority`

#### Inventory

| Location | Count (approx.) | Pattern |
|----------|-----------------|---------|
| `system/lib/system/class.Controller.php` | 3 | Menu admin links (global, kingdom, park scope) — lines 92, 98, 105 |
| AJAX controllers | ~70 | Gate before mutating actions |
| Page controllers | ~50 | `$this->data['CanEdit']`, row-level flags |
| Templates | ~20 | Inline UI gates (heraldry, roster, admin tabs) |

**Existing backend:** `Authorization::HasAuthority($mundane_id, $type, $id, $role)` — recursive scope checks, ORK admin short-circuit. Tested heavily in deprecated `AuthorizationService.testrig.php` but **not exposed** as a service method.

**Frontend workaround today:** Direct domain call avoids SOAP round-trip latency on every page (noted in `SearchAjax` comment: yapo ORM mutates `$DB` state — controllers call `$DB->Clear()` after auth checks).

**Gap:** Expose canonical API:

```
Authorization.HasAuthority(Token, Type, Id, Role) → { Authorized: bool }
```

JSONModel from frontend; domain method unchanged. Optional **`Authorization.HasAuthorityBatch`** for pages that check many rows (event lists, roster reports) to reduce N+1 service calls in R-10/R-06 follow-ups.

#### Template violations (out of T-LIB IDs, in R-14 scope)

Templates must not call `Ork3::$Lib`. Controllers precompute flags:

| Template | Fix |
|----------|-----|
| `Admin_player.tpl`, `Admin_kingdom.tpl`, `Admin_park.tpl` | Pass `CanEdit*` from controller |
| `Reports_roster.tpl`, `Reports_playerawardrecommendations.tpl` | Move scope checks to `Controller_Reports` |
| `Playernew_index.tpl`, `Kingdomnew_index.tpl`, `Playernew_reconcile.tpl` | Pass booleans in `$this->data` |
| `default.theme` | Admin menu already partially in Controller base — finish in R-14 |

### 1.4 Cross-cutting: `ghettocache`

~55 call sites across controllers and `model.Player`, `model.Award`, `model.Attendance`.

| Pattern | Examples | Policy |
|---------|----------|--------|
| **Read-through cache** | `Controller_Kingdom.players_json`, `Controller_Park.park_players` | Move cache **inside** domain/service method; frontend calls API only |
| **Write bust** | Event search bust, player detail bust after attendance | Bust in domain on write (DS-12 T-ATT-06); remove frontend bust |
| **Admin flush** | `KingdomAjax` memcache flush | Admin-only — expose `Cache.Flush` admin API or keep in AdminService |

**Gap:** R-14 defines policy + helper registration; **full** bust migration spans R-06–R-12 as those domains land. Do not duplicate cache keys in frontend after each R-*.

### 1.5 Cross-cutting: other `Ork3::$Lib` domains

| Lib | Key call sites | Owner sprint |
|-----|----------------|--------------|
| `player` | `model.Player.php`, `Controller_Park`, `Controller_Unit` | R-09 (player) + R-14 for shared helpers |
| `kingdom` | `KingdomAjax`, `model.Reports`, `Controller_Unit` | R-06 |
| `dangeraudit` | Auth INSERT paths (DS-02), EventAjax heraldry | R-02 / R-04 — move audit into domain on write |
| `heraldry` | `EventAjax` | R-04 |
| `stateofamtgard` | `Controller_Admin` | R-08 |

R-14 delivers **HasAuthority + Live + Weather + EraPhoenice JSON** first; other libs migrate in listed execution sprints using the same `JSONModel` pattern.

### 1.6 Backend surface (existing)

| Layer | Location | Relevant to R-14 |
|-------|----------|------------------|
| Domain | `class.Authorization.php` | `HasAuthority`, `GetAuthorizations` |
| Domain | `class.Live.php`, `class.Weather.php`, `class.EraPhoenice.php` | Full implementations, no service wrappers |
| Service | `AuthorizationService` | No `HasAuthority` WSDL/JSON |
| Service | — | **No** LiveService, WeatherService, EraPhoeniceService |
| JSON router | `orkservice/Json/index.php` | Lists `Authorization` — methods limited to registered functions |
| Tests | `AuthorizationService.testrig.php` | HasAuthority scenarios — migrate to PHPUnit, not production API |

### 1.7 Cross-milestone overlaps

| Pattern | Also in | Notes |
|---------|---------|-------|
| Weather lib | DS-07, DS-08, DS-12 | Single WeatherService unblocks all |
| ghettocache bust | DS-12 T-ATT-06, DS-06 kingdom cache | Policy here; implementation per domain |
| HasAuthority | Every DS-* with auth gates | R-14 provides shared API; R-* replace call sites incrementally |
| `Controller::__construct` HasAuthority | DS-13 | Menu links — include in R-14 HasAuthority rollout |

---

## 2. Test design

### 2.1 Backend unit/integration tests (implement in T-14)

Extend `tests/Integration/AuthorizationTest.php` (or create):

| Test case | Target | Validates |
|-----------|--------|-----------|
| `testHasAuthorityOrkAdmin` | Cross-cut | Global admin → true for any scope |
| `testHasAuthorityParkEdit` | T-LIB-03, T-LIB-04 | Park editor granted; non-editor denied |
| `testHasAuthorityEventCreate` | Cross-cut | Event CREATE vs EDIT roles |
| `testHasAuthorityInvalidScope` | Cross-cut | Invalid id → false |

Add `tests/Integration/LiveServiceTest.php`:

| Test case | Target | Validates |
|-----------|--------|-----------|
| `testGetStatsShape` | T-LIB-01 | Response keys: now, parks, events, active_3h |
| `testGetRecentLimit` | T-LIB-01 | ≤50 rows, ordered by entered_at |

Add `tests/Integration/WeatherServiceTest.php`:

| Test case | Target | Validates |
|-----------|--------|-----------|
| `testDailySummaryForToday` | T-LIB-02 | Non-empty rundown when cache seeded |
| `testPlayForDateInvalid` | T-LIB-02 | Rejects bad date format at service layer |
| `testArchiveForPark` | DS-12 overlap | Historic weather for park+date |

Add `tests/Unit/EraPhoeniceTest.php`:

| Test case | Target | Validates |
|-----------|--------|-----------|
| `testKnownBoundaryDate` | T-LIB-05 | Feb 12 2026 → E.P. 44 (regression anchor from class doc) |
| `testHolidayLookup` | T-LIB-05 | Garbmas on Jan 4 |
| `testServiceJsonShape` | T-LIB-05 | JSON endpoint matches static helper output |

Skip integration tests when DB unavailable.

### 2.2 Infection scope (T-14, DS-7)

Phase R-14 in two mutation passes (recommended):

**Pass A — Authorization + EraPhoenice:**

```bash
sh bin/run-infection.sh \
  --filter=class.Authorization.php \
  --filter=class.EraPhoenice.php \
  --test-framework-options="--filter=AuthorizationTest|EraPhoeniceTest"
```

**Pass B — Live + Weather (after service wrappers exist):**

```bash
sh bin/run-infection.sh \
  --filter=class.Live.php \
  --filter=class.Weather.php \
  --test-framework-options="--filter=LiveServiceTest|WeatherServiceTest"
```

Focus mutators on: HasAuthority early-return branches, cache TTL boundaries, Live cutoff time math, Weather archive lag guard.

### 2.3 Frontend functional tests (implement in T-14)

| Flow | Steps | Assert |
|------|-------|--------|
| Menu admin link | Login as kingdom editor | Kingdom admin panel link visible; non-editor hidden |
| Tournament page | Park officer vs player | CanEdit flag matches authority |
| Calendar item edit | Kingdom officer on kingdom event row | Edit allowed via AJAX gate |
| Live dashboard | Login → `/Live/stats` | JSON status 0; counts present |
| Weather dashboard | Login → `/Weather`, switch date pill | Rundown/play JSON loads |
| Era Phoenice API | GET `EraPhoenice/today` | CORS JSON unchanged shape |
| Template gates | Player admin heraldry tab | Visible only with park edit auth — no template lib call |

---

## 3. Proposed revision

### 3.1 Principle

**No `Ork3::$Lib` in `orkui/`** (including templates) in target state. Frontend uses `APIModel` / `JSONModel` exclusively. Domain libs remain implementation detail behind `orkservice/*`.

**Exception (documented):** Pure presentation formatting with no DB and no business rules *may* remain in templates if duplicated from API output — Era Phoenice should still move to service for API stability.

**Performance:** HasAuthority on hot paths may use batch API or session-cached auth snapshot refreshed on login — measure before optimizing; correctness first.

### 3.2 New service API (R-14)

#### AuthorizationService (extend)

| Method | Request | Response |
|--------|---------|----------|
| `HasAuthority` | Token, Type, Id, Role | `{ Authorized: bool }` |
| `HasAuthorityBatch` *(optional)* | Token, Checks[] | `{ Results: bool[] }` |

Register on SOAP + JSON. Implement as thin wrapper on existing domain method.

#### LiveService (new)

| Method | Maps from | Notes |
|--------|-----------|-------|
| `GetStats` | T-LIB-01 | Wraps `Live::stats()` |
| `GetRecent` | T-LIB-01 | Wraps `Live::recent()` |

#### WeatherService (new)

| Method | Maps from | Notes |
|--------|-----------|-------|
| `GetDailySummary` | T-LIB-02 | `daily_summary($date)` |
| `GetPlayForDate` | T-LIB-02 | `play_for_date($date)` |
| `GetUpcomingEventsWithForecast` | T-LIB-02 | 7-day default |
| `GetFreshnessPhrase` | T-LIB-02 | |
| `GetStripSeverities` | T-LIB-02 | Date[] → map |
| `GetForPark` | DS-07 overlap | Park profile weather |
| `GetArchiveForPark` / `GetArchiveForCoords` | DS-12 overlap | Attendance weather |

#### EraPhoeniceService (new)

| Method | Maps from | Notes |
|--------|-----------|-------|
| `GetToday` | T-LIB-05 | Same payload as controller `emit()` |
| `GetDate` | T-LIB-05 | YYYY-MM-DD param |
| `GetHolidays` | T-LIB-05 | Constant map |

CORS headers on JSON router for public methods (or dedicated route).

### 3.3 Frontend replacement strategy (R-14)

| Phase | Work |
|-------|------|
| **1** | Register services + PHPUnit coverage |
| **2** | Replace T-LIB-01–05 controllers with JSONModel calls |
| **3** | Add `Model_Authorization::has_authority()` wrapper; migrate `Controller` base menu gates |
| **4** | Replace HasAuthority in T-LIB-03, T-LIB-04, then CalendarItemAjax pattern across AJAX controllers (can parallelize into R-04–R-08 if R-14 is large) |
| **5** | Remove template `Ork3::$Lib` — controllers pass flags |
| **6** | Document ghettocache policy; remove frontend bust calls as each domain R-* completes |

**Helper wrapper (idiomatic ORK3):**

```php
// model.Authorization.php
public function has_authority($uid, $type, $id, $role) {
    return $this->api->HasAuthority([
        'Token' => $this->session->token,
        'MundaneId' => $uid,
        'Type' => $type,
        'Id' => $id,
        'Role' => $role,
    ])['Authorized'] ?? false;
}
```

*(Exact request shape follows WSDL definitions — Token validation per DS-02 patterns.)*

### 3.4 Sequencing and split risk

R-14 is **large** if all ~128 HasAuthority sites move at once. Recommended:

1. Ship Authorization + Live + Weather + EraPhoenice services (T-LIB-*) — **done in R-14**.
2. Migrate explicit T-LIB-03/04 + Controller base menus — **done in R-14**.
3. Track remaining HasAuthority replacements in **R-15** (table in §1.5); ghettocache in **R-16**; domain lib bypass in **R-17**; residual `$DB` in **R-18** — see [10-phase-2-continuation.md](./10-phase-2-continuation.md).
4. **Phase 3 audit** (after R-18): `rg 'Ork3::\$Lib' orkui/` → zero; `rg '\$DB->' orkui/` → zero.

### 3.5 Non-goals (R-14)

- Moving all ghettocache usage (continues in R-06–R-12).
- Replacing every `player`/`kingdom` lib call (R-06, R-09).
- Changing HasAuthority semantics (role inheritance, KPM bypass) — API exposes existing behavior only.

**Post-rebase (RB-D4, 2026-07-09):** §1 line ranges verified against `orkui/` and `class.Controller.php` at base `e6417645` (`origin/master`). Minor drift in Controller menu HasAuthority (92, 98, 105); T-LIB-01–05 line tables unchanged; no upstream gap closures; §3 revision unchanged.

---

## 4. Exit criteria checklist

- [ ] Backend survey complete (§1)
- [ ] Test design documented (§2)
- [ ] Proposed service API and migration policy documented (§3)
- [ ] HasAuthority inventory and template violations recorded
- [ ] Cross-refs to DS-07/DS-08/DS-12 (weather), DS-13 (Controller menu gates)

---

## Related documents

| Doc | Link |
|-----|------|
| Implementation plan | [03-implementation-plan.md](./03-implementation-plan.md) |
| Test framework | [06-test-framework.md](./06-test-framework.md) |
| DS-13 infrastructure discovery | [ds-13-infrastructure-discovery.md](./ds-13-infrastructure-discovery.md) |
| [validations/v-14-lib-service-validation.md](./validations/v-14-lib-service-validation.md) | Phase 1.6 — canary URLs + test mutation boundaries (V-14) |
