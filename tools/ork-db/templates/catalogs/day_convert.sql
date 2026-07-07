-- ORK3 Test Database — fixed embedded catalog (7 weekday rows)
CREATE TABLE IF NOT EXISTS `ork_day_convert` (
  `day` int(11) NOT NULL,
  `dayname` varchar(15) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `ork_day_convert` (`day`, `dayname`) VALUES
(0, 'Monday'),
(1, 'Tuesday'),
(2, 'Wednesday'),
(3, 'Thursday'),
(4, 'Friday'),
(5, 'Saturday'),
(6, 'Sunday');
