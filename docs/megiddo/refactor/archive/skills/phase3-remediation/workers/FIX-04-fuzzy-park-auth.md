# Worker — FIX-04 (Fuzzy park-auth-sandbox)

```
You are executing **Megiddo FIX-04** only — fuzzy park-auth-sandbox baseline.

Read: docs/megiddo/refactor/skills/phase3-remediation/workers/_shared-procedure.md, docs/megiddo/refactor/phase3-audit-report.md § Fuzzy, docs/megiddo/refactor/validations/v-00-fuzzy-setpoint.md

| Field | Value |
|-------|-------|
| Branch | `megiddo/p3-fix-04-fuzzy-park-auth` |
| Stack base | `megiddo/p3-fix-03-playwright-heraldry` @ checklist |
| Prerequisite | FIX-02 and FIX-03 complete |
| Problem | `park-auth-sandbox` dimension mismatch: baseline (961,1280) vs candidate (937,1280) — 24px shorter |
| Scope | Investigate layout; fix regression OR re-record intentional baseline |

## Tasks

1. Preflight: `bin/ork-db deploy-sandbox`, `bin/fuzzy-validator setpoint restore`.
2. Capture and compare `park-auth-sandbox` — identify what changed (heraldry block, auth chrome, header, permissions row).
3. If **regression** from R-15+ template/auth flags → fix template/CSS in minimal diff.
4. If **intentional** layout change after heraldry/asset fixes → re-record baseline per v-00 procedure (`setpoint save` or page-specific capture workflow).
5. Document decision in milestone-checklist or v-00 notes.

## Gates

```bash
bin/fuzzy-validator validate --pages park-auth-sandbox --phase all   # exit 0 test+mirror
```

Optional sanity: `bin/fuzzy-validator validate --pages kingdom-auth-sandbox,park-auth-sandbox --phase all`

## Out of scope

- Full `--all` sweep (R-19 / Phase 3 re-audit); lib bypass refactors

Commit: `FIX-04: Resolve park-auth-sandbox fuzzy baseline drift.`  
Update milestone-checklist.md; return report.
```
