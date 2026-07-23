# Version 2 — Skills & Milestones

**Status:** Implemented (SK-4 dry-run optional / operator)  
**Parent:** [README.md](./README.md)  
**Landing zone:** [../skills/](../skills/)

Follow the same skill shape as [../../refactor/skills/rebase-and-redocument/](../../refactor/skills/rebase-and-redocument/): `SKILL.md`, `orchestrator.prompt`, worker prompts, optional checklist.

---

## Skill map

| Skill id | Folder (planned) | One-line job |
|----------|------------------|--------------|
| **run-setpoint-drift** | `skills/run-setpoint-drift/` | Master setpoint vs current work → classified drift report (test + mirror) |
| **putative-drift-overlay** | `skills/putative-drift-overlay/` | Requirements/plans → draft intentional overlay for later evaluation |

Shared human README for both: update [../skills/README.md](../skills/README.md) when skills ship (process guidance already outlined in [README.md](./README.md)).

---

## Skill 5.1 — `run-setpoint-drift`

### Purpose

Automatically evaluate **current work** against the **locked gold-master setpoint** associated with `origin/master` (or an explicitly pinned setpoint bundle), on both database profiles, and produce a classified drift report.

### When to use

- Feature implementation is ready for UI stability review.
- Mid-feature checkpoint when visual risk is high.
- Before promoting a new setpoint.
- After rebase onto master when UI may have drifted.

### Inputs

| Input | Notes |
|-------|-------|
| Worktree | Current branch / dirty or clean work as operator directs |
| Base | `origin/master` setpoint via `setpoint.json` `latestBundle` on that ref, or explicit `--setpoint` |
| Overlays | Optional intentional (+ natural) overlays for the workstream |
| Pages | Default: registry pages covered by the setpoint; allow subset |

### Preflight (mandatory)

1. Docker php8 stack up; Playwright + Python deps present.
2. `bin/fuzzy-validator setpoint restore` for the chosen gold-master bundle.
3. **Mirror freshness:**
   - Read age from `tools/ork-db/extracted/manifest.json` `extracted_at` (or successor probe from [02-tool-extension.md](./02-tool-extension.md) §6).
   - If age **> 7 days**, **stop and prompt** the human to refresh/import the prod mirror; do not continue until the operator confirms refresh (or explicitly overrides with a recorded reason — override must appear in the report header).
4. `bin/ork-db deploy-sandbox` if sandbox is missing/stale per existing ork-db rules.
5. Confirm both profiles reachable: `bin/ork-db use prod` / `use dev` (fuzzy-validator already switches during dual-profile runs).

### Procedure

1. Ensure baselines match the gold-master setpoint (not re-recorded from work).
2. Run `bin/fuzzy-validator validate --profiles test,mirror --phase all` with overlay flags once FV2 CLI exists; until then, run v1 validate and label the report as “unclassified” in the skill output.
3. Collect `reports/run-*/` + `drifts.json` + reproduction packs.
4. Optionally launch the **agent evaluator** (R5): annotate unexpected drifts against requirements; write `annotations.json` only.
5. Summarize for the human: unexpected count, expected intentional/natural counts, report path, mirror age, setpoint id.

### Outputs

- Gate exit code (honest).
- Drift report + reproduction steps.
- Optional annotations (non-masking).
- Short operator summary in chat.

### Non-goals

- Re-recording baselines or publishing setpoints.
- Quietly refreshing the mirror without operator consent.
- Treating annotations as pass criteria.

---

## Skill 5.2 — `putative-drift-overlay`

### Purpose

Before (or while) implementing a feature, read **requirements and planned feature docs** and draft a **putative intentional drift overlay** so post-dev evaluation (5.1) can classify planned UI mutations as expected intentional rather than unexpected.

### When to use

- After requirements/plans are clear enough to name affected pages and UI surfaces.
- Before large visual refactors.
- When opening a workstream that will deliberately change headers, layouts, widgets, or copy.

### Inputs

