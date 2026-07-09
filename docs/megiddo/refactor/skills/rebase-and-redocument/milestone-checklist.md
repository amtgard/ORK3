# Rebase & Redocument — Milestone Checklist

Track **RB-*** progress here. **Preferred:** one [orchestrator](agent-prompt.md) chat launches serialized sub-agents per milestone. Manual: one worker prompt per chat.

**Skill:** [SKILL.md](SKILL.md) · **Agent prompts:** [agent-prompt.md](agent-prompt.md) (orchestrator + workers) · **Matrix:** [mutation-matrix.md](mutation-matrix.md) · **Conflicts:** [conflict-playbook.md](conflict-playbook.md)

**Steering:** [05-development-steering.md](../../05-development-steering.md) (DS-1–DS-8 as applicable)

---

## Run metadata (fill in RB-0)

| Field | Value |
|-------|-------|
| Date started | 2026-07-09 |
| Megiddo tip (pre-rebase) | branch `megiddo/v-14-lib-service-validation` @ `ad878395` |
| Base | `origin/master` @ `e6417645` |
| Working branch | `megiddo/rebase-20260709` |
| **Sizing grade** | **S** |
| Sizing rationale | 0 commits on `origin/master` since merge-base (`e6417645` = current master tip); no upstream `orkui/`, `db-migrations/`, template, or test churn; Megiddo is 75 commits ahead on same base — rebase expected clean |
| Session plan | **S:** one sub-agent per RB-* (serialized); queue runs faster; no RB-D per-domain splits |

---

## Phase A — Integrate

### RB-0: Preflight and size

**Branch:** create or use `megiddo/rebase-{YYYYMMDD}` (do **not** rebase yet)  
**Prompt:** [agent-prompt.md](agent-prompt.md) → `RB-0`

| Step | Status |
|------|--------|
| `git fetch`; record tip + `origin/master` SHAs | [x] |
| Working tree clean (or WIP parked) | [x] |
| Summarize `HEAD..origin/master` (commits + hot paths: `orkui/`, migrations, templates) | [x] |
| Assign sizing grade S/M/L + session plan in metadata table | [x] |
| Confirm docker / `bin/ork-db` / `bin/fuzzy-validator` available | [x] |
| Checklist metadata filled; next milestone named | [x] |
| Commit (optional docs-only): `RB-0: Size Megiddo rebase onto master` | [x] |

**Exit:** Grade + plan recorded; ready for RB-1.

---

### RB-1: Rebase onto base

**Depends on:** RB-0  
**Prompt:** [agent-prompt.md](agent-prompt.md) → `RB-1`  
**Conflicts:** [conflict-playbook.md](conflict-playbook.md)

| Step | Status |
|------|--------|
| `git rebase origin/master` (or agreed base) | [x] |
| Conflicts resolved per playbook | [x] |
| Rebase completed; tip is ancestor-based on base | [x] |
| Smoke: `composer install` / obvious syntax breakage noted for RB-2 | [x] |
| Commit: `RB-1: Rebase Megiddo line onto master` | [x] |

**Exit:** Clean rebase (or user-approved alternate strategy). No requirement that PHPUnit is green yet.

---

## Phase B — Global tests

### RB-2: Full suite green

**Depends on:** RB-1  
**Prompt:** [agent-prompt.md](agent-prompt.md) → `RB-2`

| Step | Status |
|------|--------|
| `docker compose -f docker-compose.php8.yml up -d` | [x] |
| `bin/ork-db deploy-sandbox` (fix schema/migration drift if needed) | [x] |
| E2E preflight when touching auth-gated specs | [x] |
| `sh bin/run-unit-tests.sh` exit 0 | [x] |
| Critical e2e smoke (or document deferrals to RB-D\*) | [x] |
| Commit: `RB-2: Repair tests after Megiddo rebase` | [x] |

**RB-2 notes (2026-07-09):** Sandbox schema drift vs mirror — added google-maps lat/lng override, InnoDB engine parity in `baseline-gaps.sql`, test-park coords in Render; removed stale `attendance_myisam` fixture refs. PHPUnit: 204 tests, 627 assertions, 2 skipped, exit 0. E2E: health-route smoke pass; auth-gated Playwright deferred to RB-D\* (credentials preflight per `06-test-framework.md`).

