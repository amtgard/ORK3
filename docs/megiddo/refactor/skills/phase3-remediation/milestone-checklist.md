# Phase 3 Remediation — Milestone Checklist

Orchestrator and workers update this file. Master checklist: [04-milestone-checklist.md](../../04-milestone-checklist.md) § Phase 3.

**Trigger:** [phase3-audit-report.md](../../phase3-audit-report.md) (2026-07-10) — `status=failed`

**Stack entry:** `megiddo/r-18-residual-db-refactor` @ `1d8d8455` (Phase 3 audit doc commit)

---

## Queue status

| Hop | ID | Branch | Commit | Status |
|-----|-----|--------|--------|--------|
| 1 | FIX-02 | `megiddo/p3-fix-02-assets` | `6e7bb487` | [x] |
| 2 | FIX-03 | `megiddo/p3-fix-03-playwright-heraldry` | `6766aaac` | [x] |
| 3 | FIX-04 | `megiddo/p3-fix-04-fuzzy-park-auth` | `b973c6a1` | [x] |
| 4 | FIX-05 | `megiddo/p3-fix-05-doc-hygiene` | `69fd2ac1` | [x] |
| 5 | BACKFILL | `megiddo/p3-backfill-tvds-r14-r18` | `7f9576eb` | [x] |
| 6 | DS-19 | `megiddo/ds-19-residual-lib-discovery` | `39f47f22` | [x] |
| 7 | T-19 | `megiddo/t-19-residual-lib-tests` | `c684604c` | [x] |
| 8 | V-19 | `megiddo/v-19-residual-lib-validation` | `5872aa92` | [x] |
| 9 | R-19a | `megiddo/r-19a-residual-lib-refactor` | `0088e6f2` | [x] |
| 10 | R-19b | | | [ ] |
| 11 | R-19c | | | [ ] |
| 12 | R-19d | | | [ ] |
| 13 | VALIDATE-20 | | | [ ] |

**Next actionable hop:** R-19b

---

## FIX-02: Asset pipeline

**Root cause:** Stale sandbox DB retained ~194 `has_heraldry=1` mundanes from an older render while `generate-assets` correctly emitted 82 files for the current seed-42 manifest (77 fake + 4 real + default). Fresh `sandbox.sql` and `Render::mundaneHeraldryIdsForSeed()` already matched; `deploy-sandbox` skipped SQL re-apply when render was anchored today, so post-apply asset validation failed (~110 missing files).

**Fix:** Single heraldry manifest via `Render::mundaneHeraldryIdsForSeed()`; `GenerateAssets` consumes it; `DeploySandbox` detects heraldry drift and forces SQL refresh before deploy-assets.

- [x] Root cause documented (bootstrap `has_heraldry` vs `generate-assets` ID lists)
- [x] `bin/ork-db deploy-sandbox` exits 0 (asset validation PASS)
- [x] `bin/ork-db generate-assets` + `deploy-assets` aligned with validator
- [x] PHPUnit unchanged or pass
- [x] Checklist + commit on stacked branch

---

## FIX-03: Playwright heraldry profile

**Root cause:** Phase 3 close-out ran `npx playwright test tests/e2e/` on mirror (`use prod`); `heraldry.spec.ts` asserts sandbox fake IDs (kingdom `100001`, park `1000001`, players `≥100000000`) that do not exist on mirror.

**Fix:** Wrap heraldry in `test.describe('sandbox heraldry')` with `beforeEach` sandbox roster probe (skips with clear message on mirror). Document mirror vs sandbox split in `06-test-framework.md` § Playwright DB profiles; update Phase 3 close-out orchestrator to two-gate Playwright (mirror `--grep-invert heraldry` + sandbox heraldry).

