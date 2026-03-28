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
	
	function get_rsvp($detail_id, $mundane_id) {
		global $DB;
		$DB->Clear();
		$r = $DB->DataSet("SELECT status FROM " . DB_PREFIX . "event_rsvp WHERE event_calendardetail_id = " . (int)$detail_id . " AND mundane_id = " . (int)$mundane_id . " LIMIT 1");
		if ($r && $r->Next()) return $r->status;
		return false;
	}

	// Sets RSVP to $status ('going'|'interested'). If already that status, removes it (toggle off).
	function set_rsvp($detail_id, $mundane_id, $status) {
		global $DB;
		$status = in_array($status, ['going', 'interested']) ? $status : 'going';
		$DB->Clear();
		$existing = $DB->DataSet("SELECT rsvp_id, status FROM " . DB_PREFIX . "event_rsvp WHERE event_calendardetail_id = " . (int)$detail_id . " AND mundane_id = " . (int)$mundane_id . " LIMIT 1");
		if ($existing && $existing->Next()) {
			if ($existing->status === $status) {
				// Same status — toggle off
				$DB->Clear();
				$DB->Execute("DELETE FROM " . DB_PREFIX . "event_rsvp WHERE rsvp_id = " . (int)$existing->rsvp_id);
				return false;
			}
			// Different status — update
			$DB->Clear();
			$DB->Execute("UPDATE " . DB_PREFIX . "event_rsvp SET status = '" . $status . "', modified = NOW() WHERE rsvp_id = " . (int)$existing->rsvp_id);
			return $status;
		}
		$DB->Clear();
		$DB->Execute("INSERT INTO " . DB_PREFIX . "event_rsvp (event_calendardetail_id, mundane_id, status) VALUES (" . (int)$detail_id . ", " . (int)$mundane_id . ", '" . $status . "')");
		return $status;
	}

	function toggle_rsvp($detail_id, $mundane_id) {
		return $this->set_rsvp($detail_id, $mundane_id, 'going');
	}

	function remove_rsvp($detail_id, $mundane_id) {
		global $DB;
		$rsvp = new yapo($DB, DB_PREFIX . 'event_rsvp');
		$rsvp->clear();
		$rsvp->event_calendardetail_id = (int)$detail_id;
		$rsvp->mundane_id = (int)$mundane_id;
		if ($rsvp->find()) {
			$rsvp->delete();
			return true;
		}
		return false;
	}

	function get_rsvp_count($detail_id) {
		global $DB;
		$DB->Clear();
		$r = $DB->DataSet("SELECT status, COUNT(*) as cnt FROM " . DB_PREFIX . "event_rsvp WHERE event_calendardetail_id = " . (int)$detail_id . " GROUP BY status");
		$counts = ['going' => 0, 'interested' => 0, 'total' => 0];
		if ($r) while ($r->Next()) {
			$counts[$r->status] = (int)$r->cnt;
			$counts['total'] += (int)$r->cnt;
		}
		return $counts;
	}

	function get_rsvp_list($detail_id) {
		global $DB;
		$DB->Clear();
		$r = $DB->DataSet("SELECT m.mundane_id, m.persona, er.status, p.abbreviation AS park_abbr, k.abbreviation AS kingdom_abbr FROM " . DB_PREFIX . "event_rsvp er JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = er.mundane_id LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = m.park_id LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = p.kingdom_id WHERE er.event_calendardetail_id = " . (int)$detail_id . " ORDER BY er.status, m.persona");
		$list = [];
		if ($r) while ($r->Next()) {
			$list[] = ['MundaneId' => $r->mundane_id, 'Persona' => $r->persona, 'Status' => $r->status, 'KingdomAbbr' => $r->kingdom_abbr, 'ParkAbbr' => $r->park_abbr];
		}
		return $list;
	}

	function get_upcoming_rsvps($mundane_id) {
		global $DB;
		$DB->Clear();
		$r = $DB->DataSet(
			"SELECT er.event_calendardetail_id, e.event_id, e.name AS event_name, cd.event_start, cd.event_end" .
			" FROM " . DB_PREFIX . "event_rsvp er" .
			" JOIN " . DB_PREFIX . "event_calendardetail cd ON cd.event_calendardetail_id = er.event_calendardetail_id" .
			" JOIN " . DB_PREFIX . "event e ON e.event_id = cd.event_id" .
			" WHERE er.mundane_id = " . (int)$mundane_id .
			" AND cd.event_start > NOW()" .
			" ORDER BY cd.event_start ASC"
		);
		$list = [];
		while ($r->Next()) {
			$list[] = [
				'EventCalendarDetailId' => $r->event_calendardetail_id,
				'EventId'               => $r->event_id,
				'EventName'             => $r->event_name,
				'EventStart'            => $r->event_start,
				'EventEnd'              => $r->event_end,
			];
		}
		return $list;
	}

	function get_kingdom_upcoming_events($kingdom_id, $exclude_mundane_id) {
		global $DB;
		$DB->Clear();
		$r = $DB->DataSet(
			"SELECT DISTINCT cd.event_calendardetail_id, e.event_id, e.name AS event_name, cd.event_start, cd.event_end" .
			" FROM " . DB_PREFIX . "event_calendardetail cd" .
			" JOIN " . DB_PREFIX . "event e ON e.event_id = cd.event_id" .
			" WHERE e.kingdom_id = " . (int)$kingdom_id .
			" AND cd.event_start > NOW()" .
			" AND cd.event_calendardetail_id NOT IN (" .
			"   SELECT event_calendardetail_id FROM " . DB_PREFIX . "event_rsvp WHERE mundane_id = " . (int)$exclude_mundane_id .
			" )" .
			" ORDER BY cd.event_start ASC LIMIT 6"
		);
		$list = [];
		while ($r->Next()) {
			$list[] = [
				'EventCalendarDetailId' => $r->event_calendardetail_id,
				'EventId'               => $r->event_id,
				'EventName'             => $r->event_name,
				'EventStart'            => $r->event_start,
				'EventEnd'              => $r->event_end,
			];
		}
		return $list;
	}

	function delete_event($token, $event_id) {
		$r = $this->Event->DeleteEvent(array('Token' => $token, 'EventId' => (int)$event_id));
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