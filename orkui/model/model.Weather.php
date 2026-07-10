<?php

class Model_Weather extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Weather = new JSONModel('Weather');
    }

    public function daily_summary(string $date): array
    {
        return $this->Weather->GetDailySummary($date);
    }

    public function play_for_date(string $date): array
    {
        return $this->Weather->GetPlayForDate($date);
    }

    public function upcoming_events_with_forecast(int $days = 7): array
    {
        return $this->Weather->GetUpcomingEventsWithForecast($days);
    }

    public function freshness_phrase(): string
    {
        return $this->Weather->GetFreshnessPhrase();
    }

    public function strip_severities(array $dates): array
    {
        return $this->Weather->GetStripSeverities(json_encode(array_values($dates)));
    }

    public function for_park($park_id)
    {
        $weather = new Weather();

        return $weather->for_park($park_id);
    }
}
