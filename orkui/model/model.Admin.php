<?php

class Model_Admin extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Kingdom = new APIModel('Kingdom');
        $this->Report = new APIModel('Report');
    }

    public function get_kingdom_name($kingdom_id)
    {
        $r = $this->Kingdom->GetKingdomShortInfo(array('KingdomId' => $kingdom_id));
        return $r['KingdomInfo']['KingdomName'];
    }

    public function get_park_summary($kingdom_id)
    {
        return $this->Report->GetKingdomParkAverages(array('KingdomId' => $kingdom_id));
    }

    public function get_top_parks_by_attendance($limit = 25, $start_date = null, $end_date = null, $native_populace = false, $waivered = false, $attendance_type = 'all')
    {
        return $this->Report->GetTopParksByAttendance(array('Limit' => $limit, 'StartDate' => $start_date, 'EndDate' => $end_date, 'NativePopulace' => $native_populace, 'Waivered' => $waivered, 'AttendanceType' => $attendance_type));
    }

    public function get_new_player_attendance_by_kingdom($start_date, $end_date, $attendance_type = 'all')
    {
        return $this->Report->GetNewPlayerAttendanceByKingdom(array('StartDate' => $start_date, 'EndDate' => $end_date, 'AttendanceType' => $attendance_type));
    }

    public function get_active_kingdoms_summary($attendance_type = 'all')
    {
        return $this->Report->GetActiveKingdomsSummary(array('AttendanceType' => $attendance_type));
    }

    public function get_inactive_kingdoms()
    {
        return $this->Report->GetInactiveKingdoms(array());
    }

    public function get_inactive_parks($kingdom_id = 0)
    {
        return $this->Report->GetInactiveParks(array('KingdomId' => $kingdom_id));
    }

    public function get_park_activity($kingdom_id = 0, $months = 24, $include_retired = false)
    {
        return $this->Report->GetParkActivity(array('KingdomId' => $kingdom_id, 'Months' => $months, 'IncludeRetired' => $include_retired ? 1 : 0));
    }

}
