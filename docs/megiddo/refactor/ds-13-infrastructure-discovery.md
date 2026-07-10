# DS-13: Infrastructure & Misc — Discovery Design Note

**Milestone:** DS-13  
**Branch:** `megiddo/ds-13-infrastructure-discovery`  
**Target IDs:** T-INF-01 through T-INF-05, T-WN-01 (plus T-INF-06 — home-widget RSVP batch; same files, tracked in [03-implementation-plan.md](./03-implementation-plan.md) as `T-INF-*`)  
**Depends on:** M0.1, DS-01 (T-INF-06 RSVP counts overlap `GetRsvpCountsBatch`)  
**Execution sprint:** R-13
**Test sprint:** T-13

---

## 1. Backend survey

### 1.1 Scope summary

Infrastructure violations live in **shared frontend bootstrap** code that runs on (nearly) every request, plus one small AJAX handler and an adjacent template read:

| File | Role |
|------|------|
| `orkui/index.php` | Health probe + legacy event redirect before MVC dispatch |
| `system/lib/system/class.Controller.php` | Base constructor: stale-session check, font prefs, menu auth gates; `index()` home kingdom + RSVP widget enrichment |
| `orkui/controller/controller.WnAjax.php` | Dismiss “What’s New” modal — direct INSERT |
| `orkui/template/default/default.theme` | *(adjacent)* SELECT whether to show What’s New modal — not a numbered target but same table |

**Call chain today:** Router → `Controller_*::__construct` (all pages) → optional `Controller::index()` (home). Health and event redirect bypass controller instantiation.

**Risk profile:** These paths are **high fan-out** — changes affect every page load or ops monitoring. Refactors must preserve latency (avoid extra round-trips per request) and existing redirect/health semantics.

### 1.2 Database tables touched

| Table | DS-13 usage |
|-------|-------------|
| *(none for health)* | T-INF-01 uses `SELECT 1` only |
| `ork_event` | T-INF-02 legacy redirect name/kingdom lookup |
| `ork_mundane` | T-INF-03 session token; T-INF-04 font prefs |
| `ork_park`, `ork_kingdom` | T-INF-05 home kingdom join |
| `ork_event_rsvp` | T-INF-06 batch COUNT for home event widget |
| `ork_whats_new_seen` | T-WN-01 dismiss INSERT; default.theme read |

### 1.3 Frontend violations — `orkui/index.php`

#### T-INF-01: health route

| Lines | Behavior |
|-------|----------|
| 8–21 | When `Route=Health`, runs `$DB->query("SELECT 1 AS ok")`, returns 200/503 plain text |

**Existing backend:** No HealthService. DB connectivity is implicit in every service call.

**Gap:** Health check is acceptable as infrastructure **if** it calls a thin domain helper (e.g. `Health::PingDb()`) rather than raw `$DB` in `orkui/`. Alternatively, move probe to `orkservice/` JSON endpoint — ops teams may prefer keeping a zero-auth route in `index.php` for load balancers.

**Note:** Runs before `$DONOTWEBSERVICE` / Session bootstrap — intentional for liveness.

#### T-INF-02: Event/index legacy redirect

| Lines | Behavior |
|-------|----------|
| 69–76 | `SELECT name, kingdom_id FROM ork_event WHERE event_id = ?` → 302 to `Reports/event_attendance/Kingdom/{kid}&filter={name}` |

**Existing backend:** `Event::GetEvent`, `SearchService::Event` — name and kingdom available via API.

**Gap:** One read API call (or shared redirect helper in domain) replaces inline SQL. Preserve 302 when event missing (current: no redirect, falls through to controller).

### 1.4 Frontend violations — `class.Controller.php` (base)

*File lives under `system/lib/system/` but is **frontend infrastructure** — every `Controller_*` extends it.*

#### T-INF-03: `__construct` (session token)

| Lines | Behavior |
|-------|----------|
| 40–68 | For logged-in users (except AJAX/login skip list), `SELECT token FROM ork_mundane` — if mismatch, destroy session and redirect to login with `msg=session_replaced` |

**Existing backend:** `Authorization::IsAuthorized($token)` validates token exists and user not penalized — **does not** compare session token to DB for multi-device logout.

**Gap:** New domain method e.g. `Authorization::ValidateSessionToken($mundaneId, $token): bool` (or `Session::AssertCurrentToken`) encapsulates the SELECT + compare. Controller calls via `APIModel('Authorization')` or lightweight JSONModel wrapper.

**Skip list:** Login + seven Ajax controllers skip check — preserve list when moving logic.

#### T-INF-04: `__construct` (font prefs)

| Lines | Behavior |
|-------|----------|
| 73–85 | `SELECT basic_fonts, dyslexia_fonts FROM ork_mundane` → `ViewerBasicFonts`, `ViewerDyslexiaFonts` template data |

**Existing backend:** `Player::GetPlayer` returns `BasicFonts`, `DyslexiaFonts` in player payload — heavy for a two-column read on every page.

