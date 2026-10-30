# Legend Status Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let kingdom officers convert a deceased player to Legend status: a memorial profile with a Memory Wall, enforced on the server across every surface that assumes a living player.

**Architecture:** One new domain class, `Legend`, owns the status, conversion, undo and memories. It takes a caller uid, is not registered on `Ork3::$Lib`, and is reached through `Model_Legend`, the same shape as `BonusCredit`. Existing write paths call small static guards on `Legend`. Conversion sets `active = 0`, so active-only lists are safe by default, and award and title reports opt Legends back in.

**Tech Stack:** PHP 8, MariaDB, Yapo, plain-PHP `.tpl` templates, jQuery in `revised.js`, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-10-30-legend-status-design.md`. Mockups: `docs/superpowers/specs/2026-10-30-legend-status-mockups/`. Read both. The spec's line references are from `971d2c9d6`.

## Global Constraints

- All SQL stays in `system/lib/ork3/`. `orkui/model` is the only membrane. Controllers and templates hold no SQL.
- `mysql_real_escape_string()` is a no-op. Cast with `(int)` or validate. Call `Clear()` before raw `Execute`/`DataSet`. `DataSet()` needs `->Next()`. Yapo drops `null`, so new columns are `NOT NULL` with defaults.
- Transactions are on `YapoMysql`, not `YapoDb`.
- No native `confirm`, `alert` or `prompt`. Tooltips use `data-tip`, never `title`.
- `.tpl` files are plain PHP, not Smarty. Add no entry points in `template/default/`.
- Every UI change works in light and dark (`html[data-theme="dark"]`) and on phone widths. Verify computed styles, because `orkui.css` global heading rules win.
- Every search change: scoped, `&q=` not `?q=`, curl-tested with a term that matches a Legend.
- Migrations are idempotent and classified in `tools/ork-db/manifests/migration-classification.json5`. Apply to both `ork` (`ork3-php8-db`) and `ork_test` (`ork3-php8-test-db`), then `docker restart ork3-php8-app` for the APCu schema cache.
- Run tests with `vendor/bin/phpunit --testsuite integration --filter <Name>` from the host.
- **Commits:** stage explicit paths only, never `git add -A` or `git add .`. Run `git diff --cached --stat` before each commit, because other sessions may share this checkout. Every commit message ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **`class.Authorization.php`:** the pre-commit hook strips this file from every commit. Commit it alone: confirm `grep -c 'true ||' system/lib/ork3/class.Authorization.php` prints `0`, stage only that file, and use `git commit --no-verify`. Never commit a `true ||` bypass. Never delete the file.
- The refusal message for all guards is the constant `Legend::REFUSED`: `This player is a Legend and can no longer be changed this way.`
- Banner copy, exact: `{Name} passed beyond this realm in {year}. Their spirit lives on as a legend of Amtgard and they will be missed.`
- Memory form copy, exact: `Please remain respectful. This wall honors a member of our community and is read by friends and family. Inappropriate content is subject to removal.`

## Review Focus

- **Hostile text in names and memories:** a persona or memory containing `<script>` or quotes must render as inert text in the card, the wall, modals and JSON.
- **Convert, undo, convert again:** memories reappear, each cycle has its own audit snapshot, and a double convert or an undo of a non-Legend is refused without changing anything.
- **Authority edges:** an officer of another kingdom is refused, a parent-kingdom officer succeeds on a principality player, a park officer who is also a kingdom officer succeeds, and a player with `park_id = 0` doesn't break the gate.
- **Conversion of someone in a key role:** a sitting monarch, an ORK admin, or a sole unit manager converts cleanly, and the preview names each consequence.
- **Live session at the moment of conversion:** the Legend's browser stops working on its next request, not at session expiry.

---

### Task 1: Schema and Legend core

**Files:**
- Create: `db-migrations/2026-10-30-legend-status.sql`
- Create: `system/lib/ork3/class.Legend.php`
- Create: `orkui/model/model.Legend.php`
- Create: `tests/Support/LegendFixture.php`
- Create: `tests/Integration/LegendTest.php`
- Modify: `tools/ork-db/manifests/migration-classification.json5`
- Modify: `system/lib/ork3/class.Player.php:925` (`GetPlayer` output)

**Interfaces:**
- Produces:
  - Columns `ork_mundane.is_legend TINYINT(1) NOT NULL DEFAULT 0` (indexed), `ork_mundane.legend_year SMALLINT UNSIGNED NOT NULL DEFAULT 0`
  - Table `ork_legend_log`: `legend_log_id`, `mundane_id`, `action ENUM('convert','undo','year')`, `legend_year`, `prior_active TINYINT`, `cleared_json MEDIUMTEXT NOT NULL`, `actor_id`, `created DATETIME`
  - Table `ork_legend_memory`: `legend_memory_id`, `mundane_id`, `author_id`, `body TEXT NOT NULL`, `created DATETIME`, `removed_by_id INT NOT NULL DEFAULT 0`, `removed_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00'` or the repo's nearest equivalent if strict mode refuses it
  - `Legend::REFUSED`, `Legend::MIN_YEAR = 1983`
  - `Legend::isLegend(int $mundaneId): bool` with a per-request memo
  - `Legend::clearMemo(): void`
  - `Legend::mayManage(int $uid, int $mundaneId): bool`: `HasAuthority(AUTH_KINGDOM, player's kingdom, AUTH_EDIT)` or `HasAuthority(AUTH_ADMIN, 0, AUTH_ADMIN)`, as in `SetPlayerSuspension` (`class.Player.php:2705`)
  - `Legend::isOrkAdmin(int $uid): bool`
  - `Legend::blocksEdit(int $uid, int $mundaneId): bool`: `isLegend && !mayManage`
  - `GetPlayer` response gains `IsLegend` (0/1) and `LegendYear`
  - `LegendFixture` in the style of `AttendanceFixture`: `addPlayer(...)`, `addKingdomOfficer(...)`, `addParkOfficer(...)`, `cleanup()`

