<?php

class Model_Weather extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Weather = new JSONModel('Weather');
    }

    public function daily_summary(string $date, string $token = ''): array
    {
        return $this->Weather->GetDailySummary($token, $date);
    }

    public function play_for_date(string $date, string $token = ''): array
    {
        return $this->Weather->GetPlayForDate($token, $date);
    }

    public function upcoming_events_with_forecast(int $days = 7, string $token = ''): array
    {
        return $this->Weather->GetUpcomingEventsWithForecast($token, $days);
    }

    public function freshness_phrase(string $token = ''): string
    {
        return $this->Weather->GetFreshnessPhrase($token);
    }

    public function strip_severities(array $dates, string $token = ''): array
    {
        return $this->Weather->GetStripSeverities($token, json_encode(array_values($dates)));
    }

    public function for_park($park_id)
    {
        return $this->_weather()->for_park($park_id);
    }

    private function _weather(): Weather
    {
        return new Weather();
    }
}
