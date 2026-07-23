---
name: rebase-and-redocument
description: >-
  Post-refactor rebase of the Megiddo line onto upstream master. Preserve thinned
  orkui controllers and domain services through conflict merges, repair tests and
  fuzzy baselines, then scan new upstream frontend code for $DB / Ork3::$Lib /
  business-logic regressions and migrate them in the spirit of the refactor.
  Use when the user asks to rebase Megiddo after R-* / Phase 3 / idiom work, sync
  with master, or catch frontend logic that landed upstream during the tool build.
disable-model-invocation: true
---

# Megiddo — Post-Refactor Rebase and Redocument

**Context:** R-01 … R-19d, Phase 3 automated audit / gate fixes, and idiom enforcement are **complete**. This skill rebases that tip onto a newer `origin/master` without undoing the refactor.

Work runs as **RB-*** milestones (checklist + prompts). Prefer **one orchestrator chat** that drives **serialized sub-agents** per milestone.

**After RB-Z:** resume remaining human close-out (**P3-4** → **P3-5** → optional **P3-6**) in [../../04-milestone-checklist.md](../../04-milestone-checklist.md). Do **not** restart R-*.

## When to use

- Rebase / sync Megiddo tip with `master` after the refactor stack is done
- Upstream landed large `orkui/` / migration / template changes (e.g. new modules)
- Need to keep Megiddo layering while absorbing product changes
- Need to hunt new frontend `$DB` / `Ork3::$Lib` / business-logic regressions in upstream code

## How to run

### A — Orchestrated (preferred)

1. Open **[orchestrator.prompt](orchestrator.prompt)**.
2. Select all → copy → paste into a **new** agent chat. Do not edit the file.
3. Parent launches **serialized** Task sub-agents: RB-0 → RB-1 → RB-2 → RB-H → RB-N → RB-F → RB-Z.
4. Parent waits for each worker, checks [milestone-checklist.md](milestone-checklist.md), then starts the next. Stops on `blocked`/`failed`.

Workers get the full milestone prompt text from [agent-prompt.md](agent-prompt.md) (no shared chat history). Do **not** parallelize RB-* Tasks.

**Resume:** in the new chat, after pasting, add one line: `Resume from: RB-2` (or whichever id). Otherwise the orchestrator starts at the first unchecked checklist item.

### B — Manual (one milestone per chat)

1. Copy a single **worker** fenced block from [agent-prompt.md](agent-prompt.md) (not the whole file).
2. Paste into a new chat; take the next unchecked checklist item if unsure which worker.
3. Check off; report; stop. Next chat continues.

Supporting refs: [mutation-matrix.md](mutation-matrix.md) · [conflict-playbook.md](conflict-playbook.md)

## Starting a new run (required)

If [milestone-checklist.md](milestone-checklist.md) still shows a prior completed run:

1. Move prior metadata + notes into the checklist **Prior runs** appendix (do not delete history).
2. Clear all current-run checkboxes to `[ ]`.
3. Blank the **Run metadata** table except the date started.
4. Set working branch to `megiddo/rebase-{YYYYMMDD}` from the **current Megiddo tip** (not from the old rebase branch alone).

Never treat a previous RB-Z `[x]` as “already done” for a new upstream base.

## Milestone map

| ID | Phase | Deliverable | Typical session |
|----|-------|-------------|-----------------|
| **RB-0** | A — Size | Preflight, upstream delta, overlap inventory, **sizing grade**, checklist reset | Always first |
| **RB-1** | A — Integrate | `git rebase` onto base; **spirit-preserving** conflict merges | Own session if conflicts likely |
| **RB-2** | B — Global tests | Full PHPUnit green; e2e preflight; sandbox deploy | Own session |
| **RB-H** | C — Hotspots | Repair tests / Infection / thin-layer checks for **overlap files** from RB-0 | Batch or split if **L** |
| **RB-N** | C — New code | Scan **new/changed upstream** `orkui/` for `$DB` / `Ork3::$Lib` / domain logic; migrate in spirit of refactor | Own session (often large) |
| **RB-F** | D — Fuzzy | Dual-profile baselines / setpoint after UI + schema drift | Own session |
| **RB-Z** | E — Close | Last-rebase note, link fixes, sign-off; hand off to P3-4 | Final |

