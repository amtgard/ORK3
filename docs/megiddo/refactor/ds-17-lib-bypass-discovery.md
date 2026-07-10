# DS-17: Residual Domain Lib Bypass — Discovery Design Note

**Milestone:** DS-17  
**Branch:** `megiddo/ds-17-lib-bypass-discovery` (retroactive — backfill 2026-07-10)  
**Target IDs:** T-EVT-08 (weather templates), T-KNG-11, T-PRK-05, T-PLR-08 (auth done R-15), T-RPT-02, T-UNT-02, T-UNT-03; Principality (no DS row — APIModel-only)  
**Depends on:** DS-14 (§1.5), R-15 (auth), R-16 (ghettocache)  
**Execution sprint:** R-17  
**Test sprint:** T-17

---

## 1. Backend survey

### 1.1 Scope summary

After R-14 (shared APIs) and R-15/R-16 (auth + cache), **~34 files** still held `Ork3::$Lib` domain helper calls (~203 sites at carryover audit on `76758e2c`). R-17 targets **residual domain lib bypass** on controllers and models deferred from R-05 … R-12, plus weather template violations.

| Lib | Key R-17 targets | Migration approach |
|-----|------------------|-------------------|
| `player` | `Controller_Park`, `Controller_Unit`, `Model_Player` | `Model_Player` wrappers → `Player` domain JSON |
| `kingdom` | `Controller_Kingdom`, `Controller_Unit` | `Model_Kingdom` → `KingdomProfile` APIs |
| `weather` | Event/Park/Attendance templates | `wx_*` helpers in `wx_safety_helpers.php` (no template lib calls) |
| `park` | `Controller_Reports` | `Model_Reports::GetParkKingdomId` |
| `dangeraudit` | `Controller_Unit` officer grant | `Dangeraudit` domain on auth add (T-UNT-02) |
| `event` | `Controller_Attendance` | `Event` domain `GetActiveEventsAtScope` |

