-- Add 'forfeit' and 'disqualified' to ork_match.result ENUM.
-- The PHP layer (class.Tournament.php::PostMatchResult and resolveWinnerLoser)
-- writes these short forms; the existing long forms (1-forfeits, 2-forfeits,
-- 1-is-disqualified, 2-is-disqualified) are preserved for backwards compat
-- with any pre-existing rows.
ALTER TABLE ork_match
  MODIFY result ENUM(
    '1-wins','2-wins','tie',
    'forfeit','disqualified',
    '1-forfeits','2-forfeits','1-is-disqualified','2-is-disqualified',
    '1-is-bye','2-is-bye','score'
  ) NULL DEFAULT NULL;
