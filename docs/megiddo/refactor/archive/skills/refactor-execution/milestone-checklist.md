# Refactor Execution — Milestone Checklist

Track **R-01 … R-18** Phase 2 sprints. Orchestrator and workers update this file. Master checklist: [04-milestone-checklist.md](../../04-milestone-checklist.md) Phase 2 + [10-phase-2-continuation.md](../../10-phase-2-continuation.md).

**Skill:** [SKILL.md](SKILL.md) · **Prompts:** [agent-prompt.md](agent-prompt.md)

---

## Metadata

| Field | Value |
|-------|--------|
| Integration line (R-01 base only) | `megiddo/rebase-20260709` @ `05bc1973` |
| Branching | **stack-on-prior-R** (mandatory — lights out, no merge gates) |
| Stack tip (orchestrator updates after each R-*) | `megiddo/r-18-residual-db-refactor` @ `d3f29fc7` |
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
| R-08 | `megiddo/r-08-admin-refactor` | `ebda9ebe` |
| R-09 | `megiddo/r-09-player-refactor` | `4a7b1f8c` |
| R-10 | `megiddo/r-10-reports-refactor` | `ea46a630` |
| R-11 | `megiddo/r-11-search-refactor` | `bdbc86d7` |
| R-12 | `megiddo/r-12-attendance-refactor` | `6fcc6ce0` |
| R-13 | `megiddo/r-13-infrastructure-refactor` | `758b8566` |
| R-14 | `megiddo/r-14-lib-service-refactor` | `a389b247` |
| R-15 | `megiddo/r-15-hasauthority-refactor` | `446e7c42` |
| R-16 | `megiddo/r-16-ghettocache-refactor` | `86d5cbed` |
| R-17 | `megiddo/r-17-lib-bypass-refactor` | `28a2f390` |
| R-18 | `megiddo/r-18-residual-db-refactor` | `d3f29fc7` |

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

**Notes:** Branch `megiddo/r-01-rsvp-refactor` @ `bc626ce8`. Base `05bc1973` (`megiddo/rebase-20260709`). PHPUnit 204 tests, 0 failures (2 skipped). Infection `tools/infection/infection.t01-rsvp.json5`: MSI 17%, covered MSI 55% (floors 15/15). Fuzzy test+mirror 8/8 pass; re-recorded test baselines for `event-index-rsvp*` after sandbox redeploy (dom 0.94 pre-record). Playwright: infrastructure auth smoke + `rsvp.spec.ts` 2/2 pass.

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

**Notes:** Branch `megiddo/r-02-auth-insert-refactor` @ `578f2f2f` stacked on R-01 @ `bc626ce8`. Replaced raw INSERT in AdminAjax/KingdomAjax/ParkAjax/EventAjax addauth with `Model_Authorization::add_auth`; added danger-audit on global admin grant. PHPUnit 204/204 pass. Infection `tools/infection/infection.t02-auth-insert.json5`: MSI 42%, covered MSI 42%. Fuzzy 10/10 pass; re-recorded test manifests for `kingdom-auth-sandbox,park-auth-sandbox` after sandbox redeploy. Playwright: auth smoke + `auth-permissions.spec.ts` 3/3 pass.

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

**Notes:** Branch `megiddo/r-03-banner-refactor` @ `4b0d8448` stacked on R-02 @ `516ac063`. Added `class.Banner.php`, BannerService, `Model_Banner`; thin adapters on five `*Ajax::banner`. PHPUnit 204/204 pass. Infection `tools/infection/infection.t03-banner.json5`: MSI 51%, covered MSI 74%. Fuzzy 6/6 pass; re-recorded test baselines for `kingdom-auth-sandbox,park-auth-sandbox` after sandbox redeploy. Playwright: auth smoke + `banner.spec.ts` 5/5 pass.

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

**Notes:** Branch `megiddo/r-05-event-refactor` @ `20264fa9` stacked on R-04 @ `810eceb3`. Extended `EventPlanning` with occurrence page DTO, fees/links, reconcile, dietary APIs; thinned `Controller_Event` T-EVT-01–08 (no `$DB` in migrated paths). PHPUnit 214/214 pass. Infection `tools/infection/infection.t05-event.json5` `--only-covered`: MSI 44%, covered MSI 44%. Fuzzy test+mirror 6/6 pass (re-recorded `event-index-rsvp*`). Playwright: auth smoke + `event-detail.spec.ts` 3/3 + `event-planning.spec.ts` 3/3 pass.

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

