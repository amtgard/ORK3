<?php

class Model_Pronoun extends Model {

    function __construct() {
        parent::__construct();
        $this->Pronoun = new APIModel('Pronoun');
    }

    private static function comparePronounsByName($a, $b) {
        return strcmp($a["subject"], $b["subject"]);
    }

    function fetch_pronoun_option_list($selected = null) {

        $pronouns = $this->Pronoun->GetPronounList();
            
        if ($pronouns['Status']['Status'] == 0) {
            //uasort($pronouns['Pronouns'], array('Model_Pronoun','comparePronounsByName'));

			foreach ($pronouns['Pronouns'] as $k => $pronoun) {
                $isSelected = (!empty($selected) && $pronoun[PronounId] == $selected) ? ' selected': '';
                $options .= "<option value='$pronoun[PronounId]' $isSelected>$pronoun[Subject] [$pronoun[Object]]</option>";
            }
            return $options;
        } else { 
            return false;
        }
    }

    
}

?>