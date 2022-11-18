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

    function fetch_pronoun_list() {

        $pronouns = $this->Pronoun->GetPronounList();
        $parts = [
            'subject' => [],
            'objective' => [],
            'possessive' => [],
            'possessivepronoun' => [],
            'reflexive' => []
        ]; 
        if ($pronouns['Status']['Status'] == 0) {
			foreach ($pronouns['Pronouns'] as $k => $pronoun) {
                $parts['subjective'][] = ['value' => $pronoun['PronounId'], 'display' => $pronoun['Subject']];
                $parts['objective'][] = ['value' => $pronoun['PronounId'], 'display' => $pronoun['Object']];
                $parts['possessive'][] = ['value' => $pronoun['PronounId'], 'display' => $pronoun['Possessive']];
                $parts['possessivepronoun'][] = ['value' => $pronoun['PronounId'], 'display' => $pronoun['PossessivePronoun']];
                $parts['reflexive'][] = ['value' => $pronoun['PronounId'], 'display' => $pronoun['Reflexive']];
            }
            return $parts;
        } else { 
            return array();
        }
    }

    function fetch_custom_pronoun_display($json) {
        $id_arr = json_decode($json, TRUE);
        $ids = [];

        foreach ($id_arr as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $kk => $vv) {
                    $ids[] = $vv;
                }
            } else {
                $ids[] = $v;
            }
        }


        $pronouns = $this->Pronoun->GetPronounList($ids);
        $parts = [
            'subjective' => [],
            'objective' => [],
            'possessive' => [],
            'possessivepronoun' => [],
            'reflexive' => []
        ]; 
        $part_assignment = [
            's' => ['Subject', 'subjective'],
            'o' => ['Object', 'objective'],
            'p' => ['Possessive', 'possessive'],
            'pp' => ['PossessivePronoun', 'possessivepronoun'],
            'r' => ['Reflexive', 'reflexive']
        ];
        if ($pronouns['Status']['Status'] == 0) {
            foreach ($id_arr as $k => $v) {
                if (is_array($v)) {
                    foreach ($v as $kk => $vv) {
                        $parts[$part_assignment[$k][1]][] = $pronouns['Pronouns'][$vv][$part_assignment[$k][0]];
                    }
                } else {
                    $parts[$part_assignment[$k][1]][] = $pronouns['Pronouns'][$v][$part_assignment[$k][0]];
                }
            }

            return $parts;
        } else { 
            return array();
        }
    }
    
}

?>