-- Add event schedule table for per-occurrence schedule items
CREATE TABLE IF NOT EXISTS ork_event_schedule (
    event_schedule_id       INT NOT NULL AUTO_INCREMENT,
    event_calendardetail_id INT NOT NULL,
    title                   VARCHAR(255) NOT NULL DEFAULT '',
    start_time              DATETIME NOT NULL,
    end_time                DATETIME NOT NULL,
    location                VARCHAR(255) NOT NULL DEFAULT '',
    description             TEXT NOT NULL DEFAULT '',
    modified                TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (event_schedule_id),
    KEY idx_event_schedule_detail (event_calendardetail_id)
);
