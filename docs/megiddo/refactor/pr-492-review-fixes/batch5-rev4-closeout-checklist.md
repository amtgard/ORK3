# PR #492 Batch 5 Rev4 — Mac closeout checklist

Plan: [`docs/megiddo/plans/pr492_batch5_rev4_348561df.plan.md`](../../plans/pr492_batch5_rev4_348561df.plan.md)  
Base branch: `fix-pr-492` @ `8a68493d` (tip ahead of `origin/fix-pr-492`)  
PR: https://github.com/amtgard/ORK3/pull/492

## Scope on this Mac host

| Plan phase | Status | Notes |
|---|---|---|
| A Host tool prerequisites (Linux) | **SKIPPED** | Linux-only preamble; Mac already has PHP 8.4, Docker, vendor→`~/.cache/ork3/vendor`, `gh` auth |
| B Local stack bring-up | [x] | `ork3db`/`ork3testdb`/`ork3archivedb` Up; drift-check PASS |
| C Prove Rev4 (PHPUnit) | [ ] | Full `sh bin/run-unit-tests.sh` green required |
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
| M0 | `fix-pr-492-batch5-m0-baseline` | Restore Drive-corrupted WT to HEAD; isolate fuzzy-validator WIP; record baseline numbers | [x] RED recorded | baseline 25.40% lines | config minMsi 15 | `0dd41871` | [x] |
| M1 | `fix-pr-492-batch5-m1-phpunit` | Fix any Rev4 regressions until full suite green; raise tests if needed | [ ] | ≥95% new / ≥ M0 | ≥ M0 | | [ ] |
| M2 | `fix-pr-492-batch5-m2-infection` | Rev4-touched infection configs green; raise floors monotonically where evidence supports | [ ] | ≥ M1 | ≥ M1 | | [ ] |
| M3 | `fix-pr-492-batch5-m3-docs` | Checklist SHA fill (C-28/C-29), plan Mac-skip note, Batch5 checklist sync | [ ] | n/a docs | n/a | | [ ] |
| M4 | `fix-pr-492-batch5-m4-pr-hygiene` | Post pending-replies + REVISION-4; push `fix-pr-492` + mirror `megiddo/fuzzy-validator-v2` | [ ] | n/a | n/a | | [ ] |

Note: Git ref names use hyphens (`fix-pr-492-batch5-m*`), not nested `fix-pr-492/...`, because branch `fix-pr-492` already exists.

## Baseline log (fill during M0)

| Metric | Value | Recorded |
|---|---|---|
| HEAD SHA | `8a68493d` | 2026-08-01 |
| PHPUnit | **RED** — Tests: 310, Errors: 1, Failures: 4, Skipped: 2 (`build/batch5-m0-phpunit.log`) | 2026-08-01 |
| Line coverage (suite) | **25.40%** lines (6485/25528); methods 15.98%; classes 10.87% | 2026-08-01 |
| Infection configs run | deferred to M2 (explore mapping in flight) | |
| Operating MSI floor (min observed pass / config min) | config floor **15** (`tools/infection/*.json5`); several prior logs below floor — do not lower | 2026-08-01 |
| Operating Covered MSI floor | config floor **15** | 2026-08-01 |

### Baseline failures (M1 input)

1. `ReportDomainAuthTest::testGetAdminDashboardStatsRequiresGlobalAdmin` — Error: `ReportsFixture::firstParkId()` undefined
2. `AttendanceDatesTest::testDistinctDatesByKingdom` — expected date list, got `[]`
3. `AttendanceDatesTest::testDistinctDatesByPark` — expected date list, got `[]`
4. `OfficerDirectoryTest::testKingdomOfficerDirectory` — expected `'parks'`, got `'kingdoms'`
5. `SearchServiceTest::testOrkAdminRestrictedBypass` — array missing `100006752`

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
