# Phase 3.5 — Idiom Enforcement

**Status:** Complete — I-0 … I-VALIDATE finished with `status: ok`.
**Prerequisite satisfied:** VALIDATE-20 `status=ok` on the completed stack.
**Next work:** P3-4 manual smoke matrix, P3-5 retrospective, and optional P3-6 merge, after rebase onto current `origin/master`.
**Historical hop checklists and worker skill:** [archive/skills/idiom-enforcement/](./archive/skills/idiom-enforcement/)

Megiddo refactors moved logic out of `orkui/` without rewriting the frontend in a modern style. **Refactored code must smell like the surrounding 2008–2012 ORK3 code** — same layering habits, naming, JSON shapes, whitespace, and call patterns. Idiom enforcement is a **style-only** pass: no new features, no semantic changes, no gate regressions.

---

## Why this phase exists

DS-1 requires idiomatic ORK3 code, but R-01 … R-19d were optimized for **isolation** (`$DB`/`Ork3::$Lib` zero) not **uniformity**. Agents introduced mixed patterns:

| Anti-pattern (post-refactor) | Preferred idiom (legacy ORK3) |
|------------------------------|-------------------------------|
| `(new Model_Player())` inline in AJAX controller | `$this->load_model('Player');` then `$this->Player->…` |
| `new Player()` in controller | Model wrapper method on `Model_Player` |
| `new SearchService()` only in new `Model_Search` while sibling models use established wrapper style | Match `model.*.php` peers in same domain |
| Inconsistent JSON key casing (`status` vs `Status`) within a file | Match existing methods in **that file** |
| `[]` vs `array()` | Match **that file's** prevailing syntax |
| Tabs vs spaces | Match **that file** — never reformat whole file |

Idiom hops **do not** relax success criteria from [02-requirements.md](./02-requirements.md). Static isolation (`$DB`, `Ork3::$Lib`, DML) must remain zero.

---

## Pipeline (serialized)

| Hop | Maps to | Purpose |
|-----|---------|---------|
| **I-0** | — | Publish [idioms-00-charter.md](./idioms-00-charter.md): rules, reference files, per-hop file scopes |
| **I-01 … I-18** | R-01 … R-18 | Align files touched in each execution sprint |
| **I-19a … I-19d** | R-19a … R-19d | Align residual lib migration files (3 files per hop) |
| **I-VALIDATE** | — | Charter compliance audit + full test gates |

The completed orchestration materials are archived with the hop records.

---

## Completed deliverables

| ID | Owner | Artifact |
|----|-------|----------|
| **I-0** | Agent | `idioms-00-charter.md` — rules + hop scope table + grep lint commands |
| **I-01 … I-19d** | Agent | One commit per hop; idiom-only diffs on mapped files |
| **I-VALIDATE** | Agent | `idioms-validate-report.md`; checklist sign-off |
| **P3-4** | Human | Manual smoke matrix (after I-VALIDATE `status=ok`) |
| **P3-5** | Human | Retrospective |
| **P3-6** | Human (optional) | Merge stack → `megiddo/rebase-20260709` |

---

## Per-hop gates (historical)

Every idiom hop runs **at minimum**:

```bash
rg '\$DB->' orkui/                    # exit 1
rg 'Ork3::\$Lib' orkui/              # exit 1
sh bin/run-unit-tests.sh             # exit 0
```

Hop-specific fuzzy/Playwright gates reused the archived R-* / R-19 validation records when an idiom diff touched rendered surfaces or AJAX contracts.

**I-VALIDATE** runs full VALIDATE-20 gate set plus charter lint (see I-VALIDATE worker).

---

## Reference files (I-0 seeds)

Clean pre-refactor idioms to cite in the charter:

| Layer | Reference |
|-------|-----------|
| Controller (HTML) | `orkui/controller/controller.Recap.php` |
| Controller (AJAX) | `orkui/controller/controller.EventAjax.php` (post-R-01 paths using `load_model`) |
| Model wrapper | `orkui/model/model.Event.php`, `orkui/model/model.Weather.php` |
| Authorization | `orkui/controller/controller.KingdomAjax.php` (`setofficers`, `vacateofficer` — pre-R-19a style) |

---

## Phase 3 close-out order

```
VALIDATE-20 (ok) → I-0 → I-01 … I-18 → I-19a … I-19d → I-VALIDATE (ok)
  → rebase onto current origin/master
  → P3-4 manual smoke matrix
  → P3-5 retrospective
  → P3-6 optional merge
```
