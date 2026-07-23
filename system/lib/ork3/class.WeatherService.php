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

    private function requireToken($Token): bool
    {
        return Ork3::$Lib->authorization->IsAuthorized($Token ?? '') > 0;
    }

    public function GetDailySummary($Token, string $date): array
    {
        if (!$this->requireToken($Token)) {
            return BadToken();
        }

        return $this->weather->daily_summary($date);
    }

    public function GetPlayForDate($Token, string $date): array
    {
        if (!$this->requireToken($Token)) {
            return BadToken();
        }

        return $this->weather->play_for_date($date);
    }

    public function GetUpcomingEventsWithForecast($Token, int $days = 7): array
    {
        if (!$this->requireToken($Token)) {
            return BadToken();
        }

        return $this->weather->upcoming_events_with_forecast($days);
    }

    public function GetFreshnessPhrase($Token = null): string
    {
        if (!$this->requireToken($Token)) {
            return '';
        }

        return $this->weather->freshness_phrase();
    }

    /**
     * @param array|string $dates JSON array of YYYY-MM-DD strings when passed via HTTP.
     */
    public function GetStripSeverities($Token, $dates): array
    {
        if (!$this->requireToken($Token)) {
            return BadToken();
        }
        if (is_string($dates)) {
            $decoded = json_decode($dates, true);
            $dates = is_array($decoded) ? $decoded : [];
        }

        return $this->weather->strip_severities($dates);
    }

    public function GetArchiveForPark($Token, int $parkId, string $date): array
    {
        if (!$this->requireToken($Token)) {
            return BadToken();
        }

        // archive_for_date returns null on invalid park / lag window / miss;
        // coalesce so the : array return type never TypeErrors.
        return $this->weather->archive_for_date($parkId, $date) ?? [];
    }
}
