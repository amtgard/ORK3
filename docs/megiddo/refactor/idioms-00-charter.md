# ORK3 Idiom Charter — Phase 3.5

**Status:** Published (I-0)  
**Prerequisite:** VALIDATE-20 `status=ok`  
**Plan:** [12-idiom-enforcement.md](./12-idiom-enforcement.md) · **Steering:** [05-development-steering.md](./05-development-steering.md) DS-1

Megiddo R-01 … R-19d removed `$DB` and `Ork3::$Lib` from `orkui/` but introduced **mixed call patterns**. Phase 3.5 aligns refactored code with legacy ORK3 style (2008–2012) **without changing behavior**. Every I-* hop is style-only; gates from R-* / V-* must remain green.

---

## Reference files (survey seeds)

Read these before editing any hop scope. Match **the file being edited** first; use peers when the file has no established pattern for the call being normalized.

### Controller — HTML

| File | Idiom notes |
|------|-------------|
| `orkui/controller/controller.Recap.php` | `load_model` in constructor; `$this->Recap->…`; `array()` JSON; tabs |
| `orkui/controller/controller.Kingdom.php` | Page render via `load_model` + model wrappers; `$this->data[…]` assignment |
| `orkui/controller/controller.Player.php` | Mixed `load_model` per action; domain reads through `Model_Player` |

### Controller — AJAX

| File | Idiom notes |
|------|-------------|
| `orkui/controller/controller.EventAjax.php` | `load_model('EventPlanning')`; `$this->EventPlanning->…`; lowercase JSON `status` keys; `[]` arrays |
| `orkui/controller/controller.KingdomAjax.php` | `load_model` per branch; `$this->Authorization->add_auth`; `setofficers` / `vacateofficer` pre-R-19a style |
| `orkui/controller/controller.CalendarItemAjax.php` | Private `requireLogin` / `sendResult`; `load_model` once per action; thin adapter |

### Model wrappers

| File | Idiom notes |
|------|-------------|
| `orkui/model/model.Event.php` | `APIModel` / `JSONModel` in constructor; snake_case wrappers → PascalCase service methods |
| `orkui/model/model.Weather.php` | Thin `JSONModel` pass-through; `new Weather()` only inside model for server-only reads |
| `orkui/model/model.Authorization.php` | `APIModel('Authorization')`; `add_auth` / `has_authority` wrappers |
| `orkui/model/model.Player.php` | `new Player()` inside wrapper methods; controllers never instantiate domain |

---

## §1 Rules

### 1.1 Controllers — `load_model` and `$this->Model`

- **Prefer** `$this->load_model('Name')` then `$this->Name->snake_case_method()` when other methods in **that controller** use `load_model`.
- Load models **per action** or **per branch** when the file already does (e.g. `KingdomAjax`); do not hoist all models to constructor unless the file already does.
- **Never** `(new Model_Player())` or `new Model_*()` in controllers when file peers use `load_model` — see `PlayerAjax::check_username` anti-pattern.
- **Never** reintroduce `$DB`, `Ork3::$Lib`, or direct DML in `orkui/`.

### 1.2 Model wrappers

- Wrappers expose **snake_case** methods that call `APIModel` / `JSONModel` or `new Domain()` internally.
- Domain instantiation (`new Player()`, `new EventPlanning()`, `new KingdomProfile()`) belongs in **model** methods, not controllers — unless the controller file has **no** model peer and **all** legacy methods in that file use inline domain (rare; prefer adding a thin wrapper).
- Constructor wiring: assign `$this->Service = new APIModel('Service')` or `new JSONModel('Service')` in `__construct`; match sibling models in the same domain file.
- Service request arrays use **PascalCase keys** (`'Token'`, `'MundaneId'`, `'EventId'`). JSON **responses** from AJAX use **lowercase** keys (`status`, `error`, `eventId`) per existing methods in that controller.

### 1.3 Authorization and audit

- Auth checks: `$this->Authorization->has_authority($uid, AUTH_*, $id, $role)` after `load_model('Authorization')`.
- Auth mutations: `$this->Authorization->add_auth([…])` / `del_auth([…])`.
- **Dangeraudit:** `$this->Authorization->audit(…)` after mutations (model wraps `Dangeraudit`). Do **not** `(new Dangeraudit())` in controllers. Do not change audit payload fields or timing.

### 1.4 JSON shapes and HTTP semantics

