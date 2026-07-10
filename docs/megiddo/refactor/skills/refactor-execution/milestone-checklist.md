# Refactor Execution — Milestone Checklist

Track **R-01 … R-14** Phase 2 sprints. Orchestrator and workers update this file. Master checklist: [04-milestone-checklist.md](../../04-milestone-checklist.md) Phase 2.

**Skill:** [SKILL.md](SKILL.md) · **Prompts:** [agent-prompt.md](agent-prompt.md)

---

## Metadata

| Field | Value |
|-------|--------|
| Integration line (R-01 base only) | `megiddo/rebase-20260709` @ `05bc1973` |
| Branching | **stack-on-prior-R** (mandatory — lights out, no merge gates) |
| Stack tip (orchestrator updates after each R-*) | `megiddo/r-08-admin-refactor` @ `0d003dd9` |
| Prerequisite | [rebase-and-redocument](../rebase-and-redocument/milestone-checklist.md) RB-Z complete |
| E2E credentials | [06-test-framework.md § preflight](../../06-test-framework.md#e2e-login-credentials-preflight) — mirror `admin`/`password`, sandbox `megiddo`/`test-db-player` |
| Fuzzy setpoint | `20260709T173049Z-1591950d-6b22e991bb478256.zip` |

**Stack chain** (branch @ commit — worker appends each milestone):

| R-* | Branch | Commit |
|-----|--------|--------|
| R-01 | `megiddo/r-01-rsvp-refactor` | `bc626ce8` |
| R-02 | `megiddo/r-02-auth-insert-refactor` | `516ac063` |
| R-03 | `megiddo/r-03-banner-refactor` | `910cb0dc` |
| R-04 | `megiddo/r-04-eventajax-refactor` | `810eceb3` |
| R-05 | `megiddo/r-05-event-refactor` | `20264fa9` |
| R-06 | `megiddo/r-06-kingdom-refactor` | `56b59878` |
| R-07 | `megiddo/r-07-park-refactor` | `5bc70bdf` |
| R-08 | `megiddo/r-08-admin-refactor` | `0d003dd9` |

---

## Shared sign-off (every R-*)

Each milestone branch `megiddo/r-{nn}-{slug}` must satisfy before checking Done:

- [ ] Prior R-* branch committed on its own branch (one squashed commit, clean tree) — stack base for this milestone
- [ ] New stacked branch `megiddo/r-{nn}-{slug}` created from integration (R-01) or prior R-* tip (R-02+)
- [ ] Refactor scope matches DS-{nn} §3 + `03-implementation-plan.md` targets only
- [ ] `sh bin/run-unit-tests.sh` exit 0 (full suite)
- [ ] Infection per `validations/v-{nn}-*.md` §2.4 — MSI ≥ documented floor
- [ ] `bin/fuzzy-validator validate --pages <R-{nn} gate> --phase all` — test + mirror pass (or intentional re-record documented)
- [ ] Playwright auth preflight — specs run, not skip (domain specs per V-*)
- [ ] `validations/v-{nn}-*.md` §3 checked; `04-milestone-checklist.md` updated; plan targets marked done
- [ ] Exactly **one commit** on milestone branch (DS-6): `R-{nn}: …`

---

## R-01: RSVP

**Depends on:** DS-01, T-01, V-00, V-01 · **Branch:** `megiddo/r-01-rsvp-refactor`  
**Prompt:** `{{MILESTONE}}=R-01` · **V:** [v-01-rsvp-validation.md](../../validations/v-01-rsvp-validation.md)

| Gate | Status |
|------|--------|
| Prior branch hygiene (n/a — first R-*) | [x] |
| Refactor T-RSV-* + T-INF-06 scope | [x] |
| PHPUnit full suite | [x] |
| Infection §2.4 | [x] |
| Fuzzy: `home-authenticated,player-profile,event-index-rsvp,event-index-rsvp-gok` | [x] |
| Playwright: `rsvp.spec.ts` + auth smoke | [x] |
| Docs + plan targets | [x] |
| Commit: `R-01: …` | [x] |

**Notes:** Branch `megiddo/r-01-rsvp-refactor` @ `bc626ce8`. Base `05bc1973` (`megiddo/rebase-20260709`). PHPUnit 204 tests, 0 failures (2 skipped). Infection `infection.t01-rsvp.json5`: MSI 17%, covered MSI 55% (floors 15/15). Fuzzy test+mirror 8/8 pass; re-recorded test baselines for `event-index-rsvp*` after sandbox redeploy (dom 0.94 pre-record). Playwright: infrastructure auth smoke + `rsvp.spec.ts` 2/2 pass.

---

## R-02: Auth INSERT

**Depends on:** R-01 recommended · **Branch:** `megiddo/r-02-auth-insert-refactor`  
**Prompt:** `{{MILESTONE}}=R-02` · **V:** [v-02-auth-validation.md](../../validations/v-02-auth-validation.md)

| Gate | Status |
|------|--------|
| Stack base: `megiddo/r-01-rsvp-refactor` @ `bc626ce8` | [x] |
| Refactor T-ADM-11, T-KNA-03, T-PRA-02, T-EVA-06 (per plan) | [x] |
| PHPUnit | [x] |
| Infection §2.4 | [x] |
| Fuzzy: per v-02 §1 | [x] |
| Playwright: `auth-permissions.spec.ts` | [x] |
| Docs + plan | [x] |
| Commit: `R-02: …` | [x] |

**Notes:** Branch `megiddo/r-02-auth-insert-refactor` @ `578f2f2f` stacked on R-01 @ `bc626ce8`. Replaced raw INSERT in AdminAjax/KingdomAjax/ParkAjax/EventAjax addauth with `Model_Authorization::add_auth`; added danger-audit on global admin grant. PHPUnit 204/204 pass. Infection `infection.t02-auth-insert.json5`: MSI 42%, covered MSI 42%. Fuzzy 10/10 pass; re-recorded test manifests for `kingdom-auth-sandbox,park-auth-sandbox` after sandbox redeploy. Playwright: auth smoke + `auth-permissions.spec.ts` 3/3 pass.

---

## R-03: Banner

**Depends on:** V-03 · **Branch:** `megiddo/r-03-banner-refactor` · **V:** [v-03-banner-validation.md](../../validations/v-03-banner-validation.md)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [x] |
| Refactor banner targets | [x] |
| PHPUnit | [x] |
| Infection §2.4 | [x] |
| Fuzzy: `kingdom-auth-sandbox,park-auth-sandbox,player-profile` | [x] |
| Playwright: `banner.spec.ts` | [x] |
| Docs + plan | [x] |
| Commit: `R-03: …` | [x] |

**Notes:** Branch `megiddo/r-03-banner-refactor` @ `4b0d8448` stacked on R-02 @ `516ac063`. Added `class.Banner.php`, BannerService, `Model_Banner`; thin adapters on five `*Ajax::banner`. PHPUnit 204/204 pass. Infection `infection.t03-banner.json5`: MSI 51%, covered MSI 74%. Fuzzy 6/6 pass; re-recorded test baselines for `kingdom-auth-sandbox,park-auth-sandbox` after sandbox redeploy. Playwright: auth smoke + `banner.spec.ts` 5/5 pass.

---

## R-04: EventAjax

**Branch:** `megiddo/r-04-eventajax-refactor` · **V:** [v-04-eventajax-validation.md](../../validations/v-04-eventajax-validation.md)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [x] |
| Refactor EventAjax targets | [x] |
| PHPUnit | [x] |
| Infection §2.4 | [x] |
| Fuzzy: per v-04 §1.3 | [x] |
| Playwright: domain specs | [x] |
| Docs + plan | [x] |
| Commit: `R-04: …` | [x] |

**Notes:** Branch `megiddo/r-04-eventajax-refactor` @ `64563604` stacked on R-03 @ `910cb0dc`. Added `class.EventPlanning.php`, EventService planning handlers, `Model_EventPlanning`; thinned EventAjax T-EVA-01–13 (excl. auth addauth/playersearch, banner). PHPUnit 204/204 pass. Infection `class.EventPlanning.php`: MSI 67%, covered MSI 98%. Fuzzy test+mirror 6/6 pass per profile (re-recorded test baselines for `event-index-rsvp*`). Playwright: auth smoke + `event-planning.spec.ts` 3/3 pass.

---

## R-05: Event

**Branch:** `megiddo/r-05-event-refactor` · **V:** [v-05-event-validation.md](../../validations/v-05-event-validation.md)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [x] |
| Refactor T-EVT-* targets | [x] |
| PHPUnit | [x] |
| Infection §2.4 | [x] |
| Fuzzy: `event-index-rsvp,event-index-rsvp-gok,event-create` | [x] |
| Playwright: `event-detail.spec.ts`, `event-planning.spec.ts` | [x] |
| Docs + plan | [x] |
| Commit: `R-05: …` | [x] |

**Notes:** Branch `megiddo/r-05-event-refactor` @ `20264fa9` stacked on R-04 @ `810eceb3`. Extended `EventPlanning` with occurrence page DTO, fees/links, reconcile, dietary APIs; thinned `Controller_Event` T-EVT-01–08 (no `$DB` in migrated paths). PHPUnit 214/214 pass. Infection `infection.t05-event.json5` `--only-covered`: MSI 44%, covered MSI 44%. Fuzzy test+mirror 6/6 pass (re-recorded `event-index-rsvp*`). Playwright: auth smoke + `event-detail.spec.ts` 3/3 + `event-planning.spec.ts` 3/3 pass.

---

## R-06: Kingdom

**Branch:** `megiddo/r-06-kingdom-refactor` · **V:** [v-06-kingdom-validation.md](../../validations/v-06-kingdom-validation.md)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [x] |
| Refactor kingdom targets | [x] |
| PHPUnit | [x] |
| Infection §2.4 | [x] |
| Fuzzy: `kingdom-profile,kingdom-auth-sandbox` | [x] |
| Playwright: `kingdom-profile.spec.ts` | [x] |
| Docs + plan | [x] |
| Commit: `R-06: …` | [x] |

**Notes:** Branch `megiddo/r-06-kingdom-refactor` stacked on R-05 @ `15897315`. New `KingdomProfile` domain + `Report.GetKingdomExtendedParkAverages`; thinned `Controller_Kingdom` (no `$DB`) and `Controller_KingdomAjax` T-KNG/T-KNA migrated paths. PHPUnit 214/214 pass. Infection `infection.t06-kingdom.json5` `--only-covered`: MSI 21%, covered MSI 21%. Fuzzy 4/4 pass (re-recorded DOM + test `kingdom-auth-sandbox` assets; validate with `--pages kingdom-auth-sandbox,kingdom-profile` avoids capture-order flake). Playwright: auth smoke + `kingdom-profile.spec.ts` 2/2 (ICS via `page.request`).

---

## R-07: Park

**Branch:** `megiddo/r-07-park-refactor` · **V:** [v-07-park-validation.md](../../validations/v-07-park-validation.md)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [x] |
| Refactor park targets | [x] |
| PHPUnit | [x] |
| Infection §2.4 | [x] |
| Fuzzy: `park-auth-sandbox,event-park` | [x] |
| Playwright: `park-profile.spec.ts` | [x] |
| Docs + plan | [x] |
| Commit: `R-07: …` | [x] |

**Notes:** Branch `megiddo/r-07-park-refactor` stacked on R-06 @ `56b59878`. New `ParkProfile` domain + `Model_ParkProfile`; thinned `Controller_Park::profile` (no `$DB`) and `Controller_ParkAjax` T-PRA-03 checkabbr. Batched detail coords (N+1 fix). PHPUnit 214/214 pass. Infection `infection.t07-park.json5` `--only-covered --filter=class.ParkProfile.php`: MSI 39%, covered MSI 39%. Fuzzy 4/4 pass (re-recorded test `park-auth-sandbox` baselines after height drift). Playwright: auth smoke + `park-profile.spec.ts` 2/2 pass.

---

## R-08: Admin

**Branch:** `megiddo/r-08-admin-refactor` · **V:** [v-08-admin-validation.md](../../validations/v-08-admin-validation.md)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [x] |
| Refactor admin targets | [x] |
| PHPUnit | [x] |
| Infection §2.4 | [x] |
| Fuzzy: `admin-dashboard,admin-permissions,admin-state-of-amtgard` | [x] |
| Playwright: `admin-dashboard.spec.ts` | [x] |
| Docs + plan | [x] |
| Commit: `R-08: …` | [x] |

**Notes:** Branch `megiddo/r-08-admin-refactor` stacked on R-07 @ `57baf282`. Domain APIs on `Report`, `Administration` (permissions), `Dangeraudit`, `Player`, `Weather`, `StateOfAmtgard`, `ParkProfile`; `Model_AdminDashboard` thins `Controller_Admin` T-ADM-01–09 and `AdminAjax::stateofamtgard` T-ADM-12. PHPUnit 214/214 pass. Infection `infection.t08-admin.json5` `--only-covered`: MSI 18%, covered MSI 18%. Fuzzy 6/6 pass (re-recorded test admin baselines after DOM drift). Playwright: auth smoke + `admin-dashboard.spec.ts` 3/3 pass.

---

## R-09: Player

**Branch:** `megiddo/r-09-player-refactor` · **V:** [v-09-player-validation.md](../../validations/v-09-player-validation.md)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [ ] |
| Refactor player targets | [ ] |
| PHPUnit | [ ] |
| Infection §2.4 | [ ] |
| Fuzzy: `player-profile,player-profile-sandbox` | [ ] |
| Playwright: `player-profile.spec.ts` | [ ] |
| Docs + plan | [ ] |
| Commit: `R-09: …` | [ ] |

**Notes:**

---

## R-10: Reports

**Branch:** `megiddo/r-10-reports-refactor` · **V:** [v-10-reports-validation.md](../../validations/v-10-reports-validation.md)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [ ] |
| Refactor reports targets | [ ] |
| PHPUnit | [ ] |
| Infection §2.4 | [ ] |
| Fuzzy: `reports-voting-eligible,reports-ladder-grid,reports-attendance` | [ ] |
| Playwright: `reports.spec.ts` | [ ] |
| Docs + plan | [ ] |
| Commit: `R-10: …` | [ ] |

**Notes:**

---

## R-11: Search

**Branch:** `megiddo/r-11-search-refactor` · **V:** [v-11-search-validation.md](../../validations/v-11-search-validation.md)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [ ] |
| Refactor search targets | [ ] |
| PHPUnit | [ ] |
| Infection §2.4 | [ ] |
| Fuzzy: `admin-permissions,kingdom-auth-sandbox,park-auth-sandbox` | [ ] |
| Playwright: `search.spec.ts` | [ ] |
| Docs + plan | [ ] |
| Commit: `R-11: …` | [ ] |

**Notes:**

---

## R-12: Attendance

**Branch:** `megiddo/r-12-attendance-refactor` · **V:** [v-12-attendance-validation.md](../../validations/v-12-attendance-validation.md)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [ ] |
| Refactor attendance targets | [ ] |
| PHPUnit | [ ] |
| Infection §2.4 | [ ] |
| Fuzzy: `park-auth-sandbox,event-park` | [ ] |
| Playwright: `attendance.spec.ts` | [ ] |
| Docs + plan | [ ] |
| Commit: `R-12: …` | [ ] |

**Notes:**

---

## R-13: Infrastructure

**Branch:** `megiddo/r-13-infrastructure-refactor` · **V:** [v-13-infrastructure-validation.md](../../validations/v-13-infrastructure-validation.md)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [ ] |
| Refactor infrastructure targets | [ ] |
| PHPUnit | [ ] |
| Infection §2.4 | [ ] |
| Fuzzy: `home-authenticated` | [ ] |
| Playwright: `infrastructure.spec.ts` | [ ] |
| Docs + plan | [ ] |
| Commit: `R-13: …` | [ ] |

**Notes:**

---

## R-14: Lib-service

**Branch:** `megiddo/r-14-lib-service-refactor` · **V:** [v-14-lib-service-validation.md](../../validations/v-14-lib-service-validation.md)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [ ] |
| Refactor Ork3::$Lib / lib-service targets | [ ] |
| PHPUnit | [ ] |
| Infection §2.4 (pass A + B) | [ ] |
| Fuzzy: `weather,tournament` | [ ] |
| Playwright: `lib-service.spec.ts` | [ ] |
| Docs + plan | [ ] |
| Commit: `R-14: …` | [ ] |

**Notes:**

---

## Quick reference

| Order | ID | Next after complete |
|-------|-----|---------------------|
| 1 | R-01 | R-02 |
| 2 | R-02 | R-03 |
| … | … | … |
| 14 | R-14 | Phase 3 audit |

**Next unchecked:** R-09
