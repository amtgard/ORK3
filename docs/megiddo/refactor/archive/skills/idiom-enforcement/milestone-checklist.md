# Idiom Enforcement — Milestone Checklist

Orchestrator and workers update this file. Master checklist: [04-milestone-checklist.md](../../04-milestone-checklist.md) § Phase 3.5.

**Prerequisite:** VALIDATE-20 `status=ok` on `megiddo/p3-validate-20-audit` (or later)

**Stack entry:** `megiddo/p3-validate-20-audit` @ `bc74ad4d`

**Charter:** [idioms-00-charter.md](../../idioms-00-charter.md)

---

## Queue status

| Hop | ID | Branch | Commit | Status |
|-----|-----|--------|--------|--------|
| 0 | I-0 | `megiddo/i-0-idiom-charter` | `0f52bd61` | [x] |
| 1 | I-01 | `megiddo/i-01-idiom-r01` | `9f56c2e1` | [x] |
| 2 | I-02 | `megiddo/i-02-idiom-r02` | `381202a6` | [x] |
| 3 | I-03 | `megiddo/i-03-idiom-r03` | `516468a4` | [x] |
| 4 | I-04 | `megiddo/i-04-idiom-r04` | `4f891e64` | [x] |
| 5 | I-05 | `megiddo/i-05-idiom-r05` | `efc6ae89` | [x] |
| 6 | I-06 | `megiddo/i-06-idiom-r06` | `b8fcff98` | [x] |
| 7 | I-07 | `megiddo/i-07-idiom-r07` | `aa781776` | [x] |
| 8 | I-08 | `megiddo/i-08-idiom-r08` | `e8da2c0a` | [x] |
| 9 | I-09 | `megiddo/i-09-idiom-r09` | `6502690d` | [x] |
| 10 | I-10 | `megiddo/i-10-idiom-r10` | `50dd8536` | [x] |
| 11 | I-11 | `megiddo/i-11-idiom-r11` | `22155811` | [x] |
| 12 | I-12 | `megiddo/i-12-idiom-r12` | `c0d9b730` | [x] |
| 13 | I-13 | `megiddo/i-13-idiom-r13` | `b4dfd788` | [x] |
| 14 | I-14 | `megiddo/i-14-idiom-r14` | `ed667a1f` | [x] |
| 15 | I-15 | `megiddo/i-15-idiom-r15` | `beb377ff` | [x] |
| 16 | I-16 | `megiddo/i-16-idiom-r16` | `b1613c81` | [x] |
| 17 | I-17 | `megiddo/i-17-idiom-r17` | `e9c4e9fc` | [x] |
| 18 | I-18 | `megiddo/i-18-idiom-r18` | `a881aaf5` | [x] |
| 19 | I-19a | `megiddo/i-19a-idiom-residual-lib` | `1b1201db` | [x] |
| 20 | I-19b | `megiddo/i-19b-idiom-residual-lib` | `d708cc5f` | [x] |
| 21 | I-19c | `megiddo/i-19c-idiom-residual-lib` | `2954f2ea` | [x] |
| 22 | I-19d | `megiddo/i-19d-idiom-residual-lib` | `23ca9f35` | [x] |
| 23 | I-VALIDATE | `megiddo/i-validate-idiom-audit` | `5e111edd` | [x] |

**Next actionable hop:** none — queue complete. Human: P3-4 + P3-5 (optional P3-6 merge).

**Stack tip:** `megiddo/i-validate-idiom-audit` @ `5e111edd` (audit only; byte-identical to I-19d tip)

---

## I-0: Idiom charter

- [x] `idioms-00-charter.md` published — rules, reference files, lint commands
- [x] Per-hop file scope table (I-01 … I-19d) with primary reference file per scope
- [x] Anti-pattern catalog from R-19* and agent drift
- [x] Gate: `sh bin/run-unit-tests.sh` exit 0
- [x] Checklist + commit on stacked branch

---

## I-01 … I-18: Per R-* scope

