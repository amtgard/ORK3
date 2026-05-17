-- Calendar Enhancements Round 2:
--   * Officer-only calendar items
--   * Draft events

ALTER TABLE ork_calendar_item
    ADD COLUMN is_officer_only TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE ork_event
    ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'published',
    ADD INDEX idx_event_status (status);
