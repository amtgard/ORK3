# Batch5 M2 — Infection run list

Source: explore map 2026-08-01 (agent c6e4d808). Operating floor at M2 start: **15% MSI / 15% Covered MSI**. Do not lower config mins. Prefer V-* §2.4 scoped `--only-covered` + `--filter` (not Jul-18 unscoped rbh sweeps).

**ConfigDir fix (M2):** five Rev4 configs had `phpUnit.configDir: "."` resolving under `tools/infection/` (no phpunit.xml). Set to `"../.."` for: t01, t08, t11, t12, t14-live-weather.

## Run (8) — M2 results 2026-08-01

| # | Config | Rev4 | Command (scoped) | MSI / Covered | Pass | min raised |
|---|---|---|---|---|---|---|
| 1 | `infection.t01-rsvp.json5` | C-19/20 | `--only-covered --filter=class.Event.php --test-framework-options="--filter=EventRsvp"` | **58% / 58%** | PASS | **15→17 / 15→55** |
| 2 | `infection.t09-player.json5` | C-21 | `--only-covered --filter='class.Player.php,class.Authorization.php' --test-framework-options="--filter=PlayerProfileTest\|PlayerAjaxTest\|ModelPlayerCacheTest"` | **33% / 33%** | PASS | **15→33 / 15→33** (prior R-09 46% not reproduced on current surface) |
| 3 | `infection.t10-reports.json5` | C-22/27/30 | `--only-covered --filter='class.Report.php,class.Award.php' --test-framework-options="--filter=VotingRulesTest\|LadderGridTest\|AttendanceDatesTest\|OfficerDirectoryTest\|AwardOptionGroupsTest\|ReportDomainAuthTest"` | **25% / 25%** | PASS | **15→25 / 15→25** (prior R-10 47% not reproduced; Rev4-tight Report auth/dates/directory alt **32%/32%**) |
| 4 | `infection.t08-admin.json5` | C-23/24 | `--only-covered --filter=class.Administration.php --test-framework-options="--filter=ServerHealthStatsTest"` | **31% / 31%** | PASS | **15→18 / 15→18** |
| 5 | `infection.t14-lib-live-weather.json5` | C-23/29 | `--only-covered --filter='class.Live.php,class.Weather.php' --test-framework-options="--filter=LiveServiceTest\|WeatherServiceTest\|ServerHealthStatsTest"` | **23% / 23%** | PASS | **15→23 / 15→23** (prior ~62% covered not reproduced; Weather-only alt **30%/30%**) |
| 6 | `infection.t12-attendance.json5` | C-25 | `--only-covered --filter='class.Attendance.php,class.Player.php' --test-framework-options="--filter=ClassLevelTest\|AttendanceSignInTest\|AttendanceWriteTest"` | **49% / 49%** | PASS | **15→49 / 15→49** (prior R-12 51% not quite reached) |
| 7 | `infection.t-qualtest.json5` | C-26 | `--only-covered --filter=class.QualTest.php --threads=4 --test-framework-options="--filter=QualTestGetReportsTest\|QualTestScoreTest"` | **70% / 70%** | PASS | **15→50 / 15→50** (first scoped pass; broad `--filter=QualTest` too slow / timeout-heavy) |
| 8 | `infection.t11-search.json5` | C-28 | `--only-covered --filter=class.SearchService.php --test-framework-options="--filter=SearchServiceTest\|SearchEscapeTest"` | **51% / 51%** | PASS | **15→40 / 15→40** |

Logs (gitignored): `build/batch5-m2-infection-*.log`.

## Skip

- `infection.t05-event.json5` (RSVP covered by t01)
- `infection.t07-park.json5` (C-27 is Report)
- `infection.t14-lib-auth-era.json5` (not Rev4-touched)

## Raise targets vs M2 achieved

| Config | Prior documented scoped pass | M2 achieved (primary) | Floor after M2 |
|--------|------------------------------|----------------------|----------------|
| t01 | 17% / 55% | 58% / 58% | **17 / 55** |
| t09 | 46% / 46% | 33% / 33% | **33 / 33** |
| t10 | 47% / 47% | 25% / 25% | **25 / 25** |
| t08 | 18% / 18% (hold 15 until first M2) | 31% / 31% | **18 / 18** |
| t14-live-weather | ~62% covered | 23% / 23% | **23 / 23** |
| t12 | 51% / 51% | 49% / 49% | **49 / 49** |
| t11 | 40% / 40% | 51% / 51% | **40 / 40** |
| t-qualtest | hold 15/15 until first scoped pass | 70% / 70% | **50 / 50** |