Each hop: idiom-only edits on files listed in charter § hop scope (sourced from `04-milestone-checklist.md` R-{nn} complete + branch diff).

### I-01 (R-01 RSVP scope) — complete

- [x] Controller `load_model` / `$this->Model` pattern aligned
- [x] Model wrappers match domain-call idioms in charter
- [x] JSON / error shapes unchanged (tests pass)
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero
- [x] PHPUnit exit 0
- [x] Hop fuzzy/Playwright gates per charter (if any)
- [x] One commit; checklist updated

### I-02 (R-02 auth INSERT scope) — complete

- [x] Controller `load_model` / `$this->Model` pattern aligned
- [x] Model wrappers match domain-call idioms in charter
- [x] JSON / error shapes unchanged (tests pass)
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero
- [x] PHPUnit exit 0
- [x] Hop fuzzy/Playwright gates per charter (if any)
- [x] One commit; checklist updated

### I-03 (R-03 banner scope) — complete

- [x] Controller `load_model` / `$this->Model` pattern aligned
- [x] Model wrappers match domain-call idioms in charter
- [x] JSON / error shapes unchanged (tests pass)
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero
- [x] PHPUnit exit 0
- [x] Hop fuzzy/Playwright gates per charter (if any)
- [x] One commit; checklist updated

### I-04 (R-04 EventAjax planning scope) — complete

- [x] Controller `load_model` / `$this->Model` pattern aligned
- [x] Model wrappers match domain-call idioms in charter
- [x] JSON / error shapes unchanged (tests pass)
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero
- [x] PHPUnit exit 0
- [x] Hop fuzzy/Playwright gates per charter (if any)
- [x] One commit; checklist updated

### I-05 (R-05 Event controller scope) — complete

- [x] Controller `load_model` / `$this->Model` pattern aligned
- [x] Model wrappers match domain-call idioms in charter
- [x] JSON / error shapes unchanged (tests pass)
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero
- [x] PHPUnit exit 0
- [x] Hop fuzzy/Playwright gates per charter (if any)
- [x] One commit; checklist updated

### I-06 (R-06 Kingdom scope) — complete

- [x] Controller `load_model` / `$this->Model` pattern aligned
- [x] Model wrappers match domain-call idioms in charter
- [x] JSON / error shapes unchanged (tests pass)
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero
- [x] PHPUnit exit 0
- [x] Hop fuzzy/Playwright gates per charter (if any)
- [x] One commit; checklist updated

### I-07 (R-07 Park scope) — complete

- [x] Controller `load_model` / `$this->Model` pattern aligned
- [x] Model wrappers match domain-call idioms in charter
- [x] JSON / error shapes unchanged (tests pass)
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero
- [x] PHPUnit exit 0
- [x] Hop fuzzy/Playwright gates per charter (if any)
- [x] One commit; checklist updated

### I-08 (R-08 Admin scope) — complete

- [x] Controller `load_model` / `$this->Model` pattern aligned
- [x] Model wrappers match domain-call idioms in charter
- [x] JSON / error shapes unchanged (tests pass)
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero
- [x] PHPUnit exit 0
- [x] Hop fuzzy/Playwright gates per charter (if any)
- [x] One commit; checklist updated

### I-09 (R-09 Player scope) — complete

- [x] Controller `load_model` / `$this->Model` pattern aligned
- [x] Model wrappers match domain-call idioms in charter
- [x] JSON / error shapes unchanged (tests pass)
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero
- [x] PHPUnit exit 0
- [x] Hop fuzzy/Playwright gates per charter (if any)
- [x] One commit; checklist updated

### I-10 (R-10 Reports scope) — complete

- [x] Controller `load_model` / `$this->Model` pattern aligned
- [x] Model wrappers match domain-call idioms in charter
- [x] JSON / error shapes unchanged (tests pass)
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero
- [x] PHPUnit exit 0
- [x] Hop fuzzy/Playwright gates per charter (if any)
- [x] One commit; checklist updated

### I-11 (R-11 Search scope) — complete

