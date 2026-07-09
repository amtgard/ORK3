# Rebase & Redocument — Agent Milestone Prompts

**Preferred:** paste **Orchestrator** once — the parent agent runs each RB-* as a **serialized sub-agent** (bite-sized), waiting for each to finish before the next.

**Resume / debug:** paste a single RB-* or batch prompt below.

**Skill hub:** [SKILL.md](SKILL.md) · **Checklist:** [milestone-checklist.md](milestone-checklist.md)

| Placeholder | Examples |
|-------------|----------|
| `{{MILESTONE}}` | `RB-0` · `RB-1` · `RB-2` · `RB-F` · `RB-Z` · `RB-D-01` |
| `{{BATCH}}` | `RB-D1` · `RB-D2` · `RB-D3` · `RB-D4` |
| `{{FROM}}` | Optional resume point, e.g. `RB-2` (skip completed earlier items) |

---

## Prompt — Orchestrator (one launch → all RB-* via sub-agents)

```
You are the **Megiddo rebase-and-redocument ORCHESTRATOR**. You do not perform rebase, test repair, domain redocument, or fuzzy capture yourself. You drive the pipeline by launching **one serialized sub-agent per RB-* milestone** (or domain batch), waiting for each to finish, then starting the next.

## Read first

- docs/megiddo/refactor/skills/rebase-and-redocument/SKILL.md
- docs/megiddo/refactor/skills/rebase-and-redocument/milestone-checklist.md
- docs/megiddo/refactor/skills/rebase-and-redocument/agent-prompt.md (worker prompt bodies)

## Hard rules

1. **Serialize:** Never launch two Task/sub-agents in parallel for this pipeline. One completes → you verify checklist → then launch the next.
2. **Worker scope:** Each sub-agent gets ONLY its milestone prompt from agent-prompt.md (RB-0, RB-1, …). Paste the full worker prompt text into the Task `prompt` (sub-agents have no parent chat history).
3. **Task tool:** Use subagent_type `generalPurpose` (or `shell` only if the milestone is purely git/commands with no doc judgment — prefer generalPurpose). Set `description` to the RB-* id (e.g. `RB-1 rebase`). Do **not** set `run_in_background` true — wait for the result.
4. **Checklist is source of truth:** After each worker, re-read milestone-checklist.md. If the worker failed to check boxes or commit when required, either resume that same RB-* once with a fix-up prompt, or STOP and report to the user.
5. **Stop and ask the user** (do not continue the queue) if a worker reports: unresolvable conflict, deleted characterization tests needed, product regression on master (fuzzy), Infection floor cannot be met, or sizing must jump to L mid-flight.
6. **No R-* refactors.** No push/PR unless the user already asked.
7. **Commits:** Workers follow DS-6 (one commit per RB-* on megiddo/rebase-*). You do not squash across milestones.
8. **Resume:** If {{FROM}} is set, skip milestones already checked before that id. If unset, start at first unchecked item (usually RB-0).

{{FROM}} =

## Pipeline order

RB-0 → RB-1 → RB-2 → RB-D1 → RB-D2 → RB-D3 → RB-D4 → RB-F → RB-Z

After RB-0, read the sizing grade from the checklist:

- **S:** Still use sub-agents per milestone (keeps context small). You may pass a note that grade is S so workers stay lean — do **not** collapse into one mega-worker.
- **M:** Default — one sub-agent per row above.
- **L:** After RB-2, if a domain batch is too large, split RB-D* into per-domain workers RB-D-01 … using the single-domain prompt (still serialized).

## Per-hop procedure

For each next milestone ID:

1. Write a one-line status to the user: `Starting {ID}…`
2. Launch Task with the **full** matching worker prompt from agent-prompt.md, plus this preamble:

   ```
   Orchestrator context:
   - Working branch: (from checklist metadata)
   - Base SHA: (from checklist)
   - Sizing grade: (from checklist)
   - Previous milestone result summary: (paste worker’s report)
   - You must update docs/megiddo/refactor/skills/rebase-and-redocument/milestone-checklist.md
   - Return a structured report: status (ok|blocked|failed), checklist boxes updated, commit hash if any, next recommended ID, blockers
   ```

3. Wait for the Task result.
4. Verify checklist progress for that ID.
5. If `blocked`/`failed` → stop queue, summarize for the user, do not start the next ID.
6. If `ok` → proceed to the next ID.

## Final response (after RB-Z or stop)

| Field | Value |
|-------|-------|
| Branch | |
| Base SHA | |
| Milestones completed | |
| Stopped at (if any) | |
| PHPUnit | |
| Fuzzy bundle | |
| Infection gaps | |
| Next | R-01 or resume {{FROM}} |

Begin: read the checklist, determine the first milestone, launch that sub-agent now.
```

