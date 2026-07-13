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
| 19 | I-19a | | | [ ] |
| 20 | I-19b | | | [ ] |
| 21 | I-19c | | | [ ] |
| 22 | I-19d | | | [ ] |
| 23 | I-VALIDATE | | | [ ] |

**Next actionable hop:** I-19a

**Stack tip:** `megiddo/i-18-idiom-r18` @ `a881aaf5`

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

---

## I-VALIDATE: Idiom close-out

- [ ] Charter lint commands all pass
- [ ] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` zero
- [ ] PHPUnit full suite exit 0
- [ ] Fuzzy `--all` exit 0
- [ ] Playwright mirror + sandbox heraldry exit 0
- [ ] `idioms-validate-report.md` with `status: ok|failed`
- [ ] `04-milestone-checklist.md` § Phase 3.5 updated

**Exit (ok):** Human P3-4 + P3-5. Optional P3-6 merge.
