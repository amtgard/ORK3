# Walker Release — Qualification Tests

**Branch:** `feature/qualification-tests`
**Module:** Reeve / Corpora qualification testing, kingdom-managed.

A self-serve testing system that lets each kingdom build its own
multiple-choice question banks for the **Reeve's Test** and the
**Corpora Test**, configure pass criteria and validity periods, and
issue passes that flow back into the player's official qualifications.

## Why this exists

Until now, qualification tracking has been a manual record-keeping
exercise: an officer marks a player qualified after testing them in
person or on paper. Walker moves the test-administration step into
the app so kingdoms can:

- Maintain a versioned bank of questions per test type.
- Let players take the test on their own time, with auto-grading.
- Set their own pass percent, question count, and validity window.
- Hand off question-set authorship to non-officer subject-matter
  experts via a per-test "manager" role.
- Pull from a shared **library** of questions seeded by other
  kingdoms that opted in.

The pass result is written back into the existing
`mundane.reeve_certified` / `mundane.corpora_certified` columns so
nothing downstream needed to change.

---

## Data model

All tables are InnoDB / utf8mb4. None of them carry FK constraints —
referential integrity is enforced in PHP per the project convention.

### `ork_qual_question`
Question bank, kingdom-scoped per test type.

| column | type | meaning |
|---|---|---|
| `qual_question_id` | INT UNSIGNED PK | |
| `kingdom_id` | INT UNSIGNED | scope |
| `test_type` | ENUM('reeve','corpora') | |
| `question_text` | TEXT | |
| `status` | ENUM('active','archived') | only `active` is served on tests |
| `created_by` | INT UNSIGNED | mundane id, `0` = library import |
| `created_at` / `updated_at` | DATETIME | |

Index `(kingdom_id, test_type, status)` powers every list query.

### `ork_qual_answer`
Multiple-choice options. One question may have multiple correct
answers (multi-select); scoring requires all-correct, none-incorrect.

| column | type | meaning |
|---|---|---|
| `qual_answer_id` | INT UNSIGNED PK | |
| `qual_question_id` | INT UNSIGNED | parent question |
| `answer_text` | TEXT | |
| `is_correct` | TINYINT(1) | |

### `ork_qual_config`
One row per `(kingdom_id, test_type)` (UNIQUE). Holds per-kingdom
test rules.

| column | type | default | meaning |
|---|---|---|---|
| `question_count` | INT | 10 | number of questions served per attempt |
| `pass_percent` | INT | 70 | minimum to pass |
| `valid_days` | INT | 365 | days a passing result stays valid |
| `valid_until` | DATE | NULL | optional fixed expiry; **takes precedence** over `valid_days` if set |
| `max_retakes` | INT | 0 | total submissions allowed per player; `0` = unlimited |
| `share_questions` | TINYINT | 0 | opt-in to publish this kingdom's bank to the global library |
| `instructions` | TEXT | NULL | shown above the test on the take page |

### `ork_qual_result`
One row per `(player_id, kingdom_id, test_type)` — UNIQUE; **upserted**
on every passing retake (so the row always reflects the most recent
pass). Failing attempts do not write here.

| column | type | meaning |
|---|---|---|
| `score_percent` | INT | most recent pass score |
| `passed_at` | DATETIME | |
| `expires_at` | DATETIME | computed at submit time from `valid_until ?? passed_at + valid_days` |

### `ork_qual_retake`
Total-submissions counter (passing or failing) per
`(player_id, kingdom_id, test_type)`. Lets the cap in
`config.max_retakes` be enforced. Officers can reset a single player
or every player at once via Ajax.

### `ork_qual_question_stat`
Aggregate stats per question — `times_answered`, `times_correct`.
Used by the management screen to surface low-quality questions
(low correct rate or high report count → likely needs editing).

### `ork_qual_manager`
Per-kingdom list of mundanes authorized to manage the test bank.
This is **separate from standard officer auth** — a kingdom may want
a Don of Reeves to own the question pool without elevating their
broader permissions. UNIQUE `(kingdom_id, mundane_id)`.

### `ork_qual_report`
Player-submitted feedback on a question (during or after taking it).
`reason` is one of `wording`, `correct`, `outdated`, `other`. Counts
surface to managers in the management UI for triage.

### Kingdom config flags (`ork_configuration`)
Migration `2026-03-29-add-qual-test-enabled-configs.sql` seeds two
new keys per active kingdom, both defaulting to `"0"` (disabled):

- `QualTestReeveEnabled`
- `QualTestCorporaEnabled`

Kingdoms must explicitly opt into each test type before it appears
to players or managers. Both flags surface in Kingdom Admin via
`Admin_editkingdom.tpl`.

---

## Code map

### Service layer
**`system/lib/ork3/class.QualTest.php`** — single class, ~1240 lines.
Notable public methods:

- Auth / managers: `canManage`, `getManagers`, `addManager`, `removeManager`.
- Config: `getConfig`, `saveConfig` (full upsert).
- Question CRUD: `getAllQuestions`, `getQuestion`, `saveQuestion`,
  `setQuestionStatus`, `setQuestionStatusBatch`, `saveQuestionBatch`
  (bulk import), `duplicateQuestion`.
- Test serving: `getQuestionsForTest` (random N from active pool,
  shuffled answers), `getCorrectAnswers` (server-side answer key),
  `scoreTest`, `recordQuestionStats`, `recordResult`.
- Retake gating: `incrementRetakeCount`, `getRetakeCount`,
  `resetPlayerRetakes`, `resetAllRetakes`.
