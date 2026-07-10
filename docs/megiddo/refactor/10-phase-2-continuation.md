# Phase 2 Continuation — R-15 … R-18

**Status:** In progress (stack tip `megiddo/r-15-hasauthority-refactor` @ `a5639704`)  
**Master checklist:** [04-milestone-checklist.md](./04-milestone-checklist.md)  
**Prerequisite:** R-01 … R-14 complete (domain migrations + T-LIB-01–05 JSON surfaces)

R-01 … R-14 moved the bulk of `$DB` and explicit T-* targets into domain services. Cross-cutting **`HasAuthority`**, **`ghettocache`**, and residual **`Ork3::$Lib`** call sites were intentionally split out so R-14 could ship shared APIs first. **Phase 3 is audit-only** — no implementation. Finish remaining violations in **R-15 … R-18**, then run Phase 3.

---

## Carryover audit (what R-01 … R-14 left open)

Audit snapshot on stack tip `76758e2c` (2026-07-10):

| Metric | Count |
|--------|------:|
| Files with `$DB->` in `orkui/` | 9 |
| Files with `Ork3::$Lib` in `orkui/` | 34 |
| `Ork3::$Lib` call sites (approx.) | ~203 |
| `HasAuthority` in `orkui/` (post-R-15) | **0** |

### Per-milestone deferrals

| Sprint | Targets signed off | Deferred to R-15+ | Notes |
|--------|-------------------|-------------------|-------|
| **R-05** | T-EVT-01–07 | **T-EVT-08** | `authorization` + `ghettocache` bust in `Controller_Event` |
| **R-06** | T-KNG-01–10, T-KNA-* | **T-KNG-11** | Auth gates, cache, `player->GetCircleAwardIds` in `Controller_Kingdom` |
| **R-07** | T-PRK-01–04, T-PRA-03 | **T-PRK-05** | Auth, `weather->for_park`, circle awards in `Controller_Park` |
| **R-09** | T-PLR-01–07, T-PLA/T-PLM | **T-PLR-08** | `authorization` gates in `Controller_Player` |
| **R-10** | T-RPT-01, T-RPT-03–09, T-AWD-01 | **T-RPT-02** | Auth, `park` lib, `ghettocache` in `Controller_Reports` |
| **R-12** | T-ATT-01–06, T-SIN-*, T-QR-01 | **T-ATT-04**, **T-ATT-06** (partial) | `authorization`, `event` lib in `Controller_Attendance`; model cache bust |
| **R-14** | T-LIB-01–05, Controller base menu | **~120 HasAuthority** + **~20 templates** | Shared API shipped; bulk replacement → **R-15** |
| **(none)** | T-UNT-01 → R-03 | **T-UNT-02**, **T-UNT-03** | Unit officer grant + auth/player lib in `Controller_Unit` |
| **(none)** | — | **Principality** | `controller.Principality.php` lib bypass (no DS row — treat as R-17) |

DS-14 §1.3–1.5 is the authoritative inventory for cross-cutting patterns. R-14 delivered `AuthorizationGate`, `LiveService`, `WeatherService`, and `EraPhoeniceService`; remaining work is **call-site migration**, not new service design.

### Worker hygiene gap (2026-07-10)

Orchestrated workers updated checklists in commits but left **`skills/refactor-execution/workers/`** untracked on the R-14 branch. Close-out commit adds worker prompts + this continuation plan.

---

## Execution sprints (R-15 … R-18)

