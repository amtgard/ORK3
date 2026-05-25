-- Anchor date + week interval for the 'every-x-weeks' recurrence mode.
-- start_date is the first occurrence; week_interval is 2, 3, or 4.
-- '1000-01-01' is MariaDB's minimum valid DATE, used as a NOT-NULL sentinel
-- for rows that don't use this mode.
ALTER TABLE `ork_parkday`
  ADD COLUMN `start_date` DATE NOT NULL DEFAULT '1000-01-01',
  ADD COLUMN `week_interval` INT NOT NULL DEFAULT 0;
