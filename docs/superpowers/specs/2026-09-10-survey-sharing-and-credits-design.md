# Survey Module: Org Sections, Results Sharing, Attendance Credits (Design Spec)

**Date:** 2026-09-10
**Status:** Approved design, ready for implementation plan
**Branch:** `feature/survey-module` (extends the module in `2026-09-09-survey-module-design.md`; section numbers written "base §N" refer to that file)
**Scope:** Three additions to the survey module:
1. The survey list page shows a kingdom's or park's surveys in three sections (Amtgard, Kingdom, Park).
2. A per-survey **results sharing** setting rolls results down one org level (ORK → kingdoms, kingdom → parks), either filtered to the viewer's own players or unfiltered.
3. **Attendance credits** for completing a survey: at the player's home park on the day they took it, or at a generated "Survey Credit - {title}" event. Credits are backfilled for earlier respondents whose data gate told them about credits, and granted automatically to new ones.

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

### 3. Attendance credits

#### 3.1 Configs

```sql
CREATE TABLE IF NOT EXISTS ork_survey_credit (
  credit_id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  survey_id               INT UNSIGNED NOT NULL,
  grantor_type            ENUM('kingdom','park') NOT NULL,
  grantor_id              INT NOT NULL,
  mode                    ENUM('home_park','event') NOT NULL,
  event_id                INT NULL,               -- event mode; NULL until the survey has a start date
  event_calendardetail_id INT NULL,
  enabled_by              INT NOT NULL,           -- becomes by_whom_id on every credit
  enabled_at              DATETIME NOT NULL,      -- precedence: earliest wins
  PRIMARY KEY (credit_id),
  UNIQUE KEY uq_survey_grantor (survey_id, grantor_type, grantor_id),
  KEY idx_survey_enabled (survey_id, enabled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- One credit per player per survey. Written in the same transaction as the attendance row.
CREATE TABLE IF NOT EXISTS ork_survey_credit_grant (
  survey_id      INT UNSIGNED NOT NULL,
  mundane_id     INT NOT NULL,
  credit_id      INT UNSIGNED NOT NULL,
  attendance_id  INT NOT NULL,
  PRIMARY KEY (survey_id, mundane_id),
  KEY idx_credit (credit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
```

