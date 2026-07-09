# V-08: Admin Dashboard & Health — Validation Artifacts

**Milestone:** V-08  
**Branch:** `megiddo/v-08-admin-validation`  
**Target IDs:** T-ADM-01 through T-ADM-10, T-ADM-12 (excl. T-ADM-11 → V-02)  
**Depends on:** DS-08, T-08, V-00  
**Execution sprint:** R-08  
**Discovery source:** [ds-08-admin-discovery.md §1](../ds-08-admin-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

Admin **page hosts** (dashboard, permissions, State of Amtgard). Server health / audit log are ops-heavy — covered by T-08 unit/integration; optional visual canaries deferred if volatile.

### 1.1 Page registry entries

| pageId | Route | auth | Target IDs | Capture |
|--------|-------|------|------------|---------|
| `admin-dashboard` | `./index.php?Route=Admin` | login | T-ADM-01 | V-00 — re-validate / refresh if drifted |
| `admin-permissions` | `./index.php?Route=Admin/permissions` | login | T-ADM-02 | V-00 / V-02 — re-validate |
| `admin-state-of-amtgard` | `./index.php?Route=Admin/stateofamtgard` | login | T-ADM-09, T-ADM-12 | V-00 — re-validate |

No new `pages.json5` rows for V-08. Audit log / server health remain integration-tested only (PROCESSLIST / weather freshness too volatile for fuzzy).

**Domain capture set:** none (reuse); refresh if validate drifts.  
**R-08 fuzzy gate:** `admin-dashboard,admin-permissions,admin-state-of-amtgard`

### 1.2 Canary matrix

| Surface | Variant A | Variant B |
|---------|-----------|-----------|
| Dashboard YoY | `admin-dashboard` | — |
| Permissions | `admin-permissions` | — |
| State of Amtgard | `admin-state-of-amtgard` | chart JSON via AdminAjax tests |
| Health / audit | T-08 unit/integration | no visual canary |

**Sandbox / mirror:** Admin routes require login; credentials via `profiles.json5` (FU-11).

### 1.3 Record / validate

```bash
bin/fuzzy-validator validate --pages admin-dashboard,admin-permissions,admin-state-of-amtgard --phase all
```

**V-08 capture result:** validate exit **0** (6/6). Re-recorded admin-dashboard/permissions/state-of-amtgard (mirror DOM drift).

---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-08)

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Unit/AdminDashboardTrendStatsTest.php` | Unit | YoY window / trend keys |
| `tests/Integration/AdminPermissionsTest.php` | Integration | Global + inherited grants |
| `tests/Integration/DangerAuditQueryTest.php` | Integration | Audit log read API |
| `tests/Unit/ServerHealthStatsTest.php` | Unit | Weather freshness SQL mirror |
| `tests/Unit/AbbreviationUniqueTest.php` | Unit | Park/kingdom abbr |
| `tests/Unit/StateOfAmtgardValidationTest.php` | Unit | Date/kingdom validation |
| `tests/e2e/admin-dashboard.spec.ts` | e2e | Dashboard + SoA smoke |

**Infection:** `infection.t08-admin.json5` — batched MSI ≥15% (DangerAudit+Player, Report, StateOfAmtgard, Park).

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| `AdminDashboardTrendStatsTest` | Method on Report / AdminDashboard | YoY SQL leaves controller |
| `AdminPermissionsTest` | Auth-list API shape | Permissions SQL → domain |
| `DangerAuditQueryTest` | New DangerAudit read API | Write-only → list/filter |
| `StateOfAmtgardValidationTest` | Validation moves to domain | HTTP validation thinned |
| Cross-sprint abbr / search / auth | Out of R-08 | T-ADM-06/07/10/11 owned elsewhere |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-08 | Not allowed |
|----------|---------------------|-------------|
| **Domain** | Report trend stats, DangerAudit list, SoA validation service | Change YoY window semantics without test update |
| **Infra probes** | Keep FPM/disk/memcache in controller or thin HealthService | Drop weather freshness checks |
| **Cross-sprint** | Call R-06/R-07 abbr APIs; R-02 for addauth | Re-implement auth INSERT |
| **Fuzzy** | Re-record on intentional admin UI change | Widen thresholds to force pass |
| **Infection** | Keep batched filters; add new domain classes | MSI below T-08 floors without justification |

### 2.4 Post-R-08 Infection scope

```bash
sh bin/run-infection.sh \
  --configuration=infection.t08-admin.json5 \
  --only-covered \
  --filter=class.Report.php \
  --filter=class.DangerAudit.php \
  --filter=class.StateOfAmtgard.php \
  --test-framework-options="--filter=AdminDashboardTrendStatsTest|AdminPermissionsTest|DangerAuditQueryTest|StateOfAmtgardValidationTest"
```

---

## 3. R-08 sign-off checklist

- [ ] §1 page ids pass `bin/fuzzy-validator validate --phase all` (test + mirror)
- [ ] Test edits within §2.3
- [ ] Full unit suite green
- [ ] Infection per §2.4
- [ ] No new `$DB` in `controller.Admin` / `controller.AdminAjax` for migrated T-ADM-* targets
