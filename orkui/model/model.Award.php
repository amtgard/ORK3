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

    /**
     * Award names, lower-cased, that a GetAwardList()-shaped row set says are
     * officer positions — i.e. carry a resolved (non-null, non-'none') OfficerRole.
     * A row with no OfficerRole key at all is skipped rather than treated as
     * evidence either way: this is describing what the catalog actually resolved,
     * not guessing about a malformed row.
     *
     * Shared home for this and isDanglingRowAnOfficer() below: Model_Scrollery
     * (the create dropdown, bulk print, reprint list) and Model_Player (the
     * profile Awards tab's Print button) both need the same "is this dangling
     * award row actually an officer position" answer, and neither should reach
     * into the other's model to get it.
     *
     * @param array<int, array<string, mixed>> $awards
     * @return array<string, true>
     */
    public static function officerNamesFromForTest(array $awards): array
    {
        $names = [];
        foreach ($awards as $row) {
            if (!array_key_exists('OfficerRole', $row)) {
                continue;
            }

            $role = strtolower(trim((string) ($row['OfficerRole'] ?? '')));
            if ($role === '' || $role === 'none') {
                continue;
            }

            $name = strtolower(trim((string) ($row['KingdomAwardName'] ?? $row['kingdom_awardname'] ?? '')));
            if ($name !== '') {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /**
     * Whether a DANGLING award/kingdomaward row — its own award_id join missed,
     * so both AwardId and OfficerRole come back NULL (archery's real grants have
     * this shape on purpose, under base award_id 0/94/131) — is actually an
     * officer position wearing archery's cover, rather than a genuine archery
     * grant or an uncovered ladder/title.
     *
     * Two signals, either sufficient:
     * - A RESOLVED SIBLING of the exact same name in $officerNames (built by
     *   officerNamesFromForTest() from the same catalog) — catches a position
     *   whose own base award went missing while a working copy of the same name
     *   still resolves elsewhere in the SAME kingdom. Kingdom 28's second
     *   "Sheriff": kingdomaward_id 5028 points at award_id 163 (no ork_award row);
     *   sibling kingdomaward_id 4935 resolves to award_id 70, officer_role='park'.
     * - Kingdom::awardNameLooksLikeOfficer()'s body-prefix + role-suffix pattern —
     *   catches a position with NO sibling anywhere in its own kingdom at all.
     *   Kingdom 21's "Shire Champion" (kingdomaward_id 6437, award_id 0) is the
     *   ONLY "Shire Champion" row that kingdom has — no in-kingdom sibling exists
     *   to check — but every OTHER kingdom spelling the same position (award_id
     *   206, officer_role='principality') resolves fine, and the name matches the
     *   pattern unambiguously.
     *
     * Neither signal alone covers both real cases: the pattern alone was already
     * rejected for GetAwardList()'s own Awards/Officers bucketing (it misses bare
     * "Sheriff" and bare "Defender"), and the sibling search alone misses a
     * position with no surviving in-kingdom spelling at all. Combined, they cover
     * every dangling officer position found in the real data across kingdoms 21
     * and 28, while still leaving Warlord, Defender and every archery name open —
     * none of those match the pattern, and none have an officer sibling.
     *
     * @param array<string, true> $officerNames
     */
    public static function isDanglingRowAnOfficer(string $name, array $officerNames): bool
    {
        $key = strtolower(trim($name));
        if ($key === '') {
            return false;
        }

        return isset($officerNames[$key]) || Kingdom::awardNameLooksLikeOfficer($name);
    }

    /**
     * Officer-position names known for one kingdom's own catalog — see
     * isDanglingRowAnOfficer() for how a dangling row is actually judged against
     * this map. Cached per request: a player's award history, a bulk run, or the
     * create dropdown touches at most a handful of distinct kingdoms.
     *
     * @return array<string, true>
     */
    public function officer_names_for_kingdom(int $kingdomId): array
    {
        static $cache = [];
        if ($kingdomId <= 0) {
            return [];
        }

        if (!array_key_exists($kingdomId, $cache)) {
            $response          = Ork3::$Lib->kingdom->GetAwardList([
                'IsLadder'  => 'Both',
                'IsTitle'   => 'Both',
                'KingdomId' => $kingdomId,
            ]);
            $cache[$kingdomId] = self::officerNamesFromForTest($response['Awards'] ?? []);
        }

        return $cache[$kingdomId];
    }

    /**
     * The catalog a granted award's kingdomaward belongs to, from an
     * AwardsForPlayer()-shaped row (ParkId/KingdomId/KingdomAwardName). A
     * park-granted award (ParkId set) uses its park's own kingdom, not
     * at_kingdom_id — that column is blank for a park grant. Shared by
     * Model_Scrollery and Model_Player, both of which consume that same shape.
     */
    public function kingdom_for_grant_row(array $row): int
    {
        $parkId = (int) ($row['ParkId'] ?? 0);
        if ($parkId > 0) {
            static $parkCache = [];
            if (!array_key_exists($parkId, $parkCache)) {
                $parkCache[$parkId] = Ork3::$Lib->park->GetParkDetails(['ParkId' => $parkId]);
            }
            $park = is_array($parkCache[$parkId]) ? $parkCache[$parkId] : [];

            return (int) ($park['KingdomId'] ?? 0);
        }

        return (int) ($row['KingdomId'] ?? 0);
    }

    /**
     * Corrects one AwardsForPlayer()-shaped row's OfficerRole for the dangling
     * shape isDanglingRowAnOfficer() closes — see that method's docblock. A
     * resolved AwardId is left untouched: AwardsForPlayer()'s own
     * COALESCE(alias.officer_role, a.officer_role) already gave it a real answer.
     *
     * Used by Model_Scrollery (the reprint list, scroll_for_awards_id()'s
     * single-row lookup) and Model_Player (the profile Awards tab's
     * CanPrintScroll flag) — both consume Player::AwardsForPlayer() rows and both
     * had the identical dangling-award_id exposure.
     */
    public function correct_dangling_officer_role(array $row): array
    {
        if (($row['AwardId'] ?? null) !== null) {
            return $row;
        }

        $name = (string) ($row['KingdomAwardName'] ?? '');
        if (self::isDanglingRowAnOfficer($name, $this->officer_names_for_kingdom($this->kingdom_for_grant_row($row)))) {
            $row['OfficerRole'] = 'other-officer';
        }

        return $row;
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
