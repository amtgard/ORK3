# Rebase & Redocument — Milestone Checklist (Post-Refactor)

Track **RB-*** progress for the current post-refactor rebase.

**Skill:** [SKILL.md](SKILL.md) · [orchestrator.prompt](orchestrator.prompt) · [agent-prompt.md](agent-prompt.md) · [mutation-matrix.md](mutation-matrix.md) · [conflict-playbook.md](conflict-playbook.md)

---

## Run metadata (fill in RB-0)

| Field | Value |
|-------|-------|
| Date started | 2026-07-18 |
| Megiddo tip (pre-rebase) | `megiddo/rebase-20260718` @ `1979ae722f4869ccd0bea9a5d90205c2653f8222` |
| Base | `origin/master` @ `671c108b612b03616437bb88c7126f7c56ceb703` |
| Working branch | `megiddo/rebase-20260718` |
| **Sizing grade** | **L** |
| Sizing rationale | 35 upstream commits and 1,384 changed paths (73 under `orkui/`) span QualTest follow-ups, event schedule/embed, DataTables/revised frontend, reports UI, and broad tooling/test churn. Twelve merge-base overlaps include QualTest, Event, shared templates, and Player/Report domain surfaces; upstream also has new EventEmbed, QualTest templates, schedule embed, DataTables, and Whats New files. This meets the L large-module/template-overlap heuristic. |
| Session plan | **L:** RB-G alone; RB-1 alone; RB-2 alone; split RB-H by domain if needed (QualTest/Event, templates, Player/Report); RB-N alone; RB-F alone; RB-Z close. Do not collapse into one mega-session. |
| Overlap inventory | Recorded in RB-0 notes below. |
| WIP parked | None — tree was clean before RB-0 documentation update. |

---

## Phase A — Integrate

### RB-0: Preflight, reset, and size

**Branch:** preserve `megiddo/rebase-20260718` at the current Megiddo tip; do **not** rebase yet.

| Step | Status |
|------|--------|
| Reset this checklist for a new run (prior notes → Prior runs) | [x] |
| `git fetch`; record tip + `origin/master` SHAs | [x] |
| Working tree clean (or WIP parked) | [x] |
| Summarize `HEAD..origin/master` (commits + hot paths) | [x] |
| Overlap inventory: Megiddo∩upstream paths vs upstream-new | [x] |
| Assign sizing grade S/M/L + session plan | [x] |
| Confirm docker / `bin/ork-db` / `bin/fuzzy-validator` / `tools/infection/` | [x] |
| Next milestone named | [x] |
| Commit (optional): `RB-0: Size post-refactor Megiddo rebase` | [x] |

**RB-0 notes (2026-07-18):**

- Fetch complete. Tip: `megiddo/rebase-20260718` @ `1979ae722f4869ccd0bea9a5d90205c2653f8222`; base: `origin/master` @ `671c108b612b03616437bb88c7126f7c56ceb703`; merge-base: `7631d0baad65b573d4d53f115c84d20af09b046e`.
- `HEAD..origin/master`: **35 commits**, **1,384 files**. Hot paths: `orkui/` 73, `system/` 30, `db-migrations/` 1 (`2026-07-07-add-prod-canary.sql`), `tools/` 943, and `tests/` 111.
- Existing working branch already pointed to the required tip; no rebase was run. Docker **29.2.0**; `bin/ork-db`, `bin/fuzzy-validator`, and `tools/infection/` exist.

#### Overlap inventory (Megiddo ∩ upstream since merge-base)

| Path | Class |
|------|-------|
| `orkui/controller/controller.EraPhoenice.php` | overlap |
| `orkui/controller/controller.QualTest.php` | overlap |
| `orkui/controller/controller.QualTestAjax.php` | overlap |
| `orkui/model/model.Event.php` | overlap |
| `orkui/template/default/Reports_playerawardrecommendations.tpl` | overlap |
| `orkui/template/revised-frontend/{Event,Kingdom,Park,Player}new_index.tpl` | overlap |
| `system/lib/ork3/class.{Player,Report}.php` | overlap |
| `system/lib/system/class.Controller.php` | overlap |

#### Upstream-new `orkui/` modules/files (RB-1 take → RB-N scan)

| Path | Class |
|------|-------|
| `controller/controller.EventEmbed.php` | upstream-new |
| `template/default/QualTest_{question,questions,take}.tpl` | upstream-new |
| `template/default/Reports_release_utilization.tpl` | upstream-new |
| `template/embed/{demo.html,ork-schedule.js}` | upstream-new |
| `template/revised-frontend/Kingdomnew_recommendations_panel.tpl` | upstream-new |
| `template/revised-frontend/{script/revised.js,style/ork-datatables.css,style/revised.css}` | upstream-new |
| `whats_new_content.php` | upstream-new |

**Next:** **RB-G** — capture the gold UI setpoint from unrebased `origin/master` @ `671c108b612b03616437bb88c7126f7c56ceb703`.

---

### RB-G: Gold UI setpoint (from unrebased mainline)
| Step | Status |
|------|--------|
| Checkout base SHA in a clean tree; deploy sandbox and run E2E preflight | [x] |
| Capture and publish test + mirror setpoint | [ ] |
| Return to `megiddo/rebase-*`; record bundle id and commit | [x] |

**RB-G notes (2026-07-18):**

- Gold product source: detached `671c108b612b03616437bb88c7126f7c56ceb703`
  (`origin/master`). This base predates the validator tooling, so the current
  harness served that detached worktree through Docker while performing the
  sandbox deploy, authenticated test/mirror preflight, and capture.
- Both authenticated E2E profile checks passed. The captured setpoint contains
  20 registry pages for `test,mirror`.
