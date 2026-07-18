# Worker — FIX-05 (Doc hygiene)

```
You are executing **Megiddo FIX-05** only — implementation-plan completion prose.

Read: docs/megiddo/refactor/skills/phase3-remediation/workers/_shared-procedure.md, docs/megiddo/refactor/03-implementation-plan.md, docs/megiddo/refactor/04-milestone-checklist.md § R-05 … R-12

| Field | Value |
|-------|-------|
| Branch | `megiddo/p3-fix-05-doc-hygiene` |
| Stack base | `megiddo/p3-fix-04-fuzzy-park-auth` @ checklist |
| Scope | Documentation only — no production code |

## Tasks

1. Add inline **R-05, R-06, R-07, R-12** completion paragraphs to `03-implementation-plan.md` (mirror style of R-01, R-08, R-13 entries). Source facts from `04-milestone-checklist.md` § R-05 … R-12 complete sections.
2. Place paragraphs adjacent to the target inventory sections they close (Event, Kingdom, Park, Attendance).
3. Cross-link [10-phase-2-continuation.md](../../10-phase-2-continuation.md) where R-15+ carryover is referenced.
4. Optionally add one-line pointer in `phase3-audit-report.md` § P3-E that prose gap is closed.

## Gates

- No test run required.
- `git diff` shows only `docs/megiddo/refactor/`.

Commit: `FIX-05: Backfill R-05/06/07/12 completion notes in implementation plan.`  
Update milestone-checklist.md; return report.
```
