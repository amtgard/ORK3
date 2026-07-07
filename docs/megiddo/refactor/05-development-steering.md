# Megiddo Refactor — Development Steering

Mandatory rules for all Megiddo refactor work (Phase 0 through Phase 3). Every milestone sign-off must satisfy this checklist in addition to milestone-specific exit criteria.

---

## DS-1: Idiomatic ORK3 code

All code changes must follow existing ORK3 conventions:

- Match patterns in the file and module being edited (naming, layering, error handling, request/response shapes).
- Domain logic in `system/lib/ork3/`; API surface in `orkservice/*` (`*Service.php`, `*.function.php`, `*.definitions.php`, `*.registration.php`).
- Frontend consumes services via `APIModel` / `JSONModel` and thin `Model_*` wrappers — not direct `$DB` or `Ork3::$Lib`.
- Reuse existing helpers (`valid_id`, `DB_PREFIX`, yapo ORM, `Ork3::$Lib` loader) rather than introducing parallel abstractions.
- No drive-by refactors: change only what the active milestone requires.

When in doubt, read a clean reference in the same domain (e.g. `Controller_Recap`, `CalendarItemAjax`) before adding new code.

---

## DS-2: PHP 8.2+

- All new and modified code must be valid on **PHP 8.2 or later**.
- Do not use syntax or extensions removed or deprecated in 8.2+ without an explicit exception documented in the milestone design note.
- Test and mutation runs use the project's PHP 8.2+ runtime (local and CI).

---

## DS-3: Branch per milestone

All work happens on a branch named for the **milestone currently being executed**.

| Milestone type | Branch pattern | Example |
|----------------|----------------|---------|
| Foundation | `megiddo/m0.{n}-{slug}` | `megiddo/m0.1-test-framework` |
| Discovery | `megiddo/ds-{nn}-{slug}` | `megiddo/ds-01-rsvp-discovery` |
| Test development | `megiddo/t-{nn}-{slug}` | `megiddo/t-01-rsvp-tests` |
| Execution | `megiddo/r-{nn}-{slug}` | `megiddo/r-01-rsvp-refactor` |

- One active milestone per branch.
- Do not combine milestones on a single branch.
- Branch from the agreed integration branch (typically `main` or the current Megiddo integration line).

---

## DS-4: Full unit test suite before sign-off

**The entire unit test suite must pass before milestone sign-off.** Partial runs are not acceptable for sign-off.

- Run the documented full-suite command (see M0.1 output: `06-test-framework.md` or equivalent).
- Do not use `--filter`, path-scoped PHPUnit flags, or subset scripts when validating sign-off.
- Fix or revert until the full suite is green.

Partial unit test runs are permitted **only during active development** for fast feedback. They must never be the basis for sign-off, commit, or merge.

---

## DS-5: Unit tests pass before commit

No commit on a milestone branch unless:

1. The **full unit test suite** passes (same rule as DS-4).
2. All milestone-specific checklist items are complete.

If a commit is needed mid-milestone for backup, use a local-only WIP approach; the **single published commit** (DS-6) must still meet DS-4 and DS-5.

---

## DS-6: One commit per branch

Each milestone branch must contain **exactly one commit** when closed.

- Squash intermediate commits before sign-off / merge / PR close.
- Interactive rebase and squash are acceptable.
- The resulting commit is the sole record of the milestone on that branch.

---

## DS-7: Milestone mutation tests must pass

Mutation testing uses **[Infection](https://infection.github.io/)** (see M0.1).

Before sign-off:

- Run Infection scoped to code **relevant to the active milestone** (paths/filters documented in M0.1).
- All mutants in that scope must be **killed** (or explicitly accepted with a documented justification in the milestone design note — exceptions require team review).
- Meet configured `minMsi` / `minCoveredMsi` thresholds for the scoped run.

Mutation scope may be narrower than the full suite; **unit tests may not** (DS-4).

For M0.1 itself: establish Infection and verify it runs on a pilot scope (e.g. one existing `*Service.test.php` target).

---

## DS-8: Commit title and message match the branch

The single commit on a milestone branch must clearly describe that milestone's work.

**Title:** Imperative, milestone-identified, matches branch intent.

```
M0.1: Add unified test bootstrap and Infection mutation testing
```

**Body:** What changed, why, and which milestone checklist items it closes. Reference target IDs when applicable (e.g. `T-RSV-*`).

```
Add shared test bootstrap for orkservice/* tests.
Configure Infection for system/lib/ork3 and orkservice.
Document full-suite and milestone-scoped mutation commands.

Closes M0.1 checklist items: bootstrap, CLI entry, mutation framework.
```

Branch name, commit title, and commit body must refer to the **same milestone** — no unrelated changes.

---

## Sign-off gate (every milestone)

Before marking a milestone complete in [04-milestone-checklist.md](./04-milestone-checklist.md):

| # | Gate | Rule |
|---|------|------|
| 1 | Idiomatic ORK3 | DS-1 |
| 2 | PHP 8.2+ | DS-2 |
| 3 | Branch name matches milestone | DS-3 |
| 4 | Full unit test suite green | DS-4 |
| 5 | Full unit test suite green at commit time | DS-5 |
| 6 | Exactly one commit on branch | DS-6 |
| 7 | Milestone-scoped mutation tests pass | DS-7 |
| 8 | Commit title/body match branch + milestone | DS-8 |
| 9 | E2E login preflight (when applicable) | [06-test-framework.md § preflight](./06-test-framework.md#e2e-login-credentials-preflight) — T-* / R-* with auth-gated Playwright or fuzzy-validator; no `class.Authorization.php` bypass |

---

## Related documents

| Doc | Purpose |
|-----|---------|
| [04-milestone-checklist.md](./04-milestone-checklist.md) | Milestone tracking |
| [02-requirements.md](./02-requirements.md) | Functional and non-functional requirements |
| M0.1 output (`06-test-framework.md`, when published) | Commands for full suite and Infection |
