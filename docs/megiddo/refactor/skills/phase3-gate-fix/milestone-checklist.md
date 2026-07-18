# Phase 3 Gate Fix — Milestone Checklist

**Prerequisite:** R-19d complete — `rg 'Ork3::\$Lib' orkui/` zero on `megiddo/r-19d-residual-lib-refactor`

**Stack entry:** `megiddo/r-19d-residual-lib-refactor` @ checklist metadata

---

## Queue status

| Hop | ID | Branch | Commit | Status |
|-----|-----|--------|--------|--------|
| 1 | FIX-06 | `megiddo/p3-fix-06-gate-blockers` | (pending) | [x] |
| 2 | VALIDATE-20-rerun | | | [ ] |

**Next actionable hop:** VALIDATE-20-rerun

---

## FIX-06: Gate blockers

- [x] Playwright mirror 500s fixed (`KingdomAjax/playersearch`, `Admin/serverhealth_weather_stats`)
- [x] Fuzzy `reports-ladder-grid` exit 0
- [x] PHPUnit full suite exit 0
- [x] Static isolation unchanged (`$DB`, `Ork3::$Lib` zero)
- [x] Checklist + commit on `megiddo/p3-fix-06-gate-blockers`

---

## VALIDATE-20-rerun

- [ ] V20-A static audit pass
- [ ] V20-B PHPUnit pass
- [ ] V20-C fuzzy `--all` pass
- [ ] V20-D Playwright mirror + sandbox heraldry pass
- [ ] `phase3-audit-report.md` `status=ok`
- [ ] Doc commit on `megiddo/p3-validate-20-audit`

**Exit (ok):** [idiom-enforcement/orchestrator.prompt](../idiom-enforcement/orchestrator.prompt) (or [prompts/03](../../prompts/03-idiom-enforcement-orchestrator.prompt))
