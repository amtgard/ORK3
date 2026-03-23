CREATE TABLE ork_event_meal (
  event_meal_id INT NOT NULL AUTO_INCREMENT,
  event_calendardetail_id INT NOT NULL,
  title VARCHAR(200) NOT NULL,
  cost DECIMAL(8,2) NULL,
  menu TEXT NULL,
  modified TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (event_meal_id),
  KEY idx_event_meal_detail (event_calendardetail_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
