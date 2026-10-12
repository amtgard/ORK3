-- ============================================================================
-- F026 — strip credentials out of the existing ork_danger_audit rows.
--
-- Dangeraudit::audit() now scrubs Token / Password / app secrets / salts before
-- writing, so no NEW row can carry a credential. This cleans up what is already
-- there. At time of writing production held:
--
--     642,551 rows with a live session "Token" verbatim
--     180,792 rows with a "Password"
--
-- The values are stored inside a JSON string in `parameters`, so the rewrite is
-- a targeted REGEXP_REPLACE of the VALUE only — the key, the surrounding JSON
-- structure and every other field are preserved, and the column stays valid
-- JSON. Requires MariaDB 10.0.5+ / MySQL 8+ for REGEXP_REPLACE (production runs
-- MariaDB 12.x).
--
-- Idempotent: safe to re-run. Already-redacted rows no longer match the
-- pattern, and the WHERE clauses skip them.
--
-- Note on JSON validity: after running this locally, 30 of 642,551 rows report
-- JSON_VALID = 0. They were already invalid before the scrub -- substituting a
-- dummy token back in still yields JSON_VALID = 0, so the rewrite did not break
-- them (it only ever replaces the contents of one quoted string with the literal
-- [redacted], which cannot invalidate JSON). They are pre-existing malformed
-- rows, presumably from json_encode() on invalid UTF-8 in a note field, and are
-- out of scope here.
--
-- NOT RUN AUTOMATICALLY. This rewrites ~800k rows in the audit table. Take a
-- backup, then run it in a maintenance window:
--
--     docker exec -i ork3-php8-db mariadb -u root -proot ork \
--         < db-migrations/2026-08-07-scrub-audit-credentials.sql
--
-- Verify afterwards with the SELECTs at the bottom — both should return 0.
-- ============================================================================

-- Session tokens. Matches "Token":"<anything but a quote>" in any casing of the
-- key that the codebase actually emits ("Token").
UPDATE `ork_danger_audit`
SET `parameters` = REGEXP_REPLACE(`parameters`, '"Token":"[^"]*"', '"Token":"[redacted]"')
WHERE `parameters` REGEXP '"Token":"[^"]*"'
  AND `parameters` NOT LIKE '%"Token":"[redacted]"%';

-- Passwords, including the confirm/new/old variants the forms submit.
UPDATE `ork_danger_audit`
SET `parameters` = REGEXP_REPLACE(`parameters`, '"Password":"[^"]*"', '"Password":"[redacted]"')
WHERE `parameters` REGEXP '"Password":"[^"]*"'
  AND `parameters` NOT LIKE '%"Password":"[redacted]"%';

UPDATE `ork_danger_audit`
SET `parameters` = REGEXP_REPLACE(`parameters`, '"(PasswordConfirm|ConfirmPassword|NewPassword|OldPassword|CurrentPassword)":"[^"]*"', '"\\1":"[redacted]"')
WHERE `parameters` REGEXP '"(PasswordConfirm|ConfirmPassword|NewPassword|OldPassword|CurrentPassword)":"[^"]*"';

-- The same keys can appear in the state snapshots.
UPDATE `ork_danger_audit`
SET `prior_state` = REGEXP_REPLACE(`prior_state`, '"(Token|Password|password_salt)":"[^"]*"', '"\\1":"[redacted]"')
WHERE `prior_state` REGEXP '"(Token|Password|password_salt)":"[^"]*"';

UPDATE `ork_danger_audit`
SET `post_state` = REGEXP_REPLACE(`post_state`, '"(Token|Password|password_salt)":"[^"]*"', '"\\1":"[redacted]"')
WHERE `post_state` REGEXP '"(Token|Password|password_salt)":"[^"]*"';

-- ---------------------------------------------------------------------------
-- Verification — both counts must be 0 when this has done its job.
-- ---------------------------------------------------------------------------
-- SELECT COUNT(*) AS tokens_remaining    FROM ork_danger_audit
--   WHERE parameters REGEXP '"Token":"[^"]*"' AND parameters NOT LIKE '%[redacted]%';
-- SELECT COUNT(*) AS passwords_remaining FROM ork_danger_audit
--   WHERE parameters REGEXP '"Password":"[^"]*"' AND parameters NOT LIKE '%[redacted]%';
