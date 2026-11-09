-- ORK3 — "How did you find us?" recruitment source on the player record
-- =============================================================================
-- Kingdom and park officers want to know where new players come from so they
-- can put recruiting effort where it pays off. Asked at registration (officer
-- create-player modals, self-registration) and backfillable afterwards from
-- the player's Account modal. Read by the Player Recruitment Report.
--
-- Stored as a short code, not the label, so the wording can change without
-- rewriting history. The code list lives in RecruitmentSource::CODES
-- (system/lib/ork3/class.RecruitmentSource.php):
--   friend_family, cross_larp, search_website, social_media,
--   walk_up, convention_demo, flyer, other
-- '' = not answered (distinct from 'other', which is "Other / prefer not to say").
-- NOT NULL DEFAULT '' because yapo drops null assignments.
--
-- Idempotent: ADD COLUMN / INDEX IF NOT EXISTS; safe to re-run.
-- After applying: docker restart ork3-php8-app (APCu schema cache).

ALTER TABLE ork_mundane
  ADD COLUMN IF NOT EXISTS recruitment_source VARCHAR(32) NOT NULL DEFAULT '';

CREATE INDEX IF NOT EXISTS idx_mundane_recruitment_source ON ork_mundane (recruitment_source);
