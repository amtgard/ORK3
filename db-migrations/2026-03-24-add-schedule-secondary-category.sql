ALTER TABLE ork_event_schedule
    ADD COLUMN secondary_category VARCHAR(50) NOT NULL DEFAULT '' AFTER category;
