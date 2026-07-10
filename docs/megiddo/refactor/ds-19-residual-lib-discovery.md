# DS-19: Residual Ork3::$Lib Bypass — Discovery Design Note

**Milestone:** DS-19  
**Branch:** `megiddo/ds-19-residual-lib-discovery`  
**Stack base:** `megiddo/p3-backfill-tvds-r14-r18` @ `7f9576eb`  
**Target IDs:** T-LIB-06 through T-LIB-17 (41 sites in 12 files)  
**Depends on:** R-17, R-18, Phase 3 audit ([phase3-audit-report.md](./phase3-audit-report.md) § P3-A)  
**Execution sprints:** R-19a → R-19b → R-19c → R-19d (3 files per hop)  
**Test sprint:** T-19

---

## 0. Inventory verification

```bash
rg -n 'Ork3::\$Lib' orkui/
```

| Metric | Value |
|--------|------:|
| Match lines | **41** |
| Files | **12** |
| Phase 3 audit | 42 (double-count on `KingdomAjax.php:624` — two `$Lib` refs on one line) |

**Files:**

| File | Lines | Count |
|------|-------|------:|
| `orkui/model/model.Player.php` | 83, 278, 283, 288, 311, 316, 321, 326, 331, 336, 341, 346 | 12 |
| `orkui/index.php` | 9, 65, 74–75, 117, 121, 125 | 7 |
| `orkui/controller/controller.KingdomAjax.php` | 596, 624 (×2), 635, 739, 763 | 5 lines / 6 refs |
| `orkui/controller/controller.EventAjax.php` | 293, 328, 417, 479, 668 | 5 |
| `orkui/controller/controller.AdminAjax.php` | 33, 62, 99 | 3 |
| `orkui/controller/controller.Admin.php` | 2335, 2342 | 2 |
| `orkui/controller/controller.ParkAjax.php` | 196, 380 | 2 |
| `orkui/controller/controller.SearchAjax.php` | 15 | 1 |
| `orkui/controller/controller.Search.php` | 107 | 1 |
| `orkui/controller/controller.PlayerAjax.php` | 32 | 1 |
| `orkui/controller/controller.WnAjax.php` | 18 | 1 |
| `orkui/model/model.AdminDashboard.php` | 127 | 1 |

No `$DB->` or raw DML in these paths (R-18 closed `$DB`; DML already domain-owned).

**Path A — zero exemptions:** [02-requirements.md](./02-requirements.md) § Success Criteria requires zero `Ork3::$Lib->{domain}` in `orkui/` with no carve-outs unless a human adds them later. R-19a…d must eliminate all 41 sites; VALIDATE-20 re-audit confirms `rg 'Ork3::\$Lib' orkui/` → zero.

---

## 1. Backend survey

### 1.1 Scope summary

Phase 2 (R-14 … R-18) and Phase 3 remediation FIX hops closed HasAuthority, ghettocache, domain lib bypass on *most* controllers, and all `$DB`. **41 residual `$Lib` call lines** remain — deferred carryover from DS-14 §1.5, DS-17 §1.5, and incomplete R-13 wiring on `index.php` / `WnAjax`.

| Domain / lib | Sites | Primary files | Notes |
|--------------|------:|---------------|-------|
| **player** | 14 | `model.Player.php`, `PlayerAjax`, `WnAjax` | Model wrappers still delegate to `$Lib->player`; R-17 migrated other player paths |
| **searchservice** | 6 | `KingdomAjax`, `EventAjax`, `AdminAjax`, `ParkAjax`, `SearchAjax`, `Search` | Scoped/universal search + unit activity counts — no JSON registration yet |
| **dangeraudit** | 5 | `KingdomAjax`, `EventAjax`, `AdminAjax`, `ParkAjax` | Auth-add side effects; domain exists (R-18) but AJAX still calls `$Lib` |
| **kingdom** | 3 | `KingdomAjax` | `GetParks`, `GetFamilyKingdomIds`, `GetKingdomParkTitles`, `GetKingdoms` |
| **index bootstrap** | 7 | `index.php` | Health ping, legacy event redirect, session timing on `$Lib->session` |
| **heraldry** | 1 | `EventAjax` | `SetEventHeraldry` — HeraldryService exists; one residual direct lib call |
| **weather** | 2 | `Admin.php` | Admin refresh + API stats |
| **stateofamtgard** | 2 | `AdminAjax`, `model.AdminDashboard` | SoA page bootstrap / admin tooling |

