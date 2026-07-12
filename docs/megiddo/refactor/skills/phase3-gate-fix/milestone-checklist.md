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
| 5 | VALIDATE-20-rerun (3rd) | `megiddo/p3-validate-20-audit` | — | [ ] |

**Next actionable hop:** [02-validate-20-rerun.prompt](../../prompts/02-validate-20-rerun.prompt) after FIX-08 commit

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

- [x] V20-A/B/D/E pass
- [ ] V20-C — **fail:** test `home-authenticated` dimension 1976→1838; sandbox auth DOM drift (heraldry `?v=`)
- [ ] `status=ok` — failed @ `49e76bda`

---

## FIX-08: Heraldry DOM volatility

- [x] Root cause: `deploy-sandbox` bumps heraldry cache-bust `?v=` in DOM attrs
- [x] `tree_diff.py` normalize heraldry URLs; unit tests pass
- [x] Full `setpoint capture` post-deploy; bundle `20260712T233808Z-b4ddc98c-810b9accf0e0c8c8.zip`
- [x] Repro: `validate --all` 42/42 exit 0 before and after `deploy-sandbox --yes`
- [x] Commit on `megiddo/p3-fix-08-heraldry-dom-volatile` @ `e2f7c280`

---

## VALIDATE-20-rerun (3rd)

- [ ] Stack FIX-08 → rebase `megiddo/p3-validate-20-audit`
- [ ] V20-A–F all pass; `phase3-audit-report.md` `status=ok`

**Exit (ok):** [03-idiom-enforcement-orchestrator.prompt](../../prompts/03-idiom-enforcement-orchestrator.prompt)
