CREATE TABLE `ork_idp_auth` (
  `authorization_id` int(11) NOT NULL AUTO_INCREMENT,
  `mundane_id` int(11) DEFAULT NULL,
  `idp_user_id` varchar(255) NOT NULL,
  `access_token` text,
  `refresh_token` text,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`authorization_id`),
  UNIQUE KEY `idp_user_id` (`idp_user_id`),
  KEY `mundane_id` (`mundane_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
