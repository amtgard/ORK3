<?php

/**
 * Weather dashboard JSON service (T-LIB-02).
 */
class WeatherService extends Ork3
{
    private Weather $weather;

    public function __construct()
    {
        parent::__construct();
        $this->weather = new Weather();
    }

    public function GetDailySummary(string $date): array
    {
        return $this->weather->daily_summary($date);
    }

    public function GetPlayForDate(string $date): array
    {
        return $this->weather->play_for_date($date);
    }

    public function GetUpcomingEventsWithForecast(int $days = 7): array
    {
        return $this->weather->upcoming_events_with_forecast($days);
    }

    public function GetFreshnessPhrase(): string
    {
        return $this->weather->freshness_phrase();
    }

    /**
     * @param array|string $dates JSON array of YYYY-MM-DD strings when passed via HTTP.
     */
    public function GetStripSeverities($dates): array
    {
        if (is_string($dates)) {
            $decoded = json_decode($dates, true);
            $dates = is_array($decoded) ? $decoded : [];
        }

        return $this->weather->strip_severities($dates);
    }

    public function GetArchiveForPark(int $parkId, string $date): array
    {
        return $this->weather->archive_for_date($parkId, $date);
    }
}
