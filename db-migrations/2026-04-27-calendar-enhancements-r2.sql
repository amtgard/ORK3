-- Calendar Enhancements Round 2:
--   * Officer-only calendar items
--   * Draft events
--   * Weather forecast cache (Open-Meteo)

ALTER TABLE ork_calendar_item
    ADD COLUMN is_officer_only TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE ork_event
    ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'published',
    ADD INDEX idx_event_status (status);

CREATE TABLE IF NOT EXISTS ork_weather_cache (
    cache_key     VARCHAR(64) NOT NULL PRIMARY KEY,
    lat           DOUBLE NOT NULL,
    lng           DOUBLE NOT NULL,
    forecast_date DATE NOT NULL,
    payload       MEDIUMTEXT NOT NULL,
    fetched_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_weather_fetched (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
