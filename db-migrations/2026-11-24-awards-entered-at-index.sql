-- Admin panel trend row counts awards by entered_at for this year and last;
-- without an index each count is a full scan of ork_awards (~0.4s each on prod).
ALTER TABLE `ork_awards` ADD INDEX `idx_awards_entered_at` (`entered_at`);