- [ ] **Step 1: Write the failing tests** in `LegendTest`: `test_is_legend_false_for_a_normal_player`, `test_is_legend_true_after_flag_set`, `test_may_manage_true_for_kingdom_officer_and_ork_admin`, `test_may_manage_false_for_park_officer_and_other_kingdom_officer`, `test_may_manage_true_for_parent_kingdom_officer_of_a_principality_player`, `test_blocks_edit_only_when_legend_and_not_manager`, `test_get_player_reports_legend_fields`.
- [ ] **Step 2: Run them and confirm they fail** on the missing class.
- [ ] **Step 3: Write and apply the migration** to both databases, add the classification entry (`"class": "S", "render": "full"`), and restart the app container.
- [ ] **Step 4: Implement `Legend` statics, `Model_Legend`, and the `GetPlayer` fields.** Header comment states why the class stays off `Ork3::$Lib`, as `class.BonusCredit.php` does.
- [ ] **Step 5: Run `--filter LegendTest` and confirm it passes.**
- [ ] **Step 6: Commit** the spec, mockups, this plan and the task's files.

### Task 2: Convert, preview, undo and year edit

**Files:**
- Modify: `system/lib/ork3/class.Legend.php`, `orkui/model/model.Legend.php`
- Create: `orkui/controller/controller.LegendAjax.php`
- Test: `tests/Integration/LegendConversionTest.php`

**Interfaces:**
- Consumes: Task 1.
- Produces, each returning `['Status' => 0, ...]`, or `1` for a bad request, `3` for not authorized:
  - `Legend::preview(int $uid, int $mundaneId): array` with `Items` (`[['Type','Label','Id']]`) and `Warnings` (strings)
  - `Legend::convert(int $uid, int $mundaneId, int $year): array`
  - `Legend::undo(int $uid, int $mundaneId): array` with `Cleared` from the last convert snapshot
  - `Legend::setYear(int $uid, int $mundaneId, int $year): array`
  - `Legend::purgeSessions(int $mundaneId): void`
  - Routes `LegendAjax/preview/{id}`, `convert/{id}`, `undo/{id}`, `year/{id}` using the `requireLogin` + `jsonOut` envelope from `controller.BonusCreditAjax.php`

