-- Schema-only excerpt from 2026-03-29-markdown-description-columns.sql.
-- ork_kingdom.description/url already exist in ork.sql — only apply MODIFY clauses.

ALTER TABLE `ork_park`
  MODIFY COLUMN `description` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  MODIFY COLUMN `directions` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;

ALTER TABLE `ork_event_calendardetail`
  MODIFY COLUMN `description` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;

ALTER TABLE `ork_unit`
  MODIFY COLUMN `description` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  MODIFY COLUMN `history` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;
