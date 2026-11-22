# Survey Module: Org Sections, Results Sharing, Bonus Credits (Design Spec)

**Date:** 2026-09-10
**Status:** Approved design, ready for implementation plan
**Branch:** `feature/survey-module` (extends the module in `2026-09-09-survey-module-design.md`; section numbers written "base §N" refer to that file)
**Scope:** Three additions to the survey module:
1. The survey list page shows a kingdom's or park's surveys in three sections (Amtgard, Kingdom, Park).
2. A per-survey **results sharing** setting rolls results down one org level (ORK → kingdoms, kingdom → parks), either filtered to the viewer's own players or unfiltered.
3. **Bonus credits** for completing a survey: one class credit toward the player's levels, dated the day they answered, from a per-survey bonus-credit category that any kingdom or park the survey reaches can switch on for its own players (and off again). Not attendance: no park or kingdom tally, no voting effect. (Replaced the attendance-credit design of this section before it shipped; see §3.)

## Problem

Surveys are visible only to the org that owns them. A kingdom cannot see the ORK-wide survey its players are being asked to take, and a park cannot see its kingdom's survey. Leadership one level down has no access to results about its own players. Officers have no way to reward participation, which is the usual lever Amtgard groups use to raise turnout.

## Decisions (made by the owner, 2026-09-10)

| # | Question | Decision |
|---|---|---|
| D1 | How are credits dated, given the anonymity model? | Home-park credits are dated **the day the survey was taken**. **Only respondents who choose *Any ORK Data* earn a credit.** The data gate tells respondents so, and only a respondent it told is ever credited (`credit_notice`, §3.7; review fix 2026-09-11: a backfill had put public, dated credits on people who chose Any ORK Data before any credit line existed, and named them to a non-owner park). A partial response (day + kingdom + years-played band) next to a dated public credit would single most people out, so the middle tier earns no credit either. Event credits are dated the event's start date (forced by `AddAttendance`, see Constraints). |
| D2 | What do rolled-down viewers see? | **Charts and stats only**: summary, charts, free-text lists, cross-tab, Summary for sharing. No rows table, names, individual-response panel or CSV. The consent copy stays as written. |
| D3 | How is a respondent's park known for "Park only"? | A **home-park snapshot on the response, for *Any ORK Data* rows only**. A park view counts only those rows. |
| D4 | Who can turn on credits? | **Any org in the chain**: the owner, plus kingdoms and parks below it, for their own players. **One credit per player per survey**; the config switched on first wins. |
| D5 | Which surveys appear in inherited sections? | **Open and closed** (live first). Other orgs' drafts never; archived hidden. Sections the viewer manages show every status. |
| D6 | Grant pipeline | **Post-commit grant + idempotent reconcile sweep.** A credit failure never costs a response. |

## Current-State Constraints (verified)