- [x] `tests/e2e/heraldry.spec.ts` runs against correct DB profile (sandbox test IDs)
- [x] `06-test-framework.md` documents heraldry spec profile requirements
- [x] `npx playwright test tests/e2e/heraldry.spec.ts` exit 0 (after FIX-02)
- [x] Mirror suite `npx playwright test tests/e2e/ --grep-invert heraldry` exit 0
- [x] Checklist + commit on stacked branch

---

## FIX-04: Fuzzy park-auth-sandbox

**Root cause:** Phase 3 audit ran with stale sandbox heraldry (pre–FIX-02); test-profile capture rendered **937px** (24px shorter — heraldry block absent). Git manifest was re-calibrated to 937 while setpoint baseline PNG remained **961px** (RB-F bundle). After FIX-02 asset alignment, page stably renders **961px** with heraldry — not a template/CSS regression.

**Fix:** Re-record test+mirror manifests (`imageHeight` 937→961 test; mirror timestamps/commit pin) to match setpoint baseline and post–FIX-02 render. No production code changes.

- [x] `park-auth-sandbox` investigated (manifest drift from broken heraldry capture, not layout regression)
- [x] `bin/fuzzy-validator validate --pages park-auth-sandbox --phase all` exit 0 (test+mirror 2/2 PASS)
- [x] Baseline re-record only if change is intentional (manifest sync; PNG unchanged at 961)
- [x] Checklist + commit on stacked branch

---

## FIX-05: Doc hygiene

**Scope:** Documentation only — backfill inline R-05, R-06, R-07, R-12 completion paragraphs in `03-implementation-plan.md` adjacent to Event, Kingdom, Park, and Attendance inventory sections; cross-link [10-phase-2-continuation.md](../../10-phase-2-continuation.md) on R-15+ carryover references.

- [x] `03-implementation-plan.md` has R-05, R-06, R-07, R-12 completion paragraphs
- [x] Cross-links to `10-phase-2-continuation.md` on R-15+ carryover (R-05 T-EVT-08, R-06 T-KNG-11, R-07 T-PRK-05)
- [x] `phase3-audit-report.md` § P3-E prose gap marked closed
- [x] Checklist + commit on stacked branch

---

## BACKFILL: DS/T/V for R-14 … R-18

- [x] Gap audit: [p3-backfill-tvds-audit.md](../../p3-backfill-tvds-audit.md)
- [x] `ds-15` … `ds-18` design notes committed
- [x] `t-15` … `t-18` test-design sections committed (§2 in each ds-*)
- [x] `v-15` … `v-18` validation docs committed
- [x] `04-milestone-checklist.md` Phase 1 / 1.5 / 1.6 rows updated
- [x] `10-phase-2-continuation.md` § Worker hygiene — backfill complete pointer
- [x] Checklist + commit on stacked branch

---

## DS-19: Residual lib bypass discovery

- [x] `ds-19-residual-lib-discovery.md` — inventory of 41 `Ork3::$Lib` sites in 12 files
- [x] §3 split into **R-19a, R-19b, R-19c, R-19d** (3 files per hop — see SKILL.md table)
- [x] T-19 test design section in design note (§2)
- [x] Infection scope documented per hop (§2.3)
- [x] T-LIB-06 … T-LIB-17 in `03-implementation-plan.md`
- [x] `04-milestone-checklist.md` DS-19 section + sign-off gate
- [x] Path A zero-exemptions documented
- [x] Gate: `sh bin/run-unit-tests.sh` exit 0
- [x] Checklist + commit on stacked branch

---

## T-19: Residual lib tests

- [x] Backend tests for R-19a…d domain/API surfaces
- [x] Playwright coverage gaps closed per DS-19
- [x] Full PHPUnit pass
- [x] Checklist + commit on stacked branch

**Branch:** `megiddo/t-19-residual-lib-tests`

**Tests:** `PlayerServiceTest.php`, `HeraldryServiceTest.php`, `StateOfAmtgardTest.php`; extensions to `DangerAuditQueryTest`, `WeatherServiceTest`, `KingdomAjaxTest`, `SearchServiceTest`; `tests/e2e/residual-lib.spec.ts`

