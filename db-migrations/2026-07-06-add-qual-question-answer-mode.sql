-- Multi-correct qualification questions.
--
-- Adds a per-question mode selector so an admin can flag a question as
-- expecting the player to pick every correct answer ('multi') rather than
-- exactly one ('single'). Defaults to 'single' so every existing question
-- keeps its current single-answer semantics without touching data.
--
-- Scoring for 'multi' is all-or-nothing: the player must select the exact
-- set of correct answer rows (no missing, no extra) to earn the mark.
ALTER TABLE `ork_qual_question`
    ADD COLUMN IF NOT EXISTS `answer_mode` ENUM('single','multi') NOT NULL DEFAULT 'single' AFTER `question_text`;
