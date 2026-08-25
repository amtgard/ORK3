-- ============================================================================
-- Anonymous sign-in tally — accumulating login-usage counts, NO attribution.
--
-- The Release Feature Utilization report wanted a "sign-ins over time" metric,
-- but ork_session rows are DELETED by logout, Log Out Everywhere, and the
-- three-session cap, so counting surviving rows silently undercounts. Login
-- is not otherwise recorded anywhere.
--
-- This table is the smallest honest fix: one row per (day, client bucket),
-- incremented at session creation. Deliberately NO mundane_id, ip, or token —
-- per-player login tracking was judged an overreach (Ken, 2026-08-22); daily
-- counts by client answer every usage question actually being asked.
--
-- `client` is the SHORT bucketed label ("Chrome on Mac", "jsork", "mORK"),
-- shared by Authorization::CreateSession and the report via
-- ork_session_client_label() in common.php. Cardinality stays tiny
-- (browsers x platforms + self-identified API clients): ~30 rows/day.
--
-- Apply BEFORE or WITH the code deploy. If the code lands first, tally
-- INSERTs fail silently under ERRMODE_WARNING and logins are unaffected;
-- counts simply start once the table exists.
--
--     docker exec -i ork3-php8-db mariadb -u root -proot ork \
--         < db-migrations/2026-08-22-signin-tally.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `ork_signin_tally` (
  `day`     date        NOT NULL,
  `client`  varchar(40) NOT NULL,
  `signins` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`day`, `client`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
