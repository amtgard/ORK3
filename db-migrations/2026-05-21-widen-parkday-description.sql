-- Widen ork_parkday.description from varchar(50) to varchar(200).
-- The park-day edit modal (Parknew_index.tpl) allows maxlength=200, but the
-- column was varchar(50). With sql_mode empty, longer descriptions were
-- silently truncated to 50 chars on save, so edits appeared to "do nothing".
-- This was the sibling missed by 2026-03-29-markdown-description-columns.sql,
-- which widened the description columns on ork_park / ork_unit / ork_event_calendardetail.
ALTER TABLE `ork_parkday`
  MODIFY COLUMN `description` varchar(200)
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '';
