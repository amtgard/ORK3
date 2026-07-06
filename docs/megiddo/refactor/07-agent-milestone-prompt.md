# Megiddo Refactor — Agent Milestone Prompt

Copy the prompt below into a new agent session. Replace `{{MILESTONE}}` with a specific milestone (e.g. `M0.1`, `DS-01`, `T-01`, `R-03`) or leave the default instruction to pick the next unworked milestone.

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

## Documentation (read before coding)

| Doc | Path | Purpose |
|-----|------|---------|
| Code decomposition | `docs/megiddo/refactor/01-code-decomposition.md` | Architecture, layers, violation patterns |
| Requirements | `docs/megiddo/refactor/02-requirements.md` | Scope, FR/NFR, success criteria |
| Implementation plan | `docs/megiddo/refactor/03-implementation-plan.md` | Target IDs (T-*), class/method/line inventory |
| Milestone checklist | `docs/megiddo/refactor/04-milestone-checklist.md` | What to do and what is done |
| Development steering | `docs/megiddo/refactor/05-development-steering.md` | **Mandatory** branch, test, mutation, commit rules (DS-1–DS-8) |
| Test framework | `docs/megiddo/refactor/06-test-framework.md` | Full-suite command, Infection scope (after M0.1) |

## Steering (non-negotiable)

Follow `docs/megiddo/refactor/05-development-steering.md` in full:

- **DS-1:** Idiomatic ORK3 — match existing patterns; domain in `ork3/`, API in `orkservice/`.
- **DS-2:** PHP 8.2+ only.
- **DS-3:** One branch per milestone (`megiddo/m0.*`, `megiddo/ds-*`, `megiddo/t-*`, or `megiddo/r-*`).
- **DS-4:** Full unit test suite must pass at sign-off — never partial runs for sign-off.
- **DS-5:** Full unit test suite must pass before commit.
- **DS-6:** Exactly one commit per milestone branch (squash before sign-off).
- **DS-7:** Milestone-scoped Infection mutation tests must pass (when M0.1 is complete).
- **DS-8:** Commit title and message must match the branch and milestone content.
- **Doc sign-off:** All edits under `docs/megiddo/refactor/` belong in the milestone's single commit on the active branch — never uncommitted, stashed, or deferred to another branch.

Discovery sprints (DS-*): survey, test design, proposed revision — no implementation unless the milestone explicitly includes code.

Test sprints (T-*): implement tests and pass Infection per matching DS-* design note §2 — no production refactor.

Execution sprints (R-*): implement refactor for target IDs in `03-implementation-plan.md` after the matching DS-* and T-* milestones are complete.

## Milestone to execute

T-01

Default if not specified: **Identify and execute the next unworked milestone** in `docs/megiddo/refactor/04-milestone-checklist.md`. Work in order: complete Phase 0 (M0.1 before M0.2 sign-off items that need code), then DS-01, DS-02, …, then T-01, T-02, …, then R-01, R-02, … Skip milestones whose checklist items are already checked unless asked to revisit.

Record the chosen milestone ID, branch name, and target IDs (from `03-implementation-plan.md`) at the start of your work.

---

## Process (follow in order)

### 1. Familiarize yourself with the project documents

Read, at minimum:

- `docs/megiddo/refactor/01-code-decomposition.md`
- `docs/megiddo/refactor/02-requirements.md`
- `docs/megiddo/refactor/05-development-steering.md`

Skim `03-implementation-plan.md` for targets relevant to your milestone. Use `04-milestone-checklist.md` as the source of truth for scope and completion state.

If `06-test-framework.md` exists, read it for test and Infection commands. If M0.1 is not done, note that mutation sign-off (DS-7) applies once that doc exists.

### 2. Familiarize yourself with the milestone you are going to work on

In `04-milestone-checklist.md`:

- Locate the milestone section (M0.*, DS-*, T-*, or R-*).
- Note branch name, checklist items, exit criteria, and linked target IDs in `03-implementation-plan.md`.
- For DS-*: confirm outputs are design notes (backend survey, test design, proposed revision) — not code unless this milestone is M0.1 or another foundation item that requires implementation.
- For T-*: confirm the matching DS-* discovery is complete; implement tests per DS design note §2 only — no refactor.
- For R-*: confirm the corresponding DS-* discovery and T-* test sprint are complete.

State the milestone ID, branch name, and target IDs before proceeding.

### 3. Ensure the last milestone has its work staged and committed on its branch

