# DS-15: HasAuthority Rollout — Discovery Design Note

**Milestone:** DS-15  
**Branch:** `megiddo/ds-15-hasauthority-discovery` (retroactive — backfill 2026-07-10)  
**Target IDs:** HasAuthority portion of T-EVT-08, T-KNG-11, T-PRK-05, T-PLR-08, T-RPT-02, T-ATT-04, T-UNT-03; ~20 template violations  
**Depends on:** DS-14 (§1.3, §3.2 `Authorization.HasAuthority` JSON), R-14 (`AuthorizationGate`, `Model_Authorization::has_authority`)  
**Execution sprint:** R-15  
**Test sprint:** T-15

---

## 1. Backend survey

### 1.1 Scope summary

R-14 shipped `Authorization.HasAuthority` on JSON/SOAP and migrated T-LIB-03/04 plus `class.Controller` base menu gates (~8 sites). **R-15** completes the bulk replacement: all remaining `Ork3::$Lib->authorization->HasAuthority` in `orkui/` controllers and AJAX handlers, plus template inline auth gates.

| Category | Pre-R-15 (approx.) | Post-R-15 |
|----------|-------------------|-----------|
| Controller/AJAX `HasAuthority` | ~120 | **0** |
| Template `Ork3::$Lib` auth | ~20 | **0** (precomputed flags) |
| `HasAuthority` in `orkui/` total | ~128 PHP + ~20 tpl | **0** |

**Call chain before:** Controller → `Ork3::$Lib->authorization->HasAuthority($uid, $type, $id, $role)` → domain recursive scope check.

**Call chain after:** Controller → `$this->Authorization->has_authority(...)` (`AuthorizationGate` / `Model_Authorization`) → `JSONModel('Authorization')` → domain method unchanged.

