# Survey: Update My Answers + Large Pairwise Bands Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let respondents reopen and change a submitted response while an `allow_edit` survey is open, and give very large pairwise sets lower encouragement thresholds.

**Architecture:** A per-survey `allow_edit` flag. At submit (when on) the response row stores an HMAC of (survey, player) as `edit_key`, a random `edit_uuid`, and the submitted answers JSON as `edit_answers_json`; all three are wiped at close. The runner loads those answers in "edit" mode and resubmits, which replaces the response's answers in place. Pairwise bands move to hundredths-of-a-percent integers so 0.5% and 0.25% are exact.

**Tech Stack:** PHP 8 / MariaDB (raw SQL in `system/lib/ork3`), vanilla JS runner/builder in `orkui/template/default/script/`, PHPUnit (`tests/Unit`, `tests/Integration`), node harnesses under `tests/Unit/js/`.

**Spec:** `docs/superpowers/specs/2026-11-22-survey-update-answers-design.md`

## Global Constraints

- SQL only in `system/lib/ork3/`; controllers reach it through `orkui/model/model.Survey.php` only.
- `edit_key`, `edit_uuid`, `edit_answers_json` are NEVER selected by results, export, rows, report, or any manager-facing path.
- Secret: constant `SURVEY_EDIT_SECRET` if defined and non-empty, else `hash('sha256', 'ork-survey-edit:' . DB_PASSWORD)`. Key = `hash_hmac('sha256', 'survey-edit:' . $surveyId . ':' . $uid, $secret)`.
- An edit never changes `consent`, `response_count`, `mundane_id`/scrub fields, `started_at`, `duration_seconds`, or bonus credit. For `consent='full'` only, `submitted_at` updates to the edit time.
- No native `confirm/alert/prompt`. Dark mode for any new CSS (`html[data-theme="dark"]`). Tooltips via `data-tip`.
- Toggle label (exact): `Allow respondents to change their answers before the survey closes`. Card CTA (exact): `Update my answers`.
- Never stage `system/lib/ork3/class.Authorization.php`; stage files by explicit path only; never `git add -A`.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. Player edits after `close_at` passed while status is still `open` — must be refused ("closed") and the edit columns swept. (Task 2 test `testEditRefusedAfterCloseAtAndKeysSwept`.)
2. Two quick resubmits (double-click) — must not create a second response or bump `response_count`. (Task 2 test `testResubmitTwiceKeepsOneResponse`.)
3. A response submitted before `allow_edit` was turned on — player stays "completed", no crash. (Task 2 test `testLegacyResponseWithoutKeyIsCompleted`.)
4. Builder turns `allow_edit` off mid-survey — edit columns wiped, players become "completed". (Task 2 test `testAllowEditOffWipesKeys`.)
5. A reopen link with someone else's UUID, or a stale UUID — ignored, falls back to the viewer's own state; never reveals another response. (Task 2 test `testForeignUuidIgnored`.)

---

### Task 1: Pairwise bands for very large sets

**Files:**
- Modify: `system/lib/ork3/class.SurveyTypes.php` (`PAIRWISE_BANDS`, `pairwisePlan`)
- Modify: `orkui/template/default/script/survey-render.js` (`PW_BANDS`, `pairwisePlan`)
- Modify: `orkui/template/default/script/survey-build.js` (`pairwiseHelpHtml` — the helper modal: percent labels and the sentence)
- Modify: `docs/survey-guide.md` (band table)
- Test: `tests/Unit/SurveyTypesTest.php`, `tests/Unit/SurveyPairwisePlanScriptTest.php` (+ harness `tests/Unit/js/survey-pairwise-harness.js` if its range needs widening)

**Interfaces:**
- Produces: `PAIRWISE_BANDS` entries are `[upperBound|null, [t1,t2,t3,t4]]` in HUNDREDTHS of a percent. `pairwisePlan()` keeps its keys; `band_pcts` stays in percent (int when whole, float like `0.5` otherwise — `t/100`), tiers = `intdiv(t * possible + 9999, 10000)`.

Bands (hundredths of a percent):
```
[105,   [3000, 4000, 5000, 6000]],
[200,   [2000, 3000, 4000, 5000]],
[300,   [1000, 2000, 3000, 4000]],
[500,   [1000, 1500, 2000, 2500]],
[1000,  [500, 1000, 1500, 2000]],
[2000,  [200, 400, 600, 800]],
[5000,  [100, 200, 300, 400]],
[10000, [50, 100, 150, 200]],
[null,  [25, 50, 75, 100]],
```

