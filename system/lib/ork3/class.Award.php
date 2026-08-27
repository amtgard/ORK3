<?php

class Award extends Ork3
{
    public function __construct()
    {
        parent::__construct();
        $this->award = new yapo($this->db, DB_PREFIX . 'award');
    }

    /**
     * Map of ladder award_id => metadata used to detect when a player has already reached
     * the top of that ladder or earned its Master-peerage companion award.
     *
     * Sole order→master source of truth (P3-R2). UI must not fork this map.
     */
    public static function GetLadderMasterMap()
    {
        return [
            21  => ['MasterAwardIds' => [1],   'LadderName' => 'Order of the Rose',    'MasterName' => 'Master Rose',    'MaxRank' => 10],
            22  => ['MasterAwardIds' => [2],   'LadderName' => 'Order of the Smith',   'MasterName' => 'Master Smith',   'MaxRank' => 10],
            23  => ['MasterAwardIds' => [3],   'LadderName' => 'Order of the Lion',    'MasterName' => 'Master Lion',    'MaxRank' => 10],
            24  => ['MasterAwardIds' => [4],   'LadderName' => 'Order of the Owl',     'MasterName' => 'Master Owl',     'MaxRank' => 10],
            25  => ['MasterAwardIds' => [5],   'LadderName' => 'Order of the Dragon',  'MasterName' => 'Master Dragon',  'MaxRank' => 10],
            26  => ['MasterAwardIds' => [6],   'LadderName' => 'Order of the Garber',  'MasterName' => 'Master Garber',  'MaxRank' => 10],
            27  => ['MasterAwardIds' => [12],  'LadderName' => 'Order of the Warrior', 'MasterName' => 'Warlord',        'MaxRank' => 10],
            28  => ['MasterAwardIds' => [7],   'LadderName' => 'Order of the Jovius',  'MasterName' => 'Master Jovius',  'MaxRank' => 10],
            29  => ['MasterAwardIds' => [9],   'LadderName' => 'Order of the Mask',    'MasterName' => 'Master Mask',    'MaxRank' => 10],
            30  => ['MasterAwardIds' => [8],   'LadderName' => 'Order of the Zodiac',  'MasterName' => 'Master Zodiac',  'MaxRank' => 12],
            32  => ['MasterAwardIds' => [10],  'LadderName' => 'Order of the Hydra',   'MasterName' => 'Master Hydra',   'MaxRank' => 10],
            33  => ['MasterAwardIds' => [11],  'LadderName' => 'Order of the Griffin', 'MasterName' => 'Master Griffin', 'MaxRank' => 10],
            239 => ['MasterAwardIds' => [240], 'LadderName' => 'Order of the Crown',   'MasterName' => 'Master Crown',   'MaxRank' => 10],
            243 => ['MasterAwardIds' => [244], 'LadderName' => 'Order of Battle',      'MasterName' => 'Battlemaster',   'MaxRank' => 10],
        ];
    }

    /**
     * Effective-ladder SQL predicate: a kingdom may raise an award to ladder status,
     * but can never lower an official one. Additive by construction.
     *
     * Sole spelling of "is this a ladder?" for SQL. Do not fork it.
     */
    public static function LadderSql(string $ka = 'ka', string $a = 'a'): string
    {
        return 'GREATEST(IFNULL(' . $ka . '.is_ladder, 0), IFNULL(' . $a . '.is_ladder, 0))';
    }

    /**
     * Official-ladder SQL predicate — the 16 Amtgard orders. Cross-kingdom
     * comparisons (the global Ladder Grid) key on this, never on LadderSql().
     */
    public static function OfficialLadderSql(string $a = 'a'): string
    {
        return $a . '.is_ladder = 1';
    }

