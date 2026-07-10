# V-15: HasAuthority Rollout — Validation Artifacts

**Milestone:** V-15  
**Branch:** `megiddo/v-15-hasauthority-validation` (retroactive — backfill 2026-07-10)  
**Target IDs:** HasAuthority portion of T-EVT-08, T-KNG-11, T-PRK-05, T-PLR-08, T-RPT-02, T-ATT-04, T-UNT-03; template auth flags  
**Depends on:** DS-15, T-15, V-00, V-14  
**Execution sprint:** R-15  
**Discovery source:** [ds-15-hasauthority-discovery.md §1](../ds-15-hasauthority-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

HasAuthority rollout is **cross-cutting** — no dedicated page shell. R-15 fuzzy gate uses **auth-matrix hosts** from prior V-* domains that exercise kingdom/park/global permission chrome.

### 1.1 Page registry entries

| pageId | Route | auth | Target IDs | Capture |
|--------|-------|------|------------|---------|
| `admin-permissions` | `./index.php?Route=Admin/permissions` | login | T-ADM auth overlap, cross-cut | V-08 — re-validate |
| `kingdom-auth-sandbox` | `./index.php?Route=Kingdom&KingdomId=100001` | login (sandbox) | T-KNG-11 auth | V-06 — re-validate |
| `park-auth-sandbox` | `./index.php?Route=Park&ParkId=1000001` | login (sandbox) | T-PRK-05 auth | V-07 — re-validate |
| `player-profile` | `./index.php?Route=Player&PlayerId=…` | login | T-PLR-08 auth | V-09 — re-validate |

No new `pages.json5` rows for V-15.

**Domain capture set:** none (reuse V-00/V-06/V-07/V-08/V-09).  
**R-15 fuzzy gate:** `admin-permissions,kingdom-auth-sandbox,park-auth-sandbox,player-profile`

### 1.2 Canary matrix

| Surface | Variant A | Variant B |
|---------|-----------|-----------|
| Admin permissions matrix | `admin-permissions` | — |
| Kingdom edit chrome | `kingdom-auth-sandbox` | — |
| Park edit chrome | `park-auth-sandbox` | — |
| Player admin tabs | `player-profile` | — |
| HasAuthority JSON | T-15 `AuthorizationLibTest` | skip visual |
| Template flags | T-15 `auth-permissions.spec.ts` | fuzzy hosts |

**Sandbox pins:** kingdom `100001`, park `1000001`, sandbox player from seed.  
**Mirror:** mirror E2E user (`admin`/`password`) — lenient thresholds.

### 1.3 Record / validate

```bash
bin/fuzzy-validator validate --pages admin-permissions,kingdom-auth-sandbox,park-auth-sandbox,player-profile --phase all
```

**R-15 sign-off result:** validate exit **0** — **8/8** (4 pages × 2 profiles).

---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-15)

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Integration/AuthorizationLibTest.php` | Integration | HasAuthority JSON wrapper (T-LIB-03/04 + cross-cut) |
| `tests/e2e/auth-permissions.spec.ts` | e2e | Admin/kingdom/park permission matrix smoke |
| Auth smoke (orchestrator) | e2e | Session + home login |

**Infection:** `infection.t14-lib-auth-era.json5` — pass A, MSI floor 15%.

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| `AuthorizationLibTest` | JSON `HasAuthority` shape | Direct lib call reintroduced |
| `auth-permissions.spec.ts` | Tab/link visibility | Template flag not precomputed |
| Fuzzy auth hosts | DOM chrome drift | Intentional permission UI change |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-15 | Not allowed |
|----------|---------------------|-------------|
| **Controllers** | Replace `Ork3::$Lib->authorization->HasAuthority` with `AuthorizationGate` | New direct lib auth calls |
| **Templates** | Precompute `CanEdit*` in `$this->data` | Template `Ork3::$Lib` auth |
| **Domain** | Unchanged semantics | Role inheritance / KPM changes |
| **Fuzzy** | Re-record on intentional UI change | Skip auth hosts without justification |
| **Infection** | Pass A MSI ≥ 15% | MSI drop without test improvement |

### 2.4 Post-R-15 Infection scope

```bash
sh bin/run-infection.sh \
  --configuration=infection.t14-lib-auth-era.json5 \
  --only-covered \
  --filter=class.Authorization.php \
  --filter=class.EraPhoenice.php \
  --test-framework-options="--filter=AuthorizationLibTest|EraPhoeniceTest"
```

**R-15 sign-off result:** MSI **18%**.

---

## 3. R-15 sign-off checklist

- [x] §1 page ids pass `bin/fuzzy-validator validate --phase all` (test + mirror) — **8/8**
- [x] Test edits within §2.3
- [x] Full unit suite green — **215/215** (2 skipped)
- [x] Infection per §2.4 — MSI **18%**
- [x] Zero `Ork3::$Lib->authorization->HasAuthority` in `orkui/`
- [x] Zero auth `Ork3::$Lib` in `orkui/template/`
- [x] Playwright `auth-permissions.spec.ts` **3/3** pass

**Branch:** `megiddo/r-15-hasauthority-refactor` @ `446e7c42`

---

## Related documents

| Doc | Link |
|-----|------|
| Design note | [ds-15-hasauthority-discovery.md](../ds-15-hasauthority-discovery.md) |
| Parent V-14 | [v-14-lib-service-validation.md](./v-14-lib-service-validation.md) |
| Continuation plan | [10-phase-2-continuation.md](../10-phase-2-continuation.md) |