Conversion clears these in one `YapoMysql` transaction, recording each in `cleared_json`:
- `ork_event_rsvp` rows for future events
- `ork_event_staff` and `ork_event_schedule_lead` rows for future events
- `ork_officer` seats, through `(new Common())->set_officer($kingdomId, $parkId, 0, $role)`, with a `dangeraudit` call as in `VacateOfficer`
- `ork_authorization` rows, including unit and event rows
- `ork_participant_mundane` rows for tournaments that haven't started
- `ork_court.recorder_mundane_id` and `ork_court_award.given_by_mundane_id` on courts not yet finalized
- `ork_qual_manager` and `ork_bonus_credit_granter` rows

It keeps `ork_court_award.mundane_id`, `ork_court_award_artisan`, unit membership, dues, attendance and awards. It sets `is_legend = 1`, `legend_year`, `active = 0`, writes the log row with `prior_active`, purges `ork_session` rows and `ork_application_auth` tokens, and busts player cache.

Undo sets `is_legend = 0`, keeps `legend_year` in the log only, restores `prior_active`, and returns the snapshot.

Refuse with Status 1: year outside `MIN_YEAR` to the current year, non-integer year, player ID 1, a player converting themselves, converting a Legend, undoing a non-Legend. `preview` warns about each officer seat and each unit left with no manager.

- [ ] **Step 1: Write the failing tests:** `test_convert_refused_for_park_officer`, `test_convert_refused_for_other_kingdom_officer`, `test_convert_rejects_year_before_1983_and_future_year`, `test_convert_rejects_player_one_and_self`, `test_convert_rejects_already_legend`, `test_convert_clears_future_rsvp_keeps_past_rsvp`, `test_convert_vacates_officer_seat_and_deletes_authorization`, `test_convert_clears_recorder_and_given_by_keeps_recipient_and_artisan`, `test_convert_keeps_unit_membership_and_dues`, `test_convert_purges_sessions_and_tokens`, `test_convert_sets_inactive_and_undo_restores_prior_value`, `test_undo_returns_snapshot_and_keeps_memories`, `test_undo_rejects_non_legend`, `test_reconvert_writes_a_second_snapshot`, `test_preview_warns_on_officer_seat_and_sole_unit_manager`, `test_preview_changes_nothing`.
- [ ] **Step 2: Run them and confirm they fail.**
- [ ] **Step 3: Implement the methods, model pass-throughs and controller.**
- [ ] **Step 4: Run `--filter LegendConversionTest` and confirm it passes.**
- [ ] **Step 5: Commit.**

### Task 3: Login lockout

**Files:**
- Modify: `system/lib/ork3/class.Authorization.php:125, 291, 319, 374, 419, 478, 535, 587, 1078`
- Modify: `system/lib/ork3/class.IdpIntegration.php:61, 188`
- Modify: `system/lib/ork3/class.Attendance.php:675, 864`
- Test: `tests/Integration/LegendLoginTest.php`

**Interfaces:**
- Consumes: `Legend::isLegend`, `Legend::convert`, `Legend::REFUSED`.
- Produces: nothing new.

Add `$this->mundane->is_legend == 1` next to each `suspended || penalty_box` check. In `IsAuthorized_h` (`:1078`) add it beside `penalty_box`. `ResetPassword` returns its normal response without sending, so it reveals nothing. `createIdpLink` and `ValidateClaimOrkCode` refuse. `UseAttendanceLink` and `UpdateSelfSigninClass` refuse with `Legend::REFUSED`.

- [ ] **Step 1: Write the failing tests:** `test_password_login_refused`, `test_token_reauth_refused`, `test_app_auth_refused`, `test_is_authorized_returns_zero_for_a_live_session_after_convert`, `test_reset_password_sends_nothing`, `test_idp_claim_and_link_refused`, `test_idp_claim_code_not_sent`, `test_sign_in_link_refused`, `test_undo_restores_login`.
- [ ] **Step 2: Run them and confirm they fail.**
- [ ] **Step 3: Implement the checks.**
- [ ] **Step 4: Run `--filter LegendLoginTest` and confirm it passes.**
- [ ] **Step 5: Commit `class.Authorization.php` alone** using the Global Constraints procedure, then commit the rest normally.

### Task 4: Refuse new participation