- **Scoping today.** `Survey::canManage()` delegates to `canCreate()` on the survey row's own scope (`system/lib/ork3/class.Survey.php:152-181`). ORK surveys need ORK admin. Kingdom and park surveys need `HasAuthority(... AUTH_CREATE)`, which walks park → kingdom and principality → parent. A kingdom CREATE officer therefore **already manages every park survey in the kingdom**, named rows included (`manageableScopes()` `:188-280`, the "every park of an allowed kingdom" branch). `listManageable()` (`:391-478`) returns one flat list filtered to one scope. `Controller_Survey::index` parses `Survey/index/{Kingdom|Park}/{id}` (`orkui/controller/controller.Survey.php:36-73`).
- **Results gate.** `Survey/results` and `SurveyAjax/results|rows` require `canManage` (`controller.Survey.php:163-195`, `controller.SurveyAjax.php:648-664`).
- **Responses carry no park.** `SurveyResponse::scrubForConsent()` (`class.SurveyResponse.php:89-124`) keeps `mundane_id` and `kingdom_id` for `full`, `kingdom_id` plus a tenure band for `partial`, and nothing for `anonymous`. Report filters go through `SurveyReport::responseWhere()` and `reportWhere()` (`class.SurveyReport.php:2051-2120`); `reportWhere()` applies the small-partial-kingdom rule under a kingdom filter (base §2 rule 5).
- **Attendance writes need a token.** `Attendance::AddAttendance()` (`system/lib/ork3/class.Attendance.php:66-168`) authorizes through `AttendanceAuthority()` (`:260-323`) against the caller's session token. There is no system user. The precedent for a system-written credit is self-registration's raw insert (`class.Player.php:2151-2158`: class 6, `note='Self-registration'`, `entry_method='self_reg'`, date partitions computed in SQL). Player attendance caches are busted by `bustPlayerAttendanceCaches()` (`class.Attendance.php:439`).
- **`ork_attendance`**: every column NOT NULL. It has `date_year/date_month/date_week3/date_week6` partitions, `by_whom_id`, `entry_method ENUM('manual','signin_link','self_reg','bulk_import')` (`db-migrations/2026-05-31-attendance-entry-method.sql`: "Always append new values"), and `entered_at`. The unique key is `(mundane_id, date, park_id, kingdom_id, event_id, event_calendardetail_id, persona, note, class_id)`. `note` is `varchar(20)`.
- **Class progress counts the first row per date only** (lowest `attendance_id` per `date`, `class.Player.php:1724`).
- **Last class**: `Attendance::GetPlayerLastClass()` (`class.Attendance.php:486-501`) returns 0 when the player has no attendance. **Color is `class_id = 6`** (a literal throughout, e.g. `class.Player.php:2158`).
- **Event credits are dated the occurrence start.** On the event path `AddAttendance` overwrites `date` with `EventStart`, takes `park_id` from the occurrence's `at_park_id` (0 when unset), and takes `kingdom_id` from the event (`class.Attendance.php:114-132`).
- **Event creation** is two token-gated calls, `Event::CreateEvent()` (`class.Event.php:546-608`) and `CreateEventDetails()` (`:821-877`), and the latter always calls Google geocoding. `EventPlanning::CreateEventWithCopy()` (`class.EventPlanning.php:1317-1496`) inserts the occurrence with raw SQL, sets `at_park_id`, skips geocoding and busts scope caches (`:2070-2100`). `ork_event.name` is `varchar(100)`. Occurrence text columns (`url`, `address`, `city`, ...) and `latitude/longitude` are NOT NULL with no default; production runs `sql_mode=''`. An occurrence cannot be deleted while it has attendance (`class.Event.php:232-258, 996`). A published occurrence whose window covers today triggers an "X is currently happening" prompt on the attendance pages (`controller.Attendance.php:106, 248`).
- **Player page "By" column** labels `signin_link` and `self_reg` rows specially (`orkui/template/revised-frontend/Playernew_index.tpl:7419-7432`).
- **Survey audience "attended recently"** reads `ork_attendance` in scope (`SurveyResponse::attendedRecently()`, `class.SurveyResponse.php:385-395`).
- **Cron precedent**: `bin/compute-weekly-recap.php` (header documents the cron line).
- **Layering**: SQL only in `system/lib/ork3/`; `Model_Survey` is the membrane and instantiates domain classes (`orkui/model/model.Survey.php` factories `_survey()/_response()/_report()`); controllers and templates never touch `$DB` (base Constraints).

## Design

### 1. Org sections on the survey list

**Data.** New `Survey::listForScope(int $uid, ?string $scopeType, ?int $scopeId): array` returns `['ork' => rows, 'kingdom' => rows, 'park' => rows]`. Each row is today's `listManageable()` row plus:
- `Access`: `manage` or `shared`
- `CanResults`, `ResultsLens` (label or null)
- `CanCredit`
- `CreditChip`: a config covers the viewer's org

| Viewer (route) | `ork` bucket | `kingdom` bucket | `park` bucket |
|---|---|---|---|
| Kingdom K (`Survey/index/Kingdom/K`) | ORK surveys whose audience reaches K (`audience_kingdom_ids` NULL, or it contains K or K's parent). `open`/`closed` only, unless the viewer is an ORK admin | Surveys of K and its principalities, every status | Surveys of every park in K and its principalities, every status |
| Park P (`Survey/index/Park/P`, P in kingdom K) | ORK surveys reaching K. `open`/`closed` only | Kingdom surveys whose audience reaches P's players (K; K's parent when K is a principality). `open`/`closed` only | P's surveys, every status |
| Unscoped (`Survey/index`) | Everything the viewer manages, grouped by `scope_type` (no inherited rows) | same | same |

Gates are unchanged: `Kingdom/K` requires `canCreate(uid,'kingdom',K)` and `Park/P` requires `canCreate(uid,'park',P)` (ORK admins pass both). A request for an org the viewer cannot act for gets `no_authorization()`. Inherited rows are always `Access=shared`. A row in a bucket the viewer manages (by `canManage`) is `Access=manage`.

**Page (`Survey_index.tpl`).** Three stacked `.rp-table-area` blocks. Each has a section heading ("Amtgard", the kingdom's name, "Parks" or the park's name), a count and its own DataTable (same columns, `data-order` keys and paging as today). The existing status pills filter all three tables. An empty section shows one line ("No Amtgard-wide surveys right now.") rather than disappearing.

Row actions:
- **Manage rows:** today's set (Build, Results, Preview, Clone, Copy link, Archive) plus **Credits**.
- **Shared rows:**
  - **Take**, when open; links to `Survey/s/{slug}`, and the runner decides eligibility.
  - **Results**, only when `CanResults`; labelled "Results (your kingdom)" or "(your park)" under a lens.
  - **Credits.**

