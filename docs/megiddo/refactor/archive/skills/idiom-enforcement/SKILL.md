---
name: idiom-enforcement
description: >-
  Serialized idiom alignment after VALIDATE-20 passes: I-0 charter, I-01…I-18 per R-* scope,
  I-19a…I-19d per residual-lib files, I-VALIDATE. Style-only — refactored code must match
  2008–2012 ORK3 conventions. Run before P3-4 manual smoke and P3-5 retrospective.
disable-model-invocation: true
---

# Megiddo — Idiom Enforcement (Phase 3.5)

Runs **after** VALIDATE-20 `status=ok` and **before** human P3-4/P3-5.

## When to use

- Automated success criteria pass but refactored code mixes modern agent idioms with legacy ORK3 style
- You want serialized sub-agents to normalize style **without** changing behavior or re-opening `$DB`/`Ork3::$Lib` isolation

## How to run

1. Confirm stack tip: `megiddo/p3-validate-20-audit` (or FIX-06 tip if validate branch not merged).
2. Open [prompts/03-idiom-enforcement-orchestrator.prompt](../../prompts/03-idiom-enforcement-orchestrator.prompt) → paste into a **new** agent chat.
3. When **I-VALIDATE** reports `status=ok`, proceed to P3-4 manual smoke matrix.

## Pipeline (serialized)

| Hop | Worker | Purpose |
|-----|--------|---------|
| 0 | [I-0.md](./workers/I-0.md) | Publish `idioms-00-charter.md` + per-hop file scopes |
| 1–18 | [I-01.md](./workers/I-01.md) … [I-18.md](./workers/I-18.md) | Idiom pass per R-01 … R-18 file scope |
| 19a–d | [I-19a.md](./workers/I-19a.md) … [I-19d.md](./workers/I-19d.md) | Idiom pass per R-19a … R-19d (3 files each) |
| final | [I-VALIDATE.md](./workers/I-VALIDATE.md) | Charter lint + full test gates |

## Hard constraints

- **No semantic changes** — PHPUnit + hop gates must stay green
- **No new `$DB` or `Ork3::$Lib`** — static grep must remain zero
- **Match file-local style** — tabs/spaces, `array()` vs `[]`, naming per charter
- **One commit per hop** (DS-6)

## Related

- [12-idiom-enforcement.md](../../12-idiom-enforcement.md) — canonical plan
- [05-development-steering.md](../../05-development-steering.md) DS-1
- [11-phase-3-closeout.md](../../11-phase-3-closeout.md) — P3-4/P3-5 after I-VALIDATE
