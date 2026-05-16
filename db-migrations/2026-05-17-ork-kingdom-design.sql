-- Kingdom profile customization: 1:1 design fields table mirroring the
-- ork_park_design pattern (which itself mirrors ork_mundane_design). One row
-- per kingdom (backfilled, and seeded on kingdom creation). Applies to both
-- Kingdoms and Principalities since both share the ork_kingdom table.
--
-- Field set is the same subset as ork_park_design (org-relevant):
--   * about_text + our_history  (Markdown)
--   * color_primary / color_accent / color_secondary  (hero color, gradient stop, accent)
--   * hero_overlay  ('low' | 'med' | 'high' | 'vignette')
--   * name_font  (Google-fonts key for the kingdom name in the hero)
--   * milestone_config  (JSON — visibility toggles + newest-first)
--
-- Engine note: ork_kingdom is MyISAM in legacy state. Convert to InnoDB so
-- the FK below succeeds. No FULLTEXT indexes exist on ork_kingdom (verified).
-- Idempotent: no-op when already InnoDB so reruns are safe.

SET @engine := (
    SELECT ENGINE FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ork_kingdom'
);
SET @sql := IF( @engine IS NOT NULL AND @engine <> 'InnoDB',
                'ALTER TABLE ork_kingdom ENGINE=InnoDB',
                'DO 0' );
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ork_kingdom_design (
    kingdom_id        int(11)            NOT NULL PRIMARY KEY,
    about_text        text               NULL,
    our_history       text               NULL,
    color_primary     varchar(7)         NULL,
    color_accent      varchar(7)         NULL,
    color_secondary   varchar(7)         NULL,
    hero_overlay      varchar(10)        NOT NULL DEFAULT 'med',
    name_font         varchar(100)       NULL,
    milestone_config  text               NULL,
    CONSTRAINT fk_kingdom_design_kingdom
        FOREIGN KEY (kingdom_id) REFERENCES ork_kingdom (kingdom_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: every existing kingdom (including principalities) gets a default-valued
-- row so reads can assume the row exists.
INSERT IGNORE INTO ork_kingdom_design (kingdom_id)
SELECT kingdom_id FROM ork_kingdom;

-- Preserve legacy About content: copy ork_kingdom.description into about_text.
UPDATE ork_kingdom_design d
JOIN ork_kingdom k ON d.kingdom_id = k.kingdom_id
SET d.about_text = k.description
WHERE (d.about_text IS NULL OR d.about_text = '')
  AND k.description IS NOT NULL
  AND k.description <> ''
  AND TRIM(k.description) <> '';
