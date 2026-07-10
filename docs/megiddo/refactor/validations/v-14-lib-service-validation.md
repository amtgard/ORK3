# V-14: Ork3::$Lib Service Migration — Validation Artifacts

**Milestone:** V-14  
**Branch:** `megiddo/v-14-lib-service-validation`  
**Target IDs:** T-LIB-01 through T-LIB-05; cross-cutting HasAuthority / remaining `Ork3::$Lib` policy  
**Depends on:** DS-14, T-14, V-00  
**Execution sprint:** R-14 (T-LIB-01–05); **continuation:** R-15 … R-18 reuse §2 boundaries per [10-phase-2-continuation.md](../10-phase-2-continuation.md)  
**Discovery source:** [ds-14-lib-service-discovery.md §1](../ds-14-lib-service-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

Lib-service targets are **JSON/API-heavy** (`Live/stats`, `EraPhoenice/today`) plus Weather and Tournament page hosts. `live-stats` and `era-phoenice-api` stay `skip: true` (volatile / non-visual). R-14 fuzzy gate uses **weather + tournament** hosts; HasAuthority menu chrome is covered via home/admin hosts from prior V-* when needed.

### 1.1 Page registry entries

| pageId | Route | auth | Target IDs | Capture |
|--------|-------|------|------------|---------|
| `weather` | `./index.php?Route=Weather` | login | T-LIB-02 | V-00 — re-validate / refresh |
| `tournament` | `./index.php?Route=Tournament` | login | T-LIB-04 | V-00 — re-validate / refresh |
| `live-stats` | `./index.php?Route=Live/stats` | login | T-LIB-01 | skip (volatile DOM) |
| `era-phoenice-api` | `./index.php?Route=EraPhoenice/today` | none | T-LIB-05 | skip (JSON API) |
| `home-authenticated` | `./index.php?Route=` | login | HasAuthority menu host | V-13 — optional re-validate |

No new `pages.json5` rows for V-14.

**Domain capture set:** `weather,tournament` (refresh on drift).  
**R-14 fuzzy gate:** `weather,tournament`

### 1.2 Canary matrix

| Surface | Variant A | Variant B |
|---------|-----------|-----------|
| Weather dashboard | `weather` | — |
| Tournament admin flag | `tournament` | — |
| Live stats / recent | T-14 `LiveServiceTest` + e2e | skip visual |
| Era Phoenice JSON | T-14 `EraPhoeniceTest` + e2e | skip visual |
| HasAuthority gates | T-14 `AuthorizationLibTest` | home/admin hosts optional |

**Sandbox pins:** weather stubs / tournament park from session.  
**Mirror:** live weather + tournament data (lenient thresholds).

### 1.3 Record / validate

```bash
bin/fuzzy-validator validate --pages weather,tournament --phase all
```

**V-14 capture result:** re-recorded `weather` / `tournament` (mirror drift); validate exit **0** (4/4).

---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-14)

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Integration/AuthorizationLibTest.php` | Integration | HasAuthority scopes (T-LIB-03/04 + cross-cut) |
| `tests/Integration/LiveServiceTest.php` | Integration | T-LIB-01 stats / recent shape |
| `tests/Integration/WeatherServiceTest.php` | Integration | T-LIB-02 daily / play / archive |
| `tests/Unit/EraPhoeniceTest.php` | Unit | T-LIB-05 date math + JSON shape |
| `tests/e2e/lib-service.spec.ts` | e2e | Live, Weather, Era, tournament smoke |

**Infection:**  
- Pass A: `infection.t14-lib-auth-era.json5` (MSI floor 15%)  
- Pass B: `infection.t14-lib-live-weather.json5` (MSI floor 15%)

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| `AuthorizationLibTest` | JSON `HasAuthority` wrapper | Direct `Ork3::$Lib->authorization` removed |
| `LiveServiceTest` | LiveService registration | Controller → JSONModel('Live') |
| `WeatherServiceTest` | WeatherService method names | Lib calls → service |
| `EraPhoeniceTest` | Service JSON vs static | Controller becomes thin proxy |
| `lib-service.spec.ts` | Response keys / CanEdit flag | Adapter during migration |
| Cross-sprint HasAuthority sites | Partial R-14 | Domain R-* replace call sites incrementally |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-14 | Not allowed |
|----------|---------------------|-------------|
| **Domain / service** | LiveService, WeatherService, EraPhoeniceService, Authorization.HasAuthority JSON | Change HasAuthority role inheritance / KPM semantics |
| **Controllers** | Replace T-LIB-01–05 + base menu gates with JSONModel | Leave new `Ork3::$Lib` call sites in migrated files |
| **Cross-sprint** | Policy for ghettocache; defer full bust migration to R-06–R-12 | Re-implement domain SQL owned by other R-* |
| **Templates** | Precompute CanEdit* flags in controllers | Keep template `Ork3::$Lib` for migrated gates |
| **Fuzzy** | Re-record weather/tournament on intentional UI change; keep live/era skips | Un-skip `live-stats` without stabilizing volatile DOM |
| **Infection** | Two-pass A/B filters as services land | MSI below T-14 floors without justification |

### 2.4 Post-R-14 Infection scope

```bash
# Pass A — Authorization + EraPhoenice
sh bin/run-infection.sh \
  --configuration=infection.t14-lib-auth-era.json5 \
  --only-covered \
  --filter=class.Authorization.php \
  --filter=class.EraPhoenice.php \
  --test-framework-options="--filter=AuthorizationLibTest|EraPhoeniceTest"

