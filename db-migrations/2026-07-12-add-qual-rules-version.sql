-- Stamp the rules/corpora version onto each pass and each attempt.
--
-- ork_qual_config.rules_version already labels the CURRENT test edition (shown to
-- players on the intro, e.g. "Amtgard Rules of Play v8.7" or "Corpora 5.1 2026"),
-- but it was never recorded on the result/attempt — so a qualification carried no
-- record of WHICH rules it was earned against. When the rules change and the
-- question bank is updated, there was no way to tell an old-edition pass from a
-- current one. These columns capture the config's rules_version at write time.
--
-- Stored as a free-text snapshot (not a FK) so it stays truthful even if the
-- config's label is later changed. Existing rows backfill to '' (unknown edition).

ALTER TABLE `ork_qual_result`
    ADD COLUMN IF NOT EXISTS `rules_version` VARCHAR(100) NOT NULL DEFAULT '' AFTER `score_percent`;

ALTER TABLE `ork_qual_attempt`
    ADD COLUMN IF NOT EXISTS `rules_version` VARCHAR(100) NOT NULL DEFAULT '' AFTER `pass_percent`;
