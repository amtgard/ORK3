-- Round-Robin first-place tiebreaker support
-- Sticky flag set when organizer declines a tiebreaker; prevents the banner
-- from re-appearing on every page load. Tiebreaker matches themselves live
-- in ork_match with bracket_side='tiebreaker' and round = max(regular)+N.

ALTER TABLE ork_bracket
  ADD COLUMN IF NOT EXISTS tiebreaker_declined TINYINT(1) NOT NULL DEFAULT 0;