**Files:**
- Modify: `system/lib/ork3/class.Attendance.php:66, 170`, `class.BonusCredit.php:369, 1051, 1226`, `class.Event.php:98, 192, 283`, `class.EventPlanning.php:1084, 1664`, `class.Tournament.php:107`, `class.Court.php` (role writes at 389, 571, 1402, 1767, 2575), `class.Unit.php:241, 490, 670, 702`, `class.Park.php:1094`, `class.Kingdom.php:831`, `class.Player.php:3994, 4154`, `class.Treasury.php:69`, `class.QualTest.php:172`
- Modify: `system/lib/ork3/class.Authorization.php:737`, `orkui/controller/controller.EventAjax.php:302`, `orkui/controller/controller.QualTestAjax.php:568, 611`
- Modify: `orkui/controller/controller.Player.php:172, 375`
- Test: `tests/Integration/LegendParticipationTest.php`

**Interfaces:**
- Consumes: `Legend::isLegend`, `Legend::REFUSED`.
- Produces: `AddAwardRecommendation` honors `$request['FromProfile'] = 1`. It refuses a Legend recipient without that key. `controller.Player.php:172, 375` send it, and `ParkAjax:302` and `KingdomAjax:469` don't.

Guards apply to the target player, refuse before any write, and use the surrounding method's error shape. Attendance is refused for everyone, including ORK admins. Court role writes keep working when the Legend is the award recipient or an artisan. `ork_court_award.given_by_mundane_id` is refused for a Legend on an unfinalized court only if the giver is being newly set. Unit guards apply to new adds and to reviving a retired member, not to existing rows.

- [ ] **Step 1: Write the failing tests:** one per surface, including `test_add_attendance_refused_even_for_ork_admin`, `test_set_attendance_cannot_reassign_to_legend`, `test_attendance_does_not_reactivate_legend`, `test_bonus_grant_refused`, `test_rsvp_refused_including_on_behalf`, `test_event_staff_refused`, `test_tournament_participant_refused`, `test_court_recorder_refused`, `test_court_award_to_legend_allowed`, `test_court_artisan_legend_allowed`, `test_unit_add_refused_existing_member_kept`, `test_officer_assignment_refused`, `test_authorization_grant_refused`, `test_dues_refused`, `test_qual_result_refused`, `test_recommendation_refused_without_from_profile`, `test_recommendation_allowed_from_profile`.
- [ ] **Step 2: Run them and confirm they fail.**
- [ ] **Step 3: Add the guards.** Before editing a file, count leading tabs with `awk '/^\t/{c++} END{print c+0}' <file>`. If it's not `0`, use a script-based edit rather than the Edit tool.
- [ ] **Step 4: Run `--filter LegendParticipationTest`, then `--filter "BonusCredit|Attendance|EventRsvp|Court"` to confirm nothing regressed.**
- [ ] **Step 5: Commit `class.Authorization.php` alone** as in Task 3, then commit the rest.

### Task 5: Editing, merging and moving

**Files:**
- Modify: `system/lib/ork3/class.Player.php:750, 782, 818, 848, 893, 2388, 2570, 2826, 3093, 3215, 3281, 3301, 3330, 3355, 3755, 3865, 3994, 4121`
- Modify: `system/lib/ork3/class.Heraldry.php:63, 88`, `class.Banner.php:304`
- Modify: `orkui/controller/controller.Player.php:457`, `orkui/controller/controller.Admin.php:943`
- Test: `tests/Integration/LegendEditAuthorityTest.php`

**Interfaces:**
- Consumes: `Legend::blocksEdit`, `Legend::mayManage`, `Legend::isOrkAdmin`, `Legend::isLegend`.
- Produces: `canEditAdmin` false for park officers on a Legend.

Keep existing checks and add `blocksEdit` beside them. `UpdatePlayer` refuses username, email and password changes on a Legend for everyone, and refuses `Active`. `AddAward` stays open to park officers. `UpdateAward`, `ReconcileAward` and award removal are gated. `MovePlayer` requires `isOrkAdmin` for a Legend at every caller, in the library.

`MergePlayer`: refuse Legend with non-Legend. For two Legends, the survivor keeps its own year and `ork_legend_memory` and `ork_legend_log` rows are re-homed, and the authority is `mayManage` on both.

