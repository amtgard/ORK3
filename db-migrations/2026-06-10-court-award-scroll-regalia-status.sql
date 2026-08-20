-- Bugfix: scroll_status / regalia_status columns are READ by
-- class.Court::getCourtAwards() (SELECT ca.scroll_status, ca.regalia_status)
-- and WRITTEN by class.Court::updateAwardTrackingStatus(), but no migration ever
-- created them. On any database that has only the prior court migrations applied,
-- the planner's award-list query fatals with "Unknown column 'ca.scroll_status'".
--
-- Tracking state cycles 0 (not started) -> 1 (in progress) -> 2 (ready).
ALTER TABLE ork_court_award
    ADD COLUMN scroll_status  TINYINT NOT NULL DEFAULT 0 AFTER scroll_maker_id,
    ADD COLUMN regalia_status TINYINT NOT NULL DEFAULT 0 AFTER regalia_maker_id;
