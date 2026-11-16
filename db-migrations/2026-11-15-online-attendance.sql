-- Online attendance: flag sign-ins as online and add a per-kingdom setting.
-- Existing rows stay online = 0 (no backfill). Idempotent / re-runnable.
ALTER TABLE ork_attendance ADD COLUMN IF NOT EXISTS online TINYINT(1) NOT NULL DEFAULT 0 AFTER entry_method;
ALTER TABLE ork_attendance_link ADD COLUMN IF NOT EXISTS online TINYINT(1) NOT NULL DEFAULT 0 AFTER credits;

-- CountOnlineAttendance: per-kingdom toggle for voting eligibility. Default ON.
-- Kingdoms that already have the key are skipped via the LEFT JOIN guard.
INSERT INTO ork_configuration (type, var_type, id, `key`, value, user_setting, allowed_values, modified)
SELECT
    'Kingdom'                  AS type,
    'fixed'                    AS var_type,
    k.kingdom_id               AS id,
    'CountOnlineAttendance'    AS `key`,
    '"1"'                      AS value,
    1                          AS user_setting,
    'null'                     AS allowed_values,
    NOW()                      AS modified
FROM ork_kingdom k
LEFT JOIN ork_configuration c
    ON  c.type = 'Kingdom'
    AND c.id   = k.kingdom_id
    AND c.key  = 'CountOnlineAttendance'
WHERE c.configuration_id IS NULL;

UPDATE ork_configuration SET value = '"0"' WHERE type = 'Kingdom' AND id = 20 AND `key` = 'CountOnlineAttendance';