**Gap:** Add `Player.GetViewerPreferences` (or extend existing slim profile endpoint) returning only font flags. Cache in session after first load optional optimization for R-13.

#### T-INF-05: `index()` (home kingdom)

| Lines | Behavior |
|-------|----------|
| 137–152 | Join `ork_mundane` → `ork_park` → `ork_kingdom` for `UserKingdomId`, `UserParentKingdomId` on home page |

**Existing backend:** `Player::GetPlayer` / `player_info` include park/kingdom context; kingdom domain has family lookups.

**Gap:** `Player.GetHomeKingdom($mundaneId)` returning `{ KingdomId, ParentKingdomId }` — single domain query, consumed from `Controller::index()` via model.

#### T-INF-06: `index()` (RSVP widget counts)

| Lines | Behavior |
|-------|----------|
| 166–184 | After `Search_Event`, batch `SELECT event_calendardetail_id, COUNT(*) … FROM ork_event_rsvp GROUP BY …` to attach `RsvpCount` |

**Existing backend:** None registered; DS-01 proposes `Event.GetRsvpCountsBatch`.

**Gap:** **Do not implement separate SQL in R-13** — wire home widget to DS-01 API when R-01 lands, or implement batch method once in R-13 if R-01 is delayed (coordinate: prefer R-01 first).

### 1.5 Frontend violations — `controller.WnAjax.php`

#### T-WN-01: `dismiss`

| Lines | Behavior |
|-------|----------|
| 17–19 | `INSERT IGNORE INTO ork_whats_new_seen (mundane_id, version)` |

**Existing backend:** Table used in `Player` merge path and `Report` KPI queries — **no** dismiss/read API.

**Gap:** `Player.DismissWhatsNew($mundaneId, $version)` and `Player.HasSeenWhatsNew($mundaneId, $version): bool` (or combined `GetWhatsNewState`). Replace template `$DB` read in `default.theme` (lines 673–677) with controller-provided flag set in `__construct` or View data.

**Security:** Version is sanitized with `preg_replace` in controller — domain must re-validate alphanumeric + hyphen/underscore.

### 1.6 Backend surface (existing)

| Layer | Location | Relevant to R-13 |
|-------|----------|------------------|
| Domain | `class.Authorization.php` | `IsAuthorized`, `HasAuthority` — no session-equality check |
| Domain | `class.Player.php` | Font fields on GetPlayer/update; merge handles `whats_new_seen` |
| Domain | `class.Event.php` | `GetEvent` for redirect |
| Domain | `class.Event.php` (DS-01) | Planned RSVP batch counts |
| Service | `AuthorizationService` | No `ValidateSessionToken`, no `HasAuthority` JSON |
| Service | `PlayerService` | Partial player reads exist |
| Tests | — | No infrastructure/session/whats-new tests |

### 1.7 Cross-milestone overlaps

| Pattern | Also in | Notes |
|---------|---------|-------|
| RSVP batch counts | DS-01 / R-01 | T-INF-06 defers to `GetRsvpCountsBatch` |
| `HasAuthority` in `Controller::__construct` | DS-14 | Menu admin links — R-14 policy, not R-13 |
| Template `$DB` | R-18 / Phase 3 audit | `default.theme` whats_new read moves with T-WN-01 (done R-13) |

---

## 2. Test design

### 2.1 Backend unit/integration tests (implement in T-13)

Add `tests/Unit/HealthTest.php`:

| Test case | Target | Validates |
|-----------|--------|-----------|
| `testPingDbReturnsTrueWhenConnected` | T-INF-01 | Domain ping succeeds against test DB |
| `testPingDbReturnsFalseWhenDbDown` | T-INF-01 | Graceful false (mock or skip) |

Add `tests/Integration/SessionTokenTest.php`:

| Test case | Target | Validates |
|-----------|--------|-----------|
| `testValidateSessionTokenMatches` | T-INF-03 | Current token → true |
| `testValidateSessionTokenRejectsStale` | T-INF-03 | Old token after re-login → false |

Add `tests/Integration/ViewerPreferencesTest.php`:

| Test case | Target | Validates |
|-----------|--------|-----------|
| `testGetViewerPreferences` | T-INF-04 | Returns basic/dyslexia flags |
| `testGetHomeKingdom` | T-INF-05 | Kingdom + parent IDs for seeded player |

Add `tests/Integration/WhatsNewTest.php`:

| Test case | Target | Validates |
|-----------|--------|-----------|
| `testDismissWhatsNewIdempotent` | T-WN-01 | Double dismiss no error |
| `testHasSeenWhatsNew` | T-WN-01 + template | Unseen → false; after dismiss → true |

Add `tests/Integration/LegacyRedirectTest.php`:

| Test case | Target | Validates |
|-----------|--------|-----------|
| `testEventIndexRedirectLookup` | T-INF-02 | Known event → correct kingdom_id + name encoding |

Skip integration tests when `ork3_test_db_available()` is false.