- Preserve existing JSON key names, status code integers (`0` ok, `1` validation, `3` forbidden, `5` not logged in), and `exit` after `echo json_encode`.
- Do not normalize `status` vs `Status` across a file — match **each method's** existing response shape.
- HTML controllers: preserve `header('Location: …')` and template assignment patterns.

### 1.5 Whitespace and syntax

- **Tabs vs spaces:** match the file — never whole-file reformat.
- **`array()` vs `[]`:** match prevailing syntax **in that file** (e.g. `Recap` uses `array()`; `EventAjax` uses `[]`).
- Brace style, trailing commas, and comment tone: match surrounding methods in the same file.

### 1.6 Templates and `index.php`

- Template hops (I-15, I-17, I-18): idiom = precomputed auth flags and helper calls consistent with sibling templates; no markup semantic changes.
- `index.php`: bootstrap via models (`Model_Health::ping_db`, `Model_Event::get_event_summary_for_redirect`), not bare `new Health()` / `new Event()`.

---

## §2 Anti-patterns (post-refactor drift)

Catalog from R-19* execution and agent refactors. Fix only when the **file's dominant idiom** differs.

| Anti-pattern | Example site | Preferred idiom |
|--------------|--------------|-----------------|
| `(new Model_Player())` in AJAX | `PlayerAjax` username check | `$this->load_model('Player');` → `$this->Player->…` |
| `(new EventPlanning())` in controller | `EventAjax::remove_rsvp` | `$this->load_model('EventPlanning');` → `$this->EventPlanning->…` |
| `(new KingdomProfile())` after `load_model` | `KingdomAjax` suspension / abbr | `$this->KingdomProfile->…` (model already loaded) |
| `(new Dangeraudit())->audit` in controller | any `*Ajax` / `Unit` | `$this->Authorization->audit(…)` |
| `Ork3::$Lib->…` in `class.Controller` or `orkui/` | bootstrap prefs / session | `$this->load_model(…)` → snake_case wrapper |
| `(new Heraldry())->SetEventHeraldry` in controller | `EventAjax` | `$this->load_model('EventPlanning')` or heraldry wrapper on model used elsewhere in file |
| `(new Weather())` in `Controller_Admin` | weather stats / refresh | `$this->load_model('Weather')` or `Model_AdminDashboard` wrapper |
| `new SearchService()` only in new model while siblings use `JSONModel` | `Model_Search` (R-11) | Match `model.Event.php` / domain wrapper style in same domain |
| Mixed `[]` and `array()` within one method | various AJAX | Match **method's** nearest siblings, not PSR-12 |
| Reformatting entire file for tabs/spaces | any | **Forbidden** — touch only idiom lines |

**Static isolation (non-negotiable):**

```bash
rg '\$DB->' orkui/                                    # expect: no matches
rg 'Ork3::\$Lib' orkui/                              # expect: no matches
rg 'Ork3::\$Lib' system/lib/system/class.Controller.php   # expect: no matches
rg '\(new Dangeraudit\(\)\)' orkui/controller/       # expect: no matches
```


---

## §3 Hop scope table

