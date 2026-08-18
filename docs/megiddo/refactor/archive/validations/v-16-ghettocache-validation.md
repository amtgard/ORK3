# V-16: GhettoCache Migration — Validation Artifacts

**Milestone:** V-16  
**Branch:** `megiddo/v-16-ghettocache-validation` (retroactive — backfill 2026-07-10)  
**Target IDs:** Ghettocache portion of T-EVT-08, T-KNG-11, T-RPT-02, T-PLM-03, T-ATT-06, T-SRC-01  
**Depends on:** DS-16, T-16, V-00, V-14  
**Execution sprint:** R-16  
**Discovery source:** [ds-16-ghettocache-discovery.md §1](../ds-16-ghettocache-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

Ghettocache migration affects **cached JSON surfaces** and report grids. R-16 fuzzy gate uses kingdom profile, park auth, and reports ladder hosts.

### 1.1 Page registry entries

| pageId | Route | auth | Target IDs | Capture |
|--------|-------|------|------------|---------|
| `kingdom-profile` | `./index.php?Route=Kingdom&KingdomId=…` | login | T-KNG-11 cache | V-06 — re-validate |
| `park-auth-sandbox` | `./index.php?Route=Park&ParkId=1000001` | login (sandbox) | T-PRK-05 cache overlap | V-07 — re-validate |
| `reports-ladder-grid` | `./index.php?Route=Reports/ladder_grid` | login | T-RPT-02 cache | V-10 — re-validate |

No new `pages.json5` rows for V-16.

**Domain capture set:** none (reuse).  
**R-16 fuzzy gate:** `kingdom-profile,park-auth-sandbox,reports-ladder-grid`

### 1.2 Canary matrix

| Surface | Variant A | Variant B |
|---------|-----------|-----------|
| Kingdom player JSON cache | `kingdom-profile` | — |
| Park player list cache | `park-auth-sandbox` | — |
| Reports ladder grid | `reports-ladder-grid` | — |
| Domain cache keys | T-16 integration tests | skip visual |
| Search cache | T-SRC-01 prior tests | skip visual |

**Sandbox pins:** kingdom/park sandbox ids from seed.  
**Mirror:** mirror kingdom/report data (lenient thresholds).

### 1.3 Record / validate

```bash
bin/fuzzy-validator validate --pages kingdom-profile,park-auth-sandbox,reports-ladder-grid --phase all
```

**R-16 sign-off result:** validate exit **0** — **6/6** (3 pages × 2 profiles).

---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-16)

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Integration/KingdomProfileTest.php` | Integration | Kingdom player cache |
| `tests/Integration/ModelPlayerCacheTest.php` | Integration | Player detail cache |
| `tests/Integration/ReportTest.php` | Integration | Report ladder cache |
| `tests/e2e/kingdom-profile.spec.ts` | e2e | Kingdom profile smoke |
| `tests/e2e/reports.spec.ts` | e2e | Ladder grid smoke |
| Auth smoke | e2e | Session preflight |

**Infection:** V-14 §2.4 pass A + B — `tools/infection/infection.t14-lib-auth-era.json5`, `tools/infection/infection.t14-lib-live-weather.json5`.

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| `KingdomProfileTest` | Stale player list | Cache key moved to domain |
| `ModelPlayerCacheTest` | Detail cache miss | Frontend bust removed |
| `reports.spec.ts` | Grid empty / stale | Report cache bust timing |
| Fuzzy ladder grid | DOM row count drift | Cache TTL or data change |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-16 | Not allowed |
|----------|---------------------|-------------|
| **Domain** | Internalize cache read/bust | Duplicate cache keys in frontend |
| **Controllers** | Remove `ghettocache` lib calls | New frontend cache keys |
| **TTL semantics** | Preserve existing domain TTLs | Arbitrary TTL changes without justification |
| **Fuzzy** | Re-record on intentional UI change | Skip gate pages |
| **Infection** | Pass A ≥ 15%, pass B ≥ 15% | MSI drop on touched domains |

### 2.4 Post-R-16 Infection scope

```bash
# Pass A
sh bin/run-infection.sh --configuration=tools/infection/infection.t14-lib-auth-era.json5 ...

# Pass B
sh bin/run-infection.sh --configuration=tools/infection/infection.t14-lib-live-weather.json5 ...
```

**R-16 sign-off result:** pass A MSI **18%**, pass B MSI **27%**.

---

## 3. R-16 sign-off checklist

- [x] §1 page ids pass `bin/fuzzy-validator validate --phase all` (test + mirror) — **6/6**
- [x] Test edits within §2.3
- [x] Full unit suite green — **215/215** (2 skipped)
- [x] Infection per §2.4 — pass A **18%**, pass B **27%**
- [x] Zero `Ork3::$Lib->ghettocache` in `orkui/`
- [x] Playwright `kingdom-profile.spec.ts` **2/2**, `reports.spec.ts` **4/4** pass

**Branch:** `megiddo/r-16-ghettocache-refactor` @ `86d5cbed`

---

## Related documents

| Doc | Link |
|-----|------|
| Design note | [ds-16-ghettocache-discovery.md](../ds-16-ghettocache-discovery.md) |
| Parent policy | [ds-14-lib-service-discovery.md §1.4](../ds-14-lib-service-discovery.md#14-cross-cutting-ghettocache) |
| Continuation plan | [10-phase-2-continuation.md](../10-phase-2-continuation.md) |