### 2.2 Infection scope (T-13, DS-7)

```bash
sh bin/run-infection.sh \
  --filter=class.Authorization.php \
  --filter=class.Player.php \
  --test-framework-options="--filter=HealthTest|SessionTokenTest|ViewerPreferencesTest|WhatsNewTest|LegacyRedirectTest"
```

Focus mutators on: token equality branch, INSERT IGNORE path, font int casts, kingdom join NULL handling.

### 2.3 Frontend functional tests (implement in T-13)

| Flow | Steps | Assert |
|------|-------|--------|
| Health probe | GET `Route=Health` | 200 + `OK`; stop DB → 503 |
| Session replaced | Login A; login B same user; refresh A | Redirect login + `session_replaced` |
| Font prefs | Toggle dyslexia fonts; load any page | Template receives correct flags |
| Home kingdom | Logged-in home | `UserKingdomId` matches profile park |
| Event legacy URL | GET `Event/index/{id}` | 302 to reports filter |
| What’s New | Login with unseen version | Modal shows; dismiss → hidden on reload |
| Home RSVP counts | Home with upcoming events | Event cards show counts matching event page |

---

## 3. Proposed revision

### 3.1 Principle

Infrastructure reads/writes move to **small, cache-friendly domain methods** exposed via existing services. `orkui/index.php` may retain routing for health/redirect but must not embed SQL. `Controller` base becomes a consumer of `Model_*` / JSONModel — same as other controllers.

### 3.2 New domain / service API (R-13)

| Proposed method | Maps from | Returns |
|-----------------|-----------|---------|
| `Health.PingDb` | T-INF-01 | `{ Ok: bool }` — or keep in startup with domain one-liner |
| `Event.GetEventSummaryForRedirect` | T-INF-02 | `{ Name, KingdomId }` or reuse `GetEvent` slim fields |
| `Authorization.ValidateSessionToken` | T-INF-03 | `{ Valid: bool }` |
| `Player.GetViewerPreferences` | T-INF-04 | `{ BasicFonts, DyslexiaFonts }` |
| `Player.GetHomeKingdom` | T-INF-05 | `{ KingdomId, ParentKingdomId }` |
| `Event.GetRsvpCountsBatch` | T-INF-06 | *(from DS-01)* — do not duplicate |
| `Player.DismissWhatsNew` | T-WN-01 | `{ Status }` |
| `Player.GetWhatsNewSeen` | default.theme | `{ Seen: bool }` for current `WHATS_NEW_VERSION` |

Register JSON endpoints where AJAX or high-frequency reads benefit; SOAP optional for Health (often unnecessary).

### 3.3 Frontend replacement (R-13)

| File | Change |
|------|--------|
| `orkui/index.php` | T-INF-01 → domain ping; T-INF-02 → `JSONModel('Event')` or inline service include |
| `class.Controller.php` | T-INF-03–05 via model calls; remove `$DB`; T-INF-06 → DS-01 batch API |
| `controller.WnAjax.php` | T-WN-01 → `Model_Player::dismiss_whats_new()` |
| `default.theme` | Remove `$DB` whats_new SELECT; use `$this->__data['ShowWhatsNew']` from controller |

### 3.4 Sequencing

1. Implement domain methods + tests (session, prefs, whats_new, redirect lookup).
2. Wire Controller base — highest regression risk; run full functional smoke.
3. T-INF-06 last — blocked on or coordinated with R-01 RSVP batch API.
4. Health route last or first (lowest user impact) — ops validation required.

### 3.5 Non-goals (R-13)

- Moving `HasAuthority` menu gates (DS-14 / R-14).
- Refactoring `orkui/index.php` session timing / `Ork3::$Lib->session` (not `$DB` violations).
- Template audit beyond whats_new → **R-18** / Phase 3 audit ([10-phase-2-continuation.md](./10-phase-2-continuation.md)).

**Post-rebase (RB-D4, 2026-07-09):** §1 line ranges verified against `orkui/` and `class.Controller.php` at base `e6417645` (`origin/master`). Minor drift in event redirect (69–76), session token skip list (40–68), RSVP widget batch (166–184), whats_new template read (673–677); no upstream gap closures; §3 revision unchanged.

---

## 4. Exit criteria checklist

- [ ] Backend survey complete (§1)
- [ ] Test design documented (§2)
- [ ] Proposed API revision documented (§3)
- [ ] Cross-refs to DS-01 (T-INF-06) and DS-14 (menu HasAuthority) recorded

---

## Related documents

| Doc | Link |
|-----|------|
| Implementation plan | [03-implementation-plan.md](./03-implementation-plan.md) |
| Test framework | [06-test-framework.md](./06-test-framework.md) |
| DS-14 lib-service discovery | [ds-14-lib-service-discovery.md](./ds-14-lib-service-discovery.md) |
| [validations/v-13-infrastructure-validation.md](./validations/v-13-infrastructure-validation.md) | Phase 1.6 — canary URLs + test mutation boundaries (V-13) |
