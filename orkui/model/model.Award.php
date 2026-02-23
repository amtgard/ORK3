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

            $ladder = array();
            $other  = array();
            foreach ($awards['Awards'] as $award) {
                if (!empty($award['IsLadder'])) {
                    $ladder[] = $award;
                } else {
                    $other[] = $award;
                }
            }

            $options = '';
            if (!empty($ladder)) {
                $options .= "<optgroup label='Ladder Awards'>";
                foreach ($ladder as $award) {
                    $options .= "<option value='$award[KingdomAwardId]' data-is-ladder='1' data-award-id='$award[AwardId]'>$award[KingdomAwardName]</option>";
                }
                $options .= "</optgroup>";
            }
            if (!empty($other)) {
                $options .= "<optgroup label='Awards'>";
                foreach ($other as $award) {
                    $options .= "<option value='$award[KingdomAwardId]'>$award[KingdomAwardName]</option>";
                }
                $options .= "</optgroup>";
            }
            return $options;
        } else { 
            return false;
        }
    }

    
}

?>