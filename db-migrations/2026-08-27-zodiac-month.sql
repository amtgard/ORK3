-- Order of the Zodiac is granted once per calendar month, so its twelve positions
-- are months, not levels. This column records the month. `rank` is left completely
-- alone: 1,774 existing Zodiac grants carry a legacy rank and none is rewritten.
--
-- 1-12 = January-December. 0 = no month recorded.
--
-- Deliberately NOT "reuse rank plus a flag" -- that gives one column two meanings
-- gated on a second column, the same split-brain the kingdom-ladder work removed
-- from is_ladder.

ALTER TABLE ork_awards
    ADD COLUMN IF NOT EXISTS zodiac_month TINYINT(2) NOT NULL DEFAULT 0;

ALTER TABLE ork_recommendations
    ADD COLUMN IF NOT EXISTS zodiac_month TINYINT(2) NOT NULL DEFAULT 0;