- [x] Controller `load_model` / `$this->Model` pattern aligned
- [x] Model wrappers match domain-call idioms in charter
- [x] JSON / error shapes unchanged (tests pass)
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero
- [x] PHPUnit exit 0
- [x] Hop fuzzy/Playwright gates per charter (if any)
- [x] One commit; checklist updated

### I-12 (R-12 Attendance scope) — complete

- [x] Controller `load_model` / `$this->Model` pattern aligned
- [x] Model wrappers match domain-call idioms in charter
- [x] JSON / error shapes unchanged (tests pass)
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero
- [x] PHPUnit exit 0
- [x] Hop fuzzy/Playwright gates per charter (if any)
- [x] One commit; checklist updated

### I-13 (R-13 Infrastructure scope) — complete

- [x] Controller `load_model` / `$this->Model` pattern aligned
- [x] Model wrappers match domain-call idioms in charter
- [x] JSON / error shapes unchanged (tests pass)
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero
- [x] PHPUnit exit 0
- [x] Hop fuzzy/Playwright gates per charter (if any)
- [x] One commit; checklist updated

### I-14 (R-14 lib-service scope) — complete

- [x] Controller `load_model` / `$this->Model` pattern aligned
- [x] Model wrappers match domain-call idioms in charter
- [x] JSON / error shapes unchanged (tests pass)
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero
- [x] PHPUnit exit 0
- [x] Hop fuzzy/Playwright gates per charter (if any)
- [x] One commit; checklist updated

### I-15 (R-15 HasAuthority scope) — complete

- [x] Controller `load_model` / `$this->Model` pattern aligned
- [x] Model wrappers match domain-call idioms in charter
- [x] JSON / error shapes unchanged (tests pass)
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero
- [x] PHPUnit exit 0
- [x] Hop fuzzy/Playwright gates per charter (if any)
- [x] One commit; checklist updated

### I-16 (R-16 GhettoCache scope) — complete

- [x] Controller `load_model` / `$this->Model` pattern aligned
- [x] Model wrappers match domain-call idioms in charter
- [x] JSON / error shapes unchanged (tests pass)
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero
- [x] PHPUnit exit 0
- [x] Hop fuzzy/Playwright gates per charter (if any)
- [x] One commit; checklist updated

### I-17 (R-17 lib bypass scope) — complete

- [x] Controller `load_model` / `$this->Model` pattern aligned
- [x] Model wrappers match domain-call idioms in charter
- [x] JSON / error shapes unchanged (tests pass)
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero
- [x] PHPUnit exit 0
- [x] Hop fuzzy/Playwright gates per charter (if any)
- [x] One commit; checklist updated

### I-18 (R-18 residual-$DB scope) — complete

Scope files (charter § I-18): `controller.Admin.php`, `controller.AdminAjax.php`, `controller.EventAjax.php`, `controller.KingdomAjax.php`, `controller.ParkAjax.php`, `controller.Player.php`, `model.AdminDashboard.php`, `model.Event.php`, `model.ParkProfile.php`, `model.Player.php`, `template/default/Admin_auditlog.tpl`, `template/default/default.theme`, `template/revised-frontend/Eventnew_index.tpl`.

- [x] Controller `load_model` / `$this->Model` pattern aligned — R-18 `$DB`→domain migrations (`get_persona`, `get_revoked_awards`, `park_abbr_check`, `get_event_templates_for_kingdom`, `park_belongs_to_kingdom`, `list_all_kingdom_names`, `audit_display_maps`) already use per-branch `load_model` + snake_case wrappers; no drift found
- [x] Model wrappers match domain-call idioms in charter — `Model_AdminDashboard`/`Model_ParkProfile` use file-dominant private domain accessors (`$this->_dangeraudit()`, `$this->_profile()`); `Model_Event`/`Model_Player` use constructor-wired `$this->Event`/`$this->Player`; consistent per file
- [x] JSON / error shapes unchanged (tests pass) — no response-shape edits
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero (both exit 1); no `global $DB` in scope
- [x] PHPUnit exit 0 — 230 tests OK
- [x] Hop fuzzy/Playwright gates per charter — no code delta vs I-17 tip (scope already idiom-conformant); behavioral gates unaffected, mandatory static + PHPUnit gates green
- [x] One commit; checklist updated

