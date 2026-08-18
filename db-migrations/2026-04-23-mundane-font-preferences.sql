-- Add font-preference flags to ork_mundane — 2026-04-23
--
-- basic_fonts and dyslexia_fonts are player-controlled display preferences
-- set via the Update Account modal. UpdatePlayer writes them to ork_mundane;
-- without these columns the final mundane save silently fails (PDO
-- ERRMODE_WARNING swallows the unknown-column error), causing the entire
-- UPDATE to roll back — including park_member_since, active, and any other
-- field in the same statement.

ALTER TABLE `ork_mundane`
    ADD COLUMN `basic_fonts`    TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN `dyslexia_fonts` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0;
