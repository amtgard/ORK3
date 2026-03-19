-- Add event schedule leads table (many-to-many: schedule item ↔ mundane)
-- Represents the person(s) running/teaching/leading a given schedule item.
CREATE TABLE IF NOT EXISTS ork_event_schedule_lead (
  event_schedule_lead_id INT NOT NULL AUTO_INCREMENT,
  event_schedule_id INT NOT NULL,
  mundane_id INT NOT NULL,
  PRIMARY KEY (event_schedule_lead_id),
  UNIQUE KEY uq_sched_lead (event_schedule_id, mundane_id),
  KEY idx_esl_schedule (event_schedule_id),
  CONSTRAINT fk_esl_schedule FOREIGN KEY (event_schedule_id)
    REFERENCES ork_event_schedule (event_schedule_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
