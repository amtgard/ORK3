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

	function get_attendance_summary($type, $id, $period, $num_periods) {
		logtrace("get_attendance_summary($type, $id, $period, $num_periods)", null);
		if ('All' == $period) {
			$r = $this->Report->AttendanceSummary(array('KingdomId'=>$type=='Kingdom'?$id:null, 'ParkId'=>$type=='Park'?$id:null, 'PrincipalityId'=>$type=='Principality'?$id:null, 'EventId'=>$type=='Event'?$id:null, 'ReportFromDate'=>date('Y-m-d'), 'Periods'=>360, 'PerWeeks'=>0, 'PerMonths'=>1));
		} else {
			$r = $this->Report->AttendanceSummary(array('KingdomId'=>$type=='Kingdom'?$id:null, 'ParkId'=>$type=='Park'?$id:null, 'PrincipalityId'=>$type=='Principality'?$id:null, 'EventId'=>$type=='Event'?$id:null, 'ReportFromDate'=>date('Y-m-d'), 'Periods'=>$num_periods, 'PerWeeks'=>$period=='Weeks'?1:0, 'PerMonths'=>$period=='Months'?1:0));
		}
		return $r;
	}

	function get_periodical_summary($type, $id, $period, $num_periods, $by_period) {
		logtrace("get_periodical_summary($type, $id, $period, $num_periods, $by_period)", null);
		if ('All' == $period) {
			$r = $this->Report->AttendanceSummary(array('KingdomId'=>$type=='Kingdom'?$id:null, 'ParkId'=>$type=='Park'?$id:null, 'PrincipalityId'=>$type=='Principality'?$id:null, 'EventId'=>$type=='Event'?$id:null, 'ReportFromDate'=>date('Y-m-d'), 'Periods'=>360, 'PerWeeks'=>0, 'PerMonths'=>1, 'ByPeriod' => 'week'));
		} else {
			$r = $this->Report->AttendanceSummary(array('KingdomId'=>$type=='Kingdom'?$id:null, 'ParkId'=>$type=='Park'?$id:null, 'PrincipalityId'=>$type=='Principality'?$id:null, 'EventId'=>$type=='Event'?$id:null, 'ReportFromDate'=>date('Y-m-d'), 'Periods'=>$num_periods, 'PerWeeks'=>$period=='Weeks'?1:0, 'PerMonths'=>$period=='Months'?1:0, 'ByPeriod' => 'week'));
		}
		return $r;
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
			return $r['Attendance'];
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

	function get_kingdom_parks($kingdom_id) {
		$kingdom = new APIModel('Kingdom');
		$r = $kingdom->GetParks(array('KingdomId' => $kingdom_id));
		if ($r['Status']['Status'] == 0) {
			return $r['Parks'];
		}
		return array();
	}
}

?>
