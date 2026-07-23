# Megiddo Refactor — Agent Milestone Prompt

Copy the prompt below into a new agent session. Replace `{{MILESTONE}}` with a specific milestone (e.g. `V-00`, `V-01`, `R-01`) or leave the default instruction to pick the next unworked milestone.

---

## Prompt (copy from here)

```
You are working on the ORK3 Megiddo refactor — a planned migration of business logic and database access out of the PHP frontend (`orkui/`) into the domain layer (`system/lib/ork3/`) and service APIs (`orkservice/*`). The frontend should consume those APIs only; it must not duplicate domain rules or talk to the database directly.

## Project layout (high level)

- `orkui/` — Frontend MVC (controllers, models, templates)
- `orkservice/` — Backend SOAP/JSON services
- `system/lib/ork3/` — Domain classes and data access
- `system/lib/system/` — Framework (Controller, Model, APIModel, Session, …)
- `docs/megiddo/refactor/` — Refactor planning and steering (read these first)
- `tools/ork-db/` — Sandbox + mirror database tool (`bin/ork-db`)
- `tools/fuzzy-validator/` — Render stability gate (`bin/fuzzy-validator`)

## Documentation (read before coding)

| Doc | Path | Purpose |
|-----|------|---------|
| Code decomposition | `docs/megiddo/refactor/01-code-decomposition.md` | Architecture, layers, violation patterns |
| Requirements | `docs/megiddo/refactor/02-requirements.md` | Scope, FR/NFR, success criteria |
| Implementation plan | `docs/megiddo/refactor/03-implementation-plan.md` | Target IDs (T-*), class/method/line inventory |
| Milestone checklist | `docs/megiddo/refactor/04-milestone-checklist.md` | What to do and what is done |
| Development steering | `docs/megiddo/refactor/05-development-steering.md` | **Mandatory** branch, test, mutation, commit rules (DS-1–DS-8) |
| Test framework | `docs/megiddo/refactor/06-test-framework.md` | PHPUnit, Infection, E2E preflight, fuzzy gate |
| Phase 1.6 plan | `docs/megiddo/refactor/08-phase-16-validation-artifacts.md` | V-* validation artifacts for R-* |
| Validation index | `docs/megiddo/refactor/validations/README.md` | Canary URLs + test mutation boundaries per domain |

Prior-phase design notes (`ds-*-discovery.md`) and implemented tests (T-*) are **inputs** — do not re-run discovery or rewrite tests unless the active milestone explicitly says so.

## Steering (non-negotiable)

Follow `docs/megiddo/refactor/05-development-steering.md` in full:

- **DS-1:** Idiomatic ORK3 — match existing patterns; domain in `ork3/`, API in `orkservice/`.
- **DS-2:** PHP 8.2+ only.
- **DS-3:** One branch per milestone (`megiddo/m0.*`, `megiddo/ds-*`, `megiddo/t-*`, `megiddo/v-*`, or `megiddo/r-*`).
- **DS-4:** Full unit test suite must pass at sign-off — never partial runs for sign-off.
- **DS-5:** Full unit test suite must pass before commit.
- **DS-6:** Exactly one commit per milestone branch (squash before sign-off).
- **DS-7:** Milestone-scoped Infection mutation tests must pass (when M0.1 is complete).
- **DS-8:** Commit title and message must match the branch and milestone content.
- **Doc sign-off:** All edits under `docs/megiddo/refactor/` belong in the milestone's single commit on the active branch — never uncommitted, stashed, or deferred to another branch.

## Active milestone types (what to implement)

| Type | ID | Primary deliverable |
|------|-----|---------------------|
| **Validation** | V-00 | Global fuzzy setpoint URLs + dual-profile baselines ([v-00-fuzzy-setpoint.md](./validations/v-00-fuzzy-setpoint.md)) |
| **Validation** | V-01 … V-14 | Domain canary URLs + test mutation boundaries ([validations/_template-validation.md](./validations/_template-validation.md)) |
| **Execution** | R-01 … R-14 | Refactor per `03-implementation-plan.md`; sign-off uses matching `validations/v-{nn}-*.md` |

Phases 0–1.5 (M0.*, DS-*, T-*) are **complete** unless the checklist shows otherwise — do not reopen unless asked.

## Milestone to execute

{{MILESTONE}}

Default if not specified: **Identify and execute the next unworked milestone** in `docs/megiddo/refactor/04-milestone-checklist.md`. Work in order: Phase 0 → 1 → 1.5 (already done for most items) → **1.6 (V-00, then V-01…14)** → **2 (R-01…14)**. Skip completed milestones unless asked to revisit.

Record the chosen milestone ID, branch name, and target IDs (from `03-implementation-plan.md` or validation doc) at the start of your work.

---

## Process (follow in order)

### 1. Read the plan for this milestone

- Open `04-milestone-checklist.md` for the active V-* or R-* section.
- **V-00:** [08-phase-16-validation-artifacts.md](./08-phase-16-validation-artifacts.md) + [validations/v-00-fuzzy-setpoint.md](./validations/v-00-fuzzy-setpoint.md).
- **V-01 … V-14:** Matching `ds-{nn}-*.md` (context only) + [_template-validation.md](./validations/_template-validation.md); publish `validations/v-{nn}-*.md`.
- **R-{nn}:** Matching [validations/v-{nn}-*.md](./validations/) §2 (test mutation boundaries) + `03-implementation-plan.md` target IDs.
- Read [06-test-framework.md](./06-test-framework.md) for PHPUnit, Infection, and [E2E login preflight](./06-test-framework.md#e2e-login-credentials-preflight).

### 2. Branch hygiene

- Verify the previous milestone branch has **one commit** with all deliverables.
- Create the branch named in the checklist (`megiddo/v-*` or `megiddo/r-*`).
- All work on this branch only.

### 3. Implement

**V-00**

1. Preflight step 1 — expand `tools/fuzzy-validator/manifests/pages.json5` with major-interface setpoint URLs (1–3 per class: home, kingdom, park, admin, …).
2. Preflight step 2 — `bin/fuzzy-validator record --all --phase all` on **test** (`bin/ork-db use dev`, `megiddo` / `test-db-player`) and **mirror** (`bin/ork-db use prod`, `admin` / `password`).
3. Commit baselines + manifests; verify `validate` passes on same commit.

**V-01 … V-14**

1. Document 2–4 canary URL variants per refactor feature surface in `validations/v-{nn}-*.md` §1; register page ids in `pages.json5`.
2. Document test mutation boundaries in §2 — which T-* tests break when code migrates and what R-* may change.
3. Record fuzzy baselines for domain page ids on both profiles.

**R-01 … R-14**

1. Refactor target IDs per `03-implementation-plan.md` and matching `ds-{nn}-*.md` §3.
2. Adjust tests only within boundaries in `validations/v-{nn}-*.md` §2.3.
3. Do not defer new test writing — extend only when refactor exposes uncovered paths.

### 4. Test and validate

| Gate | V-* | R-* |
|------|-----|-----|
| Full PHPUnit | DS-4 (when code touched) | **Required** |
| Infection (milestone scope) | N/A unless code | **Required** |
| `bin/fuzzy-validator validate` (test + mirror) | After record | **Required** — page ids from validation doc §1 |
| Playwright e2e | When extending registry | Per T-* specs; must pass |

```bash
bin/ork-db deploy-sandbox
bin/fuzzy-validator validate --pages <ids-from-v-NN.md> --phase all
```

### 5. Update checklist

- Check off items in `04-milestone-checklist.md`.
- Update [validations/README.md](./validations/README.md) links for new V-* docs.
- Mark target IDs done in `03-implementation-plan.md` when R-* complete.

### 6. Commit

1. Full unit suite green (R-*; V-* when tooling/registry changed)
2. Stage all deliverables: code, `tools/fuzzy-validator/**`, validation docs, checklist
3. Squash to **one commit** (DS-6)
4. Title format: `V-01: RSVP validation artifacts and fuzzy canaries` or `R-01: RSVP refactor to EventService`

Do not merge or push unless explicitly asked.

Report: milestone ID, branch, commit hash, fuzzy validate result (test/mirror), Infection summary (R-*), checklist items completed, blockers.

---

Begin with step 1 now.
```

---

## Placeholder reference

| Placeholder | Replace with |
|-------------|----------------|
| `{{MILESTONE}}` | e.g. `V-00: Global fuzzy setpoint` or `V-01: RSVP validation artifacts` or `R-01: RSVP refactor execution` |

**Next unworked milestone:** Open `04-milestone-checklist.md` — **V-00**, then V-01 … V-14, then R-01 … R-14.

## Milestone quick map

| ID | Branch pattern | Primary activity |
|----|----------------|------------------|
| M0.1 | `megiddo/m0.1-test-framework` | Test bootstrap + Infection |
| M0.2 | *(planning only)* | Scope sign-off |
| DS-01 … DS-14 | `megiddo/ds-{nn}-{slug}` | *(complete)* Discovery design notes |
| T-01 … T-14 | `megiddo/t-{nn}-{slug}` | *(complete)* Test implementation + Infection |
| **V-00** | `megiddo/v-00-fuzzy-setpoint` | Global setpoint URLs + dual-profile capture |
| **V-01 … V-14** | `megiddo/v-{nn}-{slug}` | Domain canaries + test mutation boundaries |
| R-01 … R-14 | `megiddo/r-{nn}-{slug}` | Refactor + fuzzy validate + Infection |

## Related documents

- [04-milestone-checklist.md](./04-milestone-checklist.md)
- [05-development-steering.md](./05-development-steering.md)
- [08-phase-16-validation-artifacts.md](./08-phase-16-validation-artifacts.md)
