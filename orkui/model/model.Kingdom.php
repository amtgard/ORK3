<?php

class Model_Kingdom extends Model {

	function __construct() {
		parent::__construct();
		$this->Kingdom = new APIModel('Kingdom');
		$this->Park = new APIModel('Park');
		$this->Report = new APIModel('Report');
		$this->Event = new APIModel('Event');
		$this->Heraldry = new APIModel('Heraldry');
		$this->Search = new JSONModel('Search');
	}
	
	function get_principalities($kingdom_id) {
		return $this->Kingdom->GetPrincipalities(array('KingdomId' => $kingdom_id));
	}
	
	function update_parks($token, $request) {
		$z = array();
		foreach ($request as $k => $details) {
			$z[] = $this->Park->SetParkDetails(array(
					'Token' => $token,
					'ParkId' => $details['ParkId'],
					'Name' => $details['ParkName'],
					'Abbreviation' => $details['Abbreviation'],
					'ParkTitleId' => $details['ParkTitleId'],
					'Active' => $details['Active']
				));
				
		}
		return $z;
	}
	
	function get_park_info($kingdom_id) {
		$r = $this->Kingdom->GetParks(array('KingdomId' => $kingdom_id));
		$pt = $this->Kingdom->GetKingdomParkTitles(array('KingdomId' => $kingdom_id));
		if (0 == $r['Status']['Status'] && 0 == $pt['Status']['Status'])
			return array('Parks' => $r['Parks'], 'Titles' => $pt['ParkTitles']);
		return array();
	}
	
	function get_officers($kingdom_id) {
		$r = $this->Kingdom->GetOfficers(array( 'KingdomId' => $kingdom_id ));
		logtrace("get_officers($kingdom_id)", $r);
		if ($r['Status']['Status'] == 0)
			return $r['Officers'];
		return false;
	}
	
	function set_officers($token, $kingdom_id, $request) {
		$r = array();
		foreach ($request as $k => $officer_request) {
			$officer_request['Token'] = $token;
			$officer_request['KingdomId'] = $kingdom_id;
			$r[] = $this->Kingdom->SetOfficer($officer_request);
		}
		return $r;
	}
	
	function create_kingdom($request) {
		logtrace("create_kingdom", $request);
		$r = $this->Kingdom->CreateKingdom($request);
		return $r;
	}
	
	function set_kingdom_details($request) {
		$r = $this->Kingdom->SetKingdomDetails($request);
		return $r;
	}	
	
	function set_kingdom_parktitles($request) {
		$r = $this->Kingdom->SetKingdomParkTitles($request);
		return $r;
	}	
	
	function set_kingdom_awards($request) {
		$r = $this->Kingdom->SetKingdomAwards($request);
		return $r;
	}	
	
	function get_kingdom_name($kingdom_id) {
		$r = $this->Kingdom->GetKingdomShortInfo(array('KingdomId'=>$kingdom_id));
		return $r['KingdomInfo']['KingdomName'];
	}
	
	function get_kingdom_shortinfo($kingdom_id) {
		return array( 
			'Info' => $this->Kingdom->GetKingdomShortInfo(array('KingdomId'=>$kingdom_id)),
			'HeraldryUrl' => $this->Heraldry->GetHeraldryUrl(array('Type' => 'Kingdom', 'Id' => $kingdom_id ))
		);
	}
	
	function get_kingdom_details($kingdom_id) {
		$r = $this->Kingdom->GetKingdomDetails(array('KingdomId'=>$kingdom_id));
		$r['Heraldry'] = $this->Heraldry->GetHeraldryUrl(array('Type' => 'Kingdom', 'Id' => $kingdom_id ));
		return $r;
	}
	
	function get_park_summary($kingdom_id) {
		return $this->Report->GetKingdomParkAverages(array('KingdomId'=>$kingdom_id));
	}
	
	function get_kingdom_events($kingdom_id) {
		$t = array();
		//$name = null, $kingdom_id = null, $park_id = null, $mundane_id = null, $unit_id = null, $limit = 10, $event_id = null, $date_order = null, $date_start = null, $current = 1
		$s = $this->Search->Search_Event(null, $kingdom_id, 0, null, null, 12, null, true);
		foreach ($s as $k => $e) $t[$e['EventId']] = $e;
		$r = $this->Search->Search_Event(null, $kingdom_id, null, null, null, 8, null, true);
		foreach ($r as $k => $e) $t[$e['EventId']] = $e;
		return $t;
	}
	
}

?>