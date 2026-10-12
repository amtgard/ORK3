-- Real-time collaboration: per-tournament change-log + monotonic cursor.
-- The log is NOT the system of record (ork_match / ork_participant are); it is a
-- replayable delta feed clients use to sync. Safe to prune after a tournament ends.

CREATE TABLE IF NOT EXISTS ork_tournament_seq (
  tournament_id INT NOT NULL,
  last_seq      BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (tournament_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ork_tournament_event (
  event_id      BIGINT NOT NULL AUTO_INCREMENT,
  tournament_id INT NOT NULL,
  bracket_id    INT NULL,
  seq           BIGINT NOT NULL,
  type          VARCHAR(32) NOT NULL,
  payload       TEXT NULL,
  actor_id      INT NULL,
  actor_name    VARCHAR(255) NULL,
  action_id     CHAR(36) NULL,
  created       DATETIME NOT NULL,
  PRIMARY KEY (event_id),
  KEY idx_trn_seq (tournament_id, seq),
  UNIQUE KEY uq_trn_action (tournament_id, action_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
