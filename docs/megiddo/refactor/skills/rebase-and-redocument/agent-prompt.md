# Rebase & Redocument — Worker Prompt Bodies (Post-Refactor)

**Do not paste this whole file.** For a full run, copy **[orchestrator.prompt](orchestrator.prompt)** only.

This file is the **worker library** the orchestrator (or a manual session) reads when launching a single RB-* Task. Each section below is one worker body.

**Skill hub:** [SKILL.md](SKILL.md) · **Checklist:** [milestone-checklist.md](milestone-checklist.md) · **Copy-paste launch:** [orchestrator.prompt](orchestrator.prompt)

---

## RB-0 (size + reset)

```
You are executing **Megiddo RB-0** (post-refactor rebase-and-redocument): preflight, checklist reset, and sizing only.

Read and follow:
- docs/megiddo/refactor/skills/rebase-and-redocument/SKILL.md
- docs/megiddo/refactor/skills/rebase-and-redocument/milestone-checklist.md § RB-0
- docs/megiddo/refactor/skills/rebase-and-redocument/mutation-matrix.md § RB-0 overlap inventory

Do **not** run `git rebase` yet. Do **not** migrate code yet.

### Tasks
1. If the checklist still shows a prior completed run, move its metadata/notes into **Prior runs** and clear current-run boxes / metadata.
2. git fetch; record Megiddo tip SHA/branch and origin/master SHA.
3. Ensure working tree is clean or park WIP per user.
4. Create working branch megiddo/rebase-YYYYMMDD from current Megiddo tip (no rebase).
5. Summarize commits/files in HEAD..origin/master — highlight orkui/, db-migrations/, templates, system/lib.
6. Build overlap inventory: paths changed on both Megiddo tip and origin/master since merge-base; list upstream-new orkui modules separately.
7. Assign sizing grade S/M/L per SKILL.md; write session plan into checklist metadata.
8. Confirm docker, bin/ork-db, bin/fuzzy-validator, tools/infection/ exist.
9. Check off RB-0; name next milestone (usually RB-1).
10. Optional commit: RB-0: Size post-refactor Megiddo rebase

### Return to orchestrator
status: ok|blocked|failed
checklist: what you checked
commit: hash or none
next: RB-1
blockers: …
sizing_grade: S|M|L
report: tip, base SHA, grade, overlap summary, upstream-new modules, session plan
```

---

## RB-1 (rebase + spirit merges)

```
You are executing **Megiddo RB-1**: rebase the post-refactor Megiddo line onto origin/master with spirit-preserving merges.

Read:
- docs/megiddo/refactor/skills/rebase-and-redocument/SKILL.md
- docs/megiddo/refactor/skills/rebase-and-redocument/milestone-checklist.md § RB-1
- docs/megiddo/refactor/skills/rebase-and-redocument/conflict-playbook.md

Prerequisite: RB-0 complete (sizing + overlap inventory recorded). Work on megiddo/rebase-* branch.

### Tasks
1. Confirm RB-0 metadata + overlap inventory present.
2. git rebase origin/master
3. Resolve conflicts per conflict-playbook:
   - Overlap orkui/system files: keep Megiddo thin layering; port upstream behavior into domain services / Model_*; never take upstream wholesale on thinned controllers.
   - Upstream-new files: take upstream; record for RB-N.
   - db-migrations: keep both.
   - tests/tools: prefer Megiddo; fix compile issues later in RB-2 if needed.
4. Do not delete characterization tests to resolve conflicts — stop and ask (status=blocked).
5. Do not leave new `$DB` / `Ork3::$Lib` in overlap files you touched — if upstream forced SQL into a controller, move it to lib during the conflict resolution when feasible; otherwise note for RB-N and keep Megiddo side clean.
6. Record conflict notes (file → where logic landed) on the checklist.
7. Check off RB-1; commit: RB-1: Rebase Megiddo onto master (spirit merge)

Out of scope: full PHPUnit green (RB-2), systematic hotspot Infection (RB-H), full new-module migration (RB-N), fuzzy recapture (RB-F).

### Return to orchestrator
status: ok|blocked|failed
checklist: …
commit: …
next: RB-2
blockers: …
report: conflict summary, overlap merges, upstream-new list, final tip SHA
```

---

## RB-2 (global tests)

```
You are executing **Megiddo RB-2**: make the full PHPUnit suite green after post-refactor rebase.

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
4. Prefer fixing coverage over deleting it; list deferred hotspot/new-module failures for RB-H / RB-N
5. E2E preflight per 06-test-framework.md — export credentials for mirror/sandbox; run auth Playwright smoke; do not sign off smoke-only or defer without user waiver
6. Check off RB-2; commit: RB-2: Repair tests after post-refactor rebase

Out of scope: RB-N migrations of new modules, fuzzy setpoint (RB-F).

### Return to orchestrator
status: ok|blocked|failed
checklist: …
commit: …
next: RB-H
blockers: …
deferred_to_RB_H_or_RB_N: …
report: PHPUnit result
```

---

## RB-H (overlap hotspots)