**Call chain today:** Controller or model → `Ork3::$Lib->{domain}->{method}()` → domain SQL/cache. Target: `JSONModel` / `APIModel` / thin domain `new Domain()` in models (idiomatic R-17 pattern) with **no** `$Lib` in `orkui/`.

### 1.2 Domain detail

#### searchservice (6 sites)

| Location | Method / action | Lib call |
|----------|-----------------|----------|
| `KingdomAjax` | player search | `ScopedPlayerSearch` |
| `EventAjax` | player search | `ScopedPlayerSearch` |
| `AdminAjax` | player search | `ScopedPlayerSearch` |
| `ParkAjax` | player search | `ScopedPlayerSearch` |
| `SearchAjax` | universal search | `UniversalSearch` |
| `Search` | unit activity | `GetUnitActivityCounts` |

**Existing backend:** `class.SearchService.php` domain; partial `SearchService` SOAP registration (Player, Park, Kingdom, Event, Unit, …) — **no** `ScopedPlayerSearch`, `UniversalSearch`, or `GetUnitActivityCounts` on JSON/SOAP.

**Gap:** Extend `SearchService` JSON with the three methods; controllers call `Model_Search` or `JSONModel('SearchService')`.

#### dangeraudit (5 sites)

| Location | Trigger |
|----------|---------|
| `KingdomAjax`, `EventAjax`, `AdminAjax`, `ParkAjax` | `Authorization::AddAuthorization` audit rows on role grant |

**Existing backend:** `Dangeraudit` domain + R-02/R-18 patterns on other addauth paths.

**Gap:** `Model_Authorization::add_auth` (or dedicated `Model_Dangeraudit::audit`) should invoke domain write; remove duplicate `$Lib->dangeraudit->audit` from AJAX controllers.

#### heraldry (1 site)

| Location | Call |
|----------|------|
| `EventAjax` ~668 | `heraldry->SetEventHeraldry` |

**Existing backend:** `HeraldryService` JSON (`Event` case maps to `SetEventHeraldry`).

**Gap:** Replace with `Model_Heraldry` / `JSONModel('HeraldryService')` — same as R-04 `RemoveEventHeraldry` pattern.

#### player model wrappers (12 sites)

| Lines | Method | Lib call |
|-------|--------|----------|
| 83 | cache bust | `bustRosterCachesForPlayer` |
| 278–288 | milestones CRUD | `AddCustomMilestone`, `UpdateCustomMilestone`, `DeleteCustomMilestone` |
| 311–346 | read helpers | `getCustomTitleAwardId`, `GetNotesCount`, `GetOfficerRoles`, `GetDisplayGrants`, `GetBeltlineForPlayer`, `GetReconcileAwardMap`, `CheckUsernameAvailable`, `GetAwardMaxRanks` |

**Existing backend:** `Player` domain class; partial `PlayerService` JSON (`GetPlayers`, `ReconcileAward`). R-17 migrated `player_info`, circle awards, etc. to `new Player()`.

**Gap:** Finish R-17 pattern — each wrapper calls `new Player()` or `PlayerService` JSON; register missing JSON methods in T-19/R-19a.

#### index.php bootstrap (7 sites)

| Lines | Behavior |
|-------|----------|
| 9 | `health->PingDb()` — load-balancer probe |
| 65 | `event->GetEventSummaryForRedirect` — legacy `?event_id=` redirect |
| 74–75, 117, 121, 125 | Assign `$Session` to `$Lib->session`; route timing stamps |

**Existing backend:** `Health::PingDb`, `Event::GetEventSummaryForRedirect` (R-13 domain); `SessionToken` domain. Tests in `HealthTest`, `LegacyRedirectTest`.

**Gap:** Health/redirect → domain one-liners or `JSONModel` (ops may keep plain-text Health route). Session timing → request-scoped `$Session` object **without** publishing via `Ork3::$Lib->session` (inject into controllers via existing startup/`class.Controller`).

#### kingdom ajax (5 lines / 6 refs)

