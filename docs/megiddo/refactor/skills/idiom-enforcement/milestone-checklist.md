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
| 2 | I-02 | `megiddo/i-02-idiom-r02` | `883fb3bb` | [x] |
| 3 | I-03 | | | [ ] |
| 4 | I-04 | | | [ ] |
| 5 | I-05 | | | [ ] |
| 6 | I-06 | | | [ ] |
| 7 | I-07 | | | [ ] |
| 8 | I-08 | | | [ ] |
| 9 | I-09 | | | [ ] |
| 10 | I-10 | | | [ ] |
| 11 | I-11 | | | [ ] |
| 12 | I-12 | | | [ ] |
| 13 | I-13 | | | [ ] |
| 14 | I-14 | | | [ ] |
| 15 | I-15 | | | [ ] |
| 16 | I-16 | | | [ ] |
| 17 | I-17 | | | [ ] |
| 18 | I-18 | | | [ ] |
| 19 | I-19a | | | [ ] |
| 20 | I-19b | | | [ ] |
| 21 | I-19c | | | [ ] |
| 22 | I-19d | | | [ ] |
| 23 | I-VALIDATE | | | [ ] |

**Next actionable hop:** I-03

**Stack tip:** `megiddo/i-02-idiom-r02` @ `883fb3bb`

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

### I-03 … I-18 (remaining)

- [ ] Controller `load_model` / `$this->Model` pattern aligned
- [ ] Model wrappers match domain-call idioms in charter
- [ ] JSON / error shapes unchanged (tests pass)
- [ ] `rg '$DB->' orkui/` + `rg 'Ork3::$Lib' orkui/` still zero
- [ ] PHPUnit exit 0
- [ ] Hop fuzzy/Playwright gates per charter (if any)
- [ ] One commit; checklist updated

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
