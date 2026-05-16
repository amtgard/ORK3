-- Park profile customization: 1:1 design fields table mirroring the
-- ork_mundane_design pattern. Always-present row per park (backfilled,
-- and seeded on park creation in the SetPark create path).
--
-- Field set is the subset of player design that makes sense for an org:
--   * about_text + our_history  (Markdown — replaces player about_persona/about_story split;
--                                 our_history replaces the per-character "About <Persona>" block)
--   * color_primary / color_accent / color_secondary  (hero color, gradient stop, accent for tabs/links)
--   * hero_overlay  ('low' | 'med' | 'high' | 'vignette')
--   * name_font  (Google-fonts key for the park name in the hero — same font set as Player)
--   * milestone_config  (JSON — visibility toggles + compact/newest-first settings)
--
-- Skipped from player: name_prefix/suffix/comma, pronunciation_guide (per spec ask),
-- photo_focus_*, show_beltline, show_mundane_*, show_email — none apply to a park.
--
-- Engine note: ork_park is MyISAM in legacy state. To support the FK below
-- (and match the InnoDB conversion already done on ork_mundane for the player
-- design tables), convert ork_park to InnoDB first. ork_park is ~1k rows with
-- no FULLTEXT indexes, so the conversion is fast and safe. Idempotent: no-op
-- when already InnoDB so reruns are safe.

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

CREATE TABLE IF NOT EXISTS ork_park_design (
    park_id           int(11)            NOT NULL PRIMARY KEY,
    about_text        text               NULL,
    our_history       text               NULL,
    color_primary     varchar(7)         NULL,
    color_accent      varchar(7)         NULL,
    color_secondary   varchar(7)         NULL,
    hero_overlay      varchar(10)        NOT NULL DEFAULT 'med',
    name_font         varchar(100)       NULL,
    milestone_config  text               NULL,
    CONSTRAINT fk_park_design_park
        FOREIGN KEY (park_id) REFERENCES ork_park (park_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: every existing park gets a default-valued row so reads can
-- assume the row exists (no PHP-side fallback maze).
INSERT IGNORE INTO ork_park_design (park_id)
SELECT park_id FROM ork_park;

-- Preserve legacy About content: copy ork_park.description into about_text
-- so the new editable About field starts populated with whatever was already
-- visible. about_text becomes the source of truth going forward; the legacy
-- description column is still updated by the existing Edit Details modal but
-- the public profile reads from about_text.
UPDATE ork_park_design d
JOIN ork_park p ON d.park_id = p.park_id
SET d.about_text = p.description
WHERE (d.about_text IS NULL OR d.about_text = '')
  AND p.description IS NOT NULL
  AND p.description <> ''
  AND TRIM(p.description) <> '';
