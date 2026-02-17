<?php

class Model_Admin extends Model {

	function __construct() {
		parent::__construct();
		$this->Kingdom = new APIModel('Kingdom');
		$this->Report = new APIModel('Report');
	}
	
	function get_kingdom_name($kingdom_id) {
		$r = $this->Kingdom->GetKingdomShortInfo(array('KingdomId'=>$kingdom_id));
		return $r['KingdomInfo']['KingdomName'];
	}
	
	function get_park_summary($kingdom_id) {
		return $this->Report->GetKingdomParkAverages(array('KingdomId'=>$kingdom_id));
	}

	function get_top_parks_by_attendance($limit = 25, $start_date = null, $end_date = null, $native_populace = false) {
		return $this->Report->GetTopParksByAttendance(array('Limit'=>$limit, 'StartDate'=>$start_date, 'EndDate'=>$end_date, 'NativePopulace'=>$native_populace));
	}

}

?>