- [ ] **Step 1: Failing tests** in `SurveyTypesTest`: `testPairwiseBandsForLargeSets` asserting, by option count → possible:
  - n=32 (496): `band_pcts [10,15,20,25]`, tiers `[50,75,100,124]`
  - n=33 (528): `band_pcts [5,10,15,20]`, gate 27
  - n=46 (1035): `band_pcts [2,4,6,8]`, gate 21
  - n=64 (2016): `band_pcts [1,2,3,4]`, gate 21
  - n=101 (5050): `band_pcts [0.5,1,1.5,2]`, gate 26
  - n=142 (10011): `band_pcts [0.25,0.5,0.75,1]`, gate 26
  - existing small-band expectations unchanged.
  Extend the JS/PHP parity test to also compare n ∈ {32,33,45,46,63,64,100,101,141,142,150}.
- [ ] **Step 2:** Run `vendor/bin/phpunit tests/Unit/SurveyTypesTest.php tests/Unit/SurveyPairwisePlanScriptTest.php` — expect FAIL.
- [ ] **Step 3:** Implement in PHP and the JS mirror. JS: `band_pcts` = `t/100` (JS numbers make `5` and `0.5` naturally); PHP: `$t % 100 === 0 ? intdiv($t, 100) : $t / 100`. Note JSON parity: PHP float `2.0` must not appear — whole values are ints.
- [ ] **Step 4:** Helper modal: percent cells and the sentence render `0.5%`, `1.5%`, `0.25%` (no `0.50`); the "here" row highlighting works for the new rows; row labels use `toLocaleString()` for 1,001–2,000 etc. Update the guide table to the nine rows above (in percent).
- [ ] **Step 5:** Tests pass. Commit `Survey pairwise: lower encouragement bands for very large sets`.

---

### Task 2: Domain — `allow_edit`, edit keys, edit submit, sweep

**Files:**
- Create: `db-migrations/2026-11-22-survey-allow-edit.sql`
- Modify: `tools/ork-db/manifests/migration-classification.json5`
- Modify: `system/lib/ork3/class.Survey.php` (settings map, `setStatus`, update of `AllowEdit`, clone copies `allow_edit`)
- Modify: `system/lib/ork3/class.SurveyResponse.php`
- Modify: `orkui/model/model.Survey.php` (pass-throughs only if signatures change)
- Test: `tests/Integration/SurveyEditTest.php` (new; copy setup/teardown pattern from `tests/Integration/SurveyTest.php`)

Migration (idempotent, `IF NOT EXISTS`):
```sql
ALTER TABLE ork_survey ADD COLUMN IF NOT EXISTS allow_edit TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_resume;
ALTER TABLE ork_survey_response
  ADD COLUMN IF NOT EXISTS edit_key CHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS edit_uuid CHAR(36) NULL,
  ADD COLUMN IF NOT EXISTS edit_answers_json MEDIUMTEXT NULL,
  ADD UNIQUE KEY IF NOT EXISTS uq_edit_key (edit_key);
```
Classification: `"class": "S", "render": "full"`, notes describing the above. Apply locally to both `ork` and `ork_test` via `mariadb` (see memory reference_startup_commands).

**Interfaces (Produces):**
- `Survey::FIELDS['AllowEdit'] => ['allow_edit','bool']`; `allow_edit` in clone column lists.
- `SurveyResponse::editKey(int $surveyId, int $uid): string` (public static).
- `SurveyResponse::editableResponse(array $surveyRow, int $uid): ?array` → `['response_id'=>int,'edit_uuid'=>string,'answers'=>array]` or null. Null unless `allow_edit` on and survey open-by-schedule.
- `SurveyResponse::sweepEditKeys(?int $surveyId = null): void` — NULLs the three columns for responses of surveys that are not open, have `close_at <= now` (PHP clock), or have `allow_edit = 0`; when `$surveyId` given, only that survey.
- `eligibility()` returns `['eligible'=>true,'reason'=>'ok','editing'=>true]` instead of `completed` when `editableResponse` is non-null.
- `definitionForRespondent()` adds `'Edit' => ['uuid'=>…, 'answers'=>…] | null`; when `Edit` is set, `Draft` is the player's draft if one exists (an in-progress edit), else null. Public survey fields gain `allow_edit`.
- `submit()`: when eligibility says `editing`, UPDATE path in one transaction: delete `survey_answer` rows for that response, insert new ones (reuse the existing chunked insert — extract to a private `insertAnswers(int $responseId, array $rows): bool`), update `edit_answers_json` (and `submitted_at` only for full consent), delete the draft. No participation insert, no `response_count` bump, no credit. Returns the same envelope plus `'Edited'=>true`, `'EditUuid'`.
  First submit with `allow_edit` on (non-test): set `edit_key`, `edit_uuid` (`uuid v4` from `random_bytes`), `edit_answers_json`. Return `'EditUuid'`.
