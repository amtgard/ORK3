<?php

class Model_Principality extends Model {

	function __construct() {
		parent::__construct();
		$this->Kingdom = new APIModel('Kingdom');
		$this->Park = new APIModel('Park');
		$this->Report = new APIModel('Report');
		$this->Event = new APIModel('Event');
		$this->Heraldry = new APIModel('Heraldry');
		$this->Principality = new APIModel('Principality');
		$this->Search = new JSONModel('Search');
	}
	
	function update_parks($token, $request) {
		$z = array();
		foreach ($request as $k => $details) {
			$z[] = $this->Park->SetParkDetails(array(
					'Token' => $token,
					'ParkId' => $details['ParkId'],
					'ParkTitleId' => $details['ParkTitleId'],
					'Active' => $details['Active']
				));
				
		}
		return $z;
	}
	
	function get_park_info($principality_id) {
		$r = $this->Kingdom->GetParks(array('PrincipalityId' => $principality_id));
		$pt = $this->Kingdom->GetKingdomParkTitles(array('PrincipalityId' => $principality_id));
		if (0 == $r['Status']['Status'] && 0 == $pt['Status']['Status'])
			return array('Parks' => $r['Parks'], 'Titles' => $pt['ParkTitles']);
		return array();
	}
	
	function get_officers($principality_id) {
		$r = $this->Kingdom->GetOfficers(array( 'PrincipalityId' => $principality_id ));
		logtrace("get_officers($principality_id)", $r);
		if ($r['Status']['Status'] == 0)
			return $r['Officers'];
		return false;
	}
	
	function set_officers($token, $principality_id, $request) {
		$r = array();
		foreach ($request as $k => $officer_request) {
			$officer_request['Token'] = $token;
			$officer_request['PrincipalityId'] = $principality_id;
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
	
	function get_kingdom_name($principality_id) {
		$r = $this->Kingdom->GetKingdomShortInfo(array('PrincipalityId'=>$principality_id));
		return $r['KingdomInfo']['KingdomName'];
	}
	
	function get_principality_shortinfo($principality_id) {
		return array( 
			'Info' => $this->Principality->GetPrincipalityShortInfo(array('PrincipalityId'=>$principality_id)),
			'HeraldryUrl' => $this->Heraldry->GetHeraldryUrl(array('Type' => 'Park', 'Id' => $principality_id ))
		);
	}
	
	function get_kingdom_details($principality_id) {
		$r = $this->Kingdom->GetKingdomDetails(array('PrincipalityId'=>$principality_id));
		$r['Heraldry'] = $this->Heraldry->GetHeraldryUrl(array('Type' => 'Park', 'Id' => $principality_id ));
		return $r;
	}
	
	function get_park_summary($principality_id) {
		return $this->Report->GetKingdomParkAverages(array('PrincipalityId'=>$principality_id));
	}
	
	function get_kingdom_events($principality_id) {
		$t = array();
		$s = $this->Search->Search_Event(null, $principality_id, 0, null, null, 4, null);
		foreach ($s as $k => $e) $t[$e['EventId']] = $e;
		$r = $this->Search->Search_Event(null, $principality_id, null, null, null, 12, null, true);
		foreach ($r as $k => $e) $t[$e['EventId']] = $e;
		return $t;
	}
	
}

?>