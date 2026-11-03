# Survey Module — Design Spec

**Date:** 2026-09-09
**Status:** Approved design, ready for implementation plan
**Scope:** New module. Survey builder, mobile-first survey runner, consent "data gate", Highcharts reporting with row-level data, My Amtgard widget, and a site-wide promotion banner.
**Branch:** `feature/survey-module` (off `master` at `0e847a6e`)
**Revised:** 2026-09-10 — distributed-review fixes (review items #1–#47): consent copy and tenure bands, minimum cell size with complementary suppression, start tracking and response rate, activity log, CSRF on survey mutations, attendance-based audiences, `question_duplicate` / `event_options`, image tokens and caps, results summary print / individual-response panel / URL filters, DataTables list page. Migration `db-migrations/2026-09-10-survey-review-fixes.sql`. Where this spec and the code disagree, the code wins and this file is wrong.

## Problem

The ORK has no way to ask its players anything. Kingdom and park leadership fall back to Google Forms, which cannot scope an audience to an org, cannot stop double submissions, cannot enrich answers with tenure or kingdom without asking the player to type them, and cannot offer a real privacy choice. The ORK already knows who a player is, where they play, and how long they have played, and it already has the officer authorization model needed to decide who may run a survey.

## Goal

A survey tool inside the ORK that:

1. Lets ORK admins and org officers with CREATE or ADMIN authority build surveys from a professional set of question types, with formatted text, images, welcome and thank-you screens, page breaks, and simple skip logic.
2. Presents surveys to eligible players in the My Amtgard tab (Available Surveys widget) and, optionally, as a dismissable site banner.
3. Ends every survey with a **data gate**: the respondent chooses *Any ORK Data*, *My Kingdom and How Long I've Been Playing*, or *Anonymous Only*, and storage is scrubbed to match.
4. Is mobile-friendly out of the box.
5. Reports each question with a chart suited to its type (Highcharts), supports filters and one cross-tab, exposes row-level data, and exports CSV. Reporting respects the data gate.

## Non-goals (v1)

- Public or logged-out respondents (audiences are ORK players; every respondent is a logged-in user).
- Email or Discord delivery of surveys.
- Delegated survey managers who lack CREATE/ADMIN authority (a `ork_survey_manager` table like `ork_qual_manager` could be added later).
- Editing a submitted response.
- Multi-condition or cross-page branching rules engines. v1 ships single-condition show-if.
- XLSX export (CSV only; the `SimpleXlsx` helper is not on this branch).
- Survey templates / a shared question library. "Clone survey" covers the common case.

## Current-State Constraints (verified)

- **Closest analog is QualTest**, not Voting. There is no ballot module on this branch; `class.VotingRules.php` is a static eligibility rules table. QualTest gives the module conventions: domain class with `global $DB` (`system/lib/ork3/class.QualTest.php:7-11`), `esc()`/`(int)` interpolation (`:2726`), transactions around multi-statement writes (`:452-586`), a thin typed snake_case model facade (`orkui/model/model.QualTest.php:5-8, 351-354`), a page controller plus a JSON AJAX controller with `jsonOut()` / `requireLogin()` guards that `exit` (`orkui/controller/controller.QualTestAjax.php:15-28`), and status codes `0` ok · `1` bad request · `3` not authorized · `5` not logged in.
- **Auth**: `Model_Authorization::has_authority(int $uid, string $type, $id, ?string $role): bool` (`orkui/model/model.Authorization.php:27`) → `Authorization::HasAuthority()` (`system/lib/ork3/class.Authorization.php:822`). A `role=create` or `role=admin` row satisfies a request for `AUTH_CREATE` (`:885-899`); the walk covers park → kingdom and principality → parent kingdom (`:903-950`). Site-wide ORK admin is `has_authority($uid, AUTH_ADMIN, 0, AUTH_ADMIN)` (`orkui/controller/controller.Admin.php:24`). From the domain layer the same check is `Ork3::$Lib->authorization->HasAuthority(...)` (`class.QualTest.php:27`).
- **Routing**: `index.php?Route=Controller/method/arg`; segments past the third collapse into one string that the controller must `explode('/')` (`controller.QualTest.php:56-59`). Every action parameter needs a default or the router redirects home (`orkui/index.php:133-143`).
- **Templates are plain PHP**. `Settings::$theme` is hardcoded `'default'`; tool pages live in `orkui/template/default/` and link the shared `.rp-*` shell (`orkui/template/default/style/reports.css`) themselves with an mtime cache-buster. The rule "never build in `default/`" forbids adding entry points to the legacy pages, not placing new tool templates there — QualTest, Tournament and every Report already live there. CRM profile pages are selected per action with `$this->template = '../revised-frontend/X.tpl'`.
- **Entry points on CRM surfaces**: kingdom Admin Tasks tab `#kn-tab-admin` (`orkui/template/revised-frontend/Kingdomnew_index.tpl:907-935`, groups are `.kn-report-group > h5 + ul > li > a`, gated by `$CanManageKingdom`), park Admin Tasks tab `#pk-tab-admin` (`Parknew_index.tpl:1288-1305`), and the Admin panel report list `ul.cp-report-list` (`revised-frontend/Admin_index.tpl:283-295`).
- **My Amtgard** is the `myamtgard` tab of the own-profile page (`controller.Player.php:281-283, 517`). Widgets are `.pna-card` blocks inside `.pna-sidebar` (`Playernew_index.tpl:1557`) and `.pna-feed` (`:1606`); several are empty divs filled by AJAX from the block at `:7451` (`// ---- My Amtgard sections (own profile only) ----`). Widget CSS is at `:246-328`, dark mode `:388+`, breakpoints 700px and 420px.
- **Site banner slot**: `orkui/template/default/default.theme:180-185` — `#ork-env-banner` immediately inside `#theme_container`. Per-user dismissable notice precedent is What's New: decision in the base controller (`system/lib/system/class.Controller.php:103-121`), persistence `ork_whats_new_seen`, dismiss endpoint `WnAjax/dismiss`, model methods `dismiss_whats_new` / `get_whats_new_seen` (`orkui/model/model.Player.php:468-482`).
- **Session-token skip list** `$_skipTokenCheck` (`class.Controller.php:66-77`): the new AJAX controller stays **off** it (safer default, matches QualTestAjax).
- **Highcharts**: v3.0.7 is inlined into `orkui/template/default/script/orkui.js:17075+` and loaded on every page. `Reports_release_utilization.tpl:469` already loads modern Highcharts from `code.highcharts.com` on top of it and carries the most complete dark-mode theming helper (`:471-540`). The results page follows that precedent with a pinned version.
- **Rich text** in the repo is Markdown: client `marked@12` + `dompurify@3` from jsDelivr (`Playernew_index.tpl:3932-3933, 4115`), server `Parsedown` vendored at `system/lib/Parsedown.php` used in safe mode by `QualTestAjax::help` (`controller.QualTestAjax.php:57-60`). Five templates carry their own copy of a `*_markdown()` PHP helper; there is no shared one.
- **Image upload precedent**: `class.Banner.php` — `is_uploaded_file`, `exif_imagetype` sniff, JPEG/PNG only, 1 MB cap, files at `{dir}{%06d}.{ext}`, `Common::resolve_image_ext()` on read (`system/lib/ork3/common.php:667`). Path constants are defined per environment in `config.dist.php`, `config.dev.php`, `config.test.php` (`HTTP_ASSETS` / `DIR_ASSETS` at `config.dev.php:23, 53`).
- **Tenure**: `Player::get_earliest_attendance_date($mundane_id)` (`class.Player.php:4635`, cached 300 s) with `ork_mundane.player_since_override` taking precedence when set (`Playernew_index.tpl:144-148` derives the same "playing since").
- **Design tokens**: `orkui/template/default/style/tokens.css` (`--ork-*`), dark mode selector `html[data-theme="dark"]`, no native `confirm()/alert()/prompt()`, tooltips via `data-tip`, FontAwesome 7 with FA5 names still resolving.
- **DB**: MariaDB, tables `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci`. Migrations are applied manually (`docker exec -i ork3-php8-db mariadb -uroot -proot ork < file` then `docker restart ork3-php8-app` because table metadata is cached in APCu for 24 h). Every new file in `db-migrations/` must be classified in `tools/ork-db/manifests/migration-classification.json5` or `drift-check --strict` blocks the unit-test runner. The PHPUnit sandbox is `ork_test` on 19307, refreshed by `bin/ork-db deploy-sandbox --force-refresh --yes`.
- **Layering**: all SQL in `system/lib/ork3/`; `orkui/model/` is the only membrane; controllers and templates never touch `$DB`, `Ork3::$Lib`, or `new DomainClass()`. `bin/check-layering.sh` is absent on this branch, so the rule is review discipline here.
- **Local test users**: `heraldsbridge` (mundane 46193, kingdom 17, park 1049) is an ORK admin; any password works while the auth bypass is present.

## Design

### 1. Ownership, audience, lifecycle

**Scope.** `ork_survey.scope_type ∈ {ork, kingdom, park}` with `scope_id` (0 for `ork`).

| Actor | May create | May manage (edit, open/close, results, export) |
|---|---|---|
| ORK admin (`AUTH_ADMIN,0,AUTH_ADMIN`) | any scope | every survey |
| Kingdom officer with `has_authority(uid, AUTH_KINGDOM, K, AUTH_CREATE)` | `kingdom` surveys for K (and, via the parent walk, for principalities of K) | surveys whose scope is K or a principality of K |
| Park officer with `has_authority(uid, AUTH_PARK, P, AUTH_CREATE)` | `park` surveys for P | surveys whose scope is P |

`Survey::canManage($uid, $surveyRow)` and `Survey::canCreate($uid, $scopeType, $scopeId)` are the two gates. Every AJAX mutation resolves the survey from the id it was given and checks `canManage` against **that survey's own scope**, never a scope id from the request (the `QualTest::export` lesson).

**Audience.** Eligible respondent = logged-in player where all of (`SurveyResponse::eligibility()`, checked in this order; the first failure is the `reason`):
- survey `status = 'open'` and (`open_at IS NULL OR open_at <= now`) and (`close_at IS NULL OR close_at > now`), "now" being PHP's clock (`time()` in `eligibility()`; `nowStamp()` formats the same clock for SQL), never SQL `NOW()` → else `not_open_yet` / `closed`;
- never `penalty_box = 1` → `banned`;
- no row in `ork_survey_participation` for (survey, player) → `completed` (checked before scope so a player who answered and then transferred still hears "already completed");
- `audience_active_only = 0` or `mundane.active = 1` → `inactive`. `mundane.active` means **not retired**, not "attends"; the builder labels the box **"Exclude retired accounts"** (review #12). Use the recent-attendance rule for "plays now";
- **who is reached** → `scope` or `event_attendance`:
  - `audience_event_calendardetail_id` set (event audience): the player has an `ork_attendance` credit at that event occurrence. This **replaces** the home-scope match, so visitors from other parks and kingdoms qualify;
  - otherwise the home-scope match: `park` → `mundane.park_id = scope_id`; `kingdom` → `mundane.kingdom_id = scope_id` **or** the player's kingdom has `parent_kingdom_id = scope_id`; `ork` → `audience_kingdom_ids IS NULL` or the player's kingdom (or its parent) is in the list;
- `audience_recent_months = 0`, or the player has an attendance credit **inside the survey's scope** (the park; the kingdom or any of its principalities; anywhere for `ork`) dated within the last N months → else `recent_attendance`. Range 0–120; 0 = off. With an event audience this still tests the survey's scope, but it rarely shuts visitors out: an event sign-in row carries the event's kingdom (and usually its park), so a visitor's sign-in at the event itself counts as recent attendance in scope whenever the event falls inside the window. For a kingdom survey that is essentially always; for a park survey it holds when the credit was recorded with the event's park, which is most but not all visitor credits;
- `tenure_months >= audience_min_tenure_months` → else `tenure`.

`SurveyResponse::audienceCount()` is the set-based mirror of these rules (without the schedule and already-completed checks) and must be kept in step with `eligibility()`; it is the response-rate denominator (§7 Results).

The event picker offers occurrences of **published** events the survey's scope owns (any event for `ork`; the kingdom and its principalities for `kingdom`; the park for `park`) that start between 12 months ago and 6 months ahead, newest first, at most 100 (`Survey::eventOptions()`); the saved occurrence is always included. `update` re-validates a saved id against the scope without the date window, so a park officer cannot aim a survey at another kingdom's event attendees.

**Start tracking (review #28).** The first time an eligible, non-preview player loads the runner (`definition`), `SurveyResponse::definitionForRespondent()` does `INSERT IGNORE INTO ork_survey_start (survey_id, mundane_id)`. The row has **no timestamp and no response id**, like participation, so a start cannot be timed against a submission. It is written whether or not `allow_resume` is on, and it is the completion-rate denominator.

Builders always pass the audience check in **preview** mode (`Survey/take/{id}/preview`), which renders and validates but never writes (no draft, no start row, no response unless "Submit as test").

**Lifecycle.** `draft → open → closed → archived`. `open` requires ≥ 1 answerable question and every question valid. Scheduled `open_at`/`close_at` are evaluated at read time (a `draft` with a past `open_at` does **not** auto-open; a manual "Open" is always required; `close_at` in the past makes an `open` survey read as closed). **Structure lock:** once a survey has ever been opened (`opened_at IS NOT NULL`), questions and options may not be added, deleted, retyped or reordered and pages may not be added or removed; prompts, help text, labels, welcome/thanks copy, audience and banner settings remain editable. `Clone` copies definition and images to a new `draft`. `Delete` is allowed only for `draft` with no responses; otherwise use `archived`.

### 2. Data gate (consent) and privacy model

The last screen of every survey with `data_gate_enabled = 1` shows the consent choice **before** submit. Fixed copy (not editable per survey; the option names are the owner's and do not change). The runner (`survey-take.js`) and the builder's read-only quote in the Privacy section (`survey-build.js` `consentQuote`) carry exactly this text (review #3):

> **Help us understand these results**
> Your answers are recorded either way. Choose what the ORK may attach to them:
> - **Any ORK Data** — Link my answers to my ORK profile. The {scope} officers and ORK administrators who run this survey, now and in future reigns, will see my name beside my answers, including in exported spreadsheets.
> - **My Kingdom and How Long I've Been Playing** — Record only my kingdom and a years-played range, such as 3–5 years. No name, no profile link.
> - **Anonymous Only** — Record nothing about me.

`{scope}` is the stored kingdom or park name (`definition.survey.scope_label` in the runner; the matching `scopes` entry in the builder). Names often carry their own article (kingdom 17 is stored as "The Kingdom of Crystal Groves"), so when the name already starts with "The " the copy's own "The " is dropped and the name is used as is: "The Kingdom of Crystal Groves officers", never "The The Kingdom of…". Any other name gets the "The " prefix ("The Emerald Hills officers"). For an ORK-wide survey (`scope_type = 'ork'`) the Any ORK Data line reads instead: *"Link my answers to my ORK profile. The ORK administrators who run this survey, now and in future administrations, will see my name beside my answers, including in exported spreadsheets."* The old promise that analysts could "slice results by awards, attendance, and class history" is gone: the results page offers no such analysis.

**First-screen notice.** When `data_gate_enabled = 1` the runner shows this fixed line on the first screen, so nobody answers sensitive questions assuming their name is attached:

> At the end you'll choose whether your answers are linked to your profile, kept to your kingdom and years played, or fully anonymous.

It sits on the welcome screen; a survey with no welcome screen shows it above the first page, each time the first page is shown (including after pressing Back).

Storage rules, enforced in the domain layer (`SurveyResponse::scrubForConsent()` is a pure function with unit tests and the **only** writer of these columns):

| Column on `ork_survey_response` | `full` | `partial` | `anonymous` |
|---|---|---|---|
| `mundane_id` | set | NULL | NULL |
| `kingdom_id` | set | set | NULL |
| `tenure_months` | exact months | **band floor** (see below) | NULL |
| `started_at` | set | NULL | NULL |
| `submitted_at` | exact | truncated to `DATE 00:00:00` | truncated to `DATE 00:00:00` |
| `duration_seconds` | set | **NULL** | NULL |

**Years-played bands (`SurveyResponse::TENURE_BANDS`, review #2).** A `partial` response never stores exact months. It stores the floor of its band, and reporting shows the label:

| Stored `tenure_months` | Label shown |
|---|---|
| 0 | Under 1 year |
| 12 | 1–2 years |
| 36 | 3–5 years |
| 72 | 6–10 years |
| 132 | Over 10 years |

`tenureBandFloor($months)` maps any month count to its floor; `tenureBandLabel()` gives the label. `full` rows keep exact months and show whole years ("14 years"). Migration `2026-09-10-survey-review-fixes.sql` backfills partial rows stored before banding: tenure folds onto the band floor, and `duration_seconds` and `started_at` are nulled, so stored data matches the copy.

Design decisions behind the table:
- **Double-submission is prevented by `ork_survey_participation (survey_id, mundane_id)`**, which stores *that* a player finished, with **no timestamp and no response id**, so it cannot be joined back to a response row. `ork_survey_start` follows the same rule. Exact `submitted_at` is only stored when the player already consented to a profile link.
- **Drafts (`ork_survey_draft`) are keyed by player** so a survey can be resumed, and the row is deleted inside the submit transaction. A draft is never readable by managers. Drafts are identified, pre-consent data, so they do not linger: leaving `open` (close, archive, back to draft) deletes every draft of the survey, turning `allow_resume` off deletes them, and opening sweeps drafts untouched for 60 days (`DRAFT_RETENTION_DAYS`).
- `data_gate_enabled = 0` means every response is stored as `anonymous`. There is no "always link" option.
- `is_test = 1` responses (builders using "Submit as test") are stored with `full` consent regardless of choice (they are the builder's own) and excluded from reporting by default.
- Reporting shows persona (with a profile link) only for `full` rows, kingdom and years-played band for `partial` (subject to the small-group rules below), and nothing for `anonymous`. A kingdom filter therefore excludes anonymous rows, and the results page says how many were excluded.

**Small-group protection (`SurveyReport::MIN_CELL = 5`, review #4).** Consent tiers mean little if a filter can shrink a result to one person. Every report surface (summary, aggregates, rows table, individual-response panel, CSV, summary print) applies the same rules:

1. **Suppressed subsets.** A *narrowing* filter (kingdom, consent level or a date bound; `include_test` and the cross-tab question do not narrow) that leaves fewer than 5 responses suppresses every per-question aggregate: `agg:{suppressed:true}`, `n` and `reached` null. The summary then reports only its response count; `by_day`, `consent_breakdown` and `median_duration` are null. The UI says **"Too few responses to show"**.
2. **Cross-tab groups** (`crosstabGroup()`, `crosstabGroups()`). A group with 1–4 answers is returned as `{option_id, label, n:null, suppressed:true}` with no aggregate. Empty groups are safe and stay `n:0`. Because the question's overall aggregate sits beside the groups, a lone withheld group could be recovered by subtraction (overall minus the visible groups), so cross-tabs get **complementary suppression**: whenever any group is withheld and the withheld groups together hold fewer than 5 answers, the smallest visible non-empty group is withheld too (ties go to the earlier option), repeating until the withheld groups total at least 5 or nothing non-empty is left. Subtraction then only ever yields a blend of at least 5 people. One small group therefore always takes at least one other group with it.
3. **Partial-row masking.** On a `partial` row, the kingdom shows only when at least 5 partial rows in the filtered set share it, and the years-played band only when at least 5 share the same (kingdom, band). Otherwise the value is blanked and the row carries `masked:true` ("Withheld (small group)" in the CSV). `full` rows consented to everything and are never masked.
4. **Complementary suppression** (`complementaryCells()`). A withheld cell must never be the only one the reader cannot see, or it can be recovered by elimination ("4 of 5 bands are shown, so the hidden row is in the fifth"). While fewer than two cells are unseen, the smallest shown cell is withheld as well (ties go to the lower count, then the lower key). For bands the universe is the five `TENURE_BANDS`, and within a kingdom that has any withheld band the smallest shown band is also withheld while the masked rows in that kingdom total fewer than 5, so a masked group is never a handful of people (2 masked rows beside four shown bands would otherwise narrow those 2 to their named-band peers). For kingdoms the universe is the kingdoms the audience can come from (`kingdomUniverse()`): 1 for a park survey; the kingdom plus its active principalities for a kingdom survey; the listed kingdoms plus their active principalities for an ORK-wide survey limited to a kingdom list; every active kingdom for an unrestricted ORK-wide survey or any survey with an event audience, whose visitors may come from anywhere. A withheld kingdom takes its bands with it. The withheld set for a view is a **union** (`viewForcedCells()`, called from `partialCells()`): `forcedCells()` — the rule run once over the survey's whole partial set, so no filtered view shows a cell the unfiltered view hides — plus `complementaryCells()` run again over the filtered view's own cell counts, so a date or consent filter can't leave a kingdom with a handful of masked rows beside its shown bands. The union only ever masks more.
5. **Kingdom filter.** Under a kingdom filter, partial rows from kingdoms with fewer than 5 partial rows (or withheld by rule 4) are left out of the filtered set entirely (`reportWhere()`); otherwise the filter would restore the kingdom that masking blanked. The summary signals this as a standing rule (`partial_cell_rule:true`), never as a count, since a count would reveal the small cell. The kingdom filter's option list (`kingdomsPresent()`) counts only rows the filter would keep and omits kingdoms with nothing countable.

**Order never leaks submission time (review #1).** The rows table orders by submission day, then an install-keyed hash of the response id (`orderKey()`), and shows a display ordinal rather than the database id. Free-text lists and "Other" write-ins on the results cards use the same permutation (`displayOrder()`), so no list is a submission timeline that could date an anonymous comment between two named ones.

### 3. Schema

Migration `db-migrations/2026-09-09-survey-module.sql`, idempotent (`CREATE TABLE IF NOT EXISTS`), classified `{ "class": "S", "render": "full" }`.

```sql
CREATE TABLE IF NOT EXISTS ork_survey (
  survey_id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope_type           ENUM('ork','kingdom','park') NOT NULL,
  scope_id             INT NOT NULL DEFAULT 0,
  title                VARCHAR(200) NOT NULL,
  slug                 VARCHAR(64) NOT NULL,
  description          TEXT NULL,                 -- short blurb (plain text) for lists/widget/banner
  welcome_md           TEXT NULL,                 -- markdown; NULL = skip welcome screen
  welcome_image_id     INT UNSIGNED NULL,
  thanks_md            TEXT NULL,                 -- markdown; NULL = default thank-you
  thanks_image_id      INT UNSIGNED NULL,
  status               ENUM('draft','open','closed','archived') NOT NULL DEFAULT 'draft',
  open_at              DATETIME NULL,
  close_at             DATETIME NULL,
  audience_kingdom_ids TEXT NULL,                 -- JSON int array; ork scope only; NULL = all kingdoms
  audience_active_only TINYINT(1) NOT NULL DEFAULT 1,
  audience_min_tenure_months SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  data_gate_enabled    TINYINT(1) NOT NULL DEFAULT 1,
  show_banner          TINYINT(1) NOT NULL DEFAULT 0,
  show_progress        TINYINT(1) NOT NULL DEFAULT 1,
  allow_resume         TINYINT(1) NOT NULL DEFAULT 1,
  accent_color         VARCHAR(7) NULL,           -- '#rrggbb' or NULL = default
  response_count       INT UNSIGNED NOT NULL DEFAULT 0,  -- non-test responses, maintained in submit txn
  created_by           INT NOT NULL,
  created_at           DATETIME NOT NULL,
  updated_at           DATETIME NOT NULL,
  opened_at            DATETIME NULL,             -- first open; non-NULL => structure locked
  closed_at            DATETIME NULL,
  PRIMARY KEY (survey_id),
  UNIQUE KEY uq_slug (slug),
  KEY idx_scope (scope_type, scope_id, status),
  KEY idx_status_banner (status, show_banner)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS ork_survey_page (
  page_id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  survey_id            INT UNSIGNED NOT NULL,
  sort_order           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  title                VARCHAR(200) NULL,
  description_md       TEXT NULL,
  show_if_question_id  INT UNSIGNED NULL,
  show_if_option_id    INT UNSIGNED NULL,
  PRIMARY KEY (page_id),
  KEY idx_survey (survey_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS ork_survey_question (
  question_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  survey_id            INT UNSIGNED NOT NULL,
  page_id              INT UNSIGNED NOT NULL,
  sort_order           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  type                 ENUM('single','multi','dropdown','yesno','rating','nps','matrix','ranking',
                            'short_text','paragraph','number','date','section','image') NOT NULL,
  prompt               TEXT NOT NULL,              -- question text, or heading for 'section'
  help_md              TEXT NULL,                  -- markdown under the prompt, or body for 'section'
  image_id             INT UNSIGNED NULL,          -- optional illustration; required for type 'image'
  required             TINYINT(1) NOT NULL DEFAULT 0,
  settings             TEXT NULL,                  -- JSON object, keys per type (see §4)
  show_if_question_id  INT UNSIGNED NULL,
  show_if_option_id    INT UNSIGNED NULL,
  created_at           DATETIME NOT NULL,
  updated_at           DATETIME NOT NULL,
  PRIMARY KEY (question_id),
  KEY idx_page (page_id, sort_order),
  KEY idx_survey (survey_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS ork_survey_option (
  option_id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id          INT UNSIGNED NOT NULL,
  role                 ENUM('choice','row','column') NOT NULL DEFAULT 'choice',
  sort_order           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  label                VARCHAR(255) NOT NULL,
  value_num            DECIMAL(10,2) NULL,         -- matrix column weight (Likert mean); NULL otherwise
  is_other             TINYINT(1) NOT NULL DEFAULT 0,  -- "Other (please specify)" write-in
  PRIMARY KEY (option_id),
  KEY idx_question (question_id, role, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS ork_survey_response (
  response_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  survey_id            INT UNSIGNED NOT NULL,
  consent              ENUM('full','partial','anonymous') NOT NULL,
  mundane_id           INT NULL,
  kingdom_id           INT NULL,
  tenure_months        SMALLINT UNSIGNED NULL,
  is_test              TINYINT(1) NOT NULL DEFAULT 0,
  started_at           DATETIME NULL,
  submitted_at         DATETIME NOT NULL,
  duration_seconds     INT UNSIGNED NULL,
  PRIMARY KEY (response_id),
  KEY idx_survey (survey_id, is_test, submitted_at),
  KEY idx_survey_kingdom (survey_id, kingdom_id),
  KEY idx_mundane (mundane_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS ork_survey_answer (
  answer_id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  response_id          INT UNSIGNED NOT NULL,
  question_id          INT UNSIGNED NOT NULL,
  option_id            INT UNSIGNED NULL,          -- chosen option / matrix column / ranked option
  row_option_id        INT UNSIGNED NULL,          -- matrix row
  value_text           TEXT NULL,                  -- text answers, "other" write-in, ISO date
  value_num            DECIMAL(12,3) NULL,         -- rating, nps, number, rank position
  PRIMARY KEY (answer_id),
  KEY idx_response (response_id),
  KEY idx_question_option (question_id, option_id),
  KEY idx_question_row (question_id, row_option_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Records THAT a player completed a survey. Deliberately no timestamp and no response_id.
CREATE TABLE IF NOT EXISTS ork_survey_participation (
  survey_id            INT UNSIGNED NOT NULL,
  mundane_id           INT NOT NULL,
  PRIMARY KEY (survey_id, mundane_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS ork_survey_draft (
  survey_id            INT UNSIGNED NOT NULL,
  mundane_id           INT NOT NULL,
  answers_json         LONGTEXT NOT NULL,
  page_index           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  started_at           DATETIME NOT NULL,
  updated_at           DATETIME NOT NULL,
  PRIMARY KEY (survey_id, mundane_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS ork_survey_dismissal (
  survey_id            INT UNSIGNED NOT NULL,
  mundane_id           INT NOT NULL,
  dismissed_at         DATETIME NOT NULL,
  PRIMARY KEY (survey_id, mundane_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS ork_survey_image (
  image_id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  survey_id            INT UNSIGNED NOT NULL,
  ext                  VARCHAR(4) NOT NULL,        -- 'jpg' | 'png'
  width                SMALLINT UNSIGNED NOT NULL,
  height               SMALLINT UNSIGNED NOT NULL,
  created_by           INT NOT NULL,
  created_at           DATETIME NOT NULL,
  PRIMARY KEY (image_id),
  KEY idx_survey (survey_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
```

**Review-fix migration** `db-migrations/2026-09-10-survey-review-fixes.sql` (idempotent: `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`, and a backfill that maps a band floor to itself; classified in `migration-classification.json5`):

```sql
-- Who edited, opened/closed, cloned, deleted, viewed rows of, or exported a survey (review #6).
CREATE TABLE IF NOT EXISTS ork_survey_activity (
  activity_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  survey_id            INT UNSIGNED NOT NULL,
  mundane_id           INT NOT NULL,
  action               VARCHAR(32) NOT NULL,     -- create|update|structure|status|clone|delete|rows_view|export
  detail               TEXT NULL,                -- JSON object; NULL = no detail
  created_at           DATETIME NOT NULL,
  PRIMARY KEY (activity_id),
  KEY idx_survey_created (survey_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Who opened a survey's runner (completion-rate denominator). Deliberately no timestamp.
CREATE TABLE IF NOT EXISTS ork_survey_start (
  survey_id            INT UNSIGNED NOT NULL,
  mundane_id           INT NOT NULL,
  PRIMARY KEY (survey_id, mundane_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

ALTER TABLE ork_survey
  ADD COLUMN IF NOT EXISTS updated_by INT NULL AFTER created_by,                   -- last editor
  ADD COLUMN IF NOT EXISTS audience_recent_months SMALLINT UNSIGNED NOT NULL DEFAULT 0
      AFTER audience_min_tenure_months,                                            -- 0 = off
  ADD COLUMN IF NOT EXISTS audience_event_calendardetail_id INT NULL
      AFTER audience_recent_months;                                                -- NULL = off

ALTER TABLE ork_survey_image
  ADD COLUMN IF NOT EXISTS token CHAR(16) NOT NULL DEFAULT '' AFTER ext;               -- '' = legacy name
```

plus the partial-consent backfill described in §2. After applying: `docker restart ork3-php8-app` (APCu schema cache), refresh the sandbox, then run `Survey::upgradeLegacyImageNames()` once (the command is in the migration header) to rename pre-token image files and rewrite any Markdown that names them; re-running it is a no-op.

**Activity log (review #6).** `Survey::logActivity()` appends one row per action; the model sets the session user as actor on every `Survey` instance (`setActor()`), so every write also stamps `ork_survey.updated_by`. What is recorded:

| `action` | Written by | `detail` |
|---|---|---|
| `create` | `create()` | scope type/id, title |
| `update` | `update()`; copy edits on questions and pages (prompt, help, image, title, description); any question/page/option edit on a locked survey; image add/delete | `{fields:[…]}` for `update()`, otherwise `{op, …ids, fields?}` |
| `structure` | on an unlocked survey: page/question add, duplicate, delete, reorder, move; a question's retype, `Required`, `Settings` or show-if; a page's show-if; `option_set` | `{op, …ids, fields?}` |
| `status` | `setStatus()` | `{from, to}` |
| `clone` | `cloneSurvey()` (on the new survey) | `{from_survey_id}` |
| `delete` | `delete()` | title |
| `rows_view` | `SurveyReport::rows()` (given an actor; the model passes the session user) | the normalized filters |
| `export` | `SurveyReport::csvStream()` (given an actor; the model passes the session user) | the normalized filters |

`rows_view` and `export` are skipped when the consent filter is `anonymous` (no identity or demographics in that view). `results` (aggregates only) is not logged. `update`, `structure` and `rows_view` coalesce: an entry identical (same person, action and detail) to one written in the last 15 minutes is dropped, so autosave and a scrolling table do not write one row per keystroke or page. Logging is best-effort and never fails the caller. The log is **not** deleted with its survey: it is the record that the survey existed and who removed it. There is no UI for it yet; read it with SQL.

Images are stored at `DIR_SURVEY_IMAGE . sprintf('%06d-%s.%s', $image_id, $token, $ext)` and served from `HTTP_SURVEY_IMAGE` (review #44). `token` is 16 random hex characters (`random_bytes(8)`) set on upload and on clone, so illustrations of draft or restricted surveys cannot be enumerated by counting ids. A row with `token = ''` keeps the legacy `%06d.ext` name until `upgradeLegacyImageNames()` renames it. The files are still served statically: the token makes a URL unguessable, but anyone holding the URL can load it. New constants in `config.dist.php`, `config.dev.php`, `config.test.php`: `HTTP_SURVEY_IMAGE = HTTP_ASSETS . 'survey/'`, `DIR_SURVEY_IMAGE = DIR_ASSETS . 'survey/'`. Directory `assets/survey/` with a `.gitkeep`. Upload rules copy `Banner`: `is_uploaded_file`, `exif_imagetype` sniff, JPEG/PNG only, 2 MB per file, at most 40 megapixels to decode, GD re-encode with longest edge clamped to 1600 px.

**Per-survey image budget (review #46):** at most 40 images (`MAX_IMAGES_PER_SURVEY`) and 40 MB on disk (`MAX_IMAGE_BYTES_PER_SURVEY`). When an upload would exceed either, `imageAdd()` first sweeps the survey's unreferenced images: not a question image, not the welcome/thanks image, not named in any survey's Markdown (a clone shows its source's files), and older than 60 minutes (the builder uploads first and attaches second). If the upload still does not fit, it is refused with a message saying which limit was hit.

`slug` is generated on create: 8 chars from `[a-z0-9]` via `random_int`, retried on collision. It powers the share link `Survey/s/{slug}`.

### 4. Question type catalog

`settings` is a JSON object. Unknown keys are ignored; missing keys take the defaults below. The domain validates settings on save and answers on submit with **one shared table** (`SurveyTypes::CATALOG`) so the builder, the runner and the aggregator cannot disagree.

| `type` | Options (`role`) | `settings` keys (defaults) | Answer rows written | Aggregate shape |
|---|---|---|---|---|
| `single` | `choice` ≥ 2 | `randomize:false` | 1 row: `option_id`; if `is_other`, also `value_text` | counts per option (`pct` null when n = 0), `other_texts[]` in display order (§2) |
| `multi` | `choice` ≥ 2 | `randomize:false, min_select:0, max_select:0` (0 = no cap) | 1 row per selected option | counts per option (% of respondents), `mean_selected` |
| `dropdown` | `choice` ≥ 2 | — | as `single` | as `single` |
| `yesno` | `choice` exactly 2, auto-seeded "Yes"/"No", labels editable, not deletable | — | as `single` | as `single` |
| `rating` | none | `min:1, max:5, min_label:"", max_label:"", icon:"star"` (`star`\|`number`) | 1 row: `value_num` | distribution per value, `mean`, `median`, `n`; values outside the current min..max are skipped everywhere, so n and the bars agree |
| `nps` | none | fixed 0–10, `min_label:"Not likely", max_label:"Very likely"` | 1 row: `value_num` | distribution 0–10, `detractors` (0–6), `passives` (7–8), `promoters` (9–10), `score` = %prom − %det |
| `matrix` | `row` ≥ 1, `column` ≥ 2 (`value_num` optional weight) | `require_all_rows:false` | 1 row per matrix row: `row_option_id` + `option_id` | per row: counts per column, `weighted_mean` when every column has `value_num` |
| `ranking` | `choice` ≥ 2 | `randomize:true, rank_all:true` (a fixed start order biases every ranking toward the first-listed option) | 1 row per option: `option_id` + `value_num` = rank (1 = top); none until the respondent touches the list (an arrow, a drag, or the "Keep this order" button a required ranking shows), because the order it arrived in is not a vote | per option: `mean_rank`, `first_count`, `score` (Borda: n_options − rank + 1, summed) |
| `short_text` | none | `max_length:200, placeholder:""` | 1 row: `value_text` | `n`, `texts` (first 500, in display order, §2) |
| `paragraph` | none | `max_length:4000, placeholder:""` | 1 row: `value_text` | `n`, `texts` |
| `number` | none | `min:null, max:null, step:1, unit:""` | 1 row: `value_num` | `n, mean, median, min, max, mode, values, bins` — `mode:'values'` (one bar per value) when every answer is a whole number and at most 20 are distinct; otherwise `mode:'bins'`, ≤ 10 bins `[{from, to, label, count}]` with whole-number edges and labels like `3–5` for integer data (never `3.1–6.2`), equal-width bins for fractional data |
| `date` | none | `min:null, max:null` (ISO dates) | 1 row: `value_text` = `YYYY-MM-DD` | `n, min, max, granularity, periods[{period, label, count}]` — every period from the earliest to the latest answer, empty ones with count 0; `granularity:'month'` up to a 36-month span, `'year'` beyond |
| `section` | none | — | none (`required` forced 0) | — |
| `image` | none | `caption:""` | none | — |

Types that accept a `show_if` source: `single`, `dropdown`, `yesno`, `multi` (condition = option selected). `show_if_question_id` must precede the dependent question in survey order and must not itself be hidden by a condition (one level only). A page-level `show_if` hides the whole page. Server-side validation on submit evaluates the same rule with `SurveyTypes::isShown($question, $answers)`; a hidden question is never required and its answers are discarded.

Required semantics: `single/dropdown/yesno/rating/nps/number/date/short_text/paragraph` → an answer present; `multi` → ≥ `max(1, min_select)` selected; `matrix` → every row answered when `require_all_rows`, else ≥ 1 row; `ranking` → every option ranked when `rank_all`, else ≥ 1. The `matrix` and `ranking` rules (≥ 1, `require_all_rows`, `rank_all`) apply **only when the question is required**, client and server alike (review #18); an optional grid or ranking may be left partly or wholly blank, and unknown or repeated ids are rejected either way.

`randomize` shuffles options per respondent with a seed stable for that player (a resumed draft does not reshuffle). "Other (please specify)" options are never shuffled: they stay at the end in authored order (`SurveyResponse::shuffleOptions()`, review #21).

### 5. Layers and files

**Domain — `system/lib/ork3/`** (all SQL lives here; `global $DB`, `$this->db->Clear()` before every statement, `(int)` casts, `esc()` for strings, transactions around multi-statement writes):

- `class.SurveyTypes.php` — pure, no DB. `CATALOG`, `defaultSettings($type)`, `validateSettings($type, $settings)`, `seedOptions($type)`, `validateAnswer($question, $options, $value)`, `isShown($question, $answersByQuestion)`, `normalizeAnswer(...)` → answer rows. Unit-tested.
- `class.Survey.php` — definition and lifecycle. `canCreate`, `canManage`, `isOrkAdmin`, `manageableScopes($uid)` (list of `{scope_type, scope_id, name}` the user may create for), `create`, `get($id)` (survey + pages + questions + options, builder view), `getBySlug`, `update`, `setStatus`, `clone`, `delete`, `listManageable($uid, $scopeType=null, $scopeId=null)`, `pageAdd/Update/Delete/Reorder`, `questionAdd/Update/Duplicate/Delete/Reorder/Move`, `optionSet`, `imageAdd/Delete/Url`, `upgradeLegacyImageNames()`, `renderMarkdown($md)` (Parsedown safe mode, shared by every surface), `isStructureLocked`, `eventOptions($surveyRow)`, `setActor($uid)` + `logActivity($surveyId, $action, $detail)` (§3). Every multi-statement write runs in a transaction and rolls back on the first failed statement (`exec()` returns the result; review #37).
- `class.SurveyResponse.php` — intake. `definitionForRespondent($surveyId, $uid, $preview)` (strips builder-only fields, renders markdown to HTML, applies `randomize`, attaches the player's draft), `eligibility($survey, $uid)` → `{eligible, reason}` (§1), `audienceCount($survey)` (response-rate denominator), `tenureMonths($uid)`, `TENURE_BANDS` / `tenureBandFloor()` / `tenureBandLabel()` (§2), `draftSave/draftLoad/draftDelete`, `validateSubmission($survey, $answers)` → `{ok, errors{question_id: msg}}`, `scrubForConsent(array $row, string $consent)` (pure), `submit($surveyId, $uid, $answers, $consent, $durationSeconds, $isTest)` (single transaction: validate → insert response (scrubbed) → insert answers → insert participation → delete draft → `response_count++`; rolls back on any failure and logs a structured `[survey] submit rolled back {survey_id, uid, stage, db_error}` line with quoted values redacted, review #39), `availableFor($uid)` (widget list), `bannerFor($uid)` (one open `show_banner` survey the user is eligible for and has not dismissed, or null), `dismissBanner`.
- `class.SurveyReport.php` — aggregation. Every surface reads the same response set (`reportWhere()`, which applies the §2 small-group rules).
  - `summary($surveyId, $filters)` → `{responses, starts, completion, audience, response_rate, median_duration, consent_breakdown{full,partial,anonymous}, excluded_anonymous, by_day[{day,count}], suppressed, min_cell (5), narrowing, partial_cell_rule}`. `completion` = real (non-test) finished ÷ `ork_survey_start` rows, and is **null** under a narrowing filter (starts carry no kingdom, consent or date), with no starts, or when starts < finished (responses that predate start tracking). `audience` = `audienceCount()` (the *current* audience under today's rules); `response_rate` = finished ÷ audience, **null** under a narrowing filter or with an audience of 0. `median_duration` uses `full` rows only. When `suppressed`, `by_day`, `consent_breakdown` and `median_duration` are null.
  - `aggregate($surveyId, $filters)` → `{questions:[{question_id, type, prompt, n, reached, agg, crosstab?}]}` per the §4 shapes. `reached` = filtered responses that were shown the question (its page's and its own show-if held), never below `n`, so the card reads "n = 42 of 60". When suppressed, every entry is `{n:null, reached:null, agg:{suppressed:true}}` and no answer rows are loaded. `crosstab` = `{question_id, prompt, groups:[{option_id, label, n, agg} | {option_id, label, n:null, suppressed:true}]}` with **every** group in option order (no slicing); empty groups are `n:0` with null means/scores, never a plotted 0 (review #29). Only `single`, `dropdown`, `yesno` may be the cross-tab source (`CROSSTAB_SOURCES`; one group per respondent, review #34); any other id is ignored. Targets: `single, dropdown, yesno, multi, rating, nps`.
  - `rows($surveyId, $filters, $offset, $limit)` → `{total, columns[{question_id, prompt, type}], rows[], partial_cell_rule}` where each row is `{response_id (display ordinal, not the DB id), consent, is_test, persona|null, mundane_id|null, kingdom|null, kingdom_id|null, tenure_years|null (full only), tenure_label|null ('14 years' full, '3–5 years' partial), masked, submitted_at, time_withheld, duration_seconds|null (full only), answers{question_id: display string}}`. `submitted_at` is the full timestamp for `full` rows and `'Y-m-d'` with `time_withheld:true` for partial and anonymous rows.
  - `csvStream($surveyId, $filters, $emit)` emits a UTF-8 BOM + header, then one chunk per 500-row batch, so an export never holds the whole file in memory (review #42); `csv()` is the string wrapper. Columns: Response, Consent, Persona, Mundane ID, Kingdom, Years played, Withheld (small group), Submitted, Duration (s), then one per question prompt. Same masking as `rows()`. Cells starting with `= + - @` TAB or CR get a leading apostrophe (formula injection).
  - `kingdomsPresent($surveyId)` → `[{kingdom_id, name, count}]` for the kingdom filter (review #33; §2 rule 5).
  - `summary()` and `aggregate()` are cached in GhettoCache for 120 s, keyed by survey, normalized filters and a fingerprint that moves on every new response, start and survey edit, so a submission shows on the next Apply (review #40).
  - Pure functions (`aggregateType($type, $answerRows, $options, $settings)`, `completionRate()`, `isSuppressed()`, `crosstabGroup()`, `crosstabGroups()`, `partialVisibility()`, `complementaryCells()`, `viewForcedCells()`, `displayOrder()`, `displayAnswer()`) are separated from SQL so they are unit-testable with in-memory rows. Filters (`normalizeFilters()`): `{kingdom_ids[], consent:'any'|'full'|'partial'|'anonymous', date_from, date_to, crosstab_question_id, include_test:false}`.

**Model — `orkui/model/model.Survey.php`**: `Model_Survey extends Model`, thin typed snake_case delegates to the three domain classes (`_survey()`, `_response()`, `_report()`), no logic.

**Controllers — `orkui/controller/`**:
- `controller.Survey.php` (pages). The constructor sets `$SurveyCsrf = $this->Survey->csrf_token()` (an instance method on `Model_Survey`) for **every** page action, and each template emits it as `SvConfig.csrf`. Actions: `index($scope = null)` — manageable surveys list (`Survey/index`, `Survey/index/Kingdom/17`, `Survey/index/Park/1049`); `build($id = null)`; `take($p = null)` (`Survey/take/{id}` or `Survey/take/{id}/preview`); `s($slug = null)` → resolves slug and renders the take page; `results($id = null)`; `export($id = null)` (`Survey/export/{id}?filters=<json>`, written `index.php?Route=Survey/export/{id}&filters=<json>` because the route itself rides in the query string: drops output buffers, releases the session lock, streams the CSV, `exit`). `results` passes `$Kingdoms = kingdoms_present()` (not every kingdom the viewer manages). Permission failures use `no_authorization()` for pages.
- `controller.SurveyAjax.php` (JSON, `$_POST`, `jsonOut`/`requireLogin`/`requireManage($surveyId)`, and `requireCsrf()` in the constructor; **the full contract is in §6**).

**Templates — `orkui/template/default/`**: `Survey_index.tpl`, `Survey_build.tpl`, `Survey_take.tpl`, `Survey_results.tpl`. Each links `reports.css` (`.rp-*` shell for index/build/results; the take page uses only the base survey stylesheet so it stays lean on phones).

**Static assets — `orkui/template/default/style/` and `script/`**, class prefix `sv-`:
- `survey.css` — base: `--sv-*` tokens derived from `--ork-*`, question renderers, form controls (≥ 44 px tap targets, 16 px inputs so iOS does not zoom), buttons, progress bar, consent card, dark mode, mobile breakpoints at 700 px and 420 px.
- `survey-build.css`, `survey-results.css` — surface-specific only. Nothing duplicated from `survey.css` or `reports.css`.
- `survey-render.js` — **shared renderer**: `SvRender.question(q, state, mode)` → HTML string for one question (`mode: 'take' | 'preview'`), `SvRender.read(qEl, q)` → normalized answer, `SvRender.write(qEl, q, value)`, `SvRender.md(html)` passthrough. Used by both the runner and the builder canvas so the builder shows exactly what respondents will see.
- `survey-build.js`, `survey-take.js`, `survey-results.js` — one IIFE each, configured via a `window.SvConfig` object emitted by the template.

**Third-party (CDN, pinned)**: SortableJS `https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js` (builder, and the runner's ranking drag), DOMPurify `https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.1.6/purify.min.js` with SRI (runner: welcome, thank-you, help and page-description HTML is sanitized again before `innerHTML`, review #45; the runner still works, minus drag, if either script fails to load), `marked@12` + `dompurify@3` from jsDelivr (builder live preview, same as the profile page), Highcharts `https://code.highcharts.com/11.4.8/highcharts.js` + `modules/accessibility.js` (results only), DataTables 1.13.8 CSS/JS from `cdn.datatables.net` (results rows table and the survey list page), Flatpickr `https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js` + `flatpickr.min.css` (results date range and builder Schedule; the builder must load this same pinned cdnjs build).

**Docs**: `docs/survey-guide.md` (how surveys, the data gate and reporting work) served through `SurveyAjax/help` using the whitelist pattern from `QualTestAjax::help`.

### 6. AJAX contract (`SurveyAjax/<action>`, POST `FormData`, JSON)

Common envelope: `{status: 0}` plus payload on success; `{status: 1, error}` bad request or validation (`errors{}` when per-field); `{status: 3, error}` not authorized; `{status: 5, error}` not logged in. Every action calls `requireLogin()` first.

**CSRF (review #43).** Every action except the read allowlist `available, definition, get, scopes, results, rows, help, preview_md, dismiss_banner, event_options` must send header `X-CSRF-Token` equal to the session's survey token (64 hex chars, minted once per session by `$this->Survey->csrf_token()` on `Model_Survey`, compared with `hash_equals`). A cross-site form cannot set a header, so it cannot submit answers as a logged-in player with `Consent=full` or spend their one response. `types` is **not** on the allowlist, so the builder sends the token with it like any other call. A missing or wrong token returns `{status: 3, csrf: true, error: 'Your security token expired. Reload the page and try again.'}`; the client shows that inline with a Reload link, never a native dialog. A logged-out caller skips the token check and gets `status: 5` from `requireLogin()`. Every survey page emits `SvConfig.csrf` and every POST wrapper sends it. `requireManage(SurveyId)` loads the survey and checks `canManage` against its scope. Ids arrive as `SurveyId`, `PageId`, `QuestionId`, `OptionId`, `ImageId`; JSON-bearing fields are strings decoded server-side.

Builder:

| Action | POST | Returns |
|---|---|---|
| `types` | — | `{catalog}` — `SurveyTypes` catalog (type list, show-if sources, option roles, write-in cap); the builder's only source for them. Needs the CSRF header |
| `scopes` | — | `{scopes:[{scope_type, scope_id, name}]}` the user may create for |
| `create` | `ScopeType, ScopeId, Title` | `{survey_id}` (also creates page 1) |
| `get` | `SurveyId` | `{survey, pages[], questions[{…, options[]}], images[], locked:bool}` |
| `update` | `SurveyId` + any of `Title, Description, WelcomeMd, WelcomeImageId, ThanksMd, ThanksImageId, OpenAt, CloseAt, AudienceKingdomIds(JSON), AudienceActiveOnly, AudienceMinTenureMonths, AudienceRecentMonths (0–120, '' = 0), AudienceEventCalendardetailId (id, or '' / 0 to clear), DataGateEnabled, ShowBanner, ShowProgress, AllowResume, AccentColor` | `{survey}` (includes `audience_recent_months`, `audience_event_calendardetail_id`, `updated_by`). An event id the survey's scope does not own → `status:1` "Choose an event run by this survey's park/kingdom." |
| `event_options` | `SurveyId` | `{events:[{event_calendardetail_id, label}]}` — label "Coronation — March 14, 2026"; scope and window per §1 |
| `set_status` | `SurveyId, Status` | `{survey}` or `{status:1, errors{question_id: msg}}` when opening an invalid survey |
| `clone` | `SurveyId` | `{survey_id}` |
| `delete` | `SurveyId` | `{}` (draft only) |
| `page_add` / `page_update` / `page_delete` | `SurveyId` / `PageId, Title, DescriptionMd, ShowIfQuestionId, ShowIfOptionId` / `PageId` | `{page}` / `{page}` / `{}` (questions on a deleted page move to the previous page) |
| `page_reorder` | `SurveyId, PageIds(JSON)` | `{}` |
| `question_add` | `SurveyId, PageId, Type, AfterQuestionId?` | `{question}` with default settings and seeded options |
| `question_update` | `QuestionId` + any of `Type` (retype in place; refused when locked), `Prompt, HelpMd, ImageId, Required, Settings(JSON), ShowIfQuestionId, ShowIfOptionId` | `{question}` or `{status:1, errors}` |
| `question_duplicate` | `QuestionId` | `{question, order:[question_id,…]}` — copies prompt, help, image, required, settings, show-if and every option to just after the source in one transaction; `order` is the page's new question order. Unlocked surveys only (review #15) |
| `question_delete` | `QuestionId` | `{}` |
| `question_reorder` | `PageId, QuestionIds(JSON)` | `{}` |
| `question_move` | `QuestionId, PageId, Index` | `{}` |
| `option_set` | `QuestionId, Role, Options(JSON [{option_id?, label, value_num?, is_other?}])` | `{options[]}` — replace-all in order, ids preserved when supplied |
| `image_upload` | `SurveyId`, file field `Image` | `{image_id, url, width, height}`; refused past the per-survey budget (§3) |
| `image_delete` | `ImageId` | `{}` |
| `preview_md` | `Md` | `{html}` (server render, for parity checks) |
| `help` | `Doc` (`'surveys'`) | `{html}` |

Respondent:

| Action | POST | Returns |
|---|---|---|
| `definition` | `SurveyId, Preview(0/1)` | `{survey{survey_id, slug, title, description, welcome_html, thanks_html, show_progress, allow_resume, data_gate_enabled, accent_color, welcome_image_url, thanks_image_url, close_at, scope_type, scope_label, audience_recent_months, audience_event_calendardetail_id}, pages[{page_id, title, description_html, show_if_question_id, show_if_option_id, questions[…]}], draft{answers, page_index}|null, eligible:bool, reason}` — never includes builder-only fields. **A survey outside the caller's scope (or not open, for non-managers) returns `{status:1, error:'Survey not found.'}` rather than `eligible:false`, so its title, copy and slug stay unpublished; `eligible:false` + `reason` is only returned for in-scope reasons (`completed`, `inactive`, `tenure`, `recent_attendance`, `event_attendance`, `banned`, `closed`, `not_open_yet`); for an event-audience survey "in scope" means home scope **or** attended the event.** An eligible non-preview call also inserts the §1 start row. |
| `draft_save` | `SurveyId, Answers(JSON), PageIndex` | `{}` (no-op with `status:0` when `allow_resume = 0`) |
| `submit` | `SurveyId, Answers(JSON), Consent, DurationSeconds, IsTest(0/1)` | `{thanks_html}` or `{status:1, errors{question_id: msg}}` |
| `available` | — | `{surveys:[{survey_id, slug, title, description, scope_label, close_at, in_progress:bool}]}` (`in_progress` only when `allow_resume` is on and a draft exists) |
| `dismiss_banner` | `SurveyId` | `{}` |

Results (all `requireManage`):

| Action | POST | Returns |
|---|---|---|
| `results` | `SurveyId, Filters(JSON)` | `{summary, questions:[{question_id, type, prompt, n, reached, agg, crosstab?}]}` — shapes in §5 |
| `rows` | `SurveyId, Filters(JSON), Offset, Limit(≤500)` | `{total, columns:[{question_id, prompt, type}], rows[]}` — row shape in §5; logs `rows_view` unless consent = anonymous |

`Answers` JSON shape (runner → server and draft): `{ "<question_id>": value }` where value is `option_id` (single/dropdown/yesno), `[option_id,…]` (multi), `{option_id, other:"text"}` when an `is_other` option is chosen, number (rating/nps/number), `"YYYY-MM-DD"` (date), `"text"` (text types), `{ "<row_option_id>": column_option_id }` (matrix), `[option_id,…]` in rank order (ranking).

### 7. Surfaces

**Survey list (`Survey/index`)** — `.rp-*` shell. Header "Surveys" with scope chip, "+ New Survey" opens a modal (title + scope select fed by `scopes`). Table: title, scope, status pill, responses, opened/closes, actions (`Build`, `Results`, `Preview`, `Clone`, `Copy link` copies the `Survey/s/{slug}` share link (a fallback modal shows the link when the clipboard is refused), `Archive`). The table is a **DataTable** (house rule: tabular data on screen uses DataTables), giving search, column sort and paging (25 per page by default; 10/25/50/100/All). Status and the close date sort on the cells' `data-order` keys, so the default order is live surveys first, then soonest close; dates display human-readable. Status filter pills are real `<button>`s with `aria-pressed` that search the status column. Reached from the kingdom and park Admin Tasks tabs (new "Surveys" `.kn-report-group`, inside the existing manage gate) and from the Admin panel report list.

**Builder (`Survey/build/{id}`)** — the canvas *is* the editor; there is no side inspector for content. The page uses the **Reports layout** (`reports.css` shell, same as every tool page): `.rp-root > .rp-header` (inline-editable title, status pill, actions: Preview, Open/Close, Results, Copy link, Help) then `.rp-body` = **`.rp-sidebar` on the left holding the survey settings** + `.rp-main` holding the canvas column (max 860 px) of page cards. Clicking a question card selects it (accent left border) and switches it from the `SvRender` preview look to **edit mode, in place**:

- **Prompt** is a borderless auto-growing textarea with placeholder "Question"; **help text** appears as an "Add help text" link that becomes an inline Markdown textarea with a compact toolbar (bold, italic, list, link, image) and a collapsible preview.
- **Options** (single, multi, dropdown, yesno, ranking) render as rows: the real control glyph (radio/checkbox/number) + drag handle + a borderless text input for the label + a × remove button. Below the list: **"+ Add option"** and **"+ Add 'Other'"** links. Enter in a label input commits and adds the next option; Backspace on an empty label removes it. Yes/No shows exactly two labels and no add/remove.
- **Matrix** edits rows and columns inline as a grid: row labels down the left, column labels across the top, each a borderless input, with "+ Row" and "+ Column" links and × on each header; column weights (`value_num`) in a small numeric field under each column header.
- **Rating** shows the scale as it will look, with inline controls at the ends: a count picker (3–10), the min/max label inputs, and a star/number toggle. **NPS** shows its 0–10 row with the two end-label inputs. **Text/number/date** types show the real input disabled with placeholder and limit (max length, min/max/step/unit, date bounds) editable in a compact settings line under it.
- **Sections** edit heading and body inline; **image** blocks show the image with a Replace/Remove control and an inline caption input; **pages** have inline title and description and a "+ Add page" divider between pages.
- **Card footer toolbar** (selected card only): Type `<select>` (every element type), Required toggle, Duplicate (one `question_duplicate` call, spliced into the canvas locally), Delete, and a ⋯ menu for show-if (pickers listing only earlier eligible questions and their options), randomize, min/max select, require-all-rows, rank-all, and Add image.
- **Add** controls: no palette and no floating toolbar. A **"+ Add Element"** button sits below the last question of every page (and a smaller one appears between cards on hover / when a card is selected). It creates a starter **single-choice** question with two placeholder options, selected and in edit mode with the prompt focused. The **Type** `<select>` in the card footer lists every element type — all twelve question types plus Section and Image — so a starter card is retyped in place; changing type keeps the prompt and, where the new type has options, the existing options. Pages are added with the "+ Add page" divider.
- **Autosave** on blur, Enter, or a 400 ms debounce after typing; a `.svb-savestate` pill shows Saved / Saving… / Not saved ("Not saved" while any field is held blank or refused). Each edit maps to one AJAX action (`question_update`, `option_set`, `page_update`, `question_add`, `question_reorder`, …). Reordering uses SortableJS on questions (drag handle) and on option rows. When `locked`, add/remove/reorder/type controls are disabled with a `data-tip` explaining why; labels and prompts stay editable.
- **Survey settings live in the left sidebar**, not in a drawer and not on the canvas: a stack of `.rp-filter-card`s whose `.rp-filter-card-header` is a button that collapses/expands its `.rp-filter-card-body` (chevron, `aria-expanded`, state remembered per survey in `localStorage`). Sections, in order: **Basics** (description); **Screens** (welcome and thank-you Markdown with the compact toolbar + image pickers); **Audience** (scope chip; kingdom multi-select for ORK-wide surveys; **Exclude retired accounts** (`AudienceActiveOnly`); **Attended in the last N months** (`AudienceRecentMonths`, 0 = off); **Attended an event** picker fed by `event_options` (`AudienceEventCalendardetailId`, with a note that it replaces the home park/kingdom match so visitors are included); minimum tenure months); **Schedule** (open at, close at, both via Flatpickr `altInput` human-readable); **Privacy** (data gate on/off with the fixed §2 consent copy quoted for reference, using this survey's scope name); **Promotion** (site banner on/off, share link with copy button); **Experience** (progress bar, allow resume, accent colour). Each field autosaves through `update` on change/blur with the same save-state pill. Below the sections, the standard "About This Tool" card links the help guide. Under 900 px the sidebar stacks above the canvas as `reports.css` dictates, so every section starts **collapsed** there (and Basics only is open on desktop by default) to keep the canvas reachable.
- **Surface layers (added 2026-09-10).** Light mode alternates surface value so the four layers read apart without relying on 1 px borders: L0 ground `--sv-l0 #eef2f7` (the survey page region only, never the site body) → L1 page card `#ffffff` with `#d5dde8` border, card shadow, 8 px radius (page cards, sidebar and chart cards, table well, outline rail) → L2 question card `#f5f8fb` flat, `#e2e8f0` border, 6 px radius (the selected builder card lifts back to white with the accent edge and a hover shadow) → L3 controls `#ffffff` with `#cbd5e0` border, 4 px radius, hover `#edf2f7`, checked accent tint (option rows, inputs, scale buttons, matrix cells, ranking rows, the builder prompt field). Dark mode maps the same tokens onto its existing values (`#1a202c` / `#2d3748` / `#26303f` / `#2d3748`). Shadows only on L1 and the selected card; radius steps 8 → 6 → 4 so hierarchy is not carried by shadow alone.
- **Outline nav (added 2026-09-10).** A third `.rp-body` column on the right, `nav.svb-toc`, sticky, 220 px, shown only at ≥ 1450 px — the narrowest viewport at which the sidebar, the 860 px canvas and the rail all fit (hidden below that and on all touch widths). The builder body uses `overflow-x: clip` (scoped with `body:has(#svb-toc)`) because the global `overflow-x: hidden` on `body` would make it a scrollport and defeat `position: sticky`. Per page: an 11 px uppercase page label, then one row per element in canvas order showing only the type icon (from `TYPE_META`) and the title (prompt, section heading, or image caption; "Untitled question" fallback), single line with ellipsis. Rows are anchors to `#svb-item-{question_id}`; click scrolls smoothly to the card and selects it without stealing the caret. The list rebuilds on every structural change and updates the affected row's text live while a prompt is typed; the selected card's row carries `aria-current`, and a scroll-spy highlights the topmost visible card when nothing is selected.
- **Density (added 2026-09-10).** The builder is compact in the Google Forms sense; nothing on the canvas is larger than it needs to be, and **type is sized on the app's existing scale, not a larger one of its own**. Targets, measured on desktop at 1280 px in both themes:
  - Card: 16–20 px padding, 8 px radius, 1 px border; selected card shows a 4 px accent left edge and a centred `⋮⋮` drag handle 16 px tall in its top margin. Unselected cards are pure `SvRender` preview with no extra chrome.
  - Prompt: a filled, borderless field (`--ork-surface-light` background, 1 px bottom border) at 14 px semibold (the app's card-title size, `reports.css .rp-chart-card-title`); the small image button sits to its right; the **Type picker is at the top-right of the card**, 34 px tall, icon + label + caret, not in the footer.
  - Option rows: 32 px tall, 18 px control glyph, 13 px label input (`--ork-font-size-base`) with only a bottom border on focus, 18 px × on hover/focus; the last row reads "Add option **or** add 'Other'" at 13 px with the links in the accent colour.
  - Footer: 36 px tall, right-aligned: duplicate and delete as 18 px icon buttons, a 1 px divider, "Required" label at 12 px + a 32×18 px switch, ⋯ menu. No bordered selects or checkboxes in the footer.
  - Typography — **the app's scale from `tokens.css` and `reports.css`, nothing larger**: body, labels, option text and inputs `--ork-font-size-base` (13 px); helper/meta/settings lines `--ork-font-size-sm` (12 px); card-section labels 11 px uppercase like `.rp-filter-card-header` / `.pna-card-title`; question prompt and card titles 14 px semibold like `.rp-chart-card-title`; page/survey title 20 px like `.rp-header-title`; thank-you/welcome headings 18 px max. Inline settings controls 28 px tall. Replace the survey stylesheets' own 15/16/22/26 px sizes with these. The single exception is the runner on touch widths (≤ 700 px), where real `<input>`, `<textarea>` and `<select>` elements stay 16 px so iOS does not zoom — labels around them still use the 13 px base.
  - Settings drawer and list-page controls: 30–32 px inputs and buttons at 13 px, 12 px labels. Header actions on builder/results/list: 30 px buttons at 12–13 px like `.rp-btn-ghost`.
  - The runner keeps its 44 px tap targets and 16 px form controls on touch widths (accessibility); on desktop it uses the same 13/14/20 px scale and trimmed spacing as the rest of the app.

**Runner (`Survey/take/{id}`, `Survey/s/{slug}`)** — no `.rp-*` shell: a centred column (max 720 px), accent-tinted header with the survey title, thin progress bar, one page at a time, `Back`/`Next`, inline validation messages under the offending question, autosaved draft after each page when `allow_resume`, "Resume where you left off" on return. Welcome screen (if set) → pages → consent card (if enabled) → submit → thank-you screen. When the data gate is on, the first screen carries the §2 first-screen notice (on the welcome card, or above page 1, each time it is shown, when there is no welcome screen); the consent card uses the §2 copy with this survey's `scope_label`, or the ORK-wide variant. Builders in preview mode see a "Preview — nothing will be saved" strip and a "Submit as test" option. Ineligible visitors get the runner's reason text for `def.reason` (e.g. "This survey has closed. Thank you for your interest.", "You have already completed this survey. Thank you!", "This survey is open to players who attended the event it asks about."); a player outside the scope, where the survey is hidden from them, gets "Survey not found.". All interactive targets ≥ 44 px, text inputs 16 px, keyboard-navigable, `aria-live` for validation, no hover-only affordances.

**Results (`Survey/results/{id}`)** — `.rp-*` shell. Stats row: responses; **response rate** "X%" with the hint "R of A current audience" (the audience as today's rules count it); **completion** (finished ÷ started); median time (full-consent rows); consent split (three mini-numbers). Completion and response rate show **"—"** with a `data-tip` saying why whenever the summary returns null (a narrowing filter is applied; no starts recorded yet; responses that predate start tracking; an audience of 0). Filters sidebar: kingdom (multi; only kingdoms present in the responses, with counts, from `$Kingdoms`; hidden when only one kingdom is present), consent, date range (Flatpickr `altInput`, `altFormat 'F j, Y'`), cross-tab question (single/dropdown/yes-no only), include test responses; a note "N anonymous responses are excluded by the kingdom filter" when applicable, and a standing note that small groups of "kingdom and years played" responses are left out when `partial_cell_rule` is true. Below 900 px the filters collapse into a "Filters (N active)" disclosure after the stat row.

**Applied filters are one state (review #27).** Only Apply and Reset change `appliedFilters`; the cards, the rows table's ajax callback and the Export link all read it, and the latest Apply wins (stale responses are dropped; `aria-busy` on the cards and Apply disabled while loading). The applied filters are written to the page URL as `#filters=<json>` (the same JSON the export link carries; omitted when no filter is active) with `history.replaceState`, and read back on load, so a reload keeps them and the link can be sent to a co-officer, who still needs manage access to open it (review #36).

**Suppressed results.** A card whose `agg.suppressed` is true renders **"Too few responses to show (fewer than 5)."** instead of a chart and its badge reads "n hidden" (`data-tip` "Hidden: fewer than 5 responses match these filters."); a cross-tab group with `suppressed:true` is not drawn but labelled "{option} (fewer than 5)" and listed in the card note (see cross-tab below); a suppressed summary shows the response count only, with a notice to widen the filters. Main: one `.rp-chart-card` per question, its badge reading "n = 42 of 60" (`n` of `reached`, with a `data-tip` that percentages are of those who answered), with the chart chosen by type:

| Type | Chart |
|---|---|
| `single`, `dropdown`, `yesno`, `multi` | horizontal bar, sorted by survey option order, % labels; `other_texts` collapsible list |
| `rating` | column distribution + mean/median callout |
| `nps` | single stacked bar (detractors / passives / promoters) + score tile |
| `matrix` | 100 % stacked horizontal bar per row, plus weighted mean when available |
| `ranking` | horizontal bar of mean rank (lower = better), first-place count in tooltip |
| `number` | columns per value (`mode:'values'`) or per bin (`mode:'bins'`) + mean/median/min/max |
| `date` | columns per month or year (`granularity`), empty periods included |
| `short_text`, `paragraph` | no chart: count + a scrolling list of responses with "show more" |

Cross-tab renders the same cards split by the cross-tab option, drawing **every** group. Group labels (`groupLabel()`) carry their n with no spaces — "Druid (n=7)"; an empty group is labelled "Druid (no responses)" and a suppressed one "Druid (fewer than 5)". On choice cross-tabs each answered group is one series (legend label as above); groups past the palette's eighth colour fold into one grey "Other groups (n=N)" series, and empty and suppressed groups take no series at all. On rating/NPS cross-tabs every group is a category on the axis, empty and suppressed ones greyed and italic with a null value (never a plotted 0). Below the chart a card note (`crosstabNote()`) names the leftovers in up to three sentences: "Groups past the first 8 are combined into **Other groups**: …" (choice types only), "Too few responses to show (fewer than 5): …", and "No responses: …". Choice cross-tabs default to "% within group", with a "Count" toggle that stacks raw counts. One `svChartTheme()` helper handles dark mode (transparent background, axis/grid/legend/tooltip colours) and colours come from a single `SV_COLORS` palette. Free-text lists and "Other" write-ins appear in the §2 display order.

Below the charts, a "Responses" DataTable of row-level data (server-paged through `rows`, 100 per page). Question columns are headed Q1…Qn with the full prompt in a `data-tip`. The persona column is a profile link for `full` rows and "—" otherwise; kingdom and years played show per §2 (a masked partial value is left blank and flagged as withheld for a small group, `masked:true`). Submitted shows "September 10, 2026" plus "10:14 AM" for `full` rows; partial and anonymous rows show the date only with a `data-tip` "Time withheld" (`time_withheld`). **Individual-response panel (review #36):** clicking a row opens a side panel showing that response as prompt-and-answer pairs, with the same consent masking as the row, Prev/Next through the filtered page, and focus moved to the panel heading (restored to the row on close). **Export CSV** hits `Survey/export/{id}?filters=<json>` with the applied filters.

**Summary for sharing (review #5).** A "Summary for sharing" toggle in the header (`aria-pressed`, next to Print) switches the page itself into a summary view, on screen and in print: the chart cards only, with the Responses table and the individual-response panel hidden (the rows table is not even fetched in this mode, so it writes no `rows_view`), each free-text list replaced by "N written comments", each "Other" list by "N “Other” answers written in", and a caption at the top stating the active filters, the total n, the consent split and the date prepared. The §2 suppression applies as on screen. The officer then presses Print. The mode rides in the URL as `summary=1` in the hash (a `?summary=1` query is accepted as an entry point), so a summary link opens straight into it. This is the view to show at court; Print outside summary mode still prints everything on the page, named rows included.

**Activity.** Loading the rows table (any consent filter other than anonymous) and exporting are recorded in the activity log (§3). The table loads with the results page unless it opens in summary mode, so an ordinary visit to the results page is a `rows_view`.

**My Amtgard widget** — `<div id="pna-surveys-body"></div>` inserted at the top of `.pna-sidebar`; the My Amtgard JS block fetches `SurveyAjax/available` and renders a `.pna-card` "Available Surveys" with one row per survey (title, scope label, "closes Mon D" hint, "Continue" or "Take survey" link). The card is omitted entirely when the list is empty. No new CSS beyond the existing `pna-*` vocabulary.

**Site banner** — base controller (`class.Controller.php`, right after the What's New block): when `$_uid > 0` and the controller class name does not end in `Ajax`, `$this->data['SurveyBanner'] = $this->Survey->banner_for($_uid)` (one indexed query, or `null`). `default.theme` renders `#ork-survey-banner` immediately after `#ork-env-banner`: icon, "{title} — {description}", a "Take survey" link and an × that posts `dismiss_banner` and removes the element. Styles `.svb-*` live in the theme's existing inline `<style>` block with a dark-mode rule, matching the What's New pattern.

### 8. Error handling

- Domain methods return `['Status' => 0|1|3, 'Error' => string, …]` envelopes in the QualTest style; the AJAX controller maps them 1:1 to the JSON status codes. Page controllers set `$this->data['Error']` / call `no_authorization()`.
- Submit is one transaction with explicit `ROLLBACK`; a failed insert never leaves a participation row (which would lock the player out of a survey they never finished). Each rollback path writes one structured log line (§5).
- Structural builder transactions (retype, option set, clone, duplicate, question/page delete, image delete) check every statement and roll back on the first failure; the builder never reports "Saved" over a half-applied change.
- A SurveyAjax mutation without a valid `X-CSRF-Token` returns `{status:3, csrf:true, error:'Your security token expired. Reload the page and try again.'}`; each surface shows it inline with a Reload link.
- Structure edits on a locked survey return `status:1` with `error: 'Survey structure is locked because it has been opened.'`.
- Image upload errors are specific (too large, too many megapixels, wrong type, not an upload, per-survey image count or byte budget reached).
- The runner keeps answers in memory on a failed submit and re-shows the page containing the first error.
- The results page shows "No answers yet." on cards for questions with `n = 0`, "Too few responses to show (fewer than 5)." for suppressed cards and the card note's "Too few responses to show (fewer than 5): …" for suppressed cross-tab groups (§2, §7), and never throws on empty data (Highcharts error #13 is avoided by rendering into visible containers only).

### 9. Testing

**Unit (`tests/Unit/`, no DB):**
- `SurveyTypesTest.php` — settings defaults/validation per type, `validateAnswer` accept/reject cases per type, `isShown` (question- and page-level, hidden source), required semantics for `multi`/`matrix`/`ranking`.
- `SurveyConsentTest.php` — `scrubForConsent` produces exactly the §2 table, including the tenure band floors and a NULL partial duration; test responses stay `full`; `data_gate_enabled = 0` forces anonymous.
- `SurveyTypesTest.php` also covers ranking `randomize` defaulting on, and optional matrix/ranking answers that are partial or blank.
- `SurveyConsentTest.php` also covers `TENURE_BANDS` labels and ordering, and `shuffleOptions` keeping "Other" at the end.
- `SurveyAggregateTest.php` — `aggregateType` for every type with hand-built rows (NPS score, Borda score, weighted matrix mean, integer and fractional number bins, value mode, filled and year-grouped date periods, out-of-range ratings, empty groups as null, median on even counts); the small-group rules (`isNarrowing`, `isSuppressed`, `crosstabGroup`, `crosstabGroups` complementary suppression, `partialVisibility`, `complementaryCells` (including masking until the masked rows reach 5, and a kingdom withheld when the scope leaves one candidate), `viewForcedCells` (a filtered view re-masks its own handful, the survey-wide set always survives, an unfiltered view adds nothing), `smallPartialKingdoms`, `kingdomFilterCount`); `completionRate`, `reachedCount`, `tenureLabel`; `displayOrder` not being submission order.
- `SurveyPureTest.php` — image file names (token and legacy), image budget constants, `eventLabel`, structure lock, `logActivity` with and without an actor, retype rolling back on a failed statement, `question_duplicate` refused when locked, `manageableScopes` agreeing with `canCreate`.

**Image files under test:** PHPUnit's `config.test.php` points `DIR_SURVEY_IMAGE`/`HTTP_SURVEY_IMAGE` at `assets/survey-test/` (gitignored; created by `tests/bootstrap.php`, which refuses to run if it is pointed back at `assets/survey/`), because the integration suite writes and unlinks image files and the sandbox `image_id` sequence overlaps the dev site's — sharing `assets/survey/` deleted dev uploads.

**Integration (`tests/Integration/SurveyTest.php`, sandbox `ork_test`):** create as kingdom officer → add pages/questions/options → open → three submits (one per consent) → assert stored columns and truncation → second submit blocked by participation → `results`/`rows` maths and consent masking → manage denied for an officer of another kingdom → structure lock after open → clone → CSV row count.

**Curl (each AJAX action, cookie jar login as `heraldsbridge`)** with `Expected:` JSON and a DB `SELECT` after each mutation.

**Browser (serial, Claude-in-Chrome):** builder round trip; runner on desktop and at 390 px (iPhone) and 360 px; dark mode on all four pages and the banner; results charts in light and dark; widget and banner presence and dismissal.

### 10. Acceptance criteria

1. An ORK admin, a kingdom CREATE officer and a park CREATE officer can each create a survey for their scope; an EDIT-only officer and an officer of another org cannot (page and AJAX).
2. All 12 question types plus section and image blocks can be added, configured, reordered across pages, and rendered identically in the builder canvas and the runner.
3. A survey cannot be opened with zero answerable questions or an invalid question; once opened its structure is locked while copy stays editable.
4. Eligible players see the survey in Available Surveys; ineligible players (wrong org, retired when "Exclude retired accounts" is on, no recent attendance in scope, did not attend the chosen event, insufficient tenure, already completed) do not, and get a reason on the take page. An event-audience survey reaches visitors who attended the event. The first eligible visit writes one `ork_survey_start` row.
5. Draft resume works across a page reload; the draft disappears on submit.
6. Submitting with each consent level stores exactly the §2 columns; partial stores a band floor and no duration; `submitted_at` is midnight for partial/anonymous; participation and start rows have no timestamp; a second attempt is refused.
7. The data gate copy is the fixed text in §2 (scope name, or the ORK-wide variant), the first-screen notice shows when the gate is on, and the builder's Privacy quote matches the runner word for word; disabling the gate stores anonymous rows.
8. Results render the §7 chart per type, honour every filter, report excluded anonymous rows under a kingdom filter, show a cross-tab with every group, and page row-level data with persona links only for `full` rows. A narrowed subset under 5 responses, and any cross-tab group of 1–4 (plus the complement §2 rule 2 withholds with it), shows "Too few responses to show"; partial rows mask kingdom/band per §2. Completion and response rate show "—" when null. Filters survive a reload through the URL; a row opens the individual-response panel; Summary for sharing prints charts and caption only. CSV matches the filtered rows, including the Withheld column.
9. Banner shows for `show_banner` surveys the viewer is eligible for, dismisses per user, and never renders on AJAX routes.
10. Every page passes the dark-mode checklist and has no horizontal scroll at 360 px.
11. `php -l` clean on every PHP file; unit suite green; integration test green on the sandbox; migration classified; no `$DB`, `Ork3::$Lib`, or `new Survey(` under `orkui/` outside `orkui/model/`.
12. Every SurveyAjax mutation without `X-CSRF-Token` is refused with `csrf:true`; every survey surface sends it. Viewing rows (non-anonymous filter) and exporting write `ork_survey_activity` rows.

### 11. Files touched

Create: `db-migrations/2026-09-09-survey-module.sql`, `db-migrations/2026-09-10-survey-review-fixes.sql`; `assets/survey/.gitkeep`; `system/lib/ork3/class.SurveyTypes.php`, `class.Survey.php`, `class.SurveyResponse.php`, `class.SurveyReport.php`; `orkui/model/model.Survey.php`; `orkui/controller/controller.Survey.php`, `controller.SurveyAjax.php`; `orkui/template/default/Survey_index.tpl`, `Survey_build.tpl`, `Survey_take.tpl`, `Survey_results.tpl`; `orkui/template/default/style/survey.css`, `survey-build.css`, `survey-results.css`; `orkui/template/default/script/survey-render.js`, `survey-build.js`, `survey-take.js`, `survey-results.js`; `docs/survey-guide.md`; `tests/Unit/SurveyTypesTest.php`, `SurveyConsentTest.php`, `SurveyAggregateTest.php`, `SurveyPureTest.php`; `tests/Integration/SurveyTest.php`.

Modify: `config.dist.php`, `config.dev.php`, `config.test.php` (two constants each); `tools/ork-db/manifests/migration-classification.json5`; `system/lib/system/class.Controller.php` (banner lookup); `orkui/template/default/default.theme` (banner markup + `.svb-*` styles); `orkui/template/revised-frontend/Playernew_index.tpl` (widget div + fetch); `Kingdomnew_index.tpl` and `Parknew_index.tpl` (Surveys group in Admin Tasks); `revised-frontend/Admin_index.tpl` (report-list link); `orkui/whats_new_content.php` (release note entry, last).

### 12. Risks and gotchas

- **Two Highcharts versions on one page** is already the case on the release-utilization report; the results page must load the CDN script *after* `orkui.js` and only reference `Highcharts` inside its own IIFE.
- **APCu schema cache**: after applying either migration, `docker restart ork3-php8-app` or inserts into the new tables silently fail. After the review-fix migration, also run `Survey::upgradeLegacyImageNames()` once (§3) or pre-token images keep guessable names.
- **Audience size is today's audience.** `response_rate` divides by who the audience rules admit *now*; players who answered and then moved or retired drop out of the denominator. Label it "current audience" wherever it is shown.
- **Start rows can lag responses.** Surveys that collected responses before `ork_survey_start` existed have fewer starts than finishes; completion shows "—" for them rather than a rate over 100%.
- **The activity log has no reader UI** and no retention rule yet; it grows with builder activity (coalesced per 15 minutes) and every identity-bearing export.
- **Yapo drops `null` from UPDATE**: clearing `close_at` or `accent_color` must assign `''` (which the domain maps to `NULL` via raw SQL). The domain uses raw SQL for these tables throughout, so this only matters if someone reaches for `Yapo` objects.
- **Participation without timestamp** means "completed surveys" cannot be listed chronologically for a player. That is intentional.
- **`response_count` is a cache**; `summary()` computes real counts from `ork_survey_response` and the list page may show the cached value.
- The base-controller banner query runs on every non-AJAX page for logged-in users. It is a single indexed lookup (`idx_status_banner`) joined to dismissal and participation by primary key; if it ever shows in profiling, wrap it in `GhettoCache` for 300 s keyed by user.
- Session-token revalidation applies to `SurveyAjax` (not on the skip list): a player logged in on a second device gets `status:5` on autosave, and the runner must surface "Session expired — log in again" rather than looping.
- **The CSRF token lives in the PHP session.** When the session ends (logout, expiry) the token goes with it, so a page left open gets `csrf:true` (or `status:5`) on its next save; the inline Reload link is the recovery. `dismiss_banner` is on the allowlist: the banner renders on every CRM page, none of which emits `SvConfig`.
