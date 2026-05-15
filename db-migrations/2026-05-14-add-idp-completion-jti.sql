-- Records completion-token jti values consumed by Login/idp_link_complete so
-- that a leaked completion redirect URL cannot be replayed within the 5-minute
-- exp window. Duplicate-key INSERT signals replay -> reject.
--
-- Cleanup: rows older than 1 hour are deleted by the periodic token cleanup
-- (see IdpHandoff::cleanCompletionJti and the daily ORK cron).
CREATE TABLE IF NOT EXISTS `ork_idp_completion_jti` (
  `jti` varchar(64) NOT NULL,
  `seen_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`jti`),
  KEY `seen_at` (`seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
