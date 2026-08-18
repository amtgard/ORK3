-- Seed a "Custom Title" kingdomaward row for every kingdom, pointing at the
-- Custom Title sentinel created by the prior migration. is_title=1 so the
-- dropdown and filters recognize unaliased Custom Titles as titles.
--
-- The sentinel's award_id is resolved at runtime by name (matches the
-- application code, which also looks it up by name) so this migration is
-- portable across environments where the next-available award_id differs.

-- Heal any pre-existing "Custom Title" kingdomaward rows whose award_id does
-- not point at the real Custom Title sentinel (e.g. legacy rows with
-- award_id = 0). Re-aim them at the sentinel rather than insert duplicates.
UPDATE ork_kingdomaward
SET award_id = (SELECT award_id FROM ork_award WHERE name = 'Custom Title' AND officer_role = 'none' LIMIT 1)
WHERE name = 'Custom Title'
  AND award_id <> (SELECT award_id FROM ork_award WHERE name = 'Custom Title' AND officer_role = 'none' LIMIT 1);

INSERT INTO ork_kingdomaward (kingdom_id, award_id, name, is_title, title_class, reign_limit, month_limit)
SELECT k.kingdom_id,
       (SELECT award_id FROM ork_award WHERE name = 'Custom Title' AND officer_role = 'none' LIMIT 1),
       'Custom Title', 1, 0, 0, 0
FROM ork_kingdom k
WHERE NOT EXISTS (
    SELECT 1 FROM ork_kingdomaward ka
    WHERE ka.kingdom_id = k.kingdom_id
      AND ka.name = 'Custom Title'
);

SELECT COUNT(*) AS custom_title_kingdomawards
FROM ork_kingdomaward
WHERE name = 'Custom Title'
  AND award_id = (SELECT award_id FROM ork_award WHERE name = 'Custom Title' AND officer_role = 'none' LIMIT 1);
