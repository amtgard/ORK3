# Megiddo Refactor — Milestone Checklist

Track progress here. Check items when complete. Discovery sprint outputs (design notes) link from each DS section when available.

**Development steering:** All milestones must satisfy [05-development-steering.md](./05-development-steering.md) before sign-off (branch naming, full unit suite, one commit, mutation tests, commit message).

---

## Phase 0 — Foundation

### M0.1: Unit test and mutation framework

**Branch:** `megiddo/m0.1-test-framework`

Establish a unified test framework and Infection mutation testing before any refactor execution.

#### Unit tests

- [x] Document test conventions (location, naming, bootstrap, DB fixture strategy)
- [x] Create shared test bootstrap (replacing ad-hoc `die()` stubs in existing `*Service.test.php` files)
- [x] Define how to run **all** backend unit tests from CLI (single entry command — full suite only for sign-off)
- [x] Add CI hook or documented local workflow for pre-refactor test runs
- [x] Inventory existing tests and map to services:
  - [x] `orkservice/Player/PlayerService.test.php`
  - [x] `orkservice/Kingdom/KingdomService.test.php`
  - [x] `orkservice/Park/ParkService.test.php`
  - [x] `orkservice/Report/ReportService.test.php`
  - [x] `orkservice/Calendar/CalendarService.test.php`
  - [x] `orkservice/Event/EventService.test.php`
  - [x] `orkservice/Authorization/AuthorizationService.testrig.php`
- [x] Define frontend functional test approach (scope, tooling, what flows to cover)

#### Mutation testing (Infection)

- [x] Add `infection/infection` as a dev dependency
- [x] Add `infection.json5` scoped to `system/lib/ork3/` and `orkservice/`
- [x] Document coverage driver requirement (pcov, phpdbg, or Xdebug)
- [x] Document full-suite unit test command **and** milestone-scoped Infection command
- [x] Define initial `minMsi` / `minCoveredMsi` thresholds (start conservative; raise over time)
- [x] Pilot Infection on one existing service test target; confirm mutants are killed or gaps documented
- [x] Document how execution milestones scope Infection (paths/filters per R-* sprint)

#### Deliverable

- [x] Publish framework doc: `docs/megiddo/refactor/06-test-framework.md` (output of M0.1)

#### M0.1 sign-off gate

- [x] [05-development-steering.md](./05-development-steering.md) DS-1 through DS-8 satisfied
- [x] Full unit test suite passes
- [x] Pilot mutation run passes configured thresholds
- [x] Branch `megiddo/m0.1-test-framework` squashed to exactly one commit

**Exit criteria:** A developer can add a backend unit test, run the full suite, and run milestone-scoped Infection without inventing bootstrap code; frontend functional test strategy is documented.

---

### M0.2: Planning artifacts (this document set)

- [x] Code decomposition — [01-code-decomposition.md](./01-code-decomposition.md)
- [x] Requirements — [02-requirements.md](./02-requirements.md)
- [x] Detailed implementation plan — [03-implementation-plan.md](./03-implementation-plan.md)
- [x] Milestone checklist — this file
- [ ] Team review and sign-off on scope

---

## Phase 1 — Discovery Sprints

Each discovery sprint follows the same workflow for its target IDs (from [03-implementation-plan.md](./03-implementation-plan.md)):

1. **Backend survey** — Find duplicate or related code in `system/lib/ork3/` and `orkservice/*`; note gaps.
2. **Test design** — List backend unit tests, frontend functional tests, and **Infection scope** (paths/mutators) that would catch regression.
3. **Proposed revision** — Document intended move (service API + frontend replacement calls).

**Out of scope for discovery:** implementation, code changes, deciding final API signatures.

**Branch pattern:** `megiddo/ds-{nn}-{slug}` (one commit at sign-off; design-note-only changes still follow [05-development-steering.md](./05-development-steering.md) when committed).

**Discovery sprint sign-off (when design notes are committed):**

- [ ] DS-1, DS-2, DS-3, DS-6, DS-8 from [05-development-steering.md](./05-development-steering.md)
- [ ] Full unit test suite passes (DS-4, DS-5) — discovery must not break existing tests
- [ ] Infection scope documented in test design (DS-7 applies at execution, not discovery)

---

### DS-01: RSVP subsystem

**Branch:** `megiddo/ds-01-rsvp-discovery`

**Targets:** T-RSV-01 through T-RSV-09, T-INF-06

