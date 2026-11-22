-- ORK3 — event schedule board: category leads on event staff
-- =============================================================================
-- A staff row can be marked as "lead" for one or more schedule categories (the
-- Warcrat leads Battlegames). When that happens, every schedule item of the
-- occurrence whose primary category is that category and which has no lead yet
-- gets that person as its lead automatically. Grants no permission.
--
-- ork_event_staff_category: one row per (staff row, category). Category names
-- are the keys of EventPlanning::ScheduleCategories(). Cascades with the staff
-- row.
--
-- ork_event_schedule_lead.is_auto: 1 when the lead was filled in by a category
-- lead rather than picked by hand. Un-marking a category lead (or removing the
-- staff row) removes only that person's is_auto = 1 rows; a hand-picked lead
-- (is_auto = 0, the default for every existing row) is never removed that way.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS; safe to re-run.

CREATE TABLE IF NOT EXISTS ork_event_staff_category (
  event_staff_id INT NOT NULL,
  category       VARCHAR(50) NOT NULL,
  PRIMARY KEY (event_staff_id, category),
  CONSTRAINT fk_staff_category_staff FOREIGN KEY (event_staff_id)
    REFERENCES ork_event_staff (event_staff_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ork_event_schedule_lead
  ADD COLUMN IF NOT EXISTS is_auto TINYINT(1) NOT NULL DEFAULT 0;
