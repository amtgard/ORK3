# V-11: Search & Player Search — Validation Artifacts

**Milestone:** V-11  
**Branch:** `megiddo/v-11-search-validation`  
**Target IDs:** T-SRC-01, T-SRC-02, T-ADM-10, T-KNA-06, T-PRA-01, T-EVA-06 (search portion)  
**Depends on:** DS-11, T-11, V-00  
**Execution sprint:** R-11  
**Discovery source:** [ds-11-search-discovery.md §1](../ds-11-search-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

Search **page hosts** (`search`, `search-unitsearch`, `search-ajax-universal`) stay `skip: true` (deferred inline JS / height instability — V-00). R-11 fuzzy gate uses **admin/kingdom/park hosts** that embed playersearch UI chrome; behavior covered by T-11.

### 1.1 Page registry entries

| pageId | Route | auth | Target IDs | Capture |
|--------|-------|------|------------|---------|
| `admin-permissions` | `./index.php?Route=Admin/permissions` | login | T-ADM-10 host | V-00 / V-08 — re-validate |
| `kingdom-auth-sandbox` | `./index.php?Route=Kingdom/profile/100001` | login | T-KNA-06 host | V-02 — re-validate |
| `park-auth-sandbox` | `./index.php?Route=Park/profile/1000001` | login | T-PRA-01 host | V-02 — re-validate |
| `search` | `./index.php?Route=Search` | none | T-SRC-02 shell | skip (inline JS) |
| `search-unitsearch` | `./index.php?Route=Search/unitsearch` | login | T-SRC-01 shell | skip (height drift) |
| `search-ajax-universal` | `./index.php?Route=SearchAjax/universal&q=test` | login | T-SRC-02 JSON | skip (AJAX) |

No new `pages.json5` rows for V-11.

**Domain capture set:** none (reuse); refresh if validate drifts.  
**R-11 fuzzy gate:** `admin-permissions,kingdom-auth-sandbox,park-auth-sandbox`

### 1.2 Canary matrix

| Surface | Variant A | Variant B |
|---------|-----------|-----------|
| Admin playersearch host | `admin-permissions` | — |
| Kingdom scoped search host | Sandbox `kingdom-auth-sandbox` | — |
| Park scoped search host | Sandbox `park-auth-sandbox` | — |
| Universal / unit activity | T-11 `SearchServiceTest` + `search.spec.ts` | skip visual |
| Event staff search | T-11 + EventAjax tests | R-04 hosts optional |

**Sandbox pins:** kingdom `100001`, park `1000001`.  
**Mirror:** sandbox ids → home chrome; `admin-permissions` uses mirror admin session.

### 1.3 Record / validate

```bash
bin/fuzzy-validator validate --pages admin-permissions,kingdom-auth-sandbox,park-auth-sandbox --phase all
```

**V-11 capture result:** validate exit **0** (6/6). Re-recorded `kingdom-auth-sandbox` / `park-auth-sandbox` (test DOM drift).

---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-11)

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Integration/SearchServiceTest.php` | Integration | Universal, scopes, punct fold, unit activity, admin/event |
| `tests/Unit/SearchEscapeTest.php` | Unit | LIKE escape |
| `tests/e2e/search.spec.ts` | e2e | Nav search + scoped search smoke |

**Infection:** `tools/infection/infection.t11-search.json5` — MSI 50% on `class.SearchService.php`.

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| `SearchServiceTest` | New UniversalSearch / ScopedPlayerSearch APIs | Controller SQL → SearchService |
| `SearchEscapeTest` | Helper moves with domain | Escape shared across endpoints |
| `search.spec.ts` | Response shape / selector drift | Adapter layer during migration |
| Cross-sprint EventAjax addauth | Out of R-11 | T-EVA-06 INSERT → R-02 |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-11 | Not allowed |
|----------|---------------------|-------------|
| **Domain** | UniversalSearch, ScopedPlayerSearch, GetUnitActivityCounts | Change restricted-name / scope semantics without test update |
| **Response shape** | Thin controller adapters for legacy JS keys | Break e2e without updating adapters |
| **Cross-sprint** | Call Kingdom family IDs (R-06); Event proximity (R-04) | Re-implement auth INSERT |
| **Fuzzy** | Re-record host pages on intentional chrome change; keep search skips | Un-skip `search` without fixing JS drift |
| **Infection** | Keep SearchService filter; add helpers | MSI below T-11 floor without justification |

### 2.4 Post-R-11 Infection scope

```bash
sh bin/run-infection.sh \
  --configuration=tools/infection/infection.t11-search.json5 \
  --only-covered \
  --filter=class.SearchService.php \
  --test-framework-options="--filter=SearchServiceTest|SearchEscapeTest"
```

---

## 3. R-11 sign-off checklist

- [x] §1 page ids pass `bin/fuzzy-validator validate --phase all` (test + mirror)
- [x] Test edits within §2.3
- [x] Full unit suite green
- [x] Infection per §2.4
- [x] No new `$DB` in SearchAjax / AdminAjax / KingdomAjax / ParkAjax / EventAjax playersearch paths for migrated targets
