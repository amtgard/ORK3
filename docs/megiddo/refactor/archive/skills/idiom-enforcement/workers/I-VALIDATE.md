# Worker — I-VALIDATE (Idiom close-out)

```
You are executing **Megiddo I-VALIDATE** only — final idiom + regression audit (no behavior changes).

Read: docs/megiddo/refactor/skills/idiom-enforcement/workers/_shared-procedure.md, docs/megiddo/refactor/idioms-00-charter.md §4 lint commands, docs/megiddo/refactor/skills/phase3-gate-fix/workers/VALIDATE-20.md, docs/megiddo/refactor/06-test-framework.md

| Field | Value |
|-------|-------|
| Branch | `megiddo/i-validate-idiom-audit` |
| Stack base | `megiddo/i-19d-idiom-residual-lib` @ checklist |
| Scope | Audit only — fix only charter lint false positives with user approval |

## Goal

1. All charter §4 lint commands pass
2. All VALIDATE-20 gates still pass (V20-A … V20-D)
3. Publish `docs/megiddo/refactor/idioms-validate-report.md` with `status: ok|failed`

## Gates

Run idioms-00-charter.md §4 lint commands + VALIDATE-20 V20-A through V20-D exactly.

## Docs

- `idioms-validate-report.md` — timestamp, branch, commit, lint results, gate table
- `04-milestone-checklist.md` § Phase 3.5 — check off passed items only
- `skills/idiom-enforcement/milestone-checklist.md` — I-VALIDATE complete

Commit: `I-VALIDATE: Idiom enforcement close-out audit.`  
Return report. If `status=ok`, human next: P3-4 + P3-5.
```
