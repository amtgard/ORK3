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
| Capture and publish test + mirror setpoint | [x] |
| Return to `megiddo/rebase-*`; record bundle id and commit | [x] |

**RB-G notes (2026-07-19) — mirror-data refresh:**

- Reason: prior gold `20260719T042339Z-671c108b-f95bafa0edb03f53.zip` was
  stale vs current prod/mirror DB (+ CDN). Recaptured from unrebased master
  with current mirror data; did **not** tip-rebaseline.
- Gold product source: unrebased `671c108b612b03616437bb88c7126f7c56ceb703`
  via worktree `/tmp/ork3-gold-671c108b` + tip harness overlay (Docker mount
  override). Base `config.dev.php` hardcodes mirror DB; tip `config.dev.php`
  (ORK3_DB_PROFILE switching) was overlaid so test/mirror profiles work.
- E2E preflight PASS: mirror `admin`/`password`; sandbox `megiddo`/`test-db-player`.
- Capture: sequential `record --profile test|mirror --all --phase all` with
  explicit `docker restart ork3-php8-app` after each `ork-db use` so PHP-FPM
  reloads the DB profile.
- Integrity gate PASS:
  - 20 registry pages × test+mirror; bundle gitSha `671c108b`
  - test home: Ashkara/Litavia present; Blackspire/Wetlands absent
  - mirror home: Blackspire/Wetlands/Desert Winds present; Ashkara/Litavia absent
  - PNG uniqueness: test 12/20 unique (largest dup group 5); mirror 11/20
    unique (largest dup group 9).
- Weather `manualNodes` (forecast freshness copy from `10dcbd79`) re-applied
  after record wiped them.
- Gold bundle (local publish):
  `20260719T124448Z-671c108b-149b83ec1220a285.zip` in
  `tools/fuzzy-validator/setpoints/bootstrap/` + `setpoints/out/`;
  `setpoint.json` `latestBundle` updated. Supersedes
  `20260719T042339Z-671c108b-f95bafa0edb03f53.zip`.
- Google Drive upload: optional/manual maintainer step (non-blocking).
- Tip restored to `megiddo/rebase-20260718`; Docker remounted tip product.
- Harness: `defaults.clockDate` / capture fallback set to `2026-07-19` so tip
  DEV clock pin matches gold (master lacks `X-ORK3-Clock-Date` support).
- Next: **RB-F**.

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
| Verify thin layers, upstream behavior, tests, and Infection for every overlap | [x] |
| Confirm no `$DB->` / `Ork3::$Lib` on overlap paths; commit | [x] |

**RB-H notes (2026-07-18):**

Per-hotspot (thin / upstream behavior / domain tests / Infection):

| Path | Thin | Upstream behavior | Tests | Infection |
|------|------|-------------------|-------|-----------|
| `orkui/controller/controller.EraPhoenice.php` | ok | ok (logic in `class.EraPhoenice`) | ok — expanded `EraPhoeniceTest` | **ok** `t14-lib-auth-era` + `class.EraPhoenice.php` MSI **82%** / covered **82%** (also fixed `configDir` → `../..`) |
| `orkui/controller/controller.QualTest.php` | ok | ok (endpoints via `Model_QualTest`) | ok `QualTestScoreTest` | **gap** `t-qualtest` MSI **1%** / covered **69%** (full-class uncovered surface; floor not lowered) |
| `orkui/controller/controller.QualTestAjax.php` | ok | ok (same public AJAX surface; thinned) | ok | same QualTest gate gap |
| `orkui/model/model.Event.php` | ok | ok (Megiddo API supersedes base) | ok Event* suite | **gap** `t05-event` MSI **11%** / covered **39%** |
| `orkui/template/default/Reports_playerawardrecommendations.tpl` | ok | ok (`$CanDeleteRecommendation` flag) | n/a template | n/a — Report domain gate below |
| `orkui/template/revised-frontend/{Event,Kingdom,Park,Player}new_index.tpl` | ok | ok (UX kept; auth/weather via flags/`wx_*`) | n/a template | n/a |
| `system/lib/ork3/class.Player.php` | n/a domain | ok (Megiddo + upstream growth) | ok Player* suite | **gap** `t09-player` MSI **2%** / covered **27%** |
| `system/lib/ork3/class.Report.php` | n/a domain | ok | ok Report* suite | **gap** `t10-reports` MSI **6%** / covered **48%** |
| `system/lib/system/class.Controller.php` | n/a system | ok (`load_model` / view surface) | covered indirectly | no scoped Controllers Infection config |