**Source inventory:** [ds-14-lib-service-discovery.md §1.3](./ds-14-lib-service-discovery.md#13-cross-cutting-hasauthority); carryover table in [10-phase-2-continuation.md](./10-phase-2-continuation.md).

### 1.2 Per-domain deferrals (auth portion only)

| Sprint | File(s) | Target ID | Auth patterns |
|--------|---------|-----------|---------------|
| R-05 carryover | `Controller_Event` | T-EVT-08 | Event CREATE/EDIT gates throughout controller |
| R-06 carryover | `Controller_Kingdom` | T-KNG-11 | Kingdom/park scope edit flags |
| R-07 carryover | `Controller_Park` | T-PRK-05 | Park officer gates |
| R-09 carryover | `Controller_Player` | T-PLR-08 | Player admin / heraldry gates |
| R-10 carryover | `Controller_Reports` | T-RPT-02 | Report scope edit flags |
| R-12 carryover | `Controller_Attendance` | T-ATT-04 | Attendance admin gates |
| Unit | `Controller_Unit` | T-UNT-03 | Unit officer / kingdom scope |
| Cross-cut | ~15 AJAX controllers | various | Mutating action gates |

**Non-goals for R-15:** ghettocache (→ R-16), `player`/`kingdom`/`weather` lib bypass (→ R-17), residual `$DB` (→ R-18).

### 1.3 Template violations

Templates must not call `Ork3::$Lib`. Controllers precompute booleans in `$this->data`:

| Template | Flags precomputed |
|----------|-------------------|
| `Admin_player.tpl`, `Admin_kingdom.tpl`, `Admin_park.tpl` | `CanEdit*` per scope |
| `Reports_roster.tpl`, `Reports_playerawardrecommendations.tpl` | Row-level edit / scope flags |
| `Playernew_index.tpl`, `Kingdomnew_index.tpl`, `Playernew_reconcile.tpl` | Admin tab visibility |
| `default.theme` | Nav admin links (completes DS-13/R-14 partial menu work) |

### 1.4 Backend surface (existing — no new APIs)

R-15 consumes APIs delivered in R-14:

| Layer | Artifact |
|-------|----------|
| JSON | `Authorization.HasAuthority` |
| SOAP | `Authorization.HasAuthority` |
| Frontend wrapper | `Model_Authorization::has_authority()` / `AuthorizationGate` |

No semantic changes to role inheritance, KPM bypass, or ORK admin short-circuit.

### 1.5 Cross-milestone overlaps

| Pattern | Also in | Notes |
|---------|---------|-------|
| HasAuthority | Every prior R-* with auth gates | R-15 is **call-site migration** only |
| Template flags | DS-14 §1.3 | Same policy; R-15 executes |
| Performance | DS-14 §3.1 | N+1 service calls acceptable at sign-off; batch API optional future work |

---

## 2. Test design

### 2.1 Backend unit/integration tests (T-15)

R-15 does **not** add new PHPUnit files — characterization from T-14 covers the `HasAuthority` JSON wrapper:

| Test file | Target | Validates |
|-----------|--------|-----------|
| `tests/Integration/AuthorizationLibTest.php` | Cross-cut | ORK admin, park edit, event CREATE vs EDIT, invalid scope |

**Regression command (R-15 sign-off):**

```bash
sh bin/run-unit-tests.sh
# 215 passed, 2 skipped
```

### 2.2 Infection scope (T-15, DS-7)

Reuse V-14 / T-14 pass A — Authorization domain unchanged; controller wiring mutates:

```bash
sh bin/run-infection.sh \
  --configuration=tools/infection/infection.t14-lib-auth-era.json5 \
  --only-covered \
  --filter=class.Authorization.php \
  --filter=class.EraPhoenice.php \
  --test-framework-options="--filter=AuthorizationLibTest|EraPhoeniceTest"
```

**R-15 sign-off result:** MSI **18%** (floor 15%).

### 2.3 Frontend functional tests (T-15)

| Test file | Flow | Assert |
|-----------|------|--------|
| `tests/e2e/auth-permissions.spec.ts` | Admin permissions matrix | Kingdom/park/global edit visibility matches role |
| Auth smoke (orchestrator preflight) | Login + home | Session gate intact |

**R-15 sign-off result:** `auth-permissions.spec.ts` **3/3** pass (mirror `admin`/`password`).

### 2.4 Static exit criterion

```bash
rg 'Ork3::\$Lib->authorization->HasAuthority' orkui/
# → zero matches post-R-15

rg 'HasAuthority' orkui/template/
# → zero template lib auth calls
```

---

## 3. Proposed revision

### 3.1 Principle

**Zero direct `Ork3::$Lib->authorization` in `orkui/`** after R-15. All gates use `Model_Authorization::has_authority()` or precomputed `$this->data` flags for templates.

### 3.2 Migration phases (executed in R-15)

| Phase | Work |
|-------|------|
| **1** | Replace controller/AJAX `HasAuthority` with `$this->Authorization->has_authority()` |
| **2** | Precompute template flags in owning controllers |
| **3** | Remove template `Ork3::$Lib` auth calls |
| **4** | Verify static grep exit + fuzzy auth hosts |

### 3.3 Carryover on same files

Files touched for auth may still contain ghettocache or domain lib calls — **R-16/R-17** scope per [10-phase-2-continuation.md](./10-phase-2-continuation.md).

### 3.4 Non-goals

- New authorization semantics or batch API (optional future).
- GhettoCache, domain lib bypass, `$DB` removal (R-16 … R-18).

---

## 4. Exit criteria checklist

- [x] Backend survey complete (§1)
- [x] Test design documented (§2)
- [x] Migration policy documented (§3)
- [x] Template violation inventory recorded
- [x] Cross-refs to DS-14 §1.3 and R-14 `AuthorizationGate`

---

## Related documents

| Doc | Link |
|-----|------|
| Parent inventory | [ds-14-lib-service-discovery.md §1.3](./ds-14-lib-service-discovery.md#13-cross-cutting-hasauthority) |
| Validation | [validations/v-15-hasauthority-validation.md](./validations/v-15-hasauthority-validation.md) |
| Continuation plan | [10-phase-2-continuation.md](./10-phase-2-continuation.md) |
| Gap audit | [p3-backfill-tvds-audit.md](./p3-backfill-tvds-audit.md) |
