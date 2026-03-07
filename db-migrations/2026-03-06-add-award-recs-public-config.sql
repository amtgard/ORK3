-- Add AwardRecsPublic configuration for all existing kingdoms.
-- Controls whether the Award Recommendations tab is publicly visible (1) or
-- restricted to officers/admins only (0). Defaults to 1 (public).
-- Kingdoms that already have this key are skipped via the LEFT JOIN guard.
INSERT INTO ork_configuration (type, var_type, id, `key`, value, user_setting, allowed_values, modified)
SELECT
    'Kingdom'         AS type,
    'fixed'           AS var_type,
    k.kingdom_id      AS id,
    'AwardRecsPublic' AS `key`,
    '1'               AS value,
    1                 AS user_setting,
    'null'            AS allowed_values,
    NOW()             AS modified
FROM ork_kingdom k
LEFT JOIN ork_configuration c
    ON  c.type = 'Kingdom'
    AND c.id   = k.kingdom_id
    AND c.key  = 'AwardRecsPublic'
WHERE c.configuration_id IS NULL;
