-- Schema-only excerpt from 2018-10-31-ghetto-rate-limiting (prod database qualifier removed).

CREATE TABLE `ork_rate_limit` (
  `rate_limit_id` int(11) NOT NULL AUTO_INCREMENT,
  `service` enum('geocode') NOT NULL,
  `ip_address` varchar(24) NOT NULL,
  `count` int(11) NOT NULL,
  `expires` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`rate_limit_id`),
  UNIQUE KEY `service` (`service`,`ip_address`)
) ENGINE=InnoDB;
