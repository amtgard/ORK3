# Megiddo Refactor

Migrate business logic and `$DB` / `Ork3::$Lib` access out of `orkui/` into `system/lib/ork3/` and `orkservice/*`.

**Status:** Implementation, automated Phase 3 audit, gate fixes, idiom enforcement, and the post-refactor rebase are complete. Human Phase 3 close-out and a Player first-class API residual remain.
**Current tip:** `megiddo/fuzzy-validator-v2` (see [PR #492](https://github.com/amtgard/ORK3/pull/492)).
**Remaining:** P3-4 manual smoke, P3-5 retrospective, optional P3-6 merge, and **P3-R\*** Player aggregate APIs ([11-phase-3-closeout.md](./11-phase-3-closeout.md)).

**Last rebase:** 2026-07-18 onto `origin/master` @ `7631d0baad65b573d4d53f115c84d20af09b046e`. Future upstream rebases: [skills/rebase-and-redocument/orchestrator.prompt](./skills/rebase-and-redocument/orchestrator.prompt).

## Approach

The completed refactor followed staged discovery, test development, validation artifacts, serialized R-* migrations, automated Phase 3 audit and gate fixes, then idiom enforcement. The architecture, target map, steering rules, test framework, Phase 3 close-out plan, and idiom charter remain active as reference material.

| Read | Purpose |
|------|---------|
| [01-code-decomposition.md](./01-code-decomposition.md) | Architecture and layering intent |
| [02-requirements.md](./02-requirements.md) | Scope and success criteria |
| [03-implementation-plan.md](./03-implementation-plan.md) | Map of migrated targets |
| [05-development-steering.md](./05-development-steering.md) | Development steering rules |
| [06-test-framework.md](./06-test-framework.md) | Test and validation conventions |
| [11-phase-3-closeout.md](./11-phase-3-closeout.md) | Human close-out + Player API residual (P3-R) |
| [12-idiom-enforcement.md](./12-idiom-enforcement.md) | Completed idiom-enforcement approach |
| [idioms-00-charter.md](./idioms-00-charter.md) | Idiom rules and reference patterns |

## Remaining work

- **P3-4:** Walk [the manual smoke matrix](./validations/r-milestone-smoke-matrix.html) and record the human results.
- **P3-5:** Record the close-out retrospective.
- **P3-6 (optional):** Merge after the rebase and human close-out are accepted.
- **P3-R\*:** Player first-class APIs for class levels, milestones, award maps, and reconcile suggestions (see [11-phase-3-closeout.md](./11-phase-3-closeout.md)). Bootstrap Lib / Dangeraudit / index Health·Event model hop is done.

The concise [remaining-work checklist](./04-milestone-checklist.md) tracks these items. [validations/README.md](./validations/README.md) points to the retained smoke matrix and archived validation history.

## Archive

Completed operational plans, discovery notes, validations, execution skills, prompts, reports, and the full historical checklist are indexed in [archive/README.md](./archive/README.md).
