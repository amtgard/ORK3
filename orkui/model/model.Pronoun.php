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
                $isSelected = (!empty($selected) && $pronoun['PronounId'] == $selected) ? ' selected': '';
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
            'subjective' => [],
            'objective' => [],
            'possessive' => [],
            'possessivepronoun' => [],
            'reflexive' => []
        ];
        $seen = ['subjective' => [], 'objective' => [], 'possessive' => [], 'possessivepronoun' => [], 'reflexive' => []];
        if ($pronouns['Status']['Status'] == 0) {
			foreach ($pronouns['Pronouns'] as $k => $pronoun) {
                $cols = [
                    'subjective'       => $pronoun['Subject'],
                    'objective'        => $pronoun['Object'],
                    'possessive'       => $pronoun['Possessive'],
                    'possessivepronoun'=> $pronoun['PossessivePronoun'],
                    'reflexive'        => $pronoun['Reflexive'],
                ];
                foreach ($cols as $col => $display) {
                    if (!in_array($display, $seen[$col])) {
                        $parts[$col][] = ['value' => $pronoun['PronounId'], 'display' => $display];
                        $seen[$col][] = $display;
                    }
                }
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
                    if (!empty($vv)) {
                        $ids[] = $vv;
                    }
                }
            } else {
                if (!empty($v)) {
                    $ids[] = $v;
                }
            }
        }


        $pronouns = $this->Pronoun->GetPronounList($ids);
        $parts = [
            'subjective' => [],
            's_unique' => [],
            'objective' => [],
            'o_unique' => [],
            'possessive' => [],
            'p_unique' => [],
            'possessivepronoun' => [],
            'pp_unique' => [],
            'reflexive' => [],
            'r_unique' => []
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
                        if (!in_array($pronouns['Pronouns'][$vv][$part_assignment[$k][0]], $parts[$k . '_unique'])) {
                            $parts[$part_assignment[$k][1]][$pronouns['Pronouns'][$vv][$part_assignment[$k][0]]] = $pronouns['Pronouns'][$vv][$part_assignment[$k][0]];
                            $parts[$k . '_unique'][] = $pronouns['Pronouns'][$vv][$part_assignment[$k][0]]; 
                        }
                    }
                } else {
                    if (!in_array($pronouns['Pronouns'][$v][$part_assignment[$k][0]], $parts[$k . '_unique'])) {
                        $parts[$part_assignment[$k][1]][$pronouns['Pronouns'][$v][$part_assignment[$k][0]]] = $pronouns['Pronouns'][$v][$part_assignment[$k][0]];
                        $parts[$k . '_unique'][] = $pronouns['Pronouns'][$v][$part_assignment[$k][0]];
                    }
                }
            }

            unset($parts['s_unique'], $parts['o_unique'], $parts['p_unique'], $parts['pp_unique'], $parts['r_unique']);
            ksort($parts['subjective']);
            ksort($parts['objective']);
            ksort($parts['possessive']);
            ksort($parts['possessivepronoun']);
            ksort($parts['reflexive']);
            return $parts;
        } else { 
            return array();
        }
    }
    
}

?>