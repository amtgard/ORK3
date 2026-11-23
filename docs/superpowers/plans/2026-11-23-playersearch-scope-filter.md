# Player Search Scope Filter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A filter icon + modal + `KD:PK` pill inside the shared `OrkPlayerSearch` component, scope-aware so it can only narrow a field, used by every eligible player-search field and the header Universal Search.

**Architecture:** Server gains explicit `filterKingdomId`/`filterParkId` options that AND with each field's hard scope (fixing the existing prefix-escapes-scope hole), plus a scope-aware options/resolve endpoint for the modal and the typed-prefix pill. The client UI lives only in `ork-player-search.js`/`.css`; `attach()` uses it internally and the header search calls its exported `OrkPlayerSearch.filterField()`.

**Tech Stack:** PHP 8 (yapo/YapoMysql), PHPUnit integration tests, vanilla JS (no jQuery UI), FontAwesome 7.

**Spec:** `docs/superpowers/specs/2026-11-23-playersearch-scope-filter-design.md`

## Global Constraints
- SQL only in `system/lib/ork3/`; controllers reach it through `orkui/model/model.Search.php`.
- `mysql_real_escape_string()` is a no-op — cast every id `(int)`; abbreviations regex-validated `^[A-Za-z0-9]{2,3}$`.
- `db->clear()` before every raw query; `DataSet`/query results need `->Next()`.
- Tooltip copy exactly: `Filtering to {kingdom name} - {park name}`; kingdom-only: `Filtering to {kingdom name}`. Use `data-tip`, never `title`.
- Pill label: `KABBR:PABBR` or `KABBR`.
- Modal defaults: Kingdom "All kingdoms"; Park "All parks" (disabled until a kingdom is chosen).
- No native `alert/confirm/prompt`. Dark mode via `html[data-theme="dark"]`. Works at 375px width.
- One implementation only — no filter UI code outside `ork-player-search.js`/`.css`.
- Never stage `system/lib/ork3/class.Authorization.php`; never `git add -A`; `git diff --cached --stat` before commit.
- PHPUnit: `vendor/bin/phpunit --filter SearchServiceTest` (integration suite, needs Docker DB).

## Review Focus
1. **Typed prefix in a hard-scoped field** (`restrictTo:'park'` + `q='XX:YY name'`): must return only the field's park — Task 1 test `testPrefixCannotEscapeRestrictToPark`.
2. **Park filter whose park is not in the filter kingdom / not in scope:** returns `[]`, never ignores the filter — Task 1 `testFilterParkOutsideKingdomReturnsEmpty`.
3. **Header search outside-click handler** (`default.theme` `click.searchDropdown`) clears/collapses on any click outside `#nav-search-wrap` — the modal must not trigger it — Task 4 manual check step.
4. **Filter modal opened from a field already inside a modal** (kn/pk award, recs, officer dialogs): must stack above host modal and return focus to the input — Task 3 + Task 6 browser step.
5. **Pill + `reattach()`** on fields whose scope changes (e.g. bonus credit, Admin_index): a pill no longer within the new scope must clear — Task 3 test page check.

---

### Task 1: Server — filter options AND with hard scope

**Files:**
- Modify: `system/lib/ork3/class.SearchService.php` (`RankedPlayers` ~L905–1030, `UniversalSearch` ~L1418+)
- Modify: `orkui/controller/controller.SearchAjax.php` (`players()`, `universal()`)
- Test: `tests/Integration/SearchServiceTest.php`

**Interfaces:**
- Produces: `RankedPlayers` options `filterKingdomId` (int), `filterParkId` (int). `UniversalSearch` request keys `FilterKid`, `FilterPid`. HTTP params on `SearchAjax/players`: `filterKingdomId`, `filterParkId`; on `SearchAjax/universal`: `fk`, `fp`.

