<?php

class Model_Award extends Model {

    function __construct() {
        parent::__construct();
        $this->Award = new APIModel('Award');
        $this->Kingdom = new APIModel('Kingdom');
    }

    private static function compareAwardsByName($a, $b) {
        return strcmp($a["KingdomAwardName"], $b["KingdomAwardName"]);
    }

    function fetch_award_option_list($kingdom_id = 0, $officer_role = null) {
        if (valid_id($kingdom_id)) {
            $awards = $this->Kingdom->GetAwardList(array(
                    'IsLadder' => null,
                    'IsTitle' => null,
                    'KingdomId' => $kingdom_id,
          'OfficerRole' => $officer_role
                ));
        } else {
            $awards = $this->Award->GetAwardList(array(
                    'IsLadder' => null,
                    'IsTitle' => null,
          'OfficerRole' => $officer_role
                ));
        }
            
        if ($awards['Status']['Status'] == 0) {
            uasort($awards['Awards'], array('Model_Award','compareAwardsByName'));

			foreach ($awards['Awards'] as $k => $award) {
                $options .= "<option value='$award[KingdomAwardId]'>$award[KingdomAwardName]</option>";
            }
            return $options;
        } else { 
            return false;
        }
    }

    
}

?>