**Infection:** `infection.t19-residual-lib.json5` — passes A–D per DS-19 §2.3 (run at R-19 hop sign-off)

---

## V-19: Residual lib validation

- [x] `validations/v-19-residual-lib-validation.md` — §2.3, §2.4, §2.5, §3 full gate list
- [x] Fuzzy + Playwright + Infection boundaries **per R-19a…d hop**
- [x] `validations/r-milestone-smoke-matrix.html` — R-19a…d stubs (R-19d completion note)
- [x] `04-milestone-checklist.md` § V-19 updated
- [x] Gate: `sh bin/run-unit-tests.sh` exit 0
- [x] Checklist + commit on stacked branch

**Branch:** `megiddo/v-19-residual-lib-validation` @ `025cc2e3`

---

## R-19a: `model.Player`, `index.php`, `KingdomAjax`

- [x] Zero `Ork3::$Lib` in the 3 files above
- [x] PHPUnit + hop gates per v-19 / worker (Infection pass A MSI 28%; Playwright 7/7; fuzzy pre-existing drift on v-19 base)
- [x] `03-implementation-plan.md` R-19a note
- [x] One commit; stack chain updated

**Branch:** `megiddo/r-19a-residual-lib-refactor` @ `0088e6f2`

**Migration:** T-LIB-06 — `Model_Player` player wrappers via `new Player()`; T-LIB-07 — `Health`/`Event` domain + `$Session->times`; T-LIB-08 — `Model_Kingdom`/`Model_Search`/`Dangeraudit` in KingdomAjax.

---

## R-19b: `EventAjax`, `AdminAjax`, `Admin`

- [ ] Zero `Ork3::$Lib` in the 3 files above; R-19a files still clean
- [ ] PHPUnit + hop gates
- [ ] `03-implementation-plan.md` R-19b note
- [ ] One commit; stack chain updated

---

## R-19c: `ParkAjax`, `SearchAjax`, `Search`

- [ ] Zero `Ork3::$Lib` in the 3 files above; prior hops still clean
- [ ] PHPUnit + hop gates
- [ ] `03-implementation-plan.md` R-19c note
- [ ] One commit; stack chain updated

---

## R-19d: `PlayerAjax`, `WnAjax`, `model.AdminDashboard`

- [ ] `rg 'Ork3::\$Lib' orkui/` → **zero** (all 12 files)
- [ ] Full PHPUnit + fuzzy + Playwright per v-19
- [ ] `03-implementation-plan.md` R-19 complete summary
- [ ] `04-milestone-checklist.md` R-19a…d sections
- [ ] One commit; stack tip = `megiddo/r-19d-residual-lib-refactor`

---

## VALIDATE-20: Phase 3 re-audit (success criteria)

**Audit only** — no production refactors. Confirms goals from [02-requirements.md](../../02-requirements.md) § Success Criteria.

- [ ] V20-A: `rg '\$DB->' orkui/` → zero
- [ ] V20-A: `rg 'Ork3::\$Lib' orkui/` → zero
- [ ] V20-A: no direct DML SQL strings in `orkui/` PHP (INSERT/UPDATE/DELETE)
- [ ] V20-B: PHPUnit full suite exit 0
- [ ] V20-C: fuzzy `validate --all --phase all` exit 0
- [ ] V20-D: Playwright full suite exit 0 (mirror + sandbox heraldry per FIX-03)
- [ ] V20-E: plan/checklist completeness
- [ ] `phase3-audit-report.md` updated with `status: ok|failed`
- [ ] `04-milestone-checklist.md` § Phase 3 automated items updated
- [ ] Doc commit on `megiddo/p3-validate-20-audit`

**Exit (ok):** Human — P3-4 manual smoke matrix + P3-5 retrospective. Optional P3-6 merge.

**Exit (failed):** Do not claim remediation complete; report blockers.
