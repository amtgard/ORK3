---
name: run-setpoint-drift
description: >-
  Compare the locked gold-master fuzzy setpoint against current work on both
  test (sandbox) and mirror (prod) profiles. Produce a classified drift report
  (expected natural / intentional vs unexpected) with mechanical reproduction
  packs. Prompt if the prod mirror is older than 7 days. Optional agent
  annotations never change exit codes. Use after (or mid) UI feature work,
  before promoting a new setpoint, or after rebase when UI may have drifted.
disable-model-invocation: true
---

# Skill — run-setpoint-drift (5.1)

**Plan:** [../../version-2/03-skills-and-milestones.md](../../version-2/03-skills-and-milestones.md)  
**Tool flags:** `validate --overlay` / `--require-fresh-mirror` (fuzzy-validator v2)

## When to use

- Feature implementation ready for UI stability review
- Mid-feature visual checkpoint
- Before promoting a new setpoint
- After rebase onto master when UI may have drifted

Prefer **putative-drift-overlay** earlier in the process so intentional UI is classified as expected.

## Hard rules

1. Never re-record baselines or publish setpoints in this skill.
2. Never refresh the prod mirror silently — prompt the operator when stale (> 7 days).
3. Always run **both** profiles: `test` and `mirror` (unless the operator explicitly scopes to one with a recorded reason).
4. Agent annotations (`annotations.json`) are **display-only** — never flip FAIL→PASS or edit `drifts.json`.
5. Exit code from `validate` is the source of truth (0 pass, 1 unexpected fail, 2 harness/schema/stale-mirror).

## How to run

1. Open **[orchestrator.prompt](orchestrator.prompt)**.
2. Copy → paste into a new agent chat.
3. Follow the checklist in [checklist.md](checklist.md).

## Outputs

- Gate exit code (honest)
- `tools/fuzzy-validator/reports/run-*/` with `index.html`, `drifts.json`, per-page `reproduce.md`
- Optional `annotations.json` (non-masking)
- Short operator summary: unexpected / expected intentional / expected natural counts, setpoint id, mirror age
