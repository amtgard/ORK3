<?php

class Model_Reports extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Report = new APIModel('Report');
    }

    public function ReleaseFeatureUtilization()
    {
        return $this->Report->ReleaseFeatureUtilization();
    }

    public function get_tournaments($limit = 10, $kingdom_id = null, $park_id = null, $event_id = null, $event_calendardetail_id = null)
    {
        return $this->Report->TournamentReport(array(
            'KingdomId' => $kingdom_id,
            'ParkId' => $park_id,
            'EventId' => $event_id,
            'EventCalendarDetailId' => $event_calendardetail_id,
            'Limit' => $limit
        ));
    }

    public function get_heraldry_report($request)
    {
        return $this->Report->HeraldryReport($request);
    }

    public function guilds($request)
    {
        logtrace("guilds()", $request);
        $r = $this->Report->Guilds($request);
        if ($r['Status']['Status'] == 0) {
            return $r['Guilds'];
        }
        return false;
    }

    public function kingdom_awards($request)
    {
        logtrace("kingdom_awards($kingdom_id, $park_id)", null);
        $r = $this->Report->PlayerAwards($request);
        if ($r['Status']['Status'] == 0) {
            return $r['Awards'];
        }
        return false;
    }

    public function recommended_awards($request)
    {
        $r = $this->Report->PlayerAwardRecommendations($request);
        if ($r['Status']['Status'] == 0) {
            return $r['AwardRecommendations'];
        }
        return false;
    }

    // Cheap count for the Kingdom profile's "Recommendations (N)" tab badge —
    // avoids hydrating every rec just to size the list.
    public function recommended_awards_count($request)
    {
        return (int)$this->Report->PlayerAwardRecommendationsCount($request);
    }

    public function deleted_recommended_awards($request)
    {
        $r = $this->Report->DeletedAwardRecommendations($request);
        if (isset($r['Status']['Status']) && $r['Status']['Status'] == 0) {
            return $r['AwardRecommendations'];
        }
        return [];
    }

    public function custom_awards($request)
    {
        $r = $this->Report->CustomAwards($request);
        if ($r['Status']['Status'] == 0) {
            return $r['Awards'];
        }
        return false;
    }

    public function crown_qualed($request)
    {
        logtrace("crown_qualed($kingdom_id, $park_id)", null);
        $r = $this->Report->CrownQualed($request['KingdomId']);
        if ($r['Status']['Status'] == 0) {
            return $r['Awards'];
        }
        return false;
    }

    public function class_masters($request)
    {
        logtrace("class_masters($kingdom_id, $park_id)", null);
        $r = $this->Report->ClassMasters($request);
        if ($r['Status']['Status'] == 0) {
            return $r['Awards'];
        }
        return false;
    }

    public function knights_and_masters($request)
    {
        logtrace("knights_and_masters()", $request);
        $r = $this->Report->PlayerAwards($request);
        if ($r['Status']['Status'] == 0) {
            return $r['Awards'];
        }
        return false;
    }

    public function get_attendance_summary($type, $id, $period, $num_periods, $from_date = null)
    {
        logtrace("get_attendance_summary($type, $id, $period, $num_periods)", null);
        $report_from = $from_date ?? date('Y-m-d');
        if ('All' == $period) {
            $r = $this->Report->AttendanceSummary(array('KingdomId' => $type == 'Kingdom' ? $id : null, 'ParkId' => $type == 'Park' ? $id : null, 'PrincipalityId' => $type == 'Principality' ? $id : null, 'EventId' => $type == 'Event' ? $id : null, 'ReportFromDate' => $report_from, 'Periods' => 360, 'PerWeeks' => 0, 'PerMonths' => 1));
        } else {
            $r = $this->Report->AttendanceSummary(array('KingdomId' => $type == 'Kingdom' ? $id : null, 'ParkId' => $type == 'Park' ? $id : null, 'PrincipalityId' => $type == 'Principality' ? $id : null, 'EventId' => $type == 'Event' ? $id : null, 'ReportFromDate' => $report_from, 'Periods' => $num_periods, 'PerWeeks' => $period == 'Weeks' ? 1 : 0, 'PerMonths' => $period == 'Months' ? 1 : 0));
        }
        return $r;
    }

    public function get_periodical_summary($type, $id, $period, $num_periods, $by_period, $from_date = null)
    {
        logtrace("get_periodical_summary($type, $id, $period, $num_periods, $by_period)", null);
        $report_from = $from_date ?? date('Y-m-d');
        if ('All' == $period) {
            $r = $this->Report->AttendanceSummary(array('KingdomId' => $type == 'Kingdom' ? $id : null, 'ParkId' => $type == 'Park' ? $id : null, 'PrincipalityId' => $type == 'Principality' ? $id : null, 'EventId' => $type == 'Event' ? $id : null, 'ReportFromDate' => $report_from, 'Periods' => 360, 'PerWeeks' => 0, 'PerMonths' => 1, 'ByPeriod' => 'week'));
        } else {
            $r = $this->Report->AttendanceSummary(array('KingdomId' => $type == 'Kingdom' ? $id : null, 'ParkId' => $type == 'Park' ? $id : null, 'PrincipalityId' => $type == 'Principality' ? $id : null, 'EventId' => $type == 'Event' ? $id : null, 'ReportFromDate' => $report_from, 'Periods' => $num_periods, 'PerWeeks' => $period == 'Weeks' ? 1 : 0, 'PerMonths' => $period == 'Months' ? 1 : 0, 'ByPeriod' => 'week'));
        }
        return $r;
    }

    public function get_attendance_dates($type, $id)
    {
        $r = $this->_report_domain()->GetAttendanceDates(['Type' => $type, 'Id' => (int) $id]);

        return $r['Dates'] ?? [];
    }

    public function get_distinct_player_stats($type, $id, $period, $num_periods, $from_date = null)
    {
        $report_from = $from_date ?? date('Y-m-d');
        if ('All' == $period) {
            $r = $this->Report->GetDistinctPlayerStats(array('KingdomId' => $type == 'Kingdom' ? $id : null, 'ParkId' => $type == 'Park' ? $id : null, 'PrincipalityId' => $type == 'Principality' ? $id : null, 'EventId' => $type == 'Event' ? $id : null, 'Periods' => 360, 'PerWeeks' => 0, 'PerMonths' => 1));
        } else {
            $r = $this->Report->GetDistinctPlayerStats(array('KingdomId' => $type == 'Kingdom' ? $id : null, 'ParkId' => $type == 'Park' ? $id : null, 'PrincipalityId' => $type == 'Principality' ? $id : null, 'EventId' => $type == 'Event' ? $id : null, 'Periods' => $num_periods, 'PerWeeks' => $period == 'Weeks' ? 1 : 0, 'PerMonths' => $period == 'Months' ? 1 : 0));
        }
        return $r;
    }

    public function get_monthly_chart_data($type, $id, $period, $num_periods, $from_date = null)
    {
        $report_from = $from_date ?? date('Y-m-d');
        if ('All' == $period) {
            return $this->Report->GetMonthlyChartData(['KingdomId' => $type == 'Kingdom' ? $id : null, 'ParkId' => $type == 'Park' ? $id : null, 'PrincipalityId' => $type == 'Principality' ? $id : null, 'ReportFromDate' => $report_from, 'Periods' => 360, 'PerWeeks' => 0, 'PerMonths' => 1]);
        } else {
            return $this->Report->GetMonthlyChartData(['KingdomId' => $type == 'Kingdom' ? $id : null, 'ParkId' => $type == 'Park' ? $id : null, 'PrincipalityId' => $type == 'Principality' ? $id : null, 'ReportFromDate' => $report_from, 'Periods' => $num_periods, 'PerWeeks' => $period == 'Weeks' ? 1 : 0, 'PerMonths' => $period == 'Months' ? 1 : 0]);
        }
    }

    public function get_authorization_list($type, $id, $officers)
    {
        $request = array(
                'Type' => $type,
                'Id' => $id,
                'Officers' => $officers
            );
        logtrace('Model_Reports: get_authorization_list()', $request);
        $r = $this->Report->GetAuthorizations($request);
        logtrace('Model_Reports: get_authorization_list()', $r);
        return $r;
    }

    public function active_players($type, $id, $period_type, $period, $minimum_weekly_attendance, $minimum_credits, $duespaid = false, $waivered = null, $minimum_daily_attendance = null, $montly_credit_maximum = null, $peerage = null)
    {
        $request = array(
                'ReportFromDate' => null,
                'MinimumWeeklyAttendance' => null == $minimum_weekly_attendance ? null : $minimum_weekly_attendance,
                'MinimumCredits' => null == $minimum_credits ? null : $minimum_credits,
                'PerWeeks' => null,
                'PerMonths' => null,
                'KingdomId' => null,
                'ParkId' => null,
                'DuesPaid' => $duespaid,
                'Waivered' => !is_null($waivered) && $waivered ? true : false,
                'UnWaivered' => !is_null($waivered) && !$waivered ? true : false,
                'MinimumDailyAttendance' => null == $minimum_daily_attendance ? null : $minimum_daily_attendance,
                'MonthlyCreditMaximum' => null == $montly_credit_maximum ? null : $montly_credit_maximum,
                'Peerage' => $peerage
            );
        switch ($type) {
            case 'Kingdom':
                $request['KingdomId'] = $id;
                break;
            case 'Park':
                $request['ParkId'] = $id;
                break;
        }
        switch ($period_type) {
            case 'Months':
                $request['PerWeeks'] = $period;
                break;
            case 'Weeks':
                $request['PerMonths'] = $period;
                break;
        }
        logtrace('Model_Reports: active_players()', $request);
        $r = $this->Report->GetActivePlayers($request);

        return $r['ActivePlayerSummary'];
    }

    public function player_roster($type, $id, $waivered, $duespaid = 0, $banned = 0, $active = 1, $suspended = 0)
    {
        $request = array(
                'Type' => $type,
                'Id' => $id,
                'Active' => $active == 1,
                'InActive' => $active == 0,
                'Waivered' => !is_null($waivered) && 1 == $waivered ? 1 : 0,
                'UnWaivered' => !is_null($waivered) && 0 == $waivered ? 1 : 0,
                'Token' => $this->session->token,
                'DuesPaid' => $duespaid,
                'Banned' => $banned == 1 ? true : false,
                'Suspended' => $suspended
            );

        $r = $this->Report->GetPlayerRoster($request);

        return $r['Roster'];
    }

    public function reeve_qualified($kingdom_id, $park_id = null)
    {
        $request = array(
                'KingdomId' => $kingdom_id,
                'ParkId' => $park_id
            );

        $r = $this->Report->GetReeveQualified($request);

        return $r['ReeveQualified'];
    }

    public function corpora_qualified($kingdom_id, $park_id = null)
    {
        $request = array(
                'KingdomId' => $kingdom_id,
                'ParkId' => $park_id
            );

        $r = $this->Report->GetCorporaQualified($request);

        return $r['CorporaQualified'];
    }

    public function dues_paid_list($type, $id)
    {
        $request = array(
            'Token' => $this->session->token,
            'Type' => $type,
            'Id' => $id
        );
        $r = $this->Report->GetDuesPaidList($request);

        return $r;
    }

    public function park_attendance_all_parks($request)
    {
        $r = $this->Report->ParkAttendanceAllParks($request);
        if ($r['Status']['Status'] == 0) {
            return array('Attendance' => $r['Attendance'], 'Summary' => $r['Summary'] ?? array());
        }
        return false;
    }

    public function park_attendance_single_park($request)
    {
        $r = $this->Report->ParkAttendanceSinglePark($request);
        if ($r['Status']['Status'] == 0) {
            return $r['Attendance'];
        }
        return false;
    }

    public function new_player_attendance($request)
    {
        $r = $this->Report->GetNewPlayerAttendance($request);
        if ($r['Status']['Status'] == 0) {
            return array(
                'Summary'       => $r['Summary'],
                'PlayerDetails' => $r['PlayerDetails']
            );
        }
        return false;
    }

    // Public: the list of kingdom IDs that have voting-eligibility rules defined.
    public function supported_voting_kingdom_ids()
    {
        return VotingRules::supportedKingdomIds();
    }

    public function get_park_kingdom_id($park_id)
    {
        return $this->_kingdom_profile()->GetParkKingdomId((int) $park_id);
    }

    public function get_voting_eligible($type, $id)
    {
        $kingdom_id = $type === 'Kingdom' ? (int) $id : 0;
        $park_id = $type === 'Park' ? (int) $id : 0;
        if ($type === 'Park' && $park_id && !$kingdom_id) {
            $kingdom_id = (int) $this->_park()->GetParkKingdomId($park_id);
        }

        return $this->_report_domain()->GetVotingEligible([
            'KingdomId' => $kingdom_id,
            'ParkId' => $park_id,
        ]);
    }

    public function get_voting_eligible_for_player($mundane_id, $kingdom_id)
    {
        return $this->_report_domain()->GetVotingEligibleForPlayer([
            'MundaneId' => (int) $mundane_id,
            'KingdomId' => (int) $kingdom_id,
        ]);
    }

    /**
     * @return array{ScopeName: string, LadderAwards: array<int, array<string, mixed>>, GridRows: list<array<string, mixed>>}
     */
    public function ladder_award_grid(string $type, int $kingdomId, int $parkId): array
    {
        return $this->_report_domain()->GetLadderAwardGrid([
            'KingdomId' => $kingdomId,
            'ParkId' => $parkId,
        ]);
    }

    public function get_kingdom_parks($kingdom_id)
    {
        $kingdom = new APIModel('Kingdom');
        $r = $kingdom->GetParks(array('KingdomId' => $kingdom_id));
        if ($r['Status']['Status'] == 0) {
            return $r['Parks'];
        }
        return array();
    }

    public function kingdom_officer_directory($kingdom_id = null)
    {
        $r = $this->_report_domain()->GetKingdomOfficerDirectoryMerged(['KingdomId' => $kingdom_id]);
        if (($r['Status']['Status'] ?? 1) != 0) {
            return ['Rows' => [], 'Mode' => 'kingdoms', 'Principalities' => []];
        }

        return [
            'Rows' => $r['Rows'],
            'Mode' => $r['Mode'],
            'Principalities' => $r['Principalities'],
        ];
    }
    public function event_attendance($request)
    {
        $r = $this->Report->EventAttendanceReport($request);
        if (isset($r['Status']['Status']) && $r['Status']['Status'] == 0) {
            return $r['Events'];
        }
        return array();
    }

    public function beltline_data($request)
    {
        $r = $this->Report->BeltlineData($request);
        if ($r['Status']['Status'] == 0) {
            return array(
                'Relationships' => $r['Relationships'],
                'Knights'       => $r['Knights'],
                'AllKnightIds'  => $r['AllKnightIds'],
                'KnightTypes'   => $r['KnightTypes'],
            );
        }
        return array('Relationships' => array(), 'Knights' => array(), 'AllKnightIds' => array(), 'KnightTypes' => array());
    }

    public function park_distance_matrix($request)
    {
        $r = $this->Report->GetParkDistanceMatrix($request);
        return array(
            'Parks'  => isset($r['Parks']) ? $r['Parks'] : array(),
            'Matrix' => isset($r['Matrix']) ? $r['Matrix'] : array(),
        );
    }

    public function closest_parks($request)
    {
        $r = $this->Report->GetClosestParks($request);
        return array(
            'Parks'      => isset($r['Parks']) ? $r['Parks'] : array(),
            'OriginPark' => isset($r['OriginPark']) ? $r['OriginPark'] : null,
        );
    }

    public function player_status_reconciliation($type, $id)
    {
        $request = array();
        if ($type === 'Park') {
            $request['ParkId'] = $id;
        } else {
            $request['KingdomId'] = $id;
        }
        $r = $this->Report->GetPlayerStatusReconciliation($request);
        if ($r['Status']['Status'] == 0) {
            return array(
                'InactiveWithAttendance' => $r['InactiveWithAttendance'],
                'ActiveNoAttendance'     => $r['ActiveNoAttendance'],
            );
        }
        return false;
    }

    public function set_player_active_status($token, $mundane_id, $active)
    {
        return $this->Report->SetPlayerActiveStatus(array(
            'Token'     => $token,
            'MundaneId' => $mundane_id,
            'Active'    => $active,
        ));
    }

    private function _report_domain(): Report
    {
        return new Report();
    }

    private function _kingdom_profile(): KingdomProfile
    {
        return new KingdomProfile();
    }

    private function _park(): APIModel
    {
        return new APIModel('Park');
    }
}
