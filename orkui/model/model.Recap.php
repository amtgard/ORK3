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
}
