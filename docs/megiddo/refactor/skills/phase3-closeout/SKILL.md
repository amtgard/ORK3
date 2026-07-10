---
name: phase3-closeout
description: >-
  Run Megiddo Phase 3 automated audit after R-18: rg zero violations, PHPUnit,
  fuzzy validate all, Playwright, checklist sign-off, audit report. Human completes
  manual HTML smoke matrix separately.
disable-model-invocation: true
---

# Megiddo — Phase 3 Close-out

Runs **after R-18**. Canonical plan: [11-phase-3-closeout.md](../../11-phase-3-closeout.md).

## When to use

- R-15 … R-18 complete on stack tip
- Automated verification before project close-out
- **Not** a substitute for human manual smoke matrix (P3-4)

## How to run

1. Open [orchestrator.prompt](./orchestrator.prompt) → Ctrl+A, Ctrl+C, paste into new agent chat.
2. Human walks [validations/r-milestone-smoke-matrix.html](../../validations/r-milestone-smoke-matrix.html) (P3-4).

## Deliverables

| ID | Owner | Artifact |
|----|-------|----------|
| P3-1 | Done | `validations/r-milestone-smoke-matrix.html` |
| P3-2 | Agent | `phase3-audit-report.md` + automated gates |
| P3-4 | Human | Manual matrix walk-through |

## Related

- [10-phase-2-continuation.md](../../10-phase-2-continuation.md) — R-15 … R-18
- [orchestrator-phase2-continuation.prompt](../refactor-execution/orchestrator-phase2-continuation.prompt) — finish Phase 2 first
