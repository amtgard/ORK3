# Megiddo Refactor — Requirements

## Purpose

Establish a service-oriented boundary between ORK3's frontend (`orkui/`) and backend (`system/lib/ork3/` + `orkservice/*`). Every instance of business logic and database access in the frontend must be identified, planned, and eventually moved behind a backend API.

## Scope

### In scope

All of the following anywhere under `orkui/` (controllers, models, and `orkui/index.php`), plus shared infrastructure invoked on every frontend request:

1. **Direct database access** — any use of global `$DB`, raw SQL strings, or yapo ORM instantiated in frontend code.
2. **Domain business logic** — eligibility rules, aggregations with domain semantics, write paths, transactional CRUD, authorization side effects.
3. **Direct `Ork3::$Lib` calls** — domain library usage that bypasses `orkservice/*` (including `HasAuthority`, cache busting tied to writes, weather/live/player helpers).
4. **Duplicated subsystems** — logic that exists in both frontend and backend (RSVP, banners, authorization INSERTs, etc.) until consolidated.

### Out of scope (for this planning phase)

- **How** to perform each refactor (implementation technique, API shape, migration strategy).
- **Whether** frontend code duplicates existing backend code or is unique new logic — deferred to discovery sprints.
- Template/JavaScript presentation logic that does not touch the database or encode domain rules.
- `bin/` cron scripts and `import/` tooling (separate concern).
- `orkservice/` and `system/lib/ork3/` refactors except where needed to expose new APIs.

### Acceptable frontend behavior (after refactor)

| Allowed | Not allowed |
|---------|-------------|
| Call `APIModel` / `JSONModel` service methods | Direct `$DB` queries |
| Filter/sort API results for a view | Re-implement eligibility or aggregation rules |
| Combine multiple API responses for a page | Duplicate write paths (INSERT/UPDATE/DELETE) |
| Format values for display (dates, currency) | Hardcode kingdom-specific business rules |
| Build HTML from structured API data when no API provides pre-built markup | Encode peerage/ladder categorization rules locally |
| Auth-gated UI that hides buttons (may call a lightweight auth-check API) | Bypass AuthorizationService for grants |

## Functional Requirements

### FR-1: Complete inventory

Every refactor target in `orkui/` must be documented with:

- File path
- Class name
- Method name (or named block for `index.php`)
- Line range
- Brief description of the violation

See [03-implementation-plan.md](./03-implementation-plan.md).

### FR-2: Backend ownership

All database reads and writes for domain entities must execute in `system/lib/ork3/` and be reachable only through `orkservice/*` endpoints consumed by the frontend.

### FR-3: No regression

Each refactor target must have:

- **Backend unit tests** covering the moved logic (or existing logic if consolidating duplicates).
- **Frontend functional tests** verifying the user-facing behavior is unchanged.
- **Mutation tests** (Infection) scoped to the milestone, passing before sign-off per [05-development-steering.md](./05-development-steering.md) DS-7.
- **Optional render stability gate** (when [fuzzy-validator](../fuzzy-validator/README.md) FU-11+ is available): `bin/fuzzy-validator validate` against **`test`** (strict) and **`mirror`** (lenient) database profiles — see [11-dual-database-profiles.md](../fuzzy-validator/11-dual-database-profiles.md).

