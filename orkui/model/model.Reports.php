<?php

class Model_Reports extends Model {

	function __construct() {
		parent::__construct();
		$this->Report = new APIModel('Report');
	}

	function get_tournaments($limit=10, $kingdom_id=null, $park_id=null, $event_id=null, $event_calendardetail_id=null) {
		return $this->Report->TournamentReport(array(
			'KingdomId' => $kingdom_id,
			'ParkId' => $park_id,
			'EventId' => $event_id,
			'EventCalendarDetailId' => $event_calendardetail_id,
			'Limit' => $limit
		));
	}

	function get_heraldry_report($request) {
		return $this->Report->HeraldryReport($request);
	}

	function guilds($request) {
		logtrace("guilds()", $request);
		$r = $this->Report->Guilds($request);
		if ($r['Status']['Status'] == 0) {
			return $r['Guilds'];
		}
		return false;
	}

	function kingdom_awards($request) {
		logtrace("kingdom_awards($kingdom_id, $park_id)", null);
		$r = $this->Report->PlayerAwards($request);
		if ($r['Status']['Status'] == 0) {
			return $r['Awards'];
		}
		return false;
	}

	function recommended_awards($request) {
		$r = $this->Report->PlayerAwardRecommendations($request);
		if ($r['Status']['Status'] == 0) {
			return $r['AwardRecommendations'];
		}
		return false;
	}

	function custom_awards($request) {
		$r = $this->Report->CustomAwards($request);
		if ($r['Status']['Status'] == 0) {
			return $r['Awards'];
		}
		return false;
	}

	function crown_qualed($request) {
		logtrace("crown_qualed($kingdom_id, $park_id)", null);
		$r = $this->Report->CrownQualed($request['KingdomId']);
		if ($r['Status']['Status'] == 0) {
			return $r['Awards'];
		}
		return false;
	}

	function class_masters($request) {
		logtrace("class_masters($kingdom_id, $park_id)", null);
		$r = $this->Report->ClassMasters($request);
		if ($r['Status']['Status'] == 0) {
			return $r['Awards'];
		}
		return false;
	}

	function knights_and_masters($request) {
		logtrace("knights_and_masters()", $request);
		$r = $this->Report->PlayerAwards($request);
		if ($r['Status']['Status'] == 0) {
			return $r['Awards'];
		}
		return false;
	}

	function get_attendance_summary($type, $id, $period, $num_periods, $from_date = null) {
		logtrace("get_attendance_summary($type, $id, $period, $num_periods)", null);
		$report_from = $from_date ?? date('Y-m-d');
		if ('All' == $period) {
			$r = $this->Report->AttendanceSummary(array('KingdomId'=>$type=='Kingdom'?$id:null, 'ParkId'=>$type=='Park'?$id:null, 'PrincipalityId'=>$type=='Principality'?$id:null, 'EventId'=>$type=='Event'?$id:null, 'ReportFromDate'=>$report_from, 'Periods'=>360, 'PerWeeks'=>0, 'PerMonths'=>1));
		} else {
			$r = $this->Report->AttendanceSummary(array('KingdomId'=>$type=='Kingdom'?$id:null, 'ParkId'=>$type=='Park'?$id:null, 'PrincipalityId'=>$type=='Principality'?$id:null, 'EventId'=>$type=='Event'?$id:null, 'ReportFromDate'=>$report_from, 'Periods'=>$num_periods, 'PerWeeks'=>$period=='Weeks'?1:0, 'PerMonths'=>$period=='Months'?1:0));
		}
		return $r;
	}

	function get_periodical_summary($type, $id, $period, $num_periods, $by_period, $from_date = null) {
		logtrace("get_periodical_summary($type, $id, $period, $num_periods, $by_period)", null);
		$report_from = $from_date ?? date('Y-m-d');
		if ('All' == $period) {
			$r = $this->Report->AttendanceSummary(array('KingdomId'=>$type=='Kingdom'?$id:null, 'ParkId'=>$type=='Park'?$id:null, 'PrincipalityId'=>$type=='Principality'?$id:null, 'EventId'=>$type=='Event'?$id:null, 'ReportFromDate'=>$report_from, 'Periods'=>360, 'PerWeeks'=>0, 'PerMonths'=>1, 'ByPeriod' => 'week'));
		} else {
			$r = $this->Report->AttendanceSummary(array('KingdomId'=>$type=='Kingdom'?$id:null, 'ParkId'=>$type=='Park'?$id:null, 'PrincipalityId'=>$type=='Principality'?$id:null, 'EventId'=>$type=='Event'?$id:null, 'ReportFromDate'=>$report_from, 'Periods'=>$num_periods, 'PerWeeks'=>$period=='Weeks'?1:0, 'PerMonths'=>$period=='Months'?1:0, 'ByPeriod' => 'week'));
		}
		return $r;
	}