A config is **permanent**: no disable, no delete, no mode change (the owner's "cannot be reversed"). The credits it posts are ordinary attendance rows afterwards. Officers can still edit or delete an individual credit with the normal attendance tools, as with any credit. There is no bulk "un-grant".

#### 3.2 Who may grant, and who is covered

**Valid grantors** (`SurveyCredit::validGrantor($surveyRow, $type, $id)`), D4:

| Survey | Valid grantors |
|---|---|
| `ork` | any active kingdom (incl. principalities) the audience reaches; any active park in such a kingdom |
| `kingdom` K | K; a principality of K; any park in K or its principalities |
| `park` P | P only (kingdom officers of P's kingdom act for P, as they manage its surveys) |

The acting officer needs `canCreate(uid, type, id)`. For a grantor other than the owner, the survey must be `open` or `closed`, because those officers cannot see drafts (D5). The owner may also configure a `draft`.

**Who a config covers** (pure `SurveyCredit::coveringCredit(array $configs, array $respondent, array $surveyRow, array $parentOf): ?int`):
- Only non-test responses with `consent = 'full'` and `credit_notice = 1` (D1). A player who never had such a response is never covered.
- A **park** grantor G covers a respondent whose snapshotted `park_id = G`.
- A **kingdom** grantor G covers a respondent whose snapshotted `kingdom_id` is G or has parent G.
- In **event** mode, a config whose grantor **is the survey's owner** also covers every respondent, so visitors reached by an event audience (base §1) get credit at the owner's event.
- **Precedence:** among covering configs, the earliest `enabled_at` wins (ties: lower `credit_id`). The ledger primary key guarantees one credit per player per survey. Backfill order and live-grant order therefore agree: the earlier config has already credited everyone it covers.

#### 3.3 The credit row

New internal method `Attendance::add_system_credit(array $r): array` in `class.Attendance.php`. The snake_case name is required: `orkservice/Json/index.php` exposes every public method of `Attendance`, and `JsonServer` refuses only names containing `_` (PHP method names are case-insensitive, so a camelCase name would be callable there without a login). It takes no token: **the caller is responsible for authorization** (`SurveyCredit` checks the config was created by an authorized officer). It computes the date partitions the same way as `AddAttendance`, stamps `entered_at`, calls `bustPlayerAttendanceCaches()`, and returns `['Status'=>0,'AttendanceId'=>n]` or a failure envelope. Values:

| Column | Home-park mode | Event mode |
|---|---|---|
| `mundane_id` | respondent | respondent |
| `class_id` | `GetPlayerLastClass()`, or 6 (Color) when 0 | same |
| `date` | `DATE(response.submitted_at)` (exact for `full`) | the occurrence's start date |
| `park_id` | response's snapshotted `park_id` | occurrence `at_park_id` (park grantor), else 0 |
| `kingdom_id` | that park's `kingdom_id` | the event's `kingdom_id` |
| `event_id`, `event_calendardetail_id` | 0, 0 | the config's |
| `credits` | 1.00 | 1.00 |
| `persona`, `flavor` | `''`, `''` | `''`, `''` |
| `note` | `'Survey #' . survey_id` (≤ 20 chars; keeps it off a same-day park sign-in's unique key) | same |
| `by_whom_id` | `ork_survey_credit.enabled_by` | same |
| `entry_method` | `'survey'` (new ENUM value, appended) | same |

A respondent with no snapshotted park (0/NULL) cannot be placed in home-park mode. They are skipped and counted as "no home park" in the panel. `SurveyCredit::grant()` wraps the attendance insert and the `ork_survey_credit_grant` insert in one transaction on `YapoMysql`, and rolls back on either failure. The ledger primary key makes a concurrent second grant fail cleanly.

**Player page.** `Playernew_index.tpl` "By" cell: `entry_method === 'survey'` renders *"Survey credit"* (muted, like `self_reg`).

#### 3.4 The event (event mode)

New internal method `EventPlanning::create_system_event(array $r): array` (snake_case for the same reason), modelled on `CreateEventWithCopy`'s raw inserts. It takes no token and does no geocoding, fills every NOT NULL column explicitly, and busts the scope caches the copy flow busts.
- **Survey start date** (`SurveyCredit::startDate($row)`): `DATE(opened_at)`, or `DATE(open_at)` when that is later. It is null while the survey has never opened.
- **`ork_event`**:
  - `name` = `'Survey Credit - ' . title`, cut to 100 characters (multibyte-safe) with a trailing `…` when cut.
  - For a kingdom grantor: `kingdom_id` = grantor, `park_id` 0. For a park grantor: `park_id` = grantor and `kingdom_id` = the park's kingdom (derived, never taken from the request).
  - `mundane_id` 0, `unit_id` 0, `status` `'published'`.
- **`ork_event_calendardetail`**:
  - One day: `event_start` = start date 00:00:00, `event_end` = start date 23:59:59. The window alone does not keep it off the attendance pages' "currently happening" prompt, since it covers its own start date (often today). `Event::GetActiveEventsAtScope()` therefore skips any occurrence an `ork_survey_credit` config points at, and `SurveyCredit` busts that cache once it links the ids.
  - `current` 1, `price` 0, `event_type` `'Other'`, `at_park_id` = the park grantor (NULL for a kingdom grantor).
  - `description`: *"Attendance credit for completing the survey "{title}". Credits are entered automatically for respondents who chose to link their answers to their ORK profile."*
  - `url` = the survey share link, `url_name` = "Take the survey".
  - Address fields `''`, `latitude/longitude` 0.
- **When it is created:** on enable if the survey has a start date, otherwise by `SurveyCredit::onOpened()`, called from `Survey::setStatus()` on the first transition to `open`. The ids are stored on the config.
- **Self-heal:** if the event is missing at reconcile time (deleted while it had no attendance), `reconcile()` recreates it. Once it holds credits, `HasAttendance` blocks deletion.

#### 3.5 Pipeline (D6)

- **Live grant:** after `SurveyResponse::submit()` commits, and only for a non-test `full` response with `credit_notice = 1`, it calls `SurveyCredit::grantFor($surveyId, $mundaneId)`. The call is best-effort: a failure is logged as `[survey-credit] grant failed {survey_id, uid, stage, db_error}` (values redacted, like the submit rollback log) and never changes the submit result. `submit` returns a new `credit` field: `granted`, `pending` (a config covers the player but the grant failed or needs an event that does not exist yet) or `none`.
- **Reconcile** `SurveyCredit::reconcile($surveyId): array{granted:int, skipped_no_park:int, pending:int}`:
  - Creates any missing events.
  - Posts every owed credit: a non-test `full` response with `credit_notice = 1` whose player has no grant row and is covered by a config. A `full` response with `credit_notice = 0` is never owed.
  - Is idempotent; the ledger guarantees at most one credit per player.
  - Runs on enable (the backfill), on first open, when the Credits panel opens with `pending > 0` (the panel posts `credit_reconcile`), and from the optional cron `bin/survey-credit-sweep.php`. The cron reconciles every survey with configs and pending credits and has a header documenting the cron line, like `compute-weekly-recap.php`.
- **Audience rule:** `SurveyResponse::attendedRecently()` and its set-based mirror in `audienceCount()` ignore rows with `entry_method = 'survey'`, so a survey credit never qualifies a player for another survey's "attended in the last N months" audience.
- **Data gate lock:** `update` refuses `DataGateEnabled=0` while any config exists: *"This survey gives attendance credits, which need respondents to be able to choose Any ORK Data."* `credit_enable` refuses when the gate is off.
- **Clone** does not copy configs.
- **Activity log:** `credit_enable` writes `action='credit'`, detail `{credit_id, grantor_type, grantor_id, mode, backfilled}` (base §3 activity log).

#### 3.6 Credits panel (UI)

One modal, **Attendance credit**, in shared survey CSS (`sv-` vocabulary, no native dialogs, focus trapped and restored). It opens from:
- the **Credits** row action on the list page, with the section's org as the grantor context (`Kingdom/K` or `Park/P`);
- a new **Attendance credit** card in the builder sidebar (after Privacy), with the survey's owner as the grantor context.

Contents, from `credit_status`:
1. **This survey's credits:** one line per config the viewer may see, i.e. configs covering the viewer's org, or every config for the owner and ORK admins. For example: *"The Kingdom of Crystal Groves: at players' home parks, since March 3, 2026 (41 credits)"* or *"Emerald Hills: at the event Survey Credit - Voice of the Kingdom 2026 (link) (12 credits)"*.
2. **Your org** (the grantor context), one of:
   - **Already on:** a status line. No controls; configs are permanent.
   - **Covered by an earlier config:** *"Your players are already covered by {org}'s credit."* Enabling stays possible, because it covers players the other config does not; the dry-run count says how many.
   - **Off:** the form:
     - Mode (radio): **At the player's home park, on the day they took the survey**, or **At a new event "Survey Credit - {title}" on {start date}** ("on the day this survey opens" when not yet open).
     - Live warning, filled from `credit_status.preview`: *"{N} players who already chose Any ORK Data will get a credit now. While this survey is open, everyone who completes it with Any ORK Data and is covered by this credit will get one automatically. This can't be turned off or changed, and credits already given stay on players' records."* When some are unplaceable: *"{M} can't be credited at a home park because they have none."* When some chose Any ORK Data without being told about credits (`preview.no_notice`): *"{K} players chose Any ORK Data before this survey said anything about credits, so they won't get one."*
     - Checkbox **"I understand this can't be undone"** enables the **Turn on credits** button.
3. **Gate off:** the form is replaced by *"Turn on the data gate (Privacy) so respondents can choose Any ORK Data; credits are only given to them."*

After a successful enable the panel re-renders with the result (*"Posted 41 credits."*). The list row gains a credit chip.

#### 3.7 Respondent side

- `definitionForRespondent()` adds `credit_available: bool`: a config would cover this player if they chose Any ORK Data. `available` adds the same flag per survey.
- **Data gate** (`survey-take.js`) always adds one fixed line under the three options. When `credit_available`:

  > This survey gives an attendance credit, which will appear on your public attendance record. It is only given when you choose **Any ORK Data**.

  Otherwise (a kingdom or park may still turn credits on later, and a backfill would then reach this respondent):

  > This survey may later give an attendance credit, which would appear on your public attendance record. It is only given when you choose **Any ORK Data**.

  `submit` posts `CreditNotice=1` once the gate has shown either line. The server stores `ork_survey_response.credit_notice = 1` only on a non-test `full` row (never on partial or anonymous rows, where which line a player saw would narrow who they are), and only such rows are credited, live or by a backfill (D1). A response from before this column, or from a client that showed no line, stays 0 and is counted in the panel's `preview.no_notice`.

  The builder's Privacy quote (`consentQuote`) shows both lines, each marked with when it is shown.
- **Welcome screen / first screen** and the **My Amtgard** Available Surveys row show a small chip, *Earns an attendance credit*, when `credit_available`.
- **Thank-you screen** adds one line from `submit.credit`: `granted` → *"Your attendance credit has been added."*; `pending` → *"Your attendance credit will be added shortly."*; `none` → nothing.

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

-- ork_survey_credit, ork_survey_credit_grant: DDL in §3.1.

-- Append-only ENUM change (instant in MariaDB); re-running is a no-op.
ALTER TABLE ork_attendance
  MODIFY COLUMN entry_method ENUM('manual','signin_link','self_reg','bulk_import','survey') NOT NULL DEFAULT 'manual';
```

After applying: `docker restart ork3-php8-app` (APCu schema cache), then refresh the sandbox. If `drift-check` wants the attendance ENUM through the `2026-05-31` override render, add a matching override excerpt (schema only) and classify it `render: override`. The plan checks this.

### 5. AJAX contract additions (`SurveyAjax`)

| Action | CSRF | POST | Returns |
|---|---|---|---|
| `results` (changed) | read allowlist | `+ Context` (`Kingdom/17` \| `Park/1049`, optional) | as base §6, filtered through the lens for shared viewers; `summary.lens = {label}` |
| `credit_status` | read allowlist | `SurveyId, Grantor` (`Kingdom/17` \| `Park/1049`) | `{survey_title, survey_status, event_name, start_date, gate_enabled, configs:[{credit_id, grantor_type, grantor_id, grantor_name, mode, event_id, event_calendardetail_id, event_label, enabled_at, granted}], mine:{grantor_type, grantor_id, name, can_enable, blocked_reason, config_id\|null, covered_by:{credit_id, name}\|null, preview:{home_park:{eligible_now, no_home_park, no_notice}, event:{eligible_now, no_home_park, no_notice}}\|null}\|null, pending}`. `mine` is null when the caller does not act for `Grantor`; `blocked_reason` is the panel's inline refusal ('' when `can_enable`); `covered_by` names the earlier config that already covers the grantor's players; `preview` is per mode (null once the grantor has its own config), because the no-home-park count only applies to home-park mode. **Writes nothing.** |
| `credit_enable` | required | `SurveyId, Grantor, Mode, Confirm=1` | `{credit_id, granted, skipped_no_park, pending}`; status 1 for gate off, an invalid grantor, a missing confirmation, an existing config or an unknown mode; status 3 without `canCreate` on the grantor |
| `credit_reconcile` | required | `SurveyId, Grantor` (optional) | `{granted, skipped_no_park, pending}` (counts only the configs the caller's panel shows); allowed when the caller `canManage`s the survey **or** acts for `Grantor` (`validGrantor` + `canCreate`), the same gate as `credit_status` (reconcile only posts credits already owed, so any legitimate panel viewer may trigger it); otherwise status 3 |

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
- **Templates/assets**: `Survey_index.tpl` (sections, row actions, Credits modal), `Survey_results.tpl` + `survey-results.js` (shared mode, lens strip), `Survey_build.tpl` + `survey-build.js` (Results sharing select, Attendance credit card, the credit line in the consent quote), `survey-take.js` (gate line, chips, thanks line), `survey.css` / `survey-build.css` / `survey-results.css` (chips, strip, modal; dark mode), `Playernew_index.tpl` (the "Survey credit" By label and the widget chip). The Credits modal markup and JS live in one shared include so the list page and builder use the same component.
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
- **Class progress.** A home-park credit on a day the player also signed in adds a credit row but no class progress, because only the first row per date counts (`class.Player.php:1724`).
- **Park attendance statistics.** Home-park and park-event credits count as attendance at that park and feed park attendance reports and any park-status thresholds built on them.
- **Public record.** A credit shows on the player's public attendance history, revealing that they took the survey and, in home-park mode, on which day. The data-gate line says so (on every gated survey, as a "may later" line when no credit is on yet), and only `full` respondents who were shown it are credited.
- **Earliest-attendance / "playing since".** For a player with no prior attendance, a survey credit becomes their first attendance and moves `get_earliest_attendance_date`. Accepted; not special-cased.
- **Stale snapshots.** Backfilled `park_id` on pre-migration `full` rows uses the player's current park, not the park at submission.
- **APCu schema cache.** Restart the app after the migration, or inserts into the new columns and tables silently fail.
- **`sql_mode=''`.** `create_system_event` and `add_system_credit` must name every NOT NULL column; an omitted one is silently `''` or 0.
- **Cron is optional.** Without it, a grant that failed at submit waits for the next panel open or enable; the thank-you screen says "will be added shortly".