- Identify the previous milestone in sequence.
- Verify its branch exists with **exactly one commit** containing all of that milestone's work (DS-6).
- If the previous milestone is incomplete or uncommitted, finish or commit it on its branch before starting a new milestone. Do not start new work on the wrong branch.

### 4. Create a new milestone branch

From the integration branch - we are using stacked branches, so it will be the last branch (but may be main for the first milestone):

- Create the branch named in the milestone checklist (see DS-3 in `05-development-steering.md`).
- Examples: `megiddo/m0.1-test-framework`, `megiddo/ds-01-rsvp-discovery`, `megiddo/t-01-rsvp-tests`, `megiddo/r-01-rsvp-refactor`.
- Do all milestone work on this branch only.

### 5. Create unit test coverage for this milestone, if relevant

- **Discovery (DS-*):** Document required tests in the design note (unit, functional, Infection scope). Do not implement production refactor code or test code.
- **Test development (T-*):** Implement backend unit/integration and frontend functional tests per matching DS-* design note §2. Run milestone-scoped Infection; improve tests until mutants in scope are killed. No production refactor.
- **Execution (R-*):** Refactor only — tests were delivered in the matching T-* sprint. Extend tests only when the refactor exposes new code paths not covered by T-*.
- **M0.1:** Bootstrap and tests are the primary deliverable.

Run partial tests during development if helpful; do not use partial runs for sign-off (DS-4).

### 6. Implement the milestone, if relevant

- **DS-* (discovery):** Produce design notes; link them from `04-milestone-checklist.md`. No refactor or test implementation.
- **T-* (test development):** Implement tests per DS design note; no production refactor.
- **R-* / M0.1:** Implement per discovery notes and `03-implementation-plan.md` targets. Code must be idiomatic ORK3 (DS-1), PHP 8.2+ (DS-2). No drive-by changes.

When implementation applies (R-* / M0.1), run milestone-scoped Infection after M0.1 and fix escaped mutants or document justified exceptions. T-* milestones run Infection on pre-refactor code to validate test quality.

### 7. Update the milestone checklist with work completed

Edit files under `docs/megiddo/refactor/` as needed:

- Check off completed items in `04-milestone-checklist.md`.
- Add links to design notes or PRs in DS output tables where applicable.
- Mark target IDs done in `03-implementation-plan.md` when execution is complete.
- Update design notes, steering, or test-framework docs when the milestone changes them.

These doc updates are **deliverables** — they must be included in the milestone sign-off commit (see step 8).

### 8. Stage and commit on your working branch

Before commit:

1. Run the **full** unit test suite — all tests must pass (DS-4, DS-5).
2. Run milestone-scoped Infection if applicable (DS-7).
3. Stage **all** milestone deliverables: code, tests, and every modified file under `docs/megiddo/refactor/` on the active milestone branch. Do not stash doc edits for a later branch or leave them uncommitted.
4. Squash to **exactly one commit** on the milestone branch (DS-6).
5. Commit with title and message that match the branch and milestone (DS-8).

Example commit title: `M0.1: Add unified test bootstrap and Infection mutation testing`

Do not merge or push unless explicitly asked. Report: milestone ID, branch name, commit hash, checklist items completed, test/mutation command output summary, and any blockers.

---

Begin with step 1 now.
```

---

## Placeholder reference

| Placeholder | Replace with |
|-------------|----------------|
| `{{MILESTONE}}` | e.g. `M0.1: Unit test and mutation framework` or `DS-01: RSVP subsystem` or `T-01: RSVP tests` or `R-01: RSVP refactor execution` |

**Next unworked milestone:** Open `04-milestone-checklist.md` and select the first milestone with unchecked items, in phase order (M0.1 → M0.2 → DS-01 → … → T-01 → … → R-01 → …).

## Milestone quick map

| ID | Branch pattern | Primary activity |
|----|----------------|------------------|
| M0.1 | `megiddo/m0.1-test-framework` | Test bootstrap + Infection |
| M0.2 | *(planning only)* | Scope sign-off |
| DS-01 … DS-14 | `megiddo/ds-{nn}-{slug}` | Discovery design notes |
| T-01 … T-14 | `megiddo/t-{nn}-{slug}` | Test implementation + Infection |
| R-01 … R-14 | `megiddo/r-{nn}-{slug}` | Refactor implementation |

## Related documents

- [04-milestone-checklist.md](./04-milestone-checklist.md)
- [05-development-steering.md](./05-development-steering.md)
