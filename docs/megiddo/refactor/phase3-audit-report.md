# Phase 3 Automated Audit Report

**Timestamp:** 2026-07-10T16:40:05Z  
**Branch:** `megiddo/r-18-residual-db-refactor`  
**Commit:** `1d8d8455`  
**Orchestrator:** [skills/phase3-closeout/orchestrator.prompt](./skills/phase3-closeout/orchestrator.prompt)

## Prerequisite

- **R-18:** Complete on stack tip (`04-milestone-checklist.md` § Phase 2 continuation).

## Environment

| Step | Result | Notes |
|------|--------|-------|
| `docker compose -f docker-compose.php8.yml up -d` | pass | `ork3-php8-db`, `ork3-php8-test-db`, `ork3-php8-app` running |
| `bin/ork-db deploy-sandbox` | **fail** | Post-apply asset validation: 110 missing player heraldry files (`player/100021253`, `player/100050279`, …). Deploy aborted after `deploy-assets` (108 files). |
| `bin/fuzzy-validator setpoint restore` | pass | Bundle `20260709T173049Z-1591950d-6b22e991bb478256.zip` (1349 files) |

**Credentials (documented):** Playwright and fuzzy mirror use `admin` / `password` (`ORK3_E2E_USERNAME` / `ORK3_E2E_PASSWORD`). Playwright run used **prod** profile per orchestrator (`bin/ork-db use prod`).

---

## P3-A — Static audit

### `$DB->` in `orkui/`

```bash
rg '\$DB->' orkui/
```

| Metric | Value |
|--------|-------|
| Matches | **0** |
| Result | **pass** |

### `Ork3::$Lib` in `orkui/`

```bash
rg 'Ork3::\$Lib' orkui/
```

| Metric | Value |
|--------|-------|
| Matches | **42** across **12 files** |
| Result | **fail** |

**Files (42 matches):**

| File | Count | Domains / notes |
|------|-------|-----------------|
| `orkui/model/model.Player.php` | 12 | `player` thin wrappers (milestones, notes, beltline, etc.) |
| `orkui/index.php` | 7 | `health`, `event`, `session` bootstrap / timing |
| `orkui/controller/controller.KingdomAjax.php` | 6 | `searchservice`, `dangeraudit`, `kingdom` |
| `orkui/controller/controller.EventAjax.php` | 5 | `searchservice`, `dangeraudit`, `heraldry` |
| `orkui/controller/controller.AdminAjax.php` | 3 | `searchservice`, `dangeraudit`, `stateofamtgard` |
| `orkui/controller/controller.Admin.php` | 2 | `weather` admin refresh/stats |
| `orkui/controller/controller.ParkAjax.php` | 2 | `searchservice`, `dangeraudit` |
| `orkui/controller/controller.SearchAjax.php` | 1 | `searchservice` |
| `orkui/controller/controller.Search.php` | 1 | `searchservice` |
| `orkui/controller/controller.PlayerAjax.php` | 1 | `player` username check |
| `orkui/controller/controller.WnAjax.php` | 1 | `player` dismiss |
| `orkui/model/model.AdminDashboard.php` | 1 | `stateofamtgard` bootstrap |

### Raw DML in `orkui/*.php`

```bash
rg -i 'INSERT INTO|UPDATE [a-z_]+ SET|DELETE FROM' orkui/ --glob '*.php'
```

| Metric | Value |
|--------|-------|
| Matches | **0** |
| Result | **pass** |

No documented exemptions in [02-requirements.md](./02-requirements.md) § Success Criteria. R-17/R-18 carryover notes referenced deferred lib sites (`searchservice`, `heraldry`, `index.php`) but did not close them.

---

## P3-B — PHPUnit

```bash
sh bin/run-unit-tests.sh
```

| Metric | Value |
|--------|-------|
| Exit code | **0** |
| Tests | 215 (2 skipped) |
| Assertions | 648 |
| Drift check | PASS |
| Result | **pass** |

---

## P3-C — Fuzzy regression

```bash
bin/fuzzy-validator setpoint restore
bin/fuzzy-validator validate --all --phase all
```

| Metric | Value |
|--------|-------|
| Exit code | **2** |
| Captures | 21/21 pass |
| Gate failure | `park-auth-sandbox` — dimension mismatch: baseline **(961, 1280)** vs candidate **(937, 1280)** |
| Result | **fail** |

---

## P3-D — Playwright

```bash
export ORK3_E2E_BASE_URL=http://127.0.0.1:19080/orkui/
bin/ork-db use prod
export ORK3_E2E_USERNAME=admin ORK3_E2E_PASSWORD=password
npx playwright test tests/e2e/
```

| Metric | Value |
|--------|-------|
| Exit code | **1** |
| Passed | **43** / 46 |
| Failed | **3** — all in `tests/e2e/heraldry.spec.ts` |
| Result | **fail** |