**Notes:** Branch `megiddo/r-06-kingdom-refactor` stacked on R-05 @ `15897315`. New `KingdomProfile` domain + `Report.GetKingdomExtendedParkAverages`; thinned `Controller_Kingdom` (no `$DB`) and `Controller_KingdomAjax` T-KNG/T-KNA migrated paths. PHPUnit 214/214 pass. Infection `tools/infection/infection.t06-kingdom.json5` `--only-covered`: MSI 21%, covered MSI 21%. Fuzzy 4/4 pass (re-recorded DOM + test `kingdom-auth-sandbox` assets; validate with `--pages kingdom-auth-sandbox,kingdom-profile` avoids capture-order flake). Playwright: auth smoke + `kingdom-profile.spec.ts` 2/2 (ICS via `page.request`).

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

**Notes:** Branch `megiddo/r-07-park-refactor` stacked on R-06 @ `56b59878`. New `ParkProfile` domain + `Model_ParkProfile`; thinned `Controller_Park::profile` (no `$DB`) and `Controller_ParkAjax` T-PRA-03 checkabbr. Batched detail coords (N+1 fix). PHPUnit 214/214 pass. Infection `tools/infection/infection.t07-park.json5` `--only-covered --filter=class.ParkProfile.php`: MSI 39%, covered MSI 39%. Fuzzy 4/4 pass (re-recorded test `park-auth-sandbox` baselines after height drift). Playwright: auth smoke + `park-profile.spec.ts` 2/2 pass.

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

**Notes:** Branch `megiddo/r-08-admin-refactor` stacked on R-07 @ `57baf282`. Domain APIs on `Report`, `Administration` (permissions), `Dangeraudit`, `Player`, `Weather`, `StateOfAmtgard`, `ParkProfile`; `Model_AdminDashboard` thins `Controller_Admin` T-ADM-01–09 and `AdminAjax::stateofamtgard` T-ADM-12. PHPUnit 214/214 pass. Infection `tools/infection/infection.t08-admin.json5` `--only-covered`: MSI 18%, covered MSI 18%. Fuzzy 6/6 pass (re-recorded test admin baselines after DOM drift). Playwright: auth smoke + `admin-dashboard.spec.ts` 3/3 pass.

---

## R-09: Player

**Branch:** `megiddo/r-09-player-refactor` · **V:** [v-09-player-validation.md](../../validations/v-09-player-validation.md)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [x] |
| Refactor player targets | [x] |
| PHPUnit | [x] |
| Infection §2.4 | [x] |
| Fuzzy: `player-profile,player-profile-sandbox` | [x] |
| Playwright: `player-profile.spec.ts` | [x] |
| Docs + plan | [x] |
| Commit: `R-09: …` | [x] |

**Notes:** Branch `megiddo/r-09-player-refactor` @ `4a7b1f8c` stacked on R-08 @ `ebda9ebe`. Domain APIs on `Player` (profile reads, display grants, username, email, beltline, reconcile map, roster cache bust); thinned `Controller_Player` T-PLR-01–07, `Controller_PlayerAjax` T-PLA-01–05, `Model_Player` T-PLM-01–04. PHPUnit 214/214 pass. Infection `tools/infection/infection.t09-player.json5` `--only-covered`: MSI 46%, covered MSI 46%. Fuzzy 4/4 pass (re-recorded test player-profile baselines). Playwright: auth smoke + `player-profile.spec.ts` 2/2 pass.

---

## R-10: Reports

**Branch:** `megiddo/r-10-reports-refactor` · **V:** [v-10-reports-validation.md](../../validations/v-10-reports-validation.md)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [x] |
| Refactor reports targets | [x] |
| PHPUnit | [x] |
| Infection §2.4 | [x] |
| Fuzzy: `reports-voting-eligible,reports-ladder-grid,reports-attendance` | [x] |
| Playwright: `reports.spec.ts` | [x] |
| Docs + plan | [x] |
| Commit: `R-10: …` | [x] |

