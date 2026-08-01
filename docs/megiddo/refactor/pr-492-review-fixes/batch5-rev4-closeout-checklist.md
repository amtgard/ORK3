# PR #492 Batch 5 Rev4 — Mac closeout checklist

Plan: [`docs/megiddo/plans/pr492_batch5_rev4_348561df.plan.md`](../../plans/pr492_batch5_rev4_348561df.plan.md)  
Base branch: `fix-pr-492` @ `8a68493d` (tip ahead of `origin/fix-pr-492`)  
PR: https://github.com/amtgard/ORK3/pull/492

## Scope on this Mac host

| Plan phase | Status | Notes |
|---|---|---|
| A Host tool prerequisites (Linux) | **SKIPPED** | Linux-only preamble; Mac already has PHP 8.4, Docker, vendor→`~/.cache/ork3/vendor`, `gh` auth |
| B Local stack bring-up | [x] | `ork3db`/`ork3testdb`/`ork3archivedb` Up; drift-check PASS |
| C Prove Rev4 (PHPUnit) | [x] | Full suite green on M1 (`build/batch5-m1-phpunit.log`) |
| D PR hygiene | [ ] | Post pending-replies C-19…C-31 + REVISION-4; push + mirror |

## Orchestration gates (every stacked branch)

Before a stacked branch may close:

1. Work checklist rows for that milestone updated
2. Full PHPUnit green (`sh bin/run-unit-tests.sh`)
3. New lines ≥ **95%** covered (PHPUnit coverage on changed PHP)
4. Overall unit-test coverage **≥ prior milestone** (monotonic)
5. Infection MSI/Covered MSI **≥ prior operating floor** (monotonic; do not lower `minMsi`)
6. All deliverables staged + committed (no dirty close)
7. Plan / this checklist revised if scope or SHAs change

## Stacked branches

| Milestone | Branch | Purpose | PHPUnit | Coverage Δ | Infection floor | Commit | Closed |
|---|---|---|---|---|---|---|---|
| M0 | `fix-pr-492-batch5-m0-baseline` | Restore Drive-corrupted WT to HEAD; isolate fuzzy-validator WIP; record baseline numbers | [x] RED recorded | log 25.40%; remasured same host 25.14% (6417/25528) | config minMsi 15 | `0dd41871` | [x] |
| M1 | `fix-pr-492-batch5-m1-phpunit` | Fix any Rev4 regressions until full suite green; raise tests if needed | [x] GREEN | new PHP 100%; suite 25.24% (6443/25529) ≥ remasured M0 | ≥ M0 | `7997bb30` | [x] |
| M2 | `fix-pr-492-batch5-m2-infection` | Rev4-touched infection configs green; raise floors monotonically where evidence supports | [x] GREEN | suite 25.32% ≥ M1 25.24%; no new PHP | floors raised (see runlist) | `256995ec` | [x] |
| M3 | `fix-pr-492-batch5-m3-docs` | Checklist SHA fill (C-28/C-29 + C-01…C-18), plan Mac-skip note, Batch5 checklist sync | [x] n/a docs | n/a docs | n/a | *(tip SHA follow-up)* | [x] |
| M4 | `fix-pr-492-batch5-m4-pr-hygiene` | Post pending-replies + REVISION-4; push `fix-pr-492` + mirror `megiddo/fuzzy-validator-v2` | [ ] | n/a | n/a | | [ ] |

Note: Git ref names use hyphens (`fix-pr-492-batch5-m*`), not nested `fix-pr-492/...`, because branch `fix-pr-492` already exists.

## Baseline log (fill during M0)

| Metric | Value | Recorded |
|---|---|---|
| HEAD SHA | `8a68493d` | 2026-08-01 |
| PHPUnit | **RED** — Tests: 310, Errors: 1, Failures: 4, Skipped: 2 (`build/batch5-m0-phpunit.log`) | 2026-08-01 |
| Line coverage (suite) | **25.40%** lines (6485/25528) in M0 log; **remasured** on same host at M0 tip: **25.14%** (6417/25528); methods 15.98%; classes 10.87% | 2026-08-01 |
| Infection configs run | M2 list locked (see below); explore map complete | 2026-08-01 |
| Operating MSI floor (min observed pass / config min) | M2 raised per-config (t01 **17**, t09 **33**, t10 **25**, t08 **18**, t14 **23**, t12 **49**, t-qualtest **50**, t11 **40**); never lowered; Jul-18 rbh wide-source fails are not the gate | 2026-08-01 |
| Operating Covered MSI floor | M2 raised (t01 **55**, others match MSI floors above); prior R-* 46/47/51/62 not fully reproduced on Rev4 surface — floors raised to M2 achieved | 2026-08-01 |

### M2 infection run list (from explore map)

Scoped `--only-covered` + file/test filters per V-* §2.4 (not unscoped rbh sweeps):

| # | Config | Rev4 | Notes |
|---|---|---|---|
| 1 | `infection.t01-rsvp.json5` | C-19/20 | `class.Event.php` + EventRsvp |
| 2 | `infection.t09-player.json5` | C-21 | Player getters |
| 3 | `infection.t10-reports.json5` | C-22/27/30 | Report + Award |
| 4 | `infection.t08-admin.json5` | C-23/24 | `--filter=class.Administration.php` + ServerHealthStatsTest |
| 5 | `infection.t14-lib-live-weather.json5` | C-23/29 | Weather |
| 6 | `infection.t12-attendance.json5` | C-25 | Attendance reactivation |
| 7 | `infection.t-qualtest.json5` | C-26 | `--filter=class.QualTest.php` |
| 8 | `infection.t11-search.json5` | C-28 | SearchService KD sort |

