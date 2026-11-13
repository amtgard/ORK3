# Survey Module: Pairwise Comparison Question Type (Design Spec)

**Date:** 2026-09-11
**Status:** Approved design, ready for implementation plan
**Branch:** `feature/survey-module` (extends `2026-09-09-survey-module-design.md`, written "base §N")
**Scope:** A fifteenth question type, `pairwise`. The respondent sees two options at a time and picks one or calls a tie. Each respondent gets a random order of matchups, a progress bar that tells them when they have done enough, and an off-ramp. Results rank the options by win percentage.

## Problem

Ranking asks a respondent to order every option at once. That works for five options and fails for thirty: nobody can hold thirty items in their head, and a drag list that long is miserable on a phone. Pairwise comparison asks a much easier question ("this or that?") many times. The cost is volume: N options make N(N−1)/2 matchups, so thirty options make 435. The type needs a way to take a useful sample from each respondent without demanding all of it.

## Decisions (made by the owner, 2026-09-11)

| # | Question | Decision |
|---|---|---|
| P1 | Scoring | Win = 1 point, loss = 0, tie = ½ to each side. An option's **win %** = points ÷ matchups it appeared in. Final ranking is win % descending. |
| P2 | Randomization | Every respondent gets their own random order of matchups and random left/right sides. Nothing is shared or seeded across respondents; there is no coverage balancing. |
| P3 | Is the first threshold a gate? | **Only when the question is required.** A required pairwise question keeps Next locked until the respondent reaches the first threshold; on a set of 30 matchups or fewer that means all of them. An optional pairwise question can be skipped, and whatever matchups the respondent did still count. |
| P4 | Option cap | **None.** The builder warns above 30 options but does not block. |
| P5 | Encouragement bands | By total matchups M: ≤30 plain bar; 31–105 = 30/40/50/60%; 106–200 = 20/30/40/50%; 201–300 = 10/20/30/40%; 301+ = 10/15/20/25%. The "105" boundary is a matchup count (15 options). |
| P6 | Option entry | In the builder a pairwise question's options are one paragraph textarea, one option per line, so an author can paste a list. |
| P7 | Help | A (?) on the pairwise builder card opens an explanation of scoring, gates, sizes and the encouragement bar, with this question's numbers filled in. |
| P8 | Reporting | Possible matchups, average % of matchups completed per respondent, and a win % ranking. |

## Current-State Constraints (verified)

