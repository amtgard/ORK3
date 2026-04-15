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

            $pseudoLadderIds = [7067,7249,6628,5813,6045,6050,6430,6283,7055,
                            6403,6297,7273,7070,6311,6310,7277,6411,6771,
                            6577,94,7084,6171,6574,7254];
            $custom = $ladder = $knighthoods = $masterhoods = $paragons = $associates = $nobles = $other = [];
            foreach ($awards['Awards'] as $award) {
                $sysName = $award['AwardName'] ?? $award['KingdomAwardName'];
                $isPseudoLadder = in_array((int)($award['KingdomAwardId'] ?? 0), $pseudoLadderIds);
                if ($isPseudoLadder) {
                    $ladder[] = $award;
                } elseif ($sysName === 'Custom Award' || $sysName === 'Custom Title') {
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
                    $isPseudo = in_array((int)($award['KingdomAwardId'] ?? 0), $pseudoLadderIds);
                    $awardId = $isPseudo ? 0 : ($award['AwardId'] ?? 0);
                    $options .= "<option value='" . htmlspecialchars($award['KingdomAwardId'], ENT_QUOTES) . "' data-is-ladder='1' data-award-id='" . htmlspecialchars($awardId, ENT_QUOTES) . "'>" . htmlspecialchars($award['KingdomAwardName'], ENT_QUOTES) . "</option>";
                }
                $options .= "</optgroup>";
            }
            foreach ($custom as $award) {
                $sysName = $award['AwardName'] ?? $award['KingdomAwardName'];
                $kaName = $award['KingdomAwardName'] ?? $sysName;
                $dataAttrs = '';
                if ($sysName === 'Custom Title' && $kaName === 'Custom Title') {
                    $dataAttrs = " data-custom-title='1' data-award-id='" . htmlspecialchars($award['AwardId'], ENT_QUOTES) . "'";
                } elseif ($sysName === 'Custom Award' && $kaName === 'Custom Award') {
                    $dataAttrs = " data-custom-award='1' data-award-id='" . htmlspecialchars($award['AwardId'], ENT_QUOTES) . "'";
                }
                $options .= "<option value='" . htmlspecialchars($award['KingdomAwardId'], ENT_QUOTES) . "'" . $dataAttrs . ">" . htmlspecialchars($kaName, ENT_QUOTES) . "</option>";
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
