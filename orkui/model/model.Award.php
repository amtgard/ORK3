<?php

class Model_Award extends Model {

	function __construct() {
		parent::__construct();
		$this->Award = new APIModel('Award');
		$this->Kingdom = new APIModel('Kingdom');
	}
	
	function fetch_award_option_list($kingdom_id = 0) {
		if (valid_id($kingdom_id)) {
			$awards = $this->Kingdom->GetAwardList(array(
					'IsLadder' => null,
					'IsTitle' => null,
					'KingdomId' => $kingdom_id
				));
		} else {
			$awards = $this->Award->GetAwardList(array(
					'IsLadder' => null,
					'IsTitle' => null
				));
		}
			
		if ($awards['Status']['Status'] == 0) {
			foreach ($awards['Awards'] as $k => $award) {
				$options .= "<option value='$award[KingdomAwardId]'>$award[KingdomAwardName]</option>\n";
			}
			return $options;
		} else { 
			return false;
		}
	}

	
}

?>