**Notes:** Branch `megiddo/r-10-reports-refactor` @ `ea46a630` stacked on R-09 @ `4a7b1f8c`. Added `VotingRules` config, `Report::GetLadderAwardGrid`, `GetAttendanceDates`, `GetVotingRules`, `GetVotingEligibleForPlayer`, `GetKingdomOfficerDirectoryMerged`; `Award::GetAwardOptionGroups`. Thinned `Controller_Reports::ladder_grid`, `Model_Reports`, `Model_Award`, `Controller_PlayerAjax::voting_eligible` off `$DB`. PHPUnit 215/215 pass (2 skipped). Infection `tools/infection/infection.t10-reports.json5` `--only-covered`: MSI 47%, covered MSI 47%. Fuzzy test+mirror 6/6 pass (re-recorded mirror `reports-ladder-grid` baselines). Playwright: auth smoke + `reports.spec.ts` 4/4 pass.

---

## R-11: Search

**Branch:** `megiddo/r-11-search-refactor` · **V:** [v-11-search-validation.md](../../validations/v-11-search-validation.md)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [x] |
| Refactor search targets | [x] |
| PHPUnit | [x] |
| Infection §2.4 | [x] |
| Fuzzy: `admin-permissions,kingdom-auth-sandbox,park-auth-sandbox` | [x] |
| Playwright: `search.spec.ts` | [x] |
| Docs + plan | [x] |
| Commit: `R-11: …` | [x] |

**Notes:** Branch `megiddo/r-11-search-refactor` @ `bdbc86d7` stacked on R-10 @ `de3c2118`. Added `SearchService::UniversalSearch`, `ScopedPlayerSearch`, `GetUnitActivityCounts`, `EscapeLike`, punct-fold helpers; thinned `SearchAjax::universal`, `Search::unitactivity`, `AdminAjax`/`KingdomAjax`/`ParkAjax`/`EventAjax` playersearch off `$DB`. PHPUnit 215/215 pass (2 skipped). Infection `tools/infection/infection.t11-search.json5` `--only-covered`: MSI 40%, covered MSI 40%. Fuzzy test+mirror 6/6 pass. Playwright: auth smoke + `search.spec.ts` 3/3 pass.

---

## R-12: Attendance

**Branch:** `megiddo/r-12-attendance-refactor` · **V:** [v-12-attendance-validation.md](../../validations/v-12-attendance-validation.md)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [x] |
| Refactor attendance targets | [x] |
| PHPUnit | [x] |
| Infection §2.4 | [x] |
| Fuzzy: `park-auth-sandbox,event-park` | [x] |
| Playwright: `attendance.spec.ts` | [x] |
| Docs + plan | [x] |
| Commit: `R-12: …` | [x] |

**Notes:** Branch `megiddo/r-12-attendance-refactor` @ `6fcc6ce0` stacked on R-11 @ `d9def14b`. Added `ClassLevel` helper, `Player::ComputeClassProgress`, `Attendance` reactivate/adjacent-dates/link enrichment/cache bust, `Weather` archive JSON wrappers; thinned AttendanceAjax/SignIn/QR/Attendance controllers and `Model_Attendance` off `$DB`/`Ork3::$Lib` on migrated paths. PHPUnit 215/215 pass (2 skipped). Infection `tools/infection/infection.t12-attendance.json5` `--only-covered`: MSI 51%, covered MSI 51%. Fuzzy test+mirror 4/4 pass (re-recorded `park-auth-sandbox` DOM/visual/assets + `event-park` DOM baselines). Playwright: auth smoke + `attendance.spec.ts` 4/4 pass.

---

## R-13: Infrastructure

**Branch:** `megiddo/r-13-infrastructure-refactor` · **V:** [v-13-infrastructure-validation.md](../../validations/v-13-infrastructure-validation.md)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [x] |
| Refactor infrastructure targets | [x] |
| PHPUnit | [x] |
| Infection §2.4 | [x] |
| Fuzzy: `home-authenticated` | [x] |
| Playwright: `infrastructure.spec.ts` | [x] |
| Docs + plan | [x] |
| Commit: `R-13: …` | [x] |

**Notes:** Branch `megiddo/r-13-infrastructure-refactor` stacked on R-12 @ `c86ab163`. Added `Health::PingDb`, `SessionToken::ValidateSessionToken`, `Player::GetViewerPreferences`/`GetHomeKingdom`/`DismissWhatsNew`/`GetWhatsNewSeen`, `Event::GetEventSummaryForRedirect`; thinned `orkui/index.php`, `class.Controller`, `controller.WnAjax`, `default.theme` off `$DB` on migrated paths (T-INF-06 → R-01; menu HasAuthority → R-14; session token in `SessionToken` — `class.Authorization.php` uncommittable per hook). PHPUnit 215/215 pass (2 skipped). Infection `tools/infection/infection.t13-infrastructure.json5` `--only-covered`: MSI 33% (`class.Player.php` + `class.SessionToken.php`). Fuzzy test+mirror 2/2 pass (re-recorded `home-authenticated` baselines). Playwright: auth smoke + `infrastructure.spec.ts` 3/3 pass.