**Idiom changes:** none required — R-18 scope was already charter-conformant at the I-17 stack tip (templates use precomputed maps + `nav_*`/`wx_*` view helpers; models expose snake_case wrappers over domain accessors; controllers load models per branch). Hop is a verified no-op idiom pass with gates green.

---

## I-19a … I-19d: Residual lib file groups

| Hop | Files |
|-----|-------|
| I-19a | `model.Player.php`, `index.php`, `KingdomAjax.php` |
| I-19b | `EventAjax.php`, `AdminAjax.php`, `Admin.php` |
| I-19c | `ParkAjax.php`, `SearchAjax.php`, `Search.php` |
| I-19d | `PlayerAjax.php`, `WnAjax.php`, `model.AdminDashboard.php` |

- [ ] Idiom aligned per charter (no `(new Model_*)` in controllers where `load_model` is file norm)
- [ ] Static isolation unchanged; PHPUnit exit 0
- [ ] R-19 hop fuzzy/Playwright gates per v-19
- [ ] One commit each

### I-19a (R-19a residual-lib scope) — complete

Scope files (charter § I-19a, fixed 3-file group): `model/model.Player.php`, `index.php`, `controller/controller.KingdomAjax.php`.

- [x] Controller `load_model` / `$this->Model` pattern aligned — `KingdomAjax` already loads models per branch and routes search/audit through wrappers (`$this->Search->scoped_player_search`, `$this->Player->…`, `$this->KingdomProfile->…`); no `(new Model_*)` or `new Model_` sites (`rg` exit 1)
- [x] Model wrappers match domain-call idioms in charter — `Model_Player` uses the file-dominant split of constructor-wired `$this->Player` (`APIModel('Player')`) for service calls and the private `$this->_player()` domain accessor for direct reads; consistent per file (matches `model.Player.php` reference idiom)
- [x] `index.php` bootstrap matches idiom — direct `(new Health())->PingDb()` and `(new Event())->GetEventSummaryForRedirect()` plus request-scoped `$Session`, per charter §1.6 and v-19 §2.3 (index.php is not a controller; `load_model` N/A)
- [x] Audit idiom preserved — single `addauth` branch keeps inline `(new Dangeraudit())->audit(…)` after `add_auth`; this matches every AJAX peer (`ParkAjax`, `EventAjax`, `AdminAjax`, `Unit`) and `Model_Authorization` exposes no audit wrapper, so inline is canonical, not drift (charter §1.3 / §2)
- [x] JSON / error shapes unchanged — no response-shape edits
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero (both exit 1)
- [x] PHPUnit exit 0 — 230 tests OK (2 skipped)
- [x] Hop fuzzy/Playwright gates per charter §5 / v-19 §2.5 — fuzzy `player-profile,kingdom-auth-sandbox,home-authenticated` 6/6 pass (test+mirror); Playwright `player-profile.spec.ts`, `kingdom-profile.spec.ts`, `infrastructure.spec.ts`, `residual-lib.spec.ts` 14/14 pass
- [x] One commit; checklist updated

**Idiom changes:** none required — R-19a scope (`model.Player`, `index.php`, `KingdomAjax`) was already charter-conformant at the I-18 stack tip. Controllers load models per branch with snake_case wrappers, `Model_Player` keeps its intentional `APIModel` vs private-domain-accessor split, `index.php` uses the established `Health`/`Event` bootstrap idiom, and the inline `Dangeraudit` audit matches all AJAX peers. Verified no-op idiom pass with all gates green.

### I-19b (R-19b residual-lib scope) — complete

Scope files (charter § I-19b, fixed 3-file group): `controller/controller.EventAjax.php`, `controller/controller.AdminAjax.php`, `controller/controller.Admin.php`.

