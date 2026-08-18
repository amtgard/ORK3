---
name: phase3-remediation
description: >-
  Serialized remediation after Phase 3 audit failure: fix asset pipeline, Playwright
  heraldry profile, fuzzy park-auth baseline, doc backfill, then DS-19/T-19/V-19/R-19a-d
  for residual Ork3::$Lib bypass. VALIDATE-20 re-audits success criteria. Re-run phase3-closeout only for ad-hoc audits.
disable-model-invocation: true
---

# Megiddo — Phase 3 Remediation

Runs **after** a failed [Phase 3 close-out](../phase3-closeout/orchestrator.prompt) audit ([phase3-audit-report.md](../../phase3-audit-report.md)).

## When to use

- Phase 3 automated gates failed (assets, heraldry Playwright, fuzzy, residual `Ork3::$Lib`)
- You chose **Path A** — full lib migration via discovery → tests → validation → **R-19a … R-19d** (3 files per hop)
- You want **lights-out** serialized sub-agents, not manual milestone paste

## How to run

1. Confirm stack tip: `megiddo/r-18-residual-db-refactor` (or later hop from [milestone-checklist.md](./milestone-checklist.md)).
2. Open [orchestrator.prompt](./orchestrator.prompt) → Ctrl+A, Ctrl+C, paste into a **new** agent chat.
3. When **VALIDATE-20** reports `status=ok`, complete human P3-4/P3-5. For ad-hoc re-audit later, use [phase3-closeout/orchestrator.prompt](../phase3-closeout/orchestrator.prompt).

## Pipeline (serialized)

| Hop | Worker | Purpose |
|-----|--------|---------|
| 1 | [FIX-02-assets.md](./workers/FIX-02-assets.md) | `deploy-sandbox` heraldry asset manifest |
| 2 | [FIX-03-playwright-heraldry.md](./workers/FIX-03-playwright-heraldry.md) | Heraldry E2E profile / sandbox alignment |
| 3 | [FIX-04-fuzzy-park-auth.md](./workers/FIX-04-fuzzy-park-auth.md) | `park-auth-sandbox` baseline (after heraldry) |
| 4 | [FIX-05-doc-hygiene.md](./workers/FIX-05-doc-hygiene.md) | R-05/06/07/12 prose in implementation plan |
| 5 | [BACKFILL-tvds-r14-r18.md](./workers/BACKFILL-tvds-r14-r18.md) | Retroactive DS/T/V for R-14 … R-18 |
| 6 | [DS-19.md](./workers/DS-19.md) | Discovery — 41 sites in 12 files; R-19a…d file groups |
| 7 | [T-19.md](./workers/T-19.md) | Test development for R-19 scope |
| 8 | [V-19.md](./workers/V-19.md) | Validation artifacts + per-hop fuzzy/Infection boundaries |
| 9 | [R-19a.md](./workers/R-19a.md) | `model.Player`, `index.php`, `KingdomAjax` |
| 10 | [R-19b.md](./workers/R-19b.md) | `EventAjax`, `AdminAjax`, `Admin` |
| 11 | [R-19c.md](./workers/R-19c.md) | `ParkAjax`, `SearchAjax`, `Search` |
| 12 | [R-19d.md](./workers/R-19d.md) | `PlayerAjax`, `WnAjax`, `model.AdminDashboard` — full `rg` zero |
| 13 | [VALIDATE-20.md](./workers/VALIDATE-20.md) | Re-audit: no DB/lib/DML in `orkui/`, PHPUnit, fuzzy, Playwright |

## Success criteria (VALIDATE-20)

| Check | Pass criterion |
|-------|----------------|
| Frontend DB isolation | `rg '\$DB->' orkui/` → zero |
| Frontend lib isolation | `rg 'Ork3::\$Lib' orkui/` → zero |
| Frontend DML isolation | No `INSERT INTO` / `UPDATE … SET` / `DELETE FROM` in `orkui/*.php` |
| Unit tests | `sh bin/run-unit-tests.sh` exit 0 |
| Fuzzy | `bin/fuzzy-validator validate --all --phase all` exit 0 |
| Playwright | Full e2e exit 0 (mirror + sandbox heraldry) |

Business logic belongs in `system/lib/ork3/` and `orkservice/*` — static audit + green gates confirm no regressions from moving it.

## R-19 file groups (12 files → 4 × 3)

| Hop | Files | ~Sites |
|-----|-------|-------:|
| R-19a | `model.Player.php`, `index.php`, `KingdomAjax.php` | 24 |
| R-19b | `EventAjax.php`, `AdminAjax.php`, `Admin.php` | 10 |
| R-19c | `ParkAjax.php`, `SearchAjax.php`, `Search.php` | 4 |
| R-19d | `PlayerAjax.php`, `WnAjax.php`, `model.AdminDashboard.php` | 3 |

## Related

- [phase3-audit-report.md](../../phase3-audit-report.md) — failure inventory
- [11-phase-3-closeout.md](../../11-phase-3-closeout.md) — target state
- [10-phase-2-continuation.md](../../10-phase-2-continuation.md) — R-15 … R-18 + R-19a…d pointer
- [refactor-execution/workers/_shared-procedure.md](../refactor-execution/workers/_shared-procedure.md) — R-19a…d gates