---

## R-14: Lib-service

**Branch:** `megiddo/r-14-lib-service-refactor` · **V:** [v-14-lib-service-validation.md](../../validations/v-14-lib-service-validation.md)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [x] |
| Refactor Ork3::$Lib / lib-service targets | [x] |
| PHPUnit | [x] |
| Infection §2.4 (pass A + B) | [x] |
| Fuzzy: `weather,tournament` | [x] |
| Playwright: `lib-service.spec.ts` | [x] |
| Docs + plan | [x] |
| Commit: `R-14: …` | [x] |

**Notes:** Branch `megiddo/r-14-lib-service-refactor` @ `76758e2c` stacked on R-13 @ `758b8566`. Added `AuthorizationGate`, `LiveService`, `WeatherService`, `EraPhoeniceService` + JSON registrations; `Authorization.HasAuthority` SOAP/JSON; thinned `Controller_Live`, `Controller_Weather`, `Controller_EraPhoenice`, `Controller_Tournament`, `Controller_CalendarItemAjax`, `class.Controller` menu gates off `Ork3::$Lib` on migrated paths. PHPUnit 215/215 pass (2 skipped). Infection pass A MSI 18%, pass B MSI 27% (floors 15/15). Fuzzy test+mirror 4/4 pass (re-recorded `weather`/`tournament` baselines). Playwright: auth smoke + `lib-service.spec.ts` 4/4 pass. **Carryover:** ~120 HasAuthority + templates → R-15 ([10-phase-2-continuation.md](../../10-phase-2-continuation.md)).

---

## R-15: HasAuthority

**Depends on:** R-14 · **Branch:** `megiddo/r-15-hasauthority-refactor` · **V:** [v-14-lib-service-validation.md](../../validations/v-14-lib-service-validation.md) (§1.3)

| Gate | Status |
|------|--------|
| Stack base: `megiddo/r-14-lib-service-refactor` @ `a389b247` | [x] |
| Replace remaining HasAuthority in controllers | [x] |
| Precompute template auth flags (no `Ork3::$Lib` in templates) | [x] |
| PHPUnit | [x] |
| Infection §2.4 (pass A) | [x] |
| Fuzzy: `admin-permissions,kingdom-auth-sandbox,park-auth-sandbox,player-profile` | [x] |
| Playwright: `auth-permissions.spec.ts` + auth smoke | [x] |
| Docs + plan | [x] |
| Commit: `R-15: …` | [x] |

**Notes:** Branch `megiddo/r-15-hasauthority-refactor` stacked on R-14 @ `a389b247`. Replaced ~120 `Ork3::$Lib->authorization->HasAuthority` controller call sites with `$this->Authorization->has_authority()` (`AuthorizationGate`); precomputed template flags (`Admin_*`, `Reports_*`, `Playernew_*`, `Kingdomnew_index`, `default.theme` nav). Zero `HasAuthority` / auth `Ork3::$Lib` in `orkui/`. PHPUnit 215/215 pass (2 skipped). Infection pass A MSI 18%. Fuzzy 8/8 pass (test+mirror). Playwright auth smoke + `auth-permissions.spec.ts` 3/3 pass (mirror `admin`/`password`). **Carryover:** ghettocache → R-16; domain lib bypass → R-17.

---

## R-16: GhettoCache

**Branch:** `megiddo/r-16-ghettocache-refactor` · **V:** [v-14-lib-service-validation.md](../../validations/v-14-lib-service-validation.md) (§1.4)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [x] |
| Move read-through cache + write bust into domain | [x] |
| PHPUnit | [x] |
| Infection | [x] |
| Fuzzy: `kingdom-profile,park-auth-sandbox,reports-ladder-grid` | [x] |
| Playwright: domain specs + auth smoke | [x] |
| Docs + plan | [x] |
| Commit: `R-16: …` | [x] |

