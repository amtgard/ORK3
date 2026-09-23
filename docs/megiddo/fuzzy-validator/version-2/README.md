# Fuzzy Validator — Version 2

**Status:** Implemented (tool + skills)  
**Audience:** Humans planning ORK3 UI work; agents building tool extensions and orchestration skills  
**Depends on:** v1 shipped (FU-0 … FU-16) — see [../USER-GUIDE.md](../USER-GUIDE.md)

---

## Problem

v1 treats all calibrated fuzz the same: a zone or DOM node is either allowed or it is a failure. That works for **natural drift** (list ordering, relative dates, weather widgets) learned on a stable commit.

When we plan product/UI mutations for ORK3, we also have **intentional drift** — expected visual and DOM changes that must not be confused with regressions, and must not be silently folded into the gold-master setpoint.

We need:

1. A **locked setpoint** (amber / gold master) that does not absorb development mutations.
2. **Classified drift overlays** (natural vs intentional) applied *on top of* that setpoint at evaluation time.
3. Reports that list **expected** and **unexpected** drifts by class — and never hide failures behind agent opinion.
4. Agent skills that **run** drift reports and **draft** putative overlays from requirements — at the right moments in the development process.

---

## Documents in this folder

| Doc | Purpose |
|-----|---------|
| **[README.md](./README.md)** (this file) | Overview, process placement, skill usage guide |
| **[01-requirements.md](./01-requirements.md)** | Product requirements for overlays, reporting, reproduction, agent evaluator |
| **[02-tool-extension.md](./02-tool-extension.md)** | Proposed `tools/fuzzy-validator` changes (schema, CLI, report) |
| **[03-skills-and-milestones.md](./03-skills-and-milestones.md)** | Two orchestration skills, milestones, acceptance criteria |

Implemented skill trees land under [../skills/](../skills/) when build work starts — not here.

---

## Core model

```text
┌─────────────────────────────────────────────────────────┐
│  SETPOINT (locked amber / gold master)                  │
│  baselines + calibrated natural fuzz from stable SHA    │
│  Never rewritten by intentional development overlays    │
└──────────────────────────▲──────────────────────────────┘
                           │ apply at evaluate time
         ┌─────────────────┴─────────────────┐
         │  DRIFT OVERLAY (versioned file)   │
         │  · natural  — expected volatility │
         │  · intentional — planned UI mutate│
         └─────────────────┬─────────────────┘
                           ▼
┌─────────────────────────────────────────────────────────┐
│  VALIDATE + CLASSIFY                                    │
│  expected natural | expected intentional | unexpected   │
│  + mechanical reproduction steps + screenshots          │
│  + optional agent annotations (never change exit code)  │
└─────────────────────────────────────────────────────────┘
```

**Invariant:** Agent assessment may annotate a failure as “reasonable given requirements.” It must **not** flip FAIL→PASS, remove a drift from the unexpected list, or rewrite the setpoint.

---

## Two orchestration skills (when to use)

| Skill | Folder | When to use |
|-------|--------|-------------|
| **`run-setpoint-drift`** | `skills/run-setpoint-drift/` | After (or mid) implementation: gold-master setpoint vs current work → classified drift report on **test + mirror**; prompts if prod mirror is &gt; 7 days stale |
| **`putative-drift-overlay`** | `skills/putative-drift-overlay/` | During requirements/planning: draft intentional drift overlay for post-dev evaluation |

### Development process placement

```text
  Requirements / feature plan
           │
           ▼
  [5.2] putative-drift-overlay     ← draft intentional overlay from docs
           │
           ▼
  Implement on working branch
           │
           ▼
  [5.1] run-setpoint-drift         ← master setpoint vs work; test + mirror
           │
           ├── expected (natural + intentional) → informational
           └── unexpected → FAIL + reproduction pack
                    │
                    ▼
           agent evaluator annotations on the test plan
           (optional; never masks FAIL)
           │
           ▼
  Human review → promote new setpoint only when intentional
  UI is accepted as the new gold master (separate maintainer step)
```

Use **5.2 early** so post-dev validate does not surprise the team with known planned UI changes.  
Use **5.1 late** (and whenever you need a fresh drift report) as the mechanical gate.

### Skill 5.1 extras (required)

Before continuing a dual-profile run:

1. Check whether the **prod mirror database** is more than **one week** stale.
2. If stale, **prompt the operator** for a new mirror import / refresh; do not silently continue.
3. Always take setpoints / validate against both **prod mirror** and **sandbox** via `bin/ork-db` (`use prod` / `use dev` as wired by fuzzy-validator profiles).

Freshness signal (initial proposal): `tools/ork-db/extracted/manifest.json` → `extracted_at`, or an equivalent ork-db status field if one is added. Exact probe is specified in [03-skills-and-milestones.md](./03-skills-and-milestones.md).

---

## Non-goals (v2)

- Replacing Playwright behavior e2e or PHPUnit.
- Auto-promoting overlays into the setpoint without human review.
- Softening asset (CSS/JS) hard gates via “intentional drift” without an explicit, reviewed overlay entry (assets remain hard by default).
- Letting agent prose change exit codes or omit unexpected drifts from machine-readable output.

---

## Relationship to v1

| v1 concept | v2 extension |
|------------|--------------|
| `*.fuzz.json` / `*.dom-fuzz.json` | Remain the **natural** baseline allowances baked with the setpoint |
| Undifferentiated green allow / red fail | Split into **expected** (by class) vs **unexpected** |
| HTML report with baseline / candidate / diff | Same evidence, plus classified drift list + reproduction steps |
| Dual profiles test + mirror | Unchanged; skills always exercise both |
| `setpoint restore` / capture / publish | Setpoint stays locked; overlays are separate artifacts |

---

## Implementation status

Tool FV2-* and skills SK-0…SK-3 are implemented under `tools/fuzzy-validator/` and [../skills/](../skills/). Track remaining dry-run acceptance (SK-4) via [03-skills-and-milestones.md](./03-skills-and-milestones.md).