- **Type catalog.** `SurveyTypes` (`system/lib/ork3/class.SurveyTypes.php`) is the pure table the builder, runner and aggregator share: `TYPES`, `ANSWERABLE`, `SHOW_IF_SOURCES`, `OPTION_ROLES`, `defaultSettings()`, `validateSettings()`, `seedOptions()`, `minOptions()`, `validateAnswer()`. `Model_Survey::catalog()` (`orkui/model/model.Survey.php:206`) ships `types`, `show_if_sources`, `option_roles` and `other_max_length` to the builder.
- **Question type is a DB ENUM.** `ork_survey_question.type` is `ENUM('single',…,'image')` (`db-migrations/2026-09-09-survey-module.sql:62`). A new type needs a migration, and every new migration must be classified in `tools/ork-db/manifests/migration-classification.json5` (the three survey migrations are class `S`, render `full`).
- **Answer rows.** `ork_survey_answer(answer_id, response_id, question_id, option_id, row_option_id, value_text, value_num DECIMAL(12,3))`, indexed on `(question_id, option_id)` and `(question_id, row_option_id)`. `validateAnswer()` turns one raw answer into these rows. Consent scrubbing, response deletion, test-row exclusion, draft purge and sharing views all work at the response level, so they handle any answer rows without knowing the type.
- **Options.** `ork_survey_option(option_id, question_id, role ENUM('choice','row','column'), sort_order, label VARCHAR(255), value_num, is_other)`. `Survey::optionSet()` (`class.Survey.php:2031`) replaces one role's whole list in order: supplied ids are kept, new entries inserted, missing ones deleted. On a structure-locked survey only labels may change (same ids, count and order), otherwise it returns `LOCKED_ERROR`.
- **Retype.** `retypeQuestion()` (`class.Survey.php:~1790`) keeps options whose role the new type owns, deletes the rest, and tops a type up to `minOptions()`.
- **Runner.** `survey-render.js` (`SvRender.question/read/write/setError`) renders every type for both the runner and the builder canvas. `survey-take.js` mirrors answers to sessionStorage (300 ms debounce) and autosaves the draft to the server (`draft_save`, 1500 ms debounce). Required checks run client-side on Next and again server-side on submit.
- **Builder modal.** `Survey_build.tpl:159` holds one shared `#svb-modal` (title + body), opened by `survey-build.js` (`:2596`) with focus trapping and focus restore.
- **Reporting.** `SurveyReport::aggregateType()` (`class.SurveyReport.php:1107`) is pure and dispatches per type. `MIN_CELL = 5` suppression applies to every per-question aggregate. `CROSSTAB_SOURCES = [single, dropdown, yesno]`, `CROSSTAB_TARGETS = [single, dropdown, yesno, multi, rating, nps]`; ranking and matrix are neither. The CSV/individual-response cell formatter is the per-type switch at `class.SurveyReport.php:~2090`. `survey-results.js` builds one Highcharts 11 spec per type (`specRanking` at `:448`) and tabular data on screen uses DataTables.
- **JS-under-node tests.** `tests/Unit/SurveyCreditPanelScriptTest.php` runs a harness in `tests/Unit/js/` with `node` and skips when node is missing.
- **Greenfield.** No production surveys or responses exist, so there is no legacy data to migrate.

## Design

### 1. Schema

New migration `db-migrations/2026-09-11-survey-pairwise.sql`: `ALTER TABLE ork_survey_question MODIFY type ENUM(<the existing 14 values>, 'pairwise') NOT NULL`. The statement is idempotent (re-running it is a no-op). Classify it `S`, render `full`, with a note. No other schema change.

**Answer row mapping** (one row per matchup the respondent judged):

| Column | Meaning for `pairwise` |
|---|---|
| `option_id` | the option shown on the **left** |
| `row_option_id` | the option shown on the **right** |
| `value_num` | the left option's points: `1` (left won), `0.5` (tie), `0` (right won) |
| `value_text` | `NULL` |

The right option's points are `1 − value_num`. Rows are inserted in the order the respondent answered.

### 2. Catalog and the plan

`SurveyTypes` gains:
- `pairwise` in `TYPES` (palette position after `ranking`) and `ANSWERABLE`. Not in `SHOW_IF_SOURCES`.
- `OPTION_ROLES['pairwise'] = ['choice']`, `minOptions('pairwise') = ['choice' => 3]`, `seedOptions('pairwise')` = three options ("Option 1" … "Option 3"). `defaultSettings('pairwise') = []`, so `validateSettings` drops any keys it is sent.
- `PAIRWISE_SMALL_MAX = 30` and `PAIRWISE_BANDS`, the one table everything reads:

```php
public const PAIRWISE_BANDS = [
    // [max matchups (null = no upper bound), [tier1 %, tier2 %, tier3 %, tier4 %]]
    [105,  [30, 40, 50, 60]],
    [200,  [20, 30, 40, 50]],
    [300,  [10, 20, 30, 40]],
    [null, [10, 15, 20, 25]],
];
```

- `pairwisePlan(int $optionCount): array`, pure:

```
possible = n(n−1)/2                     (0 when n < 2)
small    = possible <= 30
tiers    = small ? []
                 : [ceil(pct × possible / 100) for pct in the band's four %]
gate     = small ? possible : tiers[0]  (the count a REQUIRED respondent must reach)
returns  { possible, small, band_pcts, tiers, gate }
```

The ceiling is integer math, `intdiv(pct × possible + 99, 100)` in PHP and `Math.floor((pct × possible + 99) / 100)` in JS, so no float rounding can move a threshold by one.

Worked values:

| Options | Matchups | Band | Tier counts (gate first) |
|---|---|---|---|
| 3 | 3 | small | gate 3 |
| 8 | 28 | small | gate 28 |
| 9 | 36 | 31–105 | 11 · 15 · 18 · 22 |
| 12 | 66 | 31–105 | 20 · 27 · 33 · 40 |
| 15 | 105 | 31–105 | 32 · 42 · 53 · 63 |
| 16 | 120 | 106–200 | 24 · 36 · 48 · 60 |
| 20 | 190 | 106–200 | 38 · 57 · 76 · 95 |
| 21 | 210 | 201–300 | 21 · 42 · 63 · 84 |
| 25 | 300 | 201–300 | 30 · 60 · 90 · 120 |
| 26 | 325 | 301+ | 33 · 49 · 65 · 82 |
| 30 | 435 | 301+ | 44 · 66 · 87 · 109 |

**Shipping the plan to the browser.** `survey-render.js` mirrors the table as `PW_SMALL_MAX` / `PW_BANDS`, the same way it already mirrors `SurveyTypes::ANSWERABLE` and `OTHER_MAX_LENGTH`, and exposes `SvRender.pairwisePlan(n)`. The builder uses it to recompute the plan live as the author types. `SurveyResponse::definitionForRespondent()` adds `pairwise: pairwisePlan(n)` to every pairwise question it returns, and the runner prefers that server-sent plan, so the gate a respondent sees is the gate the server enforces. A node parity test (§9) pins the JS copy to the PHP function for every n from 0 to 60.

### 3. Answer shape and validation

Raw answer (client → `draft_save` / `submit`), in answer order:

```json
[ {"a": 812, "b": 815, "w": 812}, {"a": 820, "b": 812, "w": 0} ]
```

`a` is the left option, `b` the right, and `w` the winner's option id or `0` for a tie. `SurveyTypes::validateAnswer()` gets a `validatePairwise()` branch:

- Not an array, or an entry that isn't an object with numeric `a`, `b`, `w` → "Please make your picks again." (defensive; the runner never sends it).
- `a` or `b` not a `choice` option of this question → "That option is not part of this question."
- `a == b`, or `w` not in `{a, b, 0}` → same defensive message.
- The same unordered pair twice → "Each matchup may be answered only once."
- More entries than `possible` → caught by the repeat rule.
- **Required** and `count < gate` → "Please complete at least {gate} matchups to continue." (`gate` = `possible` on a small set, where the copy reads "Please finish all {possible} matchups to continue.")
- Empty and optional → no rows (the question was skipped).

Each valid entry becomes `row(a, b, null, w == a ? 1.0 : (w == 0 ? 0.5 : 0.0))`.

`Survey::optionSet()` additionally refuses, for a pairwise question, two labels equal after trim and case-fold ("“{label}” is listed twice.") and any `is_other` option ('A pairwise question cannot have an "other" option.'). Retype into `pairwise` already clears `is_other` (`retypeQuestion()` keeps it only on single / multi / dropdown); a test pins that.

### 4. Randomization (runner)

When the question renders, the runner:
1. Builds all `possible` unordered pairs of the question's option ids.
2. Removes pairs already present in the restored answer (draft or sessionStorage mirror), in either order.
3. Shuffles the rest with Fisher–Yates on `Math.random()`.
4. Flips left/right per pair with a coin toss.
5. Makes one greedy pass that swaps a pair forward when it would repeat an option from the previous matchup, if a non-repeating pair exists later in the queue. It is a soft preference; the queue order is otherwise untouched.

Nothing is seeded, so a respondent who resumes gets a fresh random order over the pairs they have not done. Undo (§6) puts the undone pair back at the front of the queue with the same sides.

### 5. Builder