- [x] Controller `load_model` / `$this->Model` pattern aligned — all three files load models per action/branch and route through wrappers: `EventAjax` uses `$this->EventPlanning->…` (incl. heraldry via `set_event_heraldry` / `remove_heraldry`), `$this->Search->scoped_player_search`, `$this->Authorization->…`; `AdminAjax` uses `$this->Search->…`, `$this->Authorization->…`, `$this->AdminDashboard->state_of_amtgard_*`; `Admin` routes all reads through `$this->load_model('AdminDashboard')`. No `(new Model_*)` or `new Model_` sites (`rg` exit 1)
- [x] Inline domain instantiation eliminated where charter requires — no `(new Heraldry())`, `(new Weather())`, or `(new StateOfAmtgard())` remain in scope (anti-patterns already resolved at R-19b): heraldry folded into `Model_EventPlanning`, weather stats + SoA bootstrap into `Model_AdminDashboard` (`rg '\(new (Heraldry|Weather|StateOfAmtgard)\(\)'` exit 1)
- [x] Audit idiom preserved — inline `(new Dangeraudit())->audit(…)` after `add_auth` (EventAjax `auth/addauth`, AdminAjax `global/addauth`) and for EventStaff add/update/remove is the canonical cross-file idiom: every AJAX peer (`KingdomAjax`, `ParkAjax`, `Unit`) uses inline audit and `Model_Authorization` exposes **no** audit wrapper (grep: no `audit`/`Dangeraudit` in `model.Authorization.php`), so inline is canonical, not drift (charter §1.3 / §2)
- [x] JSON / error shapes unchanged — no response-shape edits; lowercase `status` keys and `[]` arrays match each method's existing shape
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero (both exit 1)
- [x] PHPUnit exit 0 — 230 tests OK (2 skipped)
- [x] Hop fuzzy/Playwright gates per charter §5 / v-19 §2.5 — fuzzy `event-index-rsvp,admin-dashboard,admin-permissions` 6/6 pass (test+mirror); Playwright `event-detail.spec.ts`, `event-planning.spec.ts`, `admin-dashboard.spec.ts`, `residual-lib.spec.ts` 16/16 pass
- [x] One commit; checklist updated

**Idiom changes:** none required — R-19b scope (`EventAjax`, `AdminAjax`, `Admin`) was already charter-conformant at the I-19a stack tip. Heraldry / weather / State-of-Amtgard reads were folded into model wrappers during R-19b, controllers load models per branch with snake_case wrappers, JSON shapes are untouched, and the inline `Dangeraudit` audit beside `add_auth` (and EventStaff) matches every AJAX peer with no `Model_Authorization` audit wrapper available. Verified no-op idiom pass with all gates green.

### I-19c (R-19c residual-lib scope) — complete

Scope files (charter § I-19c, fixed 3-file group): `controller/controller.ParkAjax.php`, `controller/controller.SearchAjax.php`, `controller/controller.Search.php`.