- [ ] **Step 1: Write the failing tests:** `test_park_officer_cannot_update_legend`, `test_kingdom_officer_can_update_legend`, `test_ork_admin_can_update_legend`, `test_park_officer_still_edits_a_normal_player`, `test_nobody_changes_legend_login_fields_or_active`, `test_park_officer_cannot_change_image_heraldry_banner_notes_waiver_or_dues`, `test_park_officer_can_grant_new_award_to_legend`, `test_park_officer_cannot_change_or_remove_legend_award`, `test_move_refused_for_kingdom_and_park_officers`, `test_move_allowed_for_ork_admin`, `test_merge_legend_with_living_refused`, `test_merge_two_legends_keeps_survivor_year_and_rehomes_memories`, `test_park_officer_cannot_merge_a_legend`, `test_legend_with_park_id_zero_does_not_break_gate`.
- [ ] **Step 2: Run them and confirm they fail.**
- [ ] **Step 3: Implement the gates.**
- [ ] **Step 4: Run `--filter LegendEditAuthorityTest`, then `--filter "Player|Merge|Banner|Heraldry"`.**
- [ ] **Step 5: Commit.**

### Task 6: Search

**Files:**
- Modify: `system/lib/ork3/class.SearchService.php:368, 601, 780`
- Modify: `orkui/controller/controller.SearchAjax.php`, `controller.KingdomAjax.php:814`, `controller.ParkAjax.php:178`, `controller.AdminAjax.php:27`, `controller.EventAjax.php:282`
- Modify callers: `orkui/template/revised-frontend/script/revised.js`, `orkui/template/revised-frontend/Admin_index.tpl`, `Recommendations_manage.tpl`, `Playernew_reconcile.tpl`, and `orkui/template/default/` `Search_index.tpl`, `Award_addawards.tpl`, `Admin_moveplayer.tpl`, `Admin_mergeplayer.tpl`
- Test: `tests/Integration/LegendSearchTest.php`

**Interfaces:**
- Consumes: Task 1 columns.
- Produces: `IncludeLegend` request key and `include_legend=1` query param, default off in all three backends. Rows gain `Legend` (0/1) and `LegendYear`.

Opt in with `include_legend=1` at: Given To (`revised.js:3642, 7764`, `Award_addawards.tpl`), Given By (`revised.js:2376, 3695, 7819, 13147`, `Recommendations_manage.tpl:1459`, `Playernew_reconcile.tpl:329`), merge (`revised.js:15366, 15979`, `Admin_mergeplayer.tpl`, `Admin_index.tpl:1101`), header (`default.theme:628`), Advanced Search (`Search_index.tpl:285`) and ORK admin move (`Admin_index.tpl:966, 1062`, `Admin_moveplayer.tpl`).

On the move surfaces, the server honors the opt-in only for a global admin. Keep the legacy `Admin_*` move and merge pickers working without adding new entry points. `UniversalSearch` uses `(m.active = 1 OR m.is_legend = 1)` when opted in. Hide Legends from `include_inactive=1` unless also opted in.

- [ ] **Step 1: Write the failing tests:** `test_each_backend_hides_legend_by_default`, `test_include_inactive_alone_does_not_show_legend`, `test_include_legend_shows_with_flag_in_each_backend`, `test_scope_still_applies_with_include_legend`, `test_universal_finds_legend_when_opted_in`, `test_output_carries_legend_fields`.
- [ ] **Step 2: Run them and confirm they fail.**
- [ ] **Step 3: Implement backends, endpoints and callers.** Add the Legend pill in the hand-copied badge sites (`revised.js:2386, 3649, 3702, 7771, 7826, 8293, 8971, 11576`, `Admin_index.tpl:969`) and a shared `.legend-pill` rule in `revised.css` with its dark variant.
- [ ] **Step 4: Run `--filter "LegendSearchTest|Search"`.**
- [ ] **Step 5: Curl each changed URL** with a term matching a Legend, once with the opt-in and once without, as a logged-in user using one cookie jar.
- [ ] **Step 6: Commit.**

### Task 7: Reports and lists

**Files:**
- Modify: `system/lib/ork3/class.Report.php:301, 332, 466, 539, 821, 1012, 1238, 2265, 3066, 3173, 3217, 3245, 4291, 4325, 4362, 4395, 4740, 8681`
- Modify: `system/lib/ork3/class.PopulationExplorer.php:936`, `class.Court.php:2776`, `class.Sitemap.php:117` (confirm only)
- Modify: `orkui/controller/controller.Recommendations.php:508`, `orkui/template/revised-frontend/_rm_row.tpl:53, 100`, `Recommendations_manage.tpl:1246, 1525`
- Modify: report templates that render the kept lists, `orkui/template/default/Unit_index.tpl` roster, `orkui/template/default/script/court-planner.js`
- Test: `tests/Integration/LegendReportsTest.php`