Responses column on shared rows: the number when `results_share='all'`, otherwise "—".

### 2. Results sharing (rolldown)

**Setting.** `ork_survey.results_share ENUM('none','scoped','all') NOT NULL DEFAULT 'none'`. It is edited in the builder's **Privacy** sidebar card, on `ork` and `kingdom` surveys only; a park survey stores `none` and `update` refuses anything else. It can change at any time: shared viewers never see identity, so the consent copy is unaffected (D2).

| Survey scope | Label | `none` | `scoped` | `all` |
|---|---|---|---|---|
| `ork` | Share results with kingdoms | Don't share | Each kingdom sees its own players | Every kingdom sees all results |
| `kingdom` | Share results with parks | Don't share | Each park sees its own players | Every park sees all results |

Rolldown is exactly one level. Park officers get no results for ORK surveys.

**Access.** New `Survey::resultsAccess(int $uid, array $surveyRow, ?array $context): ?array` returns one of:
- `['level' => 'manage']` when `canManage`
- `['level' => 'shared', 'lens' => [...], 'label' => '...']`
- `null`

`$context` is the viewer's org, `{type, id}`, from the results URL `Survey/results/{id}/Kingdom/17` or `/Park/1049`. The controller explodes the collapsed route string (base Constraints, Routing). Shared access requires all of:
- `results_share != 'none'`
- the context org is the survey's **direct child type** (`kingdom` for an `ork` survey, `park` for a `kingdom` survey)
- the survey reaches that org: for an ORK survey, the context kingdom is in the audience; for a kingdom survey K, the park is in K or a principality of K
- `canCreate(uid, context.type, context.id)`
- survey status is `open` or `closed`
- **timing (added 2026-09-11)**: `results_share_timing` is `ongoing`, or `after_close` (the default) and `Survey::sharingOpensAt()` has passed. That is 24 hours (`SHARE_DELAY_HOURS`) after the survey stopped taking responses: the earlier of a manual close (`closed_at`) and a scheduled `close_at` that has passed. It is null while the survey still takes responses; `setStatus('open')` clears `closed_at`, so a reopen hides shared results again. A viewer who passes every other rule but not the timing gets `Survey::resultsPending()` = `['opens_at' => ?string]`. The results page shows `Survey::sharingPendingText()` instead of "no permission", `SurveyAjax/results` answers `{status:3, pending:true, opens_at, error:<that text>}`, and list rows carry `ResultsPending` / `ResultsOpensAt` / `ResultsPendingText` (a muted, inert Results control with a clock icon whose tip says when). Owners always see live results. The builder's Privacy card has a **When** radio pair (`ResultsShareTiming`), disabled while sharing is "Don't share". Clone copies it. Column: `ork_survey.results_share_timing ENUM('ongoing','after_close') NOT NULL DEFAULT 'after_close'` (in the `2026-09-11` migration).

`share = 'all'` gives an empty lens. `share = 'scoped'` gives:
- **Kingdom lens** `['kingdom_ids' => [K, …K's principalities]]`
- **Park lens** `['park_id' => P]`

**Lens enforcement (`SurveyReport`).** A new pure `SurveyReport::applyLens(array $filters, array $lens): array` folds the lens into the normalized filters **before** anything else reads them, so every surface (summary, aggregate, cross-tab, suppression, caches) sees one filter set and the user cannot remove the lens:
- `kingdom_ids`: the lens list, or its intersection with the user's pick. An empty intersection becomes `[-1]`, which matches nothing. The existing `reportWhere()` small-partial-kingdom rule and forced cells apply unchanged. Anonymous rows have no kingdom and fall out, as they do under any kingdom filter today.
- `park_id` (new filter key, handled in `responseWhere()` as `r.park_id = P`) plus `consent = 'full'`. A conflicting consent pick becomes an impossible filter.
- `isNarrowing()` treats `park_id` as narrowing, so a lens view with fewer than 5 responses is suppressed (base §2 rule 1). Completion and response rate are null ("—"). The GhettoCache key already hashes the normalized filters, so it carries the lens.

**Park snapshot.** New `ork_survey_response.park_id INT NULL`. `submit()` passes the player's current `ork_mundane.park_id` into the row, and `scrubForConsent()` nulls it for `partial` and `anonymous`, so it survives only on `full` rows (D3). The migration backfills existing `full` rows from `ork_mundane.park_id`, which is the best value available.

