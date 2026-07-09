# V-10: Reports, Voting Rules, Awards — Validation Artifacts

**Milestone:** V-10  
**Branch:** `megiddo/v-10-reports-validation`  
**Target IDs:** T-RPT-01 through T-RPT-09, T-AWD-01  
**Depends on:** DS-10, T-10, V-00  
**Execution sprint:** R-10  
**Discovery source:** [ds-10-reports-discovery.md §1](../ds-10-reports-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

Reports **page hosts** (voting eligible, ladder grid, attendance date picker). Officer directory stays `skip: true` (deferred inline JS). Award dropdown HTML is covered by T-10 unit tests — no visual canary.

### 1.1 Page registry entries

| pageId | Route | auth | Target IDs | Capture |
|--------|-------|------|------------|---------|
| `reports-voting-eligible` | `./index.php?Route=Reports/voting_eligible&KingdomId=14` | login | T-RPT-04–08 | V-00 — re-validate / refresh if drifted |
| `reports-ladder-grid` | `./index.php?Route=Reports/ladder_grid&KingdomId=14` | login | T-RPT-01 | V-00 — re-validate |
| `reports-attendance` | `./index.php?Route=Reports/attendance&KingdomId=14` | login | T-RPT-03 | V-00 — re-validate |
| `reports-officer-directory` | `./index.php?Route=Reports/kingdom_officer_directory` | none | T-RPT-09 | skip (inline JS drift) |

No new `pages.json5` rows for V-10.

**Domain capture set:** refresh if validate drifts.  
**R-10 fuzzy gate:** `reports-voting-eligible,reports-ladder-grid,reports-attendance`

### 1.2 Canary matrix

| Surface | Variant A | Variant B |
|---------|-----------|-----------|
| Voting eligible | KingdomId `14` (mirror + sandbox) | Player badge via T-10 / PlayerAjax |
| Ladder grid | KingdomId `14` | — |
| Attendance dates | KingdomId `14` | Park scope via `AttendanceDatesTest` |
| Officer directory | skip visual | `OfficerDirectoryTest` |
| Award options | `AwardOptionGroupsTest` | no visual canary |

**Sandbox / mirror:** KingdomId `14` is the pinned reports kingdom (reports.spec.ts). Login via `profiles.json5`.

### 1.3 Record / validate

```bash
bin/fuzzy-validator validate --pages reports-voting-eligible,reports-ladder-grid,reports-attendance --phase all
```

**V-10 capture result:** validate exit **0** (6/6). Re-recorded voting/ladder/attendance (mirror DOM drift).

---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-10)

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Integration/VotingRulesTest.php` | Integration | Supported IDs, rule flags, player badge |
| `tests/Integration/LadderGridTest.php` | Integration | Grid assembly, knight groups, master map |
| `tests/Unit/AttendanceDatesTest.php` | Unit | Distinct dates by kingdom/park |
| `tests/Integration/OfficerDirectoryTest.php` | Integration | Officer pivot + principality merge |
| `tests/Unit/AwardOptionGroupsTest.php` | Unit | Pseudo-ladder IDs, peerage buckets |
| `tests/e2e/reports.spec.ts` | e2e | Voting / ladder / attendance smoke |

**Infection:** `infection.t10-reports.json5` — MSI 48% on `class.Report.php` + `class.Award.php`.

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| `VotingRulesTest` | Rules store moves backend | `_all_voting_rules` leaves model |
| `LadderGridTest` | `GetLadderAwardGrid` API | Controller SQL → Report domain |
| `AttendanceDatesTest` | Method on Report | `get_attendance_dates` leaves model |
| `OfficerDirectoryTest` | Principality merge in domain | Model N+1 → single API |
| `AwardOptionGroupsTest` | Structured groups API | HTML assembly leaves `model.Award` |
| `reports.spec.ts` | Selector / column drift | Report markup |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-10 | Not allowed |
|----------|---------------------|-------------|
| **Domain** | Voting rules store, ladder grid API, award option groups | Change eligibility semantics without test update |
| **Cross-sprint** | Player voting badge consumed by R-09; auth/cache with R-14 | Re-implement player profile SQL |
| **Fixtures** | KingdomId `14` for dual-profile canaries | Mirror-only kingdom without doc |
| **Fuzzy** | Re-record on intentional report UI change; keep officer-dir skip | Un-skip officer directory without fixing JS drift |
| **Infection** | Add new Report/Award domain paths | MSI below T-10 floor without justification |

### 2.4 Post-R-10 Infection scope

```bash
sh bin/run-infection.sh \
  --configuration=infection.t10-reports.json5 \
  --only-covered \
  --filter=class.Report.php \
  --filter=class.Award.php \
  --test-framework-options="--filter=VotingRulesTest|LadderGridTest|AttendanceDatesTest|OfficerDirectoryTest|AwardOptionGroupsTest"
```

---

## 3. R-10 sign-off checklist

- [ ] §1 page ids pass `bin/fuzzy-validator validate --phase all` (test + mirror)
- [ ] Test edits within §2.3
- [ ] Full unit suite green
- [ ] Infection per §2.4
- [ ] No new `$DB` in `controller.Reports` / `model.Reports` / `model.Award` for migrated T-RPT/T-AWD targets
