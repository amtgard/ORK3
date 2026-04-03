<?php

class Model_Attendance extends Model {

	function __construct() {
		parent::__construct();
		$this->Attendance = new APIModel('Attendance');
		$this->Report = new APIModel('Report');
		$this->Search = new JSONMOdel('Search');
		$this->Log = $LOG;
	}
	
	function get_classes() {
		return $this->Attendance->GetClasses(array());
	}
	
	function add_attendance($token, $date, $park_id, $detail_id, $mundane_id, $class_id, $credits) {
		logtrace("Model_Attendance->add_attendance()", array($token, $date, $park_id, $detail_id, $mundane_id, $class_id, $credits));
		return $this->Attendance->AddAttendance(array('Token'=>$token, 'Date'=>$date, 'ParkId'=>$park_id, 'EventCalendarDetailId'=>$detail_id, 'MundaneId'=>$mundane_id, 'ClassId'=>$class_id, 'Credits'=>$credits));
	}
	
	function update_attendance($token, $attendance_id, $date, $credits, $class_id, $mundane_id) {
		$r = $this->Attendance->SetAttendance(array(
			'Token'        => $token,
			'AttendanceId' => $attendance_id,
			'MundaneId'    => $mundane_id,
			'Date'         => $date,
			'Credits'      => $credits,
			'ClassId'      => $class_id,
		));
		if ($r['Status'] == 0 && $mundane_id) {
			$key = Ork3::$Lib->ghettocache->key(['MundaneId' => $mundane_id]);
			Ork3::$Lib->ghettocache->bust('Model_Player.fetch_player_details', $key);
		}
		return $r;
	}
	
  function lookup_by_faces($request) {
    $p = new APIModel("Player");
    return $p->LookupByFaces($request);
  }
  
	function delete_attendance($token, $attendance_id, $mundane_id = null) {
		$r = $this->Attendance->RemoveAttendance(array('Token'=>$token, 'AttendanceId' => $attendance_id ));
		if ($r['Status'] == 0 && $mundane_id) {
			$key = Ork3::$Lib->ghettocache->key(['MundaneId' => $mundane_id]);
			Ork3::$Lib->ghettocache->bust('Model_Player.fetch_player_details', $key);
		}
		return $r;
	}
	
	function get_attendance_for_date($park_id, $date) {
		if (valid_id($park_id))
			return $this->Report->AttendanceForDate(array( 'ParkId' => $park_id, 'Date' => $date ));
	}
	
	function get_kingdom_attendance_for_date($kingdom_id, $date) {
		if (valid_id($kingdom_id))
			return $this->Report->AttendanceForDate(array( 'KingdomId' => $kingdom_id, 'Date' => $date ));
	}
	
	function get_attendance_for_event($event_id, $detail_id) {
		if (valid_id($event_id))
			return $this->Report->AttendanceForEvent(array( 'EventId' => $event_id, 'EventCalendarDetailId' => $detail_id ));
	}
	
	function get_eventdetail_info($detail_id) {
		$r = $this->Search->CalendarDetail($detail_id);
		return $r;
	}
	
	function get_event_info($event_id) {
		$r = $this->Search->Event(null,null,null,null,null,null,$event_id);
		logtrace("get_event_info($event_id)", $r);
		return $r;
	}



	function get_player_last_class($mundane_id) {
		return $this->Attendance->GetPlayerLastClass(['MundaneId' => (int)$mundane_id]);
	}

	function create_attendance_link($args) {
		return $this->Attendance->CreateAttendanceLink($args);
	}

	function get_attendance_link_info($link_token) {
		return $this->Attendance->GetAttendanceLinkInfo(['LinkToken' => $link_token]);
	}

	function use_attendance_link($token, $link_token, $class_id) {
		return $this->Attendance->UseAttendanceLink(['Token' => $token, 'LinkToken' => $link_token, 'ClassId' => $class_id]);
	}

	function get_recent_attendees($park_id) {
		return $this->Report->RecentParkAttendees(['ParkId' => $park_id]);
	}

	function get_adjacent_park_dates($park_id, $date) {
		global $DB;
		$pid  = (int)$park_id;
		$date = date('Y-m-d', strtotime($date));
		$DB->Clear();
		$prev = null;
		$r = $DB->DataSet("SELECT DATE(date) AS att_date FROM " . DB_PREFIX . "attendance WHERE park_id = {$pid} AND date < '{$date}' ORDER BY date DESC LIMIT 1");
		if ($r && $r->Next()) $prev = $r->att_date;
		$DB->Clear();
		$next = null;
		$r = $DB->DataSet("SELECT DATE(date) AS att_date FROM " . DB_PREFIX . "attendance WHERE park_id = {$pid} AND date > '{$date}' ORDER BY date ASC LIMIT 1");
		if ($r && $r->Next()) $next = $r->att_date;
		return ['prev' => $prev, 'next' => $next];
	}

	function get_attendance_links($token, $scope, $id) {
		$args = ['Token' => $token];
		if ($scope === 'park') $args['ParkId'] = $id;
		else $args['KingdomId'] = $id;
		return $this->Attendance->GetAttendanceLinks($args);
	}

	function delete_attendance_link($token, $link_id) {
		return $this->Attendance->DeleteAttendanceLink(['Token' => $token, 'LinkId' => $link_id]);
	}
}

?>