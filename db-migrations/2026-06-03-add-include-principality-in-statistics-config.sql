-- Add IncludePrincipalityInStatistics configuration for all existing kingdoms.
-- Per-kingdom admin toggle: when ON (1), kingdom-scoped statistics, graphs, and
-- reports fold in data from the kingdom's active child principalities. Defaults
-- to 0 (OFF). Only surfaces in the Kingdom Administration dialog for kingdoms
-- that actually have child principalities (gated in the controller).
-- Kingdoms that already have this key are skipped via the LEFT JOIN guard, so
-- this migration is re-runnable.
INSERT INTO ork_configuration (type, var_type, id, `key`, value, user_setting, allowed_values, modified)
SELECT
    'Kingdom'                            AS type,
    'fixed'                              AS var_type,
    k.kingdom_id                         AS id,
    'IncludePrincipalityInStatistics'    AS `key`,
    '0'                                  AS value,
    1                                    AS user_setting,
    'null'                               AS allowed_values,
    NOW()                                AS modified
FROM ork_kingdom k
LEFT JOIN ork_configuration c
    ON  c.type = 'Kingdom'
    AND c.id   = k.kingdom_id
    AND c.key  = 'IncludePrincipalityInStatistics'
WHERE c.configuration_id IS NULL;