**Failures:**

1. `kingdom profile shows test kingdom heraldry` — `.heraldry-img[src*="heraldry/kingdom/100001."]` not visible
2. `park profile shows test park heraldry` — `.heraldry-img[src*="heraldry/park/1000001."]` not visible
3. `kingdom roster serves heraldry avatars for flagged fake players` — no fake player with avatar URL in roster JSON

Likely related to prod/mirror heraldry asset deployment (aligns with sandbox `deploy-sandbox` asset validation failure).

---

## P3-E — Plan completeness

All **~119** tracked T-* IDs have R-* completion notes in either [03-implementation-plan.md](./03-implementation-plan.md) (R-01…R-04, R-08…R-18) or [04-milestone-checklist.md](./04-milestone-checklist.md) (R-05…R-07, R-12).

| Gap type | Detail |
|----------|--------|
| Doc prose | `03-implementation-plan.md` lacks inline **R-05, R-06, R-07, R-12** completion paragraphs (present only in checklist). |
| Code vs plan | **42** residual `Ork3::$Lib` call sites remain despite R-17/R-18 “domain lib bypass” sign-off; inventory rows (e.g. T-PLM-02/04, T-EVA-11, T-ADM-08/09, T-INF-01/02, T-SRC-*) still describe lib bypass in table text. |

**Result:** **pass** (all targets assigned to R-* milestones) with **code-state caveat** (lib bypass not fully eliminated).

---

## P3-F — Checklist sign-off (automated only)

Updated [04-milestone-checklist.md](./04-milestone-checklist.md) § Phase 3:

- [x] P3-2 agent automated audit (this report)
- [x] `rg '\$DB->' orkui/` → zero
- [x] PHPUnit full suite green
- [ ] `rg 'Ork3::\$Lib' orkui/` → zero (**42 matches**)
- [ ] Full fuzzy `--all` + Playwright green
- [ ] Success criteria in `02-requirements.md` fully satisfied
- [ ] P3-4 manual smoke matrix walk-through (human)
- [ ] P3-5 retrospective (human; draft below)

---

## Human follow-ups

### P3-4 — Manual smoke matrix

Open [validations/r-milestone-smoke-matrix.html](./validations/r-milestone-smoke-matrix.html) in a browser. Walk **R-01 … R-18** in order; mark pass/fail per section. Pay extra attention to heraldry chrome and park-auth pages given fuzzy/Playwright failures.

**Preflight before walk-through:**

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox   # fix asset manifest first if still aborting
bin/fuzzy-validator setpoint restore
export ORK3_E2E_BASE_URL=http://127.0.0.1:19080/orkui/
bin/ork-db use prod
export ORK3_E2E_USERNAME=admin ORK3_E2E_PASSWORD=password
```

### P3-5 — Retrospective (draft for human edit)

- **Residual lib bypass:** Phase 2 sign-off claimed R-17/R-18 closed domain lib migration, but static audit finds 42 `Ork3::$Lib` sites (search, heraldry, dangeraudit, player wrappers, index session). Decide: new R-19 scope vs documented exemptions in `02-requirements.md`.
- **Asset pipeline:** `deploy-sandbox` aborts on 110 missing player heraldry files; Playwright `heraldry.spec.ts` and fuzzy `park-auth-sandbox` failures may share root cause. Run `bin/ork-db generate-assets && bin/ork-db deploy-assets` and reconcile manifest expectations.
- **Fuzzy dimension drift:** `park-auth-sandbox` height 961→937 — re-record baseline or investigate layout regression from R-15+ template changes.
- **Doc hygiene:** Add R-05/06/07/12 completion paragraphs to `03-implementation-plan.md` for single-source traceability.
- **Orchestrator:** Prod-profile Playwright requires explicit approval in constrained environments; document in `06-test-framework.md` if recurring.

### P3-6 — Optional merge

Merge stack tip `megiddo/r-18-residual-db-refactor` → `megiddo/rebase-20260709` after human sign-off.

---

## Summary

| Gate | Result |
|------|--------|
| P3-A `$DB` | pass (0) |
| P3-A `Ork3::$Lib` | **fail** (42 / 12 files) |
| P3-A DML | pass (0) |
| PHPUnit | pass |
| Fuzzy | **fail** (`park-auth-sandbox` dimension) |
| Playwright | **fail** (3 heraldry tests) |
| Plan completeness | pass (with lib-bypass caveat) |

**Overall status:** `failed` — R-18 prerequisite met; automated close-out blocked on residual lib bypass, asset/heraldry deployment, and visual regression gates.

**Remediation orchestrator:** [skills/phase3-remediation/orchestrator.prompt](./skills/phase3-remediation/orchestrator.prompt) — serialized FIX-02…R-19d queue; **VALIDATE-20** final re-audit.
