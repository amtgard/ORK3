-- Multi-session login: per-token session table (3-device cap) — 2026-07-13
--
-- Replaces the single-token-per-user scheme (ork_mundane.token) with one row
-- per live session. Login inserts a row and evicts the least-recently-active
-- session beyond a cap of 3. The per-request check and the JSON-API authorizer
-- validate the presented token against this table. ork_mundane.token is kept as
-- a vestigial "last token" pointer for any unaudited legacy reader.
--
-- Backfill: seed one session per user whose ork_mundane.token is still unexpired
-- (token_expires > NOW()), i.e. logged in within the last LOGIN_TIMEOUT window,
-- so deploying this does not force currently-active users to log in again.

CREATE TABLE IF NOT EXISTS `ork_session` (
  `session_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mundane_id` INT UNSIGNED NOT NULL,
  `token`      VARCHAR(35)  NOT NULL,
  `created`    DATETIME     NOT NULL,
  `last_seen`  DATETIME     NOT NULL,
  `expires`    DATETIME     NOT NULL,
  PRIMARY KEY (`session_id`),
  UNIQUE KEY `token` (`token`),
  KEY `mundane_id` (`mundane_id`),
  KEY `expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `ork_session` (`mundane_id`, `token`, `created`, `last_seen`, `expires`)
SELECT `mundane_id`, `token`, NOW(), NOW(), `token_expires`
FROM `ork_mundane`
WHERE `token` <> '' AND CHAR_LENGTH(`token`) = 32 AND `token_expires` > NOW();
