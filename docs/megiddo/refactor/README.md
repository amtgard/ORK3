# Megiddo Refactor

Migrate business logic and `$DB` access out of `orkui/` into `system/lib/ork3/` and `orkservice/*`.

**Current phase:** **2 — Refactor execution (R-01 … R-14)**  
**Next milestone:** [R-01](./04-milestone-checklist.md) (RSVP)

Phases 0–1.6 (framework, discovery, characterization tests, validation artifacts) and supporting tools (`ork-db`, fuzzy-validator) are **complete**.

---

## Start here

| I want to… | Read |
|------------|------|
| **Rebase** onto master (orchestrator → serialized RB-* sub-agents) | [skills/rebase-and-redocument/agent-prompt.md](./skills/rebase-and-redocument/agent-prompt.md) (Orchestrator) · [SKILL.md](./skills/rebase-and-redocument/SKILL.md) · [checklist](./skills/rebase-and-redocument/milestone-checklist.md) |
| **Execute** the next R-* sprint | [07-agent-milestone-prompt.md](./07-agent-milestone-prompt.md) |
| See **what’s left** | [04-milestone-checklist.md](./04-milestone-checklist.md) |
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
| [04-milestone-checklist.md](./04-milestone-checklist.md) | **R-* progress** |
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