| Step | Status | Output link |
|------|--------|-------------|
| Backend survey | [x] | [ds-01-rsvp-discovery.md §1](./ds-01-rsvp-discovery.md#1-backend-survey) |
| Test design | [x] | [ds-01-rsvp-discovery.md §2](./ds-01-rsvp-discovery.md#2-test-design) |
| Proposed revision | [x] | [ds-01-rsvp-discovery.md §3](./ds-01-rsvp-discovery.md#3-proposed-revision) |

#### DS-01 sign-off gate

- [x] [05-development-steering.md](./05-development-steering.md) DS-1, DS-2, DS-3, DS-6, DS-8 satisfied
- [x] Full unit test suite passes (DS-4, DS-5)
- [x] Infection scope documented in test design (DS-7 at R-01)
- [x] Branch `megiddo/ds-01-rsvp-discovery` squashed to exactly one commit

---

### DS-02: Authorization INSERT bypass

**Branch:** `megiddo/ds-02-auth-insert-discovery`

**Targets:** T-ADM-11, T-KNA-03, T-PRA-02, T-EVA-06

| Step | Status | Output link |
|------|--------|-------------|
| Backend survey | [x] | [ds-02-auth-insert-discovery.md §1](./ds-02-auth-insert-discovery.md#1-backend-survey) |
| Test design | [x] | [ds-02-auth-insert-discovery.md §2](./ds-02-auth-insert-discovery.md#2-test-design) |
| Proposed revision | [x] | [ds-02-auth-insert-discovery.md §3](./ds-02-auth-insert-discovery.md#3-proposed-revision) |

#### DS-02 sign-off gate

- [x] [05-development-steering.md](./05-development-steering.md) DS-1, DS-2, DS-3, DS-6, DS-8 satisfied
- [x] Full unit test suite passes (DS-4, DS-5)
- [x] Infection scope documented in test design (DS-7 at R-02)
- [x] Branch `megiddo/ds-02-auth-insert-discovery` squashed to exactly one commit

---

### DS-03: Hero banner CRUD

**Branch:** `megiddo/ds-03-banner-discovery`

**Targets:** T-PLA-06, T-PRA-04, T-KNA-08, T-UNT-01, T-EVA-14

| Step | Status | Output link |
|------|--------|-------------|
| Backend survey | [x] | [ds-03-banner-discovery.md §1](./ds-03-banner-discovery.md#1-backend-survey) |
| Test design | [x] | [ds-03-banner-discovery.md §2](./ds-03-banner-discovery.md#2-test-design) |
| Proposed revision | [x] | [ds-03-banner-discovery.md §3](./ds-03-banner-discovery.md#3-proposed-revision) |

#### DS-03 sign-off gate

- [x] [05-development-steering.md](./05-development-steering.md) DS-1, DS-2, DS-3, DS-6, DS-8 satisfied
- [x] Full unit test suite passes (DS-4, DS-5)
- [x] Infection scope documented in test design (DS-7 at R-03)
- [x] Branch `megiddo/ds-03-banner-discovery` squashed to exactly one commit

---

### DS-04: EventAjax core

**Branch:** `megiddo/ds-04-eventajax-discovery`

**Targets:** T-EVA-01 through T-EVA-13

| Step | Status | Output link |
|------|--------|-------------|
| Backend survey | [x] | [ds-04-eventajax-discovery.md §1](./ds-04-eventajax-discovery.md#1-backend-survey) |
| Test design | [x] | [ds-04-eventajax-discovery.md §2](./ds-04-eventajax-discovery.md#2-test-design) |
| Proposed revision | [x] | [ds-04-eventajax-discovery.md §3](./ds-04-eventajax-discovery.md#3-proposed-revision) |

#### DS-04 sign-off gate

- [x] [05-development-steering.md](./05-development-steering.md) DS-1, DS-2, DS-3, DS-6, DS-8 satisfied
- [x] Full unit test suite passes (DS-4, DS-5)
- [x] Infection scope documented in test design (DS-7 at R-04)
- [x] Branch `megiddo/ds-04-eventajax-discovery` squashed to exactly one commit

---

### DS-05: Event controller detail

**Branch:** `megiddo/ds-05-event-discovery`

**Targets:** T-EVT-01 through T-EVT-08

| Step | Status | Output link |
|------|--------|-------------|
| Backend survey | [x] | [ds-05-event-discovery.md §1](./ds-05-event-discovery.md#1-backend-survey) |
| Test design | [x] | [ds-05-event-discovery.md §2](./ds-05-event-discovery.md#2-test-design) |
| Proposed revision | [x] | [ds-05-event-discovery.md §3](./ds-05-event-discovery.md#3-proposed-revision) |

#### DS-05 sign-off gate

- [x] [05-development-steering.md](./05-development-steering.md) DS-1, DS-2, DS-3, DS-6, DS-8 satisfied
- [x] Full unit test suite passes (DS-4, DS-5)
- [x] Infection scope documented in test design (DS-7 at R-05)
- [x] Branch `megiddo/ds-05-event-discovery` squashed to exactly one commit

---

### DS-06: Kingdom profile & AJAX

**Branch:** `megiddo/ds-06-kingdom-discovery`

**Targets:** T-KNG-01 through T-KNG-11, T-KNA-01, T-KNA-02, T-KNA-04 through T-KNA-07

| Step | Status | Output link |
|------|--------|-------------|
| Backend survey | [x] | [ds-06-kingdom-discovery.md §1](./ds-06-kingdom-discovery.md#1-backend-survey) |
| Test design | [x] | [ds-06-kingdom-discovery.md §2](./ds-06-kingdom-discovery.md#2-test-design) |
| Proposed revision | [x] | [ds-06-kingdom-discovery.md §3](./ds-06-kingdom-discovery.md#3-proposed-revision) |

#### DS-06 sign-off gate

- [x] [05-development-steering.md](./05-development-steering.md) DS-1, DS-2, DS-3, DS-6, DS-8 satisfied
- [x] Full unit test suite passes (DS-4, DS-5)
- [x] Infection scope documented in test design (DS-7 at R-06)
- [x] Branch `megiddo/ds-06-kingdom-discovery` squashed to exactly one commit

---

### DS-07: Park profile & AJAX

**Branch:** `megiddo/ds-07-park-discovery`

**Targets:** T-PRK-01 through T-PRK-05, T-PRA-01, T-PRA-03

| Step | Status | Output link |
|------|--------|-------------|
| Backend survey | [x] | [ds-07-park-discovery.md §1](./ds-07-park-discovery.md#1-backend-survey) |
| Test design | [x] | [ds-07-park-discovery.md §2](./ds-07-park-discovery.md#2-test-design) |
| Proposed revision | [x] | [ds-07-park-discovery.md §3](./ds-07-park-discovery.md#3-proposed-revision) |

#### DS-07 sign-off gate

- [x] [05-development-steering.md](./05-development-steering.md) DS-1, DS-2, DS-3, DS-6, DS-8 satisfied
- [x] Full unit test suite passes (DS-4, DS-5)
- [x] Infection scope documented in test design (DS-7 at R-07)
- [x] Branch `megiddo/ds-07-park-discovery` squashed to exactly one commit

---

### DS-08: Admin dashboard & health

**Branch:** `megiddo/ds-08-admin-discovery`

**Targets:** T-ADM-01 through T-ADM-10, T-ADM-12

| Step | Status | Output link |
|------|--------|-------------|
| Backend survey | [x] | [ds-08-admin-discovery.md §1](./ds-08-admin-discovery.md#1-backend-survey) |
| Test design | [x] | [ds-08-admin-discovery.md §2](./ds-08-admin-discovery.md#2-test-design) |
| Proposed revision | [x] | [ds-08-admin-discovery.md §3](./ds-08-admin-discovery.md#3-proposed-revision) |

#### DS-08 sign-off gate

- [x] [05-development-steering.md](./05-development-steering.md) DS-1, DS-2, DS-3, DS-6, DS-8 satisfied
- [x] Full unit test suite passes (DS-4, DS-5)
- [x] Infection scope documented in test design (DS-7 at R-08)
- [x] Branch `megiddo/ds-08-admin-discovery` squashed to exactly one commit

---

### DS-09: Player profile & AJAX

**Branch:** `megiddo/ds-09-player-discovery`

**Targets:** T-PLR-01 through T-PLR-08, T-PLA-01 through T-PLA-05, T-PLM-01 through T-PLM-04

| Step | Status | Output link |
|------|--------|-------------|
| Backend survey | [x] | [ds-09-player-discovery.md §1](./ds-09-player-discovery.md#1-backend-survey) |
| Test design | [x] | [ds-09-player-discovery.md §2](./ds-09-player-discovery.md#2-test-design) |
| Proposed revision | [x] | [ds-09-player-discovery.md §3](./ds-09-player-discovery.md#3-proposed-revision) |

#### DS-09 sign-off gate

- [x] [05-development-steering.md](./05-development-steering.md) DS-1, DS-2, DS-3, DS-6, DS-8 satisfied
- [x] Full unit test suite passes (DS-4, DS-5)
- [x] Infection scope documented in test design (DS-7 at R-09)
- [x] Branch `megiddo/ds-09-player-discovery` squashed to exactly one commit

---

### DS-10: Reports, voting rules, awards

**Targets:** T-RPT-01 through T-RPT-09, T-AWD-01

| Step | Status | Output link |
|------|--------|-------------|
| Backend survey | [ ] | |
| Test design | [ ] | |
| Proposed revision | [ ] | |

---

### DS-11: Search & player search

**Targets:** T-SRC-01, T-SRC-02, T-ADM-10, T-KNA-06, T-PRA-01, T-EVA-06 (search portion)

| Step | Status | Output link |
|------|--------|-------------|
| Backend survey | [ ] | |
| Test design | [ ] | |
| Proposed revision | [ ] | |

---

### DS-12: Attendance & sign-in

**Targets:** T-ATT-01 through T-ATT-06, T-SIN-01 through T-SIN-04, T-QR-01

| Step | Status | Output link |
|------|--------|-------------|
| Backend survey | [ ] | |
| Test design | [ ] | |
| Proposed revision | [ ] | |

---

### DS-13: Infrastructure & misc

**Targets:** T-INF-01 through T-INF-05, T-WN-01

| Step | Status | Output link |
|------|--------|-------------|
| Backend survey | [ ] | |
| Test design | [ ] | |
| Proposed revision | [ ] | |

---

### DS-14: Ork3::$Lib service migration

**Targets:** T-LIB-01 through T-LIB-05; cross-cutting `HasAuthority` usage

| Step | Status | Output link |
|------|--------|-------------|
| Backend survey | [ ] | |
| Test design | [ ] | |
| Proposed revision | [ ] | |

---

## Phase 2 — Refactor Execution

Execution sprints begin **after** the corresponding discovery sprint is complete and tests are written. Order is flexible but recommended:

| Exec sprint | Depends on | Target IDs |
|-------------|------------|------------|
| R-01 | DS-01, M0.1 | T-RSV-* |
| R-02 | DS-02 | T-ADM-11, T-KNA-03, T-PRA-02, T-EVA-06 |
| R-03 | DS-03 | Banner targets |
| R-04 | DS-04 | T-EVA-* |
| R-05 | DS-05 | T-EVT-* |
| R-06 | DS-06 | Kingdom targets |
| R-07 | DS-07 | Park targets |
| R-08 | DS-08 | Admin targets |
| R-09 | DS-09 | Player targets |
| R-10 | DS-10 | Reports/awards targets |
| R-11 | DS-11 | Search targets |
| R-12 | DS-12 | Attendance/sign-in targets |
| R-13 | DS-13 | Infrastructure targets |
| R-14 | DS-14 | Ork3::$Lib migration |

**Branch pattern:** `megiddo/r-{nn}-{slug}`

Per execution sprint checklist:

- [ ] [05-development-steering.md](./05-development-steering.md) DS-1 through DS-8 satisfied
- [ ] **Full** unit test suite passes (no partial run at sign-off)
- [ ] Milestone-scoped Infection run passes configured thresholds
- [ ] Frontend functional tests pass (when applicable to milestone)
- [ ] Target IDs marked done in [03-implementation-plan.md](./03-implementation-plan.md)
- [ ] No new `$DB` or unauthorized `Ork3::$Lib` usage introduced in touched files
- [ ] Branch squashed to exactly one commit; title and message match milestone

---

## Phase 3 — Completion

- [ ] All ~119 target IDs in implementation plan addressed
- [ ] Zero `$DB` in `orkui/` verified by audit (`rg '\$DB->' orkui/`)
- [ ] Zero unauthorized `Ork3::$Lib` in `orkui/` verified by audit
- [ ] Requirements success criteria in [02-requirements.md](./02-requirements.md) met
- [ ] Retrospective: lessons for future sprint hygiene

---

## Quick Reference

| Phase | Focus |
|-------|-------|
| **0** | Test + mutation framework + planning (current) |
| **1** | Discovery sprints — survey, tests, proposed revision |
| **2** | Refactor execution — move code, keep tests green |
| **3** | Audit and close-out |

**First actionable milestones:** M0.1 (test framework), then DS-01 (RSVP — highest duplication and user impact).
