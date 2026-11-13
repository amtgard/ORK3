-- Add 'finalized' to ork_bracket.status enum
-- Without this, CompleteBracket() silently stores empty string (sql_mode is off)
-- and 'finalized' status badges never render
ALTER TABLE ork_bracket
  MODIFY COLUMN status ENUM('setup','active','complete','finalized') NOT NULL DEFAULT 'setup';
