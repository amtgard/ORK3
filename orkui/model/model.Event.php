<?php

class Model_Event extends Model {

	function __construct() {
		parent::__construct();
		$this->Kingdom = new APIModel('Kingdom');
		$this->Report = new APIModel('Report');
		$this->Event = new APIModel('Event');
		$this->Heraldry = new APIModel('Heraldry');
		$this->Search = new JSONModel('Search');
		$this->Log = $LOG;
	}
	
	function create_event($token, $kingdom_id, $park_id, $mundane_id, $unit_id, $name) {
		$request = array('Token'=>$token, 'KingdomId'=>$kingdom_id, 'ParkId'=>$park_id, 'MundaneId'=>$mundane_id, 'UnitId'=>$unit_id, 'Name'=>$name);
		logtrace("create_event()", $request);
		$r = $this->Event->CreateEvent($request);
		return $r;
	}
	
	function get_event_details($event_id) {
		
		$r = $this->Event->GetEvent(array('EventId'=>$event_id));
		if ($r['Status']['Status'] == 0) {
			$ret = $r;
			$r = $this->Event->GetEventDetails(array('EventId'=>$event_id));
			$ret['CalendarEventDetails'] = $r['CalendarEventDetails'];
			$r = $this->Search->Search_Event(null, null, null, null, null, 1, $event_id);
			$ret['EventInfo'] = $r;
			$event = $this->Event->GetEvent(array('EventId'=>$event_id));
			$ret['HeraldryUrl'] = $event['HeraldryUrl'];
			$ret['HasHeraldry'] = $event['HasHeraldry'];
			
			return $ret;
		} else {
			return $r;
		}
	}
	
	function update_event_detail($request) {
		$r = $this->Event->SetEventDetails($request);
		logtrace("update_event_detail", array($request, $r));
		return $r;
	}
	
	function add_event_detail($request) {
		$r = $this->Event->CreateEventDetails($request);
		logtrace("add_event_detail", array($request, $r));
		return $r;
	}
	
	function delete_calendar_detail($token, $detail_id) {
		$r = $this->Event->DeleteEventDetail(array('Token'=>$token, 'EventCalendarDetailId'=>$detail_id));
		return $r;
	}
	
	function update_event($token, $event_id, $kingdom_id, $park_id, $mundane_id, $unit_id, $name, $heraldry, $type) {
		$r = $this->Event->SetEvent(array('Token'=>$token, 'EventId'=> $event_id, 'KingdomId'=>$kingdom_id, 'ParkId'=>$park_id, 
			'MundaneId'=>$mundane_id, 'UnitId'=>$unit_id,'Name'=>$name, 'Heraldry'=>$heraldry, 'HeraldryMimeType'=>$type));
		logtrace("update_event($token, $event_id, $kingdom_id, $park_id, $mundane_id, $unit_id, $name)", array($r));
		return $r;
	}
	
}

?>