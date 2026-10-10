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
        return $this->_award()->GetAwardOptionListHtml((int) $kingdom_id, $officer_role);
    }

    private function _award(): Award
    {
        return new Award();
    }

    // Presentation shaping for the Court Planner ad-hoc Add Award/Title pickers.
    // Classification itself is owned by Award::GetAwardOptionGroups() (lib layer)
    // — the same source the player Add Award modal's <optgroup> HTML is built
    // from — so the two pickers can never drift apart. This method only reshapes
    // that result into ordered groups of option arrays (instead of HTML) and
    // splits the standalone Custom bucket into Custom Award / Custom Title.
    public function fetch_award_option_groups($kingdom_id = 0, $officer_role = null)
    {
        $cacheKey = Ork3::$Lib->ghettocache->key(['KingdomId' => (int)$kingdom_id, 'OfficerRole' => $officer_role]);
        if (($cached = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $cacheKey, 1200)) !== false) {
            return $cached;
        }

        $grouped = $this->_award()->GetAwardOptionGroups([
            'KingdomId'   => (int)$kingdom_id,
            'OfficerRole' => $officer_role,
        ]);
        if (($grouped['Status']['Status'] ?? 1) != 0) {
            return false;
        }

        $pseudoLadderIds = $grouped['PseudoLadderIds'] ?? Award::pseudoLadderKingdomAwardIds();

        // Split the ungrouped Custom bucket into award-type vs title-type groups
        // (they render as their own group headers per the Court modal scoping).
        $customAward = $customTitle = [];
        foreach (($grouped['StandaloneOptions'] ?? []) as $award) {
            $sysName = $award['AwardName'] ?? $award['KingdomAwardName'];
            if ($sysName === 'Custom Title') {
                $customTitle[] = $award;
            } else {
                $customAward[] = $award;
            }
        }

        $byLabel = [];
        foreach (($grouped['Groups'] ?? []) as $g) {
            $byLabel[$g['Label']] = $g['Items'];
        }

        // Ordered groups: [label, items, isTitle, isLadderGroup]. Award-vs-Title
        // is derived from the group (isTitle flag), matching the spec.
        $groupsOrdered = [
            ['Ladder Awards',    $byLabel['Ladder Awards'] ?? [],     false, true],
            ['Custom Award',     $customAward,                        false, false],
            ['Custom Title',     $customTitle,                        true,  false],
            ['Knighthoods',      $byLabel['Knighthoods'] ?? [],       true,  false],
            ['Masterhoods',      $byLabel['Masterhoods'] ?? [],       true,  false],
            ['Paragons',         $byLabel['Paragons'] ?? [],          true,  false],
            ['Noble Titles',     $byLabel['Noble Titles'] ?? [],      true,  false],
            ['Associate Titles', $byLabel['Associate Titles'] ?? [],  true,  false],
            ['Other',            $byLabel['Other'] ?? [],             false, false],
        ];

        $result = [];
        foreach ($groupsOrdered as $g) {
            list($label, $items, $isTitle, $isLadderGroup) = $g;
            if (empty($items)) {
                continue;
            }
            $opts = [];
            foreach ($items as $award) {
                $isPseudo = in_array((int)($award['KingdomAwardId'] ?? 0), $pseudoLadderIds);
                $awardId  = ($isLadderGroup && $isPseudo) ? 0 : (int)($award['AwardId'] ?? 0);
                $opts[] = [
                    'KingdomAwardId' => (int)($award['KingdomAwardId'] ?? 0),
                    'Name'           => (string)($award['KingdomAwardName'] ?? ($award['AwardName'] ?? '')),
                    'IsLadder'       => $isLadderGroup ? true : !empty($award['IsLadder']),
                    'IsTitle'        => (bool)$isTitle,
                    'AwardId'        => $awardId,
                ];
            }
            $result[] = ['label' => $label, 'options' => $opts];
        }

        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $cacheKey, $result);
    }
}