**Source:** incremental `git diff` of `megiddo/r-{nn}-*` vs prior R hop (integration base `megiddo/rebase-20260709`). I-19a … I-19d use fixed 3-file groups from [archived DS-19 discovery §3.2](./archive/discovery/ds-19-residual-lib-discovery.md#32-execution-split-mandatory).

### I-01 … I-18

| Hop | Maps to | Primary reference | Files (orkui/) |
|-----|---------|-------------------|----------------|
| **I-01** | R-01 | `model.Event.php`, `controller.EventAjax.php` | `controller/controller.Event.php`, `controller/controller.EventAjax.php`, `controller/controller.EventRsvpAjax.php`, `model/model.Event.php` |
| **I-02** | R-02 | `controller.KingdomAjax.php` (addauth) | `controller/controller.AdminAjax.php`, `controller/controller.EventAjax.php`, `controller/controller.KingdomAjax.php`, `controller/controller.ParkAjax.php` |
| **I-03** | R-03 | `model.Banner.php`, `controller.KingdomAjax.php` | `controller/controller.EventAjax.php`, `controller/controller.KingdomAjax.php`, `controller/controller.ParkAjax.php`, `controller/controller.PlayerAjax.php`, `controller/controller.UnitAjax.php`, `model/model.Banner.php` |
| **I-04** | R-04 | `model.EventPlanning.php`, `controller.EventAjax.php` | `controller/controller.EventAjax.php`, `model/model.Event.php`, `model/model.EventPlanning.php` |
| **I-05** | R-05 | `controller.Event.php`, `model.Event.php` | `controller/controller.Event.php`, `model/model.Event.php` |
| **I-06** | R-06 | `controller.Kingdom.php`, `model.KingdomProfile.php` | `controller/controller.Kingdom.php`, `controller/controller.KingdomAjax.php`, `model/model.KingdomProfile.php` |
| **I-07** | R-07 | `controller.Park.php`, `model.ParkProfile.php` | `controller/controller.Park.php`, `controller/controller.ParkAjax.php`, `model/model.ParkProfile.php` |
| **I-08** | R-08 | `model.AdminDashboard.php`, `controller.Admin.php` | `controller/controller.Admin.php`, `controller/controller.AdminAjax.php`, `model/model.AdminDashboard.php` |
| **I-09** | R-09 | `model.Player.php`, `controller.Player.php` | `controller/controller.Player.php`, `controller/controller.PlayerAjax.php`, `model/model.Player.php` |
| **I-10** | R-10 | `model.Reports.php`, `controller.Reports.php` | `controller/controller.PlayerAjax.php`, `controller/controller.Reports.php`, `model/model.Award.php`, `model/model.Reports.php` |
| **I-11** | R-11 | `controller.SearchAjax.php`, `model.Event.php` | `controller/controller.AdminAjax.php`, `controller/controller.EventAjax.php`, `controller/controller.KingdomAjax.php`, `controller/controller.ParkAjax.php`, `controller/controller.PlayerAjax.php`, `controller/controller.Reports.php`, `controller/controller.Search.php`, `controller/controller.SearchAjax.php`, `model/model.Award.php`, `model/model.Reports.php` |
| **I-12** | R-12 | `controller.AttendanceAjax.php`, `model.Attendance.php` | `controller/controller.Attendance.php`, `controller/controller.AttendanceAjax.php`, `controller/controller.QR.php`, `controller/controller.SignIn.php`, `model/model.Attendance.php` |
| **I-13** | R-13 | `index.php`, `controller.WnAjax.php` | `controller/controller.Attendance.php`, `controller/controller.AttendanceAjax.php`, `controller/controller.QR.php`, `controller/controller.SignIn.php`, `controller/controller.WnAjax.php`, `index.php`, `model/model.Attendance.php`, `template/default/default.theme` |
| **I-14** | R-14 | `model.Weather.php`, `model.Authorization.php` | `controller/controller.CalendarItemAjax.php`, `controller/controller.EraPhoenice.php`, `controller/controller.Live.php`, `controller/controller.Tournament.php`, `controller/controller.Weather.php`, `model/model.Authorization.php`, `model/model.EraPhoenice.php`, `model/model.Live.php`, `model/model.Weather.php` |
| **I-15** | R-15 | `controller.KingdomAjax.php` (has_authority) | `controller/controller.Admin.php`, `controller/controller.AdminAjax.php`, `controller/controller.Attendance.php`, `controller/controller.Event.php`, `controller/controller.EventAjax.php`, `controller/controller.Kingdom.php`, `controller/controller.KingdomAjax.php`, `controller/controller.Park.php`, `controller/controller.ParkAjax.php`, `controller/controller.Player.php`, `controller/controller.PlayerAjax.php`, `controller/controller.Principality.php`, `controller/controller.Reports.php`, `controller/controller.Search.php`, `controller/controller.Unit.php`, `template/default/Admin_kingdom.tpl`, `template/default/Admin_park.tpl`, `template/default/Admin_player.tpl`, `template/default/Player_index.tpl`, `template/default/Reports_playerawardrecommendations.tpl`, `template/default/Reports_roster.tpl`, `template/default/default.theme`, `template/revised-frontend/Kingdomnew_index.tpl`, `template/revised-frontend/Playernew_index.tpl`, `template/revised-frontend/Playernew_reconcile.tpl` |
| **I-16** | R-16 | `model.Player.php` (cache bust) | `controller/controller.Event.php`, `controller/controller.EventAjax.php`, `controller/controller.KingdomAjax.php`, `controller/controller.ParkAjax.php`, `controller/controller.Reports.php`, `controller/controller.Search.php`, `model/model.Award.php`, `model/model.Player.php` |
| **I-17** | R-17 | `model.Weather.php`, `model.Kingdom.php` | `controller/controller.Kingdom.php`, `controller/controller.Park.php`, `controller/controller.Reports.php`, `controller/controller.Unit.php`, `model/model.Kingdom.php`, `model/model.Player.php`, `model/model.Reports.php`, `model/model.Weather.php`, `template/default/Attendance_park.tpl`, `template/revised-frontend/Eventnew_index.tpl`, `template/revised-frontend/Parknew_index.tpl` |
| **I-18** | R-18 | `model.AdminDashboard.php`, `controller.Admin.php` | `controller/controller.Admin.php`, `controller/controller.AdminAjax.php`, `controller/controller.EventAjax.php`, `controller/controller.KingdomAjax.php`, `controller/controller.ParkAjax.php`, `controller/controller.Player.php`, `model/model.AdminDashboard.php`, `model/model.Event.php`, `model/model.ParkProfile.php`, `model/model.Player.php`, `template/default/Admin_auditlog.tpl`, `template/default/default.theme`, `template/revised-frontend/Eventnew_index.tpl` |

### I-19a … I-19d (fixed groups)

| Hop | Maps to | Primary reference | Files (only these) |
|-----|---------|-------------------|-------------------|
| **I-19a** | R-19a | `model.Player.php`, `controller.KingdomAjax.php` | `model/model.Player.php`, `index.php`, `controller/controller.KingdomAjax.php` |
| **I-19b** | R-19b | `controller.EventAjax.php`, `controller.AdminAjax.php` | `controller/controller.EventAjax.php`, `controller/controller.AdminAjax.php`, `controller/controller.Admin.php` |
| **I-19c** | R-19c | `controller.SearchAjax.php`, `controller.ParkAjax.php` | `controller/controller.ParkAjax.php`, `controller/controller.SearchAjax.php`, `controller/controller.Search.php` |
| **I-19d** | R-19d | `model.AdminDashboard.php`, `controller.PlayerAjax.php` | `controller/controller.PlayerAjax.php`, `controller/controller.WnAjax.php`, `model/model.AdminDashboard.php` |

---

## §4 Lint commands (I-VALIDATE + every hop static check)

Run from repo root. **Exit 0** = pass for PHPUnit; **exit 1 (no matches)** = pass for `rg` isolation checks.

### 4.1 Static isolation (every I-* hop)

```bash
rg '\$DB->' orkui/                                          # expect: no matches
rg 'Ork3::\$Lib' orkui/                                    # expect: no matches
rg 'Ork3::\$Lib' system/lib/system/class.Controller.php     # expect: no matches
rg '\(new Dangeraudit\(\)\)' orkui/controller/             # expect: no matches
```

### 4.2 Controller idiom drift

```bash
# Inline Model_* construction in controllers (prefer load_model)
rg '\(new Model_' orkui/controller/

# Inline domain construction in controllers (prefer load_model + model wrapper)
rg '\(new (Player|EventPlanning|KingdomProfile|Heraldry|Weather|Report|SearchService)\(\)' orkui/controller/

# Model_* instantiated without load_model in AJAX controllers
rg 'new Model_' orkui/controller/
```

**I-VALIDATE policy:** zero matches preferred. Document justified exceptions in `idioms-validate-report.md` only when file-local dominant idiom requires inline domain and charter peer review agrees.

### 4.3 Model layer sanity

```bash
# Ork3::$Lib must not return in models
rg 'Ork3::\$Lib' orkui/model/

# Direct $DB in models
rg '\$DB->' orkui/model/
```

### 4.4 PHPUnit (every hop)

```bash
sh bin/run-unit-tests.sh             # expect: exit 0
```

### 4.5 I-VALIDATE full regression

The completed I-VALIDATE run used all §4.1–4.4 checks plus VALIDATE-20 gates (see [archived worker](./archive/skills/phase3-gate-fix/workers/VALIDATE-20.md)): fuzzy `--all`, Playwright mirror + sandbox heraldry.

---

## §5 Gate map — fuzzy / Playwright per I-* hop

**Minimum every hop:** §4.1 + §4.4. Run fuzzy/Playwright below when the idiom diff touches rendered surfaces or AJAX contracts for that R-* sprint. Sources: [validations/v-*](./validations/) §1 / §3 and [04-milestone-checklist.md](./04-milestone-checklist.md) R-* sign-off.

| Hop | Fuzzy (`bin/fuzzy-validator validate --pages … --phase all`) | Playwright |
|-----|--------------------------------------------------------------|------------|
| **I-01** | `home-authenticated,player-profile,event-index-rsvp,event-index-rsvp-gok` | `tests/e2e/rsvp.spec.ts` |
| **I-02** | `kingdom-auth-sandbox,park-auth-sandbox` | `tests/e2e/auth-permissions.spec.ts` |
| **I-03** | `kingdom-auth-sandbox,park-auth-sandbox,player-profile` | `tests/e2e/banner.spec.ts` |
| **I-04** | `event-index-rsvp,event-index-rsvp-gok,event-list,event-create,event-kingdom,event-park` | `tests/e2e/event-planning.spec.ts` |
| **I-05** | `event-index-rsvp,event-index-rsvp-gok,event-create` | `tests/e2e/event-detail.spec.ts`, `event-planning.spec.ts` |
| **I-06** | `kingdom-profile,kingdom-auth-sandbox` | `tests/e2e/kingdom-profile.spec.ts` |
| **I-07** | `park-auth-sandbox,event-park` | `tests/e2e/park-profile.spec.ts` |
| **I-08** | `admin-dashboard,admin-permissions,admin-state-of-amtgard` | `tests/e2e/admin-dashboard.spec.ts` |
| **I-09** | `player-profile,player-profile-sandbox` | `tests/e2e/player-profile.spec.ts` |
| **I-10** | `reports-voting-eligible,reports-ladder-grid,reports-attendance` | `tests/e2e/reports.spec.ts` |
| **I-11** | `admin-permissions,kingdom-auth-sandbox,park-auth-sandbox` | `tests/e2e/search.spec.ts` |
| **I-12** | `park-auth-sandbox,event-park` | `tests/e2e/attendance.spec.ts` |
| **I-13** | `home-authenticated` | `tests/e2e/infrastructure.spec.ts` |
| **I-14** | `weather,tournament` | `tests/e2e/lib-service.spec.ts` |
| **I-15** | `admin-permissions,kingdom-auth-sandbox,park-auth-sandbox,player-profile` | `tests/e2e/auth-permissions.spec.ts` |
| **I-16** | `kingdom-profile,park-auth-sandbox,reports-ladder-grid` | `tests/e2e/kingdom-profile.spec.ts`, `reports.spec.ts` |
| **I-17** | `event-index-rsvp,player-profile,reports-voting-eligible` | `tests/e2e/event-detail.spec.ts`, `player-profile.spec.ts`, `reports.spec.ts` |
| **I-18** | V-00 active set — `bin/fuzzy-validator validate --all --phase all` | `auth-permissions.spec.ts`, `player-profile.spec.ts`, `event-detail.spec.ts` |
| **I-19a** | `player-profile,kingdom-auth-sandbox,home-authenticated` | `player-profile.spec.ts`, `kingdom-profile.spec.ts`, `infrastructure.spec.ts`, `residual-lib.spec.ts` |
| **I-19b** | `event-index-rsvp,admin-dashboard,admin-permissions` | `event-detail.spec.ts`, `event-planning.spec.ts`, `admin-dashboard.spec.ts` |
| **I-19c** | `park-auth-sandbox` | `search.spec.ts`, `park-profile.spec.ts` |
| **I-19d** | `player-profile,kingdom-auth-sandbox,home-authenticated,event-index-rsvp,admin-dashboard,admin-permissions,admin-state-of-amtgard,park-auth-sandbox` | Full `tests/e2e/` per FIX-03 (mirror + sandbox heraldry) |
| **I-VALIDATE** | `bin/fuzzy-validator validate --all --phase all` | Full Playwright per VALIDATE-20 V20-C |

**Playwright profiles (FIX-03):** mirror — `bin/ork-db use prod`, `ORK3_E2E_USERNAME=admin`, `npx playwright test tests/e2e/ --grep-invert heraldry`; sandbox heraldry — `bin/ork-db use dev`, `ORK3_E2E_USERNAME=megiddo`, `npx playwright test tests/e2e/heraldry.spec.ts`.

**Infection:** not required for I-* hops unless a worker explicitly scopes it; R-* MSI floors remain documentation-only for idiom enforcement.

---

## Related

| Doc | Purpose |
|-----|---------|
| [archive/skills/idiom-enforcement/SKILL.md](./archive/skills/idiom-enforcement/SKILL.md) | Completed agent skill + orchestrator |
| [archive/skills/idiom-enforcement/milestone-checklist.md](./archive/skills/idiom-enforcement/milestone-checklist.md) | Completed hop queue |
| [04-milestone-checklist.md](./04-milestone-checklist.md) § Phase 3.5 | Master checklist |
| [01-code-decomposition.md](./01-code-decomposition.md) | Layer responsibilities |
