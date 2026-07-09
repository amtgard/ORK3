# Megiddo Refactor — Milestone Checklist

Track progress here. Check items when complete. Discovery sprint outputs (design notes) link from each DS section when available.

**Development steering:** All milestones must satisfy [05-development-steering.md](./05-development-steering.md) before sign-off (branch naming, full unit suite, one commit, mutation tests, commit message).

**E2E preflight (V-* and R-*):** Before sign-off on milestones with auth-gated Playwright or fuzzy-validator flows, complete [06-test-framework.md § E2E login credentials (preflight)](./06-test-framework.md#e2e-login-credentials-preflight). Do **not** use the local `class.Authorization.php` password bypass.

**Documentation sign-off (every milestone):** Any edits under `docs/megiddo/refactor/` made during the milestone — including checklist checkoffs, design notes, steering, requirements, and implementation-plan updates — must be **committed on the active milestone branch** as part of that milestone's single sign-off commit (DS-6). Do not leave planning docs uncommitted, stashed for a later branch, or split onto a separate docs-only commit after sign-off.

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
- [x] Document how T-* and R-* milestones scope Infection (paths/filters per sprint)

#### Deliverable

- [x] Publish framework doc: `docs/megiddo/refactor/06-test-framework.md` (output of M0.1)

#### M0.1 sign-off gate

- [x] [05-development-steering.md](./05-development-steering.md) DS-1 through DS-8 satisfied
- [x] Full unit test suite passes
- [x] Pilot mutation run passes configured thresholds
- [x] Planning doc updates committed on milestone branch (see Documentation sign-off above)
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
2. **Test design** — List backend unit tests, frontend functional tests, and **Infection scope** (paths/mutators) for the matching T-* test sprint (Phase 1.5).
3. **Proposed revision** — Document intended move (service API + frontend replacement calls).

**Out of scope for discovery:** implementation, code changes, deciding final API signatures.

**Branch pattern:** `megiddo/ds-{nn}-{slug}` (one commit at sign-off; design-note-only changes still follow [05-development-steering.md](./05-development-steering.md) when committed).

**Discovery sprint sign-off (when design notes are committed):**

- [ ] DS-1, DS-2, DS-3, DS-6, DS-8 from [05-development-steering.md](./05-development-steering.md)
- [ ] Full unit test suite passes (DS-4, DS-5) — discovery must not break existing tests
- [ ] Infection scope documented in test design (DS-7 applies at T-* implementation, not discovery)
- [ ] All `docs/megiddo/refactor/` updates committed on the milestone branch (Documentation sign-off above)

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
- [x] Infection scope documented in test design (implemented in T-01)
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
- [x] Infection scope documented in test design (implemented in T-02)
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
- [x] Infection scope documented in test design (implemented in T-03)
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
- [x] Infection scope documented in test design (implemented in T-04)
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
- [x] Infection scope documented in test design (implemented in T-05)
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
- [x] Infection scope documented in test design (implemented in T-06)
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
- [x] Infection scope documented in test design (implemented in T-07)
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
- [x] Infection scope documented in test design (implemented in T-08)
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
- [x] Infection scope documented in test design (implemented in T-09)
- [x] Branch `megiddo/ds-09-player-discovery` squashed to exactly one commit

---

### DS-10: Reports, voting rules, awards

**Branch:** `megiddo/ds-10-reports-discovery`

**Targets:** T-RPT-01 through T-RPT-09, T-AWD-01

| Step | Status | Output link |
|------|--------|-------------|
| Backend survey | [x] | [ds-10-reports-discovery.md §1](./ds-10-reports-discovery.md#1-backend-survey) |
| Test design | [x] | [ds-10-reports-discovery.md §2](./ds-10-reports-discovery.md#2-test-design) |
| Proposed revision | [x] | [ds-10-reports-discovery.md §3](./ds-10-reports-discovery.md#3-proposed-revision) |

#### DS-10 sign-off gate

- [x] [05-development-steering.md](./05-development-steering.md) DS-1, DS-2, DS-3, DS-6, DS-8 satisfied
- [x] Full unit test suite passes (DS-4, DS-5)
- [x] Infection scope documented in test design (implemented in T-10)
- [x] Branch `megiddo/ds-10-reports-discovery` squashed to exactly one commit

---

### DS-11: Search & player search

**Branch:** `megiddo/ds-11-search-discovery`

**Targets:** T-SRC-01, T-SRC-02, T-ADM-10, T-KNA-06, T-PRA-01, T-EVA-06 (search portion)

| Step | Status | Output link |
|------|--------|-------------|
| Backend survey | [x] | [ds-11-search-discovery.md §1](./ds-11-search-discovery.md#1-backend-survey) |
| Test design | [x] | [ds-11-search-discovery.md §2](./ds-11-search-discovery.md#2-test-design) |
| Proposed revision | [x] | [ds-11-search-discovery.md §3](./ds-11-search-discovery.md#3-proposed-revision) |

#### DS-11 sign-off gate

- [x] [05-development-steering.md](./05-development-steering.md) DS-1, DS-2, DS-3, DS-6, DS-8 satisfied
- [x] Full unit test suite passes (DS-4, DS-5)
- [x] Infection scope documented in test design (implemented in T-11)
- [x] Branch `megiddo/ds-11-search-discovery` squashed to exactly one commit

---

### DS-12: Attendance & sign-in

**Branch:** `megiddo/ds-12-attendance-discovery`

**Targets:** T-ATT-01 through T-ATT-06, T-SIN-01 through T-SIN-04, T-QR-01

| Step | Status | Output link |
|------|--------|-------------|
| Backend survey | [x] | [ds-12-attendance-discovery.md §1](./ds-12-attendance-discovery.md#1-backend-survey) |
| Test design | [x] | [ds-12-attendance-discovery.md §2](./ds-12-attendance-discovery.md#2-test-design) |
| Proposed revision | [x] | [ds-12-attendance-discovery.md §3](./ds-12-attendance-discovery.md#3-proposed-revision) |

#### DS-12 sign-off gate

- [x] [05-development-steering.md](./05-development-steering.md) DS-1, DS-2, DS-3, DS-6, DS-8 satisfied
- [x] Full unit test suite passes (DS-4, DS-5)
- [x] Infection scope documented in test design (implemented in T-12)
- [x] Branch `megiddo/ds-12-attendance-discovery` squashed to exactly one commit

---

### DS-13: Infrastructure & misc

**Branch:** `megiddo/ds-13-infrastructure-discovery`

**Targets:** T-INF-01 through T-INF-05, T-WN-01 (T-INF-06 home RSVP batch — see design note)

| Step | Status | Output link |
|------|--------|-------------|
| Backend survey | [x] | [ds-13-infrastructure-discovery.md §1](./ds-13-infrastructure-discovery.md#1-backend-survey) |
| Test design | [x] | [ds-13-infrastructure-discovery.md §2](./ds-13-infrastructure-discovery.md#2-test-design) |
| Proposed revision | [x] | [ds-13-infrastructure-discovery.md §3](./ds-13-infrastructure-discovery.md#3-proposed-revision) |

#### DS-13 sign-off gate

- [x] [05-development-steering.md](./05-development-steering.md) DS-1, DS-2, DS-3, DS-6, DS-8 satisfied
- [x] Full unit test suite passes (DS-4, DS-5)
- [x] Infection scope documented in test design (implemented in T-13)
- [x] Branch `megiddo/ds-13-infrastructure-discovery` squashed to exactly one commit

---

### DS-14: Ork3::$Lib service migration

**Branch:** `megiddo/ds-14-lib-service-discovery`

**Targets:** T-LIB-01 through T-LIB-05; cross-cutting `HasAuthority` usage

| Step | Status | Output link |
|------|--------|-------------|
| Backend survey | [x] | [ds-14-lib-service-discovery.md §1](./ds-14-lib-service-discovery.md#1-backend-survey) |
| Test design | [x] | [ds-14-lib-service-discovery.md §2](./ds-14-lib-service-discovery.md#2-test-design) |
| Proposed revision | [x] | [ds-14-lib-service-discovery.md §3](./ds-14-lib-service-discovery.md#3-proposed-revision) |

#### DS-14 sign-off gate

- [x] [05-development-steering.md](./05-development-steering.md) DS-1, DS-2, DS-3, DS-6, DS-8 satisfied
- [x] Full unit test suite passes (DS-4, DS-5)
- [x] Infection scope documented in test design (implemented in T-14)
- [x] Branch `megiddo/ds-14-lib-service-discovery` squashed to exactly one commit

---

## Phase 1.5 — Test Development

Test sprints implement the test plans from Phase 1 discovery **before** refactor execution. Each **T-{nn}** milestone pairs with the matching **DS-{nn}** / **R-{nn}** sprint.

**Naming:** Milestone IDs `T-01` … `T-14` denote **test sprints** (this phase). Refactor **target IDs** in [03-implementation-plan.md](./03-implementation-plan.md) (e.g. `T-RSV-01`, `T-ADM-11`) use a different `T-*` prefix and are unchanged.

**Workflow per test sprint:**

1. **Test coverage** — Implement backend unit/integration tests and frontend functional tests per the matching DS-* design note §2, covering all refactor target sites for that sprint.
2. **Infection gate** — Run milestone-scoped Infection per DS-* §2.3; improve tests until configured thresholds pass and mutants in scope are killed (DS-7).

**Out of scope for test sprints:** production refactor, moving logic out of `orkui/`, or changing API signatures (those belong in R-*).

**Branch pattern:** `megiddo/t-{nn}-{slug}` (one commit at sign-off; follow [05-development-steering.md](./05-development-steering.md)).

**Test sprint sign-off (every T-*):**

- [ ] [05-development-steering.md](./05-development-steering.md) DS-1 through DS-8 satisfied
- [ ] E2E login preflight complete when milestone includes auth-gated Playwright specs ([06-test-framework.md § preflight](./06-test-framework.md#e2e-login-credentials-preflight))
- [ ] Backend tests implemented per matching DS-* design note §2.1
- [ ] Frontend functional tests implemented per matching DS-* design note §2 (frontend functional subsection, when applicable)
- [ ] **Full** unit test suite passes (DS-4, DS-5)
- [ ] Milestone-scoped Infection run passes configured thresholds (DS-7)
- [ ] All `docs/megiddo/refactor/` updates committed on the milestone branch (Documentation sign-off above)
- [ ] Branch `megiddo/t-{nn}-{slug}` squashed to exactly one commit

---

### T-01: RSVP subsystem tests

**Branch:** `megiddo/t-01-rsvp-tests`

**Depends on:** DS-01, M0.1

**Design source:** [ds-01-rsvp-discovery.md §2](./ds-01-rsvp-discovery.md#2-test-design)

**Targets:** T-RSV-01 through T-RSV-09, T-INF-06 (characterization coverage for sites impacted by R-01)

| Step | Status |
|------|--------|
| Backend unit/integration tests | [x] |
| Frontend functional tests | [x] |
| Milestone-scoped Infection passes | [x] |

**Tests:** `tests/Integration/EventRsvpTest.php`, `EventRsvpAjaxTest.php`, `EventRsvpSearchTest.php`, `tests/Unit/EventRsvpValidationTest.php`, `tests/e2e/rsvp.spec.ts`

**Infection (pre-refactor):** `infection.t01-rsvp.json5` — `--filter=model.Event.php --test-framework-options="--filter=EventRsvp"` (MSI 52%, covered MSI 63%)

#### T-01 sign-off gate

- [x] Test sprint sign-off checklist (above) satisfied
- [x] Branch `megiddo/t-01-rsvp-tests` squashed to exactly one commit

---

### T-02: Authorization INSERT bypass tests

**Branch:** `megiddo/t-02-auth-insert-tests`

**Depends on:** DS-02

**Design source:** [ds-02-auth-insert-discovery.md §2](./ds-02-auth-insert-discovery.md#2-test-design)

**Targets:** T-ADM-11, T-KNA-03, T-PRA-02, T-EVA-06

| Step | Status |
|------|--------|
| Backend unit/integration tests | [x] |
| Frontend functional tests | [x] |
| Milestone-scoped Infection passes | [x] |

**Tests:** `tests/Integration/AuthorizationAddTest.php`, `tests/Support/AuthorizationAddFixture.php`, `tests/e2e/auth-permissions.spec.ts`

**Infection (pre-refactor):** `infection.t02-auth-insert.json5` — `--only-covered --filter=class.Authorization.php --test-framework-options="--filter=AuthorizationAddTest"` (MSI 42%, covered MSI 42%)

#### T-02 sign-off gate

- [x] Test sprint sign-off checklist (above) satisfied
- [x] Branch `megiddo/t-02-auth-insert-tests` squashed to exactly one commit

---

### T-03: Hero banner CRUD tests

**Branch:** `megiddo/t-03-banner-tests`

**Depends on:** DS-03

**Design source:** [ds-03-banner-discovery.md §2](./ds-03-banner-discovery.md#2-test-design)

**Targets:** T-PLA-06, T-PRA-04, T-KNA-08, T-UNT-01, T-EVA-14

| Step | Status |
|------|--------|
| Backend unit/integration tests | [x] |
| Frontend functional tests | [x] |
| Milestone-scoped Infection passes | [x] |

**Tests:** `tests/Integration/BannerTest.php`, `tests/Support/BannerFixture.php`, `tests/e2e/banner.spec.ts`

**Infection (pre-refactor):** `infection.t03-banner.json5` — `--only-covered --filter=class.Park.php --test-framework-options="--filter=BannerTest"` (MSI 55%, covered MSI 55%)

#### T-03 sign-off gate

- [x] Test sprint sign-off checklist (above) satisfied
- [x] Branch `megiddo/t-03-banner-tests` squashed to exactly one commit

---

### T-04: EventAjax core tests

**Branch:** `megiddo/t-04-eventajax-tests`

**Depends on:** DS-04

**Design source:** [ds-04-eventajax-discovery.md §2](./ds-04-eventajax-discovery.md#2-test-design)

**Targets:** T-EVA-01 through T-EVA-13

| Step | Status |
|------|--------|
| Backend unit/integration tests | [x] |
| Frontend functional tests | [x] |
| Milestone-scoped Infection passes | [x] |

**Tests:** `tests/Integration/EventPlanningTest.php`, `tests/Integration/EventAttendanceAjaxTest.php`, `tests/Support/EventPlanningFixture.php`, `tests/e2e/event-planning.spec.ts`

**Infection (pre-refactor):** `infection.t04-eventajax.json5` — `--only-covered --filter=class.Event.php --test-framework-options="--filter=EventPlanningTest"` (MSI 48%, covered MSI 48%)

#### T-04 sign-off gate

- [x] Test sprint sign-off checklist (above) satisfied
- [x] Branch `megiddo/t-04-eventajax-tests` squashed to exactly one commit

---

### T-05: Event controller detail tests

**Branch:** `megiddo/t-05-event-tests`

**Depends on:** DS-05

**Design source:** [ds-05-event-discovery.md §2](./ds-05-event-discovery.md#2-test-design)

**Targets:** T-EVT-01 through T-EVT-08

| Step | Status |
|------|--------|
| Backend unit/integration tests | [x] |
| Frontend functional tests | [x] |
| Milestone-scoped Infection passes | [x] |

**Tests:** `tests/Integration/EventOccurrenceTest.php`, `tests/Integration/EventRsvpBatchTest.php`, `tests/e2e/event-detail.spec.ts`

**Infection (pre-refactor):** `infection.t05-event.json5` — `--only-covered --filter=class.Event.php --test-framework-options="--filter=EventOccurrenceTest|EventRsvpBatchTest"` (MSI 37%, covered MSI 37%)

#### T-05 sign-off gate

- [x] Test sprint sign-off checklist (above) satisfied
- [x] Branch `megiddo/t-05-event-tests` squashed to exactly one commit

---

### T-06: Kingdom profile & AJAX tests

**Branch:** `megiddo/t-06-kingdom-tests`

**Depends on:** DS-06

**Design source:** [ds-06-kingdom-discovery.md §2](./ds-06-kingdom-discovery.md#2-test-design)

**Targets:** T-KNG-01 through T-KNG-11, T-KNA-01, T-KNA-02, T-KNA-04 through T-KNA-07

| Step | Status |
|------|--------|
| Backend unit/integration tests | [x] |
| Frontend functional tests | [x] |
| Milestone-scoped Infection passes | [x] |

**Tests:** `tests/Integration/KingdomProfileTest.php`, `tests/Integration/KingdomAjaxTest.php`, `tests/e2e/kingdom-profile.spec.ts`

**Infection (pre-refactor):** `infection.t06-kingdom.json5` — `--only-covered --filter=class.Kingdom.php --filter=class.Report.php --test-framework-options="--filter=KingdomProfileTest|KingdomAjaxTest"` (MSI 43%, covered MSI 43%)

#### T-06 sign-off gate

- [x] Test sprint sign-off checklist (above) satisfied
- [x] Branch `megiddo/t-06-kingdom-tests` squashed to exactly one commit

---

### T-07: Park profile & AJAX tests

**Branch:** `megiddo/t-07-park-tests`

**Depends on:** DS-07

**Design source:** [ds-07-park-discovery.md §2](./ds-07-park-discovery.md#2-test-design)

**Targets:** T-PRK-01 through T-PRK-05, T-PRA-01, T-PRA-03

| Step | Status |
|------|--------|
| Backend unit/integration tests | [x] |
| Frontend functional tests | [x] |
| Milestone-scoped Infection passes | [x] |

**Tests:** `tests/Integration/ParkProfileTest.php`, `tests/Integration/ParkAjaxTest.php`, `tests/e2e/park-profile.spec.ts`

**Infection (pre-refactor):** `infection.t07-park.json5` — `--only-covered --filter=class.Park.php --test-framework-options="--filter=ParkProfileTest|ParkAjaxTest"` (MSI 24%, covered MSI 24%)

#### T-07 sign-off gate

- [x] Test sprint sign-off checklist (above) satisfied
- [x] Branch `megiddo/t-07-park-tests` squashed to exactly one commit

---

### T-08: Admin dashboard & health tests

**Branch:** `megiddo/t-08-admin-tests`

**Depends on:** DS-08

**Design source:** [ds-08-admin-discovery.md §2](./ds-08-admin-discovery.md#2-test-design)

**Targets:** T-ADM-01 through T-ADM-10, T-ADM-12

| Step | Status |
|------|--------|
| Backend unit/integration tests | [x] |
| Frontend functional tests | [x] |
| Milestone-scoped Infection passes | [x] |

**Tests:** `tests/Unit/AdminDashboardTrendStatsTest.php`, `tests/Integration/AdminPermissionsTest.php`, `tests/Integration/DangerAuditQueryTest.php`, `tests/Unit/ServerHealthStatsTest.php`, `tests/Unit/AbbreviationUniqueTest.php`, `tests/Unit/StateOfAmtgardValidationTest.php`, `tests/e2e/admin-dashboard.spec.ts`

**Infection (pre-refactor):** scoped batches via `infection.t08-admin.json5` (≥15% MSI each): DangerAudit+Player 20%, Report 94%, StateOfAmtgard 98%, Park 24%. Weather freshness validated by SQL mirror in `ServerHealthStatsTest` (domain class excluded — pre-refactor gap).

#### T-08 sign-off gate

- [x] Test sprint sign-off checklist (above) satisfied
- [x] Branch `megiddo/t-08-admin-tests` squashed to exactly one commit

---

### T-09: Player profile & AJAX tests

**Branch:** `megiddo/t-09-player-tests`

**Depends on:** DS-09

**Design source:** [ds-09-player-discovery.md §2](./ds-09-player-discovery.md#2-test-design)

**Targets:** T-PLR-01 through T-PLR-08, T-PLA-01 through T-PLA-05, T-PLM-01 through T-PLM-04

| Step | Status |
|------|--------|
| Backend unit/integration tests | [x] |
| Frontend functional tests | [x] |
| Milestone-scoped Infection passes | [x] |

**Tests:** `tests/Integration/PlayerProfileTest.php`, `tests/Integration/PlayerAjaxTest.php`, `tests/Unit/ModelPlayerCacheTest.php`, `tests/e2e/player-profile.spec.ts`

**Infection (pre-refactor):** scoped batches via `infection.t09-player.json5` (≥15% MSI each): Player profile+cache 25%, Player AJAX 22%, Authorization 52%. Report filter deferred — no T-09 test coverage on Report domain paths.

#### T-09 sign-off gate

- [x] Test sprint sign-off checklist (above) satisfied
- [x] Branch `megiddo/t-09-player-tests` squashed to exactly one commit

---

### T-10: Reports, voting rules, awards tests

**Branch:** `megiddo/t-10-reports-tests`

**Depends on:** DS-10

**Design source:** [ds-10-reports-discovery.md §2](./ds-10-reports-discovery.md#2-test-design)

**Targets:** T-RPT-01 through T-RPT-09, T-AWD-01

| Step | Status |
|------|--------|
| Backend unit/integration tests | [x] |
| Frontend functional tests | [x] |
| Milestone-scoped Infection passes | [x] |

**Tests:** `tests/Integration/VotingRulesTest.php`, `tests/Integration/LadderGridTest.php`, `tests/Unit/AttendanceDatesTest.php`, `tests/Integration/OfficerDirectoryTest.php`, `tests/Unit/AwardOptionGroupsTest.php`, `tests/e2e/reports.spec.ts`

**Infection (pre-refactor):** `infection.t10-reports.json5` — `--only-covered --filter=class.Report.php --filter=class.Award.php --test-framework-options="--filter=VotingRulesTest|LadderGridTest|AttendanceDatesTest|OfficerDirectoryTest|AwardOptionGroupsTest"` (MSI 48%, covered MSI 48%)

#### T-10 sign-off gate

- [x] Test sprint sign-off checklist (above) satisfied
- [x] Branch `megiddo/t-10-reports-tests` squashed to exactly one commit

---

### T-11: Search & player search tests

**Branch:** `megiddo/t-11-search-tests`

**Depends on:** DS-11

**Design source:** [ds-11-search-discovery.md §2](./ds-11-search-discovery.md#2-test-design)

**Targets:** T-SRC-01, T-SRC-02, T-ADM-10, T-KNA-06, T-PRA-01, T-EVA-06 (search portion)

| Step | Status |
|------|--------|
| Backend unit/integration tests | [x] |
| Frontend functional tests | [x] |
| Milestone-scoped Infection passes | [x] |

**Tests:** `tests/Integration/SearchServiceTest.php`, `tests/Unit/SearchEscapeTest.php`, `tests/e2e/search.spec.ts`

**Infection (pre-refactor):** `infection.t11-search.json5` — `--only-covered --filter=class.SearchService.php --test-framework-options="--filter=SearchServiceTest|SearchEscapeTest"` (MSI 50%, covered MSI 50%)

#### T-11 sign-off gate

- [x] Test sprint sign-off checklist (above) satisfied
- [x] Branch `megiddo/t-11-search-tests` squashed to exactly one commit

---

### T-12: Attendance & sign-in tests

**Branch:** `megiddo/t-12-attendance-tests`

**Depends on:** DS-12

**Design source:** [ds-12-attendance-discovery.md §2](./ds-12-attendance-discovery.md#2-test-design)

**Targets:** T-ATT-01 through T-ATT-06, T-SIN-01 through T-SIN-04, T-QR-01

| Step | Status |
|------|--------|
| Backend unit/integration tests | [x] |
| Frontend functional tests | [x] |
| Milestone-scoped Infection passes | [x] |

**Tests:** `tests/Unit/ClassLevelTest.php`, `tests/Integration/AttendanceSignInTest.php`, `tests/Integration/AttendanceWriteTest.php`, `tests/Support/AttendanceFixture.php`, `tests/e2e/attendance.spec.ts`

**Infection (pre-refactor):** `infection.t12-attendance.json5` — `--only-covered --filter=class.Attendance.php --filter=class.Player.php --test-framework-options="--filter=ClassLevelTest|AttendanceSignInTest|AttendanceWriteTest"` (MSI 53%, covered MSI 53%)

#### T-12 sign-off gate

- [x] Test sprint sign-off checklist (above) satisfied
- [x] Branch `megiddo/t-12-attendance-tests` squashed to exactly one commit

---

### T-13: Infrastructure & misc tests

**Branch:** `megiddo/t-13-infrastructure-tests`

**Depends on:** DS-13

**Design source:** [ds-13-infrastructure-discovery.md §2](./ds-13-infrastructure-discovery.md#2-test-design)

**Targets:** T-INF-01 through T-INF-05, T-WN-01 (T-INF-06 home RSVP batch — coordinate with T-01)

| Step | Status |
|------|--------|
| Backend unit/integration tests | [x] |
| Frontend functional tests | [x] |
| Milestone-scoped Infection passes | [x] |

**Tests:** `tests/Unit/HealthTest.php`, `tests/Integration/SessionTokenTest.php`, `tests/Integration/ViewerPreferencesTest.php`, `tests/Integration/WhatsNewTest.php`, `tests/Integration/LegacyRedirectTest.php`, `tests/Support/InfrastructureFixture.php`, `tests/e2e/infrastructure.spec.ts`

**Infection (pre-refactor):** `infection.t13-infrastructure.json5` — `--only-covered --filter=class.Player.php --test-framework-options="--filter=ViewerPreferencesTest|PlayerProfileTest|PlayerAjaxTest|ModelPlayerCacheTest"` (MSI 14%, covered MSI 13%; Authorization SQL mirrors — domain deferred to R-13/T-14)

#### T-13 sign-off gate

- [x] Test sprint sign-off checklist (above) satisfied
- [x] Branch `megiddo/t-13-infrastructure-tests` squashed to exactly one commit

---

### T-14: Ork3::$Lib service migration tests

**Branch:** `megiddo/t-14-lib-service-tests`

**Depends on:** DS-14

**Design source:** [ds-14-lib-service-discovery.md §2](./ds-14-lib-service-discovery.md#2-test-design)

**Targets:** T-LIB-01 through T-LIB-05; cross-cutting `HasAuthority` usage

| Step | Status |
|------|--------|
| Backend unit/integration tests | [x] |
| Frontend functional tests | [x] |
| Milestone-scoped Infection passes | [x] |

**Tests:** `tests/Integration/AuthorizationLibTest.php`, `tests/Integration/LiveServiceTest.php`, `tests/Integration/WeatherServiceTest.php`, `tests/Unit/EraPhoeniceTest.php`, `tests/e2e/lib-service.spec.ts`

**Infection (pre-refactor):** scoped batches via `infection.t14-lib-auth-era.json5` and `infection.t14-lib-live-weather.json5` (≥15% MSI each): Authorization+EraPhoenice 19%, Live+Weather 62%

#### T-14 sign-off gate

- [x] Test sprint sign-off checklist (above) satisfied
- [x] Branch `megiddo/t-14-lib-service-tests` squashed to exactly one commit

---

## Phase 1.6 — Validation Artifacts

Canary URLs, dual-database fuzzy baselines, and **test mutation boundaries** for R-* execution. Plan: [08-phase-16-validation-artifacts.md](./08-phase-16-validation-artifacts.md) · Index: [validations/README.md](./validations/README.md) · **Agent prompt:** [09-v-phase-agent-prompt.md](./09-v-phase-agent-prompt.md) (batched G0–G4).

**Tools:** `bin/ork-db` (sandbox + mirror) · `bin/fuzzy-validator` (record/validate both profiles)

**Workflow:**

1. **V-00 (global)** — Register major-interface setpoint URLs; capture baselines on **test** + **mirror**.
2. **V-01 … V-14 (parallel)** — Per domain: 2–4 canary URL variants + document how T-* tests may migrate during R-*.

**Out of scope:** Re-surveying backend (DS-*), writing new characterization tests (T-*), production refactor (R-*).

**Branch pattern:** `megiddo/v-00-fuzzy-setpoint`, `megiddo/v-{nn}-{slug}`

**Validation sprint sign-off (V-00 and every V-*):**

- [x] [05-development-steering.md](./05-development-steering.md) DS-1, DS-3, DS-6, DS-8 satisfied
- [x] Matching DS-{nn} + T-{nn} complete (V-01+ only); V-00 requires T-14 per [v-00-fuzzy-setpoint.md](./validations/v-00-fuzzy-setpoint.md)
- [x] [E2E login preflight](./06-test-framework.md#e2e-login-credentials-preflight) complete for capture/validate
- [x] Validation doc published under `validations/v-{nn}-*.md` (template: [_template-validation.md](./validations/_template-validation.md))
- [x] Canary page ids registered in `tools/fuzzy-validator/manifests/pages.json5`
- [x] `bin/fuzzy-validator record` or `setpoint capture` on **test** + **mirror** for milestone page ids
- [x] All `docs/megiddo/refactor/` updates committed on milestone branch
- [x] Branch squashed to exactly one commit

---

### V-00: Global fuzzy setpoint

**Branch:** `megiddo/v-00-fuzzy-setpoint`

**Spec:** [validations/v-00-fuzzy-setpoint.md](./validations/v-00-fuzzy-setpoint.md)

| Step | Status |
|------|--------|
| Preflight 1 — major-interface URL registry (1–3 per class) | [x] |
| Preflight 2 — dual-profile fuzzy record (test + mirror) | [x] |
| V-00 sign-off gate | [x] |

---

### V-01: RSVP validation artifacts

**Branch:** `megiddo/v-01-rsvp-validation`

**Spec:** [validations/v-01-rsvp-validation.md](./validations/v-01-rsvp-validation.md)

| Step | Status |
|------|--------|
| Canary URLs + `pages.json5` (`event-index-rsvp`, `event-index-rsvp-gok`; detail ids skipped) | [x] |
| Test mutation boundaries (§2) | [x] |
| Dual-profile fuzzy record + validate (4/4 pass) | [x] |
| V-01 sign-off gate | [x] |

---

### V-02: Authorization INSERT validation artifacts

**Branch:** `megiddo/v-02-auth-validation`

**Spec:** [validations/v-02-auth-validation.md](./validations/v-02-auth-validation.md)

| Step | Status |
|------|--------|
| Canary URLs + `pages.json5` (`kingdom-auth-sandbox`, `park-auth-sandbox`) | [x] |
| Test mutation boundaries (§2) | [x] |
| Dual-profile fuzzy record + validate | [x] |
| V-02 sign-off gate | [x] |

---

### V-03: Banner validation artifacts

**Branch:** `megiddo/v-03-banner-validation`

**Spec:** [validations/v-03-banner-validation.md](./validations/v-03-banner-validation.md)

| Step | Status |
|------|--------|
| Canary URLs (reuse V-00/V-02 hosts; no new pages.json5 rows) | [x] |
| Test mutation boundaries (§2) | [x] |
| Dual-profile fuzzy validate (domain-critical ids) | [x] |
| V-03 sign-off gate | [x] |

---

### V-04: EventAjax validation artifacts

**Branch:** `megiddo/v-04-eventajax-validation`

**Spec:** [validations/v-04-eventajax-validation.md](./validations/v-04-eventajax-validation.md)

| Step | Status |
|------|--------|
| Canary URLs (reuse V-00/V-01 event hosts; refresh drifted baselines) | [x] |
| Test mutation boundaries (§2) | [x] |
| Dual-profile fuzzy validate (12/12) | [x] |
| V-04 sign-off gate | [x] |

---

### V-05: Event controller validation artifacts

**Branch:** `megiddo/v-05-event-validation`

**Spec:** [validations/v-05-event-validation.md](./validations/v-05-event-validation.md)

| Step | Status |
|------|--------|
| Canary URLs (`event-template` skip; gate reuses index RSVP + create) | [x] |
| Test mutation boundaries (§2) | [x] |
| Dual-profile fuzzy validate (6/6) | [x] |
| V-05 sign-off gate | [x] |

---

### V-06: Kingdom validation artifacts

**Branch:** `megiddo/v-06-kingdom-validation`

**Spec:** [validations/v-06-kingdom-validation.md](./validations/v-06-kingdom-validation.md)

| Step | Status |
|------|--------|
| Canary URLs (reuse V-00/V-02 kingdom hosts; refresh `kingdom-profile`) | [x] |
| Test mutation boundaries (§2) | [x] |
| Dual-profile fuzzy validate (4/4) | [x] |
| V-06 sign-off gate | [x] |

---

### V-07: Park validation artifacts

**Branch:** `megiddo/v-07-park-validation`

**Spec:** [validations/v-07-park-validation.md](./validations/v-07-park-validation.md)

| Step | Status |
|------|--------|
| Canary URLs (reuse sandbox park + `event-park`; `park-profile` skip) | [x] |
| Test mutation boundaries (§2) | [x] |
| Dual-profile fuzzy validate (4/4) | [x] |
| V-07 sign-off gate | [x] |

---

### V-08: Admin validation artifacts

**Branch:** `megiddo/v-08-admin-validation`

**Spec:** [validations/v-08-admin-validation.md](./validations/v-08-admin-validation.md)

| Step | Status |
|------|--------|
| Canary URLs (reuse V-00 admin hosts; refresh drifted baselines) | [x] |
| Test mutation boundaries (§2) | [x] |
| Dual-profile fuzzy validate (6/6) | [x] |
| V-08 sign-off gate | [x] |

---

### V-09: Player validation artifacts

**Branch:** `megiddo/v-09-player-validation`

**Spec:** [validations/v-09-player-validation.md](./validations/v-09-player-validation.md)

| Step | Status |
|------|--------|
| Canary URLs (reuse V-00 player hosts; refresh `player-profile-sandbox`) | [x] |
| Test mutation boundaries (§2) | [x] |
| Dual-profile fuzzy validate (4/4) | [x] |
| V-09 sign-off gate | [x] |

---

### V-10: Reports validation artifacts

**Branch:** `megiddo/v-10-reports-validation`

**Spec:** [validations/v-10-reports-validation.md](./validations/v-10-reports-validation.md)

| Step | Status |
|------|--------|
| Canary URLs (reuse V-00 report hosts; refresh drifted baselines) | [x] |
| Test mutation boundaries (§2) | [x] |
| Dual-profile fuzzy validate (6/6) | [x] |
| V-10 sign-off gate | [x] |

---

### V-01 … V-14: Domain validation artifacts

Each row: canary URLs (§1) + test mutation boundaries (§2) + domain baselines. Pairs with DS/T/R numbering.

| ID | Branch | Validation doc | Depends on | Blocks |
|----|--------|----------------|------------|--------|
| V-01 | `megiddo/v-01-rsvp-validation` | [v-01-rsvp-validation.md](./validations/v-01-rsvp-validation.md) | DS-01, T-01, V-00 | R-01 |
| V-02 | `megiddo/v-02-auth-validation` | [v-02-auth-validation.md](./validations/v-02-auth-validation.md) | DS-02, T-02, V-00 | R-02 |
| V-03 | `megiddo/v-03-banner-validation` | [v-03-banner-validation.md](./validations/v-03-banner-validation.md) | DS-03, T-03, V-00 | R-03 |
| V-04 | `megiddo/v-04-eventajax-validation` | [v-04-eventajax-validation.md](./validations/v-04-eventajax-validation.md) | DS-04, T-04, V-00 | R-04 |
| V-05 | `megiddo/v-05-event-validation` | [v-05-event-validation.md](./validations/v-05-event-validation.md) | DS-05, T-05, V-00 | R-05 |
| V-06 | `megiddo/v-06-kingdom-validation` | [v-06-kingdom-validation.md](./validations/v-06-kingdom-validation.md) | DS-06, T-06, V-00 | R-06 |
| V-07 | `megiddo/v-07-park-validation` | [v-07-park-validation.md](./validations/v-07-park-validation.md) | DS-07, T-07, V-00 | R-07 |
| V-08 | `megiddo/v-08-admin-validation` | [v-08-admin-validation.md](./validations/v-08-admin-validation.md) | DS-08, T-08, V-00 | R-08 |
| V-09 | `megiddo/v-09-player-validation` | [v-09-player-validation.md](./validations/v-09-player-validation.md) | DS-09, T-09, V-00 | R-09 |
| V-10 | `megiddo/v-10-reports-validation` | [v-10-reports-validation.md](./validations/v-10-reports-validation.md) | DS-10, T-10, V-00 | R-10 |
| V-11 | `megiddo/v-11-search-validation` | *(create)* | DS-11, T-11, V-00 | R-11 |
| V-12 | `megiddo/v-12-attendance-validation` | *(create)* | DS-12, T-12, V-00 | R-12 |
| V-13 | `megiddo/v-13-infrastructure-validation` | *(create)* | DS-13, T-13, V-00 | R-13 |
| V-14 | `megiddo/v-14-lib-service-validation` | *(create)* | DS-14, T-14, V-00 | R-14 |

---

## Phase 2 — Refactor Execution

Execution sprints begin **after** the corresponding discovery sprint, test sprint, **and validation artifact sprint (V-*)** are complete. Order is flexible but recommended:

| Exec sprint | Depends on | Target IDs |
|-------------|------------|------------|
| R-01 | DS-01, T-01, **V-00, V-01**, M0.1 | T-RSV-* |
| R-02 | DS-02, T-02, V-00, **V-02** | T-ADM-11, T-KNA-03, T-PRA-02, T-EVA-06 |
| R-03 | DS-03, T-03, V-00, **V-03** | Banner targets |
| R-04 | DS-04, T-04, V-00, **V-04** | T-EVA-* |
| R-05 | DS-05, T-05, V-00, **V-05** | T-EVT-* |
| R-06 | DS-06, T-06, V-00, **V-06** | Kingdom targets |
| R-07 | DS-07, T-07, V-00, **V-07** | Park targets |
| R-08 | DS-08, T-08, V-00, **V-08** | Admin targets |
| R-09 | DS-09, T-09, V-00, **V-09** | Player targets |
| R-10 | DS-10, T-10, V-00, **V-10** | Reports/awards targets |
| R-11 | DS-11, T-11, V-00, **V-11** | Search targets |
| R-12 | DS-12, T-12, V-00, **V-12** | Attendance/sign-in targets |
| R-13 | DS-13, T-13, V-00, **V-13** | Infrastructure targets |
| R-14 | DS-14, T-14, V-00, **V-14** | Ork3::$Lib migration |

**Branch pattern:** `megiddo/r-{nn}-{slug}`

Per execution sprint checklist:

- [ ] [05-development-steering.md](./05-development-steering.md) DS-1 through DS-8 satisfied
- [ ] Matching T-* test sprint complete (tests already in place; do not defer test writing to R-*)
- [ ] Matching **V-*** validation doc complete — follow [validations/v-{nn}-*.md](./validations/) §2 migration boundaries
- [ ] E2E login preflight complete when milestone includes auth-gated Playwright or fuzzy-validator flows ([06-test-framework.md § preflight](./06-test-framework.md#e2e-login-credentials-preflight))
- [ ] **Full** unit test suite passes (no partial run at sign-off)
- [ ] Milestone-scoped Infection run passes configured thresholds on refactored code
- [ ] Frontend functional tests pass (when applicable to milestone)
- [ ] `bin/ork-db deploy-sandbox` then `bin/fuzzy-validator validate --pages <ids-from-v-NN.md> --phase all` passes **test** (strict) and **mirror** (lenient) — requires V-00 + V-{nn} baselines ([11-dual-database-profiles.md](../fuzzy-validator/11-dual-database-profiles.md))
- [ ] Target IDs marked done in [03-implementation-plan.md](./03-implementation-plan.md)
- [ ] No new `$DB` or unauthorized `Ork3::$Lib` usage introduced in touched files
- [ ] All `docs/megiddo/refactor/` updates committed on the milestone branch (Documentation sign-off above)
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
| **0** | Test + mutation framework + planning |
| **1** | Discovery sprints — survey, test design, proposed revision |
| **1.5** | Test development — implement tests + Infection per DS design notes |
| **1.6** | Validation artifacts — canary URLs, dual-profile fuzzy baselines, test mutation boundaries |
| **2** | Refactor execution — move code; fuzzy + Infection + PHPUnit sign-off |
| **3** | Audit and close-out |

**Next actionable milestone:** G3 remainder (V-11…V-12), then G4 (V-13…V-14), then R-01 (RSVP refactor — DS-01, T-01, V-01 complete).
