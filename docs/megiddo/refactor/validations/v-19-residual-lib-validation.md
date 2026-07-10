# V-19: Residual Ork3::$Lib Bypass — Validation Artifacts

**Milestone:** V-19  
**Branch:** `megiddo/v-19-residual-lib-validation`  
**Target IDs:** T-LIB-06 through T-LIB-17 (41 sites in 12 files)  
**Depends on:** DS-19, T-19, V-00, V-14  
**Execution sprints:** R-19a → R-19b → R-19c → R-19d (3 files per hop)  
**Discovery source:** [ds-19-residual-lib-discovery.md §1](../ds-19-residual-lib-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

Residual lib migration spans **player model wrappers**, **index bootstrap**, **kingdom/event/admin AJAX**, **search**, and **admin dashboard SoA**. No new `pages.json5` rows — reuse V-00 active hosts per hop.

### 1.1 Page registry entries (per hop)

| Hop | pageId | Route | auth | Target IDs |
|-----|--------|-------|------|------------|
| R-19a | `player-profile` | `./index.php?Route=Player&PlayerId=…` | login | T-LIB-06 milestones / read helpers |
| R-19a | `kingdom-auth-sandbox` | kingdom admin auth surface | login | T-LIB-08 kingdom + search + audit |
| R-19a | `home-authenticated` | `./index.php?Route=` | login | T-LIB-07 session timing / bootstrap |
| R-19b | `event-index-rsvp` | `./index.php?Route=Event&EventId=…` | login | T-LIB-09 heraldry + search |
| R-19b | `admin-dashboard` | `./index.php?Route=Admin` | login | T-LIB-10, T-LIB-11 weather |
| R-19b | `admin-permissions` | `./index.php?Route=Admin/permissions` | login | T-LIB-10 dangeraudit |
| R-19c | `park-auth-sandbox` | park admin auth surface | login | T-LIB-12 search + audit |
| R-19d | `admin-state-of-amtgard` | `./index.php?Route=Admin/state_of_amtgard` | login | T-LIB-17 SoA bootstrap |

**Health / legacy redirect (R-19a):** `GET index.php?Route=Health` and `?event_id=` legacy URL — covered by `HealthTest`, `LegacyRedirectTest`, `infrastructure.spec.ts` (no fuzzy capture; `skip: true` in registry).

**Domain capture set:** none (reuse V-00 baselines). Re-record only on intentional UI change.

### 1.2 Canary matrix

| Surface | R-19 hop | Variant |
|---------|----------|---------|
| Player milestones / profile | R-19a | `player-profile` |
| Kingdom admin + scoped search | R-19a | `kingdom-auth-sandbox` |
| Session / home bootstrap | R-19a | `home-authenticated` |
| Event heraldry + search | R-19b | `event-index-rsvp` |
| Admin weather + SoA | R-19b | `admin-dashboard`, `admin-permissions` |
| Park search + audit | R-19c | `park-auth-sandbox` |
| Universal search | R-19c | `search.spec.ts` (no fuzzy host) |
| Username / What's New / SoA widget | R-19d | `residual-lib.spec.ts`, `admin-state-of-amtgard` |

**Sandbox pins:** kingdom `100001`, park `1000001`, player sandbox id `1` per [v-00-fuzzy-setpoint.md](./v-00-fuzzy-setpoint.md).  
**Mirror:** same URL strings; lenient thresholds on dual-profile validate.

### 1.3 Record / validate (V-19 hop)

V-19 is **doc-only** — no capture/validate at sign-off. Per-hop gates run at R-19a…d sign-off (§2.5).

---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-19)

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Integration/PlayerServiceTest.php` | Integration | T-LIB-06, T-LIB-15–16 milestone CRUD, username, beltline, reconcile |
| `tests/Integration/HeraldryServiceTest.php` | Integration | T-LIB-09 `SetEventHeraldry` |
| `tests/Integration/StateOfAmtgardTest.php` | Integration | T-LIB-10, T-LIB-17 `GetPageBootstrap` |
| `tests/Integration/DangerAuditQueryTest.php` | Integration | T-LIB-08–10, T-LIB-12 audit on auth add |
| `tests/Integration/WeatherServiceTest.php` | Integration | T-LIB-11 admin refresh + api_stats |
| `tests/Integration/KingdomAjaxTest.php` | Integration | T-LIB-08 kingdom list / parks |
| `tests/Integration/SearchServiceTest.php` | Integration | T-LIB-12–14 scoped/universal/unit activity |
| `tests/Unit/HealthTest.php` | Unit | T-LIB-07 `PingDb` |
| `tests/Integration/LegacyRedirectTest.php` | Integration | T-LIB-07 legacy `?event_id=` redirect |
| `tests/Integration/WhatsNewTest.php` | Integration | T-LIB-16 dismiss |
| `tests/Integration/PlayerAjaxTest.php` | Integration | T-LIB-15 username check |
| `tests/e2e/residual-lib.spec.ts` | e2e | Cross-hop smoke (health, milestones, heraldry, search, username, WN) |
| `tests/e2e/player-profile.spec.ts` | e2e | R-19a player surfaces |
| `tests/e2e/kingdom-profile.spec.ts` | e2e | R-19a kingdom |
| `tests/e2e/infrastructure.spec.ts` | e2e | R-19a health / bootstrap |
| `tests/e2e/event-detail.spec.ts` | e2e | R-19b event |
| `tests/e2e/event-planning.spec.ts` | e2e | R-19b heraldry |
| `tests/e2e/admin-dashboard.spec.ts` | e2e | R-19b admin + weather |
| `tests/e2e/search.spec.ts` | e2e | R-19c universal search |
| `tests/e2e/park-profile.spec.ts` | e2e | R-19c park |

**Infection config:** [infection.t19-residual-lib.json5](../../../infection.t19-residual-lib.json5) — `minMsi` / `minCoveredMsi` **15%**; passes A–D per §2.4.

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| `PlayerServiceTest` | JSON method missing | PlayerService registration incomplete |
| `SearchServiceTest` | Empty results / wrong scope | ScopedPlayerSearch not on JSON |
| `DangerAuditQueryTest` | No audit row | Auth-add path still calls `$Lib->dangeraudit` |
| `HeraldryServiceTest` | Heraldry URL null | EventAjax still on `$Lib->heraldry` |
| `StateOfAmtgardTest` | Bootstrap payload shape | AdminDashboard still on `$Lib->stateofamtgard` |
| `HealthTest` / `LegacyRedirectTest` | Route failure | `index.php` still assigns `$Lib->session` |
| `KingdomAjaxTest` | Kingdom list empty | Kingdom wrappers not wired |
| `residual-lib.spec.ts` | AJAX 500 | Residual `$Lib` fatals after partial hop |
| Fuzzy gate pages | DOM missing blocks | Controller flags not precomputed post-migration |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-19a…d | Not allowed |
|----------|------------------------|-------------|
| **Domain / service** | Expose existing domain behavior via `JSONModel` / `APIModel` / `new Domain()` | Change search scope, audit semantics, heraldry rules, or milestone business logic |
| **Controllers / models** | Thin wrappers; one-liner model calls | New `Ork3::$Lib` call sites in touched files |
| **index.php** | Direct `Health::PingDb`, `Event::GetEventSummaryForRedirect`; request-scoped `$Session` without publishing via `$Lib->session` | Carve-out leaving `$Lib` in bootstrap (Path A zero exemptions) |
| **Search** | Register `ScopedPlayerSearch`, `UniversalSearch`, `GetUnitActivityCounts` on SearchService JSON | Re-implement SQL owned by domain |
| **Dangeraudit** | Fold into `Model_Authorization::add_auth` | Duplicate audit writes |
| **Cross-hop** | Prior-hop files must stay `$Lib`-free after each sign-off | Touch R-19b/c/d files during R-19a |
| **Fuzzy** | Re-record hop gate baselines on intentional UI change | Skip hop gate pages at sign-off |
| **Infection** | Per-hop passes A–D with MSI ≥ 15% | MSI below floor without justification |
| **Residual count** | `rg 'Ork3::\$Lib' orkui/` decreases per hop | Claim zero-lib before R-19d |

### 2.4 Infection scope + MSI floors (per R-19 hop)

Shared configuration: `--configuration=infection.t19-residual-lib.json5` (`minMsi` **15%**, `minCoveredMsi` **15%**).

**Pass A — R-19a** (player + bootstrap + kingdom):

```bash
sh bin/run-infection.sh \
  --configuration=infection.t19-residual-lib.json5 \
  --only-covered \
  --filter=class.Player.php \
  --filter=class.Health.php \
  --filter=class.Event.php \
  --filter=class.Kingdom.php \
  --test-framework-options="--filter=PlayerServiceTest|HealthTest|LegacyRedirectTest|Kingdom"
