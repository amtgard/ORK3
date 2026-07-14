# Phase 3 close-out — agent prompt runbook

Copy-paste prompts for the remaining automated work before human P3-4/P3-5. Open each `.prompt` file → Ctrl+A → Ctrl+C → paste into a **new** agent chat. File contents are agent-language only.

**Prerequisite:** Stack tip `megiddo/r-19d-residual-lib-refactor` (R-19d complete; `Ork3::$Lib` → zero).

**Doc layout:** [../README.md](../README.md) · completed DS notes in [../discovery/](../discovery/) · historical skills in [../archive/](../archive/)

## Run order

| Step | Prompt | Proceed when |
|------|--------|--------------|
| 1 | [01-fix-06-gate-blockers.prompt](./01-fix-06-gate-blockers.prompt) | ✅ Done @ `c330d69b` |
| 2 | [01-fix-07-fuzzy-baselines.prompt](./01-fix-07-fuzzy-baselines.prompt) | ✅ Done @ `b4ddc98c` |
| 3 | [02-validate-20-rerun.prompt](./02-validate-20-rerun.prompt) | 2nd run failed V20-C @ `49e76bda` |
| 4 | [01-fix-08-heraldry-dom-volatile.prompt](./01-fix-08-heraldry-dom-volatile.prompt) | `validate --all` 42/42 after redeploy |
| 5 | [02-validate-20-rerun.prompt](./02-validate-20-rerun.prompt) (3rd) | 3rd run failed V20-C + V20-D |
| 6 | [01-fix-09-event-index-attendance.prompt](./01-fix-09-event-index-attendance.prompt) | `validate --all` 41/41; Playwright 50/50 mirror |
| 7 | [02-validate-20-rerun.prompt](./02-validate-20-rerun.prompt) (4th) | `phase3-audit-report.md` `status=ok` |
| 8 | [03-idiom-enforcement-orchestrator.prompt](./03-idiom-enforcement-orchestrator.prompt) | `idioms-validate-report.md` `status=ok` |
| 8b | [04-sandbox-fuzzy-rebaseline-orchestrator.prompt](./04-sandbox-fuzzy-rebaseline-orchestrator.prompt) | After seed-test-credentials: re-record **test**-profile fuzzy baselines + setpoint (`FIX-10`) |
| 9 | Human — [validations/r-milestone-smoke-matrix.html](../validations/r-milestone-smoke-matrix.html) | P3-4 manual walk-through |
| 10 | Human — retrospective bullets in `phase3-audit-report.md` | P3-5 complete |

## After step 7 (VALIDATE-20 `status=ok`)

- Optional merge: P3-6 → `megiddo/rebase-20260709`
- Canonical plans: [11-phase-3-closeout.md](../11-phase-3-closeout.md), [12-idiom-enforcement.md](../12-idiom-enforcement.md)

## Worker sources (orchestrator sub-agents)

| Phase | Workers |
|-------|---------|
| FIX-06 | [skills/phase3-gate-fix/workers/FIX-06-gate-blockers.md](../skills/phase3-gate-fix/workers/FIX-06-gate-blockers.md) |
| FIX-07 | [skills/phase3-gate-fix/workers/FIX-07-fuzzy-baselines.md](../skills/phase3-gate-fix/workers/FIX-07-fuzzy-baselines.md) |
| FIX-08 | [skills/phase3-gate-fix/workers/FIX-08-heraldry-dom-volatile.md](../skills/phase3-gate-fix/workers/FIX-08-heraldry-dom-volatile.md) |
| FIX-09 | [skills/phase3-gate-fix/workers/FIX-09-event-index-attendance.md](../skills/phase3-gate-fix/workers/FIX-09-event-index-attendance.md) |
| FIX-10 | [skills/phase3-gate-fix/workers/FIX-10-sandbox-fuzzy-rebaseline.md](../skills/phase3-gate-fix/workers/FIX-10-sandbox-fuzzy-rebaseline.md) |
| VALIDATE-20 | [skills/phase3-gate-fix/workers/VALIDATE-20.md](../skills/phase3-gate-fix/workers/VALIDATE-20.md) |
| I-* | [skills/idiom-enforcement/workers/](../skills/idiom-enforcement/workers/) |
