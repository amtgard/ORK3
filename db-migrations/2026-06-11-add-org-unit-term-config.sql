-- Add OrgUnitTerm configuration for all existing principalities (2026-06-11).
-- Per-principality terminology toggle: controls the human-readable label used for
-- a sub-kingdom organizational unit. Defaults to 'principality'; a principality may
-- instead choose 'grand_duchy' (e.g. Rising Winds labels its subdivisions "Grand
-- Duchy" rather than "Principality"). Only principalities (parent_kingdom_id > 0)
-- are backfilled. Rows that already carry this key are skipped via the LEFT JOIN
-- guard, so this migration is re-runnable.
INSERT INTO ork_configuration (type, var_type, id, `key`, value, user_setting, allowed_values, modified)
SELECT
    'Kingdom'                            AS type,
    'fixed'                              AS var_type,
    k.kingdom_id                         AS id,
    'OrgUnitTerm'                        AS `key`,
    -- value is JSON-encoded: get_configs() does json_decode(value), so the stored
    -- literal must be the quoted JSON string "principality" (raw `principality`
    -- would json_decode() to NULL). allowed_values is 'null' to mirror the scalar
    -- 'fixed' stats-toggle pattern and avoid update_config()'s buggy array path.
    '"principality"'                     AS value,
    1                                    AS user_setting,
    'null'                               AS allowed_values,
    NOW()                                AS modified
FROM ork_kingdom k
LEFT JOIN ork_configuration c
    ON  c.type = 'Kingdom'
    AND c.id   = k.kingdom_id
    AND c.`key`  = 'OrgUnitTerm'
WHERE k.parent_kingdom_id > 0
    AND c.configuration_id IS NULL;
