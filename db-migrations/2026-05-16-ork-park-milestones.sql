-- Custom park milestones, mirroring ork_player_milestones.
-- Rows are officer-authored and shown on the park profile timeline.
-- Derived milestones (first attendance, attendance count crossings, etc.)
-- are computed at read time and not stored.
--
-- NOTE: This migration depends on the ork_park engine conversion performed
-- in 2026-05-16-ork-park-design.sql. Run that migration first. As a
-- defensive guard, we re-issue the same idempotent InnoDB convert here
-- so the FK below succeeds even if the design migration was skipped.

SET @engine := (
    SELECT ENGINE FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ork_park'
);
SET @sql := IF( @engine IS NOT NULL AND @engine <> 'InnoDB',
                'ALTER TABLE ork_park ENGINE=InnoDB',
                'DO 0' );
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ork_park_milestones (
    milestone_id     int(11)        NOT NULL PRIMARY KEY AUTO_INCREMENT,
    park_id          int(11)        NOT NULL,
    icon             varchar(50)    NOT NULL DEFAULT 'fa-star',
    description      varchar(500)   NOT NULL DEFAULT '',
    milestone_date   date           NOT NULL,
    created_at       timestamp      NOT NULL DEFAULT current_timestamp(),
    updated_at       timestamp      NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    KEY idx_park_milestone_park_date (park_id, milestone_date),
    CONSTRAINT fk_park_milestone_park
        FOREIGN KEY (park_id) REFERENCES ork_park (park_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
