-- Court Planner notes: non-award line items on a court's running order
-- ("autocrat announcements", "officer changeover"). A separate table from
-- ork_court_award ON PURPOSE: every court_award row is a candidate for the
-- stage/finalize pipeline that writes the permanent player-award record, so a
-- note stored there would be one missed filter away from being granted as an
-- award. sort_order shares one number space with ork_court_award.sort_order —
-- the two tables together are the running order. row_version is bumped on every
-- write so the planner heartbeat can tell a note changed.
CREATE TABLE IF NOT EXISTS ork_court_note (
    court_note_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    court_id       INT UNSIGNED NOT NULL,
    title          VARCHAR(150) NOT NULL DEFAULT '',
    details        TEXT,
    sort_order     INT NOT NULL DEFAULT 0,
    row_version    INT NOT NULL DEFAULT 0,
    created_by     INT UNSIGNED NOT NULL DEFAULT 0,
    modified       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (court_note_id),
    KEY idx_court (court_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
