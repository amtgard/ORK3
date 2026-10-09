-- Fix ork_match.score column: was DOUBLE(12,4), which truncates "2-1" to 2.
-- Change to VARCHAR(20) to store scores like "2-1", "3-2", etc.
ALTER TABLE ork_match MODIFY COLUMN score VARCHAR(20) NULL DEFAULT NULL;
