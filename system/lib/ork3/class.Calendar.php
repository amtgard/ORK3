<?php

class Calendar extends Ork3 {

	public function __construct() {
		parent::__construct();
	}
	
	public function Next($request) {
		switch ($request['Type']) {
			case 'Week': return $this->NextWeek($request); 
			case 'Month': return $this->NextMonth($request); 
			case 'Year': return $this->NextYear($request); 
		}
	}
	
	public function NextWeek($request) {
		$this->db->Clear();
		$this->db->date = $request['Date'];
		$sql = "select event.event_id, event.name, detail.event_start, detail.event_end, detail.url, detail.description from " . DB_PREFIX . "event event left join " . DB_PREFIX . "event_calendardetail detail on detail.event_id = event.event_id where event_start >= :date and event_end <= date_add(:date, interval 7 day)";
		return array('Status'=>Success(), 'Dates' => array_merge($this->_make_calendar_set($sql), $this->_park_days(strtotime($request['Date']), 'week')));
	}
	
	public function NextMonth($request) {
		$this->db->Clear();
		$this->db->date = $request['Date'];
		$sql = "select event.event_id, event.name, detail.event_start, detail.event_end, detail.url, detail.description from " . DB_PREFIX . "event event left join " . DB_PREFIX . "event_calendardetail detail on detail.event_id = event.event_id where event_start >= :date and event_end <= date_add(:date, interval 1 month)";
		return array('Status'=>Success(), 'Dates' => array_merge($this->_make_calendar_set($sql), $this->_park_days(strtotime($request['Date']), 'month')));
	}
	
	public function NextYear($request) {
		$this->db->Clear();
		$this->db->date = $request['Date'];
		$sql = "select event.event_id, event.name, detail.event_start, detail.event_end, detail.url, detail.description from " . DB_PREFIX . "event event left join " . DB_PREFIX . "event_calendardetail detail on detail.event_id = event.event_id where event_start >= :date and event_end <= date_add(:date, interval 1 year)";
		return array('Status'=>Success(), 'Dates' => array_merge($this->_make_calendar_set($sql), $this->_park_days(strtotime($request['Date']), 'year')));
	}
	
	private function _make_calendar_set($sql) {
		$response = array();
		$events = $this->db->Query($sql);
		if ($events !== false && $events->Size() > 0) do {
			$response[] = array(
					'DateStart' => $events->event_start,
					'DateEnd' => $events->event_end,
					'Time' => '',
					'Title' => $events->name,
					'Url' => HTTP_UI . 'Event/index/' . $events->event_id,
					'Description' => $events->description
				);
		} while ($events->next());
		return $response;
	}
	
	public function _park_days($start_date, $period) {
		$sql = "
				select 
						park.name, park.park_id, recurrence, week_of_month, week_day, month_day, purpose, description, time 
					from " . DB_PREFIX . "park park 
						left join " . DB_PREFIX . "parkday parkday on parkday.park_id = park.park_id 
					where 
						park.active = 'Active' and
						recurrence is not null";
		$dates = array();
		$parkdays = $this->db->Query($sql);
		switch ($period) {
			case 'week': $final_date = strtotime("+1 week", $start_date); break;
			case 'month': $final_date = strtotime("+1 month", $start_date); break;
			case 'year': $final_date = strtotime("+1 year", $start_date); break;
		}
		if ($parkdays !== false && $parkdays->size() > 0) do {
			$currdate = $start_date;
			$moredates = true;
			$counter = 0;
			while ($moredates) {
				$counter++;
				$date = Park::CalculateNextParkDay($parkdays->recurrence, $parkdays->week_of_month, $parkdays->month_day, $parkdays->week_day, $currdate);
				switch($parkdays->recurrence) {
					case 'weekly': $currdate = strtotime("+1 week", $currdate); break;
					case 'monthly': 
					case 'week-of-month': $currdate = strtotime("+1 month", $currdate); break;
					default:
						$moredates = false;
						break;
				}
				if ($currdate > $final_date) {
					$moredates = false;
				}
				if (strtotime($date) <= $final_date)
					$dates[] = array(
							'DateStart' => $date,
							'DateEnd' => $date,
							'Time' => $parkdays->time,
							'Title' => $parkdays->name . " ({$parkdays->purpose})",
							'Url' => HTTP_UI . 'Park/index/' . $parkdays->park_id,
							'Description' => $parkdays->description
						);
			}
		} while ($parkdays->next());
		return $dates;
	}
}

?>