---

## Worker prompts (for orchestrator Task bodies / manual sessions)

### RB-0 (size)

```
You are executing **Megiddo RB-0** (rebase-and-redocument): preflight and sizing only.

Read and follow:
- docs/megiddo/refactor/skills/rebase-and-redocument/SKILL.md
- docs/megiddo/refactor/skills/rebase-and-redocument/milestone-checklist.md § RB-0

Do **not** run `git rebase` yet. Do **not** repair ds-*/tests/fuzzy.

### Tasks
1. git fetch; record Megiddo tip SHA/branch and origin/master SHA.
2. Ensure working tree is clean or park WIP per user.
3. Create or confirm working branch megiddo/rebase-YYYYMMDD from Megiddo tip (no rebase).
4. Summarize commits/files in HEAD..origin/master — highlight orkui/, db-migrations/, templates, tests.
5. Assign sizing grade S/M/L per SKILL.md and write the session plan into the checklist metadata table.
6. Confirm docker, bin/ork-db, bin/fuzzy-validator exist.
7. Check off RB-0; name the next milestone (usually RB-1).
8. Optional commit: RB-0: Size Megiddo rebase onto master

### Return to orchestrator
status: ok|blocked|failed
checklist: what you checked
commit: hash or none
next: RB-1 (or other)
blockers: …
sizing_grade: S|M|L
report: tip, base SHA, grade, session plan
```

### RB-1 (rebase)

```
You are executing **Megiddo RB-1**: rebase the Megiddo line onto origin/master.

Read:
- docs/megiddo/refactor/skills/rebase-and-redocument/SKILL.md
- docs/megiddo/refactor/skills/rebase-and-redocument/milestone-checklist.md § RB-1
- docs/megiddo/refactor/skills/rebase-and-redocument/conflict-playbook.md

Prerequisite: RB-0 complete (sizing recorded). Work on megiddo/rebase-* branch.

### Tasks
1. Confirm RB-0 metadata present.
2. git rebase origin/master
3. Resolve conflicts per conflict-playbook (upstream for orkui/system/orkservice; keep Megiddo tests/tools/docs structure).
4. Do not delete characterization tests to resolve conflicts — stop and ask if forced (status=blocked).
5. When rebase finishes, note any obvious breakage for RB-2 (do not require green PHPUnit yet).
6. Check off RB-1; one commit: RB-1: Rebase Megiddo line onto master

Out of scope: ds-* line refresh, Infection, fuzzy recapture (later RB-*).

### Return to orchestrator
status: ok|blocked|failed
checklist: …
commit: …
next: RB-2
blockers: …
report: conflict summary, final tip SHA
```

### RB-2 (global tests)

```
You are executing **Megiddo RB-2**: make the full PHPUnit suite green after rebase.

Read:
- docs/megiddo/refactor/skills/rebase-and-redocument/SKILL.md
- docs/megiddo/refactor/skills/rebase-and-redocument/milestone-checklist.md § RB-2
- docs/megiddo/refactor/06-test-framework.md (E2E preflight)
- docs/megiddo/refactor/05-development-steering.md (DS-4/DS-5)

Prerequisite: RB-1 complete.

### Tasks
1. docker compose -f docker-compose.php8.yml up -d
2. bin/ork-db deploy-sandbox — fix migration/schema drift if deploy fails
3. sh bin/run-unit-tests.sh until exit 0 (fix tests/fixtures for upstream API/schema/UI drift)
4. Prefer fixing coverage over deleting it; list any deferred domain-specific failures for RB-D*
5. E2E preflight before auth-gated Playwright; smoke or defer e2e with notes
6. Check off RB-2; commit: RB-2: Repair tests after Megiddo rebase

Out of scope: systematic ds-* line audits (RB-D*), fuzzy setpoint (RB-F), R-* refactors.

### Return to orchestrator
status: ok|blocked|failed
checklist: …
commit: …
next: RB-D1
blockers: …
deferred_to_RB_D: …
report: PHPUnit result
```

### RB-D\* domain batch