**Interfaces:**
- Consumes: `.legend-pill` from Task 6.
- Produces: `IsLegend` on report and recommendation rows.

Follow the spec's Reports and lists table exactly. Kept lists use `(m.active = 1 OR m.is_legend = 1)`. Excluded lists add `m.is_legend = 0`, including where `active` isn't filtered. Reconciliation prompts never offer a Legend. Recommendations show the Legend pill in place of `retired`, and the CSV gains a Legend column. Non-Legend retired recipients keep today's wording.

- [ ] **Step 1: Write the failing tests:** `test_roster_excludes_legend`, `test_active_players_excludes_legend`, `test_reeve_corpora_crown_qualified_exclude_legend`, `test_voting_eligibility_excludes_legend_and_never_prompts_reactivation`, `test_dues_paid_excludes_lifetime_legend`, `test_population_explorer_excludes_by_default_and_has_criterion`, `test_knights_peerage_masters_custom_awards_keep_legend_with_flag`, `test_ladder_grid_keeps_legend`, `test_unit_roster_keeps_legend_with_flag`, `test_recommendations_flag_legend_and_retired_stays`, `test_court_planner_rows_carry_is_legend`.
- [ ] **Step 2: Run them and confirm they fail.**
- [ ] **Step 3: Implement queries, row flags and pills.**
- [ ] **Step 4: Run `--filter "LegendReportsTest|Report|LadderGrid|OfficerDirectory|PopulationExplorer|Recommendation|Court"`.**
- [ ] **Step 5: Commit.**

### Task 8: Memory Wall backend

**Files:**
- Modify: `system/lib/ork3/class.Legend.php`, `orkui/model/model.Legend.php`, `orkui/controller/controller.LegendAjax.php`
- Test: `tests/Integration/LegendMemoryTest.php`

**Interfaces:**
- Consumes: Task 1 and Task 2.
- Produces:
  - `Legend::MAX_MEMORY_CHARS = 2000`
  - `Legend::memories(int $mundaneId): array`: rows with `MemoryId`, `AuthorId`, `AuthorPersona`, `Body`, `Created`, newest first, removed rows excluded, empty for a non-Legend
  - `Legend::addMemory(int $uid, int $mundaneId, string $body): array`
  - `Legend::removeMemory(int $uid, int $memoryId): array`
  - Routes `LegendAjax/memories/{id}`, `add/{id}`, `remove/{memoryId}`

`addMemory` needs a logged-in, non-suspended author and a Legend target, trims the body, refuses empty or over-limit text, and runs `ProfanityFilter` with its `ERROR_MESSAGE`. `removeMemory` needs `mayManage` on the memory's player and stamps `removed_by_id` and `removed_at`. `memories` is readable logged out. Output escaping happens in the UI, and the JSON stays raw.

- [ ] **Step 1: Write the failing tests:** `test_add_memory_records_author_and_date`, `test_add_refused_when_logged_out_or_suspended`, `test_add_refused_for_non_legend`, `test_add_refuses_empty_and_over_2000_chars`, `test_add_runs_profanity_filter`, `test_memories_newest_first`, `test_remove_refused_for_author_and_park_officer`, `test_remove_soft_deletes_for_kingdom_officer_and_ork_admin`, `test_removed_memory_not_listed`, `test_memories_hidden_after_undo_and_back_after_reconvert`, `test_script_tag_stored_verbatim`.
- [ ] **Step 2: Run them and confirm they fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run `--filter LegendMemoryTest` and confirm it passes.**
- [ ] **Step 5: Commit.**

### Task 9: Legend profile

**Files:**
- Modify: `orkui/controller/controller.Player.php` (profile data)
- Modify: `orkui/template/revised-frontend/Playernew_index.tpl:1115-1124, 1164-1202, 1223, 1239, 1515-1580`
- Modify: `orkui/template/revised-frontend/script/revised.js`, `orkui/template/revised-frontend/style/revised.css`
- Test: `tests/Integration/LegendProfileTest.php`

