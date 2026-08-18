# DS-16: GhettoCache Migration — Discovery Design Note

**Milestone:** DS-16  
**Branch:** `megiddo/ds-16-ghettocache-discovery` (retroactive — backfill 2026-07-10)  
**Target IDs:** Ghettocache portion of T-EVT-08, T-KNG-11, T-RPT-02, T-PLM-03, T-ATT-06, T-SRC-01  
**Depends on:** DS-14 (§1.4 policy), R-15 (auth gates on same controllers)  
**Execution sprint:** R-16  
**Test sprint:** T-16

---

## 1. Backend survey

### 1.1 Scope summary

~55 `Ork3::$Lib->ghettocache` call sites existed across `orkui/` before R-16 (per DS-14 §1.4). R-16 moves **read-through cache** and **write bust** into domain/service methods; frontend calls domain APIs only.

| Pattern | Pre-R-16 examples | Post-R-16 policy |
|---------|-------------------|------------------|
| **Read-through** | `Controller_Kingdom.players_json`, `Controller_Park.park_players`, `Model_Player` detail | Cache inside `Player`, `KingdomProfile`, `ParkProfile`, `Report` domain methods |
| **Write bust** | Event search bust, player detail bust after attendance | Bust on domain write (`Event`, `Report`, `Park`, `Kingdom`, `Heraldry`, `Attendance`) |
| **Admin flush** | `KingdomAjax` memcache flush | Retained admin path or domain `Cache` API |

**Exit criterion:** `rg 'Ork3::\$Lib->ghettocache' orkui/` → **zero** (comment-only references in `Controller_Recap` acceptable).

**Source inventory:** [ds-14-lib-service-discovery.md §1.4](./ds-14-lib-service-discovery.md#14-cross-cutting-ghettocache).

### 1.2 Files migrated (R-16 sign-off)

| Layer | Files thinned |
|-------|---------------|
| Models | `model.Player.php`, `model.Award.php` |
| Controllers | `Controller_Search`, `Controller_Event`, `Controller_EventAjax`, `Controller_Reports`, `Controller_KingdomAjax`, `Controller_ParkAjax` |
| Domain | `Player`, `Award`, `SearchService`, `Event`, `Report`, `Park`, `Kingdom`, `Heraldry`, `Attendance` — cache read/bust internalized |

### 1.3 Per-target mapping

| Target ID | Controller / model | Cache behavior moved |
|-----------|-------------------|----------------------|
| T-EVT-08 | `Controller_Event`, `Controller_EventAjax` | Event list/search cache bust on write |
| T-KNG-11 | `Controller_Kingdom`, `Controller_KingdomAjax` | Kingdom player list read-through |
| T-RPT-02 | `Controller_Reports` | Ladder/roster cache bust |
| T-PLM-03 | `Model_Player` | Player detail read-through + bust |
| T-ATT-06 | `Model_Attendance` / attendance writes | Attendance bust paths |
| T-SRC-01 | `Controller_Search` | Search result cache |

### 1.4 Cross-milestone overlaps

| Pattern | Also in | Notes |
|---------|---------|-------|
| ghettocache bust | DS-12 T-ATT-06, DS-06 kingdom cache | Policy in DS-14; R-16 executes frontend removal |
| Player cache | DS-09, R-09 | `Model_Player` paths consolidated in R-16 |
| Search cache | DS-11, R-11 | `SearchService` owns keys after R-16 |

### 1.5 Carryover

Residual `player`/`kingdom`/`weather` **lib bypass** on same files → **R-17**. Residual `$DB` → **R-18**.

---

## 2. Test design

### 2.1 Backend unit/integration tests (T-16)

R-16 reuses existing domain and model tests — no new PHPUnit files at sign-off:

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Integration/PlayerProfileTest.php` | Integration | Player detail cache paths |
| `tests/Integration/ModelPlayerCacheTest.php` | Integration | Model cache behavior |
| `tests/Integration/KingdomProfileTest.php` | Integration | Kingdom player JSON |
| `tests/Integration/ReportTest.php` | Integration | Report ladder cache |
| Prior T-05/T-10/T-12 tests | Integration | Event, report, attendance domains |

**Regression command (R-16 sign-off):**

```bash
sh bin/run-unit-tests.sh
# 215 passed, 2 skipped
```

### 2.2 Infection scope (T-16, DS-7)

Reuse V-14 §2.4 both passes — touched domain classes include `Player`, `Report`, `Event`:

```bash
# Pass A — Authorization + EraPhoenice (regression guard)
sh bin/run-infection.sh --configuration=tools/infection/infection.t14-lib-auth-era.json5 ...

# Pass B — Live + Weather + cache-adjacent domains
sh bin/run-infection.sh --configuration=tools/infection/infection.t14-lib-live-weather.json5 ...
```

**R-16 sign-off result:** pass A MSI **18%**, pass B MSI **27%** (floors 15%/15%).

### 2.3 Frontend functional tests (T-16)

| Test file | Flow | Assert |
|-----------|------|--------|
| `tests/e2e/kingdom-profile.spec.ts` | Kingdom profile + players JSON | Cached player list renders |
| `tests/e2e/reports.spec.ts` | Ladder grid | Report grid loads after cache migration |
| Auth smoke | Login preflight | Session intact |

**R-16 sign-off result:** `kingdom-profile.spec.ts` **2/2**, `reports.spec.ts` **4/4** pass.

### 2.4 Static exit criterion

```bash
rg 'Ork3::\$Lib->ghettocache' orkui/
# → zero matches post-R-16
```

---

## 3. Proposed revision

### 3.1 Principle

**No frontend ghettocache keys** — domain methods own TTL, key naming, and bust-on-write. Controllers and models call `JSONModel` / `APIModel` only.

### 3.2 Migration phases (executed in R-16)

| Phase | Work |
|-------|------|
| **1** | Move read-through cache into domain `Get*` methods |
| **2** | Move write bust into domain mutators (`Save*`, `Delete*`, attendance record) |
| **3** | Remove `Ork3::$Lib->ghettocache` from `orkui/` |
| **4** | Verify grep exit + kingdom/park/report fuzzy hosts |

### 3.3 Non-goals

- Changing cache TTL semantics without domain justification.
- Admin memcache flush UI redesign (retain existing admin behavior).
- Residual lib bypass or `$DB` (R-17/R-18).

---

## 4. Exit criteria checklist

- [x] Backend survey complete (§1)
- [x] Test design documented (§2)
- [x] Cache migration policy documented (§3)
- [x] Per-target mapping recorded
- [x] Cross-refs to DS-14 §1.4

---

## Related documents

| Doc | Link |
|-----|------|
| Parent inventory | [ds-14-lib-service-discovery.md §1.4](./ds-14-lib-service-discovery.md#14-cross-cutting-ghettocache) |
| Validation | [validations/v-16-ghettocache-validation.md](./validations/v-16-ghettocache-validation.md) |
| Continuation plan | [10-phase-2-continuation.md](./10-phase-2-continuation.md) |
| Gap audit | [p3-backfill-tvds-audit.md](./p3-backfill-tvds-audit.md) |
