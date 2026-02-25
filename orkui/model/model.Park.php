<?php

class Model_Park extends Model {

	function __construct() {
		parent::__construct();
		$this->Park = new APIModel('Park');
		$this->Report = new APIModel('Report');
		$this->Event = new APIModel('Event');
		$this->Heraldry = new APIModel('Heraldry');
		$this->Search = new JSONModel('Search');
	}
	
    function mergeparks($request) {
        return $this->Park->MergeParks($request);
    }
    
	function add_park_day($request) {
		return $this->Park->AddParkDay($request);
	}
	
	function delete_park_day($request) {
		return $this->Park->RemoveParkDay($request);
	}
	
	function get_park_details($park_id) {
		$request = array( 'ParkId' => $park_id );
		return array('ParkConfiguration'=> $this->Park->GetParkConfiguration($request),
						'ParkDays'=> $this->Park->GetParkDays($request),
						'ParkInfo' => $this->Park->GetParkDetails($request),
						'Heraldry' => $this->Heraldry->GetHeraldryUrl(array('Type' => 'Park', 'Id' => $park_id )));
	}
	
	function get_officers($park_id, $token) {
		$r = $this->Park->GetOfficers(array( 'ParkId' => $park_id, 'Token' => $token ));
		logtrace("get_officers($park_id)", $r);
		if ($r['Status']['Status'] == 0)
			return $r['Officers'];
		return false;
	}
	
	function set_officers($token, $park_id, $request) {
		$r = array();
		foreach ($request as $k => $officer_request) {
			$officer_request['Token'] = $token;
			$officer_request['ParkId'] = $park_id;
			$r[] = $this->Park->SetOfficer($officer_request);
		}
		return $r;
	}

	function vacate_officer($park_id, $role, $token) {
		return $this->Park->VacateOfficer(array('ParkId' => $park_id, 'Role' => $role, 'Token' => $token));
	}
	
	function create_park($request) {
		logtrace("create_park", $request);
		$r = $this->Park->CreatePark($request);
		return $r;
	}

	function set_park_details($request) {
		logtrace("set_park_details", $request);
		return $this->Park->SetParkDetails($request);
	}
	
	function get_park_info($park_id) {
		return $this->Park->GetParkShortInfo(array('ParkId'=>$park_id));
	}

	function get_park_name($park_id) {
		$r = $this->Park->GetParkShortInfo(array('ParkId'=>$park_id));
		return $r['ParkInfo']['ParkName'];
	}
		
	function get_park_events($park_id) {
		$r = $this->Search->Search_Event(null, null, $park_id, null, null, null);
		return $r;
	}
	
	function get_park_parkdays($park_id) {
		return $this->Park->GetParkDays(array('ParkId'=>$park_id));
	}
	
}


?>