| Lines | Call |
|-------|------|
| 624 | `GetParks` + `GetFamilyKingdomIds` |
| 635 | `GetKingdomParkTitles` |
| 739 | `ScopedPlayerSearch` |
| 763 | `GetKingdoms` |
| 596 | `dangeraudit->audit` |

**Gap:** `Model_Kingdom` wrappers (R-06/R-17 partial); search + audit per §1.2.

#### weather admin (2 sites)

| Location | Call |
|----------|------|
| `Admin.php` 2335 | `weather->AdminRefreshWithPrior` |
| `Admin.php` 2342 | `weather->api_stats(3)` |

**Existing backend:** `WeatherService` JSON from R-14 (dashboard paths).

**Gap:** Register admin-only `Weather.AdminRefreshWithPrior`, `Weather.GetApiStats` (or fold into existing WeatherService).

#### stateofamtgard (2 sites)

| Location | Call |
|----------|------|
| `AdminAjax` 99 | `$sor = Ork3::$Lib->stateofamtgard` |
| `model.AdminDashboard` 127 | `GetPageBootstrap` |

**Gap:** `StateOfAmtgardService` JSON for bootstrap + admin mutations; thin models.

#### player ajax / whats-new (2 sites)

| Location | Call |
|----------|------|
| `PlayerAjax` 32 | `CheckUsernameAvailable` |
| `WnAjax` 18 | `DismissWhatsNew` |

**Note:** R-13 added `Player::DismissWhatsNew` domain method but `WnAjax` still uses `$Lib`. `PlayerAjax` duplicates `Model_Player::check_username_available` lib path.

---

## 2. Test design

### 2.1 Backend unit/integration tests (implement in T-19)

| Test file | Target IDs | Validates |
|-----------|------------|-----------|
| `tests/Integration/SearchServiceTest.php` (extend) | T-LIB-12–14 | `ScopedPlayerSearch`, `UniversalSearch`, `GetUnitActivityCounts` shapes + auth scope |
| `tests/Integration/DangerauditTest.php` (extend) | T-LIB-08–10, T-LIB-12 | Audit row on auth add (kingdom/event/admin/park scopes) |
| `tests/Integration/HeraldryServiceTest.php` (extend) | T-LIB-09 | `SetEventHeraldry` via service |
| `tests/Integration/PlayerServiceTest.php` (extend) | T-LIB-06, T-LIB-15–16 | Milestone CRUD, username check, beltline, reconcile map, dismiss whats-new |
| `tests/Unit/HealthTest.php` (existing) | T-LIB-07 | `PingDb` — reuse for index bootstrap |
| `tests/Integration/LegacyRedirectTest.php` (existing) | T-LIB-07 | `GetEventSummaryForRedirect` |
| `tests/Integration/WeatherServiceTest.php` (extend) | T-LIB-11 | Admin refresh + api_stats |
| `tests/Integration/StateOfAmtgardTest.php` (new or extend Admin) | T-LIB-10, T-LIB-17 | `GetPageBootstrap` payload |

Skip integration tests when DB unavailable.

### 2.2 Frontend functional tests (implement in T-19)

| Flow | R-19 hop | Steps | Assert |
|------|----------|-------|--------|
| Kingdom player search | R-19a | Kingdom admin → scoped player search AJAX | JSON results; no lib in stack |
| Player milestones | R-19a | Player profile → add/edit/delete custom milestone | CRUD succeeds |
| Health probe | R-19a | `GET index.php?Route=Health` | 200 + `OK` |
| Legacy event redirect | R-19a | `?event_id=` legacy URL | 302 to canonical route |
| Event heraldry set | R-19b | Event planning → set heraldry | Image URL updated |
| Admin global auth + SoA | R-19b | Admin grant + SoA admin tab | Audit + bootstrap load |
| Weather admin refresh | R-19b | Admin weather panel → refresh | Stats JSON |
| Park/Kingdom auth grant audit | R-19c | Grant role via AJAX | Audit log entry |
| Universal search | R-19c | Search bar query | Results JSON |
| Username availability | R-19d | Player new / AJAX check | `{ available: bool }` |
| What's New dismiss | R-19d | Dismiss banner | Seen row persisted |
| Admin dashboard SoA widget | R-19d | Admin home | Bootstrap renders |