	function get_attendance_dates($type, $id) {
		global $DB;
		$id = (int)$id;
		$col = ($type === 'Kingdom') ? 'kingdom_id' : 'park_id';
		$DB->Clear();
		$rs = $DB->DataSet("SELECT DISTINCT DATE(date) AS att_date FROM " . DB_PREFIX . "attendance WHERE {$col} = {$id} ORDER BY att_date DESC");
		$dates = [];
		if ($rs) {
			while ($rs->Next()) {
				$dates[] = $rs->att_date;
			}
		}
		return $dates;
	}

	function get_distinct_player_stats($type, $id, $period, $num_periods, $from_date = null) {
		$report_from = $from_date ?? date('Y-m-d');
		if ('All' == $period) {
			$r = $this->Report->GetDistinctPlayerStats(array('KingdomId'=>$type=='Kingdom'?$id:null, 'ParkId'=>$type=='Park'?$id:null, 'PrincipalityId'=>$type=='Principality'?$id:null, 'EventId'=>$type=='Event'?$id:null, 'Periods'=>360, 'PerWeeks'=>0, 'PerMonths'=>1));
		} else {
			$r = $this->Report->GetDistinctPlayerStats(array('KingdomId'=>$type=='Kingdom'?$id:null, 'ParkId'=>$type=='Park'?$id:null, 'PrincipalityId'=>$type=='Principality'?$id:null, 'EventId'=>$type=='Event'?$id:null, 'Periods'=>$num_periods, 'PerWeeks'=>$period=='Weeks'?1:0, 'PerMonths'=>$period=='Months'?1:0));
		}
		return $r;
	}

	function get_monthly_chart_data($type, $id, $period, $num_periods, $from_date = null) {
		$report_from = $from_date ?? date('Y-m-d');
		if ('All' == $period) {
			return $this->Report->GetMonthlyChartData(['KingdomId'=>$type=='Kingdom'?$id:null, 'ParkId'=>$type=='Park'?$id:null, 'PrincipalityId'=>$type=='Principality'?$id:null, 'ReportFromDate'=>$report_from, 'Periods'=>360, 'PerWeeks'=>0, 'PerMonths'=>1]);
		} else {
			return $this->Report->GetMonthlyChartData(['KingdomId'=>$type=='Kingdom'?$id:null, 'ParkId'=>$type=='Park'?$id:null, 'PrincipalityId'=>$type=='Principality'?$id:null, 'ReportFromDate'=>$report_from, 'Periods'=>$num_periods, 'PerWeeks'=>$period=='Weeks'?1:0, 'PerMonths'=>$period=='Months'?1:0]);
		}
	}

