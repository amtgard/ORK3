# Version 2 — Requirements

**Status:** Implemented (see milestone checklist in 03)  
**Parent:** [README.md](./README.md)

---

## R1 — Locked setpoint (amber / gold master)

1. The published setpoint bundle remains the **canonical visual/DOM/asset baseline** for a known-good SHA.
2. Intentional product UI changes **must not** be written into the setpoint as part of ordinary validate/record during feature work.
3. Natural calibration fuzz that ships *with* the setpoint continues to describe volatility observed on that locked SHA (dates, weather, list shuffle, session chrome, etc.).
4. Promoting a new gold master remains an **explicit maintainer action** (`setpoint capture` / `publish` / Drive upload) after intentional UI is accepted — not a side effect of overlay authoring or agent evaluation.

---

## R2 — Classified drift overlays

Overlays are versioned artifacts applied **at evaluation time** on top of a restored setpoint.

### Classes

| Class | Meaning | Typical source |
|-------|---------|----------------|
| **natural** | Volatility expected without product change | Calibration learning; known volatile widgets; mirror data churn patterns |
| **intentional** | Drift expected because of planned development mutation | Requirements / feature plans; putative overlay skill (5.2) |

### Overlay properties

1. Overlay entries reference **page id**, **profile** (`test` | `mirror` | both), **layer** (`dom` | `visual` | optionally `assets`), and a **selector or bbox** (same coordinate / path conventions as v1 manifests).
2. Each entry carries:
   - `class`: `natural` | `intentional`
   - `rationale` (human-readable)
   - `requirementRef` (optional URI/path into requirements or plan docs — required for intentional)
   - `source`: `calibrated` | `manual` | `putative` | `promoted`
3. Multiple overlays may stack (e.g. shared natural pack + feature-specific intentional pack). Merge rules: union of allowances; conflicts (same region with different classes) must fail closed and surface in the report.
4. Overlays **never** mutate files inside the setpoint zip or committed baselines.

### Distinction from v1 fuzz files

| Artifact | Role |
|----------|------|
| `manifests/{profile}/*.fuzz.json` / `*.dom-fuzz.json` | Locked with setpoint — primary natural allowances for that gold master |
| Drift overlay files (new) | Additive classified allowances for a workstream; may refine natural *or* declare intentional |

v2 may eventually tag existing fuzz zones with `class: natural` for reporting clarity without changing gate math.

---

## R3 — Evaluation output: expected vs unexpected

On every validate-with-overlay run, the tool must emit:

1. **Machine-readable drift inventory** (JSON) listing each detected diff region/path with:
   - `status`: `expected` | `unexpected`
   - `class`: `natural` | `intentional` | `none` (unexpected has no matching overlay class)
   - page, profile, layer, location, scores contribution
2. **Human HTML report** sections:
   - Expected drifts (grouped by class)
   - Unexpected drifts (failures)
3. **Exit code semantics unchanged in spirit:**
   - Any **unexpected** drift that fails thresholds → exit `1`
   - Expected drifts (covered by natural or intentional overlay) do **not** reduce pass score for that surface
   - Harness errors → exit `2`
4. Expected drifts remain **visible** in the report (informational), never elided.

---

## R4 — Mechanical reproduction steps

For each **unexpected** (and optionally each expected) drift, the validator must generate a **reproduction pack** suitable for a human or agent:

1. Ordered checklist of shell / UI steps (restore setpoint, select profiles via ork-db, open URL, viewport, auth).
2. Pointers to **baseline** and **candidate** screenshots (and DOM snippets where relevant) — same evidence already produced under `reports/run-*/`.
3. Diff / overlay imagery already used in v1 reports.
4. Stable identifiers so an agent can cite `driftId` when annotating a test plan.

Reproduction text should be derived mechanically from page registry + profile config + capture parameters — not free-form LLM inventing steps.

---

## R5 — Agent evaluator (annotations only)

After a drift report exists:

1. An agent may evaluate **unexpected** drifts against the feature requirements / plan.
2. Output is **annotations** attached to the test plan or a sibling annotation file (e.g. `reasonable | suspicious | out-of-scope`, rationale, requirement cites).
3. **Hard rules:**
   - Annotations **must not** change exit codes.
   - Annotations **must not** remove or reclassify drifts in the machine-readable inventory produced by the validator.
   - Annotations **must not** be used as an excuse to skip fixing or to auto-merge.
4. The HTML / JSON gate output remains the source of truth for pass/fail.

---

## R6 — Orchestration skill: run setpoint drift (5.1)

See [03-skills-and-milestones.md](./03-skills-and-milestones.md) for full skill spec. Requirements summary:

1. Compare gold-master setpoint (from `origin/master` or published latest) against **current worktree**.
2. Run automatically across configured pages for **both** `test` (sandbox) and `mirror` (prod mirror) via ork-db.
3. Before continuing: if prod mirror is **> 7 days** stale, **prompt** for a new mirror and wait for operator confirmation / refresh — do not proceed silently.
4. Produce the classified drift report (R3) + reproduction packs (R4).
5. Optionally invoke R5 annotator afterward without altering gate results.

---

## R7 — Orchestration skill: putative drift overlay (5.2)

1. Consume requirements + planned feature docs.
2. Produce a **putative** intentional overlay (and notes for natural regions that plans imply will remain volatile).
3. Overlay is draft (`source: putative`) until a human promotes it.
4. Used so that post-implementation `run-setpoint-drift` can classify planned UI as expected intentional rather than unexpected — still never hides residual unexpected drift.

---

## R8 — Documentation

1. This `version-2/` plan package.
2. A skills README (human) describing **when** to run 5.1 vs 5.2 in the development process (seeded in [README.md](./README.md); finalized under `../skills/` when skills ship).
3. Updates to USER-GUIDE / DEVELOPER-GUIDE only when tool behavior ships (not before).

---

## Acceptance (product-level)

v2 is “done” when:

- [x] Overlay schema + CLI flags exist and are covered by unit tests.
- [x] Validate classifies drifts; unexpected still fails the gate.
- [x] Report includes expected/unexpected sections and per-drift reproduction steps with baseline/candidate screenshots.
- [x] Agent annotator path is documented and proven not to alter exit codes.
- [x] Skills 5.1 and 5.2 exist under `docs/megiddo/fuzzy-validator/skills/` with SKILL.md + prompts.
- [x] Mirror staleness prompt (≥ 7 days) is enforced in skill 5.1.
- [x] Dual-profile (test + mirror) runs are the default for both skills.
