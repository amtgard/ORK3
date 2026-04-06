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
        $cacheKey = Ork3::$Lib->ghettocache->key(['KingdomId' => (int)$kingdom_id, 'OfficerRole' => $officer_role]);
        if (($cached = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $cacheKey, 1200)) !== false)
            return $cached;
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

            $custom = $ladder = $knighthoods = $masterhoods = $paragons = $associates = $nobles = $other = [];
            foreach ($awards['Awards'] as $award) {
                $sysName = $award['AwardName'] ?? $award['KingdomAwardName'];
                if ($sysName === 'Custom Award') {
                    $custom[] = $award;
                } elseif (!empty($award['IsLadder'])) {
                    $ladder[] = $award;
                } elseif (in_array($sysName, ['Defender', 'Master'])) {
                    $nobles[] = $award;
                } elseif ($sysName === 'Weaponmaster') {
                    $other[] = $award;
                } elseif (($award['Peerage'] ?? '') === 'Knight') {
                    $knighthoods[] = $award;
                } elseif (($award['Peerage'] ?? '') === 'Paragon') {
                    $paragons[] = $award;
                } elseif (($award['Peerage'] ?? '') === 'Master'
                          || (!empty($award['IsTitle']) && ($award['TitleClass'] ?? 0) == 10)) {
                    $masterhoods[] = $award;
                } elseif (in_array($award['Peerage'] ?? '', ['Squire','Man-At-Arms','Page','Lords-Page'])
                          || $sysName === 'Apprentice') {
                    $associates[] = $award;
                } elseif ((!empty($award['IsTitle']) && ($award['TitleClass'] ?? 0) >= 30)
                          || $sysName === 'Esquire') {
                    $nobles[] = $award;
                } else {
                    $other[] = $award;
                }
            }

            $options = '';
            if (!empty($ladder)) {
                $options .= "<optgroup label='Ladder Awards'>";
                foreach ($ladder as $award) {
                    $options .= "<option value='" . htmlspecialchars($award['KingdomAwardId'], ENT_QUOTES) . "' data-is-ladder='1' data-award-id='" . htmlspecialchars($award['AwardId'], ENT_QUOTES) . "'>" . htmlspecialchars($award['KingdomAwardName'], ENT_QUOTES) . "</option>";
                }
                $options .= "</optgroup>";
            }
            foreach ($custom as $award) {
                $options .= "<option value='" . htmlspecialchars($award['KingdomAwardId'], ENT_QUOTES) . "'>" . htmlspecialchars($award['KingdomAwardName'], ENT_QUOTES) . "</option>";
            }
            $groups = [
                'Knighthoods' => $knighthoods,
                'Masterhoods' => $masterhoods,
                'Paragons' => $paragons,
                'Noble Titles' => $nobles,
                'Associate Titles' => $associates,
                'Other' => $other,
            ];
            foreach ($groups as $label => $items) {
                if (empty($items)) continue;
                $options .= "<optgroup label='" . htmlspecialchars($label, ENT_QUOTES) . "'>";
                foreach ($items as $award) {
                    $options .= "<option value='" . htmlspecialchars($award['KingdomAwardId'], ENT_QUOTES) . "'>" . htmlspecialchars($award['KingdomAwardName'], ENT_QUOTES) . "</option>";
                }
                $options .= "</optgroup>";
            }
            return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $cacheKey, $options);
        } else {
            return false;
        }
    }


}

?>
