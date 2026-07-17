# Rebase & Redocument — Milestone Checklist (Post-Refactor)

Track **RB-*** progress for the **current** post-refactor rebase. **Preferred:** paste [orchestrator.prompt](orchestrator.prompt) into one chat; it launches serialized sub-agents per milestone.

**Skill:** [SKILL.md](SKILL.md) · **Copy-paste:** [orchestrator.prompt](orchestrator.prompt) · **Workers:** [agent-prompt.md](agent-prompt.md) · **Matrix:** [mutation-matrix.md](mutation-matrix.md) · **Conflicts:** [conflict-playbook.md](conflict-playbook.md)

**Steering:** [05-development-steering.md](../../05-development-steering.md) · **Remaining close-out:** [04-milestone-checklist.md](../../04-milestone-checklist.md)

---

## Run metadata (fill in RB-0)

| Field | Value |
|-------|-------|
| Date started | 2026-07-17 |
| Megiddo tip (pre-rebase) | `megiddo/p3-fix-10-sandbox-fuzzy-rebaseline` @ `e1d993976f646c9d75ea13c96e99b26aa10939b4` |
| Base | `origin/master` @ `7631d0baad65b573d4d53f115c84d20af09b046e` |
| Working branch | `megiddo/rebase-20260717` |
| **Sizing grade** | **L** |
| Sizing rationale | 26 commits on `HEAD..origin/master`; merge-base `e6417645`. Upstream brings full **Qualification Tests** module (new `orkui` controllers/templates + `class.QualTest.php` + consolidated migration). Overlap includes **Player / Kingdom / Reports** controllers + revised-frontend templates + `class.Kingdom.php` — matches L heuristics (large new module + messy Player/Kingdom/Reports/template merges). |
| Session plan | **L:** RB-1 alone (spirit merges); RB-2 alone; RB-H split by domain (Kingdom / Player / Reports / templates); RB-N alone (QualTest spirit scan — controllers already call `Ork3::$Lib->qualtest`); RB-F alone; RB-Z close. Do not collapse into one mega-session. |
| Overlap inventory | See table under RB-0 notes below |
| WIP parked | `stash@{0}`: `WIP: park docs archive ahead of megiddo/rebase-20260717 (RB-0)` (docs archive + related; skill files restored onto this branch for the post-refactor checklist) |

---

## Phase A — Integrate

### RB-0: Preflight, reset, and size

**Branch:** create `megiddo/rebase-{YYYYMMDD}` from current Megiddo tip (do **not** rebase yet)  
**Prompt:** [agent-prompt.md](agent-prompt.md) → `RB-0`

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

**Exit:** Grade + overlap inventory recorded; ready for RB-1.

**RB-0 notes (2026-07-17):**

Upstream delta (`HEAD..origin/master`): **26 commits**, tip `7631d0ba` (Walker 3.5.4 / Qual Tests / maintenance banner). Meaningful file churn since merge-base `e6417645`: **35 files** (`orkui/` 27, `db-migrations/` 2, `system/lib/` 2, plus docs/README). Tree-wide `git diff --name-only HEAD..origin/master` is large (~1383) because Megiddo tip has diverged; use merge-base for inventory.

Hot paths (merge-base → `origin/master`): QualTest controllers/templates; Kingdom/Player/Reports touch-ups; revised-frontend templates/JS/CSS; `db-migrations/2026-07-14-qualification-tests.sql`; `system/lib/ork3/class.QualTest.php` + `class.Kingdom.php`.

#### Overlap inventory (Megiddo ∩ upstream since merge-base)

| Path | Megiddo changed? | Upstream changed? | Class |
|------|------------------|-------------------|-------|
| `orkui/controller/controller.Kingdom.php` | yes | yes | **overlap** |
| `orkui/controller/controller.Player.php` | yes | yes | **overlap** |
| `orkui/controller/controller.Reports.php` | yes | yes | **overlap** |
| `orkui/model/model.Reports.php` | yes | yes | **overlap** |
| `orkui/template/default/default.theme` | yes | yes | **overlap** |
| `orkui/template/revised-frontend/Eventnew_index.tpl` | yes | yes | **overlap** |
| `orkui/template/revised-frontend/Kingdomnew_index.tpl` | yes | yes | **overlap** |
| `orkui/template/revised-frontend/Parknew_index.tpl` | yes | yes | **overlap** |
| `orkui/template/revised-frontend/Playernew_index.tpl` | yes | yes | **overlap** |
| `system/lib/ork3/class.Kingdom.php` | yes | yes | **overlap** |

