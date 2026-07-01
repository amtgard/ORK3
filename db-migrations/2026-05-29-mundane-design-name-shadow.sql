-- Standalone name-shadow toggle for the persona name in the hero header.
-- Previously this effect was only applied automatically when an AmtPride
-- gradient was active; this column lets any player enable it independently,
-- e.g. to improve legibility over a custom banner image.
ALTER TABLE `ork_mundane_design`
    ADD COLUMN `name_shadow` TINYINT(1) NOT NULL DEFAULT 0 AFTER `name_font`;
