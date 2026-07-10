# Megiddo Refactor

Migrate business logic and `$DB` access out of `orkui/` into `system/lib/ork3/` and `orkservice/*`.

**Current phase:** **2 continuation — R-15 … R-18** (after R-01 … R-14 complete)  
**Next milestone:** [R-15](./04-milestone-checklist.md) (HasAuthority rollout) · [continuation plan](./10-phase-2-continuation.md)

Phases 0–1.6 and Phase 2 core (R-01 … R-14) are **complete**. Phase 2 continuation: **R-15 … R-18**. Phase 3 after R-18: [11-phase-3-closeout.md](./11-phase-3-closeout.md).

**Last rebase:** 2026-07-09 onto `origin/master` @ `e6417645` (`megiddo/rebase-20260709`; [RB checklist](./skills/rebase-and-redocument/milestone-checklist.md) complete).

---

## Start here

| I want to… | Read |
|------------|------|
| **Rebase** onto master (orchestrator → serialized RB-* sub-agents) | [skills/rebase-and-redocument/agent-prompt.md](./skills/rebase-and-redocument/agent-prompt.md) (Orchestrator) · [SKILL.md](./skills/rebase-and-redocument/SKILL.md) · [checklist](./skills/rebase-and-redocument/milestone-checklist.md) |
| **Execute** the next R-* sprint | [skills/refactor-execution/agent-prompt.md](./skills/refactor-execution/agent-prompt.md) (orchestrator) · [07-agent-milestone-prompt.md](./07-agent-milestone-prompt.md) (manual) |
| See **what’s left** | [04-milestone-checklist.md](./04-milestone-checklist.md) · [10-phase-2-continuation.md](./10-phase-2-continuation.md) · [11-phase-3-closeout.md](./11-phase-3-closeout.md) |
| **Finish R-15 … R-18** | [skills/refactor-execution/orchestrator-phase2-continuation.prompt](./skills/refactor-execution/orchestrator-phase2-continuation.prompt) |
| **Phase 3 automated audit** | [skills/phase3-closeout/orchestrator.prompt](./skills/phase3-closeout/orchestrator.prompt) |
| **Manual smoke matrix** | [validations/r-milestone-smoke-matrix.html](./validations/r-milestone-smoke-matrix.html) |
| Find **target IDs** | [03-implementation-plan.md](./03-implementation-plan.md) |
| Follow **branch / test / commit rules** | [05-development-steering.md](./05-development-steering.md) |
| Run **PHPUnit / Infection / fuzzy** | [06-test-framework.md](./06-test-framework.md) |
| Use **canaries + mutation boundaries** | [validations/README.md](./validations/README.md) |

---

## Active documents

| Doc | Purpose |
|-----|---------|
| [01-code-decomposition.md](./01-code-decomposition.md) | Architecture, layers, violation patterns |
| [02-requirements.md](./02-requirements.md) | Scope, FR/NFR, success criteria |
| [03-implementation-plan.md](./03-implementation-plan.md) | Target inventory (class / method / lines) |
| [04-milestone-checklist.md](./04-milestone-checklist.md) | **R-* progress** + Phase 3 audit |
| [10-phase-2-continuation.md](./10-phase-2-continuation.md) | R-15 … R-18 scope + carryover audit |
| [11-phase-3-closeout.md](./11-phase-3-closeout.md) | **Phase 3** plan, HTML smoke matrix, agent prompts |
| [05-development-steering.md](./05-development-steering.md) | DS-1–DS-8 |
| [06-test-framework.md](./06-test-framework.md) | PHPUnit, Infection, E2E, fuzzy gate |
| [07-agent-milestone-prompt.md](./07-agent-milestone-prompt.md) | Copy-paste R-* agent prompt |
| [validations/](./validations/) | Per-domain canaries + test mutation boundaries |
| `ds-*-discovery.md` | Discovery notes (inputs for R-*) |

---

## Tools (shipped)

| Tool | CLI | Docs |
|------|-----|------|
| Sandbox DB | `bin/ork-db` | [../test-database-tool/README.md](../test-database-tool/README.md) |
| Fuzzy validator | `bin/fuzzy-validator` | [../fuzzy-validator/README.md](../fuzzy-validator/README.md) |

---

## Archive

Completed phase plans and historical checklists: [archive/](./archive/)
