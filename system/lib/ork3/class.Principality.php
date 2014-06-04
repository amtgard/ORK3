<?php

class Principality extends Ork3 {

	public function __construct() {
		parent::__construct();
		$this->park = new yapo($this->db, DB_PREFIX . 'park');
		$this->kingdom = new yapo($this->db, DB_PREFIX . 'kingdom');
	}
	
	
	public function PromoteToPrincipality($request) {
	
	}
	
	public function DemoteToPark($request) {
	
	}
	
	public function GetPrincipalityOfficers($request) {
		$request['KingdomId'] = $request['PrincipalityId'];
		return Ork3::$Lib->kingdom->GetOfficers($request);
	}
	
	public function GetPrincipalityShortInfo($request) {
		$request['KingdomId'] = $request['PrincipalityId'];
		return Ork3::$Lib->kingdom->GetKingdomShortInfo($request);
	}
	
	public function GetPrincipalityAuthorizations($request) {
		$request['KingdomId'] = $request['PrincipalityId'];
		return Ork3::$Lib->kingdom->GetKingdomAuthorizations($request);
	}
	
	public function CreatePrincipality($request) {
		$r = Ork3::$Lib->kingdom->CreateKingdom($request);
		if ($r['Status'] == 0) {
			$this->kingdom->clear();
			$this->kingdom->kingdom_id = $r['Detail'];
			$this->kingdom->find();
			$this->kingdom->parent_kingdom_id = $request['KingdomId'];
			$this->kingdom->save();
		}
		return $r;
	}

	public function SetPrincipalityDetails($request) {
		$request['KingdomId'] = $request['PrincipalityId'];
		return Ork3::$Lib->kingdom->SetKingdomDetails($request);
	}
	
	public function SetPrincipalityOfficer($request) {
		$request['KingdomId'] = $request['PrincipalityId'];
		$r = Ork3::$Lib->kingdom->SetOfficer($request);
		return $r;
	}
	
	public function RetirePrincipality($request) {
		$request['ParkId'] = $request['PrincipalityId'];
		return Ork3::$Lib->kingdom->RetireKingdom($request);
	}
	
	public function RestorePrincipality($request) {
		$request['KingdomId'] = $request['PrincipalityId'];
		return Ork3::$Lib->kingdom->RestoreKingdom($request);
	}
	
	public function WafflePrincipality($request, $waffle) {
		$request['KingdomId'] = $request['PrincipalityId'];
		return Ork3::$Lib->kingdom->WaffleKingdom($request);
	}
}

?>