- Mundane sync: `syncMundaneQual` writes back to
  `mundane.reeve_certified` / `corpora_certified` so existing
  qualification reports keep working unchanged.
- Stats / reports: `getPlayerResults`, `getTestResults`,
  `getTestReportStats` (kingdom-rollup).
- Library: `getLibraryQuestions` (cross-kingdom pool of
  `share_questions=1` rows), `copyQuestionToKingdom`.
- Question reports: `reportQuestion`, `getReportCounts`,
  `clearReports`.

### Controllers
- **`controller.QualTest.php`** — page routes:
  - `manage($kingdom_id)` — manager dashboard.
  - `questions(action)` — question list with filters.
  - `question(action)` — single question editor.
  - `take(action)` — player-facing take page (status, take, result).
- **`controller.QualTestAjax.php`** — 19 endpoints covering save,
  set status, report/clear/getreports, bulk status, bulk import,
  duplicate, preview, take-server (`gettest` / `submittest` /
  `checkanswer`), retake resets, manager add/remove, library list /
  copy.

### Frontend integration
- **`Playernew_index.tpl`**:
  - Sidebar "Qualifications" card displays Reeve / Corpora pass
    state with admin pencil → `pnOpenQualModal()`.
  - Take/manage CTAs gated on the kingdom flags.
- **`Kingdomnew_index.tpl`** Reports tab:
  - Per-test-type cards showing question count and config summary,
    visible whenever `CanManageTests` OR the type is enabled.
  - "Manage" button → `QualTest/manage/<kid>`.
- **`controller.Reports.php`** — adds the standalone test-results
  report served by `Reports_test_results.tpl`.

### Templates
- `QualTest_manage.tpl` — manager dashboard (stats, managers,
  config form, library-import drawer).
- `QualTest_questions.tpl` — list + bulk-status + bulk-import.
- `QualTest_question.tpl` — single editor.
- `QualTest_take.tpl` — 1,355-line player flow with status view →
  take view → result view, confetti on pass, in-test report-question
  button.
- `Reports_test_results.tpl` — DataTables-backed kingdom rollup
  with pass/fail badges and qualified-percent stat.

---

## Workflows

### Manager (kingdom officer or qual_manager)

1. Open Kingdom profile → Reports tab → **Manage Reeve's Test** (or
   Corpora). Auth check via `QualTest::canManage`.
2. **Configure**: question count, pass %, validity (`valid_days` OR
   fixed `valid_until`), max retakes, opt-in to library, instructions
   text.
3. **Build the bank**: add questions singly via the editor, or paste
   a structured block via bulk import; duplicate existing for
   variants; archive instead of delete to preserve stats.
4. **Pull from library**: drawer shows shared questions from other
   opted-in kingdoms with question text + correct-rate. Copy
   creates a new local row tagged `created_by = 0` so it's clearly
   imported.
5. **Triage reports**: questions with player-submitted reports show
   a counter; click to see reasons, then edit or clear.
6. **Add managers**: search players to grant test-management auth
   without giving them broader officer rights.
7. **Reset retakes**: per player (e.g. a player who failed for a
   silly reason) or for everyone (e.g. you just rewrote half the
   questions).

### Player

1. Visit own player profile → Qualifications card; or follow a
   "Take Reeve's Test" link surfaced by the kingdom.
2. **Status view** shows current pass state, expiration, retakes
   remaining, instructions, and stat chips (count / pass% / validity).
3. **Take**: questions one-by-one, can flag a question as
   wording/correct/outdated/other while taking it.
4. **Submit** → server scores via `scoreTest` (all-correct semantics
   for multi-select). On pass: `recordResult` upserts the result row,
   `syncMundaneQual` writes back to `mundane.<type>_certified`,
   confetti fires. On fail: only the retake counter increments.
5. Retakes allowed up to `max_retakes` (or unlimited if `0`).

---

## Things to know before changing this module

- **Mundane sync is load-bearing.** The legacy `mundane.<type>_certified`
  flag is what every other report/UI reads. `syncMundaneQual` is the
  bridge; do not stop calling it on a passing submit, or qualifications
  silently fall out of sync.
- **`valid_until` overrides `valid_days`.** If you add a new validity
  knob, decide its precedence relative to both — there is no
  three-way merge logic today.
- **Library is opt-in both ways.** A kingdom only sees library
  questions if their own `share_questions = 1`? **No** — actually
  any kingdom can pull from the library; only the contributing
  kingdom needs the flag set. Re-read `getLibraryQuestions` before
  changing this assumption.
- **Question-stat incrementing happens server-side at submit**, not
  per-question. If you add a mid-test "save my answer" endpoint, do
  not double-count.
- **Manager check is layered**: standard officer auth OR a row in
  `ork_qual_manager`. Always go through `canManage` — never
  inline an auth check.
- **Two retake counters in play**: `ork_qual_retake.retake_count`
  is total submissions; `max_retakes` config is the cap. Reset
  endpoints write the counter, not the cap.

## What's not done in this branch

Inferred from the code surface — the user-visible footprint is
complete (config, build, take, results, reports, library, managers,
retakes, instructions, confetti). No explicit `TODO` markers in the
QualTest source. Open question for product:

- **No question-level analytics surface for managers.** The stat
  table exists and updates, but only `Reports_test_results.tpl`
  consumes it. A "questions ranked by difficulty/correct-rate"
  view would close the loop on the report-question feature.
- **No notification when a player passes/fails.** Kingdom officers
  must check the report.
- **No multi-language support** for question text — single text
  column.
