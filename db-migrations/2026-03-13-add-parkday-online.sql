ALTER TABLE ork_parkday
    ADD COLUMN `online` tinyint(1) NOT NULL DEFAULT 0 AFTER `alternate_location`;