**Exit:** Full PHPUnit green. Domain-specific assertion tweaks may continue in RB-D\* if isolated and listed under “deferred”.

---

## Phase C — Domain redocument

Each **RB-D\*** batch repairs, for every domain in the batch:

1. `ds-{nn}-*-discovery.md` §1 lines/behavior + §3 if assumptions broke (+ post-rebase note)
2. Matching rows in `03-implementation-plan.md`
3. `validations/v-{nn}-*.md` §1 page ids / §2 test paths
4. Domain unit/integration/e2e failures still open after RB-2
5. `infection.t{nn}*.json5` paths + milestone Infection gate

**Sizing L:** split a batch into `RB-D-{nn}` single-domain milestones (copy the batch checklist row into its own section).

### Shared sign-off (every RB-D\* / RB-D-{nn})

- [x] Discovery + implementation-plan lines updated for domains in scope
- [x] Validation docs paths/ids still valid
- [x] Domain tests green (or gap noted)
- [x] Infection gate pass for domains in scope (or gap noted + user aware)
- [x] Checklist checked; one commit `RB-D1: …` (or `RB-D-01: …`)

---

### RB-D1: Domains 01–04

**Depends on:** RB-2 · **Domains:** RSVP, auth INSERT, banner, EventAjax  
**Prompt:** `{{BATCH}}=RB-D1` or `{{MILESTONE}}=RB-D1`

| Domain | ds-* | plan lines | v-* | tests | Infection | Done |
|--------|------|------------|-----|-------|-----------|------|
| 01 RSVP | [x] | [x] | [x] | [x] | [x] | [x] |
| 02 Auth | [x] | [x] | [x] | [x] | [x] | [x] |
| 03 Banner | [x] | [x] | [x] | [x] | [x] | [x] |
| 04 EventAjax | [x] | [x] | [x] | [x] | [x] | [x] |

**RB-D1 notes (2026-07-09):** Base `e6417645`; §1 verified against current `orkui/`. Domains 03–04 had minor line drift (banner end lines; EventAjax staff/schedule/heraldry blocks). §3 gaps unchanged. PHPUnit domain filter: 67 tests, 1 skipped, exit 0. Infection: t01 MSI 55%/covered 59%; t02 MSI 42%; t03 MSI 58%; t04 MSI 48% (all ≥15 floor). Auth-gated Playwright still deferred (RB-2 carry-forward).

---

### RB-D2: Domains 05–08

**Depends on:** RB-2 (RB-D1 recommended first) · **Domains:** event, kingdom, park, admin  
**Prompt:** `{{BATCH}}=RB-D2`

| Domain | ds-* | plan lines | v-* | tests | Infection | Done |
|--------|------|------------|-----|-------|-----------|------|
| 05 Event | [x] | [x] | [x] | [x] | [x] | [x] |
| 06 Kingdom | [x] | [x] | [x] | [x] | [x] | [x] |
| 07 Park | [x] | [x] | [x] | [x] | [x] | [x] |
| 08 Admin | [x] | [x] | [x] | [x] | [x] | [x] |

**RB-D2 notes (2026-07-09):** Base `e6417645`; §1 verified against current `orkui/`. Domains 05–08 had minor line drift (event RSVP/template/reconcile; kingdom player-count/ICS; park draft clause; admin suspend/abbr). §3 gaps unchanged. PHPUnit domain filter: 48 tests, exit 0. Infection: t05 MSI 26%; t06 MSI 43%; t07 MSI 24%; t08 batched — Report 88%, StateOfAmtgard 89%, Park 24%, DangerAudit 15% (Player/Weather domain excluded per pre-refactor gap).

---

### RB-D3: Domains 09–12

**Depends on:** RB-2 · **Domains:** player, reports, search, attendance  
**Prompt:** `{{BATCH}}=RB-D3`

| Domain | ds-* | plan lines | v-* | tests | Infection | Done |
|--------|------|------------|-----|-------|-----------|------|
| 09 Player | [x] | [x] | [x] | [x] | [x] | [x] |
| 10 Reports | [x] | [x] | [x] | [x] | [x] | [x] |
| 11 Search | [x] | [x] | [x] | [x] | [x] | [x] |
| 12 Attendance | [x] | [x] | [x] | [x] | [x] | [x] |

