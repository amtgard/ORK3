-- Points bracket method: adds new method enum value, per-bracket config columns,
-- and the ork_point_score cell table.
--
-- ORDERING: this file was renamed from "2026-05-28-points-bracket.sql" to
-- "2026-05-28-add-points-bracket.sql" so it sorts BEFORE
-- "2026-05-28-dedup-score-into-points.sql". The enum value 'points' must exist
-- before dedup runs `UPDATE ... SET method='points' WHERE method='score'`; with
-- sql_mode off, updating to a not-yet-valid enum value silently stores '' and
-- corrupts the row. Keep this lexical ordering.
ALTER TABLE ork_bracket
  MODIFY method enum('single','double','swiss','round-robin','ironman','score','points')
    NOT NULL DEFAULT 'single';

ALTER TABLE ork_bracket
  ADD COLUMN IF NOT EXISTS point_rounds int(11) NULL AFTER best_of,
  ADD COLUMN IF NOT EXISTS point_mode   enum('fixed','open') NULL AFTER point_rounds,
  ADD COLUMN IF NOT EXISTS point_scale  varchar(120) NULL AFTER point_mode;

CREATE TABLE IF NOT EXISTS ork_point_score (
  point_score_id  int(11)         NOT NULL AUTO_INCREMENT,
  bracket_id      int(11)         NOT NULL,
  participant_id  int(11)         NOT NULL,
  round           int(11)         NOT NULL,
  points          decimal(8,2)        NULL,
  scored_at       datetime            NULL,
  scored_by       int(11)             NULL,
  PRIMARY KEY (point_score_id),
  UNIQUE KEY uq_cell (bracket_id, participant_id, round),
  KEY idx_bracket (bracket_id),
  KEY idx_participant (participant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