	function get_authorization_list($type, $id, $officers) {
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

	function active_players($type, $id, $period_type, $period, $minimum_weekly_attendance, $minimum_credits, $duespaid = false, $waivered = null, $minimum_daily_attendance = null, $montly_credit_maximum = null, $peerage = null) {
		$request = array(
				'ReportFromDate' => null,
				'MinimumWeeklyAttendance' => null==$minimum_weekly_attendance?null:$minimum_weekly_attendance,
				'MinimumCredits' => null==$minimum_credits?null:$minimum_credits,
				'PerWeeks' => null,
				'PerMonths' => null,
				'KingdomId' => null,
				'ParkId' => null,
				'DuesPaid' => $duespaid,
				'Waivered' => !is_null($waivered)&&$waivered?true:false,
				'UnWaivered' => !is_null($waivered)&&!$waivered?true:false,
                'MinimumDailyAttendance' => null==$minimum_daily_attendance?null:$minimum_daily_attendance,
                'MonthlyCreditMaximum' => null==$montly_credit_maximum?null:$montly_credit_maximum,
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

	function player_roster($type, $id, $waivered, $duespaid = 0, $banned = 0, $active = 1, $suspended = 0) {
		$request = array(
				'Type' => $type,
				'Id' => $id,
				'Active' => $active==1,
				'InActive' => $active==0,
				'Waivered' => !is_null($waivered)&&1==$waivered?1:0,
				'UnWaivered' => !is_null($waivered)&&0==$waivered?1:0,
				'Token' => $this->session->token,
				'DuesPaid' => $duespaid,
				'Banned' => $banned==1?true:false,
				'Suspended' => $suspended
			);

		$r = $this->Report->GetPlayerRoster($request);

		return $r['Roster'];
	}

	function reeve_qualified($kingdom_id, $park_id = null) {
		$request = array(
				'KingdomId' => $kingdom_id,
				'ParkId' => $park_id
			);

		$r = $this->Report->GetReeveQualified($request);

		return $r['ReeveQualified'];
	}

	function corpora_qualified($kingdom_id, $park_id = null) {
		$request = array(
				'KingdomId' => $kingdom_id,
				'ParkId' => $park_id
			);

		$r = $this->Report->GetCorporaQualified($request);

		return $r['CorporaQualified'];
	}

	function dues_paid_list($type, $id) {
		$request = array(
			'Token' => $this->session->token,
			'Type' => $type,
			'Id' => $id
		);
		$r = $this->Report->GetDuesPaidList($request);

		return $r;
	}

	function park_attendance_all_parks($request) {
		$r = $this->Report->ParkAttendanceAllParks($request);
		if ($r['Status']['Status'] == 0) {
			return array('Attendance' => $r['Attendance'], 'Summary' => $r['Summary'] ?? array());
		}
		return false;
	}

	function park_attendance_single_park($request) {
		$r = $this->Report->ParkAttendanceSinglePark($request);
		if ($r['Status']['Status'] == 0) {
			return $r['Attendance'];
		}
		return false;
	}

	function new_player_attendance($request) {
		$r = $this->Report->GetNewPlayerAttendance($request);
		if ($r['Status']['Status'] == 0) {
			return array(
				'Summary'       => $r['Summary'],
				'PlayerDetails' => $r['PlayerDetails']
			);
		}
		return false;
	}

	private function _voting_rules($kingdom_id) {
		$rules = [
			14 => [ // Celestial Kingdom — Contributing (7+ credits) or Active (12+ credits)
				'AttendanceRequired'    => 7,
				'MonthsWindow'          => 6,
				'MinMembershipMonths'   => 0,
				'AttendanceMode'        => 'count',
				'ProvinceMode'          => false,
				'ActiveMemberThreshold' => 12,
				'AllKingdoms'           => true,
			],
			31 => [ // Nine Blades
				'AttendanceRequired'  => 6,
				'MonthsWindow'        => 6,
				'MinMembershipMonths' => 6,
				'AttendanceMode'      => 'weeks',
				'ProvinceMode'        => false,
			],
			3 => [ // Iron Mountains — Mon–Sun weeks; 6-month membership required
				'AttendanceRequired'  => 6,
				'MonthsWindow'        => 6,
				'MinMembershipMonths' => 6,
				'AttendanceMode'      => 'weeks',
				'ProvinceMode'        => false,
			],
			17 => [ // Crystal Groves
				'AttendanceRequired'    => 6,
				'MonthsWindow'          => 6,
				'MinMembershipMonths'   => 6,
				'AttendanceMode'        => 'count',
				'ProvinceMode'          => true,
				'KingdomEventBonus'     => true,
			],
			10 => [ // Rising Winds — membership age checked via first attendance date, not park join date
				'AttendanceRequired'    => 7,
				'MonthsWindow'          => 6,
				'MinMembershipMonths'   => 6,
				'AttendanceMode'        => 'days',
				'ProvinceMode'          => false,
				'MembershipMode'        => 'first_attendance',
				'WeekSnap'              => true,
			],
			25 => [ // Viridian Outlands
				'AttendanceRequired'    => 6,
				'MonthsWindow'          => 6,
				'MinMembershipMonths'   => 0,
				'AttendanceMode'        => 'days',
				'ProvinceMode'          => false,
				'WeekSnap'              => true,
			],
			20 => [ // Northern Lights — online attendance excluded
				'AttendanceRequired'    => 6,
				'MonthsWindow'          => 6,
				'MinMembershipMonths'   => 0,
				'AttendanceMode'        => 'days',
				'ProvinceMode'          => false,
				'ExcludeOnline'         => true,
				'WeekSnap'              => true,
			],
			36 => [ // Northreach — 180-day window, 12 weeks; age 14+ (not auto-checked, no DOB in DB)
				'AttendanceRequired'    => 12,
				'MonthsWindow'          => 0,
				'DaysWindow'            => 180,
				'MinMembershipMonths'   => 0,
				'AttendanceMode'        => 'weeks',
				'ProvinceMode'          => false,
				'MinAge'                => 14,
			],
			27 => [ // Polaris — Sun–Sat week; 3-month home chapter membership required
				'AttendanceRequired'    => 6,
				'MonthsWindow'          => 6,
				'MinMembershipMonths'   => 3,
				'AttendanceMode'        => 'weeks',
				'WeekOffset'            => 6,
				'ProvinceMode'          => false,
			],
			38 => [ // 13 Roads
				'AttendanceRequired'    => 6,
				'MonthsWindow'          => 6,
				'MinMembershipMonths'   => 0,
				'AttendanceMode'        => 'days',
				'ProvinceMode'          => false,
				'WeekSnap'              => true,
			],
			4 => [ // Goldenvale — home park sign-ins; 1 kingdom event counts toward the 6
				'AttendanceRequired'    => 6,
				'MonthsWindow'          => 6,
				'MinMembershipMonths'   => 0,
				'AttendanceMode'        => 'count',
				'ProvinceMode'          => false,
				'HomeParkOnly'          => true,
				'KingdomEventBonus'     => true,
				'WeekSnap'              => true,
			],
			6 => [ // Emerald Hills — Tue–Mon week; 6-month kingdom residency required; Active Knight = voting eligible + 8 raw sign-ins
				'AttendanceRequired'    => 6,
				'MonthsWindow'          => 6,
				'MinMembershipMonths'   => 6,
				'AttendanceMode'        => 'weeks',
				'WeekOffset'            => 1,
				'ProvinceMode'          => false,
				'ActiveKnightThreshold' => 8,
			],
			19 => [ // Tal Dagore — 8 credits/6mo; max 2 from outside kingdom; multi-credit events capped at 2
				'AttendanceRequired'      => 8,
				'MonthsWindow'            => 6,
				'MinMembershipMonths'     => 3,
				'AttendanceMode'          => 'count',
				'ProvinceMode'            => false,
				'MaxCreditsPerEvent'      => 2,
				'MaxOutsideKingdomCredits'=> 2,
			],
		];
		return $rules[$kingdom_id] ?? null;
	}

	function get_voting_eligible($type, $id) {
		$kingdom_id = $type === 'Kingdom' ? (int)$id : 0;
		$park_id    = $type === 'Park'    ? (int)$id : 0;
		if ($type === 'Park' && $park_id && !$kingdom_id) {
			$park = new APIModel('Park');
			$kingdom_id = (int)$park->GetParkKingdomId($park_id);
		}
		$rules = $this->_voting_rules($kingdom_id) ?? [];
		return $this->Report->GetVotingEligible(array_merge($rules, [
			'KingdomId' => $kingdom_id,
			'ParkId'    => $park_id,
		]));
	}

	function get_voting_eligible_for_player($mundane_id, $kingdom_id) {
		$rules = $this->_voting_rules((int)$kingdom_id) ?? [];
		return $this->Report->GetVotingEligible(array_merge($rules, [
			'KingdomId' => (int)$kingdom_id,
			'MundaneId' => (int)$mundane_id,
		]));
	}

	function get_kingdom_parks($kingdom_id) {
		$kingdom = new APIModel('Kingdom');
		$r = $kingdom->GetParks(array('KingdomId' => $kingdom_id));
		if ($r['Status']['Status'] == 0) {
			return $r['Parks'];
		}
		return array();
	}

	function kingdom_officer_directory($kingdom_id = null) {
		$r = $this->Report->KingdomOfficerDirectory(array('KingdomId' => $kingdom_id));
		if ($r['Status']['Status'] == 0) {
			return ['Rows' => $r['Kingdoms'], 'Mode' => $r['Mode']];
		}
		return ['Rows' => [], 'Mode' => 'kingdoms'];
	}
	function event_attendance($request) {
		$r = $this->Report->EventAttendanceReport($request);
		if (isset($r['Status']['Status']) && $r['Status']['Status'] == 0) {
			return $r['Events'];
		}
		return array();
	}

	function beltline_data($request) {
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

	function park_distance_matrix($request) {
		$r = $this->Report->GetParkDistanceMatrix($request);
		return array(
			'Parks'  => isset($r['Parks'])  ? $r['Parks']  : array(),
			'Matrix' => isset($r['Matrix']) ? $r['Matrix'] : array(),
		);
	}

	function closest_parks($request) {
		$r = $this->Report->GetClosestParks($request);
		return array(
			'Parks'      => isset($r['Parks']) ? $r['Parks'] : array(),
			'OriginPark' => isset($r['OriginPark']) ? $r['OriginPark'] : null,
		);
	}

	function player_status_reconciliation($type, $id) {
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

	function set_player_active_status($token, $mundane_id, $active) {
		return $this->Report->SetPlayerActiveStatus(array(
			'Token'     => $token,
			'MundaneId' => $mundane_id,
			'Active'    => $active,
		));
	}
}

?>