- `Survey::setStatus` to non-open → `sweepEditKeys($id)`. `Survey::update` switching AllowEdit to 0 → `sweepEditKeys($id)`. `editableResponse` itself calls the sweep for its survey when closed-by-date before returning null.
- `widgetRow` gains `'editable' => bool` (cards); a player with an editable response is listed in available surveys.

- [ ] **Step 1: Failing tests** in `SurveyEditTest`:
  - `testFirstSubmitStoresEditKeyOnlyWhenAllowEdit` — off: columns NULL; on: `edit_key === SurveyResponse::editKey(sid, uid)`, uuid matches `/^[0-9a-f-]{36}$/`.
  - `testEditReplacesAnswersInPlace` — submit, resubmit different answers: one response row, `response_count` 1, answers equal the second set, `consent` unchanged.
  - `testAnonymousEditKeepsScrubbedFields` — anonymous: `mundane_id`, `submitted_at`, `duration_seconds` unchanged after edit.
  - `testResubmitTwiceKeepsOneResponse`, `testEditRefusedAfterCloseAtAndKeysSwept`, `testLegacyResponseWithoutKeyIsCompleted`, `testAllowEditOffWipesKeys`, `testCloseStatusWipesKeys`, `testForeignUuidIgnored` (definition for viewer B never returns A's answers).
  - `testResultsAndExportNeverSelectEditColumns` — grep-style: `SurveyReport` source contains no `edit_key|edit_uuid|edit_answers_json`, and no `r.*`/`SELECT *` on survey_response.
  - `testNoSecondCreditOnEdit` — with credit configured, grant count stays 1.
- [ ] **Step 2:** Run `vendor/bin/phpunit tests/Integration/SurveyEditTest.php` — FAIL.
- [ ] **Step 3:** Implement per Interfaces.
- [ ] **Step 4:** Run the new test plus `tests/Integration/SurveyTest.php tests/Integration/SurveySharingCreditTest.php tests/Unit/SurveyConsentTest.php tests/Unit/SurveyPureTest.php` — PASS.
- [ ] **Step 5:** Commit `Survey: allow respondents to update answers before close (domain)`.

---

### Task 3: UI — builder toggle, runner edit mode, cards, reopen link, guide

**Files:**
- Modify: `orkui/template/default/script/survey-build.js` (Experience section toggle)
- Modify: `orkui/template/default/script/survey-take.js`
- Modify: `orkui/controller/controller.Survey.php` (`s()` and `take()` read `&r=` uuid and pass it into the embedded definition only if it equals the viewer's own `Edit.uuid`; otherwise ignored)
- Modify: `orkui/controller/controller.SurveyAjax.php` (`submit` returns `edited`, `edit_url`)
- Modify: `orkui/template/revised-frontend/_survey_available_card.tpl` (CTA `Update my answers` when `editable`)
- Modify: `docs/survey-guide.md` (Experience settings: the new toggle, its privacy note, legacy responses not editable)
- Test: `tests/Unit/SurveyEditRunnerScriptTest.php` + node harness `tests/Unit/js/survey-edit-harness.js` if the runner exposes testable pure helpers; otherwise browser verification in Step 4.

Behaviour:
- Builder: `checkRow('Allow respondents to change their answers before the survey closes', truthy(s.allow_edit), 'data-sv-field="AllowEdit"', false)` directly under the resume row, with a help line: "Responses can be reopened from the survey link until it closes. While open, the server keeps a private link from each response to its player, removed at close. Responses sent before this was turned on can't be changed."
- Runner, when `definition.Edit` present: prefill answers from `Draft` (in-progress edit) else `Edit.answers`; welcome/first screen shows a note "You've already answered this survey. Change anything you like and press Update my answers."; the consent block is not shown (consent fixed); the submit button reads `Update my answers`; thanks screen after an edit says "Your answers have been updated."
- Thanks screen (first submit or edit) when `edit_url` returned: "You can change your answers until this survey closes:" + the link (`UIR . 'Survey/s/{slug}&r={uuid}'`, or `Survey/take/{id}&r={uuid}` when no slug) with a copy button (`data-tip="Copy link"`).
- Session/local answer mirror (`SS_KEY`) must not carry a finished edit's answers into a later visit.

- [ ] **Step 1:** Implement builder toggle; verify the field saves (`AllowEdit` round-trip via existing update test or curl).
- [ ] **Step 2:** Implement runner edit mode, controller `&r=` handling, submit envelope, card CTA.
- [ ] **Step 3:** Update the guide.
- [ ] **Step 4: Browser verify** on :19080 (test account, not the user's): open a survey with a large pairwise question and `allow_edit` on; do some matchups; submit; reopen from the card and from the thanks link; add matchups; update; confirm results show one response with the new picks; close survey and confirm the link shows the closed message. Check both themes.
- [ ] **Step 5:** Commit `Survey: Update my answers in the runner, builder and cards`.
