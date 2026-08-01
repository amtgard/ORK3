# PR #492 Review Fixes

Address [PR #492](https://github.com/amtgard/ORK3/pull/492) review comments from `@baltinerdist` on branch `fix-pr-492` (forked from `megiddo/fuzzy-validator-v2`).

| Doc | Purpose |
|-----|---------|
| [plan.md](./plan.md) | Per-comment analysis, fix proposals, test plans |
| [checklist.md](./checklist.md) | Numbered work checklist (progress state) |
| [orchestrator.prompt](./orchestrator.prompt) | Fix orchestrator instructions |

**Policy decisions baked into the plan:**

- **C-17 (Rev2):** Keep `reactivateInactiveMundane` on `AddAttendance`; remove it from `UseAttendanceLink` (restore master parity for the public link path).
- **C-25 (Rev4):** Narrow `AddAttendance` reactivation further — only when explicit `ReactivateInactive` is set by the park-add path, with a dangeraudit entry.
- **C-27 (Rev4):** Restore master park ladder-grid behavior (no park→`kingdom_id` derivation).
- **C-31 (Rev4):** Auth inventory is analysis/documentation only; do not mass-gate pre-existing master surfaces.

**Rev4 (Batch 5):** C-19…C-31 address `@baltinerdist` adversarial review at `20b0f61f`. Drive via [`orchestrator.prompt`](./orchestrator.prompt).