**Option entry (P6).** A pairwise card's option area is one `<textarea class="svb-pw-lines">`, one option per line, pre-filled from the current options in `sort_order`. Like every builder field it saves as the author types, through the builder's debounced `save()` and `option_set` with role `choice`. A multi-line paste has bullets ("- ", "* ", "• ") stripped before it lands, as the option-row paste already does:
- Lines are trimmed; blank lines are dropped.
- **Unlocked survey:** each line whose text exactly matches an existing option's label reuses that option's id (first match wins), so reordering and inserting keep ids. Other lines are sent without an id (new options), and options no line matched are omitted (deleted).
- **Locked survey:** line *i* maps to option *i* by position. If the line count differs, the textarea shows the lock message inline and does not save; otherwise the relabels are sent with their ids.
- **Duplicates:** a case-insensitive duplicate line shows "“{label}” is listed twice." under the box and nothing saves until it is fixed. The server enforces the same rule (§3).
- Fewer than 3 lines → the existing min-options error.

**Live readout** under the textarea, recomputed on every input from `SvRender.pairwisePlan` and the catalog's bands:
- "12 options → 66 matchups · required respondents do at least 20"
- Small set: "6 options → 15 matchups · required respondents do all 15"
- Above 30 options, a warning line (`.svb-warn` treatment): "Over 30 options makes for a long question: 45 options is 990 matchups."

**Help (?) (P7).** An icon button (`fa-circle-question`, `aria-label="How pairwise questions work"`, `data-tip`) beside the readout opens `#svb-modal` titled "How pairwise questions work". Its body is built from the plan, so the numbers always match:
1. **How it works:** respondents see two options at a time and pick one or call a tie. Win = 1 point, tie = ½ each, loss = 0. Results rank options by win %.
2. **Random every time:** each respondent gets their own random order and random sides.
3. **How many they're asked to do:** the five-band table (≤30 · 31–105 · 106–200 · 201–300 · 301+) with each band's four thresholds, **this question's band highlighted**, and one sentence with this question's numbers: "Your 12 options make 66 matchups. Required respondents do at least 20 (30%), then see encouragement at 27, 33 and 40."
4. **Required or optional:** required locks Next until the first threshold (sets of 30 or fewer: every matchup). Optional can be skipped, and partial work still counts.
5. **What respondents see:** a small strip of the bar's stages (red → yellow → yellow-green → green → dark green) with each stage's message (§6).
6. **Tip:** keep it to 30 options or fewer when you can. Past that, each respondent covers a small fraction of the matchups and the ranking needs more respondents to settle.

**Palette entry.** `pairwise: { label: 'Pairwise', icon: 'fa-code-compare', hint: 'Pick the better of two, many times.' }`. The builder canvas preview renders one sample matchup (the first two options) and an empty bar; clicks there record nothing.

### 6. Runner

**Matchup stage.** A card region with two large option buttons (left, right) and a smaller **Tie** button between them; at ≤480px they stack as left / Tie / right. Buttons are real `<button>`s with ≥44px targets. Picking one gives it a brief selected state, then the next matchup replaces it. The transition is skipped under `prefers-reduced-motion`. Keyboard, only while focus is inside the card: ← picks left, → picks right, ↓ ties. An **Undo** link (hidden until there is something to undo) removes the last judged entry and puts that pair back at the front of the queue. It can be pressed repeatedly.

**Progress.** Below the stage: a bar, a counter ("12 of 66 matchups") and one `aria-live="polite"` message line. The bar is a `role="progressbar"` with `aria-valuenow/max`.

*Small set (possible ≤ 30):* a plain bar whose fill color follows completion: red below a third, yellow from a third, light green from two thirds, dark green at 100% (integer math: `3k ≥ M`, `3k ≥ 2M`). No tier messages. A required question shows "Finish all {possible} matchups to continue." until done.

*Larger set:* tick marks at the four tier counts. The fill color and message follow the highest tier reached:

| Reached | Fill | Message |
|---|---|---|
| below tier 1, required | red | "{gate − done} more matchups to go before you can continue." |
| below tier 1, optional | red | "Every matchup helps. Do as many as you like." |
| tier 1 | yellow | "This is a great start. You can move on, but you can make our survey better by doing a few more matchups!" |
| tier 2 | yellow-green | "Even better! You can keep going for better results or continue." |
| tier 3 | green | "Awesome! This is a great sample. Feel free to keep ranking or continue on." |
| tier 4 | dark green | "Fantastic! You've given us a great sample size, so you can keep going or continue on. Your choice!" |