```
You are executing **Megiddo {{BATCH}}** (rebase-and-redocument domain redocument).

{{BATCH}} = RB-D1
# Domains: RB-D1=01-04 · RB-D2=05-08 · RB-D3=09-12 · RB-D4=13-14

Read:
- docs/megiddo/refactor/skills/rebase-and-redocument/SKILL.md
- docs/megiddo/refactor/skills/rebase-and-redocument/milestone-checklist.md § {{BATCH}}
- docs/megiddo/refactor/skills/rebase-and-redocument/mutation-matrix.md

Prerequisite: RB-2 complete. No R-* production refactors.

### For each domain {nn} in this batch (sequential)
1. Open ds-{nn}-*-discovery.md §1 — update class/method/line/behavior to match current orkui/ (and cited domain code).
2. Amend §3 only if upstream closed/changed the gap; add post-rebase note with base SHA.
3. Sync matching target ID rows in docs/megiddo/refactor/03-implementation-plan.md
4. Fix validations/v-{nn}-*.md §1 page ids / §2 test paths if drifted.
5. Fix remaining domain tests; run Infection for infection.t{nn}*.json5 (update source paths if needed; do not lower MSI floors without asking — status=blocked if you would).
6. Check the domain row on the checklist.

### Batch close
- Shared sign-off checkboxes for {{BATCH}}
- One commit: {{BATCH}}: Redocument domains after rebase
- If you touched PHP: re-run sh bin/run-unit-tests.sh

### Return to orchestrator
status: ok|blocked|failed
checklist: …
commit: …
next: RB-D2|RB-D3|RB-D4|RB-F
blockers: …
report: per-domain ok/stale/fixed, Infection results
```

### Single domain (sizing L split)

```
You are executing **Megiddo RB-D-{{NN}}** — single-domain redocument after rebase.

{{NN}} = 01

Same process as one domain inside an RB-D* batch (mutation-matrix.md).
Checklist: mark that domain under its parent batch (or add RB-D-{{NN}} note).
Commit: RB-D-{{NN}}: Redocument domain after rebase

### Return to orchestrator
status: ok|blocked|failed
commit: …
next: next domain or next batch
blockers: …
```

### RB-F (fuzzy)

```
You are executing **Megiddo RB-F**: refresh fuzzy-validator baselines after rebase.

Read:
- docs/megiddo/refactor/skills/rebase-and-redocument/SKILL.md
- docs/megiddo/refactor/skills/rebase-and-redocument/milestone-checklist.md § RB-F
- docs/megiddo/fuzzy-validator/USER-GUIDE.md
- docs/megiddo/refactor/06-test-framework.md § E2E preflight

Prerequisite: RB-2; prefer RB-D* done if pages.json5/canaries changed.

### Tasks
1. bin/ork-db deploy-sandbox; E2E preflight for test + mirror profiles
2. Restore setpoint if baselines missing: bin/fuzzy-validator setpoint restore …
3. bin/fuzzy-validator validate --all --phase all
4. On legitimate upstream render drift: record affected pages or setpoint capture + publish
5. Update validations/v-00-*.md and affected v-{nn} capture notes / latestBundle
6. Re-validate until test + mirror pass
7. Commit: RB-F: Recapture fuzzy baselines after rebase

If failures look like product bugs on master (not baseline drift), status=blocked.

### Return to orchestrator
status: ok|blocked|failed
commit: …
next: RB-Z
blockers: …
report: bundle id, validate summary
```

### RB-Z (close)

```
You are executing **Megiddo RB-Z**: close rebase-and-redocument.

Read checklist § RB-Z and SKILL.md exit criteria.

### Tasks
1. Verify RB-1, RB-2, planned RB-D*, RB-F are checked (or waivers noted).
2. Re-run sh bin/run-unit-tests.sh
3. Confirm fuzzy still green (quick validate or trust RB-F if unchanged).
4. Add Last rebase note to docs/megiddo/refactor/04-milestone-checklist.md and/or README.md (date, base SHA).
5. Fix broken links under docs/megiddo/refactor/
6. Commit: RB-Z: Close Megiddo rebase and redocument
7. Final report for orchestrator/user

Do not push/PR unless explicitly asked. Do not start R-*.

### Return to orchestrator
status: ok|blocked|failed
commit: …
next: R-01
blockers: …
report: base SHA, branch, PHPUnit, Infection gaps, fuzzy bundle, remaining risk
```

---

## Batch quick map

| Batch | Domains | R-* docs/tests trustworthy for |
|-------|---------|--------------------------------|
| RB-D1 | 01–04 | R-01…R-04 |
| RB-D2 | 05–08 | R-05…R-08 |
| RB-D3 | 09–12 | R-09…R-12 |
| RB-D4 | 13–14 | R-13…R-14 |
| RB-F | global fuzzy | all R-* fuzzy gates |
