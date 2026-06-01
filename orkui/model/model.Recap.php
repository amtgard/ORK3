<?php

class Model_Recap extends Model {

	function __construct() {
		parent::__construct();
		$this->Report = new APIModel('Report');
	}

	// Read a cached weekly recap. Returns the decoded payload or null if no row exists.
	function get($week_start = null) {
		$request = array();
		if (!empty($week_start)) $request['WeekStart'] = $week_start;
		return $this->Report->ReadWeeklyRecap($request);
	}

	// Available week_starts in the recap table, newest first.
	function recent_weeks($limit = 26) {
		$r = $this->Report->ListRecapWeeks($limit);
		return is_array($r) ? $r : array();
	}

	// Kingdom-scoped recap. Returns the decoded payload or null if the global
	// recap for that week hasn't been computed yet.
	function get_for_kingdom($kingdom_id, $week_start = null) {
		$request = array('KingdomId' => (int)$kingdom_id);
		if (!empty($week_start)) $request['WeekStart'] = $week_start;
		return $this->Report->GetWeeklyRecapForKingdom($request);
	}

	// Active kingdoms list for the dropdown picker.
	function kingdom_list() {
		$r = $this->Report->ListRecapKingdoms();
		return is_array($r) ? $r : array();
	}
}
