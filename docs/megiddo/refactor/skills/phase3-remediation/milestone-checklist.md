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
| 3 | FIX-04 | | | [ ] |
| 4 | FIX-05 | | | [ ] |
| 5 | BACKFILL | | | [ ] |
| 6 | DS-19 | | | [ ] |
| 7 | T-19 | | | [ ] |
| 8 | V-19 | | | [ ] |
| 9 | R-19a | | | [ ] |
| 10 | R-19b | | | [ ] |
| 11 | R-19c | | | [ ] |
| 12 | R-19d | | | [ ] |
| 13 | VALIDATE-20 | | | [ ] |

**Next actionable hop:** FIX-04

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

- [ ] `park-auth-sandbox` investigated (layout regression vs intentional)
- [ ] `bin/fuzzy-validator validate --pages park-auth-sandbox --phase all` exit 0
- [ ] Baseline re-record only if change is intentional
- [ ] Checklist + commit on stacked branch

---

## FIX-05: Doc hygiene

- [ ] `03-implementation-plan.md` has R-05, R-06, R-07, R-12 completion paragraphs
- [ ] Cross-links from `04-milestone-checklist.md` if needed
- [ ] Checklist + commit on stacked branch

---

## BACKFILL: DS/T/V for R-14 … R-18

- [ ] Gap audit: which DS/T/V artifacts missing for R-14 … R-18
- [ ] `ds-15` … `ds-18` design notes (or consolidated backfill doc) committed
- [ ] `t-15` … `t-18` test-design sections committed
- [ ] `v-15` … `v-18` validation docs committed
- [ ] `04-milestone-checklist.md` Phase 1 / 1.5 / 1.6 rows updated
- [ ] Checklist + commit on stacked branch

---

## DS-19: Residual lib bypass discovery

- [ ] `ds-19-residual-lib-discovery.md` — inventory of 41 `Ork3::$Lib` sites in 12 files
- [ ] §3 split into **R-19a, R-19b, R-19c, R-19d** (3 files per hop — see SKILL.md table)
- [ ] T-19 test design section in design note
- [ ] Infection scope documented per hop
- [ ] Checklist + commit on stacked branch

---

## T-19: Residual lib tests

- [ ] Backend tests for R-19a…d domain/API surfaces
- [ ] Playwright coverage gaps closed per DS-19
- [ ] Full PHPUnit pass
- [ ] Checklist + commit on stacked branch

---

## V-19: Residual lib validation

- [ ] `validations/v-19-residual-lib-validation.md`
- [ ] Fuzzy + Playwright + Infection boundaries **per R-19a…d hop**
- [ ] Checklist + commit on stacked branch

---

## R-19a: `model.Player`, `index.php`, `KingdomAjax`

- [ ] Zero `Ork3::$Lib` in the 3 files above
- [ ] PHPUnit + hop gates per v-19 / worker
- [ ] `03-implementation-plan.md` R-19a note
- [ ] One commit; stack chain updated

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
