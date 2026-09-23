<?php

class Model_Recap extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Report = new APIModel('Report');
    }

    // Read a cached weekly recap. Returns the decoded payload or null if no row exists.
    public function get($week_start = null)
    {
        $request = array();
        if (!empty($week_start)) {
            $request['WeekStart'] = $week_start;
        }
        return $this->Report->ReadWeeklyRecap($request);
    }

    // Available week_starts in the recap table, newest first.
    public function recent_weeks($limit = 26)
    {
        $r = $this->Report->ListRecapWeeks($limit);
        return is_array($r) ? $r : array();
    }

    // Kingdom-scoped recap. Returns the decoded payload or null if the global
    // recap for that week hasn't been computed yet.
    public function get_for_kingdom($kingdom_id, $week_start = null)
    {
        $request = array('KingdomId' => (int)$kingdom_id);
        if (!empty($week_start)) {
            $request['WeekStart'] = $week_start;
        }
        return $this->Report->GetWeeklyRecapForKingdom($request);
    }

    // Active kingdoms list for the dropdown picker.
    public function kingdom_list()
    {
        $r = $this->Report->ListRecapKingdoms();
        return is_array($r) ? $r : array();
    }

    // Weekly headline numbers from every stored recap, for the trends charts.
    public function trend_series()
    {
        $r = $this->Report->GetRecapTrendSeries();
        return is_array($r) ? $r : array();
    }

    // Distinct attending players per week across all history (24h-cached upstream).
    public function weekly_active_players()
    {
        $r = $this->Report->GetWeeklyActivePlayersSeries();
        return is_array($r) ? $r : array();
    }

    // Daily anonymous sign-in counts by client family (1h-cached upstream).
    public function signin_series()
    {
        $r = $this->Report->GetSigninTrendSeries();
        return is_array($r) ? $r : array();
    }

    // Active sessions per community-app version (30m-cached upstream).
    public function app_versions()
    {
        $r = $this->Report->GetCommunityAppVersions();
        return is_array($r) ? $r : array();
    }
}
