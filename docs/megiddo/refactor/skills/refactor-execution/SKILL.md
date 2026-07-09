---
name: refactor-execution
description: >-
  Execute Megiddo Phase 2 refactor sprints R-01 through R-14 as serialized
  sub-agents: branch hygiene, domain migration, PHPUnit, Infection, fuzzy-validator,
  Playwright, docs, and one commit per milestone. Use when starting or continuing
  R-* execution after rebase-and-redocument (RB-Z) is complete.
disable-model-invocation: true
---

# Megiddo — Refactor Execution (R-01 … R-14)

Phase **2** — migrate business logic and `$DB` out of `orkui/` per discovery (DS-*), tests (T-*), and validation artifacts (V-*). Prefer **one orchestrator chat** that drives **serialized sub-agents** per **R-*** milestone.

**Prerequisite:** [rebase-and-redocument](../rebase-and-redocument/milestone-checklist.md) RB-Z complete (or user waives). **Not** the RB-* rebase skill — that repairs docs/tests/baselines only.

## When to use

- Start or continue R-* refactor execution after rebase
- Run the full R-01 … R-14 queue with one paste (orchestrator)
- Resume a single R-* milestone manually

## How to run

### A — Orchestrated (preferred)

1. Open [agent-prompt.md](agent-prompt.md) → **Prompt — Orchestrator**.
2. Paste into a new agent chat (optionally set `{{FROM}}` to resume, e.g. `R-03`).
3. Parent launches **serialized** Task sub-agents: **R-01 → R-02 → … → R-14**.
4. Parent waits for each worker, verifies [milestone-checklist.md](milestone-checklist.md), then starts the next. Stops on `blocked`/`failed`.

Workers get the full milestone prompt (no shared chat history). **Do not** parallelize R-* Tasks.

### B — Manual (one milestone per chat)

1. Copy the **Worker — R-{NN}** prompt from [agent-prompt.md](agent-prompt.md).
2. Set `{{MILESTONE}}=R-01` (etc.).
3. Check off [milestone-checklist.md](milestone-checklist.md); stop. Next chat continues.

## Milestone map

| ID | Domain | DS | V | Infection config | Fuzzy gate (from V doc) |
|----|--------|----|---|------------------|-------------------------|
| **R-01** | RSVP | [ds-01](../../ds-01-rsvp-discovery.md) | [v-01](../../validations/v-01-rsvp-validation.md) | `infection.t01-rsvp.json5` | `home-authenticated,player-profile,event-index-rsvp,event-index-rsvp-gok` |
| **R-02** | Auth INSERT | [ds-02](../../ds-02-auth-insert-discovery.md) | [v-02](../../validations/v-02-auth-validation.md) | `infection.t02-auth-insert.json5` | per v-02 §1 |
| **R-03** | Banner | [ds-03](../../ds-03-banner-discovery.md) | [v-03](../../validations/v-03-banner-validation.md) | `infection.t03-banner.json5` | `kingdom-auth-sandbox,park-auth-sandbox,player-profile` |
| **R-04** | EventAjax | [ds-04](../../ds-04-eventajax-discovery.md) | [v-04](../../validations/v-04-eventajax-validation.md) | `infection.t04-eventajax.json5` | per v-04 §1.3 |
| **R-05** | Event | [ds-05](../../ds-05-event-discovery.md) | [v-05](../../validations/v-05-event-validation.md) | `infection.t05-event.json5` | `event-index-rsvp,event-index-rsvp-gok,event-create` |
| **R-06** | Kingdom | [ds-06](../../ds-06-kingdom-discovery.md) | [v-06](../../validations/v-06-kingdom-validation.md) | `infection.t06-kingdom.json5` | `kingdom-profile,kingdom-auth-sandbox` |
| **R-07** | Park | [ds-07](../../ds-07-park-discovery.md) | [v-07](../../validations/v-07-park-validation.md) | `infection.t07-park.json5` | `park-auth-sandbox,event-park` |
| **R-08** | Admin | [ds-08](../../ds-08-admin-discovery.md) | [v-08](../../validations/v-08-admin-validation.md) | `infection.t08-admin.json5` | `admin-dashboard,admin-permissions,admin-state-of-amtgard` |
| **R-09** | Player | [ds-09](../../ds-09-player-discovery.md) | [v-09](../../validations/v-09-player-validation.md) | `infection.t09-player.json5` | `player-profile,player-profile-sandbox` |
| **R-10** | Reports | [ds-10](../../ds-10-reports-discovery.md) | [v-10](../../validations/v-10-reports-validation.md) | `infection.t10-reports.json5` | `reports-voting-eligible,reports-ladder-grid,reports-attendance` |
| **R-11** | Search | [ds-11](../../ds-11-search-discovery.md) | [v-11](../../validations/v-11-search-validation.md) | `infection.t11-search.json5` | `admin-permissions,kingdom-auth-sandbox,park-auth-sandbox` |
| **R-12** | Attendance | [ds-12](../../ds-12-attendance-discovery.md) | [v-12](../../validations/v-12-attendance-validation.md) | `infection.t12-attendance.json5` | `park-auth-sandbox,event-park` |
| **R-13** | Infrastructure | [ds-13](../../ds-13-infrastructure-discovery.md) | [v-13](../../validations/v-13-infrastructure-validation.md) | `infection.t13-infrastructure.json5` | `home-authenticated` |
| **R-14** | Lib-service | [ds-14](../../ds-14-lib-service-discovery.md) | [v-14](../../validations/v-14-lib-service-validation.md) | `t14-lib-auth-era` + `t14-lib-live-weather` | `weather,tournament` |