- Gold bundle:
  `20260718T230634Z-671c108b-b16eae2472f1daa9.zip` (copied unchanged to
  `tools/fuzzy-validator/setpoints/bootstrap/` and committed there;
  `setpoint.json` points here).
- `bin/fuzzy-validator setpoint publish` completed locally, but the external
  Google Drive upload remains a manual maintainer step because no
  configured Drive upload client or synced `ORK3 Fuzzy Setpoints` destination
  is available on this machine. The publish step remains open until the bundle
  is uploaded unchanged before RB-F.

### RB-1: Rebase with spirit-preserving merges
| Step | Status |
|------|--------|
| Rebase onto base; preserve Megiddo layering and upstream behavior | [x] |
| Take upstream-new files, keep migrations, record conflicts, and commit | [x] |

**RB-1 sign-off (2026-07-18):**

- Rebase is clean: `671c108b612b03616437bb88c7126f7c56ceb703` is an
  ancestor of `HEAD` (`b927109575c6fcb7af9c9ad1cabca91fc3e7378d`) on
  `megiddo/rebase-20260718`.
- Conflict resolution retained the code, tooling, and documentation replays;
  baseline-only replays were dropped. Upstream-new files and migrations were
  retained for later RB-N/RB-H review.
- Existing sign-off commit
  `75a3d4f84a42c1b19c6be6687bdf4cf9e850913b`
  (`RB-1: Rebase Megiddo onto master (spirit merge)`) is an ancestor and is
  the single RB-1 sign-off; no duplicate commit was created.
- `git diff 671c108b..75a3d4f8 -- orkui/` adds no `$DB` or
  `Ork3::$Lib` references. `controller.EventEmbed.php` retains its pre-existing
  base `$DB` use and remains RB-N scope; no rebase-introduced reference was
  found.

## Phase B — Global tests
### RB-2: Full suite green
| Step | Status |
|------|--------|
| Deploy sandbox, E2E preflight, full PHPUnit, critical smoke, and commit | [x] |

**RB-2 notes (2026-07-18):**

- `docker compose -f docker-compose.php8.yml up -d` — app + mirror + sandbox running.
- `bin/ork-db deploy-sandbox` — PASS (no migration/schema drift); credentials seeded.
- `sh bin/run-unit-tests.sh` — **OK**: 250 tests, 859 assertions, 2 skipped, exit 0.
  Drift-check strict PASS. No fixture or coverage repairs required post-rebase.
- E2E preflight (06-test-framework):
  - Mirror (`admin`/`password`): health + auth home login PASS; full mirror suite
    `npx playwright test tests/e2e/ --grep-invert heraldry` → 44 passed, 6 skipped, exit 0.
  - Sandbox (`megiddo`/`test-db-player`): auth home login PASS.
- Deferred to RB-H / RB-N: none from this milestone (suite already green; hotspot and
  upstream-new spirit work remain RB-H / RB-N scope).
- Next: **RB-H**.

## Phase C — Hotspots and new-code spirit
### RB-H: Overlap hotspots
| Step | Status |
|------|--------|
| Verify thin layers, upstream behavior, tests, and Infection for every overlap | [ ] |
| Confirm no `$DB->` / `Ork3::$Lib` on overlap paths; commit | [ ] |

### RB-N: New upstream code — spirit of the refactor
| Step | Status |
|------|--------|
| Inventory and scan upstream-new `orkui/` code | [ ] |
| Move violations behind `system/lib/ork3/` / `Model_*`; add characterization tests | [ ] |
| Confirm static gates and PHPUnit; commit | [ ] |

## Phase D — Fuzzy
### RB-F: Validate rebased tip against gold
| Step | Status |
|------|--------|
| Restore RB-G gold bundle; preflight and validate test + mirror; commit | [ ] |

## Phase E — Close
### RB-Z: Sign-off
| Step | Status |
|------|--------|
| Re-run PHPUnit/static/coverage/fuzzy checks; update Last rebase docs; commit | [ ] |

---

## Prior runs

### 2026-07-17/18 — post-refactor rebase (complete)

| Field | Value |
|-------|-------|
| Megiddo tip (pre-rebase) | `megiddo/p3-fix-10-sandbox-fuzzy-rebaseline` @ `e1d993976f646c9d75ea13c96e99b26aa10939b4` |
| Base | `origin/master` @ `7631d0baad65b573d4d53f115c84d20af09b046e` |
| Working branch | `megiddo/rebase-20260717` |
| Sizing grade | **L** |
| Outcome | RB-0…RB-Z complete; handed off to P3-4. |

Notes preserved: RB-0 found 26 upstream commits and QualTest / Player / Kingdom / Reports / template overlap. RB-G published `20260718T030809Z-7631d0ba-fd37ea34a9523a28.zip` from unrebased mainline. RB-1 retained thin layering and migrations; RB-H thinned QualTest through `Model_QualTest`; RB-N cleared `orkui/` `$DB` and `Ork3::$Lib` gates with characterization coverage. RB-F passed test + mirror 40/40 against gold; RB-Z passed 250 tests / 859 assertions and listed the QualTest full-class Infection gap.

### 2026-07-09 — pre-execution / tooling-era rebase (complete)

| Field | Value |
|-------|-------|
| Megiddo tip (pre-rebase) | `megiddo/v-14-lib-service-validation` @ `ad878395` |
| Base | `origin/master` @ `e6417645` |
| Working branch | `megiddo/rebase-20260709` |
| Sizing grade | S |
| Outcome | RB-0…RB-Z complete; fuzzy setpoint `20260709T173049Z-1591950d-6b22e991bb478256.zip` |

This run used the pre-R-* playbook. **Do not reuse those conflict rules** for post-refactor rebases.