**Interfaces:**
- Consumes: `LegendAjax` routes from Tasks 2 and 8, `.legend-pill` from Task 6, `IsLegend` and `LegendYear` from Task 1.
- Produces: template vars `IsLegend`, `LegendYear`, `CanManageLegend`, `Memories`.

Build to the approved mockups, using the spec's colors and sepia filter:
- Sepia on `.pn-avatar img` only, through a `.pn-legend` class on `#pn-hero`.
- A separate In Memoriam card between the hero and `.pn-stats-row`, with the exact banner copy and the plain persona.
- The Legend pill replaces the Active, Waiver, Dues and Voting Eligible pills. Keep New Player off. Officer and admin pills go away because the data was cleared.
- The button reads `Recommend Posthumous Award` and uses the existing recommendation modal, which sends `FromProfile`.
- The Memory Wall tab comes first and is the default panel, and shows a count.
- `Share a Memory` reveals the form with the exact copy. A logged-out visitor sees a prompt to log in instead.
- Remove uses an inline in-page confirmation, for `CanManageLegend` only.
- `Mark as Legend`, `Remove Legend Status` and year edit show for `CanManageLegend` only, in the custom modal pattern used on this page. The convert modal loads `preview`, and the undo modal lists what was cleared.
- Hide My Amtgard-only and edit controls according to `canEditAdmin`.
- Dates display like `October 12, 2026`. Render all user text with `htmlspecialchars` in PHP and `textContent` or the page's escape helper in JS.

- [ ] **Step 1: Write the failing tests:** `test_profile_data_for_legend_viewer_roles`, `test_can_manage_legend_false_for_park_officer`, `test_template_renders_exact_banner_copy_with_escaped_persona`.
- [ ] **Step 2: Run them and confirm they fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run `--filter LegendProfileTest`, and `node --check` on `revised.js`.**
- [ ] **Step 5: Exercise it in Chrome** in both themes and at phone width through the iframe harness: convert, view, post, remove and undo. Confirm nothing overflows and the computed heading colors are right.
- [ ] **Step 6: Commit.**

### Task 10: Notifications, release notes and docs

**Files:**
- Modify: `system/lib/ork3/class.Notification.php:124, 196`, `class.Court.php:3493`
- Modify: `orkui/whats_new_content.php`, `orkui/template/default/docs_modal.tpl`
- Test: `tests/Integration/LegendNotificationTest.php`

**Interfaces:**
- Consumes: `Legend::isLegend`.
- Produces: nothing new.

Skip in-app notifications addressed to a Legend. Add a What's New release entry and bump `WHATS_NEW_VERSION` and `ORK_VERSION`. Add an in-app docs article on Legend status, built with a script as the docs file's history recommends, then `node --check` the extracted script and assert the article id is unique.

- [ ] **Step 1: Write the failing tests:** `test_recommender_notice_skips_legend`, `test_pass_down_notice_skips_legend`, `test_unrecorded_court_notice_skips_legend`.
- [ ] **Step 2: Run them and confirm they fail.**
- [ ] **Step 3: Implement all three changes.**
- [ ] **Step 4: Run `--filter LegendNotificationTest` and the docs checks.**
- [ ] **Step 5: Commit.**

### Task 11: End-to-end verification

**Files:** none planned. Fixes go in the file at fault, each with a test.

- [ ] **Step 1: Run the full suites:** `vendor/bin/phpunit --testsuite unit`, then `vendor/bin/phpunit --testsuite integration`. Compare any failures against `971d2c9d6` and report them.
- [ ] **Step 2: Run the role walk on the local stack.** Convert a test player as a kingdom officer, then confirm as a park officer, a regular player and an ORK admin: pickers, profile controls, moves, merge, login and an open session.
- [ ] **Step 3: Check principality behavior** with a parent-kingdom officer.
- [ ] **Step 4: Run the schema drift gate** in `tools/ork-db` and confirm the new migration is classified.
- [ ] **Step 5: Run `/layers-review` lenses on the branch** for layer separation, dark mode and CSS, and fix what's real.
- [ ] **Step 6: Check `orkmobile`** and record whether it's live here. It's an uninitialized submodule, so say so if it can't be checked.
- [ ] **Step 7: Commit any fixes.**