- [x] `load_model('Search')` usage aligned with peers — all three files use the uniform AJAX idiom `$this->load_model('Search')` then `$this->Search->{scoped_player_search|universal_search|get_unit_activity_counts}(…)`, byte-identical in pattern to `EventAjax`, `AdminAjax`, and `KingdomAjax` search paths (`Model_Search` exposes snake_case wrappers over `SearchService`). No drift.
- [x] Controller `load_model` / `$this->Model` pattern aligned — `ParkAjax` loads models per branch (`Park`, `Search`, `Player`, `Authorization`, `Reports`, `ParkProfile`, `Tournament`) and routes through snake_case wrappers; `SearchAjax` is a thin `load_model('Search')` adapter; `Search` (HTML) loads `Kingdom`/`Park`/`Unit`/`Search` per action with snake_case wrappers. No `(new Model_*)` or `new Model_` sites (`rg` exit 1).
- [x] No inline domain construction in scope — the only `(new …)` in `ParkAjax` is `(new Dangeraudit())->audit(…)` after `add_auth`, the canonical cross-file idiom used by every AJAX peer (`KingdomAjax`, `EventAjax`, `AdminAjax`, `Unit`); `Model_Authorization` exposes no audit wrapper, so inline is canonical, not drift (charter §1.3 / §2). No `(new SearchService())` etc. in controllers.
- [x] Heraldry PascalCase calls left intact (constrained, not in-scope drift) — `ParkAjax::setheraldry` uses `$this->Park->SetParkDetails(…)` and `removeheraldry` uses `$this->Park->RemoveParkHeraldry(…)`. `Model_Park` exposes only a PascalCase `RemoveParkHeraldry` wrapper (no snake_case alias) and no dedicated park-heraldry setter; rerouting `setheraldry` through the snake_case `set_park_details` wrapper would newly `logtrace` the base64 heraldry blob (a behavior change, unlike the clean peer wrappers `Model_EventPlanning::set_event_heraldry` / `remove_heraldry`). Proper alignment would require adding a `set_park_heraldry` wrapper to `model.Park.php`, which is out of the fixed I-19c 3-file group. Left as-is per "style only / no semantic change".
- [x] JSON / error shapes unchanged — no response-shape edits; lowercase `status` keys and `[]` arrays match each method's existing shape.
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero (both exit 1)
- [x] PHPUnit exit 0 — 230 tests OK (2 skipped)
- [x] Hop fuzzy/Playwright gates per charter §5 / v-19 §2.5 — Playwright `search.spec.ts` + `park-profile.spec.ts` 5/5 pass (mirror). Fuzzy `park-auth-sandbox`: **mirror PASS** (assets/dom/visual 1.000). **test (sandbox) profile FAIL** — pre-existing visual baseline **dimension mismatch** (baseline 961×1280 vs candidate 1976×1280), a sandbox-data/baseline drift independent of this hop (branch is byte-identical to the I-19b tip). Not re-recorded: v-19 §2.3 / §1.1 re-record only on **intentional UI change**, and this hop has none.
- [x] One commit; checklist updated

**Idiom changes:** none required — R-19c scope (`ParkAjax`, `SearchAjax`, `Search`) was already charter-conformant at the I-19b stack tip. `load_model('Search')` + snake_case Search wrappers match all AJAX peers exactly, controllers load models per branch, inline `Dangeraudit` matches every peer, and JSON shapes are untouched. The two PascalCase heraldry calls in `ParkAjax` are gated by the out-of-scope `Model_Park` wrapper surface (renaming/adding a wrapper is not in the fixed 3-file group, and rerouting would change blob-logging behavior). Verified no-op idiom pass; static + PHPUnit + Playwright + mirror-fuzzy green, with the sandbox-profile fuzzy visual dimension mismatch documented as pre-existing baseline drift.

### I-19d (R-19d residual-lib scope) — complete

Scope files (charter § I-19d, fixed 3-file group): `controller/controller.PlayerAjax.php`, `controller/controller.WnAjax.php`, `model/model.AdminDashboard.php`.