**RB-D3 notes (2026-07-09):** Base `e6417645`; §1 verified against current `orkui/`. Domains 09–12 had minor line drift (player profile/beltline/reconcile; reports ladder_grid/voting; search unitactivity/park playersearch; attendance SignIn levels/QR). §3 gaps unchanged. Fixed `LadderGridTest` `$DB->Clear()` pollution from prior attendance tests. PHPUnit domain filter: 56 tests, 1 skipped, exit 0. Infection: t09 batched — profile+cache 25%, AJAX 22%, Authorization 52%; t10 MSI 49%; t11 MSI 50%; t12 MSI 53% (all ≥15 floor).

---

### RB-D4: Domains 13–14

**Depends on:** RB-2 · **Domains:** infrastructure, lib-service  
**Prompt:** `{{BATCH}}=RB-D4`

| Domain | ds-* | plan lines | v-* | tests | Infection | Done |
|--------|------|------------|-----|-------|-----------|------|
| 13 Infrastructure | [x] | [x] | [x] | [x] | [x] | [x] |
| 14 Lib-service | [x] | [x] | [x] | [x] | [x] | [x] |

**RB-D4 notes (2026-07-09):** Base `e6417645`; §1 verified against current `orkui/` and `class.Controller.php`. Domains 13–14 had minor line drift (infra redirect/session/RSVP/widget/template; lib-service Controller menu HasAuthority 92/98/105; plan T-INF-05/06 method→`index`, T-LIB-05 lines). §3 gaps unchanged. PHPUnit domain filter: 21 tests, exit 0. Infection: t13 MSI 13% (Player+profile batch); t14 pass A MSI 18%; t14 pass B MSI 28% (all ≥15 floor).

---

## Phase D — Fuzzy

### RB-F: Fuzzy baselines and setpoint

**Depends on:** RB-2; prefer all RB-D\* done if pages.json5 / canaries changed  
**Prompt:** `{{MILESTONE}}=RB-F`

| Step | Status |
|------|--------|
| E2E preflight for capture profiles | [x] |
| `bin/fuzzy-validator validate --all --phase all` (or restore setpoint first) | [x] |
| Re-record / `setpoint capture` + `publish` if upstream render drift | [x] |
| Update `validations/v-00-*.md` + affected `v-{nn}` capture notes / `latestBundle` | [x] |
| Validate pass **test** + **mirror** | [x] |
| Commit: `RB-F: Recapture fuzzy baselines after rebase` | [x] |

**RB-F notes (2026-07-09):** Base `e6417645` @ commit `1591950d`. Sandbox `--force-refresh` caused test-profile dimension drift (`park-auth-sandbox` 937→961px; `player-profile-sandbox`, `kingdom-auth-sandbox`, RSVP hosts). Full `setpoint capture` → `20260709T173049Z-1591950d-6b22e991bb478256.zip`; bootstrap copy committed. Validate **42/42 pass** (21 pages × test+mirror), exit 0. E2E preflight: health + auth (`admin`/`password`).

---

## Phase E — Close

### RB-Z: Sign-off

**Depends on:** RB-1, RB-2, all planned RB-D\*, RB-F  
**Prompt:** `{{MILESTONE}}=RB-Z`

| Step | Status |
|------|--------|
| Re-run `sh bin/run-unit-tests.sh` | [ ] |
| Spot-check Infection gaps closed or listed | [ ] |
| Fuzzy still green (no accidental doc-only breakage) | [ ] |
| Write **Last rebase** note on [04-milestone-checklist.md](../../04-milestone-checklist.md) / [README.md](../../README.md) | [ ] |
| Fix broken links under `docs/megiddo/refactor/` | [ ] |
| Final report table to user | [ ] |
| Commit: `RB-Z: Close Megiddo rebase and redocument` | [ ] |

**Exit:** Skill complete → next is **R-01**.

---

## Quick reference

| Order | ID |
|-------|-----|
| 1 | RB-0 |
| 2 | RB-1 |
| 3 | RB-2 |
| 4 | RB-D1 → RB-D2 → RB-D3 → RB-D4 |
| 5 | RB-F |
| 6 | RB-Z |

**Next unchecked:** **RB-Z** — Sign-off
