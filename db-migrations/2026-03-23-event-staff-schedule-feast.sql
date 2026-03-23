ALTER TABLE ork_event_staff
  ADD COLUMN can_schedule TINYINT NOT NULL DEFAULT 0 AFTER can_attendance,
  ADD COLUMN can_feast    TINYINT NOT NULL DEFAULT 0 AFTER can_schedule;
