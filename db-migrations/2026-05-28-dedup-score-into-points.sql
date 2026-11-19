-- Dedup: collapse the 'score' bracket method into 'points'. Conceptually identical
-- (per-round scored format), so the UI now only exposes 'points'. Existing score
-- brackets (if any) become points brackets; enum value 'score' is dropped.
--
-- ORDERING: must run AFTER 2026-05-28-add-points-bracket.sql (which adds 'points'
-- to the enum). The filenames are ordered so 'add-points-bracket' sorts first.
--
-- SELF-GUARD (#45): do NOT depend on filename lexical order alone. If a re-apply or
-- partial replay runs these data steps while 'points' is not yet a valid enum member,
-- an UPDATE ... SET method='points' would silently store '' (sql_mode off). Gate both
-- data UPDATEs on 'points' actually being present in the column's enum definition.
SET @has_points := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'ork_bracket'
    AND column_name = 'method'
    AND COLUMN_TYPE LIKE "%'points'%"
);

-- Collapse 'score' brackets into 'points' (only when the target enum member exists).
SET @sql := IF(@has_points > 0,
  'UPDATE ork_bracket SET method = ''points'' WHERE method = ''score''',
  'SELECT ''skip: points enum member not present yet'' AS note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Repair (#44): a previous run of this pair in the WRONG order (dedup before points-bracket)
-- set method='' on 'score' rows, because 'points' was not yet a valid enum value and
-- sql_mode is off. Only rows that plausibly WERE score brackets carry per-round scores in
-- ork_point_score, so scope the repair to method='' rows that actually have point_score
-- data — never blanket-convert every empty method (an empty method may be a genuinely
-- unclassified/new bracket). Runs before the enum MODIFY so '' is still a legal value.
SET @sql := IF(@has_points > 0,
  'UPDATE ork_bracket b SET b.method = ''points''
     WHERE b.method = '''' AND b.bracket_id IN (SELECT DISTINCT bracket_id FROM ork_point_score)',
  'SELECT ''skip: points enum member not present yet'' AS note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE ork_bracket
  MODIFY method enum('single','double','swiss','round-robin','ironman','points')
    NOT NULL DEFAULT 'single';
