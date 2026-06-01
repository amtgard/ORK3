-- Amtgard Week in Review cache
-- One row per ISO week (week_start = the Monday). payload_json holds the
-- fully-rendered recap for that week, computed once by bin/compute-weekly-recap.php
-- on Monday morning and read straight back by the Recap page + JSON endpoint.
--
-- We keep history rather than overwriting: prior weeks become a free archive
-- and let a future community-nomination layer reference recaps by week_start.

CREATE TABLE `ork_weekly_recap` (
  `week_start`    DATE        NOT NULL,
  `computed_at`   DATETIME    NOT NULL,
  `payload_json`  LONGTEXT    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`week_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
