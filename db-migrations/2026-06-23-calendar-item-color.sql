-- Per-item color for calendar items, so kingdoms/parks can color-code phases
-- (e.g. an Althing schedule: submission / discussion / voting periods).
-- Stored as a #rrggbb hex; defaults to the existing calendar-item slate.

ALTER TABLE ork_calendar_item
    ADD COLUMN IF NOT EXISTS color VARCHAR(7) NOT NULL DEFAULT '#64748b' AFTER is_locals_only;
