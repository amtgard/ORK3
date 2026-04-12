<?php

class Model_CalendarItem extends Model {

	function __construct() {
		parent::__construct();
		$this->CalendarItem = new APIModel('CalendarItem');
	}

	function create_calendar_item($token, $kingdom_id, $park_id, $name, $description, $all_day, $event_start, $event_end) {
		return $this->CalendarItem->CreateCalendarItem([
			'Token'       => $token,
			'KingdomId'   => $kingdom_id,
			'ParkId'      => $park_id,
			'Name'        => $name,
			'Description' => $description,
			'AllDay'      => $all_day,
			'EventStart'  => $event_start,
			'EventEnd'    => $event_end,
		]);
	}

	function update_calendar_item($token, $id, $name, $description, $all_day, $event_start, $event_end) {
		return $this->CalendarItem->UpdateCalendarItem([
			'Token'          => $token,
			'CalendarItemId' => $id,
			'Name'           => $name,
			'Description'    => $description,
			'AllDay'         => $all_day,
			'EventStart'     => $event_start,
			'EventEnd'       => $event_end,
		]);
	}

	function delete_calendar_item($token, $id) {
		return $this->CalendarItem->DeleteCalendarItem(['Token' => $token, 'CalendarItemId' => $id]);
	}

	function get_calendar_item($id) {
		return $this->CalendarItem->GetCalendarItem(['CalendarItemId' => $id]);
	}
}

?>