**Shared results page.** `Controller_Survey::results` accepts the context and calls `results_access`. `null` gets `no_authorization()`. `SvConfig` gains `access` (`manage|shared`), `context` and `lensLabel`. In shared mode:
- **Shown:** the stat row, charts, free-text and "Other" lists, cross-tab, the consent filter, Summary for sharing.
- **Hidden:** the Responses table, the individual-response panel, Export CSV, Print (Summary for sharing's print stays), the include-test toggle (forced off server-side for shared viewers), and the kingdom filter under a kingdom lens.
- **No date filters and no per-day counts** (`summary.by_day` is null), for every shared viewer including an `all` share; `applyLens` drops `date_from`/`date_to` server-side. Home-park credits are public and dated the day taken (D1), so comparing two date windows (`[.., D]` and `[.., D-1]`, each over the minimum cell) would give the answers and free text of the one respondent on day D whom a credit names. Suppression is per view and cannot catch that. (Review fix, 2026-09-11.)
- `park_id` and `impossible` are lens-only filter keys. `SurveyReport::clientFilters()` drops them from any client's Filters, so only `applyLens` sets them and no viewer can slice results to one park.
- **Lens strip** under the header:
  - Kingdom lens: *"Showing responses from players of {Kingdom} who chose Any ORK Data or My Kingdom and How Long I've Been Playing. Anonymous responses can't be attributed to a kingdom."*
  - Park lens: *"Showing responses from {Park} players who chose Any ORK Data. Other responses can't be attributed to a park."*
  - `all`: *"Shared by {owner}: all respondents."*

**Server enforcement.** `SurveyAjax/results` accepts `Context` (`Kingdom/17`) and resolves access through `results_access`. A shared viewer's filters pass through `applyLens`, and `include_test` is forced false. `SurveyAjax/rows`, `Survey/export` and every builder action keep `requireManage`, so shared viewers get status 3. The UI hides these controls, but hiding is not the security boundary.

### 3. Bonus credits

> **Replaced before shipping (2026-11-11).** The design below first posted an *attendance* row per
> credit (home-park or a generated "Survey Credit" event). Before any of it reached production the
> ORK gained bonus credits (`class.BonusCredit.php`, 2026-10-14): class credits that are not
> attendance. The survey credit became one of those. What follows is the shipped design; the
> attendance variant survives only in git history.

#### 3.1 The category and the switches

Each survey owns ONE bonus-credit category, created by the system the first time any org switches
credit on and remembered in `ork_survey.bonus_category_id`:

- `name` = `'Survey Bonus - ' . title`, cut to 100 characters (multibyte-safe, trailing `…`). When a
  category of the kingdom already holds that name (manual, archived, or an older survey of the same
  title) the name takes a ` (#survey_id)` suffix (`SurveyCredit::categoryName($title, $surveyId)`),
  the suffix kept whole and the title giving way.
- `source_type` `'survey'`, `source_id` = `survey_id`, `default_credits` 1.00, `repeatable` 0.
- Scope: the owning kingdom for a kingdom or park survey (`scope_type 'kingdom'`); `'ork'` for an
  Amtgard-wide survey. A kingdom switching credit on for an Amtgard-wide survey ADOPTS the category
  (`ork_bonus_credit_adoption`), which is what lets the credit reach its players. A park may switch
  on for an Amtgard-wide survey only once its kingdom has adopted.
- Created by `BonusCredit::ensureSourceCategory()` with NO authority check: the enabler is the
  officer `Survey::canCreate()` admits for the grantor org, who may be a park officer without the
  kingdom CREATE that `createCategory()` demands. Renamed with the survey (`renameSourceCategories`),
  archived with it (`archiveSourceCategories`), deleted with a deleted draft when it never granted
  (`deleteSourceCategories`; one that did grant is archived instead).

```sql
CREATE TABLE IF NOT EXISTS ork_survey_credit (
  credit_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  survey_id      INT UNSIGNED NOT NULL,
  grantor_type   ENUM('kingdom','park') NOT NULL,
  grantor_id     INT NOT NULL,
  enabled_by     INT NOT NULL,          -- becomes granted_by on every credit
  enabled_at     DATETIME NOT NULL,     -- precedence: earliest wins
  disabled_by    INT NULL,
  disabled_at    DATETIME NULL,
  PRIMARY KEY (credit_id),
  UNIQUE KEY uq_survey_grantor (survey_id, grantor_type, grantor_id),
  KEY idx_survey_enabled (survey_id, enabled_at)
);

-- One credit per player per survey. The row stays when the credit is revoked.
CREATE TABLE IF NOT EXISTS ork_survey_credit_grant (
  survey_id        INT UNSIGNED NOT NULL,
  mundane_id       INT NOT NULL,
  credit_id        INT UNSIGNED NOT NULL,
  bonus_credit_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (survey_id, mundane_id),
  KEY idx_credit (credit_id)
);
```

A config is one org's **switch**. Switching off stamps `disabled_at`: responses submitted before it
are still owed, later ones are not (`SurveyCredit::configLiveFor`). Switching on again clears the
stamps and the original `enabled_at` keeps its place in the precedence order. Credits already given
are untouched by either; officers revoke them one at a time under Bonus Credits, and a revoked
credit is never granted again because the ledger row outlives it.

#### 3.2 Who may switch on, and who is covered

**Valid grantors** (`SurveyCredit::validGrantor`), unchanged: an Amtgard-wide survey reaches any
active kingdom its audience names and any park in such a kingdom; a kingdom survey its kingdom, its
principalities and their parks; a park survey that park only. The acting officer needs
`canCreate(uid, type, id)`; a grantor other than the owner needs the survey `open` or `closed`
(D5); the owner may also switch on a `draft`. Nobody switches on an archived survey.

**Who a switch covers** (pure `SurveyCredit::coverage(array $configs, array $respondent, array $surveyRow, array $parentOf): ?int`):
- Only non-test responses with `consent = 'full'` and `credit_notice = 1` (D1).
- Switches off before the response's `submitted_at` are skipped.
- A **park** grantor covers a respondent whose snapshotted `park_id` is the park.
- A **kingdom** grantor covers a respondent whose snapshotted `kingdom_id` is it or has it as parent.
  No park is needed: a bonus credit has no park.
- The survey **owner's** switch covers everyone who answered, visitors included (what event mode
  used to do). A visitor's own kingdom then decides at grant time: the kingdom credit crosses to
  them only where that kingdom accepts outside credits (`ork_bonus_credit_kingdom_setting`, on by
  default); an Amtgard-wide credit only where their kingdom adopted it. Respondents the rules keep
  out are counted as **Skipped**, stay owed, and post once the rule changes.