```

**Pass B — R-19b** (event/admin heraldry + weather + SoA + dangeraudit):

```bash
sh bin/run-infection.sh \
  --configuration=infection.t19-residual-lib.json5 \
  --only-covered \
  --filter=class.Heraldry.php \
  --filter=class.Weather.php \
  --filter=class.StateOfAmtgard.php \
  --filter=class.Dangeraudit.php \
  --test-framework-options="--filter=HeraldryServiceTest|WeatherServiceTest|StateOfAmtgard|Dangeraudit"
```

**Pass C — R-19c** (search):

```bash
sh bin/run-infection.sh \
  --configuration=infection.t19-residual-lib.json5 \
  --only-covered \
  --filter=class.SearchService.php \
  --test-framework-options="--filter=SearchServiceTest"
```

**Pass D — R-19d** (residual player + SoA dashboard):

```bash
sh bin/run-infection.sh \
  --configuration=infection.t19-residual-lib.json5 \
  --only-covered \
  --filter=class.Player.php \
  --filter=class.StateOfAmtgard.php \
  --test-framework-options="--filter=PlayerServiceTest|StateOfAmtgard|WhatsNew|PlayerAjax"
```

**R-19d final sign-off:** run passes **A + B + C + D** (full R-19 scope).

### 2.5 Fuzzy + Playwright gate table (per hop)

| Hop | Files | Static gate | Fuzzy gate (`validate --phase all`) | Playwright |
|-----|-------|-------------|-------------------------------------|------------|
| **R-19a** | `model.Player.php`, `index.php`, `KingdomAjax.php` | `rg 'Ork3::\$Lib'` on 3 files → exit 1; `rg '\$DB->' orkui/` → exit 1 | `player-profile,kingdom-auth-sandbox,home-authenticated` | `player-profile.spec.ts`, `kingdom-profile.spec.ts`, `infrastructure.spec.ts` |
| **R-19b** | `EventAjax.php`, `AdminAjax.php`, `Admin.php` | `rg 'Ork3::\$Lib'` on R-19b files → exit 1; R-19a files still clean | `event-index-rsvp,admin-dashboard,admin-permissions` | `event-detail.spec.ts`, `event-planning.spec.ts`, `admin-dashboard.spec.ts` |
| **R-19c** | `ParkAjax.php`, `SearchAjax.php`, `Search.php` | `rg 'Ork3::\$Lib'` on R-19c files → exit 1 | `park-auth-sandbox` | `search.spec.ts`, `park-profile.spec.ts` |
| **R-19d** | `PlayerAjax.php`, `WnAjax.php`, `model.AdminDashboard.php` | `rg 'Ork3::\$Lib' orkui/` → **zero**; `rg '\$DB->' orkui/` → exit 1 | **Full gate list** (§3) | Full suite per FIX-03 (§3) |

**Every hop:** `sh bin/run-unit-tests.sh` exit 0 · Infection per §2.4 for that hop.

**Playwright profiles (FIX-03):** mirror — `bin/ork-db use prod` + `ORK3_E2E_USERNAME=admin` + `npx playwright test tests/e2e/ --grep-invert heraldry`; sandbox heraldry — `bin/ork-db use dev` + `ORK3_E2E_USERNAME=megiddo` + `npx playwright test tests/e2e/heraldry.spec.ts`.

---

## 3. R-19d final sign-off — full gate list

After R-19d, **all 41** residual `$Lib` sites are gone. Gates below are **cumulative** across R-19a…d plus lib-specific hosts.

### 3.1 Static audit

```bash
rg 'Ork3::\$Lib' orkui/          # exit 1 — zero matches
rg '\$DB->' orkui/               # exit 1 — still zero (R-18)
sh bin/run-unit-tests.sh        # exit 0 — full suite
```

### 3.2 Fuzzy — full gate list

```bash
bin/fuzzy-validator validate --pages \
  player-profile,kingdom-auth-sandbox,home-authenticated,\
  event-index-rsvp,admin-dashboard,admin-permissions,admin-state-of-amtgard,\
  park-auth-sandbox \
  --phase all
