# Survey: Update My Answers + large pairwise bands — design

Status: approved 2026-11-22. Small change (short design).

## Intent

A live survey has a very large pairwise question. Respondents should be able to do
some matchups, submit, and come back later to add more or change answers, while the
survey is open. Very large pairwise sets also need lower encouragement thresholds,
because 10% of thousands of matchups is an unreasonable first goal.

## Part 1: Update My Answers

**Setting.** New per-survey toggle `allow_edit` (`AllowEdit`), labelled "Allow respondents
to change their answers before the survey closes", in the builder's configuration
options next to "Let respondents resume a part-finished survey". Default off. It is a
setting, not structure, so it can change on an open survey.

**Behaviour.** When `allow_edit` is on and the survey is eligible-open (status open,
`close_at` not passed), a player who already participated is no longer "completed".
The runner loads their submitted answers, they change them, and resubmit. The same
response row is updated in place (answers replaced inside one transaction). When
the survey is closed, or `allow_edit` is off, the existing "you already completed this"
message applies.

**Finding the response (approach A, chosen).** A new nullable column
`ork_survey_response.edit_key CHAR(64)` holds `HMAC-SHA256(secret, "survey-edit:{survey_id}:{mundane_id}")`,
written at submit only when `allow_edit` is on (and for non-test rows). The secret is
a new config constant `SURVEY_EDIT_SECRET`; when it is undefined, it is derived from
`DB_PASSWORD` so no deploy-time config change is required (builder's call). The
column is never selected by results, export, rows, or any manager-facing path. It
is cleared (set NULL) when the survey closes: on a status change to closed, and
lazily for date-based closes (a sweep of `close_at <= NOW()` surveys run from the
results/export and respondent read paths). It is also cleared if `allow_edit` is
switched off.

The help text for the toggle tells builders that while editing is open, an operator
with database access and the server secret could link an anonymous response to its
player, and that the link is wiped at close.

**Rules kept.**
- Consent level stays what it was at first submit; an edit does not change it, and
  the consent screen is skipped on edit. Profile-link scrubbing rules apply unchanged.
- `response_count` and bonus credit are not touched by an edit (no double counting,
  no second grant).
- `submitted_at` / `duration_seconds` keep their first-submit values for every
  consent level: no new timing signal, and bonus credit judges coverage and dates
  the grant from `submitted_at`, so an edit must not move it (changed after review;
  first drafted as "full consent may update to the edit time").
- Drafts: an edit in progress autosaves to `ork_survey_draft` like a first attempt when
  `allow_resume` is on; the draft is deleted on resubmit.
- Survey cards show "Update my answers" instead of "Take survey" when the player has
  an editable response.

**Pre-existing responses** submitted before `allow_edit` was turned on have no
`edit_key`, so they cannot be edited (the link was never stored). The toggle's help
text says this.

## Part 2: Pairwise bands for very large sets

Extend `SurveyTypes::PAIRWISE_BANDS` (and its JS mirror `PW_BANDS`, pinned by
`SurveyPairwisePlanScriptTest`). Percentages move to hundredths of a percent so 0.5 and
0.25 are exact; tier counts stay integer ceilings.

| Matchups | Tiers (%) |
|---|---|
| ≤ 105 | 30, 40, 50, 60 (unchanged) |
| ≤ 200 | 20, 30, 40, 50 (unchanged) |
| ≤ 300 | 10, 20, 30, 40 (unchanged) |
| ≤ 500 | 10, 15, 20, 25 (unchanged) |
| ≤ 1000 | 5, 10, 15, 20 |
| ≤ 2000 | 2, 4, 6, 8 |
| ≤ 5000 | 1, 2, 3, 4 |
| ≤ 10000 | 0.5, 1, 1.5, 2 |
| > 10000 | 0.25, 0.5, 0.75, 1 |

"Over 500" means more than 500 matchups. Plans are computed at read time from option
count, so a live survey picks up the new bands on deploy. Gates only go down, so no
saved progress becomes invalid. Anywhere the builder shows band percentages must
format decimals.

## Approaches considered (Part 1)

- **A. Hidden server-keyed link, wiped at close (chosen).** Edits work for every
  consent level. While open, someone with the DB plus the secret could re-link.
- **B. Hold submissions privately until close.** Never linkable, but results are
  empty while open and a date-based close needs a background publisher.
- **C. Named (full-consent) responses only.** Simplest, but with the data gate off
  every response is anonymous, so nobody could edit.

## Non-goals

- Editing after close. Editing responses submitted before the toggle was on.
- Changing consent level on edit.
- Any manager-visible indication of which responses were edited.