- **Precedence:** among covering switches the earliest `enabled_at` wins (ties: lower `credit_id`).

#### 3.3 The credit row

`BonusCredit::grantFromSource(array $r)`: NO authority check (the caller authorized it); applies
only the kingdom-level rules an officer could not waive (live category, accept-outside, adoption,
once-only). Values: `mundane_id` the respondent; `class_id` the player's last class, or 6 (Color)
when they have none; `credits` 1.00; `credit_date` = `DATE(response.submitted_at)`; `category_id`
the survey's; `note` `'Survey #' . survey_id`; `granted_by` = `ork_survey_credit.enabled_by`.
Reason codes (`outside`, `unadopted`, `archived`, `already`, …) let `SurveyCredit::grant()` tell
"not owed yet" (skipped) from "failed" (pending).

`SurveyCredit::grant()` wraps the bonus insert and the ledger insert in one transaction and rolls
back on either failure. A player who already holds the category's credit by an officer's hand is
ledgered against that credit instead of being credited twice. The player's class-level caches are
busted after COMMIT.

The only reader of bonus credits is `Player::GetPlayerClasses()`; nothing that counts attendance
(voting, tallies, the recap, the audience rule) can see them.

#### 3.4 Pipeline (D6)

- **Live grant:** after `SurveyResponse::submit()` commits, for a non-test `full` response with
  `credit_notice = 1`, `SurveyCredit::grantFor($surveyId, $mundaneId)`; best-effort, logged as
  `[survey-credit] grant failed {survey_id, uid, stage, db_error}`; `submit` returns `credit`:
  `granted`, `pending` or `none`.
- **Reconcile** `SurveyCredit::reconcile($surveyId): array{Granted, Skipped, Pending}` posts every
  owed credit, idempotent, bounded in a web request (`SYNC_GRANT_CAP`, `SYNC_SECONDS`; the rest is
  `Remaining`) and unbounded in `bin/survey-credit-sweep.php`, which needs no site host any more.
  A banned or currently suspended player's credit is held (`held` in the panel) until the sanction
  lifts.
- **Data gate lock:** `update` refuses `DataGateEnabled=0` while any switch is on (*"This survey
  gives bonus credits, which need respondents to be able to choose Any ORK Data."*). Off switches
  do not lock it.
- **Clone** copies neither switches nor the category.
- **Activity log:** `action='credit'`, detail `{action: enable|disable, credit_id, grantor_type,
  grantor_id, category_id, backfilled}` (`backfilled` and `category_id` on enable only).

#### 3.5 Credits panel (UI)

One modal, **Bonus credit**, opened from the list's **Credits** row action (the section's org as
grantor) and the builder's **Bonus credit** card (the owner). From `credit_status`:
1. The category line: its name and whose it is, or the name it will get.
2. **This survey's credit switches:** one line per visible config — org, on since / on from–to, now
   off, and its credit count.
3. **Your org:** on → a status line and **Turn off credit**; off and allowed → the warning
   (eligible-now count, the "levels only, dated the day answered, can be turned off" line,
   `no_notice`) and **Turn on credit**; otherwise `blocked_reason`.

`onChange(on)` tells the host page which way the switch went; the automatic reconcile never calls
it.

#### 3.6 Respondent side

