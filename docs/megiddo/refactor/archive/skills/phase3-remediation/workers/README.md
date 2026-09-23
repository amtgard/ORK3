# Phase 3 Remediation — Worker Prompts

One file per orchestrator hop. The **orchestrator** reads one file per Task; paste a single worker for manual/debug runs.

**Shared steps (FIX hops):** [_shared-procedure.md](_shared-procedure.md)  
**R-19a…d gates:** [refactor-execution/workers/_shared-procedure.md](../refactor-execution/workers/_shared-procedure.md)

| Hop | File | Branch slug |
|-----|------|-------------|
| FIX-02 | [FIX-02-assets.md](FIX-02-assets.md) | `megiddo/p3-fix-02-assets` |
| FIX-03 | [FIX-03-playwright-heraldry.md](FIX-03-playwright-heraldry.md) | `megiddo/p3-fix-03-playwright-heraldry` |
| FIX-04 | [FIX-04-fuzzy-park-auth.md](FIX-04-fuzzy-park-auth.md) | `megiddo/p3-fix-04-fuzzy-park-auth` |
| FIX-05 | [FIX-05-doc-hygiene.md](FIX-05-doc-hygiene.md) | `megiddo/p3-fix-05-doc-hygiene` |
| BACKFILL | [BACKFILL-tvds-r14-r18.md](BACKFILL-tvds-r14-r18.md) | `megiddo/p3-backfill-tvds-r14-r18` |
| DS-19 | [DS-19.md](DS-19.md) | `megiddo/ds-19-residual-lib-discovery` |
| T-19 | [T-19.md](T-19.md) | `megiddo/t-19-residual-lib-tests` |
| V-19 | [V-19.md](V-19.md) | `megiddo/v-19-residual-lib-validation` |
| R-19a | [R-19a.md](R-19a.md) | `megiddo/r-19a-residual-lib-refactor` |
| R-19b | [R-19b.md](R-19b.md) | `megiddo/r-19b-residual-lib-refactor` |
| R-19c | [R-19c.md](R-19c.md) | `megiddo/r-19c-residual-lib-refactor` |
| R-19d | [R-19d.md](R-19d.md) | `megiddo/r-19d-residual-lib-refactor` |
| VALIDATE-20 | [VALIDATE-20.md](VALIDATE-20.md) | `megiddo/p3-validate-20-audit` |

**R-19 file groups:** 12 files → 4 hops × 3 files — see [SKILL.md](../SKILL.md).

**Orchestrated run:** [orchestrator.prompt](../orchestrator.prompt) — starts at first unchecked hop on [milestone-checklist.md](../milestone-checklist.md).

**Manual single hop:** open worker file, copy fenced prompt, paste into new agent chat.
