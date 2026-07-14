-- Global Question Library: remember WHERE an imported question came from.
--
-- The library hid questions you already had by comparing question TEXT. Text is editable,
-- so the moment a kingdom reworded an imported question the match broke and the library
-- offered the same question back — inviting a near-duplicate of one you already hold.
-- Dedup has to key on identity, not on a mutable field.
--
-- source_question_id is the qual_question_id in the ORIGINATING kingdom. NULL for questions
-- a kingdom wrote itself. No FK: the source kingdom may archive or delete its question, and
-- that must not cascade into (or block) the copy, which is now independently owned.

ALTER TABLE `ork_qual_question`
    ADD COLUMN `source_question_id` INT(10) UNSIGNED NULL DEFAULT NULL
        COMMENT 'qual_question_id this was imported from (Global Question Library); NULL if written locally'
        AFTER `created_by`,
    ADD INDEX `idx_source_question` (`source_question_id`);

-- Backfill: link copies made before this column existed. Conservative on purpose — only
-- where the text still matches EXACTLY and exactly ONE shared question in another kingdom
-- could be the source. An ambiguous match (two kingdoms sharing identical text) is left
-- NULL rather than guessed: a wrong link would hide the wrong question from the library.
UPDATE `ork_qual_question` dest
JOIN (
    SELECT src.question_text,
           MIN(src.qual_question_id) AS src_id,
           COUNT(DISTINCT src.kingdom_id) AS kingdoms
    FROM `ork_qual_question` src
    JOIN `ork_qual_config` c
      ON c.kingdom_id = src.kingdom_id
     AND c.test_type = 'reeve'
     AND c.share_questions = 1
    WHERE src.test_type = 'reeve'
      AND src.status = 'active'
    GROUP BY src.question_text
    HAVING kingdoms = 1
) m ON m.question_text = dest.question_text
SET dest.source_question_id = m.src_id
WHERE dest.test_type = 'reeve'
  AND dest.source_question_id IS NULL
  AND dest.qual_question_id <> m.src_id
  -- only link a copy to a source in a DIFFERENT kingdom
  AND dest.kingdom_id <> (
      SELECT s2.kingdom_id FROM `ork_qual_question` s2
      WHERE s2.qual_question_id = m.src_id
  );