As before, with the wording changed: data-gate line *"This survey gives a bonus credit toward your
class levels, which will appear on your ORK profile. It is only given when you choose **Any ORK
Data**."* (or *"may later give"*); chip *Earns a bonus credit*; thank-you line *"Your bonus credit
has been added."* / *"… will be added shortly."* `credit_notice` is stored and honoured exactly as
in D1.

#### 3.7 Bonus Credits pickers

Every place a category is chosen (the grant modal's Category select, the granter scope select, the
Categories list) groups categories by `source_type`: hand-made credits first, then **Survey
credits** together, so an officer granting a survey credit by hand finds it under its own heading.

### 4. Schema migration

`db-migrations/2026-09-11-survey-sharing-credits.sql`, idempotent, classified `{ "class": "S", "render": "full" }` with a note in `tools/ork-db/manifests/migration-classification.json5`:

```sql
ALTER TABLE ork_survey
  ADD COLUMN IF NOT EXISTS results_share ENUM('none','scoped','all') NOT NULL DEFAULT 'none' AFTER data_gate_enabled;

ALTER TABLE ork_survey_response
  ADD COLUMN IF NOT EXISTS park_id INT NULL AFTER kingdom_id,
  ADD KEY IF NOT EXISTS idx_survey_park (survey_id, park_id);

-- D1 / §3.7: the data gate showed this Any ORK Data respondent a credit line.
-- No backfill: existing rows were never told.
ALTER TABLE ork_survey_response
  ADD COLUMN IF NOT EXISTS credit_notice TINYINT(1) NOT NULL DEFAULT 0 AFTER park_id;

-- Guarded backfill: full rows only, never overwrites a snapshot.
UPDATE ork_survey_response r
  JOIN ork_mundane m ON m.mundane_id = r.mundane_id
   SET r.park_id = m.park_id
 WHERE r.consent = 'full' AND r.mundane_id IS NOT NULL AND r.park_id IS NULL;

ALTER TABLE ork_survey
  ADD COLUMN IF NOT EXISTS bonus_category_id INT UNSIGNED NULL AFTER results_share_timing;

-- ork_survey_credit, ork_survey_credit_grant: DDL in §3.1.
```

The file never ran in production, so the 2026-11-11 reshape was folded into it rather than
converted by a later migration; a dev database that applied the earlier shape drops the two
credit tables and re-runs it. It depends on `2026-10-14-bonus-credits.sql`.

After applying: `docker restart ork3-php8-app` (APCu schema cache), then refresh the sandbox.

### 5. AJAX contract additions (`SurveyAjax`)

