<?php

class Model_Tools extends Model {

	function __construct() {
		parent::__construct();
		$this->Tools = new APIModel('Tools');
	}
	
	function set_contract_details($request) {
			return $this->Tools->SetContractParkDetails($request);
	}
	
	function submit_contract($request) {
		
	}
	
	function set_chapter_status($request) {
		
	}
	
}

?>