Reuse existing Playwright specs where possible (`search.spec.ts`, `player-profile.spec.ts`, `infrastructure.spec.ts`, `heraldry.spec.ts`, `auth-permissions.spec.ts`).

### 2.3 Infection scope (T-19, per R-19 hop)

**Pass A — R-19a (player + bootstrap + kingdom):**

```bash
sh bin/run-infection.sh \
  --filter=class.Player.php \
  --filter=class.Health.php \
  --filter=class.Event.php \
  --filter=class.Kingdom.php \
  --test-framework-options="--filter=PlayerServiceTest|HealthTest|LegacyRedirectTest|Kingdom"
```

**Pass B — R-19b (event/admin heraldry + weather + SoA):**

```bash
sh bin/run-infection.sh \
  --filter=class.Heraldry.php \
  --filter=class.Weather.php \
  --filter=class.StateOfAmtgard.php \
  --filter=class.Dangeraudit.php \
  --test-framework-options="--filter=HeraldryServiceTest|WeatherServiceTest|StateOfAmtgard|Dangeraudit"
```

**Pass C — R-19c (search):**

```bash
sh bin/run-infection.sh \
  --filter=class.SearchService.php \
  --test-framework-options="--filter=SearchServiceTest"
```

**Pass D — R-19d (residual player + SoA dashboard):**

```bash
sh bin/run-infection.sh \
  --filter=class.Player.php \
  --filter=class.StateOfAmtgard.php \
  --test-framework-options="--filter=PlayerServiceTest|StateOfAmtgard"
```

Document consolidated config in `infection.t19-residual-lib.json5` during T-19.

**T-19 config:** [infection.t19-residual-lib.json5](../../../infection.t19-residual-lib.json5) — shared `minMsi`/`minCoveredMsi` 15; run each pass with `--configuration=infection.t19-residual-lib.json5` plus the `--filter` / `--test-framework-options` pairs below.

### 2.4 Gate expectations (VALIDATE-20)

After R-19d:

- `rg 'Ork3::\$Lib' orkui/` → **zero**
- Full PHPUnit, fuzzy `--all`, Playwright (mirror + sandbox heraldry per FIX-03)

---

## 3. Proposed revision

### 3.1 Principle

**No `Ork3::$Lib` in `orkui/`** — including `index.php`, models, and all controllers. Domain libs remain implementation detail behind `orkservice/*` or idiomatic `new Domain()` inside model methods (R-17 pattern) where JSON overhead is unnecessary for server-side-only reads.

**Zero exemptions (Path A):** Do not add Success Criteria carve-outs in `02-requirements.md`. Session timing and health probe must migrate without leaving `$Lib` references.

### 3.2 Execution split (mandatory)

| Hop | Files | ~Sites | Primary domains |
|-----|-------|-------:|-----------------|
| R-19a | `model.Player.php`, `index.php`, `KingdomAjax.php` | 24 | player wrappers, session/health bootstrap, kingdom/search/dangeraudit |
| R-19b | `EventAjax.php`, `AdminAjax.php`, `Admin.php` | 10 | heraldry, dangeraudit, stateofamtgard, weather admin |
| R-19c | `ParkAjax.php`, `SearchAjax.php`, `Search.php` | 4 | searchservice, dangeraudit |
| R-19d | `PlayerAjax.php`, `WnAjax.php`, `model.AdminDashboard.php` | 3 | player username, whats-new, SoA bootstrap |

### 3.3 R-19a — `model.Player.php`, `index.php`, `KingdomAjax.php`

| Target ID | Surface | Migration |
|-----------|---------|-----------|
| T-LIB-06 | `Model_Player` (12 sites) | Replace `$Lib->player` with `new Player()` or `JSONModel('PlayerService')`; register JSON for milestone CRUD, beltline, reconcile map, award max ranks, notes count, officer roles, display grants, username check, roster cache bust |
| T-LIB-07 | `index.php` (7 sites) | `Health::PingDb()` / `Event::GetEventSummaryForRedirect()` direct domain calls; remove `$Lib->session` — use `$Session` local + controller injection for timing (`logtrace` reads `$Session->times`) |
| T-LIB-08 | `KingdomAjax` kingdom + search + audit | `Model_Kingdom` for `GetParks`, `GetFamilyKingdomIds`, `GetKingdomParkTitles`, `GetKingdoms`; `Model_Search::scoped_player_search`; fold dangeraudit into `Model_Authorization::add_auth` |

