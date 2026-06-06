# Include Principality in Statistics — implementation contract

Date: 2026-06-03. This is the SHARED CONTRACT for the workflow agents. Every agent reads this and follows the helper signatures + file ownership EXACTLY so the surfaces integrate.

## Goal
A per-kingdom admin toggle **"Include Principality in Statistics"** (default OFF). When ON, every kingdom-scoped statistic/graph/report/list for that kingdom folds in its active child principalities' data (attendance, player counts, awards, recs, units, events, map). Independently (NOT gated by the toggle): principality parks always appear in parks dropdowns, and principality members are always included in kingdom-scoped player search. Plus a set of legit bugfixes.

## Storage (mirror existing `AwardRecsPublic` exactly)
Kingdom settings are EAV rows in `ork_configuration` (`type='Kingdom'`, `id=kingdom_id`, `key`, `value` = JSON string, `user_setting=1`, `var_type='fixed'`). NEW key: **`IncludePrincipalityInStatistics`**, default value `'0'`.
- Backfill migration for existing kingdoms (mirror `db-migrations/2026-03-06-add-award-recs-public-config.sql` AND the follow-up `2026-03-20-fix-award-recs-public-user-setting.sql` — set `user_setting=1` from the start). Read those two files and mirror.
- New-kingdom seed: mirror the `AwardRecsPublic` seed in `CreateKingdom` (`class.Kingdom.php` ~342-352) for the new key, default `'0'`.
- Generic save path already handles it: `setconfig` (controller.KingdomAjax.php:117-143) → `SetKingdomDetails` (class.Kingdom.php:493-534) → `Common::update_config`. NO new endpoint.

## HELPER CONTRACT — implemented in `system/lib/ork3/class.Kingdom.php` (Agent KINGDOM)
All return `array` of int kingdom ids. Callers build IN-lists via `implode(',', array_map('intval', $ids))`.
```php
// Active child-principality kingdom ids of $kingdomId ([] if none). Uses the same
// criteria as GetPrincipalities (ork_kingdom.parent_kingdom_id = $kingdomId AND active='Active').
public function GetChildPrincipalityIds($kingdomId)        // => int[]

// [$kingdomId, ...child principality ids] — ALWAYS includes children.
// Structural scoping (parks dropdown, player search) uses THIS.
public function GetFamilyKingdomIds($kingdomId)            // => int[]

// True iff the IncludePrincipalityInStatistics config flag for $kingdomId is '1'.
public function StatsIncludesPrincipalities($kingdomId)    // => bool

// GetFamilyKingdomIds($kingdomId) when StatsIncludesPrincipalities is true AND
// there are children; otherwise [$kingdomId]. STATS/REPORT scoping uses THIS.
public function GetStatsKingdomIds($kingdomId)             // => int[]
```
Reachable everywhere as `Ork3::$Lib->kingdom->GetStatsKingdomIds($kid)` and via `$this->Kingdom->...` in controllers. A principality (no children) and a childless kingdom both resolve to `[$kid]`, so normal behavior is unchanged — only a parent-with-children-and-flag-on expands.

