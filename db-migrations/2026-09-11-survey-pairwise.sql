-- Survey module: the pairwise comparison question type.
-- Spec: docs/superpowers/specs/2026-09-11-survey-pairwise-design.md §1.
-- Idempotent: re-running the MODIFY with the same list is a no-op.
-- Answers need no schema change: one ork_survey_answer row per matchup
-- (option_id = left, row_option_id = right, value_num = left's points).
-- After applying: docker restart ork3-php8-app (APCu schema cache).

ALTER TABLE ork_survey_question
  MODIFY COLUMN type ENUM('single','multi','dropdown','yesno','rating','nps','matrix','ranking','pairwise',
                          'short_text','paragraph','number','date','section','image') NOT NULL;