- [ ] **Step 1: Write failing tests** in `SearchServiceTest`:
  - `testPrefixCannotEscapeRestrictToPark`: player P in park A, persona X; second park B in same kingdom with player Q persona X-ish (use fixture `createPlayer($parkB, ...)` with same persona stem). `RankedPlayers(['q'=>"{$k}:{$pB} {$stem}", 'parkId'=>$parkA, 'restrictTo'=>'park'])` → every row `ParkId === $parkA`; Q not present.
  - `testExplicitFilterNarrowsWithinKingdomScope`: `restrictTo=>'kingdom'`, `kingdomId=>$kid`, `filterParkId=>$parkA` → all rows `ParkId === $parkA`.
  - `testFilterOutsideRestrictScopeReturnsEmpty`: `restrictTo=>'kingdom'`, `kingdomId=>$kid`, `filterKingdomId=>$otherKid` (fixture second kingdom outside family) → `[]`.
  - `testFilterParkOutsideKingdomReturnsEmpty`: `filterKingdomId=>$kid`, `filterParkId=>` a park in another kingdom → `[]`.
  - `testUniversalSearchHonorsFilter`: `UniversalSearch(['Query'=>$persona,'FilterPid'=>$parkB])` → all `players[*]` from park B (compare via `RankedPlayers` row ids or add park id to assert).
  Add fixture helpers to `tests/Support` SearchFixture only if missing (e.g. `kingdomOutsideFamily($kid)`).
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter SearchServiceTest` — new tests FAIL.
- [ ] **Step 3: Implement.** Read `filterKingdomId`/`filterParkId` (int). Merge with prefix: explicit params win over the `q` prefix; if both a prefix and explicit params exist, the prefix text is still stripped from the search term. If `filterParkId>0`, look up its kingdom; if `filterKingdomId>0` and it differs → `return []`. Replace the `if/elseif` chain at ~L1005 with independent conditions: hard scope (`restrict_kids` / `restrictTo park|kingdom`) always applied, filter conditions additionally appended. In `UniversalSearch`, pass `FilterKid/FilterPid` through as `filterKingdomId/filterParkId`. Controller: read and `(int)` the new params.
- [ ] **Step 4: Run** `vendor/bin/phpunit --filter SearchServiceTest` — all PASS (including pre-existing `testMagicSearchAbbrevPrefix`, `testRankedPlayersKdPrefixOrdersActiveBeforeInactive`).
- [ ] **Step 5: Curl check** (logged-in test cookie jar per memory `reference_local_curl_auth_session`): `SearchAjax/players&restrictTo=park&parkId=<A>&q=<K>:<B>%20<stem>` returns only park A rows.
- [ ] **Step 6: Commit** `Bugfix: player search prefix/filter can only narrow a field's scope`.

### Task 2: Server — scope-aware filter options + prefix resolve

**Files:**
- Modify: `system/lib/ork3/class.SearchService.php` (new public methods)
- Modify: `orkui/model/model.Search.php`
- Modify: `orkui/controller/controller.SearchAjax.php` (new actions `filteroptions`, `filterresolve`)
- Test: `tests/Integration/SearchServiceTest.php`

**Interfaces:**
- Consumes: scope shape `['parkId'=>int,'kingdomId'=>int,'restrictTo'=>''|'park'|'kingdom','restrictKingdomIds'=>int[],'excludeKingdomId'=>int,'excludeParkId'=>int]`.
- Produces:
  - `SearchService::FilterScopeKingdoms(array $scope): array` → `[['id'=>int,'name'=>string,'abbr'=>string], ...]` sorted by name; `[]` when `restrictTo==='park'`. Allowed set: `restrictKingdomIds` if given; else family of `kingdomId` if `restrictTo==='kingdom'`; else all active kingdoms. Minus `excludeKingdomId`.
  - `SearchService::FilterScopeParks(int $kingdomId, array $scope): array` → `[['id','name','abbr'], ...]` active parks; `[]` if the kingdom is not allowed; minus `excludeParkId`.
  - `SearchService::ResolveFilterPrefix(string $prefix, array $scope): ?array` — `$prefix` like `KCG:AR`, `KCG:`, `KCG:*`, `KCG`; returns `['kingdomId','kingdomName','kingdomAbbr','parkId'(0),'parkName'(''),'parkAbbr'('')]` or `null` if unknown/out of scope.
  - Model: `filter_scope_kingdoms`, `filter_scope_parks`, `resolve_filter_prefix` (thin delegates).
  - HTTP (public, no login needed; names are public data): `SearchAjax/filteroptions&kingdomId=&parkId=&restrictTo=&restrictKingdomIds=&excludeKingdomId=&excludeParkId=[&forKingdom=N]` → `{kingdoms:[...]}` or, with `forKingdom`, `{parks:[...]}`. `SearchAjax/filterresolve&prefix=KCG:AR&<scope params>` → `{match:{...}|null}`.
