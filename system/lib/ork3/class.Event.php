<?php

class Event  extends Ork3 {

	public function __construct() {
		parent::__construct();
		$this->event = new yapo($this->db, DB_PREFIX . 'event');
		$this->detail = new yapo($this->db, DB_PREFIX . 'event_calendardetail');
	}
	
	public function CreateEvent($request) {
		logtrace("CreateEvent()", $request);
		$log = '';
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		// Common event setup
		$this->event->clear();
		$this->event->kingdom_id = $request['KingdomId'];
		$this->event->park_id = $request['ParkId'];
		$this->event->mundane_id = $request['MundaneId'];
		$this->event->unit_id = $request['UnitId'];
		$this->event->name = $request['Name'];
		$this->event->modified = date('Y-m-d H:i:s');
		
		if (valid_id($request['MundaneId']) && !valid_id($request['UnitId'])) {
			$this->event->kingdom_id = 0;
			$this->event->park_id = 0;
			$this->event->unit_id = 0;
			$this->event->save();
		} else if (valid_id($request['UnitId'])) {
			$this->event->kingdom_id = 0;
			$this->event->park_id = 0;
			$this->event->save();
		} else if (valid_id($request['ParkId']) && valid_id($request['KingdomId']) && valid_id($mundane_id)
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $request['ParkId'], AUTH_CREATE)) {
			$park = new yapo($this->db, DB_PREFIX . 'park');
			$park->clear();
			$park->park_id = $request['ParkId'];
			if ($park->find()) {
				$this->event->mundane_id = 0;
				$this->event->unit_id = 0;
				$this->event->save();
			} else {
				return InvalidParameter(NULL, 'Problem processing request.');
			}
		} else if (valid_id($request['KingdomId']) && valid_id($mundane_id)
						&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $request['KingdomId'], AUTH_CREATE)) {
			$kingdom = new yapo($this->db, DB_PREFIX . 'kingdom');
			$kingdom->clear();
			$kingdom->kingdom_id = $request['KingdomId'];
			if ($kingdom->find()) {
				$this->event->park_id = 0;
				$this->event->mundane_id = 0;
				$this->event->unit_id = 0;
				$this->event->save();
			} else {
				return InvalidParameter(NULL, 'Problem processing request.');
			}
		} else {
			// Bailout without committing
			return NoAuthorization();
		}
		Ork3::$Lib->heraldry->SetEventHeraldry($request);

		return Success($this->event->event_id);
	}
	
	public function GetEvents($request) {
		$this->event->clear();
		if (isset($request['LimitTo']) && $request['LimitTo'] === true) {
			$this->event->kingdom_id = 0;
			$this->event->park_id = 0;
			$this->event->unit_id = 0;
			$this->event->mundane_id = 0;
		}
		
		if (valid_id($request['KingdomId']))
			$this->event->kingdom_id = $request['KingdomId'];
		if (valid_id($request['ParkId']))
			$this->event->park_id = $request['ParkId'];
		if (valid_id($request['UnitId']))
			$this->event->unit_id = $request['UnitId'];
		if (valid_id($request['MundaneId']))
			$this->event->mundane_id = $request['MundaneId'];
		$events = array();
		if ($this->event->find()) do {
			$events[] = array(
				'EventId' => $this->event->event_id,
				'KingdomId' => $this->event->kingdom_id,
				'ParkId' => $this->event->park_id,
				'UnitId' => $this->event->unit_id,
				'MundaneId' => $this->event->mundane_id,
				'Name' => $this->event->name
			);
		} while ($this->event->next());
		return $events;
	}
	
	public function GetEvent($request) {
		$this->event->clear();
		$this->event->event_id = $request['EventId'];
		$response = array();
		if (valid_id($request['EventId']) && $this->event->find()) {
			$response['KingdomId'] = $this->event->kingdom_id;
			$response['ParkId'] = $this->event->park_id;
			$response['MundaneId'] = $this->event->mundane_id;
			$response['Name'] = $this->event->name;
			$response['HasHeraldry'] = $this->event->has_heraldry;
			$response['HeraldryUrl'] = $this->event->has_heraldry?Ork3::$Lib->heraldry->GetHeraldryUrl(array('Type'=>'Event','Id'=>$request['EventId'])):Ork3::$Lib->heraldry->GetHeraldryUrl(array('Type'=>'Event','Id'=>0));
			$response['Status'] = Success();
		} else {
			$response['Status'] = InvalidParameter();
		}
		return $response;
	}
	
	public function GetEventDetail($request) {
		logtrace("GetEventDetail()", $request);
		$this->detail->clear();
		$this->detail->event_calendardetail_id = $request['EventCalendarDetailId'];
		$response = array();
		if (valid_id($request['EventCalendarDetailId']) && $this->detail->find()) {
			$response['CalendarEventDetails'] = array();
			$nr = array();
			$nr['EventCalendarDetailId'] = $this->detail->event_calendardetail_id;
			$nr['EventId'] = $this->detail->event_id;
			$nr['Current'] = $this->detail->current;
			$nr['Price'] = $this->detail->price;
			$nr['EventStart'] = $this->detail->event_start;
			$nr['EventEnd'] = $this->detail->event_end;
			$nr['Description'] = $this->detail->description;
			$nr['Url'] = $this->detail->url;
			$nr['UrlName'] = $this->detail->url_name;
			$nr['Address'] = $this->detail->address;
			$nr['Province'] = $this->detail->province;
			$nr['PostalCode'] = $this->detail->postal_code;
			$nr['City'] = $this->detail->city;
			$nr['Country'] = $this->detail->country;
			$nr['Geocode'] = $this->detail->google_geocode;
			$nr['Location'] = $this->detail->location;
			$nr['MapURL'] = $this->detail->map_url;
			$nr['MapUrlName'] = $this->detail->map_url_name;
			$nr['Modified'] = $this->detail->modified;
			$response['CalendarEventDetails'][] = $nr;
			$response['Status'] = Success();
		} else {
			logtrace('Event->GetEventDetail()',array($request, $this->detail, $this->detail->lastSql()));
			$response['Status'] = InvalidParameter();
		}
		return $response;
	}
	
	public function GetEventDetails($request) {
		$this->detail->clear();
		$this->detail->event_id = $request['EventId'];
		if ($request['Current']) $this->detail->current = 1;
		$response = array();
		if (valid_id($request['EventId']) && $this->detail->find(array('event_start DESC'),'AND',($request['Current']?1:null))) {
			$response['CalendarEventDetails'] = array();
			do {
				$nr = array();
				$nr['EventCalendarDetailId'] = $this->detail->event_calendardetail_id;
				$nr['EventId'] = $this->detail->event_id;
				$nr['Current'] = $this->detail->current;
				$nr['Price'] = $this->detail->price;
				$nr['EventStart'] = $this->detail->event_start;
				$nr['EventEnd'] = $this->detail->event_end;
				$nr['Description'] = $this->detail->description;
				$nr['Url'] = $this->detail->url;
				$nr['UrlName'] = $this->detail->url_name;
				$nr['Address'] = $this->detail->address;
				$nr['Province'] = $this->detail->province;
				$nr['PostalCode'] = $this->detail->postal_code;
				$nr['City'] = $this->detail->city;
				$nr['Country'] = $this->detail->country;
				$nr['Geocode'] = $this->detail->google_geocode;
				$nr['Location'] = $this->detail->location;
				$nr['MapURL'] = $this->detail->map_url;
				$nr['MapUrlName'] = $this->detail->map_url_name;
				$nr['Modified'] = $this->detail->modified;
				$response['CalendarEventDetails'][] = $nr;
			} while ($this->detail->next());
			$response['Status'] = Success();
		} else {
			$response['Status'] = InvalidParameter();
		}
		return $response;
	}
	
	public function CreateEventDetails($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);

		//print_r(array("class.Event.php", $request,$mundane_id)); die();
		
		if ($mundane_id > 0 && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_EVENT, $request['EventId'], AUTH_CREATE)) {
			if (valid_id($request['Current']) && valid_id($request['EventId'])) {
				$this->detail->clear();
				$this->detail->event_id = $request['EventId'];
				$this->detail->current = 0;
				$this->detail->update();
			}
			
			$details = Common::Geocode($request['Address'], $request['City'], $request['Province'], $request['PostalCode']);
			
			$this->detail->clear();
			$this->detail->event_id = $request['EventId'];
			$this->detail->current = $request['Current'];
			$this->detail->price = $request['Price'];
			$this->detail->event_start = $request['EventStart'];
			$this->detail->event_end = $request['EventEnd'];
			$this->detail->description = Common::make_safe_html($request['Description']);
			$this->detail->url = $request['Url'];
			$this->detail->url_name = $request['UrlName'];
			$this->detail->address = isset($details['Address'])?$details['Address']:$request['Address'];
			$this->detail->province = isset($details['Province'])?$details['Province']:$request['Province'];
			$this->detail->postal_code = isset($details['PostalCode'])?$details['PostalCode']:$request['PostalCode'];
			$this->detail->city = isset($details['City'])?$details['City']:$request['City'];
			$this->detail->country = $request['Country'];
			$this->detail->map_url = $request['MapUrl'];
			$this->detail->map_url_name = $request['MapUrlName'];
			$this->detail->modified = date('Y-m-d H:i:s');
			$this->detail->google_geocode = $details['Geocode'];
			$this->detail->location = $details['Location'];
			$this->detail->save();
			return Success($this->detail->event_calendardetail_id);
		} else {
			return NoAuthorization();
		}
	}

	public function SetCurrent($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		
		$this->detail->clear();
		$this->detail->event_calendardetail_id = $request['EventCalendarDetailId'];
		if (valid_id($request['EventCalendarDetailId']) && $this->detail->find()) {
			$event_id = $this->detail->event_id;
		} else {
			return InvalidParameter();
		}
		if ($mundane_id > 0 && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_EVENT, $event_id, AUTH_CREATE)) {
			if (valid_id($request['EventCalendarDetailId']) && $this->detail->find()) {
				if ($request['Current']) {
					$sql = 'update ' . DB_PREFIX . 'event_calendardetail set current = 0 where event_id = ' . $event_id;
					$this->db->query($sql);
				}
				
				$this->detail->clear();
				$this->detail->event_calendardetail_id = $request['EventCalendarDetailId'];
				$this->detail->find();
				$this->detail->current = 1;
				$this->detail->save();
				
				logtrace("SetCurrent()", array($request, $this->detail->lastSql()));
				
				return Success($this->detail->event_calendardetail_id);
			} else {
				return InvalidParameter();
			}
		} else {
			return NoAuthorization();
		}
	}
	
	public function DeleteEventDetail($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		
		$this->detail->clear();
		$this->detail->event_calendardetail_id = $request['EventCalendarDetailId'];
		if (valid_id($request['EventCalendarDetailId']) && $this->detail->find()) {
			$event_id = $this->detail->event_id;
		} else {
			return InvalidParameter();
		}
		if ($mundane_id > 0 && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_EVENT, $event_id, AUTH_CREATE)) {
			$this->detail->clear();
			$this->detail->event_calendardetail_id = $request['EventCalendarDetailId'];
			if ($this->detail->find()) {
				$this->detail->delete();
				return Success();
			} else {
				return ProcessingError('Event Calendar Detail is missing after it was found.  Race conditions eminent!');
			}
		}
	}
	
	public function SetEventDetails($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);

		logtrace("SetEventDetails()",$request);
		
		if (valid_id($mundane_id) && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_EVENT, $request['EventId'], AUTH_EDIT)) {
			$this->detail->clear();
			$this->detail->event_calendardetail_id = $request['EventCalendarDetailId'];
			if (valid_id($request['EventCalendarDetailId']) && $this->detail->find()) {
				$details = Common::Geocode($request['Address'], $request['City'], $request['Province'], $request['Postal_code']);
			
				$this->detail->event_id = $request['EventId'];
				$this->detail->current = $request['Current'];
				$this->detail->price = $request['Price'];
				$this->detail->event_start = $request['EventStart'];
				$this->detail->event_end = $request['EventEnd'];
				$this->detail->description = Common::make_safe_html($request['Description']);
				$this->detail->url = $request['Url'];
				$this->detail->url_name = $request['UrlName'];
				$this->detail->address = isset($details['Address'])?$details['Address']:$request['Address'];
				$this->detail->province = isset($details['Province'])?$details['Province']:$request['Province'];
				$this->detail->postal_code = isset($details['PostalCode'])?$details['PostalCode']:$request['PostalCode'];
				$this->detail->city = isset($details['City'])?$details['City']:$request['City'];
				$this->detail->country = $request['Country'];
				$this->detail->map_url = $request['MapUrl'];
				$this->detail->map_url_name = $request['MapUrlName'];
				$this->detail->modified = date('Y-m-d H:i:s');
				$this->detail->google_geocode = $details['Geocode'];
				$this->detail->location = $details['Location'];
				Ork3::$Lib->heraldry->SetEventHeraldry($request);
				$this->detail->save();
				if (valid_id($request['Current'])) {
					logtrace("SetEventDetails",array( 'Token' => $request['Token'], 'EventId'=> $request['EventId'], 'EventCalendarDetailId' => $request['EventCalendarDetailId'], 'Current' => 1));
					$this->SetCurrent(array( 'Token' => $request['Token'], 'EventCalendarDetailId' => $request['EventCalendarDetailId'], 'Current' => 1));
				}
				logtrace('SetEventDetails', $request);
			} else {
				return InvalidParameter('');
			}
		} else {
			return NoAuthorization();
		}
	}

	public function SetEvent($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		
		if (valid_id($mundane_id) && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_EVENT, $request['EventId'], AUTH_EDIT)) {
				$this->event->clear();
				$this->event->event_id = $request['EventId'];
				$response = array();
				if (valid_id($request['EventId']) && $this->event->find()) {
					if (is_numeric($request['KingdomId'])) $this->event->kingdom_id = $request['KingdomId'];
					if (is_numeric($request['ParkId'])) {
						$this->event->park_id = $request['ParkId'];
						$p = Ork3::$Lib->park->GetParkShortInfo(array('ParkId'=>$request['ParkId']));
						if ($p['Status']['Status'] != 0) {
							return $p['Status'];
						} else {
							$this->event->kingdom_id = $p['KingdomId'];
						}
					}
					if (is_numeric($request['MundaneId'])) $this->event->mundane_id = $request['MundaneId'];
					if (is_numeric($request['UnitId'])) $this->event->unit_id = $request['UnitId'];
					if (trimlen($request['Name'])) $this->event->name = $request['Name'];
					$this->event->save();
					Ork3::$Lib->heraldry->SetEventHeraldry($request);
					logtrace("SetEvent", array($request, $this->event));
					return Success();
				} else {
					return InvalidParameter('Event Id is not a valid id.');
				}
		} else {
			return NoAuthorization();
		}
	}
}

?>