*100% (either size):* the stage gives way to "Whoa, you ranked them all! Incredible job, we thank you!" with the full dark-green bar. Undo stays available.

Colors are tokens `--sv-pw-0` (red), `--sv-pw-1` (yellow), `--sv-pw-2` (yellow-green / light green), `--sv-pw-3` (green), `--sv-pw-4` (dark green), with dark-mode values under the survey dark selector. Stage is carried by text and tick marks as well as color. The small-set bar uses `--sv-pw-0/1/2/4`.

**Read / write.** `SvRender.read()` returns the entry list (`[]` when untouched). `write()` restores it and rebuilds the queue (§4). Every pick marks the answer dirty, so the existing mirror/draft autosave covers it.

**Required check on Next.** The runner's client check uses the plan's `gate` and shows the inline required error with the §3 wording. Submit re-checks server-side.

**Show-if / cross-tab / retype.** Pairwise is not a show-if source, not a cross-tab source and not a cross-tab target (it follows ranking, which is neither). Retype works between pairwise and the other choice-option types through the existing `retypeQuestion()`.

### 7. Reporting

**Aggregate.** `SurveyReport::aggregateType('pairwise', …)` dispatches to a pure `aggPairwise($rows, $options)`:

```
for each row: left = option_id, right = row_option_id, p = value_num
  appearances[left]++, appearances[right]++
  points[left] += p, points[right] += 1 − p
  p == 1 → wins[left]++,  losses[right]++
  p == 0 → wins[right]++, losses[left]++
  else   → ties[left]++,  ties[right]++
per respondent: count[response_id]++

returns {
  n          : respondents with ≥ 1 row,
  possible   : pairwisePlan(choice option count).possible,
  judged     : total rows,
  avg_count  : judged / n            (null when n = 0),
  avg_pct    : mean over respondents of count / possible × 100, 1 dp (null when n = 0),
  options    : [ { option_id, label, appearances, wins, ties, losses, points,
                   win_pct (points / appearances × 100, 1 dp; null when 0 appearances),
                   rank (competition rank by win_pct: 1, 2, 2, 4; null when unranked) } ]
               sorted by win_pct desc, then appearances desc, then label;
               never-matched options last
}
```

Rows naming an option that no longer exists are ignored (defensive; the structure lock prevents it). MIN_CELL suppression, report filters and test-row exclusion apply unchanged because they sit around `aggregateType()`.

**Results card** (`survey-results.js`):
- **Respondents** is the card's existing "n = 42 of 60" badge. Under the chart, the existing callout row (`calloutsFor`) shows **Possible matchups**, **Average % of matchups** ("45%"), **Avg per respondent** ("30 of 66") and **Matchups judged**.
- A horizontal Highcharts bar chart (`specPairwise`) of win % in rank order, 0–100 axis, data labels "62.5%", tooltip with W / T / L and appearances. Tall variant (`svr-chart-tall`) like ranking and matrix.
- A DataTable: Rank · Option · Win % · W · T · L · Matchups, default order Rank ascending, never-matched rows showing "—" and sorting last (`data-order`).
- The type label map adds `pairwise: 'Pairwise'`.
- Shared/rolldown viewers get the same tiles, chart and table; they are all aggregates.

**CSV and individual response.** The per-type cell formatter adds a `pairwise` case: "{k} of {possible}: " followed by each entry in answer order, winner first: "Hawk > Owl", ties "Wolf = Bear", joined with "; ". An untouched optional question is an empty cell. The CSV formula-injection guard applies as it does for every cell.

### 8. Seed, docs, release note

- `bin/seed-survey-example.php` adds a pairwise question ("Which event should the kingdom add next?", 8 options, optional) and gives each seeded response a random 0–100% of its matchups with a weighted preference, so the demo ranking is non-trivial.
- `docs/survey-guide.md`: a Pairwise row in the types table and a "Pairwise questions" section (what it is, bands table, gate, tips). The builder's in-app help (`SurveyAjax/help`) renders this file, so it is the in-app doc too.
- `orkui/whats_new_content.php`: the existing Survey release entry lists the new type (the module is unreleased, so no new version entry).