    /**
     * Resolve an award's maximum rank.
     *
     * Cannot be done in SQL: the official ladders' maxes live in GetLadderMasterMap(),
     * not in the database, and ka.max_level is 0 on every official row — so a SQL-only
     * COALESCE(NULLIF(ka.max_level, 0), 10) would silently demote Zodiac from 12 to 10.
     *
     * @param int $awardId     ork_award.award_id; 0 for a pure kingdom award
     * @param int $kaMaxLevel  ork_kingdomaward.max_level; 0 means unspecified
     */
    public static function MaxRankFor(int $awardId, int $kaMaxLevel = 0): int
    {
        $map = self::GetLadderMasterMap();
        if (isset($map[$awardId]['MaxRank'])) {
            return (int) $map[$awardId]['MaxRank'];
        }
        if ($kaMaxLevel > 0) {
            return min(12, $kaMaxLevel);
        }
        return 10;
    }

    /**
     * Whether the star pill should be offered: the recipient is already at or past
     * the top of this ladder, so further recognition is expressed as an unranked
     * grant rather than an out-of-range rank number.
     */
    public static function OffersStar(int $awardId, int $kaMaxLevel, int $currentRank): bool
    {
        return $currentRank >= self::MaxRankFor($awardId, $kaMaxLevel);
    }

    /**
     * Order of the Zodiac is granted once per calendar month, so its twelve positions
     * are months rather than levels. It is the only award of that nature.
     *
     * A name for a fact several call sites need — not a taxonomy over a family that
     * does not exist. See 2026-08-27-zodiac-monthly-awards-design.md.
     */
    public static function IsMonthlyLadder(int $awardId): bool
    {
        return $awardId === 30;
    }

    /** @var list<string> 1-indexed in the accessors below. */
    private const MONTH_NAMES = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    /**
     * Single-letter month label for a Zodiac pill: J F M A M J J A S O N D.
     * Ambiguous on its own by design — the full name rides along in data-tip.
     */
    public static function MonthInitial(int $month): string
    {
        $name = self::MonthName($month);

        return $name === '' ? '' : substr($name, 0, 1);
    }

    public static function MonthName(int $month): string
    {
        return self::IsValidZodiacMonth($month) ? self::MONTH_NAMES[$month - 1] : '';
    }

    public static function IsValidZodiacMonth(int $month): bool
    {
        return $month >= 1 && $month <= 12;
    }

    /**
     * The month a grant date falls in — the reconciliation pre-fill. A suggestion the
     * officer confirms, never an automatic write: a monthly award is usually granted
     * at or just after the end of the month it honours.
     */
    public static function ZodiacMonthFromDate(string $date): int
    {
        if ($date === '' || strpos($date, '0000-00-00') === 0) {
            return 0;
        }
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return 0;
        }