```
You are executing **Megiddo RB-H**: repair and verify overlap hotspots after rebase.

Read:
- docs/megiddo/refactor/skills/rebase-and-redocument/SKILL.md
- docs/megiddo/refactor/skills/rebase-and-redocument/milestone-checklist.md § RB-H
- docs/megiddo/refactor/skills/rebase-and-redocument/mutation-matrix.md § RB-H
- docs/megiddo/refactor/skills/rebase-and-redocument/conflict-playbook.md

Prerequisite: RB-2 complete. Use the RB-0 overlap inventory as the work list.

### For each overlap hotspot (sequential)
1. Confirm thin layering: no `$DB->` / `Ork3::$Lib` in the orkui paths.
2. Spot-check vs origin/master that upstream product behavior is present (or explicitly deferred with reason).
3. Fix remaining domain tests for that hotspot.
4. Run relevant tools/infection/infection.t*.json5 gates (do not lower MSI floors without asking — status=blocked if you would).
5. Check the hotspot row on the checklist.

### Batch close
- Shared sign-off checkboxes for RB-H
- One commit: RB-H: Repair overlap hotspots after rebase
- If you touched PHP: re-run sh bin/run-unit-tests.sh

### Return to orchestrator
status: ok|blocked|failed
checklist: …
commit: …
next: RB-N
blockers: …
report: per-hotspot ok/fixed/gap, Infection results
```

---

## RB-N (new upstream code — spirit enforcement)

```
You are executing **Megiddo RB-N**: scan and migrate new upstream frontend business/database logic so the tip still meets the spirit of the Megiddo refactor.

Read:
- docs/megiddo/refactor/skills/rebase-and-redocument/SKILL.md
- docs/megiddo/refactor/skills/rebase-and-redocument/milestone-checklist.md § RB-N
- docs/megiddo/refactor/skills/rebase-and-redocument/mutation-matrix.md § RB-N
- docs/megiddo/refactor/02-requirements.md (allowed vs not-allowed frontend behavior)
- docs/megiddo/refactor/idioms-00-charter.md (match sibling style when thinning)

Prerequisite: RB-2 complete; prefer RB-H done. Upstream-new modules were listed in RB-0 / RB-1.

### Tasks
1. Inventory upstream-new and heavily rewritten orkui/ areas since the prior merge-base (controllers, models, templates with PHP logic).
2. Static scan those areas (and then repo-wide orkui/):
   - rg '\$DB->' orkui/
   - rg 'Ork3::\$Lib' orkui/
   - raw SQL / yapo-in-frontend / authorization INSERTs / domain eligibility rules in controllers or templates
3. For each violation, migrate in the spirit of completed R-*:
   - Domain logic + SQL → system/lib/ork3/ (new class OK for a new module)
   - Expose via orkservice / Model_* patterns used by siblings
   - Leave orkui controllers/templates thin and presentational
   - Prefer idiomatic load_model / wrapper style from idioms-00-charter.md
4. Add or extend characterization tests for moved behavior; run full PHPUnit until green.
5. Do not lower Infection floors without asking. New modules: add a scoped infection config under tools/infection/ if practical, or list a gap.
6. If scope explodes beyond one milestone, status=blocked with a proposed RB-N2 split — do not leave static gates red silently.
7. Check off RB-N; commit: RB-N: Migrate new upstream frontend logic behind services

Out of scope: fuzzy setpoint (RB-F), starting P3-4.

### Return to orchestrator
status: ok|blocked|failed
checklist: …
commit: …
next: RB-F
blockers: …
report: modules touched, migrations performed, static gate results, test results, remaining waivers
```

---

## RB-F (fuzzy)

```
You are executing **Megiddo RB-F**: refresh fuzzy-validator baselines after post-refactor rebase.

Read:
- docs/megiddo/refactor/skills/rebase-and-redocument/SKILL.md
- docs/megiddo/refactor/skills/rebase-and-redocument/milestone-checklist.md § RB-F
- docs/megiddo/fuzzy-validator/USER-GUIDE.md
- docs/megiddo/refactor/06-test-framework.md § E2E preflight

Prerequisite: RB-2; prefer RB-H + RB-N done if pages/UI/schema changed.

### Tasks
1. bin/ork-db deploy-sandbox; E2E preflight for test + mirror profiles
2. Restore setpoint if baselines missing: bin/fuzzy-validator setpoint restore …
3. bin/fuzzy-validator validate --all --phase all
4. On legitimate upstream render drift: setpoint capture + publish
5. Update latestBundle / active notes as needed
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

---

## RB-Z (close)

```
You are executing **Megiddo RB-Z**: close the post-refactor rebase-and-redocument run.

Read checklist § RB-Z and SKILL.md exit criteria.

### Tasks
1. Verify RB-1, RB-2, RB-H, RB-N, RB-F are checked (or waivers noted).
2. Re-run sh bin/run-unit-tests.sh
3. Confirm static gates: rg '\$DB->' orkui/ and rg 'Ork3::\$Lib' orkui/ clean (or waivers listed)
4. Confirm fuzzy still green (quick validate or trust RB-F if unchanged)
5. Update Last rebase note on docs/megiddo/refactor/04-milestone-checklist.md and README.md (date, base SHA, branch)
6. Mark rebase item progress on the remaining-work checklist as appropriate
7. Fix broken links under active docs/megiddo/refactor/
8. Commit: RB-Z: Close post-refactor Megiddo rebase
9. Final report for orchestrator/user

Do not push/PR unless explicitly asked. Do not start R-*. Next is P3-4.

### Return to orchestrator
status: ok|blocked|failed
commit: …
next: P3-4
blockers: …
report: base SHA, branch, PHPUnit, static gates, Infection gaps, fuzzy bundle, RB-N summary, remaining risk
```
