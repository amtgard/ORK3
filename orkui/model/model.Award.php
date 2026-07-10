<?php

class Model_Award extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Award = new APIModel('Award');
        $this->Kingdom = new APIModel('Kingdom');
    }

    public function fetch_award_option_list($kingdom_id = 0, $officer_role = null)
    {
        $cacheKey = Ork3::$Lib->ghettocache->key(['KingdomId' => (int)$kingdom_id, 'OfficerRole' => $officer_role]);
        if (($cached = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $cacheKey, 1200)) !== false) {
            return $cached;
        }

        $award = new Award();
        $grouped = $award->GetAwardOptionGroups([
            'KingdomId' => (int) $kingdom_id,
            'OfficerRole' => $officer_role,
        ]);

        if (($grouped['Status']['Status'] ?? 1) != 0) {
            return false;
        }

        $pseudoLadderIds = $grouped['PseudoLadderIds'] ?? Award::pseudoLadderKingdomAwardIds();
        $options = '';

        foreach ($grouped['StandaloneOptions'] ?? [] as $award) {
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

        foreach ($grouped['Groups'] ?? [] as $group) {
            $label = $group['Label'] ?? '';
            $items = $group['Items'] ?? [];
            if ($items === []) {
                continue;
            }
            $options .= "<optgroup label='" . htmlspecialchars($label, ENT_QUOTES) . "'>";
            foreach ($items as $award) {
                $extra = '';
                if ($label === 'Ladder Awards') {
                    $isPseudo = in_array((int)($award['KingdomAwardId'] ?? 0), $pseudoLadderIds, true);
                    $awardId = $isPseudo ? 0 : ($award['AwardId'] ?? 0);
                    $extra = " data-is-ladder='1' data-award-id='" . htmlspecialchars($awardId, ENT_QUOTES) . "'";
                } elseif ($label === 'Masterhoods') {
                    $extra = " data-award-id='" . htmlspecialchars((int)($award['AwardId'] ?? 0), ENT_QUOTES) . "' data-peerage='Master'";
                }
                $options .= "<option value='" . htmlspecialchars($award['KingdomAwardId'], ENT_QUOTES) . "'{$extra}>" . htmlspecialchars($award['KingdomAwardName'], ENT_QUOTES) . "</option>";
            }
            $options .= "</optgroup>";
        }

        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $cacheKey, $options);
    }
}
