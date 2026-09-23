---
name: phase3-gate-fix
description: >-
  FIX-06 and VALIDATE-20-rerun after Phase 3 remediation — resolve fuzzy ladder-grid
  and Playwright mirror 500s, then re-audit success criteria before idiom enforcement.
disable-model-invocation: true
---

# Megiddo — Phase 3 Gate Fix

Active tail of Phase 3 remediation. Full historical pipeline: [archive/skills/phase3-remediation](../../archive/skills/phase3-remediation/).

## Run order

1. [prompts/01-fix-06-gate-blockers.prompt](../../prompts/01-fix-06-gate-blockers.prompt)
2. [prompts/02-validate-20-rerun.prompt](../../prompts/02-validate-20-rerun.prompt)
3. [prompts/03-idiom-enforcement-orchestrator.prompt](../../prompts/03-idiom-enforcement-orchestrator.prompt)

## Workers

| Hop | Worker |
|-----|--------|
| FIX-06 | [workers/FIX-06-gate-blockers.md](./workers/FIX-06-gate-blockers.md) |
| VALIDATE-20-rerun | [workers/VALIDATE-20-rerun.md](./workers/VALIDATE-20-rerun.md) |
| Reference | [workers/VALIDATE-20.md](./workers/VALIDATE-20.md) |