| Action | CSRF | POST | Returns |
|---|---|---|---|
| `results` (changed) | read allowlist | `+ Context` (`Kingdom/17` \| `Park/1049`, optional) | as base §6, filtered through the lens for shared viewers; `summary.lens = {label}` |
| `credit_status` | read allowlist | `SurveyId, Grantor` (`Kingdom/17` \| `Park/1049`) | `{survey_title, survey_status, gate_enabled, category:{category_id, name, scope_type, scope_name, archived}\|null, category_name, configs:[{credit_id, grantor_type, grantor_id, grantor_name, enabled_at, disabled_at\|null, enabled, granted}], mine:{grantor_type, grantor_id, name, config_id\|null, enabled, can_enable, can_disable, blocked_reason, covered_by:{credit_id, name}\|null, preview:{eligible_now, no_notice}\|null}\|null, pending, held}`. `mine` is null when the caller does not act for `Grantor`. **Writes nothing.** |
| `credit_enable` | required | `SurveyId, Grantor` | `{credit_id, granted, skipped, pending, remaining, already}`; status 1 for gate off, an invalid grantor, an archived survey or a park going first on an Amtgard-wide survey; status 3 without `canCreate` on the grantor. Switching on an org that is already on is a success with `already`. |
| `credit_disable` | required | `SurveyId, Grantor` | `{credit_id}`; same gates as `credit_enable`; status 1 when the org never switched on |
| `credit_reconcile` | required | `SurveyId, Grantor` (optional) | `{granted, skipped, pending, remaining}` (counts only the configs the caller's panel shows); allowed when the caller `canManage`s the survey **or** acts for `Grantor`; otherwise status 3 |

`update` gains `ResultsShare` (`none|scoped|all`; must be `none` on a park survey). `submit` takes `CreditNotice` (0/1, §3.7) and gains the `credit` field. `definition` and `available` gain `credit_available`.

### 6. Layers and files

- **Domain (`system/lib/ork3/`)**:
  - New `class.SurveyCredit.php`: `validGrantor`, `grantorsFor($uid, $surveyRow)`, `coveringCredit` (pure), `startDate` (pure), `eventName` (pure), `status`, `preview`, `enable`, `grantFor`, `reconcile`, `onOpened`, `creditAvailableFor($surveyRow, $uid)`.
  - `class.Survey.php`: `listForScope`, `resultsAccess`, the `results_share` field in `update`, the `onOpened` hook in `setStatus`, the gate lock.
  - `class.SurveyResponse.php`: park snapshot, `credit` on submit, `credit_available`, the survey-credit exclusion in the audience rule.
  - `class.SurveyReport.php`: `applyLens`, the `park_id` filter, `isNarrowing`.
  - `class.Attendance.php`: `add_system_credit`.
  - `class.EventPlanning.php`: `create_system_event`.
  - `class.Event.php`: `GetActiveEventsAtScope` skips survey credit occurrences (§3.4).
- **Model**: `Model_Survey` gains thin delegates (`list_for_scope`, `results_access`, `credit_status`, `credit_enable`, `credit_reconcile`) and a `_credit()` factory.
- **Controllers**: `Controller_Survey::index` renders the three buckets; `results` parses the context and gates on `results_access`. `Controller_SurveyAjax` gets the `results` context and the three credit actions.
- **Templates/assets**: `Survey_index.tpl` (sections, row actions, Bonus credit modal), `Survey_results.tpl` + `survey-results.js` (shared mode, lens strip), `Survey_build.tpl` + `survey-build.js` (Results sharing select, Bonus credit card, the credit line in the consent quote), `survey-take.js` (gate line, chips, thanks line), `survey.css` / `survey-build.css` / `survey-results.css` (chips, strip, modal; dark mode), `Playernew_index.tpl` (the "Survey credit" By label and the widget chip). The Bonus credit modal markup and JS live in one shared include so the list page and builder use the same component.
- **CLI**: `bin/survey-credit-sweep.php`.
- **Docs**: `docs/survey-guide.md` (sharing and credits sections, served through `SurveyAjax/help`); extend the branch's existing Survey release note in `orkui/whats_new_content.php`.

### 7. Error handling

- Envelopes and status codes as base §8. Every credit and share gate resolves the survey row from its id, never trusting a scope from the request. The grantor context in the request is validated by `validGrantor` plus `canCreate`.
- A credit failure never fails `submit`: the response commits first.
- `grant()` is one transaction (attendance + ledger) and rolls back on the first failed statement. A duplicate ledger key (a concurrent grant) is treated as "already granted", not an error.
- `enable()` inserts the config, creates the event when possible and runs `reconcile()`. A reconcile failure leaves the config in place (it is permanent) and reports `pending`, which the panel shows with its reconcile call.
- A shared viewer asking for `rows`, `export` or any builder action gets status 3, with the same message as a stranger.
- The data-gate-off refusal and the "configs are permanent" state are explained inline in the panel, never with a native dialog.

### 8. Testing

**Unit (no DB):**
- `SurveyCreditTest.php`:
  - `coveringCredit` precedence: kingdom first then park, park first then kingdom, ties by id; the owner's event config covers visitors, a non-owner's does not.
  - The `validGrantor` matrix per survey scope.
  - `startDate` (open_at later than opened_at; never opened → null).
  - `eventName` 100-character multibyte truncation.
  - The note format stays ≤ 20 characters for 10-digit ids.
  - The class fallback to 6.
- `SurveyAggregateTest.php`: `applyLens` (intersection, empty intersection → impossible, park lens forces `full`, a conflicting consent pick → impossible); `isNarrowing` with `park_id`.
- `SurveyConsentTest.php`: `scrubForConsent` keeps `park_id` only for `full`.

**Integration (`tests/Integration/SurveyTest.php`, sandbox):**
- `listForScope` buckets for:
  - a kingdom officer: sees ORK open/closed surveys reaching the kingdom; not ORK drafts, not ORK surveys limited to other kingdoms, not another kingdom's parks;
  - a park officer: sees own kingdom and ORK surveys; not a sibling park, not another kingdom.
- `resultsAccess` matrix.
- Lens counts: a kingdom lens excludes anonymous and other kingdoms; a park lens counts `full` from that park only; a lens view under 5 is suppressed.
- `rows` and `export` refused for shared viewers.
- Credits:
  - enable backfills `full` only; partial and anonymous respondents get nothing;
  - only `full` respondents the gate told (`credit_notice = 1`) are credited, by the backfill or live; the others are counted as `no_notice` and never owed;
  - a second enable for the same grantor is refused;
  - precedence across a kingdom and a park config;
  - a live submit grants, dated `DATE(submitted_at)`, with `entry_method='survey'`, `note='Survey #id'`, class = last class or 6, `by_whom_id = enabled_by`;
  - event mode creates exactly one one-day published event and dates credits at its start;
  - reconcile is idempotent (run twice → the same row count);
  - gate-off enable refused, and gate-off update refused while a config exists;
  - the audience rule ignores survey credits.

**Curl:** each new action with `Expected:` JSON and a DB `SELECT` after each mutation (cookie-jar login as `heraldsbridge`, plus a kingdom-officer and a park-officer account for the shared paths).

**Browser (serial):**
- List sections for kingdom and park context.
- Shared results page in both lens types.
- Credits modal enable flow (warning copy, checkbox gate, count).
- Runner gate line and thank-you line.
- Player page "Survey credit" label.
- Dark mode and 360 px for each.

### 9. Acceptance criteria

1. `Survey/index/Kingdom/K` shows Amtgard, Kingdom and Park sections per §1; `Survey/index/Park/P` shows the ORK, its own-kingdom and its own-park surveys, and nothing from other kingdoms or parks. Inherited sections list open and closed only.
2. An ORK survey set to "Each kingdom sees its own players" gives a kingdom officer a results page filtered to that kingdom's full and partial respondents, with charts and stats only. "Every kingdom sees all results" is unfiltered but still charts-only. "Don't share" gives no results link and a 403 on the URL. The same holds for kingdom → park with "Each park sees its own players" counting Any ORK Data respondents from that park.
3. Shared viewers can never reach rows, the individual-response panel, CSV or any builder action (server-enforced). Lens views under 5 responses are suppressed.
4. An authorized officer at any valid grantor level can turn on credits once, per survey, after an irreversible-action warning showing the live backfill count. On enable, every earlier Any ORK Data respondent the config covers, and whose data gate showed a credit line, gets exactly one credit; the warning counts the ones who were not told, who get none. While open, new Any ORK Data respondents get theirs on submit. Nobody gets two credits for one survey.
5. Home-park credits are 1.00 credit at the respondent's snapshotted home park, dated the day they took the survey, in their last class or Color. Event credits land on a single published one-day "Survey Credit - {title}" event dated the survey's start date. Both carry `entry_method='survey'`, and the player page labels them "Survey credit".
6. Respondents covered by a credit see the fixed data-gate line saying credits need Any ORK Data; every other gate says a credit may come later. Only respondents shown one of those lines are ever credited. Partial and anonymous respondents are never credited.
7. A credit failure never loses a response; `reconcile` repairs it, and re-running it changes nothing.
8. `php -l` clean; unit and integration suites green; the migration is classified and `drift-check --strict` passes; no `$DB`, `Ork3::$Lib` or `new SurveyCredit(` under `orkui/` outside `orkui/model/`; dark-mode checklist and 360 px pass on every touched surface.

### 10. Risks and gotchas

- **Consent bias.** Rewarding only Any ORK Data will shift the consent mix toward `full`. This is an owner decision, recorded here so results readers know the consent split is not a neutral signal on credit-bearing surveys.
- **Public record.** A bonus credit shows on the player's ORK profile under their class levels, revealing that they took the survey and on which day. The data-gate line says so (on every gated survey, as a "may later" line when no credit is on yet), and only `full` respondents who were shown it are credited.
- **Re-enable reopens the window.** Switching an org's credit off and back on clears the `disabled_*` stamps, so responses submitted during the off window become eligible again. Deliberate; the unit tests pin it.
- **Stale snapshots.** Backfilled `park_id` on pre-migration `full` rows uses the player's current park, not the park at submission.
- **APCu schema cache.** Restart the app after the migration, or inserts into the new columns and tables silently fail.
- **Cron is optional.** Without it, a grant that failed at submit waits for the next panel open or enable; the thank-you screen says "will be added shortly".

### 11. Deferred ideas (not built; revisit after release feedback)

**Anonymous external participants via single-use codes.** Discussed 2026-11-12; parked until release feedback shows a need. Today every respondent is a signed-in player, and "anonymous" is a storage tier, not a way in. If people who have no ORK account (lapsed players, parents, event guests) need to answer, the preferred shape is the Qualtrics "personal link" model rather than the SurveyMonkey open link:

- The builder generates a batch of single-use codes (or QR cards) for a survey and hands them out in person or by mail.
- A code opens the runner without login and redeems exactly once. The response is stored exactly as Anonymous Only is today: no player, no kingdom, no park, submit time rounded to the day.
- A code table records only that a code was used, never which response it produced (same separation as `ork_survey_participation`).
- Code responses carry a marker so results can count and filter them apart from members; roster percentages, the kingdom and park lenses, and bonus credits never include them.
- The data gate needs a variant for respondents with no profile to link, since two of its three options would be meaningless.
- The runner endpoint must be cheap and read-only until submit; shared links get crawled, and non-NA visitors meet the Cloudflare challenge rule.

Rejected alternative: an open "anyone with the link" collector with cookie-based duplicate control. Familiar from SurveyMonkey, but ballot stuffing is trivial, a leaked link is a leaked survey, and the module's one-answer-per-person guarantee would no longer hold for that survey.