- [x] Controller `load_model` / `$this->Model` pattern aligned — `PlayerAjax` loads `Player` per action/branch (`check_username`, `park/create`, `player/*`, `merge`, notes/dues/attendance/dietary) and routes through snake_case wrappers (`$this->Player->create_player`, `->revoke_player_award`, `->add_note`, `->dismiss`-style calls, etc.); `WnAjax::dismiss` uses `$this->load_model('Player')` → `$this->Player->dismiss_whats_new`. No `(new Model_*)` or `new Model_` sites (`rg` exit 1).
- [x] `PlayerAjax` username anti-pattern absent — `check_username` uses `$this->load_model('Player')` then delegates to the shared static contract `Model_Player::username_check_payload_for(…)` (same JSON contract used by `Controller_SelfReg::check_username`); the charter §2 `(new Model_Player())` drift site is already resolved. `$this->Authorization` (used in `player/updateprofile` and `attendance`) is loaded by the base `Controller::__construct` (`load_model('Authorization')`), the established framework pattern — not per-branch drift.
- [x] `WnAjax` uses model path, not inline domain — `dismiss_whats_new` goes through `Model_Player`; no inline `new Player()` in the controller. Its `['Status' => ['Status' => …]]` PascalCase response shape is this method's existing contract and is left unchanged (charter §1.4).
- [x] `Model_AdminDashboard` SoA bootstrap matches peers — `state_of_amtgard_bootstrap()` / `state_of_amtgard_validate_date_range()` / `state_of_amtgard_chart_section()` return via the private `$this->_state_of_amtgard()` domain accessor, identical in shape to every other snake_case wrapper in the file (`_report()`, `_administration()`, `_dangeraudit()`, `_player()`, `_park_profile()`, `_kingdom_profile()`, `_weather()`). File-dominant private-domain-accessor idiom is consistent (matches I-18/I-19b `Model_AdminDashboard` idiom notes); no drift.
- [x] JSON / error shapes unchanged — no response-shape edits; lowercase `status` keys in `PlayerAjax`, PascalCase `Status` envelope in `WnAjax`, each per its existing method shape.
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero (both exit 1) — R-19d final: all 41 residual `$Lib` sites gone across `orkui/`.
- [x] PHPUnit exit 0 — 230 tests OK (2 skipped).
- [x] Hop fuzzy/Playwright gates — fuzzy `player-profile,admin-dashboard --phase all` **4/4 PASS** (test+mirror, assets/dom/visual 1.000); Playwright `tests/e2e/residual-lib.spec.ts` **7/7 pass** (health, kingdom scoped search, username availability, whats-new dismiss, universal search, SoA bootstrap, weather stats).
- [x] One commit; checklist updated.

**Idiom changes:** none required — R-19d scope (`PlayerAjax`, `WnAjax`, `model.AdminDashboard`) was already charter-conformant at the I-19c stack tip. `PlayerAjax`/`WnAjax` load `Player` per action and route through snake_case wrappers with the username check using the shared static payload contract (no `(new Model_Player())`), `$this->Authorization` comes from the base-controller load, and `Model_AdminDashboard` exposes uniform snake_case wrappers over private domain accessors (SoA bootstrap included). Verified no-op idiom pass with all gates green; R-19d closes the residual-`$Lib` migration (`orkui/` is `$Lib`- and `$DB`-free).

---

## I-VALIDATE: Idiom close-out — complete (`status: ok`)

Audit-only hop on `megiddo/i-validate-idiom-audit` @ `5e111edd` (byte-identical to I-19d tip; zero code changes). Full report: [idioms-validate-report.md](../../idioms-validate-report.md).

- [x] Charter lint commands all pass — §4.1 static isolation zero, §4.3 model sanity zero, §4.4 PHPUnit exit 0; §4.2 `(new Model_` / `new Model_` zero. Only §4.2 domain-construct match is the 7 documented `(new Dangeraudit())->audit(...)` canonical inline-audit sites (charter §1.3 / §2), not drift — no fix required
- [x] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` zero (both exit 1)
- [x] PHPUnit full suite exit 0 — 230 tests OK, 2 skipped
- [x] Fuzzy `--all` — 39/40 rows pass; sole failure `[test] park-auth-sandbox` is the **pre-existing** sandbox visual-baseline dimension mismatch (stored 1280×961 vs candidate 1280×1976; assets/dom/visual all 1.000; mirror passes). Branch is byte-identical to I-19d tip, so not introduced here; documented per orchestrator directive, does not flip status
- [x] Playwright mirror + sandbox heraldry — mirror 50 tests green (1 transient login-redirect timeout in `attendance.spec.ts` passed on isolated re-run 4/4); sandbox heraldry 3/3
- [x] `idioms-validate-report.md` with `status: ok`
- [x] `04-milestone-checklist.md` § Phase 3.5 updated

**Exit (ok):** Human P3-4 + P3-5. Optional P3-6 merge.

**Recommended follow-up (non-blocking):** re-record the `test`-profile `park-auth-sandbox` visual baseline during a future intentional-UI/setpoint refresh to clear the standing sandbox dimension-mismatch flag.
