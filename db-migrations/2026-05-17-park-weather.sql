-- Park weather cache
-- Stores per-park current weather + 7-day forecast fetched from Open-Meteo.
-- Refreshed every ~30 min by bin/refresh-weather.php (cron), with a lazy
-- fallback when a render finds a stale row. One row per park; truncate-safe.
--
-- Denormalized "current" and "today" columns are for cheap aggregate reads
-- ("show me parks with rain coming up"). The full forecast lives in
-- forecast_json for richer per-park displays.

CREATE TABLE `ork_park_weather` (
  `park_id`           int(11)        NOT NULL,
  `fetched_at`        datetime       NOT NULL,
  `current_temp_f`    decimal(5,2)   DEFAULT NULL,
  `current_code`      tinyint unsigned DEFAULT NULL,
  `current_is_day`    tinyint(1)     DEFAULT NULL,
  `current_wind_mph`  decimal(5,1)   DEFAULT NULL,
  `today_high_f`      decimal(5,2)   DEFAULT NULL,
  `today_low_f`       decimal(5,2)   DEFAULT NULL,
  `today_precip_pct`  tinyint unsigned DEFAULT NULL,
  `forecast_json`     longtext       CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`park_id`),
  KEY `ix_fetched_at` (`fetched_at`),
  KEY `ix_today_precip` (`today_precip_pct`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
