# V-12: Attendance & Sign-In — Validation Artifacts

**Milestone:** V-12  
**Branch:** `megiddo/v-12-attendance-validation`  
**Target IDs:** T-ATT-01 through T-ATT-06, T-SIN-01 through T-SIN-04, T-QR-01  
**Depends on:** DS-12, T-12, V-00  
**Execution sprint:** R-12  
**Discovery source:** [ds-12-attendance-discovery.md §1](../ds-12-attendance-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

Attendance / SignIn / QR **page hosts** (`attendance`, `sign-in-invalid`, `attendance-ajax-getday`, `qr-link-api`) stay `skip: true` (deferred inline JS / async asset drift — V-00). R-12 fuzzy gate uses **park profile + park calendar** hosts that frame attendance workflows; behavior covered by T-12.

### 1.1 Page registry entries

| pageId | Route | auth | Target IDs | Capture |
|--------|-------|------|------------|---------|
| `park-auth-sandbox` | `./index.php?Route=Park/profile/1000001` | login | Park attendance host | V-02 / V-11 — re-validate |
| `event-park` | `./index.php?Route=Event/park/1` | login | Park calendar / event attendance context | V-00 / V-07 — re-validate |
| `attendance` | `./index.php?Route=Attendance` | none | T-ATT-* shell | skip (inline JS) |
| `sign-in-invalid` | `./index.php?Route=SignIn/index/abc` | none | T-SIN-* error shell | skip (asset drift) |
| `attendance-ajax-getday` | `./index.php?Route=AttendanceAjax/park/1/getday` | login | T-ATT JSON | skip (AJAX) |
| `qr-link-api` | `./index.php?Route=QR/link/abc` | none | T-QR-01 JSON | skip (API) |

No new `pages.json5` rows for V-12.

**Domain capture set:** none (reuse).  
**R-12 fuzzy gate:** `park-auth-sandbox,event-park`

### 1.2 Canary matrix

| Surface | Variant A | Variant B |
|---------|-----------|-----------|
| Park attendance host | Sandbox `park-auth-sandbox` | — |
| Park calendar host | `event-park` | — |
| Attendance day / AJAX | T-12 `AttendanceWriteTest` + e2e | skip visual |
| Sign-in / class levels | T-12 `ClassLevelTest` + `AttendanceSignInTest` | skip visual |
| QR token | T-12 e2e invalid token | skip visual |

**Sandbox pins:** park `1000001`, park calendar id `1`.  
**Mirror:** sandbox park → home chrome; `event-park` uses mirror park `1`.

### 1.3 Record / validate

```bash
bin/fuzzy-validator validate --pages park-auth-sandbox,event-park --phase all
```

**V-12 capture result:** validate exit **0** (4/4).

---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-12)

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Unit/ClassLevelTest.php` | Unit | Thresholds `[5,12,21,34,53]` |
| `tests/Integration/AttendanceSignInTest.php` | Integration | Link info, last class, credits |
| `tests/Integration/AttendanceWriteTest.php` | Integration | Add/reactivate, edit persona, adjacent dates |
| `tests/Support/AttendanceFixture.php` | Fixture | Sandbox attendance rows |
| `tests/e2e/attendance.spec.ts` | e2e | Attendance / SignIn / QR smoke |

**Infection:** `infection.t12-attendance.json5` — MSI 53% on `class.Attendance.php` + `class.Player.php`.

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| `ClassLevelTest` | Helper moves to domain | Thresholds leave SignIn / templates |
| `AttendanceSignInTest` | Enriched `GetAttendanceLinkInfo` | Event name / credits via GetPlayerClasses |
| `AttendanceWriteTest` | Reactivate in `AddAttendance` | Controller UPDATE removed |
| `attendance.spec.ts` | Selector / JSON shape | Ajax response enrichment |
| Cross-sprint weather / active events | Partial R-12 | Weather JSON; GetActiveEventsAtScope with R-04/R-14 |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-12 | Not allowed |
|----------|---------------------|-------------|
| **Domain** | Reactivate-on-add, adjacent dates, class level helper, link info enrichment | Change credit/level semantics without test update |
| **Cross-sprint** | Align profile thresholds with R-09; weather proxy optional | Re-implement park profile SQL |
| **Fixtures** | Sandbox park `1000001` / AttendanceFixture | Mirror-only attendance rows without doc |
| **Fuzzy** | Re-record hosts on intentional chrome change; keep attendance/sign-in skips | Un-skip `attendance` without fixing JS drift |
| **Infection** | Add ClassLevel / Attendance helpers | MSI below T-12 floor without justification |

### 2.4 Post-R-12 Infection scope

```bash
sh bin/run-infection.sh \
  --configuration=infection.t12-attendance.json5 \
  --only-covered \
  --filter=class.Attendance.php \
  --filter=class.Player.php \
  --test-framework-options="--filter=ClassLevelTest|AttendanceSignInTest|AttendanceWriteTest"
```

---

## 3. R-12 sign-off checklist

- [ ] §1 page ids pass `bin/fuzzy-validator validate --phase all` (test + mirror)
- [ ] Test edits within §2.3
- [ ] Full unit suite green
- [ ] Infection per §2.4
- [ ] No new `$DB` in Attendance / SignIn / QR controllers or `model.Attendance` for migrated targets