### 9. Testing

- **`tests/Unit/SurveyTypesTest.php`:** `pairwisePlan` at n = 0, 1, 2, 3, 8, 9, 15, 16, 20, 21, 25, 26, 30 (every band boundary: 28/36, 105/120, 190/210, 300/325); catalog membership (answerable, not a show-if source, choice role, min 3, seeds 3); `validatePairwise`: valid list, tie, unknown option, `a == b`, bad `w`, repeated pair in both orders, required below the gate, required at exactly the gate, small-set required needing all, optional empty.
- **`tests/Unit/SurveyAggregateTest.php`:** `aggPairwise` win % with ties, shared competition ranks, sort tie-breaks, a never-matched option, `avg_pct` over respondents with different counts, n = 0.
- **`tests/Unit/SurveyPairwisePlanScriptTest.php` + `tests/Unit/js/survey-pairwise-harness.js`:** runs `SvRender.pairwisePlan` under node for n = 0…60 against `SurveyTypes::pairwisePlan`; also asserts the queue builder yields every pair exactly once, excludes already-answered pairs in either order, and never repeats an option back to back when the pair set allows it.
- **`tests/Integration/SurveyTest.php`:** create a pairwise question, `option_set` with a duplicate label is refused, submit a required pairwise below the gate is refused and at the gate succeeds, stored rows match §1, the respondent definition carries `pairwise`, retype single-with-Other → pairwise clears `is_other` (drafts store answers as opaque JSON, so resume is checked in the browser pass).
- **Browser (serial, headless Playwright per the module's verification gotchas):** builder paste of 20 lines, readout, >30 warning, (?) modal content with the right highlighted band; runner at 1280 and 360 in light and dark: pick / tie / undo / keys, bar colors and every message, the required gate on Next, resume from draft, 100% state; results tiles, chart and DataTable.

### 10. Acceptance criteria

1. An author can add a pairwise question, paste a list of options one per line, see the matchup count and gate, and open a (?) explanation whose numbers match the question.
2. Above 30 options the builder warns; it never blocks.
3. Two respondents taking the same survey get different matchup orders and sides; neither sees the same pair twice.
4. The progress bar and messages follow the §6 tables exactly for small sets and for every band.
5. A required pairwise question cannot be passed or submitted below its gate; an optional one can be skipped and partial answers are kept.
6. Results show possible matchups, average % of matchups, respondents, matchups judged, and a win % ranking (chart + DataTable) with ties counted as ½.
7. CSV and the individual-response panel show each respondent's matchups.
8. All existing Survey tests still pass; the new tests pass; `php -l` and `node --check` are clean; the layering grep stays clean; light and dark modes and 360px width verified.

### 11. Out of scope

- A "skip / don't know" button on a matchup.
- Coverage balancing across respondents (P2 says pure random).
- Elo, Bradley–Terry or other model-based scores; win % is the ranking.
- Pairwise in cross-tab (as a source or a target), matching ranking today.
- Images on options (no survey type has them).

### 12. Risks and gotchas

- **Answer volume.** A respondent who finishes 435 matchups writes 435 answer rows in the submit transaction and a draft JSON of roughly 15 KB. Both are well inside current limits; the aggregate is linear in rows.
- **`row_option_id` is not only a matrix row any more.** Anything that reads it must branch on type. Today only `aggMatrix` and the matrix CSV case read it.
- **Locked relabel by position.** If an author on a locked survey swaps two lines' text, the ids stay put and the labels swap. That is the same rule every other type follows (labels only while locked), but it would mislabel results, so the textarea's lock hint says "Wording fixes only, while the survey is open."
- **The plan lives in PHP.** The builder's JS port is guarded by the parity test; the runner uses the server-sent plan, so a drift can only mislead the builder readout, never the gate.