## Conversion pattern for surfaces
Replace a hardcoded `<col>.kingdom_id = '<X>'` (or `= <X>`) with `<col>.kingdom_id IN (<list>)` where `<list> = implode(',', GetStatsKingdomIds($KingdomId))` for STATS/reports, or `GetFamilyKingdomIds($KingdomId)` for the always-on structural surfaces. Keep existing dedup logic (DISTINCT-by-week/month/player) — it dedupes across the family automatically because the IN-list widens the park set. Where an `ORDER BY ... CASE WHEN <col>.kingdom_id = <X>` pins the home kingdom, keep that pin on the original `<X>` (so the kingdom's own rows still sort first).

## FILE OWNERSHIP (no two agents touch the same file)

### Phase 1 — Foundation + independent bugfixes (parallel)
- **MIG** — NEW file `db-migrations/2026-06-03-add-include-principality-in-statistics-config.sql` (backfill all kingdoms, user_setting=1, var_type='fixed', value '0'). Then APPLY it locally: `docker exec -i ork3-php8-db mariadb -u root -proot ork < <file>`. Verify rows exist.
- **KINGDOM** — `system/lib/ork3/class.Kingdom.php`: implement the 4 helpers above; seed the new config in `CreateKingdom` (mirror AwardRecsPublic); BUGFIX GetParks (a) support an optional `KingdomIds` array param → `WHERE p.kingdom_id IN (...)` (default keeps single-`KingdomId` behavior), (b) remove the dead `is_principality`/`parent_park_id` references (columns don't exist; `ParentParkId`/`ParentOf` always null — drop them); BUGFIX `SetKingdomParent` add cycle prevention (reject if the proposed parent is the kingdom itself OR a descendant of it — walk the parent chain to detect a cycle); BUGFIX `GetPrincipalities` return `Success()` with empty `Principalities` array when none found (not `InvalidParameter`).
- **AUTH** — `system/lib/ork3/class.Authorization.php`: add a cycle/depth guard to the `parent_kingdom_id` recursion in `HasAuthority` (~830-840) — pass a visited-set or a depth cap so a cyclic `parent_kingdom_id` cannot infinite-recurse. Touch ONLY the traversal block. (NOTE: this file has a local login bypass on lines ~327/330 — DO NOT touch those lines or anything else; only the HasAuthority parent-traversal.)
- **COMMON** — `system/lib/ork3/common.php`: remove the dead/broken principality branch in `create_officers` (~506-512) that tests the undefined `$for_principality` and misattributes officers to the parent. Principalities are correctly seeded via the standard `create_officers($id,0)` path; just delete the broken branch (keep the normal officer seeding intact).
- **MODELPRINZ** — `orkui/model/model.Principality.php`: fix `get_officers`/`set_officers` (~38-54) to call the correct Principality lib methods (`GetPrincipalityOfficers`/`SetPrincipalityOfficer`) OR map `PrincipalityId`→`KingdomId`, so they operate on the right id instead of 0.
- **AWARD** — `system/lib/ork3/class.Award.php` (+ `class.Player.php` if needed): `LookupAward` (~36-45) returns a kingdomaward id even when `find()` fails — add an existence guard so a missing row returns an invalid/zero id; and in `Player::AddAward` (~1742/1779) do not save an award with a zero/invalid `kingdomaward_id` (return InvalidParameter). Prevents orphaned grants.

### Phase 2 — Surfaces (parallel, after Phase 1; build against the helper contract above)
- **REPORT** — `system/lib/ork3/class.Report.php`: for EVERY kingdom-scoped report/stat method, swap the hardcoded `kingdom_id = $request[KingdomId]` filter to `kingdom_id IN (` + `implode(',', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId']))` + `)`. Methods/anchors (verify each): GetKingdomParkAverages (~1452,1457), GetKingdomParkMonthlyAverages (~1495), GetPlayerRoster (~1304,1357), GetActivePlayers (~1801), AttendanceSummary (~954), GetDistinctPlayerStats (~1669), GetMonthlyChartData (~1715), the `_Recap*` helpers (~3838-4096), ClassMasters (~153), PlayerAwards (~278), CrownQualed (~223,238,242), PlayerAwardRecommendations (~441), peerage/knight reports (~362,695), Guilds, UnitSummary (~820,884). Preserve dedup + the home-kingdom ORDER BY pin. Methods that take a `PrincipalityId` already resolve to `[self]` via the helper — leave their behavior intact. Do NOT change non-kingdom-scoped methods.
- **CTRLKINGDOM** — `orkui/controller/controller.Kingdom.php`: (1) `park_averages_json`/`park_monthly_json` raw SQL (`p.kingdom_id = {$kid}` at ~96,117,133,156 + trend queries) → use `GetStatsKingdomIds($kid)` IN-list so the hero stat cards + footer totals roll up when the flag is on; (2) Events queries (`e.kingdom_id = {$kid}` at ~220,256,488,519,738) → `GetStatsKingdomIds`; (3) the "Parks (N)" hero count: when the flag is on, reflect the family park count; (4) compute `$this->data['HasChildPrincipalities'] = (empty($IsPrinz) && count(child principalities) > 0)` near line 422; (5) when building `$this->data['AdminConfig']` (~645-651), DROP the `IncludePrincipalityInStatistics` row when `!HasChildPrincipalities` so the toggle only shows for kingdoms that have principalities.
- **CTRLAJAX** — `orkui/controller/controller.KingdomAjax.php`: (1) `playersearch` (~836) — replace `AND m.kingdom_id = {$kid}` with `AND m.kingdom_id IN (` + `implode(',', GetFamilyKingdomIds($kid))` + `)` (ALWAYS family, not toggle-gated); keep the ORDER BY home-kingdom pin on `$kid`. (2) `getparks` dropdown action (~593-600) — return family parks (ALWAYS): call `GetParks(['KingdomIds'=>GetFamilyKingdomIds($kid)])` (the new param from Agent KINGDOM) or union per-id. Do NOT change `getkingdoms`.
- **ADMINUI** — `orkui/template/revised-frontend/script/revised.js`: in `buildConfig()` (~4486-4576) add a branch for `cfg.Key === 'IncludePrincipalityInStatistics'` rendering a checkbox whose `.value` is `'1'`/`'0'` (so the existing `wireConfig` collector + `setconfig` persist it unchanged); set `inp.dataset.configId = cfg.ConfigurationId`. Add the help text to the `keyHints` map (~4500-4505) keyed `'IncludePrincipalityInStatistics'`: "When looking at statistics, graphs, and reports, include relevant metrics from Principality(ies) such as attendance and player counts." (renders as the existing `.kn-cfg-hint` `(?)` data-hint tooltip — dark-mode safe; do NOT use native title=). Add a human label "Include Principality in Statistics".

### Phase 3 — Verify
Lint every changed PHP file (`docker exec ork3-php8-app php -l <path>`), `node --check` revised.js, confirm the migration applied, and report any method the REPORT agent could not convert.

## Out of scope / notes
- Don't merge principality parks into the kingdom's MAIN park tile list — they keep their separate "Principalities" sections (already built); the toggle only affects AGGREGATE stats/reports/counts.
- Empty promote/demote stubs in class.Principality.php and the retire-cascade are NOT in this pass.
- class.Authorization.php cannot be committed via the pre-commit hook; that's handled separately by the human — agents just edit it.
