-- Phase 2 org customizations: tagline, social links, announcement banner
-- (all 3 orgs), plus unit recruitment+how-to-join and kingdom reign banner.
--
-- All columns added to the *_design tables (system-of-record stays the
-- main org table; presentational/cosmetic settings live in design).
--
-- social_links: JSON object keyed by platform slug. Empty/missing keys
-- are not rendered. Recognized slugs (frontend convention):
--   discord, facebook, instagram, threads, bluesky, twitter, youtube, amtwiki
-- Storing as TEXT (utf8mb4) so platform list can grow without schema changes.
--
-- announcement_until: when NOT NULL, the announcement only renders while
-- CURDATE() <= announcement_until. NULL = renders indefinitely.

ALTER TABLE ork_park_design
    ADD COLUMN IF NOT EXISTS tagline             VARCHAR(160) NULL          AFTER name_font,
    ADD COLUMN IF NOT EXISTS social_links        TEXT         NULL          AFTER tagline,
    ADD COLUMN IF NOT EXISTS announcement        TEXT         NULL          AFTER social_links,
    ADD COLUMN IF NOT EXISTS announcement_until  DATE         NULL          AFTER announcement;

ALTER TABLE ork_kingdom_design
    ADD COLUMN IF NOT EXISTS tagline                VARCHAR(160) NULL       AFTER name_font,
    ADD COLUMN IF NOT EXISTS social_links           TEXT         NULL       AFTER tagline,
    ADD COLUMN IF NOT EXISTS announcement           TEXT         NULL       AFTER social_links,
    ADD COLUMN IF NOT EXISTS announcement_until     DATE         NULL       AFTER announcement,
    ADD COLUMN IF NOT EXISTS monarch_reign_started  DATE         NULL       AFTER announcement_until,
    ADD COLUMN IF NOT EXISTS regent_reign_started   DATE         NULL       AFTER monarch_reign_started,
    ADD COLUMN IF NOT EXISTS reign_lore             TEXT         NULL       AFTER regent_reign_started;

ALTER TABLE ork_unit_design
    ADD COLUMN IF NOT EXISTS tagline             VARCHAR(160) NULL          AFTER name_font,
    ADD COLUMN IF NOT EXISTS social_links        TEXT         NULL          AFTER tagline,
    ADD COLUMN IF NOT EXISTS announcement        TEXT         NULL          AFTER social_links,
    ADD COLUMN IF NOT EXISTS announcement_until  DATE         NULL          AFTER announcement,
    ADD COLUMN IF NOT EXISTS recruitment_status  VARCHAR(20)  NULL          AFTER announcement_until,
    ADD COLUMN IF NOT EXISTS how_to_join         TEXT         NULL          AFTER recruitment_status;
