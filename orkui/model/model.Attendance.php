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
		logtrace("Model_Attendance->add_attendance()", array($token, $date, $park_id, $event_id, $mundane_id, $class_id, $credits));
		return $this->Attendance->AddAttendance(array('Token'=>$token, 'Date'=>$date, 'ParkId'=>$park_id, 'EventCalendarDetailId'=>$detail_id, 'MundaneId'=>$mundane_id, 'ClassId'=>$class_id, 'Credits'=>$credits));
	}
	
	function update_attendance() {
	
	}
	
  function lookup_by_faces($request) {
    $p = new APIModel("Player");
    return $p->LookupByFaces($request);
  }
  
	function delete_attendance($token, $attendance_id) {
		return $this->Attendance->RemoveAttendance(array('Token'=>$token, 'AttendanceId' => $attendance_id ));
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
}

?>