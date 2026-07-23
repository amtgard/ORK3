# PR #492 Review Fixes

Address [PR #492](https://github.com/amtgard/ORK3/pull/492) review comments from `@baltinerdist` on branch `fix-pr-492` (forked from `megiddo/fuzzy-validator-v2`).

| Doc | Purpose |
|-----|---------|
| [plan.md](./plan.md) | Per-comment analysis, fix proposals, test plans |
| [checklist.md](./checklist.md) | Numbered work checklist (progress state) |
| [orchestrator.prompt](./orchestrator.prompt) | Fix orchestrator instructions |

**Policy decisions baked into the plan:**

- **C-17:** Keep `reactivateInactiveMundane` on `AddAttendance`; remove it from `UseAttendanceLink` (restore master parity for the public link path).
- Non-inline “behavior changes worth confirming” from the top-level review are **out of scope** for this fix series except as noted in plan.md § Behavior triage (no commits unless a later pass is requested).