- Overlap `orkui/` paths: `rg '$DB->'` / `rg 'Ork3::$Lib'` **CLEAN**.
- Full PHPUnit after Era test expansion: **256 tests / 865 assertions**, exit 0 (drift-check strict PASS).
- Infection floors were **not** lowered. Event / Player / Report / QualTest overall MSI remain below 15% due to large uncovered class surface after rebase; covered-MSI stays healthy where measured. Same QualTest full-class gap pattern as prior run.
- Next: **RB-N**.

### RB-N: New upstream code — spirit of the refactor
| Step | Status |
|------|--------|
| Inventory and scan upstream-new `orkui/` code | [x] |
| Move violations behind `system/lib/ork3/` / `Model_*`; add characterization tests | [x] |
| Confirm static gates and PHPUnit; commit | [x] |

**RB-N notes (2026-07-18):**

Upstream-new inventory + scan:

| Path | Violations | Action |
|------|------------|--------|
| `controller/controller.EventEmbed.php` | `$DB` published-event + occurrence SQL (pre-existing on base) | Migrated to `class.EventEmbed` + `Model_Event` |
| `template/default/QualTest_{question,questions,take}.tpl` | none (presentation / precomputed flags) | no change |
| `template/default/Reports_release_utilization.tpl` | none | no change |
| `template/embed/{demo.html,ork-schedule.js}` | none (JS client) | no change |
| `template/revised-frontend/Kingdomnew_recommendations_panel.tpl` | none (auth flags) | no change |
| `template/revised-frontend/{script,style}/*` | none | no change |
| `whats_new_content.php` | none | no change |

Migrations:

- New domain `system/lib/ork3/class.EventEmbed.php` (`GetPublishedScheduleEmbed`) — published-only gate, strict detail ownership (no fall-through), 7-day default occurrence, schedule via `EventPlanning::GetSchedule`.
- Exposed as `Event.GetPublishedScheduleEmbed` (`orkservice/Event/*`).
- `Model_Event::get_published_schedule_embed`; `Controller_EventEmbed` thinned to model + presentational day grouping.
- Characterization: `tests/Unit/EventEmbedTest.php` (6 cases).
- Infection: `tools/infection/infection.t-event-embed.json5` — MSI **70%** / covered **74%** (floors 15%, not lowered).

Static gates: `rg '$DB->' orkui/` and `rg 'Ork3::$Lib' orkui/` **CLEAN**; no raw SQL under `orkui/`.

PHPUnit: **262 tests / 882 assertions**, exit 0 (drift-check strict PASS).

Remaining waivers / gaps (unchanged from RB-H): QualTest / Event / Player / Report full-class Infection MSI below 15% floors — not lowered.

Next: **RB-F**.

## Phase D — Fuzzy
### RB-F: Validate rebased tip against gold
| Step | Status |
|------|--------|
| Restore RB-G gold bundle; preflight and validate test + mirror; commit | [ ] |

**RB-F notes (2026-07-19) — after gold mirror-data refresh (no tip rebaseline):**

- Restored gold `20260719T124448Z-671c108b-149b83ec1220a285.zip` (`latestBundle`).
  `bin/ork-db deploy-sandbox` PASS. Run id:
  `rb-f-after-gold-refresh-20260719` → exit **1**.
- Report: http://127.0.0.1:8765/run-rb-f-after-gold-refresh-20260719/index.html
- Dual-profile validate (clock pin `2026-07-19` matching gold civil day):
  **test 17/20 PASS**, **mirror 18/20 PASS**.
- Remaining fails (intentional Megiddo award UI / CDN, not mirror attendance drift):
  - test `player-profile-sandbox` / `kingdom-auth-sandbox` / `park-auth-sandbox`:
    assets < 1.0 (+ minor DOM) — Megiddo award `<optgroup>` grouping vs master gold
    and Google Fonts CDN CSS hash drift.
  - mirror `player-profile-sandbox`: assets < 1.0 — Google Fonts CDN CSS hash drift
    (award DOM within mirror 0.99 floor).
  - mirror `kingdom-profile`: assets+dom — Megiddo award optgroup/option membership
    and kingdom stats text vs master gold.
- Weather forecast-freshness `manualNodes` restored (from `10dcbd79`).
- Prior product fix retained: R-13 What's New wiring. Did **not** tip-rebaseline.
- Blockers for green RB-F: waiver to tip-rebaseline / refuzz award-option pages
  (and optionally CDN font assets), or accept remaining intentional Megiddo UI
  drift. Mirror live-attendance drift cleared by gold refresh.

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