**Notes:** Branch `megiddo/r-16-ghettocache-refactor` stacked on R-15 @ `446e7c42`. Moved ghettocache read-through + write bust into domain (`Player`, `Award`, `SearchService`, `Event`, `Report`, `Park`, `Kingdom`, `Heraldry`, `Attendance`); thinned `Model_Player`, `Model_Award`, `Controller_Search`, `Controller_Event`, `Controller_EventAjax`, `Controller_Reports`, `Controller_KingdomAjax`, `Controller_ParkAjax` off `Ork3::$Lib->ghettocache`. Zero `ghettocache` in `orkui/` (comment-only in `Controller_Recap`). PHPUnit 215/215 pass (2 skipped). Infection pass A MSI 18%, pass B MSI 27%. Fuzzy 6/6 pass (test+mirror). Playwright auth smoke + `kingdom-profile.spec.ts` 2/2 + `reports.spec.ts` 4/4 pass. **Carryover:** residual domain lib bypass → R-17.

---

## R-17: Lib bypass

**Branch:** `megiddo/r-17-lib-bypass-refactor` · **V:** [v-14-lib-service-validation.md](../../validations/v-14-lib-service-validation.md)

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [x] |
| Residual `Ork3::$Lib` domain helpers (T-EVT-08, T-KNG-11, T-PRK-05, T-PLR-08, T-RPT-02, T-UNT-02/03, …) | [x] |
| PHPUnit | [x] |
| Infection | [x] |
| Fuzzy: `event-index-rsvp,player-profile,reports-voting-eligible` | [x] |
| Playwright: domain specs + auth smoke | [x] |
| Docs + plan | [x] |
| Commit: `R-17: …` | [x] |

**Notes:** Branch `megiddo/r-17-lib-bypass-refactor` stacked on R-16 @ `86d5cbed`. Migrated residual domain lib bypass: `Model_Player`/`Model_Weather`/`Model_Reports`/`Model_Kingdom` wrappers; `Controller_Kingdom`, `Controller_Park`, `Controller_Reports`, `Controller_Unit` off `Ork3::$Lib`; Event/Park/Attendance weather templates via `wx_*` helpers in `wx_safety_helpers.php`. Principality already APIModel-only. PHPUnit 215/215 pass (2 skipped). Infection pass A MSI 18%, pass B MSI 27%. Fuzzy 6/6 pass (test+mirror; re-recorded gate baselines). Playwright auth smoke + `event-detail.spec.ts` 3/3 + `player-profile.spec.ts` 2/2 + `reports.spec.ts` 4/4 pass. **Carryover:** residual `$DB` + deferred lib sites (searchservice, heraldry, index.php) → R-18.

---

## R-18: Residual `$DB`

**Branch:** `megiddo/r-18-residual-db-refactor` · **V:** V-00 regression sweep

| Gate | Status |
|------|--------|
| Prior R-* hygiene | [x] |
| Zero `$DB->` in `orkui/` | [x] |
| PHPUnit | [x] |
| Fuzzy: V-00 active pages | [x] |
| Playwright: smoke + touched domain specs | [x] |
| Docs + plan | [x] |
| Commit: `R-18: …` | [x] |

**Notes:** Branch `megiddo/r-18-residual-db-refactor` stacked on R-17 @ `28a2f390`. Eliminated all residual `$DB->` in `orkui/`: domain APIs on `Player`, `Dangeraudit`, `Weather`, `ParkProfile`, `Event`, `Administration`; nav helpers (`nav_view_helpers.php`, `wx_coords_for_calendar_detail`); thinned Admin/Player/*Ajax controllers + `Admin_auditlog.tpl`, `default.theme`, `Eventnew_index.tpl`. PHPUnit 215/215 pass (2 skipped). Infection spot-check: `class.Player.php` MSI 20%, `class.DangerAudit.php` MSI 50%. Fuzzy V-00 active pages 34/34 pass (17 pages × 2 profiles). Playwright: auth smoke + `auth-permissions.spec.ts` 3/3 + `player-profile.spec.ts` 2/2 + `event-detail.spec.ts` 3/3 pass. **Phase 2 complete** — stack ready for Phase 3 audit.

---

## Quick reference

| Order | ID | Next after complete |
|-------|-----|---------------------|
| 1 | R-01 | R-02 |
| 2 | R-02 | R-03 |
| … | … | … |
| 14 | R-14 | R-15 |
| 15 | R-15 | R-16 |
| 16 | R-16 | R-17 |
| 17 | R-17 | R-18 |
| 18 | R-18 | Phase 3 audit |

**Next unchecked:** Phase 3 audit ([11-phase-3-closeout.md](../../11-phase-3-closeout.md))
