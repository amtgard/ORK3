-- Qualification tests: the reporter's own words on a question report.
-- `reason` is a fixed list, so an "other" report told the test writer nothing
-- beyond "something is wrong". This holds what the reporter typed; it is
-- required for "other" and empty for the listed reasons.
-- Additive and re-runnable. MariaDB client, not mysql.
ALTER TABLE `ork_qual_report`
    ADD COLUMN IF NOT EXISTS `comment` VARCHAR(500) NOT NULL DEFAULT '' AFTER `reason`;
