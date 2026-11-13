-- Backfill for #39: relabel tiebreaker matches that were silently stored with an
-- empty bracket_side before the enum carried the tiebreaker values.
--
-- Runs AFTER 2026-04-06-bracket-side-tiebreaker-enum.sql (adds 'tiebreaker-3rd') and
-- 2026-05-19-bracket-side-rr-tiebreaker-enum.sql (adds 'tiebreaker'), so both target
-- values are valid enum members here.
--
-- Reliable signal: every insert_match() call passes bracket_side explicitly and every
-- non-tiebreaker path passes a non-empty value ('winners' / 'losers' / 'grand-final').
-- The ONLY way a row acquired bracket_side='' is CreateTiebreakerMatch / the RR
-- tiebreaker flow writing a value that was not yet a valid enum member (sql_mode off).
-- We disambiguate the two tiebreaker kinds by the parent bracket's method:
--   single / double  -> 3rd-place tiebreaker ('tiebreaker-3rd')
--   round-robin      -> first-place tiebreaker ('tiebreaker')
-- Idempotent: once relabeled there are no bracket_side='' rows left to match.
--
-- SELF-GUARD (#45): do NOT rely on filename lexical order to ensure the enum-MODIFY
-- migrations ran first. If a re-apply or partial replay runs these UPDATEs while the
-- target enum member is not yet valid, sql_mode-off would silently store '' back again.
-- Gate each UPDATE on its specific enum member actually being present in the column.
SET @has_tb3 := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'ork_match'
    AND column_name = 'bracket_side'
    AND COLUMN_TYPE LIKE "%'tiebreaker-3rd'%"
);
SET @has_tb := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'ork_match'
    AND column_name = 'bracket_side'
    AND COLUMN_TYPE LIKE "%'tiebreaker'%"
);

SET @sql := IF(@has_tb3 > 0,
  'UPDATE ork_match m
     JOIN ork_bracket b ON b.bracket_id = m.bracket_id
     SET m.bracket_side = ''tiebreaker-3rd''
     WHERE m.bracket_side = '''' AND b.method IN (''single'', ''double'')',
  'SELECT ''skip: tiebreaker-3rd enum member not present yet'' AS note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_tb > 0,
  'UPDATE ork_match m
     JOIN ork_bracket b ON b.bracket_id = m.bracket_id
     SET m.bracket_side = ''tiebreaker''
     WHERE m.bracket_side = '''' AND b.method = ''round-robin''',
  'SELECT ''skip: tiebreaker enum member not present yet'' AS note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