Authenticated Playwright and fuzzy-validator flows require **configured E2E login credentials** (sandbox `megiddo` / `test-db-player` or mirror dev user) — not the local-only `class.Authorization.php` bypass. See [06-test-framework.md § E2E login credentials (preflight)](./06-test-framework.md#e2e-login-credentials-preflight).

Test design is part of discovery sprints (Phase 1). Test **implementation** and Infection validation happen in matching test sprints (Phase 1.5, milestones T-01 … T-14) **before** the corresponding refactor execution sprint (R-*).

### FR-4: Authorization integrity

All authorization grants (`ork_authorization` writes) must go through `AuthorizationService` (or successor API). Direct `INSERT INTO ork_authorization` from `orkui` is prohibited in the target state.

Current violations:

| Class | Method | Lines |
|-------|--------|-------|
| `Controller_AdminAjax` | `global` (action `addauth`) | 58–64 |
| `Controller_KingdomAjax` | `kingdom` (action `addauth`) | 631–635 |
| `Controller_ParkAjax` | `park` (action `addauth`) | 427–431 |
| `Controller_EventAjax` | `auth` | 505–509 |

### FR-5: Consolidate duplicated subsystems

These subsystems appear in multiple frontend locations and must converge on a single backend API:

| Subsystem | Frontend locations |
|-----------|-------------------|
| Event RSVP | `Model_Event` (79–215), `Controller_EventRsvpAjax` (14–88), `Controller_Event` (55–92, 187–188), `class.Controller` (171–172) |
| Hero banner CRUD | `Controller_PlayerAjax::banner` (854–988), `Controller_ParkAjax::banner` (689–805), `Controller_KingdomAjax::banner` (1254–1364), `Controller_UnitAjax::banner` (36–138) |
| Player search SQL | `Controller_AdminAjax`, `Controller_KingdomAjax`, `Controller_ParkAjax`, `Controller_SearchAjax`, `Controller_EventAjax` |
| Abbreviation uniqueness | `Controller_Admin::ajax`, `Controller_KingdomAjax`, `Controller_ParkAjax` |

### FR-6: Business rules relocation

The following domain rules currently live in `orkui` and must move backend:

| Rule | Location | Lines |
|------|----------|-------|
| Kingdom voting eligibility configs | `Model_Reports::_all_voting_rules` | 334–474 |
| Award list categorization (pseudo-ladder IDs, peerage buckets) | `Model_Award::fetch_award_option_list` | 37–112 |
| Class level thresholds (5/12/21/34/53 credits) | `Controller_SignIn::index` | 115–149 |
| Ladder grid report assembly | `Controller_Reports::ladder_grid` | 1064–1284 |
| Kingdom/Park profile aggregates | `Controller_Kingdom::profile`, `Controller_Park::profile` | see plan |
| Event detail transactional CRUD | `Controller_Event::detail` | 243–1090 |
| Admin YoY dashboard stats | `Controller_Admin::index` | 43–90 |
| Player merge authorization mirror | `Controller_PlayerAjax::merge` | 542–594 |

### FR-7: Infrastructure cleanup

Session validation, font preferences, and home-page widget data currently query `$DB` from `class.Controller.php` (lines 51–172) and `orkui/index.php` (lines 11, 71). These must move to appropriate backend services.

## Non-Functional Requirements

### NFR-1: Incremental delivery

Refactors ship in small batches. Each batch completes discovery (see milestones) before implementation.

### NFR-2: Test and mutation framework first

Before the first refactor execution sprint, establish:

- A **unit test framework** for `system/lib/ork3/` and `orkservice/*` with documented conventions. Existing test files (`*Service.test.php`, `AuthorizationService.testrig.php`) are starting points but not a unified framework.
- **Mutation testing** via [Infection](https://infection.github.io/) (`infection/infection`), scoped per milestone for sign-off.

See M0.1 in [04-milestone-checklist.md](./04-milestone-checklist.md).

### NFR-3: Idiomatic ORK3 and PHP 8.2+

All changes must be idiomatic to ORK3 (see [05-development-steering.md](./05-development-steering.md) DS-1) and target **PHP 8.2+** (DS-2).

### NFR-4: Milestone branch discipline

All work executes on a milestone-named branch with exactly one commit at sign-off, full unit test suite passing, milestone-scoped mutation tests passing, and commit metadata matching the branch. See [05-development-steering.md](./05-development-steering.md).

### NFR-5: Traceability

Each discovery sprint produces a short design note linking:

- Refactor target IDs from the implementation plan
- Backend code survey results (duplicate vs. unique — filled in during discovery)
- Proposed API additions/changes
- Test plan (backend unit + frontend functional)

### NFR-6: No silent behavior change

Moved logic must preserve existing semantics unless an explicit product decision documents intentional change.

### NFR-7: E2E login preflight (T-* and R-*)

Milestones that run auth-gated Playwright or fuzzy-validator checks must complete the credential preflight in [06-test-framework.md](./06-test-framework.md#e2e-login-credentials-preflight) before sign-off. Use documented sandbox or mirror passwords via `ORK3_E2E_*` env vars — **never** the uncommitted `class.Authorization.php` login bypass.

## Discovery Sprint Requirements

Before implementing any refactor target group, a discovery sprint must:

1. **Survey backend** — For each target, identify related or duplicate code in `system/lib/ork3/` and `orkservice/*`.
2. **Design tests** — Specify backend unit tests and frontend functional tests that would catch regression.
3. **Propose revision** — Document the intended move (which service method, which frontend calls replace the SQL) without committing to implementation details in this requirements doc.

Discovery sprint outputs are appended to [04-milestone-checklist.md](./04-milestone-checklist.md) as they complete.

## Success Criteria

The refactor is complete when:

- [ ] Zero `$DB` usage in `orkui/` (including models and `index.php`)
- [ ] Zero direct `Ork3::$Lib->{domain}` calls in `orkui/` except where explicitly exempted (if any)
- [ ] All items in [03-implementation-plan.md](./03-implementation-plan.md) marked done
- [ ] Backend unit test coverage for all moved logic
- [ ] Frontend functional tests for affected user flows
- [ ] Optional: fuzzy-validator dual-profile gate passes for touched pages ([fuzzy-validator](../fuzzy-validator/README.md))
- [ ] No direct `INSERT`/`UPDATE`/`DELETE` on domain tables from `orkui`

## Document Index

| Doc | Purpose |
|-----|---------|
| [01-code-decomposition.md](./01-code-decomposition.md) | Architecture map |
| [03-implementation-plan.md](./03-implementation-plan.md) | Target inventory |
| [04-milestone-checklist.md](./04-milestone-checklist.md) | Milestones and sprint tracking |
| [05-development-steering.md](./05-development-steering.md) | Branch, test, mutation, and commit rules |
| [07-agent-milestone-prompt.md](./07-agent-milestone-prompt.md) | Copy-paste agent prompt per milestone |
| [../fuzzy-validator/README.md](../fuzzy-validator/README.md) | Render stability gate (`bin/fuzzy-validator`) |
| [../test-database-tool/README.md](../test-database-tool/README.md) | Stable sandbox DB for tests and fuzzy **`test`** profile |