| Input | Notes |
|-------|-------|
| Requirements / plan paths | Explicit list from the operator |
| Page registry | `tools/fuzzy-validator/manifests/pages.json5` |
| Current setpoint id | Pin `basedOnSetpoint` |
| Prior overlays | Avoid duplicate entries |

### Procedure

1. Inventory planned UI mutations per page / profile / layer from the docs.
2. Map each mutation to overlay entries (`class: intentional`, `source: putative`, `requirementRef` required).
3. Call out residual natural volatility the plan implies (weather, dates) as `class: natural` notes or entries if not already covered by setpoint fuzz.
4. Run `overlay validate` when CLI exists; otherwise schema-check manually against [02-tool-extension.md](./02-tool-extension.md).
5. Write draft under `tools/fuzzy-validator/overlays/putative/{workstream}.json5`.
6. Ask human to review; on approval, promote copy to `overlays/intentional/` (`source: promoted` or `manual`).

### Outputs

- Putative overlay file.
- Short mapping table: requirement ↔ page ↔ layer ↔ entry id.
- List of open questions (ambiguous UI not yet specced).

### Non-goals

- Implementing the feature.
- Marking asset byte changes intentional without explicit requirement language.
- Auto-promoting putative → intentional without human review.
- Using the overlay to pre-excuse unknown regressions.

---

## Agent evaluator (shared mini-skill / step)

Used by 5.1 (and optionally ad hoc):

1. Read `drifts.json` unexpected items + requirement docs + optional putative/intentional overlay.
2. For each unexpected drift, assess `reasonable | suspicious | unexplained` with citations.
3. Write `annotations.json` only.
4. Never edit `drifts.json`, overlays, baselines, or exit-code interpretation.

---

## Milestone checklist (build order)

### Tool (from [02-tool-extension.md](./02-tool-extension.md))

- [x] **FV2-0** Overlay schema + validate command + unit tests
- [x] **FV2-1** `validate --overlay` + `drifts.json` classification
- [x] **FV2-2** HTML sections + mechanical `reproduce.md`
- [x] **FV2-3** Mirror freshness helper (`--require-fresh-mirror`)
- [x] **FV2-4** Evidence suite intentional vs unexpected
- [x] **FV2-5** Annotation display-only in HTML report

### Skills

- [x] **SK-0** Update `skills/README.md` with when-to-use (finalize from version-2 README)
- [x] **SK-1** `run-setpoint-drift` — SKILL.md + orchestrator.prompt + checklist
- [x] **SK-2** `putative-drift-overlay` — SKILL.md + orchestrator.prompt + example overlay
- [x] **SK-3** Wire evaluator step into 5.1 prompt; document non-masking rules
- [ ] **SK-4** End-to-end dry run on one workstream (putative → implement → run-setpoint-drift)

### Docs close-out

- [x] Patch [../USER-GUIDE.md](../USER-GUIDE.md) / [../DEVELOPER-GUIDE.md](../DEVELOPER-GUIDE.md) once CLI ships
- [ ] Archive this plan’s completed milestone sections into `../archive/` when v2 ships (keep skills live)

---

## Acceptance scenarios

### A — Intentional header change

1. Putative overlay marks DOM path for new header (5.2).
2. Code lands; 5.1 validate with overlay → drift listed under **expected intentional**; exit 0 if no other diffs.
3. Same run without overlay → **unexpected**; exit 1; reproduction pack present.

### B — Natural weather only

1. Weather tile differs; covered by setpoint natural fuzz or natural overlay → expected natural; does not fail.
2. Unrelated nav regression → unexpected; fails; annotation may say “unrelated to weather REQ.”

### C — Stale mirror

1. `extracted_at` older than 7 days.
2. Skill 5.1 prompts and refuses to continue until refresh or documented override.

### D — Annotations cannot greenwash

1. Unexpected drift remains in `drifts.json` and HTML Unexpected section.
2. `annotations.json` says `reasonable`.
3. Exit code still `1`.
