-- Park day 'every-x-weeks' recurrence mode + anchor.
-- Consolidated migration (Rose-cycle, fresh-deploy edition):
--   * MODIFY recurrence enum to add 'every-x-weeks'
--   * + start_date  (first occurrence — '1000-01-01' sentinel for unused)
--   * + week_interval (2, 3, or 4 weeks)
ALTER TABLE `ork_parkday`
  MODIFY `recurrence`
    enum('weekly','monthly','week-of-month','every-x-weeks')
    NOT NULL DEFAULT 'weekly',
  ADD COLUMN `start_date`    DATE NOT NULL DEFAULT '1000-01-01',
  ADD COLUMN `week_interval` INT  NOT NULL DEFAULT 0;
