-- Make ork_kingdomaward.is_title / title_class the authoritative per-kingdom value.
-- Historically GetAwardList read ifnull(a.is_title, ka.is_title), so the global award's
-- value always won and a kingdom could never override (e.g. mark "Master" a title locally).
-- The read now uses ka.is_title directly; backfill existing rows from the linked system
-- award so the displayed values are unchanged on deploy, then per-kingdom edits stick.
UPDATE ork_kingdomaward ka
  JOIN ork_award a ON a.award_id = ka.award_id
  SET ka.is_title = a.is_title,
      ka.title_class = a.title_class;