#### Upstream-new `orkui/` / lib (take in RB-1 → spirit scan in RB-N)

| Path | Class |
|------|-------|
| `orkui/controller/controller.QualTest.php` | **upstream-new** |
| `orkui/controller/controller.QualTestAjax.php` | **upstream-new** |
| `orkui/template/default/QualTest_*.tpl` (manage/question/questions/take) | **upstream-new** |
| `orkui/template/default/Reports_test_results.tpl` | **upstream-new** |
| `system/lib/ork3/class.QualTest.php` | **upstream-new** |
| `db-migrations/2026-07-14-qualification-tests.sql` | **upstream-new** |

Tooling check: docker OK (Compose v5.0.2); `bin/ork-db`, `bin/fuzzy-validator`, `tools/infection/` present.

---

### RB-1: Rebase with spirit-preserving merges

**Depends on:** RB-0  
**Prompt:** [agent-prompt.md](agent-prompt.md) → `RB-1`  
**Conflicts:** [conflict-playbook.md](conflict-playbook.md)

| Step | Status |
|------|--------|
| `git rebase origin/master` (or agreed base) | [ ] |
| Overlap conflicts merged per playbook (Megiddo layering + upstream behavior) | [ ] |
| Upstream-new files taken; listed for RB-N | [ ] |
| Migrations kept (both sides) | [ ] |
| Rebase completed; tip based on new base | [ ] |
| Conflict notes recorded (file → where logic landed) | [ ] |
| Commit: `RB-1: Rebase Megiddo onto master (spirit merge)` | [ ] |

**Exit:** Clean rebase. PHPUnit need not be green yet.

---

## Phase B — Global tests

### RB-2: Full suite green

**Depends on:** RB-1  
**Prompt:** [agent-prompt.md](agent-prompt.md) → `RB-2`

| Step | Status |
|------|--------|
| `docker compose -f docker-compose.php8.yml up -d` | [ ] |
| `bin/ork-db deploy-sandbox` (fix schema/migration drift) | [ ] |
| E2E preflight when touching auth-gated specs | [ ] |
| `sh bin/run-unit-tests.sh` exit 0 | [ ] |
| Critical e2e smoke (or documented deferrals to RB-H/RB-N) | [ ] |
| Commit: `RB-2: Repair tests after post-refactor rebase` | [ ] |

**Exit:** Full PHPUnit green. Domain/hotspot tweaks may continue in RB-H/RB-N if listed.

---

## Phase C — Hotspots and new-code spirit

### RB-H: Overlap hotspots

**Depends on:** RB-2  
**Prompt:** [agent-prompt.md](agent-prompt.md) → `RB-H`

For each **overlap** path from RB-0 inventory:

| Hotspot / domain | Thin layer OK | Upstream behavior | Tests | Infection | Done |
|------------------|---------------|-------------------|-------|-----------|------|
| Kingdom (`controller.Kingdom.php`, `class.Kingdom.php`, `Kingdomnew_index.tpl`) | [ ] | [ ] | [ ] | [ ] | [ ] |
| Player (`controller.Player.php`, `Playernew_index.tpl`) | [ ] | [ ] | [ ] | [ ] | [ ] |
| Reports (`controller.Reports.php`, `model.Reports.php`) | [ ] | [ ] | [ ] | [ ] | [ ] |
| Templates (`default.theme`, Event/Park revised-frontend) | [ ] | [ ] | [ ] | [ ] | [ ] |

Shared sign-off:

- [ ] No `$DB->` / `Ork3::$Lib` reintroduced on overlap paths
- [ ] Hotspot tests green (or gaps listed)
- [ ] Relevant `tools/infection/` gates green (or gaps listed)
- [ ] Commit: `RB-H: Repair overlap hotspots after rebase`