Skip: t05-event (covered by t01), t07-park, t14-lib-auth-era.

### Baseline failures (M1 input)

1. `ReportDomainAuthTest::testGetAdminDashboardStatsRequiresGlobalAdmin` — Error: `ReportsFixture::firstParkId()` undefined
2. `AttendanceDatesTest::testDistinctDatesByKingdom` — expected date list, got `[]`
3. `AttendanceDatesTest::testDistinctDatesByPark` — expected date list, got `[]`
4. `OfficerDirectoryTest::testKingdomOfficerDirectory` — expected `'parks'`, got `'kingdoms'`
5. `SearchServiceTest::testOrkAdminRestrictedBypass` — array missing `100006752`

### M1 result log

| Metric | Value | Recorded |
|---|---|---|
| Branch tip | `7997bb30` | 2026-08-01 |
| PHPUnit | **GREEN** — Tests: 310, Assertions: 1115, Errors: 0, Failures: 0, Skipped: 2 (`build/batch5-m1-phpunit.log`) | 2026-08-01 |
| Line coverage (suite) | **25.24%** lines (6443/25529); methods 16.09% (136/845); classes 10.87% | 2026-08-01 |
| New PHP coverage | `SearchService::Player` session-clear line covered (100% of new executable stmts) | 2026-08-01 |
| Monotonic vs M0 | 25.24% ≥ remasured M0 25.14% (original log 25.40% not reproducible on this host/DB) | 2026-08-01 |

## Phase D reply posting

| File | Posted | Thread / comment URL |
|---|---|---|
| pending-replies/C-19.md | [ ] | |
| pending-replies/C-20.md | [ ] | |
| pending-replies/C-21.md | [ ] | |
| pending-replies/C-22.md | [ ] | |
| pending-replies/C-23.md | [ ] | |
| pending-replies/C-24.md | [ ] | |
| pending-replies/C-25.md | [ ] | |
| pending-replies/C-26.md | [ ] | |
| pending-replies/C-27.md | [ ] | |
| pending-replies/C-28.md | [ ] | |
| pending-replies/C-29.md | [ ] | |
| pending-replies/C-30.md | [ ] | |
| pending-replies/C-31.md | [ ] | |
| pending-replies/REVISION-4.md | [ ] | |

## Out of band (do not mix into Batch5 branches)

- `tools/fuzzy-validator/**` WIP (manifests, capture, setpoint) — stash / separate track
- Linux Phase A host install work

## Progress notes

- 2026-08-01: Plan synced locally. Linux Phase A skipped per operator. WT had Drive-sync reverts of C-21/C-22 auth in `class.Player.php` / `class.Report.php` (+ related); restored from HEAD for baseline.
- 2026-08-01: Fuzzy-validator WIP stashed as `stash@{0}` (`batch5: park fuzzy-validator WIP outside closeout`).
- 2026-08-01: M0 PHPUnit baseline RED (1 error / 4 failures). Coverage 25.40% lines. Handing off to M1.
- 2026-08-01: M1 cleared all five baseline failures. Suite green (310 / 1115). Coverage 25.24% ≥ remasured M0 25.14%. Fixes: `ReportsFixture::firstParkId`; C-22 auth session tokens in AttendanceDates/OfficerDirectory tests; `SearchService::Player` clears `$_SESSION['is_authorized_mundane_id']` before Token auth.
- 2026-08-01: M2 Infection closeout — 8/8 scoped configs PASS ≥15; floors raised monotonically (see `batch5-m2-infection-runlist.md`). Fixed `phpUnit.configDir` on t01/t08/t11/t12/t14. Prior R-* raise targets 46/47/51/~62 not fully reproduced; floors set to M2 achieved (or documented 17/55, 18/18, 40/40 where exceeded).
- 2026-08-01: M3 docs closeout — filled checklist Commit SHAs for C-28 (`ea4a6608`), C-29 (`ae4f0864`), and verified blanks C-01…C-18 from `FIX-PR492` history; corrected C-27 to tip-reachable `6834b1cd` (orphan twin `c9656250` not on HEAD). Mac Phase A skip already noted in plan. Docs-only; PHPUnit not re-run. Remaining: Phase D / M4 (pending-replies + push/mirror). Fuzzy-validator WIP stays stashed.

### M2 result log

| Metric | Value | Recorded |
|---|---|---|
| Branch | `fix-pr-492-batch5-m2-infection` | 2026-08-01 |
| Infection 8/8 | t01 58/58; t09 33/33; t10 25/25; t08 31/31; t14 23/23; t12 49/49; t-qualtest 70/70; t11 51/51 — all PASS | 2026-08-01 |
| Floors raised | t01 17/55; t09 33/33; t10 25/25; t08 18/18; t14 23/23; t12 49/49; t-qualtest 50/50; t11 40/40 | 2026-08-01 |
| PHPUnit | **GREEN** — Tests: 310, Assertions: 1115, Errors: 0, Failures: 0, Skipped: 2 (`build/batch5-m2-phpunit.log`) | 2026-08-01 |
| Line coverage (suite) | **25.32%** lines (6465/25529) ≥ M1 25.24%; methods 16.33%; classes 10.87% | 2026-08-01 |
| New PHP coverage | n/a (Infection configDir + floor bumps + docs only; no product PHP) | 2026-08-01 |
| Runlist | `batch5-m2-infection-runlist.md` | 2026-08-01 |