- [ ] **Step 1: Failing tests:** `testFilterScopeKingdomsRestrictedToFamily` (restrictTo kingdom → ids ⊆ family), `testFilterScopeKingdomsEmptyForParkLocked`, `testFilterScopeParksRejectsOutOfScopeKingdom`, `testResolveFilterPrefixKnownAndUnknown` (valid `K:P` → ids/names; `ZZZ:QQ` → null; valid kingdom outside `restrictKingdomIds` → null; `K:*` → park 0).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** (reuse `resolveAbbrevPrefix`'s lookup SQL; regex-validate abbrs; `(int)` ids). Controller parses scope params with the same `$csvIds` helper as `players()` (hoist it to a private method).
- [ ] **Step 4: Run** — PASS. Curl both endpoints once.
- [ ] **Step 5: Commit** `Enhancement: scope-aware player search filter options endpoint`.

### Task 3: Component — filter icon, pill, modal, prefix detection

**Files:**
- Modify: `orkui/template/default/script/ork-player-search.js`
- Modify: `orkui/template/default/style/ork-player-search.css`

**Interfaces:**
- Consumes: Task 1 params, Task 2 endpoints.
- Produces:
  - `attach()` opt `filter` (default `true`; `false` = no UI). Filter auto-hidden when `restrictTo==='park'` or `FilterScopeKingdoms` is empty.
  - `OrkPlayerSearch.filterField(input, { uir, scope, onChange(filter|null) }) → { get(): filter|null, set(filter|null), destroy() }` where `filter = {kingdomId, kingdomName, kingdomAbbr, parkId, parkName, parkAbbr}`. `attach()` calls this internally and appends `&filterKingdomId=&filterParkId=` in `buildUrl` and re-runs the search on change.
  - `reattach()` with a new scope re-validates the current pill via `filterresolve`; clears it if `null`.
- [ ] **Step 1: Markup/positioning.** Wrap nothing: insert the icon button and pill as absolutely positioned siblings in a zero-size `position:relative` anchor placed immediately before the input (or reuse the input's offsetParent); set the input's `padding-left` to the pill width and `padding-right` to the icon width. Icon: `<button type="button" class="ops-filter-btn" aria-label="Filter by kingdom or park" data-tip="Filter by kingdom or park"><i class="fas fa-filter"></i></button>`; active state when a pill is set.
- [ ] **Step 2: Pill.** `<span class="ops-filter-pill" data-tip="Filtering to …">KCG:AR <button class="ops-filter-pill-x" aria-label="Remove filter">×</button></span>`.
- [ ] **Step 3: Typed prefix.** On `input`, if value matches `/^([A-Za-z0-9]{2,3}):([A-Za-z0-9]{2,3}|\*)?\s/` or `/^([A-Za-z0-9]{2,3}):\s/`, call `filterresolve`; on match set the pill and strip the prefix from the value (keep caret at the end of the remaining text); on `null` leave the text alone. Debounce with the existing timer; abort stale requests.
- [ ] **Step 4: Backspace.** `keydown` Backspace with `selectionStart===0 && selectionEnd===0` and a pill set → clear pill, set `value = pillLabel + value` with caret after the label, `preventDefault`. × button clears the pill (no text restore) and focuses the input.
- [ ] **Step 5: Modal.** One shared in-page modal (`.ops-filter-modal`, appended to `body`, z-index above `ork-player-search` dropdown and host modals), Kingdom `<select>` from `filteroptions`, Park `<select>` loaded on change via `forKingdom`; Apply / Clear / Cancel; Esc closes; focus trap; returns focus to the input; pre-selects the current pill. Cache kingdom lists per scope key for the page lifetime.
- [ ] **Step 6: Styles** incl. `html[data-theme="dark"]` variants and ≤480px (modal full-width with 16px gutter; tap targets ≥36px).
- [ ] **Step 7: Verify** on a scratch page or an existing page (Kingdom profile → Awards → recipient field): pill via modal, via typing `KCG:AR `, tooltip text exact, backspace breaks to text, ×, results narrow (network shows `filterKingdomId`/`filterParkId`), dark mode computed styles, 375px width via iframe harness.
- [ ] **Step 8: Commit** `Enhancement: player search filter pill and kingdom/park filter modal`.

### Task 4: Header Universal Search uses the component filter

**Files:**
- Modify: `orkui/template/default/default.theme` (nav search block ~L475–480, script ~L618–785)

**Interfaces:**
- Consumes: `OrkPlayerSearch.filterField()` (Task 3), `fk`/`fp` on `SearchAjax/universal` (Task 1).

- [ ] **Step 1:** `var nsFilter = OrkPlayerSearch.filterField(document.getElementById('UniversalSearch'), { uir: nsUir, scope: {}, onChange: function(){ re-run nsSearch if term ≥2 } })`. `nsSearch` appends `&fk=&fp=` from `nsFilter.get()`. No filter UI code in this file.
- [ ] **Step 2:** Exempt the modal from the outside-click collapse: `click.searchDropdown` handler ignores targets inside `.ops-filter-modal`; the outside-click clear also clears the pill (`nsFilter.set(null)`) to match clearing the text.
- [ ] **Step 3: Verify** in Chrome: header search with the pill shows only that kingdom/park's players; parks/kingdoms/units unaffected; modal interactions don't collapse the header; mobile collapsed-nav layout still fits.
- [ ] **Step 4: Commit** `Enhancement: header search kingdom/park filter pill`.

### Task 5: Call-site sweep (per-surface decisions)

**Files:** call sites listed in the spec's "Per-surface decisions". Off list → add `filter: false`:
- `default/Attendance_kingdom.tpl:533`, `Attendance_park.tpl:770`, `Attendance_event.tpl:88`
- `default/Admin_moveplayer.tpl:63`, `Admin_mergeplayer.tpl:66,79`, `Admin_suspendplayer.tpl:33` (PlayerName only; `Suspendator` keeps filter)
- `revised-frontend/Admin_index.tpl:1052,1101,1114`
- `revised-frontend/script/revised.js`: `pk-att-player-name` (~11102), event attendance `evPnInput` (~8856), `kn-moveplayer-player-name` (~14758), pk move player (~15257), pk merge keep/remove (~15455/15474, park-locked — auto-hidden but set explicitly)
- `default/Reports_roster.tpl` `sp-player-text` (~816; check its initial attach too)

- [ ] **Step 1:** Apply the `filter:false` edits. Confirm with `grep -rn "filter: false" orkui/template | wc -l` matching the list.
- [ ] **Step 2:** Spot-check one "narrowed" surface (kn officer assign ~5390): modal lists only the kingdom family.
- [ ] **Step 3: Commit** `Enhancement: opt attendance and admin-picker surfaces out of the player search filter`.

### Task 6: Browser verification pass

- [ ] Chrome (dedicated test account, new tab; serial): header search, Kingdom profile award recipient (inside modal), Park recs, Unit member add, bonus credit (reattach), one Off surface (no icon), one narrowed surface. Light + dark, 375px via iframe harness. Record findings; fix inside the owning task's files.
- [ ] Run `vendor/bin/phpunit --filter SearchServiceTest` and `bash tests/playersearch/ranking.sh` — both green.
