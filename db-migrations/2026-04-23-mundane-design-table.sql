-- Move profile-design fields from ork_mundane to a dedicated 1:1 table.
-- 19 columns (the 3.5.2 Mask design set) move; viewer-side font flags stay.

CREATE TABLE `ork_mundane_design` (
  `mundane_id`            int(11) NOT NULL,
  `about_persona`         text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `about_story`           text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color_primary`         varchar(7)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color_accent`          varchar(7)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color_secondary`       varchar(7)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hero_overlay`          varchar(4)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'med',
  `name_prefix`           varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name_suffix`           varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suffix_comma`          tinyint(1) unsigned NOT NULL DEFAULT 0,
  `photo_focus_x`         tinyint(3) unsigned DEFAULT 50,
  `photo_focus_y`         tinyint(3) unsigned DEFAULT 50,
  `photo_focus_size`      tinyint(3) unsigned DEFAULT 100,
  `show_beltline`         tinyint(1) unsigned NOT NULL DEFAULT 1,
  `pronunciation_guide`   varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `show_mundane_first`    tinyint(1) unsigned NOT NULL DEFAULT 1,
  `show_mundane_last`     tinyint(1) unsigned NOT NULL DEFAULT 1,
  `show_email`            tinyint(1) unsigned NOT NULL DEFAULT 1,
  `milestone_config`      text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name_font`             varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`mundane_id`),
  CONSTRAINT `fk_design_mundane` FOREIGN KEY (`mundane_id`)
    REFERENCES `ork_mundane` (`mundane_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ork_mundane_design` (
  `mundane_id`, `about_persona`, `about_story`,
  `color_primary`, `color_accent`, `color_secondary`, `hero_overlay`,
  `name_prefix`, `name_suffix`, `suffix_comma`,
  `photo_focus_x`, `photo_focus_y`, `photo_focus_size`,
  `show_beltline`, `pronunciation_guide`,
  `show_mundane_first`, `show_mundane_last`, `show_email`,
  `milestone_config`, `name_font`
)
SELECT
  `mundane_id`, `about_persona`, `about_story`,
  `color_primary`, `color_accent`, `color_secondary`, `hero_overlay`,
  `name_prefix`, `name_suffix`, `suffix_comma`,
  `photo_focus_x`, `photo_focus_y`, `photo_focus_size`,
  `show_beltline`, `pronunciation_guide`,
  `show_mundane_first`, `show_mundane_last`, `show_email`,
  `milestone_config`, `name_font`
FROM `ork_mundane`;

ALTER TABLE `ork_mundane`
  DROP COLUMN `about_persona`,
  DROP COLUMN `about_story`,
  DROP COLUMN `color_primary`,
  DROP COLUMN `color_accent`,
  DROP COLUMN `color_secondary`,
  DROP COLUMN `hero_overlay`,
  DROP COLUMN `name_prefix`,
  DROP COLUMN `name_suffix`,
  DROP COLUMN `suffix_comma`,
  DROP COLUMN `photo_focus_x`,
  DROP COLUMN `photo_focus_y`,
  DROP COLUMN `photo_focus_size`,
  DROP COLUMN `show_beltline`,
  DROP COLUMN `pronunciation_guide`,
  DROP COLUMN `show_mundane_first`,
  DROP COLUMN `show_mundane_last`,
  DROP COLUMN `show_email`,
  DROP COLUMN `milestone_config`,
  DROP COLUMN `name_font`;
