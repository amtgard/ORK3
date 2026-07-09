# Rebase & Redocument — Milestone Checklist

Track **RB-*** progress here. **Preferred:** one [orchestrator](agent-prompt.md) chat launches serialized sub-agents per milestone. Manual: one worker prompt per chat.

**Skill:** [SKILL.md](SKILL.md) · **Agent prompts:** [agent-prompt.md](agent-prompt.md) (orchestrator + workers) · **Matrix:** [mutation-matrix.md](mutation-matrix.md) · **Conflicts:** [conflict-playbook.md](conflict-playbook.md)

**Steering:** [05-development-steering.md](../../05-development-steering.md) (DS-1–DS-8 as applicable)

---

## Run metadata (fill in RB-0)

| Field | Value |
|-------|-------|
| Date started | 2026-07-09 |
| Megiddo tip (pre-rebase) | branch `megiddo/v-14-lib-service-validation` @ `ad878395` |
| Base | `origin/master` @ `e6417645` |
| Working branch | `megiddo/rebase-20260709` |
| **Sizing grade** | **S** |
| Sizing rationale | 0 commits on `origin/master` since merge-base (`e6417645` = current master tip); no upstream `orkui/`, `db-migrations/`, template, or test churn; Megiddo is 75 commits ahead on same base — rebase expected clean |
| Session plan | **S:** one sub-agent per RB-* (serialized); queue runs faster; no RB-D per-domain splits |

---

## Phase A — Integrate

### RB-0: Preflight and size

**Branch:** create or use `megiddo/rebase-{YYYYMMDD}` (do **not** rebase yet)  
**Prompt:** [agent-prompt.md](agent-prompt.md) → `RB-0`

| Step | Status |
|------|--------|
| `git fetch`; record tip + `origin/master` SHAs | [x] |
| Working tree clean (or WIP parked) | [x] |
| Summarize `HEAD..origin/master` (commits + hot paths: `orkui/`, migrations, templates) | [x] |
| Assign sizing grade S/M/L + session plan in metadata table | [x] |
| Confirm docker / `bin/ork-db` / `bin/fuzzy-validator` available | [x] |
| Checklist metadata filled; next milestone named | [x] |
| Commit (optional docs-only): `RB-0: Size Megiddo rebase onto master` | [x] |

**Exit:** Grade + plan recorded; ready for RB-1.

---

### RB-1: Rebase onto base

**Depends on:** RB-0  
**Prompt:** [agent-prompt.md](agent-prompt.md) → `RB-1`  
**Conflicts:** [conflict-playbook.md](conflict-playbook.md)

| Step | Status |
|------|--------|
| `git rebase origin/master` (or agreed base) | [ ] |
| Conflicts resolved per playbook | [ ] |
| Rebase completed; tip is ancestor-based on base | [ ] |
| Smoke: `composer install` / obvious syntax breakage noted for RB-2 | [ ] |
| Commit: `RB-1: Rebase Megiddo line onto master` | [ ] |

**Exit:** Clean rebase (or user-approved alternate strategy). No requirement that PHPUnit is green yet.

---

## Phase B — Global tests

### RB-2: Full suite green

**Depends on:** RB-1  
**Prompt:** [agent-prompt.md](agent-prompt.md) → `RB-2`

| Step | Status |
|------|--------|
| `docker compose -f docker-compose.php8.yml up -d` | [ ] |
| `bin/ork-db deploy-sandbox` (fix schema/migration drift if needed) | [ ] |
| E2E preflight when touching auth-gated specs | [ ] |
| `sh bin/run-unit-tests.sh` exit 0 | [ ] |
| Critical e2e smoke (or document deferrals to RB-D\*) | [ ] |
| Commit: `RB-2: Repair tests after Megiddo rebase` | [ ] |

**Exit:** Full PHPUnit green. Domain-specific assertion tweaks may continue in RB-D\* if isolated and listed under “deferred”.

---

## Phase C — Domain redocument

Each **RB-D\*** batch repairs, for every domain in the batch:

1. `ds-{nn}-*-discovery.md` §1 lines/behavior + §3 if assumptions broke (+ post-rebase note)
2. Matching rows in `03-implementation-plan.md`
3. `validations/v-{nn}-*.md` §1 page ids / §2 test paths
4. Domain unit/integration/e2e failures still open after RB-2
5. `infection.t{nn}*.json5` paths + milestone Infection gate