## Spirit of the refactor (non-negotiable during RB-1 and RB-N)

From [02-requirements.md](../../02-requirements.md) and completed R-*:

| Keep / restore | Never reintroduce |
|----------------|-------------------|
| Thin `orkui/` controllers/models calling services / `Model_*` | `$DB->` in `orkui/` |
| Domain logic in `system/lib/ork3/` + `orkservice/*` | Direct `Ork3::$Lib` domain calls in `orkui/` |
| Auth grants via Authorization APIs | Raw `INSERT INTO ork_authorization` from frontend |
| Characterization tests that lock behavior | Deleting tests to “win” the rebase |

When upstream and Megiddo both changed the same file: **do not** take upstream wholesale. Merge so upstream **product behavior** lands, but Megiddo **layering** wins. See [conflict-playbook.md](conflict-playbook.md).

**RB-N** extends that spirit to **new** upstream modules (e.g. Qualification Tests): if they ship business/DB logic in `orkui/`, migrate behind lib/service (or thin wrappers) until static gates are clean — or stop and ask if scope explodes.

## Sizing (RB-0 output)

After `git fetch` and inspecting `HEAD..origin/master` (and optionally a dry-run rebase), assign a grade:

| Grade | Heuristic (any match → that grade or higher) | Orchestrator plan |
|-------|-----------------------------------------------|-------------------|
| **S** | ≤15 commits; few/no overlapping Megiddo `orkui/` files; no new frontend modules | One sub-agent per RB-*; RB-N may be short |
| **M** | Moderate overlap + migrations; some template churn | Default — one sub-agent per milestone |
| **L** | Large new modules, many overlap conflicts, or messy Player/Kingdom/Reports/template merges | RB-1 / RB-2 / RB-N alone; split RB-H by file or domain |

Record the grade on the checklist. Do not silently collapse a **L** run into one mega-session.

## Branching and commits

| Milestone | Branch pattern | Commits |
|-----------|----------------|---------|
| RB-0 | Create `megiddo/rebase-{YYYYMMDD}` from Megiddo tip (no rebase yet) | Docs-only OK; optional |
| RB-1…RB-Z | Same branch | **One commit per RB-*** (DS-6), title `RB-2: …` |

## Non-negotiables

From [05-development-steering.md](../../05-development-steering.md):

- Prefer **rebase** over merge onto `master`
- Never `--force` to `master` / `main`
- Never skip hooks unless the user asks
- Full PHPUnit at RB-2 and again at RB-Z (DS-4/DS-5)
- No drive-by product features unrelated to absorbing upstream or clearing spirit gates
- Never delete characterization tests to go green without a checklist “gap” note
- Infection configs live under `tools/infection/`

## Stop / ask the user

- Conflict chooses between two shipped product behaviors (not just layering)
- Upstream removed a feature Megiddo still tests
- RB-N migration of a new module exceeds a reasonable single milestone (propose split)
- Fuzzy failures look like product bugs on master (not baseline drift)
- Infection floors cannot be met without huge scope expansion
- Sizing **L** and the user expected a single session

## Exit criteria (whole skill)

- [ ] Checklist reset + all RB-* for **this** run complete (or waived in writing)
- [ ] `sh bin/run-unit-tests.sh` green
- [ ] `rg '\$DB->' orkui/` and `rg 'Ork3::\$Lib' orkui/` clean (or explicit waivers listed)
- [ ] Hotspot Infection gates green (or gaps listed)
- [ ] Fuzzy validate pass test+mirror for agreed page set / setpoint published
- [ ] README + `04-milestone-checklist.md` record **Last rebase** date + base SHA
- [ ] Ready for **P3-4** (not R-01)
