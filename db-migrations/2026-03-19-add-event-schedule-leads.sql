-- Event schedule leads: many-to-many between schedule items and the
-- person(s) running/teaching/leading them.
-- Consolidated migration (Rose-cycle, fresh-deploy edition):
--   * original CREATE (FK on event_schedule_id)
--   * + cascading FK on mundane_id (B12) so mundane merges/deletes
--     cascade to lead rows instead of leaving orphaned "unknown leader"
--     entries on the schedule UI.
CREATE TABLE IF NOT EXISTS ork_event_schedule_lead (
  event_schedule_lead_id INT NOT NULL AUTO_INCREMENT,
  event_schedule_id INT NOT NULL,
  mundane_id INT NOT NULL,
  PRIMARY KEY (event_schedule_lead_id),
  UNIQUE KEY uq_sched_lead (event_schedule_id, mundane_id),
  KEY idx_esl_schedule (event_schedule_id),
  KEY idx_esl_mundane  (mundane_id),
  CONSTRAINT fk_esl_schedule FOREIGN KEY (event_schedule_id)
    REFERENCES ork_event_schedule (event_schedule_id) ON DELETE CASCADE,
  CONSTRAINT fk_sched_lead_mundane FOREIGN KEY (mundane_id)
    REFERENCES ork_mundane (mundane_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
