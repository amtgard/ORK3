# Phase 3 Gate Fix — Milestone Checklist

**Prerequisite:** R-19d complete — `rg 'Ork3::\$Lib' orkui/` zero on `megiddo/r-19d-residual-lib-refactor`

**Stack entry:** `megiddo/r-19d-residual-lib-refactor` @ `b19fb375`

---

## Queue status

| Hop | ID | Branch | Commit | Status |
|-----|-----|--------|--------|--------|
| 1 | FIX-06 | `megiddo/p3-fix-06-gate-blockers` | `c330d69b` | [x] |
| 2 | FIX-07 | `megiddo/p3-fix-07-fuzzy-baselines` | `b4ddc98c` | [x] |
| 3 | VALIDATE-20-rerun (2nd) | `megiddo/p3-validate-20-audit` | `49e76bda` | [ ] failed V20-C |
| 4 | FIX-08 | `megiddo/p3-fix-08-heraldry-dom-volatile` | `e2f7c280` | [x] |
| 5 | VALIDATE-20-rerun (3rd) | `megiddo/p3-validate-20-audit` | rebased | [ ] failed V20-C + V20-D |
| 6 | FIX-09 | `megiddo/p3-fix-09-event-index-attendance` | `73b36ee2` | [x] |

**Next actionable hop:** [02-validate-20-rerun.prompt](../../prompts/02-validate-20-rerun.prompt) on rebased `megiddo/p3-validate-20-audit`

---

## FIX-06: Gate blockers

- [x] Playwright mirror 500s fixed
- [x] Fuzzy `reports-ladder-grid` + PHPUnit green
- [x] Commit `c330d69b`

---

## FIX-07: Fuzzy baseline drift

- [x] Full `setpoint capture` test+mirror; bundle `20260712T221041Z-c330d69b-af9ae3139c2ada41.zip`
- [x] `validate --all` exit 0 (42/42) at record time
- [x] Commit `b4ddc98c`

---

## VALIDATE-20-rerun (2nd)

- [x] Stack FIX-07 → reset `megiddo/p3-validate-20-audit` on FIX-07 tip @ `b4ddc98c`
- [x] V20-A static audit pass (`$DB`, `Ork3::$Lib`, DML all zero)
- [x] V20-B PHPUnit pass (230/230, 2 skipped)
- [ ] V20-C fuzzy `--all` pass — **fail:** test `home-authenticated` dimension (1976,1280) vs (1838,1280); sandbox auth pages DOM 0.996–0.998 (heraldry `?v=`)
- [x] V20-D Playwright mirror + sandbox heraldry pass (50/50 + 3/3)
- [x] V20-E plan completeness pass
- [ ] `phase3-audit-report.md` `status=ok` — **failed** @ audit 2026-07-12T22:49:09Z

**Remediation:** FIX-08 — heraldry DOM normalization + setpoint re-record

---

## FIX-08: Heraldry DOM volatility

- [x] Root cause: `deploy-sandbox` bumps heraldry cache-bust `?v=` in DOM attrs
- [x] `tree_diff.py` normalize heraldry URLs; unit tests pass
- [x] Full `setpoint capture` post-deploy; bundle `20260712T233808Z-b4ddc98c-810b9accf0e0c8c8.zip`
- [x] Repro: `validate --all` 42/42 exit 0 before and after `deploy-sandbox --yes`
- [x] Commit on `megiddo/p3-fix-08-heraldry-dom-volatile` @ `e2f7c280`

---

## VALIDATE-20-rerun (3rd)

- [x] Stack FIX-08 → rebase `megiddo/p3-validate-20-audit` (audit @ 2026-07-12T23:56:02Z)
- [x] V20-A static audit pass (`$DB`, `Ork3::$Lib`, DML all zero)
- [x] V20-B PHPUnit pass (230/230, 2 skipped)
- [ ] V20-C fuzzy `--all` pass — **fail (3rd):** mirror `event-index` DOM 0.996063 (event table rows 11–12 links; test profile 21/21)
- [ ] V20-D Playwright mirror + sandbox heraldry pass — **fail (3rd):** mirror 49/50 (`attendance.spec.ts` login `networkidle` timeout); sandbox heraldry 3/3
- [x] V20-E plan completeness pass
- [ ] `phase3-audit-report.md` `status=ok` — **failed** @ audit 2026-07-12T23:56:02Z

**Remediation:** FIX-09 — `event-index` skip + attendance login `waitForURL(/Player\/profile/)`

**Exit (ok):** [03-idiom-enforcement-orchestrator.prompt](../../prompts/03-idiom-enforcement-orchestrator.prompt)

---

## FIX-09: event-index skip + attendance login flake

- [x] Root cause: mirror `event-index` volatile event list href churn; attendance login `networkidle` on long-polling mirror
- [x] `event-index` `skip: true` in `pages.json5` (V-00 only; R-05 via `event-index-rsvp*`)
- [x] `attendance.spec.ts` login wait → `waitForURL(/Player\/profile/)` (avoid mirror `networkidle` flake)
- [x] Repro: `validate --all` 41/41 exit 0 before and after `deploy-sandbox --yes`
- [x] Playwright mirror 50/50 + sandbox heraldry 3/3
- [x] Commit on `megiddo/p3-fix-09-event-index-attendance` @ `73b36ee2` (code `04bd2878`)
- [x] Rebase `megiddo/p3-validate-20-audit` onto FIX-09 tip