**Controller thinning:** KingdomAjax player-search and kingdom list actions become one-liner model calls; auth-add paths stop calling `$Lib->dangeraudit` directly.

### 3.4 R-19b — `EventAjax.php`, `AdminAjax.php`, `Admin.php`

| Target ID | Surface | Migration |
|-----------|---------|-----------|
| T-LIB-09 | `EventAjax` | `Model_Search::scoped_player_search`; `Model_Heraldry` / HeraldryService for `SetEventHeraldry`; dangeraudit via authorization model |
| T-LIB-10 | `AdminAjax` | Search + global admin audit + `Model_StateOfAmtgard` replacing `$Lib->stateofamtgard` |
| T-LIB-11 | `Admin.php` weather | `JSONModel('WeatherService')` admin methods `AdminRefreshWithPrior`, `GetApiStats` |

### 3.5 R-19c — `ParkAjax.php`, `SearchAjax.php`, `Search.php`

| Target ID | Surface | Migration |
|-----------|---------|-----------|
| T-LIB-12 | `ParkAjax` | `Model_Search::scoped_player_search`; dangeraudit via authorization model |
| T-LIB-13 | `SearchAjax` | `Model_Search::universal_search` → SearchService JSON |
| T-LIB-14 | `Search.php` | `Model_Search::get_unit_activity_counts` |

**New SearchService JSON methods (R-19c / T-19):**

| Method | Maps from |
|--------|-----------|
| `ScopedPlayerSearch` | Existing domain |
| `UniversalSearch` | Existing domain |
| `GetUnitActivityCounts` | Existing domain |

### 3.6 R-19d — `PlayerAjax.php`, `WnAjax.php`, `model.AdminDashboard.php`

| Target ID | Surface | Migration |
|-----------|---------|-----------|
| T-LIB-15 | `PlayerAjax` | Delegate to `Model_Player::check_username_available` (post R-19a lib-free) |
| T-LIB-16 | `WnAjax` | `Model_Player::dismiss_whats_new` → `Player::DismissWhatsNew` domain (R-13 gap) |
| T-LIB-17 | `Model_AdminDashboard` | `Model_StateOfAmtgard::get_page_bootstrap` → StateOfAmtgardService JSON |

### 3.7 Sequencing

1. **T-19** — tests + Infection config for APIs above (may `@group` skip until services registered).
2. **R-19a → R-19d** — serialized; each hop gates per [v-19-residual-lib-validation.md](./validations/v-19-residual-lib-validation.md) (V-19 hop).
3. **VALIDATE-20** — full Phase 3 re-audit; confirm zero lib + zero `$DB`.

### 3.8 Non-goals

- Changing search/heraldry/audit **semantics** — expose existing domain behavior only.
- New Success Criteria exemptions.
- Merging R-19 branches to integration until VALIDATE-20 passes.

---

## 4. Exit criteria checklist

- [x] Backend survey complete (§1) — 41 sites / 12 files verified
- [x] Test design documented (§2) — backend, Playwright, Infection per hop
- [x] Proposed revision with R-19a…d table (§3)
- [x] T-LIB-06 … T-LIB-17 tracked in [03-implementation-plan.md](./03-implementation-plan.md)
- [x] Path A zero-exemptions stated (§0, §3.1)
- [ ] Implementation (R-19a…d) — out of DS-19 scope

---

## Related documents

| Doc | Link |
|-----|------|
| Phase 3 audit | [phase3-audit-report.md](./phase3-audit-report.md) |
| DS-14 lib migration | [ds-14-lib-service-discovery.md](./ds-14-lib-service-discovery.md) |
| DS-17 domain bypass | [ds-17-lib-bypass-discovery.md](./ds-17-lib-bypass-discovery.md) |
| Continuation plan | [10-phase-2-continuation.md](./10-phase-2-continuation.md) § R-19a…d |
| Remediation orchestrator | [skills/phase3-remediation/orchestrator.prompt](./skills/phase3-remediation/orchestrator.prompt) |
