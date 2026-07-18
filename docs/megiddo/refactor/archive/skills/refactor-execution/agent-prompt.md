# Refactor Execution — Agent Milestone Prompts

**Preferred:** paste **Orchestrator** once — the parent agent runs each **R-*** as a **serialized sub-agent**, waiting for each to finish before the next.

**Resume / debug:** paste a single **Worker — R-{NN}** prompt below.

**Skill hub:** [SKILL.md](SKILL.md) · **Checklist:** [milestone-checklist.md](milestone-checklist.md)

| Placeholder | Examples |
|-------------|----------|
| `{{MILESTONE}}` | `R-01` · `R-05` · `R-14` |
| `{{FROM}}` | Optional resume point, e.g. `R-03` (skip completed earlier items) |
| `{{SLUG}}` | Branch slug, e.g. `rsvp-refactor` → `megiddo/r-01-rsvp-refactor` |

---

## Prompt — Orchestrator (one launch → all R-* via sub-agents)

```
You are the **Megiddo refactor-execution ORCHESTRATOR**. You do not write production refactor code, run full test suites, or sign off milestones yourself. You drive Phase 2 (R-01 … R-18) by launching **one serialized sub-agent per R-* milestone**, waiting for each to finish, then starting the next. **Phase 3 is audit-only** after R-18 — see [10-phase-2-continuation.md](../../10-phase-2-continuation.md).

## Read first

- docs/megiddo/refactor/skills/refactor-execution/SKILL.md
- docs/megiddo/refactor/skills/refactor-execution/milestone-checklist.md
- docs/megiddo/refactor/skills/refactor-execution/agent-prompt.md (worker prompt bodies)
- docs/megiddo/refactor/05-development-steering.md (DS-1 … DS-8)

## Hard rules

1. **Serialize:** Never launch two Task/sub-agents in parallel for this pipeline. One completes → you verify checklist → then launch the next.
2. **Worker scope:** Each sub-agent gets ONLY its R-* worker prompt from agent-prompt.md. Paste the full worker text into the Task `prompt` (sub-agents have no parent chat history). Set `{{MILESTONE}}` and `{{SLUG}}` for that id.
3. **Task tool:** Use subagent_type `generalPurpose`. Set `description` to the R-* id (e.g. `R-01 RSVP refactor`). Do **not** set `run_in_background` true — wait for the result.
4. **Checklist is source of truth:** After each worker, re-read milestone-checklist.md. If the worker failed to check boxes, commit, or run gates, either resume that same R-* once with a fix-up prompt, or STOP and report to the user.
5. **Stop and ask the user** if a worker reports: behavior/semantic change beyond V-* §2.3, Infection floor cannot be met, fuzzy failure looks like unintended regression, prior R-* branch not committed, or scope must jump to another domain.
6. **No push/PR** unless the user already asked.
7. **Commits:** Workers follow DS-6 (exactly **one squashed commit** per R-* on `megiddo/r-{nn}-*`). You do not squash across milestones.
8. **Resume:** If {{FROM}} is set, skip milestones already checked before that id. If unset, start at first unchecked item (usually R-01).
9. **Integration line:** Record on checklist metadata. R-01 branches from integration tip. R-{nn>1} branches from integration **after user merges** prior R, or from prior `megiddo/r-{nn-1}-*` tip if checklist says stack mode — workers must not guess; read checklist metadata.

{{FROM}} =

## Pipeline order

R-01 → R-02 → … → R-14 → R-15 → R-16 → R-17 → R-18

## Per-hop procedure

For each next milestone ID:

1. Write a one-line status to the user: `Starting {ID}…`
2. Launch Task with the **full** Worker — R-{NN} prompt from below, plus this preamble:

   ```
   Orchestrator context:
   - Integration branch/SHA: (from checklist metadata)
   - Prior R-* branch + commit: (from checklist, or none for R-01)
   - Branching mode: merge-to-integration | stack-on-prior-R
   - Previous milestone result summary: (paste worker's report)
   - You must update docs/megiddo/refactor/skills/refactor-execution/milestone-checklist.md
   - Return a structured report: status (ok|blocked|failed), checklist boxes updated, branch name, commit hash if any, next recommended ID, blockers
   ```

3. Wait for the Task result.
4. Verify checklist progress for that ID (branch, commit, PHPUnit, Infection, fuzzy, docs).
5. If `blocked`/`failed` → stop queue, summarize for the user, do not start the next ID.
6. If `ok` → remind user to merge `megiddo/r-{nn}-*` to integration before the next worker if using merge mode; proceed to next ID.

## Final response (after R-18 or stop)

| Field | Value |
|-------|-------|
| Integration line | |
| Milestones completed | |
| Stopped at (if any) | |
| Branches + commits | |
| PHPUnit (last run) | |
| Infection (last milestone) | |
| Fuzzy (last milestone) | |
| Next | merge / R-{nn} / Phase 3 audit (after R-18 only) |

Begin: read the checklist, determine the first milestone, launch that sub-agent now.
```

---

## Worker — R-{NN} (template for orchestrator Task bodies / manual sessions)

Replace `{{MILESTONE}}` (e.g. `R-01`) and `{{SLUG}}` (e.g. `rsvp-refactor`). Read the matching row in SKILL.md milestone map for DS / V / infection / fuzzy pointers.

```
You are executing **Megiddo {{MILESTONE}}** — Phase 2 refactor execution (one domain sprint).

## Read and follow

- docs/megiddo/refactor/skills/refactor-execution/SKILL.md
- docs/megiddo/refactor/skills/refactor-execution/milestone-checklist.md § {{MILESTONE}}
- docs/megiddo/refactor/05-development-steering.md (DS-1 … DS-8)
- docs/megiddo/refactor/06-test-framework.md (PHPUnit, Infection, E2E preflight, fuzzy)
- docs/megiddo/refactor/03-implementation-plan.md — target IDs for this sprint
- docs/megiddo/refactor/ds-{nn}-*-discovery.md §3 — proposed revision (implementation scope)
- docs/megiddo/refactor/validations/v-{nn}-*.md — §1 fuzzy gate, §2 test mutation boundaries, §2.4 Infection, §3 sign-off

Do **not** re-run discovery (DS-*) or rewrite T-* tests except within V-* §2.3 boundaries.

---

## 1. Prior milestone / branch hygiene

**If {{MILESTONE}} is R-01:** Skip to §2. Confirm integration line exists (post RB-Z, e.g. `megiddo/rebase-YYYYMMDD`) and working tree is clean or park WIP per user.

**If {{MILESTONE}} is R-02 … R-14:**

1. Identify prior id `R-{nn-1}` from checklist.
2. Verify prior branch `megiddo/r-{nn-1}-*` exists with **exactly one commit** (DS-6) containing all prior deliverables.
3. Verify **no uncommitted** changes on prior branch (`git status` clean on that branch tip).
4. Confirm integration policy from checklist:
   - **merge mode:** base new branch from **integration line tip** (must include merged R-{nn-1}), OR
   - **stack mode:** base from prior `megiddo/r-{nn-1}-*` tip.
5. If prior branch is incomplete, uncommitted, or gates unknown → **status=blocked**; do not start refactor.

---

## 2. Environment preflight

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox
bin/fuzzy-validator setpoint restore   # if baselines missing locally
export ORK3_E2E_BASE_URL=http://127.0.0.1:19080/orkui/
bin/ork-db use prod
export ORK3_E2E_USERNAME=admin ORK3_E2E_PASSWORD=password
export ORK3_E2E_TEST_PASSWORD=test-db-player
```

Credentials: [06-test-framework.md § E2E login credentials](../../06-test-framework.md#e2e-login-credentials-preflight). Auth Playwright specs must **run**, not skip.

---

## 3. Create working branch

```bash
git fetch
# Base: integration tip OR prior R branch tip (per checklist § metadata)
git checkout -b megiddo/r-{nn}-{slug}
```

Example: `megiddo/r-01-rsvp-refactor` for R-01 with slug `rsvp-refactor`. One milestone per branch (DS-3).

---

## 4. Refactor work

1. Implement targets from `03-implementation-plan.md` and `ds-{nn}-*-discovery.md` §3 for this domain only.
2. Move logic out of `orkui/` into `system/lib/ork3/` and/or `orkservice/*` per DS-1.
3. Remove `$DB` / unauthorized `Ork3::$Lib` from touched controllers/models in scope.
4. Adjust tests **only** within `validations/v-{nn}-*.md` §2.3 — preserve **semantic intent** (counts, enums, auth rules, API shapes).
5. Do not defer new test writing to a later R-* if refactor exposes uncovered paths — extend within §2.3 only.

---

## 5. Validate — full PHPUnit (no regression in semantic intent)

```bash
sh bin/run-unit-tests.sh
```

- Exit **0** required (DS-4, DS-5). No `--filter` or partial suite for sign-off.
- If tests fail: fix code or adjust tests within §2.3; never delete characterization coverage to go green without user approval.

---

## 6. Validate — Infection (no regression in mutation coverage)

Run commands from `validations/v-{nn}-*.md` §2.4 (Post-R-{nn} Infection scope). Typical:

```bash
sh bin/run-infection.sh --configuration=infection.t{nn}-*.json5
# and/or explicit --filter= from §2.4
```

- Meet documented `minMsi` / `minCoveredMsi` floors from T-* / V-* (do not lower without user approval → status=blocked).
- Record MSI / covered MSI in checklist notes.

---

## 7. Validate — fuzzy-validator (no render regression)

Page ids from V-* doc (**R-{nn} fuzzy gate** line or §1.3 validate command):

```bash
bin/ork-db deploy-sandbox
bin/fuzzy-validator validate --pages <comma-separated-ids> --phase all
```

- Must pass **test** (strict) and **mirror** (lenient) profiles.
- If intentional UI change: `record` affected pages + update baselines/setpoint per USER-GUIDE; document in V-* capture notes.
- If failure looks like unintended regression → fix code; do not widen fuzz thresholds without user approval.

---

## 8. Validate — Playwright (when milestone touches auth-gated e2e)

```bash
npx playwright test tests/e2e/infrastructure.spec.ts -g "home route loads after login"
# Plus domain specs cited in V-* §2.1 / T-* (e.g. tests/e2e/rsvp.spec.ts for R-01)
```

Authenticated specs must not skip. Known environment-specific failures (documented in V-*) may be noted; do not sign off new regressions.

---

## 9. Update milestone docs

1. Check off `validations/v-{nn}-*.md` §3 sign-off boxes.
2. Update [04-milestone-checklist.md](../../04-milestone-checklist.md) Phase 2 for {{MILESTONE}}.
3. Mark completed target IDs in [03-implementation-plan.md](../../03-implementation-plan.md).
4. Update [milestone-checklist.md](milestone-checklist.md) § {{MILESTONE}}: branch, commit hash, gate results, notes.
5. Any doc under `docs/megiddo/refactor/` touched → include in the **same commit** as code (DS-8 doc sign-off).

---

## 10. Stage, squash, and commit

1. Confirm full PHPUnit green again if late edits were made.
2. Stage **all** deliverables: PHP, tests, infection config if changed, fuzzy manifests only if intentional re-record, docs.
3. Squash to **one commit** on `megiddo/r-{nn}-{slug}` (DS-6).
4. Commit title: `R-{nn}: <imperative summary>` (e.g. `R-01: Migrate RSVP logic to EventService`).
5. Body: target IDs closed, gate summary, checklist pointers.
6. Do **not** push/PR unless user asked.

---

## Return to orchestrator

```
status: ok|blocked|failed
milestone: {{MILESTONE}}
branch: megiddo/r-{nn}-{slug}
base_sha: …
commit: hash or none
checklist: boxes updated
phpunit: exit code, counts
infection: config, MSI, covered MSI
fuzzy: pages, pass/fail test+mirror
playwright: specs run, pass/skip/fail
docs: files updated
next: R-{nn+1} or blocked reason
blockers: …
report: short narrative
```

---

## Worker quick map (copy {{MILESTONE}} + {{SLUG}} per row)

| ID | {{SLUG}} suggestion | V doc | Primary infection |
|----|---------------------|-------|-------------------|
| R-01 | `rsvp-refactor` | v-01-rsvp-validation.md | tools/infection/infection.t01-rsvp.json5 |
| R-02 | `auth-insert-refactor` | v-02-auth-validation.md | tools/infection/infection.t02-auth-insert.json5 |
| R-03 | `banner-refactor` | v-03-banner-validation.md | tools/infection/infection.t03-banner.json5 |
| R-04 | `eventajax-refactor` | v-04-eventajax-validation.md | tools/infection/infection.t04-eventajax.json5 |
| R-05 | `event-refactor` | v-05-event-validation.md | tools/infection/infection.t05-event.json5 |
| R-06 | `kingdom-refactor` | v-06-kingdom-validation.md | tools/infection/infection.t06-kingdom.json5 |
| R-07 | `park-refactor` | v-07-park-validation.md | tools/infection/infection.t07-park.json5 |
| R-08 | `admin-refactor` | v-08-admin-validation.md | tools/infection/infection.t08-admin.json5 |
| R-09 | `player-refactor` | v-09-player-validation.md | tools/infection/infection.t09-player.json5 |
| R-10 | `reports-refactor` | v-10-reports-validation.md | tools/infection/infection.t10-reports.json5 |
| R-11 | `search-refactor` | v-11-search-validation.md | tools/infection/infection.t11-search.json5 |
| R-12 | `attendance-refactor` | v-12-attendance-validation.md | tools/infection/infection.t12-attendance.json5 |
| R-13 | `infrastructure-refactor` | v-13-infrastructure-validation.md | tools/infection/infection.t13-infrastructure.json5 |
| R-14 | `lib-service-refactor` | v-14-lib-service-validation.md | t14-lib-auth-era + t14-lib-live-weather |

Fuzzy page lists: each V doc **R-{nn} fuzzy gate** line (or §1.3 `validate --pages` command).

---

## Example — paste-ready Worker for R-01

Set `{{MILESTONE}}=R-01`, `{{SLUG}}=rsvp-refactor`, then use the **Worker — R-{NN}** template above. Domain-specific fuzzy gate:

```bash
bin/fuzzy-validator validate --pages home-authenticated,player-profile,event-index-rsvp,event-index-rsvp-gok --phase all
```

Infection: `validations/v-01-rsvp-validation.md` §2.4.
