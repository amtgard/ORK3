-- Custom unit milestones, mirroring ork_park_milestones.
-- Rows are officer-authored and shown on the unit profile timeline.
-- Derived milestones (first member activity) are computed at read time and not stored.
--
-- NOTE: This migration depends on the ork_unit engine conversion performed
-- in 2026-05-17-ork-unit-design.sql. Run that migration first. As a
-- defensive guard, we re-issue the same idempotent InnoDB convert here
-- so the FK below succeeds even if the design migration was skipped.

SET @engine := (
    SELECT ENGINE FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ork_unit'
);
SET @sql := IF( @engine IS NOT NULL AND @engine <> 'InnoDB',
                'ALTER TABLE ork_unit ENGINE=InnoDB',
                'DO 0' );
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ork_unit_milestones (
    milestone_id     int(11)        NOT NULL PRIMARY KEY AUTO_INCREMENT,
    unit_id          int(11)        NOT NULL,
    icon             varchar(50)    NOT NULL DEFAULT 'fa-star',
    description      varchar(500)   NOT NULL DEFAULT '',
    milestone_date   date           NOT NULL,
    created_at       timestamp      NOT NULL DEFAULT current_timestamp(),
    updated_at       timestamp      NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    KEY idx_unit_milestone_unit_date (unit_id, milestone_date),
    CONSTRAINT fk_unit_milestone_unit
        FOREIGN KEY (unit_id) REFERENCES ork_unit (unit_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
