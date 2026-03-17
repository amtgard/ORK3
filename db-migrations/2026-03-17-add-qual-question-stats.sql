CREATE TABLE IF NOT EXISTS `ork_qual_question_stat` (
  `qual_question_id` int(11) NOT NULL,
  `times_answered`   int(11) NOT NULL DEFAULT 0,
  `times_correct`    int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`qual_question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