**Source inventory:** [ds-14-lib-service-discovery.md §1.5](./ds-14-lib-service-discovery.md#15-cross-cutting-other-ork3lib-domains) — this doc consolidates the R-17 execution slice.

### 1.2 Per-target detail

#### T-EVT-08 — Event weather templates

| Location | Pre-R-17 | Post-R-17 |
|----------|----------|-----------|
| `Eventnew_index.tpl`, park/event calendar templates | `Ork3::$Lib->weather` inline | `wx_daily_summary`, `wx_play_for_date` helpers |
| `Controller_Event` | Residual weather lib | Thinned — helpers precompute in controller |

#### T-KNG-11 — Kingdom circle awards

| Location | Pattern |
|----------|---------|
| `Controller_Kingdom` | `player->GetCircleAwardIds` → `Model_Player` wrapper |

#### T-PRK-05 — Park weather + awards

| Location | Pattern |
|----------|---------|
| `Controller_Park` | `weather->for_park`, `GetCircleAwardIds` → models |

#### T-PLR-08 — Player auth

Auth completed in R-15; R-17 confirms no residual `player` lib on auth paths.

#### T-RPT-02 — Reports park kingdom

| Location | Pattern |
|----------|---------|
| `Controller_Reports` | `GetParkKingdomId` → `Model_Reports` |

#### T-UNT-02 / T-UNT-03 — Unit officer + scope

| Location | Pattern |
|----------|---------|
| `Controller_Unit` officer grant | `dangeraudit` → domain write |
| `Controller_Unit` index | `player_info`, `GetKingdoms` → model wrappers |

#### Principality

`controller.Principality.php` — already APIModel-only at sign-off; no R-17 code change required.

### 1.3 Model wrappers (idiomatic ORK3)

| Model | Wraps |
|-------|-------|
| `Model_Player` | `Player` domain helpers (`GetCircleAwardIds`, `player_info`, …) |
| `Model_Weather` | `WeatherService` / weather archive |
| `Model_Reports` | `Report` domain (`GetParkKingdomId`, …) |
| `Model_Kingdom` | `KingdomProfile` lookups |

### 1.4 Weather view helpers

New `wx_safety_helpers.php` + `wx_coords_for_calendar_detail` (R-18 adds coords helper) — templates call PHP functions that internally use `JSONModel`, not `Ork3::$Lib`.

### 1.5 Carryover

Deferred lib sites (`searchservice`, `heraldry`, `index.php` model paths) and all residual `$DB` → **R-18** / **R-19** per Phase 3 audit.

**Pre-R-17 metric:** 34 files / ~203 `Ork3::$Lib` sites in `orkui/` (carryover audit). R-17 closes targeted controller/model/template bypass; full zero-lib exit requires R-19a…d.

---

## 2. Test design

### 2.1 Backend unit/integration tests (T-17)

Reuse prior domain tests — R-17 wiring changes only:

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Integration/WeatherServiceTest.php` | Integration | Weather service paths (T-LIB-02 overlap) |
| `tests/Integration/PlayerProfileTest.php` | Integration | Player model wrappers |
| `tests/Integration/ReportTest.php` | Integration | Report domain |
| `tests/Integration/KingdomProfileTest.php` | Integration | Kingdom helpers |
| `tests/Integration/AuthorizationLibTest.php` | Integration | Auth regression (R-15 guard) |

**Regression command (R-17 sign-off):**

```bash
sh bin/run-unit-tests.sh
# 215 passed, 2 skipped
```

### 2.2 Infection scope (T-17, DS-7)

V-14 §2.4 pass A + B on touched domains:

```bash
sh bin/run-infection.sh --configuration=infection.t14-lib-auth-era.json5 ...
sh bin/run-infection.sh --configuration=infection.t14-lib-live-weather.json5 ...
```

**R-17 sign-off result:** pass A MSI **18%**, pass B MSI **27%**.

### 2.3 Frontend functional tests (T-17)

| Test file | Flow | Assert |
|-----------|------|--------|
| `tests/e2e/event-detail.spec.ts` | Event index + RSVP | Weather block renders via helpers |
| `tests/e2e/player-profile.spec.ts` | Player profile | Circle awards / profile load |
| `tests/e2e/reports.spec.ts` | Voting eligible report | Park kingdom scope correct |
| Auth smoke | Login preflight | — |

**R-17 sign-off result:** `event-detail.spec.ts` **3/3**, `player-profile.spec.ts` **2/2**, `reports.spec.ts` **4/4** pass.

### 2.4 Template static check

```bash
rg 'Ork3::\$Lib' orkui/template/
# → zero weather/auth lib calls on migrated templates post-R-17
```

---

## 3. Proposed revision

### 3.1 Principle

**No `Ork3::$Lib` in migrated controllers, models, or templates** for R-17 target IDs. Domain logic stays in `orkservice/*`; frontend uses model wrappers or view helpers.

### 3.2 Migration phases (executed in R-17)

| Phase | Work |
|-------|------|
| **1** | Add/extend model wrappers (`Model_Player`, `Model_Weather`, `Model_Reports`, `Model_Kingdom`) |
| **2** | Thin `Controller_Kingdom`, `Controller_Park`, `Controller_Reports`, `Controller_Unit` |
| **3** | Replace template weather lib with `wx_*` helpers |
| **4** | Fuzzy gate on event/player/reports hosts |

### 3.3 Non-goals

- Eliminating all `Ork3::$Lib` in `orkui/` (41 sites remain for R-19a…d per Phase 3 audit).
- Residual `$DB` (R-18).
- Changing weather API semantics.

---

## 4. Exit criteria checklist

- [x] Backend survey complete (§1)
- [x] Test design documented (§2)
- [x] Model wrapper policy documented (§3)
- [x] Per-target mapping from DS-14 §1.5
- [x] Weather template helper strategy recorded

---

## Related documents

| Doc | Link |
|-----|------|
| Parent inventory | [ds-14-lib-service-discovery.md §1.5](./ds-14-lib-service-discovery.md#15-cross-cutting-other-ork3lib-domains) |
| Validation | [validations/v-17-lib-bypass-validation.md](./validations/v-17-lib-bypass-validation.md) |
| Implementation plan | [03-implementation-plan.md](./03-implementation-plan.md) |
| Gap audit | [p3-backfill-tvds-audit.md](./p3-backfill-tvds-audit.md) |