        return (int) date('n', $timestamp);
    }

    /**
     * class_id => Paragon award_id (Class Levels / My Amtgard badge display).
     *
     * @return array<int, int>
     */
    public static function GetClassParagonMap(): array
    {
        return [
            1 => 37, 2 => 38, 3 => 39, 4 => 40, 5 => 41, 6 => 241, 7 => 42, 8 => 43,
            9 => 44, 10 => 45, 11 => 46, 12 => 47, 14 => 242, 15 => 49, 16 => 50, 17 => 51,
        ];
    }

    /**
     * Knighthood award_id => short name (milestones + belt detection).
     * Belt image URLs remain presentation-layer (template).
     *
     * @return array<int, string>
     */
    public static function GetKnightAwardMap(): array
    {
        return [
            17 => 'Flame',
            18 => 'Crown',
            19 => 'Serpent',
            20 => 'Sword',
            245 => 'Battle',
        ];
    }

    /**
     * Flatten MasterAwardIds from GetLadderMasterMap (includes Warlord 12).
     *
     * @return list<int>
     */
    public static function GetMasterAwardIds(): array
    {
        $ids = [];
        foreach (self::GetLadderMasterMap() as $info) {
            foreach ((array)($info['MasterAwardIds'] ?? []) as $masterId) {
                $masterId = (int)$masterId;
                if ($masterId > 0) {
                    $ids[$masterId] = true;
                }
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * Paragon award_ids (values of GetClassParagonMap).
     *
     * @return list<int>
     */
    public static function GetParagonAwardIds(): array
    {
        return array_values(array_unique(array_map('intval', array_values(self::GetClassParagonMap()))));
    }

    public function LookupAward($request)
    {
        if (valid_id($request['KingdomId']) && valid_id($request['AwardId'])) {
            $kingdomaward = new yapo($this->db, DB_PREFIX . 'kingdomaward');
            $kingdomaward->clear();
            $kingdomaward->kingdom_id = $request['KingdomId'];
            $kingdomaward->award_id = $request['AwardId'];
            if (!$kingdomaward->find()) {
                // No matching kingdomaward row — return an invalid id so callers
                // don't create an orphaned award grant against a stale id.
                return array(0, $request['AwardId']);
            }
            return array($kingdomaward->kingdomaward_id, $kingdomaward->award_id);
        }
    }

    public function LookupKingdomAward($request)
    {
        if (valid_id($request['KingdomAwardId'])) {
            $kingdomaward = new yapo($this->db, DB_PREFIX . 'kingdomaward');
            $kingdomaward->clear();
            $kingdomaward->kingdomaward_id = $request['KingdomAwardId'];
            $kingdomaward->find();
            return array($kingdomaward->kingdom_id, $kingdomaward->award_id);
        }
    }

    public function GetAwardList($request)
    {
        if ($request['IsLadder'] == 'Ladder') {
            $ladder_clause = " and ka.is_ladder = 1";
        } elseif ($request['IsLadder'] == 'NonLadder') {
            $ladder_clause = " and ka.is_ladder = 0";
        }
        if ($request['IsTitle'] == 'Title') {
            $ladder_clause = " and is_title = 1";
        } elseif ($request['IsTitle'] == 'NonTitle') {
            $ladder_clause = " and is_title = 0";
        }
        if (isset($request['OfficerRole']) && $request['OfficerRole'] == 'Awards') {
            $officer_role_clause = " and officer_role = 'none'";
        } elseif (isset($request['OfficerRole']) && $request['OfficerRole'] == 'Officers') {
            $officer_role_clause = " and officer_role != 'none'";
        }
        $sql = "select award_id, name, a.award_id, a.is_ladder, is_title, title_class, a.officer_role, a.peerage
    				from " . DB_PREFIX . "award a 
    				where 1
    					$ladder_clause
    					$title_clause
            $officer_role_clause
    				order by is_ladder, a.is_title, a.title_class desc, a.name";
        $r = $this->db->query($sql);

        $response = array();
        $response['Awards'] = array();
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $response['Awards'][] = array(
                    'KingdomAwardId' => $r->award_id,
                    'KingdomAwardName' => $r->name,
                    'ReignLimit' => 0,
                    'MonthLimit' => 0,
                    'AwardName' => $r->name,
                    'AwardId' => $r->award_id,
                    'IsLadder' => $r->is_ladder,
                    'IsTitle' => $r->is_title,
                    'TitleClass' => $r->title_class,
                      'OfficerRole' => $r->officer_role,
                    'Peerage' => $r->peerage
                );
            }
            $response['Status'] = Success();
        } else {
            $response['Status'] = InvalidParameter(null, 'Problem processing your request.');
        }
        return $response;
    }

    /**
     * Awards a "Custom Title" may be aliased to: peerage-ladder rungs and other titles.
     * Drives the Add Award modal's "Alias of" dropdown on the player, kingdom, and park
     * profiles. The list is global (not kingdom-specific), so it is shared across all three.
     *
     * @return array ['Peerage' => [...], 'Titles' => [...]] of ['AwardId','Name','Peerage'] rows
     */
    public function fetch_custom_title_alias_options()
    {
        $sql = "SELECT award_id, name, peerage, is_title
    		FROM " . DB_PREFIX . "award
    		WHERE officer_role = 'none'
    		  AND name <> 'Custom Title'
    		  AND name <> 'Custom Award'
    		  AND (peerage IN ('Page','Lords-Page','Squire','Man-At-Arms','Master','Knight') OR is_title = 1)
    		ORDER BY FIELD(peerage,'Knight','Master','Squire','Man-At-Arms','Lords-Page','Page') DESC, is_title DESC, name ASC";
        $r = $this->db->query($sql);
        $peerage = [];
        $titles = [];
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $row = [
                    'AwardId' => (int)$r->award_id,
                    'Name'    => $r->name,
                    'Peerage' => $r->peerage,
                ];
                if (in_array($r->peerage, ['Page','Lords-Page','Squire','Man-At-Arms','Master','Knight'], true)) {
                    $peerage[] = $row;
                } elseif ((int)$r->is_title === 1) {
                    $titles[] = $row;
                }
            }
        }
        return ['Peerage' => $peerage, 'Titles' => $titles];
    }

    public function CreateAward($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_CREATE)) {
            $this->log->Write('Award', $mundane_id, LOG_ADD, $request);
            $this->award->clear();
            $this->award->name = $request['Name'];
            $this->award->is_ladder = $request['IsLadder'];
            $this->award->is_title = $request['IsTitle'];
            $this->award->title_class = $request['TitleClass'];
            $this->award->peerage = $request['Peerage'];
            $this->award->officer_role = $request['OfficerRole'];
            $this->award->save();
        } else {
            return NoAuthorization();
        }
    }

    public function EditAward($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
            $this->log->Write('Award', $mundane_id, LOG_EDIT, $request);
            $this->award->clear();
            $this->award->award_id = $request['AwardId'];
            if ($this->kingdomaward->find()) {
                $this->award->name = $request['Name'];
                $this->award->is_ladder = $request['IsLadder'];
                $this->award->is_title = $request['IsTitle'];
                $this->award->title_class = $request['TitleClass'];
                $this->award->peerage = $request['Peerage'];
                $this->award->officer_role = $request['OfficerRole'];
                $this->award->award->save();

            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

    public function RemoveAward($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
            $this->log->Write('Award', $mundane_id, LOG_REMOVE, $request);
            $this->award->award_id = $request['AwardId'];
            if ($this->award->find()) {
                $this->award->delete();
            }
            return Success();
        }
        return NoAuthorization();
    }

    public function create_system_awards()
    {

        $this->create_award('Master Rose', 0, 1, 10, 'Master');
        $this->create_award('Master Smith', 0, 1, 10, 'Master');
        $this->create_award('Master Lion', 0, 1, 10, 'Master');
        $this->create_award('Master Owl', 0, 1, 10, 'Master');
        $this->create_award('Master Dragon', 0, 1, 10, 'Master');
        $this->create_award('Master Garber', 0, 1, 10, 'Master');
        $this->create_award('Master Jovius', 0, 1, 10);
        $this->create_award('Master Zodiac', 0, 1, 10);
        $this->create_award('Master Mask', 0, 1, 10);
        $this->create_award('Master Hydra', 0, 1, 10);
        $this->create_award('Master Griffin', 0, 1, 10);
        $this->create_award('Warlord', 0, 1, 10, 'Master');

        $this->create_award("Lord's Page", 0, 1, 5, 'Lords-Page');
        $this->create_award('Man-at-Arms', 0, 1, 5, 'Man-at-Arms');
        $this->create_award('Page', 0, 1, 5, 'Page');
        $this->create_award('Squire', 0, 1, 15, 'Squire');

        $this->create_award('Knight of the Flame', 0, 1, 20, 'Knight');
        $this->create_award('Knight of the Crown', 0, 1, 20, 'Knight');
        $this->create_award('Knight of the Serpent', 0, 1, 20, 'Knight');
        $this->create_award('Knight of the Sword', 0, 1, 20, 'Knight');

        $this->create_award('Order of the Rose', 1, 0, 0);
        $this->create_award('Order of the Smith', 1, 0, 0);
        $this->create_award('Order of the Lion', 1, 0, 0);
        $this->create_award('Order of the Owl', 1, 0, 0);
        $this->create_award('Order of the Dragon', 1, 0, 0);
        $this->create_award('Order of the Garber', 1, 0, 0);
        $this->create_award('Order of the Warrior', 1, 0, 0);
        $this->create_award('Order of the Jovius', 1, 0, 0);
        $this->create_award('Order of the Mask', 1, 0, 0);
        $this->create_award('Order of the Zodiac', 1, 0, 0);
        $this->create_award('Order of the Walker in the Middle', 1, 0, 0);
        $this->create_award('Order of the Hydra', 1, 0, 0);
        $this->create_award('Order of the Griffin', 1, 0, 0);
        $this->create_award('Order of the Flame', 1, 0, 0);

        $this->create_award('Defender', 0, 1, 10);
        $this->create_award('Weaponmaster', 0, 1, 10);

        $this->create_award('Master Anti-Paladin', 0, 1, 10);
        $this->create_award('Master Archer', 0, 1, 10);
        $this->create_award('Master Assassin', 0, 1, 10);
        $this->create_award('Master Barbarian', 0, 1, 10);
        $this->create_award('Master Bard', 0, 1, 10);
        $this->create_award('Master Druid', 0, 1, 10);
        $this->create_award('Master Healer', 0, 1, 10);
        $this->create_award('Master Monk', 0, 1, 10);
        $this->create_award('Master Monster', 0, 1, 10);
        $this->create_award('Master Paladin', 0, 1, 10);
        $this->create_award('Master Peasant', 0, 1, 10);
        $this->create_award('Master Raider', 0, 1, 10);
        $this->create_award('Master Scout', 0, 1, 10);
        $this->create_award('Master Warrior', 0, 1, 10);
        $this->create_award('Master Wizard', 0, 1, 10);

        $this->create_award('Lord', 0, 1, 30);
        $this->create_award('Lady', 0, 1, 30);
        $this->create_award('Baronet', 0, 1, 40);
        $this->create_award('Baronetess', 0, 1, 40);
        $this->create_award('Baron', 0, 1, 50);
        $this->create_award('Baroness', 0, 1, 50);
        $this->create_award('Viscount', 0, 1, 60);
        $this->create_award('Viscountess', 0, 1, 60);
        $this->create_award('Count', 0, 1, 70);
        $this->create_award('Countess', 0, 1, 70);
        $this->create_award('Marquis', 0, 1, 80);
        $this->create_award('Marquess', 0, 1, 80);
        $this->create_award('Duke', 0, 1, 90);
        $this->create_award('Duchess', 0, 1, 90);
        $this->create_award('Archduke', 0, 1, 100);
        $this->create_award('Archduchess', 0, 1, 100);
        $this->create_award('Grand Duke', 0, 1, 110);
        $this->create_award('Grand Duchess', 0, 1, 110);

        $this->create_award('Sheriff', 0, 0, 0);
        $this->create_award('Provincial Baron', 0, 0, 0);
        $this->create_award('Provincial Baroness', 0, 0, 0);
        $this->create_award('Provincial Duke', 0, 0, 0);
        $this->create_award('Provincial Duchess', 0, 0, 0);
        $this->create_award('Provincial Grand Duke', 0, 0, 0);
        $this->create_award('Provincial Grand Duchess', 0, 0, 0);

        $this->create_award('Shire Regent', 0, 0, 0);
        $this->create_award('Baronial Regent', 0, 0, 0);
        $this->create_award('Ducal Regent', 0, 0, 0);
        $this->create_award('Grand Ducal Regent', 0, 0, 0);

        $this->create_award('Shire Clerk', 0, 0, 0);
        $this->create_award('Baronial Seneschal', 0, 0, 0);
        $this->create_award('Ducal Chancellor', 0, 0, 0);
        $this->create_award('Grand Ducal General Minister', 0, 0, 0);

        $this->create_award('Provincial Champion', 0, 0, 0);
        $this->create_award('Baronial Champion', 0, 0, 0);
        $this->create_award('Ducal Defender', 0, 0, 0);
        $this->create_award('Grand Ducal Defender', 0, 0, 0);

        $this->create_award('Kingdom Champion', 0, 0, 0);
        $this->create_award('Kingdom Regent', 0, 0, 0);
        $this->create_award('Kingdom Prime Minister', 0, 0, 0);
        $this->create_award('Kingdom Monarch', 0, 0, 0);

        $this->create_award('Director of the Board', 0, 0, 0);

        $this->create_award('Custom Award', 0, 0, 0);
    }

    public function create_award($name, $is_ladder, $is_title, $title_class, $peerage = 'None', $officer_role = 'none')
    {
        $this->award->clear();
        $this->award->name = $name;
        $this->award->is_ladder = $is_ladder;
        $this->award->is_title = $is_title;
        $this->award->title_class = $title_class;
        $this->award->peerage = $peerage;
        $this->award->officer_role = $officer_role;
        $this->award->save();
    }

    /**
     * Structured award dropdown groups for UI rendering (T-AWD-01).
     *
     * @return array<string, mixed>
     */
    public function GetAwardOptionGroups($request)
    {
        $kingdomId = valid_id($request['KingdomId'] ?? 0) ? (int) $request['KingdomId'] : 0;
        $officerRole = $request['OfficerRole'] ?? null;

        if ($kingdomId > 0) {
            $kingdom = new Kingdom();
            $awards = $kingdom->GetAwardList([
                'IsLadder' => null,
                'IsTitle' => null,
                'KingdomId' => $kingdomId,
                'OfficerRole' => $officerRole,
            ]);
        } else {
            $awards = $this->GetAwardList([
                'IsLadder' => null,
                'IsTitle' => null,
                'OfficerRole' => $officerRole,
            ]);
        }

        if (($awards['Status']['Status'] ?? 1) != 0) {
            return ['Status' => $awards['Status'], 'Groups' => [], 'StandaloneOptions' => []];
        }

        $items = $awards['Awards'] ?? [];
        usort($items, static function (array $a, array $b): int {
            return strcmp($a['KingdomAwardName'] ?? '', $b['KingdomAwardName'] ?? '');
        });

        $custom = $officialLadder = $kingdomLadder = $knighthoods = $masterhoods = $paragons = $associates = $nobles = $other = [];

        foreach ($items as $award) {
            $sysName = $award['AwardName'] ?? $award['KingdomAwardName'];
            // OfficialIsLadder (a.is_ladder = 1: one of the 16 official Amtgard orders) wins
            // ties -- requirement 1, a kingdom can never lower an official ladder. Kingdom::
            // GetAwardList() (kingdomId > 0) carries OfficialIsLadder/KaIsLadder per row. The
            // system-award list (kingdomId == 0, Award::GetAwardList()) has no kingdom row at
            // all, so its IsLadder (== a.is_ladder there) doubles as official, and there is no
            // KaIsLadder key to fall back on -- absence must never silently read as "official".
            $isOfficialLadder = !empty($award['OfficialIsLadder'] ?? $award['IsLadder'] ?? 0);
            $isKingdomLadder = !$isOfficialLadder && !empty($award['KaIsLadder'] ?? 0);
            if ($isOfficialLadder) {
                $officialLadder[] = $award;
            } elseif ($isKingdomLadder) {
                $kingdomLadder[] = $award;
            } elseif ($sysName === 'Custom Award' || $sysName === 'Custom Title') {
                $custom[] = $award;
            } elseif (in_array($sysName, ['Defender', 'Master'], true)) {
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
            } elseif (in_array($award['Peerage'] ?? '', ['Squire', 'Man-At-Arms', 'Page', 'Lords-Page'], true)
                || $sysName === 'Apprentice') {
                $associates[] = $award;
            } elseif ((!empty($award['IsTitle']) && ($award['TitleClass'] ?? 0) >= 30)
                || $sysName === 'Esquire') {
                $nobles[] = $award;
            } else {
                $other[] = $award;
            }
        }

        $groups = [];
        // Requirement 4: official and kingdom ladders must be visibly distinguishable
        // everywhere, so this is two groups instead of one shared "Ladder Awards" bucket.
        if ($officialLadder !== []) {
            $groups[] = ['Label' => 'Official Ladder Awards', 'Items' => $officialLadder];
        }
        if ($kingdomLadder !== []) {
            $groups[] = ['Label' => 'Kingdom Ladder Awards', 'Items' => $kingdomLadder];
        }
        foreach ([
            'Knighthoods' => $knighthoods,
            'Masterhoods' => $masterhoods,
            'Paragons' => $paragons,
            'Noble Titles' => $nobles,
            'Associate Titles' => $associates,
            'Other' => $other,
        ] as $label => $groupItems) {
            if ($groupItems !== []) {
                $groups[] = ['Label' => $label, 'Items' => $groupItems];
            }
        }

        return [
            'Status' => Success(),
            'Groups' => $groups,
            'StandaloneOptions' => $custom,
        ];
    }

    public function GetAwardOptionListHtml(int $kingdomId = 0, $officerRole = null)
    {
        $cacheKey = Ork3::$Lib->ghettocache->key([
            'KingdomId' => (int) $kingdomId,
            'OfficerRole' => $officerRole,
            // Version 1: data-max-rank attribute added to <option> elements (2026-08-27).
            // Bump this when emitted <option> markup changes.
            'v' => 1,
        ]);
        if (($cached = Ork3::$Lib->ghettocache->get(__CLASS__ . '.GetAwardOptionListHtml', $cacheKey, 1200)) !== false) {
            return $cached;
        }

        $grouped = $this->GetAwardOptionGroups([
            'KingdomId' => (int) $kingdomId,
            'OfficerRole' => $officerRole,
        ]);
        if (($grouped['Status']['Status'] ?? 1) != 0) {
            return false;
        }

        $options = '';

        foreach ($grouped['Groups'] ?? [] as $group) {
            $label = $group['Label'] ?? '';
            $items = $group['Items'] ?? [];
            if ($items === []) {
                continue;
            }
            $options .= "<optgroup label='" . htmlspecialchars($label, ENT_QUOTES) . "'>";
            foreach ($items as $award) {
                $extra = '';
                if ($label === 'Kingdom Ladder Awards') {
                    // No official award backs this rung -- data-award-id stays 0.
                    $extra = " data-is-ladder='1' data-award-id='0'";
                } elseif ($label === 'Official Ladder Awards') {
                    $extra = " data-is-ladder='1' data-award-id='" . htmlspecialchars((int) ($award['AwardId'] ?? 0), ENT_QUOTES) . "'";
                } elseif ($label === 'Masterhoods') {
                    $extra = " data-award-id='" . htmlspecialchars((int) ($award['AwardId'] ?? 0), ENT_QUOTES) . "' data-peerage='Master'";
                }
                // The client must never guess a ladder's height from the award NAME
                // (see Award::MaxRankFor) -- every option carries the real answer.
                $maxRank = self::MaxRankFor((int) ($award['AwardId'] ?? 0), (int) ($award['MaxLevel'] ?? 0));
                $extra .= " data-max-rank='" . $maxRank . "'";
                $options .= "<option value='" . htmlspecialchars($award['KingdomAwardId'], ENT_QUOTES) . "'{$extra}>" . htmlspecialchars($award['KingdomAwardName'], ENT_QUOTES) . "</option>";
            }
            $options .= "</optgroup>";
        }

        foreach ($grouped['StandaloneOptions'] ?? [] as $award) {
            $sysName = $award['AwardName'] ?? $award['KingdomAwardName'];
            $kaName = $award['KingdomAwardName'] ?? $sysName;
            $dataAttrs = '';
            if ($sysName === 'Custom Title' && $kaName === 'Custom Title') {
                $dataAttrs = " data-custom-title='1' data-award-id='" . htmlspecialchars($award['AwardId'], ENT_QUOTES) . "'";
            } elseif ($sysName === 'Custom Award' && $kaName === 'Custom Award') {
                $dataAttrs = " data-custom-award='1' data-award-id='" . htmlspecialchars($award['AwardId'], ENT_QUOTES) . "'";
            }
            $dataAttrs .= " data-max-rank='" . self::MaxRankFor((int) ($award['AwardId'] ?? 0), (int) ($award['MaxLevel'] ?? 0)) . "'";
            $options .= "<option value='" . htmlspecialchars($award['KingdomAwardId'], ENT_QUOTES) . "'" . $dataAttrs . ">" . htmlspecialchars($kaName, ENT_QUOTES) . "</option>";
        }

        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.GetAwardOptionListHtml', $cacheKey, $options);
    }
}
