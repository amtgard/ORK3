-- Unit profile customization: 1:1 design fields table mirroring ork_park_design.
-- Always-present row per unit (backfilled, and seeded on unit creation in the
-- CreateUnit path).
--
-- Field set matches Park: about_text + our_history (Markdown), color_primary /
-- color_accent / color_secondary, hero_overlay, name_font, milestone_config (JSON).
--
-- Engine note: ork_unit is MyISAM in legacy state. Convert to InnoDB first so
-- the FK below succeeds. Idempotent: no-op when already InnoDB.

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

CREATE TABLE IF NOT EXISTS ork_unit_design (
    unit_id           int(11)            NOT NULL PRIMARY KEY,
    about_text        text               NULL,
    our_history       text               NULL,
    color_primary     varchar(7)         NULL,
    color_accent      varchar(7)         NULL,
    color_secondary   varchar(7)         NULL,
    hero_overlay      varchar(10)        NOT NULL DEFAULT 'med',
    name_font         varchar(100)       NULL,
    milestone_config  text               NULL,
    CONSTRAINT fk_unit_design_unit
        FOREIGN KEY (unit_id) REFERENCES ork_unit (unit_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: every existing unit gets a default-valued row so reads can
-- assume the row exists (no PHP-side fallback maze).
INSERT IGNORE INTO ork_unit_design (unit_id)
SELECT unit_id FROM ork_unit;

-- Preserve legacy About content: copy ork_unit.description into about_text
-- so the new editable About field starts populated.
UPDATE ork_unit_design d
JOIN ork_unit u ON d.unit_id = u.unit_id
SET d.about_text = u.description
WHERE (d.about_text IS NULL OR d.about_text = '')
  AND u.description IS NOT NULL
  AND u.description <> ''
  AND TRIM(u.description) <> '';

-- Unit-specific: ork_unit has a native history column — backfill our_history
-- from it so the legacy History section migrates into the new design field.
UPDATE ork_unit_design d
JOIN ork_unit u ON d.unit_id = u.unit_id
SET d.our_history = u.history
WHERE (d.our_history IS NULL OR d.our_history = '')
  AND u.history IS NOT NULL
  AND u.history <> ''
  AND TRIM(u.history) <> '';
