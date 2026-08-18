-- Profile-design fields live in a dedicated 1:1 table keyed on mundane_id.
-- Rows are lazy-created by Player::UpdatePlayer when a player first saves
-- design changes; GetPlayer falls back to defaults when no row exists.

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
  `belt_display`          varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'white',
  `pronunciation_guide`   varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `show_mundane_first`    tinyint(1) unsigned NOT NULL DEFAULT 0,
  `show_mundane_last`     tinyint(1) unsigned NOT NULL DEFAULT 0,
  `show_email`            tinyint(1) unsigned NOT NULL DEFAULT 0,
  `milestone_config`      text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name_font`             varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`mundane_id`),
  CONSTRAINT `fk_design_mundane` FOREIGN KEY (`mundane_id`)
    REFERENCES `ork_mundane` (`mundane_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
