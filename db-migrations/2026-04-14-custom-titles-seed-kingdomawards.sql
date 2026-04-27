-- Seed a "Custom Title" kingdomaward row for every kingdom, pointing at the
-- Custom Title sentinel (award_id=248). Mirrors the per-kingdom "Custom Award"
-- rows that already exist. is_title=1 so the dropdown and filters recognize
-- unaliased Custom Titles as titles.

INSERT INTO ork_kingdomaward (kingdom_id, award_id, name, is_title, title_class, reign_limit, month_limit)
SELECT k.kingdom_id, 248, 'Custom Title', 1, 0, 0, 0
FROM ork_kingdom k
WHERE NOT EXISTS (
    SELECT 1 FROM ork_kingdomaward ka
    WHERE ka.kingdom_id = k.kingdom_id
      AND ka.award_id = 248
      AND ka.name = 'Custom Title'
);

SELECT COUNT(*) AS custom_title_kingdomawards FROM ork_kingdomaward WHERE award_id = 248 AND name = 'Custom Title';
