---
name: putative-drift-overlay
description: >-
  Draft a putative intentional drift overlay from requirements and feature plans
  so post-implementation run-setpoint-drift can classify planned UI mutations as
  expected intentional rather than unexpected. Writes overlays under
  tools/fuzzy-validator/overlays/putative/. Does not implement features or
  auto-promote overlays into intentional/ without human review. Use during
  requirements/planning or before large visual refactors.
disable-model-invocation: true
---

# Skill — putative-drift-overlay (5.2)

**Plan:** [../../version-2/03-skills-and-milestones.md](../../version-2/03-skills-and-milestones.md)  
**Schema:** [../../version-2/02-tool-extension.md](../../version-2/02-tool-extension.md) · example: `tools/fuzzy-validator/overlays/putative/example-workstream.json5`

## When to use

- Requirements/plans clear enough to name affected pages and UI surfaces
- Before large visual refactors
- Opening a workstream that will deliberately change headers, layouts, widgets, or copy

Run **before** implementation lands so later **run-setpoint-drift** is not surprised by planned UI.

## Hard rules

1. Every intentional entry needs `requirementRef` and `class: intentional`, `source: putative`.
2. Assets stay hard by default — only mark `layer: assets` when requirements explicitly call out CSS/JS byte changes.
3. Do not implement the feature in this skill.
4. Do not auto-promote `putative/` → `intentional/` without human review.
5. Overlay must pass `bin/fuzzy-validator overlay validate`.
6. Never rewrite setpoint zip / baselines.

## How to run

1. Open **[orchestrator.prompt](orchestrator.prompt)**.
2. Copy → paste into a new agent chat with the operator’s requirements/plan paths.

## Outputs

- `tools/fuzzy-validator/overlays/putative/{workstream}.json5`
- Mapping table: requirement ↔ page ↔ layer ↔ entry id
- Open questions for ambiguous UI