| Sprint | Branch slug | Depends on | Scope | Primary target IDs |
|--------|-------------|------------|-------|-------------------|
| **R-15** | `r-15-hasauthority-refactor` | R-14 | Replace remaining `HasAuthority` in controllers + precompute template flags | DS-14 §1.3; partial T-EVT-08, T-KNG-11, T-PRK-05, T-PLR-08, T-RPT-02, T-ATT-04, T-UNT-03 |
| **R-16** | `r-16-ghettocache-refactor` | R-15 | Move read-through cache and write bust into domain; remove frontend `ghettocache` | DS-14 §1.4; T-EVT-08, T-KNG-11, T-RPT-02, T-PLM-03, T-ATT-06, T-SRC-01 |
| **R-17** | `r-17-lib-bypass-refactor` | R-16 | Residual `Ork3::$Lib` domain helpers (player, kingdom, park, weather, dangeraudit, event, unit) | T-EVT-08, T-KNG-11, T-PRK-05, T-PLR-08, T-RPT-02, T-UNT-02/03, Principality |
| **R-18** | `r-18-residual-db-refactor` | R-17 | Last `$DB->` in `orkui/` (controllers, models, templates, `index.php`) | Remaining rows in [03-implementation-plan.md](./03-implementation-plan.md) |

**Branch pattern:** `megiddo/r-{nn}-{slug}` — same stacked discipline as R-01 … R-14 (one squashed commit per sprint).

**Validation:** Reuse V-14 for R-15–R-17 fuzzy/Infection boundaries where scope overlaps lib-service; add milestone notes to `v-14-lib-service-validation.md` §3 as each continuation sprint completes. R-18 runs full-suite audit gates.

**Fuzzy gate (suggested):**

| Sprint | Pages |
|--------|-------|
| R-15 | `admin-permissions,kingdom-auth-sandbox,park-auth-sandbox,player-profile` |
| R-16 | `kingdom-profile,park-auth-sandbox,reports-ladder-grid` |
| R-17 | `event-index-rsvp,player-profile,reports-voting-eligible` |
| R-18 | Full V-00 active set (regression sweep) |

---

## Phase 3 — Audit and close-out

**Canonical plan:** [11-phase-3-closeout.md](./11-phase-3-closeout.md) (deliverables, agent vs human, prompts, cross-reference index).

Phase 3 runs **after R-18**. Summary:

| ID | Deliverable | Owner |
|----|-------------|-------|
| P3-1 | [validations/r-milestone-smoke-matrix.html](./validations/r-milestone-smoke-matrix.html) — one manual smoke per R-* | Human (+ agent may refresh baselines) |
| P3-2 | Automated audit (`rg`, PHPUnit, fuzzy, Playwright) | Agent — [skills/phase3-closeout/orchestrator.prompt](./skills/phase3-closeout/orchestrator.prompt) |
| P3-4 | Manual walk-through of HTML matrix | Human |
| P3-5 | Retrospective | Human |

Automated verification checklist (detail in 11-phase-3-closeout.md):

| Check | Command / artifact |
|-------|-------------------|
| P3-1 HTML matrix | [validations/r-milestone-smoke-matrix.html](./validations/r-milestone-smoke-matrix.html) |
| P3-4 manual smokes | Human walk R-01 … R-18 in HTML matrix |
| Zero `$DB` | `rg '\$DB->' orkui/` → no matches |
| Zero lib bypass | `rg 'Ork3::\$Lib' orkui/` → no matches |
| Plan complete | [03-implementation-plan.md](./03-implementation-plan.md) all T-* done |
| P3-2 gates | PHPUnit, fuzzy `--all`, Playwright |

Optional: merge stack tip → `megiddo/rebase-20260709`.

---

## Related documents

| Doc | Role |
|-----|------|
| [04-milestone-checklist.md](./04-milestone-checklist.md) | R-15 … R-18 progress + Phase 3 checkboxes |
| [ds-14-lib-service-discovery.md](./ds-14-lib-service-discovery.md) | Cross-cutting inventory (§1.3–1.5) |
| [skills/refactor-execution/](./skills/refactor-execution/) | Orchestrator + worker prompts for R-15+ |
| [11-phase-3-closeout.md](./11-phase-3-closeout.md) | Phase 3 audit plan + HTML matrix + prompts |