Gate commands and MSI floors: each **V-{nn}** doc §2.4 (Infection) and §1 / §3 (fuzzy + sign-off).

## Branching and commits

| Item | Rule |
|------|------|
| Integration line | Record on checklist (e.g. `megiddo/rebase-YYYYMMDD` after RB-Z) |
| Milestone branch | `megiddo/r-{nn}-{slug}` — one milestone per branch (DS-3) |
| Base for R-01 | Integration line tip |
| Base for R-{nn}, nn>1 | Integration line **after** R-{nn-1} is merged, **or** prior `megiddo/r-{nn-1}-*` tip if user stacks without merge — orchestrator records choice in checklist metadata |
| Commits | **Exactly one squashed commit** per R-* branch (DS-6), title `R-01: …` |
| Prior milestone | Before starting R-{nn}, verify R-{nn-1} branch has **one commit**, all gates green, **no uncommitted work** on that branch |

## Non-negotiables

From [05-development-steering.md](../../05-development-steering.md):

- DS-1 idiomatic ORK3; no drive-by refactors
- DS-4/DS-5 full PHPUnit (`sh bin/run-unit-tests.sh`) before sign-off and commit
- DS-7 milestone-scoped Infection per matching V-* §2.4
- E2E credentials per [06-test-framework.md § preflight](../../06-test-framework.md#e2e-login-credentials-preflight) — mirror `admin`/`password`; never `class.Authorization.php` bypass
- Fuzzy: `bin/fuzzy-validator validate --pages <gate> --phase all` — **test** + **mirror** profiles
- Test edits only within V-* §2.3 mutation boundaries — **no semantic regression**
- Re-record fuzzy baselines only for **intentional** UI change; never widen thresholds to force pass without user approval

## Stop / ask the user

- Refactor requires changing documented behavior or RSVP/auth semantics
- Infection MSI below documented floor without acceptable justification
- Fuzzy failure looks like unintended product regression
- Milestone scope must expand into another domain's targets
- Prior R-* branch not merged/committed and integration base is unclear

## Exit criteria (whole skill)

- [ ] R-01 … R-14 checked on [milestone-checklist.md](milestone-checklist.md)
- [ ] Each `megiddo/r-*` branch has one commit; gates recorded
- [ ] [04-milestone-checklist.md](../../04-milestone-checklist.md) Phase 2 items checked
- [ ] Target IDs marked done in [03-implementation-plan.md](../../03-implementation-plan.md)
- [ ] Phase 3 audit prep: `rg '\$DB->' orkui/` trending toward zero

## Related

- [07-agent-milestone-prompt.md](../../07-agent-milestone-prompt.md) — single-milestone prompt (non-orchestrated)
- [rebase-and-redocument](../rebase-and-redocument/SKILL.md) — RB-* only
- [validations/README.md](../../validations/README.md) — V-* index
