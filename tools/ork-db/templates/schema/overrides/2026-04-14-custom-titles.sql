-- Schema-only excerpt from 2026-04-14-custom-titles.sql (Custom Title award comes from extract).

ALTER TABLE ork_awards
  ADD COLUMN alias_award_id INT(11) NULL DEFAULT NULL AFTER custom_name,
  ADD KEY idx_alias_award_id (alias_award_id);