# Pass B — Live + Weather
sh bin/run-infection.sh \
  --configuration=infection.t14-lib-live-weather.json5 \
  --only-covered \
  --filter=class.Live.php \
  --filter=class.Weather.php \
  --test-framework-options="--filter=LiveServiceTest|WeatherServiceTest"
```

---

## 3. R-14 sign-off checklist

- [x] §1 page ids pass `bin/fuzzy-validator validate --phase all` (test + mirror)
- [x] Test edits within §2.3
- [x] Full unit suite green
- [x] Infection per §2.4 (both passes)
- [x] No new `Ork3::$Lib` in Live / Weather / Tournament / EraPhoenice / CalendarItemAjax / Controller base menu paths for migrated targets

**R-15 … R-18:** Dedicated validation docs published in BACKFILL hop — [v-15](./v-15-hasauthority-validation.md), [v-16](./v-16-ghettocache-validation.md), [v-17](./v-17-lib-bypass-validation.md), [v-18](./v-18-residual-db-validation.md). Reuse §2.3–2.4 boundaries where scope overlaps. Summary sign-off retained below; full gate detail in each V-* doc.

### R-15 (2026-07-10) — see [v-15-hasauthority-validation.md §3](./v-15-hasauthority-validation.md#3-r-15-sign-off-checklist)

- [x] Controllers/AJAX: zero `Ork3::$Lib->authorization->HasAuthority` in `orkui/` — all gates use `Model_Authorization::has_authority` / `AuthorizationGate`
- [x] Templates: precomputed auth flags in controllers; zero auth `Ork3::$Lib` in `orkui/template/`
- [x] Infection pass A MSI 18%; fuzzy gate `admin-permissions,kingdom-auth-sandbox,park-auth-sandbox,player-profile` 8/8

### R-16 (2026-07-10) — see [v-16-ghettocache-validation.md §3](./v-16-ghettocache-validation.md#3-r-16-sign-off-checklist)

- [x] Zero `Ork3::$Lib->ghettocache` in `orkui/` — read-through cache in domain (`Player`, `Award`, `SearchService`, `KingdomProfile`, `ParkProfile`, `Report`); write bust on domain writes (`Event`, `Report`, `Park`, `Kingdom`, `Heraldry`, `Attendance`)
- [x] Infection pass A MSI 18%, pass B MSI 27%; fuzzy gate `kingdom-profile,park-auth-sandbox,reports-ladder-grid` 6/6

### R-17 (2026-07-10) — see [v-17-lib-bypass-validation.md §3](./v-17-lib-bypass-validation.md#3-r-17-sign-off-checklist)

- [x] T-EVT-08, T-KNG-11, T-PRK-05, T-PLR-08, T-RPT-02, T-UNT-02/03 domain lib bypass removed from target controllers; Event/Park/Attendance weather templates use `wx_*` helpers (no `Ork3::$Lib` in templates)
- [x] Infection pass A MSI 18%, pass B MSI 27%; fuzzy gate `event-index-rsvp,player-profile,reports-voting-eligible` 6/6 (re-recorded baselines)

### R-18 (2026-07-10) — see [v-18-residual-db-validation.md §3](./v-18-residual-db-validation.md#3-r-18-sign-off-checklist)

- [x] Zero `$DB->` in `orkui/` — domain APIs on `Player`, `Dangeraudit`, `Weather`, `ParkProfile`, `Event`, `Administration`; `nav_view_helpers.php` + `wx_coords_for_calendar_detail`
- [x] Infection spot-check: Player MSI 20%, DangerAudit MSI 50%; fuzzy V-00 active pages 34/34 pass (17 × 2 profiles)
