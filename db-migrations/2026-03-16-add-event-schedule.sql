-- Event schedule items — per-occurrence agenda entries.
-- Consolidated migration (Rose-cycle, fresh-deploy edition):
--   * original CREATE
--   * + category, secondary_category
--   * + feast-unify columns (menu, cost, dietary, allergens)
--   * + FK to ork_event_calendardetail ON DELETE CASCADE
-- Engine + charset explicit so the FK validates (legacy installs default
-- to MyISAM, which can't accept FK declarations).
CREATE TABLE IF NOT EXISTS ork_event_schedule (
    event_schedule_id       INT NOT NULL AUTO_INCREMENT,
    event_calendardetail_id INT NOT NULL,
    title                   VARCHAR(255) NOT NULL DEFAULT '',
    start_time              DATETIME NOT NULL,
    end_time                DATETIME NOT NULL,
    location                VARCHAR(255) NOT NULL DEFAULT '',
    description             TEXT NOT NULL DEFAULT '',
    menu                    TEXT NULL,
    cost                    DECIMAL(8,2) NULL,
    dietary                 VARCHAR(500) NULL,
    allergens               VARCHAR(500) NULL,
    category                VARCHAR(50) NOT NULL DEFAULT 'Other',
    secondary_category      VARCHAR(50) NOT NULL DEFAULT '',
    modified                TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (event_schedule_id),
    KEY idx_event_schedule_detail (event_calendardetail_id),
    CONSTRAINT fk_sched_detail
        FOREIGN KEY (event_calendardetail_id)
        REFERENCES ork_event_calendardetail (event_calendardetail_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
