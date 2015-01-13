<?php

class Attendance  extends Ork3 {

	public function __construct() {
		parent::__construct();
		$this->attendance = new yapo($this->db, DB_PREFIX . 'attendance');
		$this->class = new yapo($this->db, DB_PREFIX . 'class');
	}
	
	public function GetClasses($request) {
		$this->class->clear();
		if (is_numeric($request['Active'])) {
			$this->class->active = $request['Active'];
		}
		$response = array ( 'Status' => Success(), 'Classes' => array() );
		if ($this->class->find()) do {
			$response['Classes'][] = array(
					'ClassId' => $this->class->class_id,
					'Name' => $this->class->name,
					'Active' => $this->class->active
				);
		} while ($this->class->next());
		return $response;
	}
	
	public function CreateClass($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_CREATE)) {
			$this->class->clear();
			$this->class->name = $request['Name'];
			$this->class->active = $request['Active'];
			$this->class->save();
			return Success($this->class->class_id);
		} else {
			return NoAuthorization();
		}
	}
	
	public function SetClass($request) {
		$response = array();
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
			$this->class->clear();
			$this->class->class_id = $request['ClassId'];
			if (valid_id($request['ClassId']) && $this->class->find()) {
				$this->class->name = $request['Name'];
				$this->class->active = $request['Active'];
				$this->class->save();
				return Success();
			} else {
				return InvalidParameter();
			}
		} else {
			return NoAuthorization();
		}
	}
	
	public function AddAttendance($request) {
		logtrace("Attendance->AddAttendance()", $request);
		
		if (($type = $this->AttendanceAuthority($request)) === false) {
            logtrace("Attendance->AddAttendance: No Authority", $request);
			return NoAuthorization('Type is not specified.');
		}
		
        if (valid_id($request['MundaneId']) && valid_id($request['ClassId']) and strtotime($request['Date']) && valid_id($request['Credits']))
            ;
        else {
            logtrace("Attendance->AddAttendance: Invalid Request", $request);
            return InvalidParameter();
        }
        
		$this->attendance->clear();
		$this->attendance->mundane_id = $request['MundaneId'];
        $this->attendance->persona = $request['Persona'];
		$this->attendance->class_id = $request['ClassId'];
		$this->attendance->date = $request['Date'];
		$this->attendance->credits = $request['Credits'];
        $this->attendance->note = $request['Note'];
    	$this->attendance->flavor = $request['Flavor'];
		
		switch ($type) {
			case AUTH_PARK:
				$park = Ork3::$Lib->park->GetParkShortInfo(array('ParkId' => $request['ParkId']));
				$this->attendance->kingdom_id = $park['ParkInfo']['KingdomId'];
				$this->attendance->park_id = $request['ParkId'];
				break;
			case AUTH_KINGDOM:
				$this->attendance->kingdom_d = $request['KingdomId'];
				break;
			case AUTH_EVENT:
				$detail = Ork3::$Lib->event->GetEventDetail(array('EventCalendarDetailId' => $request['EventCalendarDetailId']));
				if ($detail['Status']['Status'] != 0) {
                    logtrace("AddAttendance: Could not fetch Event Detail", $detail);
					return InvalidParameter();
				}
				$this->attendance->date = $detail['CalendarEventDetails'][0]['EventStart'];
				$this->attendance->event_id = $detail['CalendarEventDetails'][0]['EventId'];
				$this->attendance->event_calendardetail_id = $request['EventCalendarDetailId'];
				break;
			default:
				return InvalidParameter();
		}
		
		$this->attendance->save();
		
		logtrace("Attendance->AddAttendance()", array($this->attendance->lastSql(), $request, $detail));
		
        if ($this->attendance->attendance_id)
    		return Success($this->attendance->attendance_id);
        return InvalidParameter();
	}
	
	public function SetAttendance($request) {
		
		logtrace("Attendance->SetAttendance()", $request);
		
		if ($this->AttendanceAuthority($request) === false) {
			return NoAuthorization();
		}
		
		$this->attendance->clear();
		$this->attendance->attendance_id = $request['AttendanceId'];
		if (!valid_id() || !$this->attendance->find()) {
			return InvalidParameter();
		}
		
		$this->attendance->mundane_id = $request['MundaneId'];
		$this->attendance->class_id = $request['ClassId'];
		$this->attendance->date = $request['Date'];
		$this->attendance->credits = $request['Credits'];
		
		$this->attendance->save();
		
		logtrace("Attendance->AddAttendance()", array($this->attendance->lastSql()));
		
		return Success($this->attendance->attendance_id);
	}
	
	public function HasAttendance($request) {
		$sql = "select count(*) > 0 as has_attendance from " . DB_PREFIX . "attendance where ";
		switch ($request['Filter']) {
			case 'Event':
					if (!is_numeric($request['Value']))
						return InvalidParameter('An event_calendardetail_id must be selected for this event test.');
					$sql .= " event_calendardetail_id = " . $request['Value'];
				break;
			default:
				return InvalidParameter('No valid Filter selected.');
		}
		$r = $this->db->query($sql);
		return $r->has_attendance;
	}
	
	public function AttendanceAuthority($request) {
		logtrace("Attendance->AttendanceAuthority()", $request);
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if (!valid_id($mundane_id)) {
			logtrace("Attendance->AttendanceAuthority() - No mundane_id", null);
			return false;
		}
		
		if (valid_id($request['AttendanceId'])) {
			$this->attendance->attendance_id = $request['AttendanceId'];
			if ($this->attendance->find()) {
				return $this->attendance_authority_h(array('Token'=>$request['Token'], 'ParkId' => $this->attendance->park_id, 'KingdomId' => $this->attendance->kingdom_id, 'EventId' => $this->attendance->event_id ));
			} else {
				return false;
			}
		} else {
			return $this->attendance_authority_h($request);
		}
	}
	
	private function attendance_authority_h($request) {
		logtrace("Attendance->attendance_authority_h()", $request);
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if (valid_id($request['ParkId'])) {
			if (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $request['ParkId'], AUTH_CREATE)) {
				return AUTH_PARK;
			}
		} else if (valid_id($request['KingdomId'])) {
			if (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $request['KingdomId'], AUTH_CREATE)) {
				return AUTH_KINGDOM;
			}
		} else if (valid_id($request['EventCalendarDetailId'])) {
			$detail = Ork3::$Lib->event->GetEventDetail(array('EventCalendarDetailId' => $request['EventCalendarDetailId']));
			if ($detail['Status']['Status'] != 0) {
				logtrace('attendance_authority_h() - ecdid match', $detail);
				return false;
			}
			if (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_EVENT, $detail['CalendarEventDetails'][0]['EventId'], AUTH_CREATE)) {
				return AUTH_EVENT;
			}
		} else if (valid_id($request['EventId'])) {
			if (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_EVENT, $request['EventId'], AUTH_CREATE)) {
				return AUTH_EVENT;
			}
		} else {
			logtrace('attendance_authority_h() - no matches');
			return false;
		}
	}
	
	public function RemoveAttendance($request) {
		
		logtrace("Attendance->RemoveAttendance()", $request);
		
		if ($this->AttendanceAuthority($request) === false) {
			return NoAuthorization();
		}
		
		$this->attendance->clear();
		$this->attendance->attendance_id = $request['AttendanceId'];
		if (!valid_id($request['AttendanceId']) || !$this->attendance->find()) {
			return InvalidParameter();
		}
		
		$this->attendance->delete();
		
		return Success($this->attendance->attendance_id);
	}

	public function _create_system_classes() {
		$classes = array(
			0 => 'Anti-Paladin',
			1 => 'Archer',
			2 => 'Assassin',
			3 => 'Barbarian',
			4 => 'Bard',
			5 => 'Color',
			6 => 'Druid',
			7 => 'Healer',
			8 => 'Monk',
			9 => 'Monster',
			10 => 'Paladin',
			11 => 'Peasant',
			12 => 'Color',
			13 => 'Reeve',
			14 => 'Scout',
			15 => 'Warrior',
			16 => 'Wizard');
		
		foreach($classes as $class) {
			$this->class->clear();
			$this->class->name = $class;
			$this->class->active = 1;
			$this->class->save();
		}
	}
	
}

?>