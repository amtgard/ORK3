ALTER TABLE ork_event_schedule
    ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT 'Other' AFTER description;