**Exit:** Overlap surfaces trustworthy; remaining new-module work is RB-N.

---

### RB-N: New upstream code — spirit of the refactor

**Depends on:** RB-2 (RB-H recommended first)  
**Prompt:** [agent-prompt.md](agent-prompt.md) → `RB-N`  
**Matrix:** [mutation-matrix.md](mutation-matrix.md) § RB-N

| Step | Status |
|------|--------|
| Inventory upstream-new / heavily rewritten `orkui/` areas | [ ] |
| Static scan: `$DB`, raw SQL, `Ork3::$Lib`, auth INSERTs | [ ] |
| Migrate violations into `system/lib/ork3/` + thin frontend | [ ] |
| Add/extend characterization tests for moved behavior | [ ] |
| `rg '\$DB->' orkui/` clean | [ ] |
| `rg 'Ork3::\$Lib' orkui/` clean | [ ] |
| `sh bin/run-unit-tests.sh` exit 0 | [ ] |
| Commit: `RB-N: Migrate new upstream frontend logic behind services` | [ ] |

**Exit:** Success-criteria static gates clean on `orkui/`, or explicit user waivers listed on this checklist.

**RB-N preview (from RB-0):** QualTest controllers on `origin/master` already use heavy `Ork3::$Lib->qualtest` — expect spirit migration (thin frontend → service/`Model_*`) as primary RB-N work. Split to RB-N2 if scope explodes.

---

## Phase D — Fuzzy

### RB-F: Fuzzy baselines and setpoint

**Depends on:** RB-2; prefer RB-H + RB-N done if UI/schema changed  
**Prompt:** [agent-prompt.md](agent-prompt.md) → `RB-F`

| Step | Status |
|------|--------|
| E2E preflight for capture profiles | [ ] |
| `bin/fuzzy-validator validate --all --phase all` (or restore setpoint first) | [ ] |
| Re-record / `setpoint capture` + `publish` if legitimate drift | [ ] |
| Update active validation notes / `latestBundle` as needed | [ ] |
| Validate pass **test** + **mirror** | [ ] |
| Commit: `RB-F: Recapture fuzzy baselines after rebase` | [ ] |

---

## Phase E — Close

### RB-Z: Sign-off

**Depends on:** RB-1, RB-2, RB-H, RB-N, RB-F  
**Prompt:** [agent-prompt.md](agent-prompt.md) → `RB-Z`

| Step | Status |
|------|--------|
| Re-run `sh bin/run-unit-tests.sh` | [ ] |
| Confirm static spirit gates still clean | [ ] |
| Spot-check Infection gaps closed or listed | [ ] |
| Fuzzy still green | [ ] |
| Write **Last rebase** on [04-milestone-checklist.md](../../04-milestone-checklist.md) + [README.md](../../README.md) | [ ] |
| Fix broken links under active `docs/megiddo/refactor/` | [ ] |
| Final report table to user | [ ] |
| Commit: `RB-Z: Close post-refactor Megiddo rebase` | [ ] |

**Exit:** Skill complete → next is **P3-4** (manual smoke matrix), then P3-5 / optional P3-6.

---

## Quick reference

| Order | ID |
|-------|-----|
| 1 | RB-0 |
| 2 | RB-1 |
| 3 | RB-2 |
| 4 | RB-H |
| 5 | RB-N |
| 6 | RB-F |
| 7 | RB-Z |

**Next unchecked:** RB-1

---

## Prior runs

### 2026-07-09 — pre-execution / tooling-era rebase (complete)

| Field | Value |
|-------|-------|
| Megiddo tip (pre-rebase) | `megiddo/v-14-lib-service-validation` @ `ad878395` |
| Base | `origin/master` @ `e6417645` |
| Working branch | `megiddo/rebase-20260709` |
| Sizing grade | S |
| Outcome | RB-0…RB-Z complete; fuzzy setpoint `20260709T173049Z-1591950d-6b22e991bb478256.zip` |

That run used the pre-R-* playbook (take upstream for `orkui/` when Megiddo had not yet migrated production code). **Do not reuse those conflict rules** for post-refactor rebases.