```

**Expected:** **16/16** pass (8 pages × 2 profiles). Equivalently `validate --all --phase all` at VALIDATE-20.

### 3.3 Playwright — full suite (FIX-03)

```bash
# Mirror (no sandbox heraldry IDs)
bin/ork-db use prod
export ORK3_E2E_USERNAME=admin ORK3_E2E_PASSWORD=password
npx playwright test tests/e2e/ --grep-invert heraldry

# Sandbox heraldry
bin/ork-db use dev
export ORK3_E2E_USERNAME=megiddo ORK3_E2E_PASSWORD=test-db-player
npx playwright test tests/e2e/heraldry.spec.ts
```

Include `tests/e2e/residual-lib.spec.ts` in mirror run.

### 3.4 Infection — full R-19 scope

Run §2.4 passes **A, B, C, D** — each MSI ≥ **15%**.

### 3.5 R-19d sign-off checklist (template)

- [ ] `rg 'Ork3::\$Lib' orkui/` → **zero** (12 files clean)
- [ ] `rg '\$DB->' orkui/` → **zero**
- [ ] Full unit suite green
- [ ] Fuzzy §3.2 — **16/16** (or `--all` at VALIDATE-20)
- [ ] Playwright §3.3 — mirror + sandbox heraldry exit 0
- [ ] Infection passes A–D — MSI ≥ 15% each
- [ ] `03-implementation-plan.md` — R-19 complete summary
- [ ] [r-milestone-smoke-matrix.html](./r-milestone-smoke-matrix.html) — R-19a…d sections verified

**Next hop:** [VALIDATE-20](../skills/phase3-remediation/workers/VALIDATE-20.md) — full success-criteria re-audit (`--all` fuzzy, full Playwright, DML grep).

---

## 4. V-19 sign-off checklist

- [x] Validation doc published (this file) — §2.3, §2.4, §2.5, §3
- [x] Per-hop fuzzy + Playwright + Infection boundaries documented for R-19a…d
- [x] `infection.t19-residual-lib.json5` referenced (T-19)
- [x] `validations/r-milestone-smoke-matrix.html` — R-19a…d stubs
- [x] `04-milestone-checklist.md` § V-19 updated
- [x] Full unit suite green at V-19 sign-off
- [x] Branch `megiddo/v-19-residual-lib-validation` — one commit

---

## Related documents

| Doc | Link |
|-----|------|
| Design note | [ds-19-residual-lib-discovery.md](../ds-19-residual-lib-discovery.md) |
| Test sprint | T-19 @ `megiddo/t-19-residual-lib-tests` |
| Parent lib policy | [v-14-lib-service-validation.md](./v-14-lib-service-validation.md) |
| Global setpoint | [v-00-fuzzy-setpoint.md](./v-00-fuzzy-setpoint.md) |
| Phase 3 remediation | [skills/phase3-remediation/SKILL.md](../skills/phase3-remediation/SKILL.md) |
| Manual smokes | [r-milestone-smoke-matrix.html](./r-milestone-smoke-matrix.html) |
