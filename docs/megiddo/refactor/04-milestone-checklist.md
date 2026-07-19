# Megiddo Refactor — Remaining Work Checklist

**Status:** All implementation work (R-01 … R-19d), the Phase 3 automated audit and gate fixes, idiom enforcement, and the post-refactor rebase are complete. Human close-out and Player API residual remain.

The complete historical milestone checklist is preserved in [archive/04-milestone-checklist-complete.md](./archive/04-milestone-checklist-complete.md).

## Final close-out

- [x] Rebase onto `origin/master` @ `7631d0baad65b573d4d53f115c84d20af09b046e` on `megiddo/rebase-20260717`: RB-0…RB-Z complete; gold fuzzy validated test + mirror. Later tip: `megiddo/fuzzy-validator-v2`. Future runs: [skills/rebase-and-redocument/orchestrator.prompt](./skills/rebase-and-redocument/orchestrator.prompt).
- [ ] **P3-4:** Complete the human walk-through in [validations/r-milestone-smoke-matrix.html](./validations/r-milestone-smoke-matrix.html).
- [ ] **P3-5:** Record the retrospective.
- [ ] **P3-6 (optional):** Merge after rebase and human close-out acceptance.

## Player first-class API residual (P3-R)

**Plan home:** [player-aggregates/](./player-aggregates/) (inventory, contract, milestones, orchestrator). Summary also in [11-phase-3-closeout.md](./11-phase-3-closeout.md) § P3-R.

- [x] **P3-R0:** Inventory + API contract ([player-aggregates/](./player-aggregates/)).
- [ ] **P3-R1:** Class level / progress via domain (`ClassLevel` / Player); thin controller.
- [ ] **P3-R2:** Milestones + award maps API; remove maps from Player templates.
- [ ] **P3-R3:** Reconcile suggestions API; template display-only.
- [ ] **P3-R4:** Wire controller/templates; fuzzy player-profile canaries; optional orkservice exposure.

**Non-blocking (done):** bootstrap `class.Controller` Lib→model hop; `Authorization->audit` for Dangeraudit; `index.php` via `Model_Health` / `Model_Event`.

See [11-phase-3-closeout.md](./11-phase-3-closeout.md) for Phase 3 human close-out and [README.md](./README.md) for active navigation.
