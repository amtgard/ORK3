# Fuzzy Validator — Skills

Agent orchestration skills for the fuzzy validator (same pattern as `docs/megiddo/refactor/skills/`).

## When to use

| Skill | Folder | When |
|-------|--------|------|
| **putative-drift-overlay** (5.2) | [putative-drift-overlay/](./putative-drift-overlay/) | **During requirements/planning** (before or while implementing): draft intentional drift overlay so post-dev evaluation knows planned UI |
| **run-setpoint-drift** (5.1) | [run-setpoint-drift/](./run-setpoint-drift/) | **After (or mid) implementation**: gold-master setpoint vs current work → classified drift report on **test + mirror**; prompts if prod mirror is &gt; 7 days stale |

```text
  Requirements / feature plan
           │
           ▼
  [5.2] putative-drift-overlay     ← draft intentional overlay
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
           optional agent annotations (never mask FAIL)
```

**Non-masking:** Annotations never change exit codes or remove unexpected drifts from `drifts.json`.

Process overview: [../version-2/README.md](../version-2/README.md).  
Full skill specs: [../version-2/03-skills-and-milestones.md](../version-2/03-skills-and-milestones.md).

v1 FU-* agent prompts remain archived under [../archive/](../archive/).
