<?php

class CalendarItem extends Ork3 {

	public function __construct() {
		parent::__construct();
		$this->item = new yapo($this->db, DB_PREFIX . 'calendar_item');
	}

	// Permission: AUTH_CREATE on AUTH_KINGDOM or AUTH_PARK, same as events.
	private function canManage($mundane_id, $kingdom_id, $park_id) {
		if (!valid_id($mundane_id)) return false;
		if (valid_id($park_id)) {
			return Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $park_id, AUTH_CREATE);
		}
		if (valid_id($kingdom_id)) {
			return Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $kingdom_id, AUTH_CREATE);
		}
		return false;
	}

	public function CreateCalendarItem($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		$kingdom_id = (int)($request['KingdomId'] ?? 0);
		$park_id    = (int)($request['ParkId']    ?? 0);

		if (!valid_id($kingdom_id) && !valid_id($park_id)) {
			return InvalidParameter(null, 'A kingdom or park is required.');
		}
		if (!$this->canManage($mundane_id, $kingdom_id, $park_id)) {
			return NoAuthorization();
		}

		// If park given, always record its parent kingdom_id for scoped queries.
		if (valid_id($park_id) && !valid_id($kingdom_id)) {
			global $DB;
			$DB->Clear();
			$rs = $DB->DataSet('SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = ' . (int)$park_id . ' LIMIT 1');
			if ($rs && $rs->Next()) $kingdom_id = (int)$rs->kingdom_id;
		}

		$name = trim((string)($request['Name'] ?? ''));
		if (!strlen($name)) return InvalidParameter(null, 'Name is required.');

		$allDay = !empty($request['AllDay']) ? 1 : 0;
		[$start, $end] = $this->normalizeDates($request['EventStart'] ?? '', $request['EventEnd'] ?? '', $allDay);
		if (!$start || !$end) return InvalidParameter(null, 'Start and end dates are required.');

		$this->item->clear();
		$this->item->kingdom_id  = $kingdom_id;
		$this->item->park_id     = valid_id($park_id) ? $park_id : 0;
		$this->item->name        = substr($name, 0, 120);
		$this->item->description = (string)($request['Description'] ?? '');
		$this->item->all_day     = $allDay;
		$this->item->event_start = $start;
		$this->item->event_end   = $end;
		$this->item->created_by  = (int)$mundane_id;
		$this->item->created     = date('Y-m-d H:i:s');
		$this->item->save();

		return Success($this->item->calendar_item_id);
	}

	public function UpdateCalendarItem($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		$id         = (int)($request['CalendarItemId'] ?? 0);
		if (!valid_id($id)) return InvalidParameter(null, 'Invalid calendar item id.');

		$this->item->clear();
		$this->item->calendar_item_id = $id;
		if (!$this->item->find()) return InvalidParameter(null, 'Calendar item not found.');

		if (!$this->canManage($mundane_id, (int)$this->item->kingdom_id, (int)$this->item->park_id)) {
			return NoAuthorization();
		}

		$name = trim((string)($request['Name'] ?? ''));
		if (!strlen($name)) return InvalidParameter(null, 'Name is required.');

		$allDay = !empty($request['AllDay']) ? 1 : 0;
		[$start, $end] = $this->normalizeDates($request['EventStart'] ?? '', $request['EventEnd'] ?? '', $allDay);
		if (!$start || !$end) return InvalidParameter(null, 'Start and end dates are required.');

		$this->item->name        = substr($name, 0, 120);
		$this->item->description = (string)($request['Description'] ?? '');
		$this->item->all_day     = $allDay;
		$this->item->event_start = $start;
		$this->item->event_end   = $end;
		$this->item->save();

		return Success($id);
	}

	public function DeleteCalendarItem($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		$id         = (int)($request['CalendarItemId'] ?? 0);
		if (!valid_id($id)) return InvalidParameter(null, 'Invalid calendar item id.');

		$this->item->clear();
		$this->item->calendar_item_id = $id;
		if (!$this->item->find()) return InvalidParameter(null, 'Calendar item not found.');

		if (!$this->canManage($mundane_id, (int)$this->item->kingdom_id, (int)$this->item->park_id)) {
			return NoAuthorization();
		}

		$this->item->delete();
		return Success();
	}

	public function GetCalendarItem($request) {
		$id = (int)($request['CalendarItemId'] ?? 0);
		if (!valid_id($id)) return InvalidParameter();
		$this->item->clear();
		$this->item->calendar_item_id = $id;
		if (!$this->item->find()) return InvalidParameter();
		return [
			'CalendarItemId' => (int)$this->item->calendar_item_id,
			'KingdomId'      => (int)$this->item->kingdom_id,
			'ParkId'         => (int)$this->item->park_id,
			'Name'           => $this->item->name,
			'Description'    => $this->item->description,
			'AllDay'         => (int)$this->item->all_day,
			'EventStart'     => $this->item->event_start,
			'EventEnd'       => $this->item->event_end,
			'Status'         => Success(),
		];
	}

	private function normalizeDates($start, $end, $allDay) {
		$start = trim((string)$start);
		$end   = trim((string)$end);
		if (!$start) return [null, null];
		if (!$end)   $end = $start;

		if ($allDay) {
			// Accept either 'Y-m-d' or 'Y-m-d H:i:s'; keep just the date and pin times.
			$s = substr($start, 0, 10);
			$e = substr($end,   0, 10);
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $e)) return [null, null];
			if ($e < $s) $e = $s;
			return [$s . ' 00:00:00', $e . ' 23:59:59'];
		}

		// Timed: accept 'Y-m-d\TH:i' or 'Y-m-d H:i' or 'Y-m-d H:i:s'.
		$start = str_replace('T', ' ', $start);
		$end   = str_replace('T', ' ', $end);
		if (strlen($start) === 16) $start .= ':00';
		if (strlen($end)   === 16) $end   .= ':00';
		if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $start)) return [null, null];
		if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $end))   return [null, null];
		if ($end < $start) $end = $start;
		return [$start, $end];
	}
}

?>