**Sizing L:** split a batch into `RB-D-{nn}` single-domain milestones (copy the batch checklist row into its own section).

### Shared sign-off (every RB-D\* / RB-D-{nn})

- [ ] Discovery + implementation-plan lines updated for domains in scope
- [ ] Validation docs paths/ids still valid
- [ ] Domain tests green (or gap noted)
- [ ] Infection gate pass for domains in scope (or gap noted + user aware)
- [ ] Checklist checked; one commit `RB-D1: …` (or `RB-D-01: …`)

---

### RB-D1: Domains 01–04

**Depends on:** RB-2 · **Domains:** RSVP, auth INSERT, banner, EventAjax  
**Prompt:** `{{BATCH}}=RB-D1` or `{{MILESTONE}}=RB-D1`

| Domain | ds-* | plan lines | v-* | tests | Infection | Done |
|--------|------|------------|-----|-------|-----------|------|
| 01 RSVP | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| 02 Auth | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| 03 Banner | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| 04 EventAjax | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |

---

### RB-D2: Domains 05–08

**Depends on:** RB-2 (RB-D1 recommended first) · **Domains:** event, kingdom, park, admin  
**Prompt:** `{{BATCH}}=RB-D2`

| Domain | ds-* | plan lines | v-* | tests | Infection | Done |
|--------|------|------------|-----|-------|-----------|------|
| 05 Event | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| 06 Kingdom | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| 07 Park | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| 08 Admin | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |

---

### RB-D3: Domains 09–12

**Depends on:** RB-2 · **Domains:** player, reports, search, attendance  
**Prompt:** `{{BATCH}}=RB-D3`

| Domain | ds-* | plan lines | v-* | tests | Infection | Done |
|--------|------|------------|-----|-------|-----------|------|
| 09 Player | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| 10 Reports | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| 11 Search | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| 12 Attendance | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |

---

### RB-D4: Domains 13–14

**Depends on:** RB-2 · **Domains:** infrastructure, lib-service  
**Prompt:** `{{BATCH}}=RB-D4`

| Domain | ds-* | plan lines | v-* | tests | Infection | Done |
|--------|------|------------|-----|-------|-----------|------|
| 13 Infrastructure | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| 14 Lib-service | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |

---

## Phase D — Fuzzy

### RB-F: Fuzzy baselines and setpoint

**Depends on:** RB-2; prefer all RB-D\* done if pages.json5 / canaries changed  
**Prompt:** `{{MILESTONE}}=RB-F`

| Step | Status |
|------|--------|
| E2E preflight for capture profiles | [ ] |
| `bin/fuzzy-validator validate --all --phase all` (or restore setpoint first) | [ ] |
| Re-record / `setpoint capture` + `publish` if upstream render drift | [ ] |
| Update `validations/v-00-*.md` + affected `v-{nn}` capture notes / `latestBundle` | [ ] |
| Validate pass **test** + **mirror** | [ ] |
| Commit: `RB-F: Recapture fuzzy baselines after rebase` | [ ] |

---

## Phase E — Close

### RB-Z: Sign-off

**Depends on:** RB-1, RB-2, all planned RB-D\*, RB-F  
**Prompt:** `{{MILESTONE}}=RB-Z`

| Step | Status |
|------|--------|
| Re-run `sh bin/run-unit-tests.sh` | [ ] |
| Spot-check Infection gaps closed or listed | [ ] |
| Fuzzy still green (no accidental doc-only breakage) | [ ] |
| Write **Last rebase** note on [04-milestone-checklist.md](../../04-milestone-checklist.md) / [README.md](../../README.md) | [ ] |
| Fix broken links under `docs/megiddo/refactor/` | [ ] |
| Final report table to user | [ ] |
| Commit: `RB-Z: Close Megiddo rebase and redocument` | [ ] |

**Exit:** Skill complete → next is **R-01**.

---

## Quick reference

| Order | ID |
|-------|-----|
| 1 | RB-0 |
| 2 | RB-1 |
| 3 | RB-2 |
| 4 | RB-D1 → RB-D2 → RB-D3 → RB-D4 |
| 5 | RB-F |
| 6 | RB-Z |

**Next unchecked:** **RB-1** — Rebase onto base
