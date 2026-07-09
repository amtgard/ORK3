---
name: rebase-and-redocument
description: >-
  Rebase the Megiddo refactor line onto upstream master, then repair drift in
  discovery notes (ds-*), R-* designs, unit tests, Infection configs, and fuzzy
  snapshots across multi-step RB-* milestones with agent prompts. Use when the
  user asks to rebase Megiddo work, sync with master, refresh line ranges after
  upstream merges, or re-document after a long tool build delay.
disable-model-invocation: true
---

# Megiddo — Rebase and Redocument

Upstream moved while Megiddo tooling (ork-db, fuzzy-validator, DS/T/V) was built. Work runs as **RB-*** milestones (checklist + prompts). Prefer **one orchestrator chat** that drives **serialized sub-agents** per milestone so each piece stays bite-sized without you pasting nine times.

**Do not** start R-* until [milestone-checklist.md](milestone-checklist.md) Phase E (RB-Z) is checked, or the user explicitly waives.

## When to use

- Rebase / sync with master / catch up upstream / redocument after rebase
- Discovery line ranges or target IDs no longer match `orkui/`
- PHPUnit, Infection, or fuzzy validate fail after merging onto `master`

## How to run

### A — Orchestrated (preferred: one paste)

1. Open [agent-prompt.md](agent-prompt.md) → **Prompt — Orchestrator**.
2. Paste into a new agent chat (optionally set `{{FROM}}` to resume).
3. The parent agent launches **serialized** Task sub-agents: RB-0 → RB-1 → RB-2 → RB-D1…D4 → RB-F → RB-Z.
4. Parent waits for each worker, checks [milestone-checklist.md](milestone-checklist.md), then starts the next. Stops on `blocked`/`failed`.

Workers get the full milestone prompt text (no shared chat history). Do **not** parallelize RB-* Tasks.

### B — Manual (one milestone per chat)

1. Copy a single worker prompt from [agent-prompt.md](agent-prompt.md).
2. Set `{{MILESTONE}}` / `{{BATCH}}` or take the next unchecked checklist item.
3. Check off; report; stop. Next chat continues.

Supporting refs: [mutation-matrix.md](mutation-matrix.md) · [conflict-playbook.md](conflict-playbook.md)

## Milestone map

| ID | Phase | Deliverable | Typical session |
|----|-------|-------------|-----------------|
| **RB-0** | A — Size | Preflight, upstream delta, **sizing grade**, filled checklist plan | Always first |
| **RB-1** | A — Integrate | `git rebase` onto base + conflicts resolved | Own session if conflicts likely |
| **RB-2** | B — Global tests | Full PHPUnit green; e2e preflight; sandbox deploy | Own session |
| **RB-D1** | C — Domains | DS/T/V repair for domains **01–04** (RSVP, auth, banner, EventAjax) | Batch |
| **RB-D2** | C — Domains | Domains **05–08** (event, kingdom, park, admin) | Batch |
| **RB-D3** | C — Domains | Domains **09–12** (player, reports, search, attendance) | Batch |
| **RB-D4** | C — Domains | Domains **13–14** (infrastructure, lib-service) | Batch |
| **RB-F** | D — Fuzzy | Registry + dual-profile baselines / setpoint | Own session |
| **RB-Z** | E — Close | Checklist note, link fixes, sign-off report | Final |

**Per domain inside RB-D\*:** refresh `ds-*` §1/§3 + `03-implementation-plan.md` lines + `validations/v-{nn}` paths + domain unit/e2e fixes + `infection.t{nn}` re-gate. Details in [mutation-matrix.md](mutation-matrix.md).

## Sizing (RB-0 output — drives how many sessions)

After `git fetch` and inspecting `HEAD..origin/master` (and optionally a dry-run rebase), assign a grade:

| Grade | Heuristic (any match → that grade or higher) | Orchestrator plan |
|-------|-----------------------------------------------|-------------------|
| **S** | ≤15 commits behind; few/no `orkui/` touches; rebase likely clean | Still **one sub-agent per RB-*** (keeps context small); queue runs faster |
| **M** | Moderate `orkui/` / template / migration churn | Default — one sub-agent per RB-* / RB-D batch |
| **L** | Large `orkui/` rewrites, many migrations, or messy conflicts | RB-1 / RB-2 alone; split RB-D\* into per-domain `RB-D-{nn}` sub-agents |

Record the grade on the checklist. Do not silently upgrade a **L** rebase into a single mega-session.

## Branching and commits

| Milestone | Branch pattern | Commits |
|-----------|----------------|---------|
| RB-0 | Work on tip or create `megiddo/rebase-{YYYYMMDD}` (no rebase yet) | Docs-only OK; optional |
| RB-1…RB-Z | Same integration branch `megiddo/rebase-{YYYYMMDD}` | **One squashed commit per RB-*** (DS-6), title `RB-2: …` |

Do **not** use separate branches per RB-D domain unless sizing **L** requires isolation — prefer linear commits on the rebase branch.

## Non-negotiables

From [05-development-steering.md](../../05-development-steering.md):

- Prefer **rebase** over merge onto `master`
- Never `--force` to `master` / `main`
- Never skip hooks unless the user asks
- Full PHPUnit at RB-2 sign-off and again at RB-Z (DS-4/DS-5)
- No production R-* refactors — repair docs/tests/baselines only
- Never delete characterization tests to go green without a checklist “gap” note

## Stop / ask the user

- Conflict chooses between two shipped behaviors
- Upstream removed a feature Megiddo still tests
- Fuzzy failures look like product regressions on master
- Infection cannot meet thresholds without huge scope expansion
- Sizing **L** and the user expected a single session

## Exit criteria (whole skill)

- [ ] All RB-* on the checklist complete (or waived in writing)
- [ ] `sh bin/run-unit-tests.sh` green
- [ ] In-scope Infection gates green (or gaps listed)
- [ ] Fuzzy validate pass test+mirror for agreed page set / setpoint published
- [ ] `ds-*` / implementation-plan / validations refreshed for in-scope domains
- [ ] Checklist records **Last rebase** date + base SHA
- [ ] Ready for [R-01](../../04-milestone-checklist.md)
