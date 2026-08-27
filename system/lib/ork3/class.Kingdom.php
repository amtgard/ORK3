<?php

class Kingdom extends Ork3
{
    // Per-request memo caches for the principality-rollup helpers. These are read
    // dozens of times per kingdom-scoped report page; the Kingdom lib is a single
    // instance per request (see startup.php), so caching here is safe and avoids
    // redundant DB hits — most notably for ordinary kingdoms that have no children.
    private $childPrincipalityCache = array();
    private $statsIncludesPrincipalityCache = array();

    public function __construct()
    {
        parent::__construct();
        $this->kingdom = new yapo($this->db, DB_PREFIX . 'kingdom');
        $this->kingdomaward = new yapo($this->db, DB_PREFIX . 'kingdomaward');
    }

    public function GetKingdomByAbbreviation($request)
    {
        if (trimlen($request['Abbreviation']) < 2 || trimlen($request['Abbreviation']) > 3) {
            return null;
        }

        $this->kingdom->clear();
        $this->kingdom->abbreviation = strtoupper(trim($request['Abbreviation']));
        if ($this->kingdom->find()) {
            return $this->kingdom->kingdom_id;
        }
        return null;
    }

    /**
     * Headline numbers and the work queue for the kingdom admin console.
     *
     * Two different jobs, deliberately in one call because they share the scope
     * and are all cheap COUNTs: "Standing" is how the kingdom is doing, "Queue"
     * is what is waiting on the officer reading the page. Every Queue entry is a
     * set someone can actually work, and every count describes exactly the set
     * the card's drill-through opens -- the unwaivered count therefore matches
     * the unwaivered roster report (active players with no waiver) rather than
     * applying an activity window the report cannot express.
     *
     * Scope differs by half, on purpose: "Standing" follows GetStatsKingdomIds()
     * so it folds in child principalities when IncludePrincipalityInStatistics
     * is on, while "Queue" stays kingdom-only because a principality's officers
     * work their own queue.
     *
     * Note this does NOT match the park-averages summary rendered beside it:
     * Report::GetKingdomParkAverages() is deliberately parent-only (park LISTS
     * stay parent-only; only aggregates fold). On a parent kingdom with the
     * toggle on, hero Parks will therefore read HIGHER than that summary. That
     * is the project's rule, not a defect -- do not "reconcile" them by folding
     * principality parks into a park list.
     *
     * One Queue member is not a work queue at all: 'WaiveredMembers' is the blast
     * radius for the Reset Waivers action, so the confirm modal can say how many
     * players are about to be cleared. It is scoped exactly like that UPDATE.
     *
     * Officer roles are matched on both stored forms (display name and canonical
     * key) because ork_officer.role migrated from one to the other and the column
     * collation hides the difference in SQL but not in PHP.
     *
     * The thresholds the counts use are returned in 'Windows' so callers can
     * describe them without restating literals that would drift from the SQL.
     *
     * @param int $kingdom_id
     * @return array  ['Standing' => [...], 'Queue' => [...], 'Windows' => [...]]
     */
    public function GetAdminDashboard($kingdom_id)
    {
        global $DB;
        $kingdom_id = (int) $kingdom_id;
        $quietParkDays = 60;
        $coreOffices   = ['Monarch', 'Regent', 'Prime Minister', 'Champion', 'GMR'];
        $out = [
            'Standing' => ['Parks' => 0, 'ActivePlayers' => 0, 'AttendanceYtd' => 0],
            'Queue'    => ['OpenRecommendations' => 0, 'UnwaiveredActive' => 0, 'QuietParks' => 0, 'VacantCrownOffices' => 0, 'WaiveredMembers' => 0],
            'Windows'  => [
                'QuietParkDays'     => $quietParkDays,
                'CrownOffices'      => $coreOffices,
                'CrownOfficeCount'  => count($coreOffices),
            ],
        ];
        if ($kingdom_id <= 0) {
            return $out;
        }

        // ---- Standing -------------------------------------------------------
        // Standing follows the kingdom-vs-principality stat scope, like every other
        // aggregate. See the docblock: the park-averages summary on the same page
        // is parent-only by design, so these two legitimately disagree.
        $statIds    = array_map('intval', $this->GetStatsKingdomIds($kingdom_id));
        $statIdList = implode(', ', $statIds);
        $DB->Clear();
        $r = $DB->DataSet(
            "SELECT
                (SELECT COUNT(*) FROM " . DB_PREFIX . "park
                  WHERE kingdom_id IN (" . $statIdList . ") AND active = 'Active') AS parks,
                (SELECT COUNT(DISTINCT a.mundane_id) FROM " . DB_PREFIX . "attendance a
                  WHERE a.kingdom_id IN (" . $statIdList . ")
                    AND a.date >= (CURDATE() - INTERVAL 182 DAY)) AS active_players,
                (SELECT COUNT(*) FROM " . DB_PREFIX . "attendance a
                  WHERE a.kingdom_id IN (" . $statIdList . ")
                    AND a.date_year = YEAR(CURDATE())) AS attendance_ytd"
        );
        if ($r !== false && $r->Next()) {
            $out['Standing']['Parks']         = (int) $r->parks;
            $out['Standing']['ActivePlayers'] = (int) $r->active_players;
            $out['Standing']['AttendanceYtd'] = (int) $r->attendance_ytd;
        }

        // ---- Queue ----------------------------------------------------------
        // "Open" for a recommendation is deleted_at IS NULL, matching the Recs
        // Manager; snoozed and passed-to-local rows are somebody else's problem.
        $DB->Clear();
        $r = $DB->DataSet(
            "SELECT
                (SELECT COUNT(*) FROM " . DB_PREFIX . "recommendations rc
                    JOIN " . DB_PREFIX . "mundane rm ON rm.mundane_id = rc.mundane_id
                  WHERE rm.kingdom_id = " . $kingdom_id . "
                    AND rc.deleted_at IS NULL
                    AND COALESCE(rc.snoozed_by_id, 0) = 0
                    AND COALESCE(rc.passed_to_local, 0) = 0) AS open_recs,
                (SELECT COUNT(*) FROM " . DB_PREFIX . "mundane m
                  WHERE m.kingdom_id = " . $kingdom_id . "
                    AND m.waivered = 0 AND m.active = 1) AS unwaivered_active,
                (SELECT COUNT(*) FROM " . DB_PREFIX . "mundane m
                  WHERE m.kingdom_id = " . $kingdom_id . "
                    AND m.waivered = 1) AS waivered_members"
        );
        if ($r !== false && $r->Next()) {
            $out['Queue']['OpenRecommendations'] = (int) $r->open_recs;
            $out['Queue']['UnwaiveredActive']    = (int) $r->unwaivered_active;
            // Blast radius for Reset Waivers. Player::ResetWaivers scopes its
            // UPDATE to kingdom_id + waivered = 1 with NO active filter, so this
            // count deliberately omits `active` too -- adding one here would
            // under-report what the reset is about to clear.
            $out['Queue']['WaiveredMembers']     = (int) $r->waivered_members;
        }

        // Parks with nothing recorded in the quiet window. Grouped rather than NOT EXISTS so
        // a park with zero attendance rows at all is still counted.
        $DB->Clear();
        $r = $DB->DataSet(
            "SELECT COUNT(*) AS quiet FROM (
                SELECT pk.park_id
                  FROM " . DB_PREFIX . "park pk
                  LEFT JOIN " . DB_PREFIX . "attendance a
                         ON a.park_id = pk.park_id
                        AND a.date >= (CURDATE() - INTERVAL " . $quietParkDays . " DAY)
                 WHERE pk.kingdom_id = " . $kingdom_id . " AND pk.active = 'Active'
                 GROUP BY pk.park_id
                HAVING COUNT(a.attendance_id) = 0
             ) q"
        );
        if ($r !== false && $r->Next()) {
            $out['Queue']['QuietParks'] = (int) $r->quiet;
        }

        // Vacant crown offices. Counts DISTINCT filled offices against the Core
        // Five rather than the position registry, so this answers correctly on a
        // database where officer-position.sql has not been applied yet.
        $core = PermissionRegistry::OfficerRoleVariants($coreOffices);
        $quoted = [];
        foreach ($core as $variant) {
            $quoted[] = "'" . str_replace("'", "''", (string) $variant) . "'";
        }
        if (!empty($quoted)) {
            $DB->Clear();
            $r = $DB->DataSet(
                "SELECT COUNT(DISTINCT LOWER(REPLACE(o.role, ' ', '_'))) AS filled
                   FROM " . DB_PREFIX . "officer o
                  WHERE o.kingdom_id = " . $kingdom_id . "
                    AND o.park_id = 0
                    AND o.mundane_id > 0
                    AND o.role IN (" . implode(', ', $quoted) . ")"
            );
            if ($r !== false && $r->Next()) {
                $out['Queue']['VacantCrownOffices'] = max(0, count($coreOffices) - (int) $r->filled);
            }
        }

        return $out;
    }

    public function GetKingdomShortInfo($request)
    {
        $this->kingdom->clear();
        $this->kingdom->kingdom_id = $request['KingdomId'];
        $response = array();
        if ($this->kingdom->find()) {
            $response['Status'] = Success();
            $response['KingdomInfo'] = array();
            $response['KingdomInfo']['KingdomId'] = $this->kingdom->kingdom_id;
            $response['KingdomInfo']['KingdomName'] = $this->kingdom->name;
            $response['KingdomInfo']['Abbreviation'] = $this->kingdom->abbreviation;
            $response['KingdomInfo']['HasHeraldry'] = $this->kingdom->has_heraldry;
            global $DB;
            $DB->Clear();
            $_bn = $DB->DataSet("SELECT has_banner, banner_show_logo, banner_vignette, banner_offset_x, banner_offset_y FROM ork_kingdom WHERE kingdom_id = " . (int)$this->kingdom->kingdom_id);
            if ($_bn && $_bn->Next()) {
                $response['KingdomInfo']['HasBanner']      = (int)$_bn->has_banner;
                $response['KingdomInfo']['BannerShowLogo'] = (int)$_bn->banner_show_logo;
                $response['KingdomInfo']['BannerVignette'] = (int)$_bn->banner_vignette;
                $response['KingdomInfo']['BannerOffsetX']  = (int)$_bn->banner_offset_x;
                $response['KingdomInfo']['BannerOffsetY']  = (int)$_bn->banner_offset_y;
            }
            $response['KingdomInfo']['IsPrincipality'] = $this->kingdom->parent_kingdom_id > 0 ? 1 : 0;
            $response['KingdomInfo']['ParentKingdomId'] = $this->kingdom->parent_kingdom_id;
            $response['KingdomInfo']['Active'] = $this->kingdom->active;
            $response['KingdomInfo']['Description'] = $this->kingdom->description ?? '';
            $response['KingdomInfo']['Url'] = $this->kingdom->url ?? '';
        } else {
            $response['Status'] = InvalidParameter();
        }
        return $response;
    }

    /*
        public function SetKingdomAwards($request) {
            $response = array();
            if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                    && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'kingdom.award.edit', 'kingdom', $request['KingdomId'], AUTH_EDIT)) {
                $this->log->Write('Kingdom', $mundane_id, LOG_EDIT, $request);
                if (is_array($request['KingdomAwards'])) {
                        $this->kingdomaward->clear();
                        $this->kingdomaward->award_id = $kingdomaward['AwardId'];
                        $this->kingdomaward->kingdom_id = $request['KingdomId'];
                        switch ($kingdomaward['Action']) {
                            case CFG_REMOVE:
                                if (valid_id($request['AwardId']) && $this->kingdomaward->find()) {
                                    if (valid_id($this->kingdomaward->award_id)) {
                                        $response['Status'] = InvalidParameter('You may not delete basic Awards.  Take it up with the CoM.');
                                        return $response;
                                    }

                                    $awards = new yapo($this->db, DB_PREFIX . 'mundane_award');
                                    $award->clear();
                                    $awards->kingdomaward_id = $this->kingdomaward->kingdomaward_id;
                                    if (valid_id($request['AwardId'] && $awards->find())) {
                                        $response['Status'] = InvalidParameter('You may not delete basic a Kingdom Award which is assigned to a Player.  Remove all awards first.');
                                        return $response;
                                    }

                                    $this->kingdomaward->delete();
                                }
                                break;
                            case CFG_EDIT:
                                if (valid_id($request['AwardId']) && $this->kingdomaward->find()) {
                                    $this->kingdomaward->name = trimlen($kingdomaward['Name'])>0?$kingdomaward['Name']:$this->kingdomaward->name;
                                    $this->kingdomaward->reign_limit = trimlen($kingdomaward['ReignLimit'])>0?$kingdomaward['ReignLimit']:$this->kingdomaward->reign_limit;
                                    $this->kingdomaward->month_limit = trimlen($kingdomaward['MonthLimit'])>0?$kingdomaward['MonthLimit']:$this->kingdomaward->month_limit;
                                    $this->kingdomaward->is_title = trimlen($kingdomaward['IsTitle'])>0?$kingdomaward['IsTitle']:$this->kingdomaward->title;
                                    $this->kingdomaward->title_class = trimlen($kingdomaward['TitleClass'])>0?$kingdomaward['TitleClass']:$this->kingdomaward->title_class;
                                    $this->kingdomaward->save();
                                }
                                break;
                            case CFG_ADD:
                                    $this->kingdomaward->name = $kingdomaward['Name'];
                                    $this->kingdomaward->reign_limit = $kingdomaward['ReignLimit'];
                                    $this->kingdomaward->month_limit = $kingdomaward['MonthLimit'];
                                    $this->kingdomaward->is_title = $kingdomaward['IsTitle'];
                                    $this->kingdomaward->title_class = $kingdomaward['TitleClass'];
                                    $this->kingdomaward->save();
                                break;
                        }
                    }
                }
                $response = Success();
            } else {
                $response = NoAuthorization(null, $mundane_id);
            }
            return $response;
        }
    */
    private static function awardNameLooksLikeOfficer($name)
    {
        if (!is_string($name) || $name === '') {
            return false;
        }
        $prefix = '(Provincial|Baronial|Ducal|Grand\s+Ducal|Shire|Kingdom|Imperial|Principality|Barony|Duchy|Grand\s+Duchy)';
        $suffix = '(Monarch|Regent|Prime\s+Minister|Champion|Defender|Seneschal|Chancellor|Clerk|GMR|Guildmaster\s+of\s+Reeves|Guild\s+Master\s+of\s+Reeves|General\s+Minister|Sheriff|Baron(ess)?|Grand\s+Duke|Grand\s+Duchess|Duke|Duchess)';
        return preg_match('/^' . $prefix . '\s+' . $suffix . '\b/i', trim($name)) === 1;
    }

    /**
     * Kingdom award catalog.
     *
     * Disabled (soft-deleted) awards are EXCLUDED by default. This list is what
     * every "give an award" picker is ultimately built from -- directly, and via
     * Award::GetAwardOptionGroups() -- so the safe default is to omit anything a
     * kingdom has retired. Pass IncludeDisabled => true only from a management
     * surface that needs to show retired awards so they can be re-enabled; each
     * row carries a 'Disabled' flag so such a surface can mark them.
     *
     * Already-granted awards do NOT read their name through here -- they resolve
     * it by joining kingdomaward on the grant row (see Player/Report), which is
     * unfiltered -- so retiring an award never blanks a name on a player record.
     */
    public function GetAwardList($request)
    {
        $ladder_clause = '';
        $title_clause  = '';
        if ($request['IsLadder'] == 'Ladder') {
            $ladder_clause = " and " . Award::LadderSql() . " = 1";
        } elseif ($request['IsLadder'] == 'NonLadder') {
            $ladder_clause = " and " . Award::LadderSql() . " = 0";
        }
        if ($request['IsTitle'] == 'Title') {
            $ladder_clause = " and is_title = 1";
        } elseif ($request['IsTitle'] == 'NonTitle') {
            $ladder_clause = " and is_title = 0";
        }
        $disabled_clause = empty($request['IncludeDisabled']) ? " and ka.disabled = 0" : "";
        $kingdom_id = (int) $request['KingdomId'];
        $sql = "select kingdomaward_id, ifnull(ka.name, a.name) as kingdom_awardname, ka.reign_limit, ka.month_limit, a.name as award_name,
						a.award_id, " . Award::LadderSql() . " as is_ladder, IFNULL(ka.is_ladder, 0) as ka_is_ladder, IFNULL(" . Award::OfficialLadderSql() . ", 0) as official_is_ladder, IFNULL(ka.max_level, 0) as max_level,
                    ka.is_title as is_title, ka.title_class as title_class,
            ka.disabled as disabled, a.officer_role, a.peerage
					from " . DB_PREFIX . "kingdomaward ka
						left join " . DB_PREFIX . "award a on ka.award_id = a.award_id and ka.kingdom_id = " . $kingdom_id . "
					where 1
						$ladder_clause
						$title_clause
						$disabled_clause
						and ka.kingdom_id = " . $kingdom_id . "
					order by is_ladder, ka.is_title, ka.title_class desc, ka.name, a.name";
        $r = $this->db->query($sql);

        logtrace('GetAwardList', array($sql, $request));
        $response = array();
        if ($r !== false && $r->size() > 0) {
            $response['Awards'] = array();
            while ($r->next()) {
                $isOfficerRole = !in_array($r->officer_role, ['none', null]);
                // Some kingdomaward rows are mapped to a non-officer system award (e.g. Custom Award)
                // or are orphaned (LEFT JOIN -> NULL officer_role) but are clearly officer titles by
                // name — e.g. "Baronial Guild Master of Reeves", "Imperial Monarch", "Shire Regent".
                // Treat those as officers so they bucket into the Officers list, not Awards.
                if (!$isOfficerRole && self::awardNameLooksLikeOfficer($r->kingdom_awardname)) {
                    $isOfficerRole = true;
                }
                if (isset($request['OfficerRole']) && $request['OfficerRole'] == 'Awards' && $isOfficerRole) {
                    continue;
                } elseif (isset($request['OfficerRole']) && $request['OfficerRole'] == 'Officers' && !$isOfficerRole) {
                    continue;
                }

                $response['Awards'][$r->kingdomaward_id] = array(
                    'KingdomAwardId' => $r->kingdomaward_id,
                    'KingdomAwardName' => $r->kingdom_awardname,
                    'ReignLimit' => $r->reign_limit,
                    'MonthLimit' => $r->month_limit,
                    'AwardName' => $r->award_name,
                    'AwardId' => $r->award_id,
                    'IsLadder' => $r->is_ladder,
                    'KaIsLadder' => (int) $r->ka_is_ladder,
                    'OfficialIsLadder' => (int) $r->official_is_ladder,
                    'MaxLevel' => (int) $r->max_level,
                    'IsTitle' => $r->is_title,
                    'TitleClass' => $r->title_class,
                    'Disabled' => (int) $r->disabled,
                    'OfficerRole' => $r->officer_role,
                    'Peerage' => $r->peerage
                );
            }
            $response['Status'] = Success();
        } else {
            $response['Status'] = InvalidParameter(null, 'Problem processing request.');
        }
        return $response;
    }

    public function CreateAward($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'kingdom.award.create', 'kingdom', $request['KingdomId'], AUTH_CREATE)) {
            $this->log->Write('Award', $mundane_id, LOG_ADD, $request);
            $this->kingdomaward->clear();
            $this->kingdomaward->kingdom_id = $request['KingdomId'];
            $this->kingdomaward->award_id = $request['AwardId'];
            $this->kingdomaward->name = $request['Name'];
            $this->kingdomaward->reign_limit = $request['ReignLimit'];
            $this->kingdomaward->month_limit = $request['MonthLimit'];
            $this->kingdomaward->is_title = $request['IsTitle'];
            $this->kingdomaward->title_class = $request['TitleClass'];
            $this->kingdomaward->disabled = 0;

            // Same guard as EditAward (requirement 1): an "Add Award Alias" can point
            // at one of the 16 official Amtgard ladders, and that award's ladder
            // configuration belongs to Amtgard, not the kingdom. Skip the write and let
            // the column default (0) stand -- never let a kingdom seed a custom
            // is_ladder/max_level onto an alias of an official ladder.
            $awardId = (int) ($request['AwardId'] ?? 0);
            $officialLadder = false;
            if ($awardId > 0) {
                $this->db->Clear();
                $officialRs = $this->db->DataSet(
                    'select IFNULL(is_ladder, 0) as official_is_ladder from ' . DB_PREFIX . 'award where award_id = ' . $awardId
                );
                if ($officialRs && $officialRs->Next()) {
                    $officialLadder = (int) $officialRs->official_is_ladder === 1;
                }
            }

            if (!$officialLadder) {
                $isLadder = isset($request['IsLadder']) && (int) $request['IsLadder'] === 1 ? 1 : 0;
                $this->kingdomaward->is_ladder = $isLadder;
                if ($isLadder === 1) {
                    // Ladder and Title? are mutually exclusive.
                    $this->kingdomaward->is_title = 0;
                }

                $maxLevel = (int) ($request['MaxLevel'] ?? 0);
                if ($maxLevel < 0) {
                    $maxLevel = 0;
                }
                $this->kingdomaward->max_level = min(12, $maxLevel); // Rule 2
            }

            $this->kingdomaward->save();

            // This used to fall off the end and return null, so every caller that
            // tested the result saw "no error" whether or not the insert landed.
            return Success();
        } else {
            return NoAuthorization();
        }
    }

    public function EditAward($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) <= 0) {
            return NoAuthorization();
        }

        // Load the award FIRST, then authorize against the kingdom that actually
        // owns it. Authorizing against the caller-supplied KingdomId is not a
        // guard: yapo drops every non-PK field from the WHERE clause once the
        // primary key is set, so seeding kingdom_id before find() filters
        // nothing and the row is fetched by kingdomaward_id alone.
        $this->kingdomaward->clear();
        $this->kingdomaward->kingdomaward_id = $request['KingdomAwardId'];
        if (!$this->kingdomaward->find()) {
            return InvalidParameter();
        }
        $owning_kingdom_id = (int)$this->kingdomaward->kingdom_id;

        if (!Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'kingdom.award.edit', 'kingdom', $owning_kingdom_id, AUTH_CREATE)) {
            return NoAuthorization();
        }

        $this->log->Write('Award', $mundane_id, LOG_EDIT, $request);
        // kingdom_id is deliberately NOT reassigned -- an edit must never
        // re-parent an award into another kingdom.
        $this->kingdomaward->name = $request['Name'];
        $this->kingdomaward->reign_limit = $request['ReignLimit'];
        $this->kingdomaward->month_limit = $request['MonthLimit'];
        $this->kingdomaward->is_title = $request['IsTitle'];
        $this->kingdomaward->title_class = $request['TitleClass'];

        // Is this row one of the 16 official Amtgard ladders? If so its ladder
        // configuration belongs to Amtgard, not to the kingdom (requirement 1).
        $this->db->Clear();
        $officialRs = $this->db->DataSet(
            'select IFNULL(' . Award::OfficialLadderSql('a') . ', 0) as official_is_ladder
             from ' . DB_PREFIX . 'kingdomaward ka
             left join ' . DB_PREFIX . 'award a on a.award_id = ka.award_id
             where ka.kingdomaward_id = ' . (int) $request['KingdomAwardId']
        );
        $officialLadder = false;
        if ($officialRs && $officialRs->Next()) {
            $officialLadder = (int) $officialRs->official_is_ladder === 1;
        }

        if (!$officialLadder) {
            // yapo drops null, so write 0 rather than null to clear the flag.
            $isLadder = isset($request['IsLadder']) && (int) $request['IsLadder'] === 1 ? 1 : 0;
            $this->kingdomaward->is_ladder = $isLadder;
            if ($isLadder === 1) {
                // Ladder and Title? are mutually exclusive.
                $this->kingdomaward->is_title = 0;
            }

            $maxLevel = (int) ($request['MaxLevel'] ?? 0);
            if ($maxLevel < 0) {
                $maxLevel = 0;
            }
            $this->kingdomaward->max_level = min(12, $maxLevel); // Rule 2
        }

        $this->kingdomaward->save();
        return Success();
    }

    /**
     * How many granted awards point at a kingdom award definition.
     *
     * This is the blast radius of retiring one: every ork_awards row joins back
     * to ork_kingdomaward for its display name, so a hard DELETE here orphans all
     * of them at once (on this database, one mid-sized order took 3,772 grants
     * with it). Callers surface the number so the confirm can say what is at
     * stake.
     *
     * @param int $kingdomaward_id
     * @return int
     */
    public function CountAwardGrants($kingdomaward_id)
    {
        $kingdomaward_id = (int) $kingdomaward_id;
        if ($kingdomaward_id <= 0) {
            return 0;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            "SELECT COUNT(*) AS grant_count FROM " . DB_PREFIX . "awards
              WHERE kingdomaward_id = " . $kingdomaward_id
        );
        if ($rs !== false && $rs->Next()) {
            return (int) $rs->grant_count;
        }
        return 0;
    }

    /**
     * Retire a kingdom award. SOFT delete, on purpose.
     *
     * The row stays so every already-granted award keeps resolving its name;
     * `disabled = 1` takes it out of GetAwardList, which is what every granting
     * picker is built from, so it can no longer be handed out. RestoreAward() is
     * the inverse.
     *
     * The response carries 'AwardingCount' -- how many grants reference the award
     * -- so the UI can warn before and report after.
     */
    public function RemoveAward($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) <= 0) {
            return NoAuthorization();
        }

        // Same ownership rule as EditAward: authorize against the award's own
        // kingdom, read from the row, not against the caller-supplied KingdomId.
        $this->kingdomaward->clear();
        $this->kingdomaward->kingdomaward_id = $request['KingdomAwardId'];
        if (!$this->kingdomaward->find()) {
            return InvalidParameter();
        }
        $owning_kingdom_id = (int)$this->kingdomaward->kingdom_id;

        if (!Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'kingdom.award.remove', 'kingdom', $owning_kingdom_id, AUTH_CREATE)) {
            return NoAuthorization();
        }

        $this->log->Write('Award', $mundane_id, LOG_REMOVE, $request);
        // prior_state is captured off the found row, so the audit records the
        // kingdom the award really belonged to rather than one supplied by the
        // caller.
        // Read every field off the found row BEFORE the count query -- that query
        // runs on the same handle this yapo record is bound to.
        $prior_state = [
            'kingdomaward_id' => (int)$this->kingdomaward->kingdomaward_id,
            'kingdom_id'      => $owning_kingdom_id,
            'name'            => $this->kingdomaward->name,
            'award_id'        => (int)$this->kingdomaward->award_id,
            'is_title'        => (int)$this->kingdomaward->is_title,
            'reign_limit'     => (int)$this->kingdomaward->reign_limit,
            'month_limit'     => (int)$this->kingdomaward->month_limit,
            'disabled'        => (int)$this->kingdomaward->disabled,
        ];
        $awarding_count = $this->CountAwardGrants($prior_state['kingdomaward_id']);
        $prior_state['awarding_count'] = $awarding_count;

        // Soft delete. The previous hard delete() orphaned every grant pointing at
        // this definition -- their name resolves through this row.
        $this->kingdomaward->disabled = 1;
        $this->kingdomaward->save();

        Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Kingdom', $owning_kingdom_id, $prior_state);
        $response = Success();
        $response['AwardingCount'] = $awarding_count;
        return $response;
    }

    /**
     * Un-retire a kingdom award soft-deleted by RemoveAward(). A soft delete that
     * cannot be undone is just a delete with extra steps.
     */
    public function RestoreAward($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) <= 0) {
            return NoAuthorization();
        }

        // Same ownership rule as EditAward/RemoveAward: authorize against the
        // award's own kingdom, read from the row.
        $this->kingdomaward->clear();
        $this->kingdomaward->kingdomaward_id = $request['KingdomAwardId'];
        if (!$this->kingdomaward->find()) {
            return InvalidParameter();
        }
        $owning_kingdom_id = (int)$this->kingdomaward->kingdom_id;

        if (!Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'kingdom.award.edit', 'kingdom', $owning_kingdom_id, AUTH_CREATE)) {
            return NoAuthorization();
        }

        $kingdomaward_id = (int)$this->kingdomaward->kingdomaward_id;

        $this->log->Write('Award', $mundane_id, LOG_EDIT, $request);
        $this->kingdomaward->disabled = 0;
        $this->kingdomaward->save();

        $response = Success();
        $response['AwardingCount'] = $this->CountAwardGrants($kingdomaward_id);
        return $response;
    }

    public function create_kingdom_awards($kingdom_id)
    {
        $sql = "insert into " . DB_PREFIX . "kingdomaward (kingdom_id, award_id, name, is_title, title_class) select " . mysql_real_escape_string($kingdom_id) .", award_id, name, is_title, title_class from " . DB_PREFIX . "award";
        $this->db->query($sql);
    }

    public function GetKingdomParkTitles($request)
    {
        $parktitle = new yapo($this->db, DB_PREFIX . 'parktitle');
        $parktitle->clear();
        $parktitle->kingdom_id = $request['KingdomId'];
        $response['ParkTitles'] = array();
        if ($parktitle->find(array('class desc'))) {
            do {
                $response['ParkTitles'][] = array(
                        'ParkTitleId' => $parktitle->parktitle_id,
                        'Title' => $parktitle->title,
                        'Class' => $parktitle->class,
                        'MinimumAttendance' => $parktitle->minimumattendance,
                        'MinimumCutoff' => $parktitle->minimumcutoff,
                        'Period' => $parktitle->period,
                        'Length' => $parktitle->period_length
                    );
            } while ($parktitle->next());
        }
        $response['Status'] = Success();
        return $response;
    }

    /*
    public function GetKingdomAwardList($request) {
        return $this->GetAwardList(array( 'IsLadder' => 'Both', 'IsTitle' => 'Both', 'KingdomId' => $request['KingdomId'] ));
    }
    */

    public function GetKingdomDetails($request)
    {
        $this->kingdom->clear();
        $this->kingdom->kingdom_id = $request['KingdomId'];
        $response = array();
        if ($request['KingdomId'] > 0 && $this->kingdom->find()) {
            $response['Status'] = Success();
            $response['KingdomInfo'] = array();
            $response['KingdomInfo']['KingdomId'] = $this->kingdom->kingdom_id;
            $response['KingdomInfo']['KingdomName'] = $this->kingdom->name;
            $response['KingdomInfo']['Abbreviation'] = $this->kingdom->abbreviation;
            $response['KingdomInfo']['Active'] = $this->kingdom->active;
            $response['KingdomInfo']['IsPrincipality'] = $this->kingdom->parent_kingdom_id > 0 ? 1 : 0;
            $response['KingdomInfo']['ParentKingdomId'] = $this->kingdom->parent_kingdom_id;
            $response['KingdomInfo']['Description'] = $this->kingdom->description ?? '';
            $response['KingdomInfo']['Url'] = $this->kingdom->url ?? '';

            // Fetch configs
            $response['KingdomConfiguration'] = Common::get_configs($request['KingdomId']);

            $pt = $this->GetKingdomParkTitles($request);

            $response['ParkTitles'] = $pt['ParkTitles'];

            $response['Awards'] = $this->GetAwardList(array( 'IsLadder' => 'Both', 'IsTitle' => 'Both', 'KingdomId' => $request['KingdomId'] ));
        } else {
            $response['Status'] = InvalidParameter();
        }
        return $response;
    }

    public function GetKingdomAuthorizations($request)
    {
        $sql = "select authorization_id, username, a.mundane_id, role from ".DB_PREFIX."authorization a left join ".DB_PREFIX."mundane m on a.mundane_id = m.mundane_id where a.kingdom_id = '" . mysql_real_escape_string($request['KingdomId']) . "'";
        $r = $this->db->query($sql);
        $response = array();
        $response['Authorizations'] = array();
        if ($r !== false && $r->size() > 0) {
            $response['Status'] = Success();
            while ($r->next()) {
                $response['Authorizations'][] = array(
                        'AuthorizationId' => $r->authorization_id,
                        'UserName' => $r->username,
                        'MundaneId' => $r->mundane_id,
                        'Role' => $r->role
                    );
            }
        } else {
            $response['Status'] = InvalidParameter(null, 'Problem processing request.');
        }
        return $response;
    }

    public function CreateKingdom($request)
    {
        $response = array();
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_CREATE)) {
            $this->log->Write('Kingdom', $mundane_id, LOG_ADD, $request);
            $this->kingdom->clear();
            $this->kingdom->name = $request['Name'];
            if ($this->kingdom->find()) {
                $response = InvalidParameter('Duplicate Kingdom Name');
                return $response;
            }
            $this->kingdom->clear();
            $this->kingdom->name = $request['Name'];
            $this->kingdom->abbreviation = $request['Abbreviation'];
            $this->kingdom->active = 'Active';
            $this->kingdom->parent_kingdom_id = $request['ParentKingdomId'];
            $this->kingdom->modified = date("Y-m-d H:i:s", time());
            $this->kingdom->save();

            $c = new Common();
            $c->add_config(
                $mundane_id,
                CFG_KINGDOM,
                'fixed',
                $this->kingdom->kingdom_id,
                'AveragePeriod',
                array('Type' => $request['AttendancePeriodType'],'Period' => $request['AttendancePeriod']),
                1,
                array('Type' => array('month','week'))
            );
            $c->add_config($mundane_id, CFG_KINGDOM, 'number', $this->kingdom->kingdom_id, 'AttendanceWeeklyMinimum', $request['AttendanceWeeklyMinimum']);
            $c->add_config($mundane_id, CFG_KINGDOM, 'number', $this->kingdom->kingdom_id, 'AttendanceDailyMinimum', $request['AttendanceDailyMinimum']);
            $c->add_config($mundane_id, CFG_KINGDOM, 'number', $this->kingdom->kingdom_id, 'AttendanceCreditMinimum', $request['AttendanceCreditMinimum']);
            $c->add_config($mundane_id, CFG_KINGDOM, 'number', $this->kingdom->kingdom_id, 'MonthlyCreditMaximum', $request['MonthlyCreditMaximum']);
            $c->add_config($mundane_id, CFG_KINGDOM, 'fixed', $this->kingdom->kingdom_id, 'DuesPeriod', array('Type' => $request['DuesPeriodType'],'Period' => $request['DuesPeriod']), 1, array('Type' => array('month','week')));
            $c->add_config($mundane_id, CFG_KINGDOM, 'number', $this->kingdom->kingdom_id, 'DuesAmount', $request['DuesAmount']);
            $c->add_config($mundane_id, CFG_KINGDOM, 'number', $this->kingdom->kingdom_id, 'KingdomDuesTake', $request['KingdomDuesTake']);
            $c->add_config($mundane_id, CFG_KINGDOM, 'color', $this->kingdom->kingdom_id, 'AtlasColor', 'FE7569');
            $c->add_config($mundane_id, CFG_KINGDOM, 'fixed', $this->kingdom->kingdom_id, 'AwardRecsPublic', '1');
            $c->add_config($mundane_id, CFG_KINGDOM, 'fixed', $this->kingdom->kingdom_id, 'IncludePrincipalityInStatistics', '0');
            $c->add_config($mundane_id, CFG_KINGDOM, 'fixed', $this->kingdom->kingdom_id, 'QualTestReeveEnabled', '0');
            $c->add_config($mundane_id, CFG_KINGDOM, 'fixed', $this->kingdom->kingdom_id, 'QualTestCorporaEnabled', '0');

            $c->create_officers($this->kingdom->kingdom_id, 0);

            $c->create_park_titles($this->kingdom->kingdom_id);

            $c->create_events($this->kingdom->kingdom_id, 0);

            $this->create_kingdom_awards($this->kingdom->kingdom_id);

            Ork3::$Lib->treasury->create_accounts($mundane_id, 'kingdom', $this->kingdom->kingdom_id, $this->kingdom->kingdom_id);

            $request['KingdomId'] = $this->kingdom->kingdom_id;
            Ork3::$Lib->heraldry->SetKingdomHeraldry($request);
            Ork3::$Lib->dangeraudit->audit(__CLASS__ . '::' . __FUNCTION__, $request, 'Kingdom', (int)$this->kingdom->kingdom_id, null, [
                'kingdom_id'        => (int)$this->kingdom->kingdom_id,
                'name'              => $request['Name'],
                'abbreviation'      => $request['Abbreviation'],
                'parent_kingdom_id' => (int)$request['ParentKingdomId'],
            ]);
            $response = Success($this->kingdom->kingdom_id);
            $this->_flushPrincipalityCaches();
        } else {
            $response = NoAuthorization();
        }
        return $response;
    }

    // Full memcache flush after any change that alters the kingdom-family tree
    // (created / reparented / deactivated kingdoms or principalities). Lots of
    // derived caches across averages / events / recs / recap / officer directory
    // key off principality membership; enumerating every dependent key is
    // whack-a-mole, and these mutations are rare admin actions so the wipe cost
    // is acceptable.
    private function _flushPrincipalityCaches()
    {
        if (isset(Ork3::$Lib->ghettocache) && isset(Ork3::$Lib->ghettocache->memcache)) {
            Ork3::$Lib->ghettocache->memcache->flush();
        }
    }

    public function GetPrincipalities($request)
    {
        $this->kingdom->clear();
        $this->kingdom->parent_kingdom_id = $request['KingdomId'];
        $this->kingdom->active = 'Active';
        $result = array('Status' => Success(), 'Principalities' => array());
        if ($this->kingdom->find()) {
            do {
                $result['Principalities'][] = array(
                        'KingdomId' => $this->kingdom->kingdom_id,
                        'Name' => $this->kingdom->name,
                        'IsPrincipality' => 1,
                        'ParentKingdomId' => $this->kingdom->parent_kingdom_id
                    );
            } while ($this->kingdom->next());
        }
        return $result;
    }

    public function GetParks($request)
    {
        // Optional 'KingdomIds' array → WHERE p.kingdom_id IN (...). Default keeps
        // single-'KingdomId' behavior unchanged.
        if (isset($request['KingdomIds']) && is_array($request['KingdomIds']) && count($request['KingdomIds']) > 0) {
            $ids = implode(',', array_map('intval', $request['KingdomIds']));
            $kingdom_clause = "p.kingdom_id IN ($ids)";
        } else {
            $kingdom_clause = "p.kingdom_id = '" . mysql_real_escape_string($request['KingdomId']) . "'";
        }
        $sql = "select * 
					from " . DB_PREFIX . "park p
						left join " . DB_PREFIX . "parktitle pt on p.parktitle_id = pt.parktitle_id
					where $kingdom_clause
					order by pt.class desc, p.name asc";
        $r = $this->db->query($sql);
        if ($r !== false && $r->size() > 0) {
            $response = array('Status' => Success(), 'Parks' => array());
            while ($r->next()) {
                $response['Parks'][] = array(
                        'ParkId' => $r->park_id,
                        'KingdomId' => $r->kingdom_id,
                        'Name' => $r->name,
                        'Abbreviation' => $r->abbreviation,
                        'Location' => $r->location,
                        'Url' => $r->url,
                        'Directions' => stripslashes(nl2br($r->directions)),
                        'Description' => stripslashes(nl2br($r->description)),
                        'ParkTitleId' => $r->parktitle_id,
                        'Active' => $r->active,
                        'Title' => $r->title,
                        'Class' => $r->class,
                        'HasHeraldry' => $r->has_heraldry,
                        'City' => $r->city,
                        'Province' => $r->province
                    );
            }
        } else {
            // Always include Parks so callers (e.g. Kingdom/map) never array_filter(null).
            $response = array('Status' => InvalidParameter(), 'Parks' => array());
        }
        return $response;
    }

    public function SetKingdomParkTitles($request)
    {
        $response = array();
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'kingdom.parktitle.manage', 'kingdom', $request['KingdomId'], AUTH_EDIT)) {
            $this->log->Write('Kingdom', $mundane_id, LOG_EDIT, $request);
            if (is_array($request['ParkTitles'])) {
                $parktitle = new yapo($this->db, DB_PREFIX . 'parktitle');
                foreach ($request['ParkTitles'] as $k => $title) {
                    switch ($title['Action']) {
                        case CFG_REMOVE:
                            $parktitle->clear();
                            $parktitle->parktitle_id = $title['ParkTitleId'];
                            if (valid_id($title['ParkTitleId']) && $parktitle->find()) {
                                if ($parktitle->kingdom_id != $request['KingdomId']) {
                                    $response['Status'] = NoAuthorization('You cannot edit the park titles of another kingdom.');
                                    return $response;
                                }
                                $parktitle->delete();
                            }
                            break;
                        case CFG_EDIT:
                            $parktitle->clear();
                            $parktitle->parktitle_id = $title['ParkTitleId'];
                            if (valid_id($title['ParkTitleId']) && $parktitle->find()) {
                                if ($parktitle->kingdom_id != $request['KingdomId']) {
                                    $response['Status'] = NoAuthorization('You cannot edit the park titles of another kingdom.');
                                    return $response;
                                }
                                $parktitle->title = strlen($title['Title']) ? $title['Title'] : $parktitle->title;
                                $parktitle->class = strlen($title['Class']) ? $title['Class'] : $parktitle->class;
                                $parktitle->minimumattendance = strlen($title['MinimumAttendance']) ? $title['MinimumAttendance'] : $parktitle->minimumattendance;
                                $parktitle->minimumcutoff = strlen($title['MinimumCutoff']) ? $title['MinimumCutoff'] : $parktitle->minimumcutoff;
                                $parktitle->period = strlen($title['Period']) ? $title['Period'] : $parktitle->period;
                                $parktitle->period_length = strlen($title['PeriodLength']) ? $title['PeriodLength'] : $parktitle->period_length;
                                $parktitle->save();
                            }
                            break;
                        case CFG_ADD:
                            $parktitle->clear();
                            $parktitle->kingdom_id = $request['KingdomId'];
                            $parktitle->title = $title['Title'];
                            $parktitle->class = $title['Class'];
                            $parktitle->minimumattendance = $title['MinimumAttendance'];
                            $parktitle->minimumcutoff = $title['MinimumCutoff'];
                            $parktitle->period = $title['Period'];
                            $parktitle->period_length = $title['PeriodLength'];
                            $parktitle->save();
                            break;
                    }
                }
            }
            $response = Success();
        } else {
            $response = NoAuthorization(null, $mundane_id);
        }
        return $response;
    }

    public function SetKingdomDetails($request)
    {
        $response = array();
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'kingdom.details.edit', 'kingdom', $request['KingdomId'], AUTH_EDIT)) {
            $this->log->Write('Kingdom', $mundane_id, LOG_EDIT, $request);
            $this->kingdom->clear();
            $this->kingdom->kingdom_id = $request['KingdomId'];
            if ($this->kingdom->find()) {
                // Park-level record edits audit; their kingdom-level equivalent did
                // not. Snapshot before/after so the two are symmetrical.
                $prior_state = [
                    'kingdom_id'   => (int)$this->kingdom->kingdom_id,
                    'name'         => $this->kingdom->name,
                    'abbreviation' => $this->kingdom->abbreviation,
                    'description'  => $this->kingdom->description,
                    'url'          => $this->kingdom->url,
                ];
                $this->kingdom->name = strlen($request['Name']) > 0 ? $request['Name'] : $this->kingdom->name;
                $this->kingdom->abbreviation = strlen($request['Abbreviation']) > 0 ? $request['Abbreviation'] : $this->kingdom->abbreviation;
                if (isset($request['Description'])) {
                    $this->kingdom->description = $request['Description'];
                }
                if (isset($request['Url'])) {
                    $this->kingdom->url = $request['Url'];
                }
                $this->kingdom->modified = date("Y-m-d H:i:s", time());
                $this->kingdom->save();

                $_audit_req = $request;
                unset($_audit_req['Heraldry']);
                Ork3::$Lib->dangeraudit->audit(
                    __CLASS__ . '::' . __FUNCTION__,
                    $_audit_req,
                    'Kingdom',
                    (int)$this->kingdom->kingdom_id,
                    $prior_state,
                    [
                        'kingdom_id'   => (int)$this->kingdom->kingdom_id,
                        'name'         => $this->kingdom->name,
                        'abbreviation' => $this->kingdom->abbreviation,
                        'description'  => $this->kingdom->description,
                        'url'          => $this->kingdom->url,
                    ]
                );

                Ork3::$Lib->heraldry->SetKingdomHeraldry($request);

                $c = new Common();
                if (is_array($request['KingdomConfiguration'])) {
                    foreach ($request['KingdomConfiguration'] as $k => $config) {
                        switch ($config['Action']) {
                            case CFG_REMOVE:
                                $c->remove_config($mundane_id, $config['ConfigurationId'], CFG_KINGDOM, $this->kingdom->kingdom_id, $config['Key']);
                                break;
                            case CFG_EDIT:
                                $c->update_config($mundane_id, $config['ConfigurationId'], CFG_KINGDOM, $this->kingdom->kingdom_id, $config['Key'], $config['Value']);
                                break;
                            case CFG_ADD:
                                $c->add_config($mundane_id, CFG_KINGDOM, $config['Type'], $this->kingdom->kingdom_id, $config['Key'], $config['Value'], $config['UserSetting'], $config['AllowedValues']);
                                break;
                        }
                    }
                    // Kingdom config can change rollup stats and many derived report values.
                    if (Ork3::$Lib->ghettocache->memcache instanceof Memcached) {
                        Ork3::$Lib->ghettocache->memcache->flush();
                    }
                }
                $response = Success();
            } else {
                $response = InvalidParameter(null, 'Problem processing request');
            }
        } else {
            $response = NoAuthorization(null, $mundane_id);
        }
        return $response;
    }

    public function SetKingdomParent($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_ADMIN)) {
            $kingdom_id = (int)$request['KingdomId'];
            $parent_id  = (int)$request['ParentKingdomId'];
            // Cannot make a kingdom its own parent or create a circular reference
            if ($parent_id === $kingdom_id) {
                return InvalidParameter('A kingdom cannot be its own parent.');
            }
            $this->kingdom->clear();
            $this->kingdom->kingdom_id = $kingdom_id;
            if (!$this->kingdom->find()) {
                return InvalidParameter('Kingdom not found.');
            }
            if ($parent_id > 0) {
                $this->kingdom->clear();
                $this->kingdom->kingdom_id = $parent_id;
                if (!$this->kingdom->find()) {
                    return InvalidParameter('Parent kingdom not found.');
                }
                // Cycle prevention: walk the proposed parent's ancestor chain. If we
                // reach $kingdom_id, the proposed parent is a descendant of the
                // kingdom we are reparenting → that would create a loop. Reject it.
                $walker  = new yapo($this->db, DB_PREFIX . 'kingdom');
                $cursor  = $parent_id;
                $visited = array();
                while ($cursor > 0) {
                    if ($cursor === $kingdom_id) {
                        return InvalidParameter('A kingdom cannot be made a child of one of its own descendants.');
                    }
                    if (isset($visited[$cursor])) {
                        break; // pre-existing cycle in data — stop walking
                    }
                    $visited[$cursor] = true;
                    $walker->clear();
                    $walker->kingdom_id = $cursor;
                    if (!$walker->find()) {
                        break;
                    }
                    $cursor = (int)$walker->parent_kingdom_id;
                }
                $this->kingdom->clear();
                $this->kingdom->kingdom_id = $kingdom_id;
                $this->kingdom->find();
            }
            $this->log->Write('Kingdom', $mundane_id, LOG_EDIT, $request);
            $this->kingdom->parent_kingdom_id = $parent_id;
            $this->kingdom->modified = date('Y-m-d H:i:s', time());
            $this->kingdom->save();
            $this->_flushPrincipalityCaches();
            return Success();
        }
        return NoAuthorization();
    }

    // Active child-principality kingdom ids of $kingdomId ([] if none). Uses the
    // same criteria as GetPrincipalities (parent_kingdom_id = $kingdomId AND
    // active = 'Active').
    public function GetChildPrincipalityIds($kingdomId)
    {
        $kingdomId = (int)$kingdomId;
        $ids = array();
        if ($kingdomId <= 0) {
            return $ids;
        }
        if (isset($this->childPrincipalityCache[$kingdomId])) {
            return $this->childPrincipalityCache[$kingdomId];
        }
        $child = new yapo($this->db, DB_PREFIX . 'kingdom');
        $child->clear();
        $child->parent_kingdom_id = $kingdomId;
        $child->active = 'Active';
        if ($child->find()) {
            do {
                $ids[] = (int)$child->kingdom_id;
            } while ($child->next());
        }
        $this->childPrincipalityCache[$kingdomId] = $ids;
        return $ids;
    }

    // [$kingdomId, ...child principality ids] — ALWAYS includes children.
    // Structural scoping (parks dropdown, player search) uses THIS.
    public function GetFamilyKingdomIds($kingdomId)
    {
        $kingdomId = (int)$kingdomId;
        return array_merge(array($kingdomId), $this->GetChildPrincipalityIds($kingdomId));
    }

    // True iff the IncludePrincipalityInStatistics config flag for $kingdomId is '1'.
    public function StatsIncludesPrincipalities($kingdomId)
    {
        $kingdomId = (int)$kingdomId;
        if (isset($this->statsIncludesPrincipalityCache[$kingdomId])) {
            return $this->statsIncludesPrincipalityCache[$kingdomId];
        }
        $configs = Common::get_configs($kingdomId, CFG_KINGDOM);
        $enabled = isset($configs['IncludePrincipalityInStatistics'])
            && (int)$configs['IncludePrincipalityInStatistics']['Value'] === 1;
        $this->statsIncludesPrincipalityCache[$kingdomId] = $enabled;
        return $enabled;
    }

    // GetFamilyKingdomIds($kingdomId) when StatsIncludesPrincipalities is true AND
    // there are children; otherwise [$kingdomId]. STATS/REPORT scoping uses THIS.
    public function GetStatsKingdomIds($kingdomId)
    {
        $kingdomId = (int)$kingdomId;
        $children = $this->GetChildPrincipalityIds($kingdomId);
        if (count($children) > 0 && $this->StatsIncludesPrincipalities($kingdomId)) {
            return array_merge(array($kingdomId), $children);
        }
        return array($kingdomId);
    }

    public function GetOfficers($request)
    {
        $kingdom_id = mysql_real_escape_string($request['KingdomId']);
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        $is_authorized = Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'kingdom.officer.set', 'kingdom', $kingdom_id, AUTH_EDIT);

        // Kingdom-level officers: scope to this kingdom with no park, and resolve
        // title aliases against this kingdom's own alias rows.
        $aliasKingdomExpr = "'" . $kingdom_id . "'";
        $whereClause = "o.kingdom_id = '" . $kingdom_id . "' and o.park_id = 0";

        return Kingdom::buildOfficerRows($this->db, $aliasKingdomExpr, $whereClause, $mundane_id, $is_authorized);
    }

    /**
     * Shared officer query + result assembly for Kingdom::GetOfficers and
     * Park::GetOfficers. The SELECT list, joins, retired/hide-when-vacant
     * filters, ORDER BY, privacy gate, and returned key set are identical for
     * both callers; they differ only in (a) the officer_position_alias
     * kingdom-match expression and (b) the WHERE clause scoping rows to a
     * kingdom or a park.
     *
     * @param object $db               DB handle ($this->db).
     * @param string $aliasKingdomExpr SQL expr for al.kingdom_id match
     *                                 (e.g. "'5'" for a kingdom, "o.kingdom_id" for a park).
     * @param string $whereClause      Scoping WHERE predicate (without leading "where").
     * @param int    $mundane_id       Requesting mundane id (privacy gate).
     * @param bool   $is_authorized    Whether the requester may see private name fields.
     */
    public static function buildOfficerRows($db, $aliasKingdomExpr, $whereClause, $mundane_id, $is_authorized)
    {
        $sql = "select a.*, p.name as park_name, k.name as kingdom_name, e.name as event_name, u.name as unit_name, m.mundane_id as m_mundane_id, m.username, m.given_name, m.surname, m.persona, m.restricted, o.role as officer_role, o.officer_id, o.position_id,
					op.canonical_key as canonical_key, op.parent_position_id as parent_position_id, op.hide_when_vacant as hide_when_vacant, op.classification as classification,
					" . OfficerPosition::DisplayTitleSql('op', 'al') . " as display_title
					from " . DB_PREFIX . "officer o
						left join " . DB_PREFIX . "officer_position op on op.position_id = o.position_id
						left join " . DB_PREFIX . "officer_position_alias al on al.kingdom_id = " . $aliasKingdomExpr . " and al.canonical_key = op.canonical_key
						left join " . DB_PREFIX . "mundane m on o.mundane_id = m.mundane_id
						left join " . DB_PREFIX . "authorization a on a.authorization_id = o.authorization_id
							left join ".DB_PREFIX."park p on a.park_id = p.park_id
							left join ".DB_PREFIX."kingdom k on a.kingdom_id = k.kingdom_id
							left join ".DB_PREFIX."event e on a.event_id = e.event_id
							left join ".DB_PREFIX."unit u on a.unit_id = u.unit_id
				where " . $whereClause . "
				  and (op.retired_at IS NULL or op.position_id IS NULL)
				  and NOT (op.hide_when_vacant = 1 and op.classification != 'crown' and (o.mundane_id IS NULL or o.mundane_id = 0))
				order by op.classification, op.sort_order, o.role
			";
        $r = $db->query($sql);
        $response = array();
        $response['Officers'] = array();
        if ($r !== false && $r->size() > 0) {
            $response['Status'] = Success();
            while ($r->next()) {
                $fetchprivate = true;
                if ($mundane_id > 0 && $is_authorized) {
                    $fetchprivate = false;
                }
                $response['Officers'][] = array(
                            'AuthorizationId' => $r->authorization_id,
                            'MundaneId' => $r->m_mundane_id,
                            'ParkId' => $r->park_id,
                            'KingdomId' => $r->kingdom_id,
                            'EventId' => $r->event_id,
                            'UnitId' => $r->unit_id,
                            'Role' => $r->canonical_key !== null ? $r->canonical_key : $r->role,
                            'CanonicalKey' => $r->canonical_key !== null ? $r->canonical_key : $r->role,
                            'ParentPositionId' => ($r->parent_position_id === null || $r->parent_position_id === '') ? null : (int)$r->parent_position_id,
                            'HideWhenVacant' => (int)$r->hide_when_vacant,
                            'DisplayTitle' => $r->display_title !== null ? $r->display_title : $r->role,
                            'ParkName' => $r->park_name,
                            'KingdomName' => $r->kingdom_name,
                            'EventName' => $r->event_name,
                            'UnitName' => $r->unit_name,
                            'Restricted' => $r->restricted,
                            'UserName' => $r->username,
                            'GivenName' => $fetchprivate ? "" : $r->given_name,
                            'Surname' => $fetchprivate ? "" : $r->surname,
                            'Persona' => $r->persona,
                            'OfficerId' => $r->officer_id,
                            // Two shapes on purpose. OfficerRoleKey is the canonical key and is
                            // what code compares against; OfficerRole stays a human label because
                            // templates fall back to it for display (Kingdom_index/Park_index use
                            // DisplayTitle ?? OfficerRole, and Admin_setofficers builds its form
                            // field names off it). Emitting the raw canonical key under the display
                            // name rendered offices as "prime_minister" and broke every consumer
                            // that still compared against 'Prime Minister'.
                            'OfficerRoleKey' => PermissionRegistry::CanonicalOfficerRole(
                                $r->canonical_key !== null ? $r->canonical_key : $r->officer_role
                            ),
                            'OfficerRole' => PermissionRegistry::OfficerRoleLabel(
                                $r->canonical_key !== null ? $r->canonical_key : $r->officer_role
                            )
                        );
            }
            $response['Status'] = Success();
        } else {
            $response['Status'] = InvalidParameter();
        }
        return $response;
    }

    public function SetOfficer($request)
    {
        $response = array();
        $mundane = Ork3::$Lib->player->player_info($request['MundaneId']);
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'kingdom.officer.set', 'kingdom', $request['KingdomId'], AUTH_EDIT)) {
            if ($mundane['KingdomId'] == $request['KingdomId']) {
                // Look up prior holder so the audit can show before/after,
                // and so we can suppress no-op re-saves of the same assignment.
                // Resolve the position_id for this Role (accepts canonical key or legacy display string).
                $_positionId = Ork3::$Lib->officerposition->ResolvePositionId((int)$request['KingdomId'], $request['Role']);
                $_canonicalKey = Ork3::$Lib->officerposition->ResolveCanonicalKey((int)$request['KingdomId'], $request['Role']);

                $_priorOfficer = new yapo($this->db, DB_PREFIX . 'officer');
                $_priorOfficer->clear();
                $_priorOfficer->kingdom_id = (int)$request['KingdomId'];
                $_priorOfficer->park_id    = 0;
                if ($_positionId > 0) {
                    $_priorOfficer->position_id = $_positionId;
                } else {
                    $_priorOfficer->role = $request['Role'];
                }
                $_priorMundaneId = $_priorOfficer->find() ? (int)$_priorOfficer->mundane_id : 0;

                $c = new Common();
                $c->set_officer($request['KingdomId'], 0, $request['MundaneId'], $_canonicalKey, 0, $mundane_id, $_positionId);

                if ($_priorMundaneId !== (int)$request['MundaneId']) {
                    $_audit_req = $request;
                    unset($_audit_req['Token']);
                    Ork3::$Lib->dangeraudit->audit(
                        __CLASS__ . '::' . __FUNCTION__,
                        $_audit_req,
                        'Kingdom',
                        (int)$request['KingdomId'],
                        ['MundaneId' => $_priorMundaneId, 'Role' => $request['Role']],
                        [
                            'KingdomId' => (int)$request['KingdomId'],
                            'MundaneId' => (int)$request['MundaneId'],
                            'Role'      => $request['Role'],
                        ]
                    );
                }
            } else {
                return InvalidParameter(null, "The new officer must be a member of this Kingdom.");
            }
        } else {
            $response = NoAuthorization();
        }
        return $response;
    }

    public function VacateOfficer($request)
    {
        $response = array();
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        if ($mundane_id > 0) {
            if (Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'kingdom.officer.vacate', 'kingdom', $request['KingdomId'], AUTH_EDIT)) {
                // Resolve the position_id for this Role (accepts canonical key or legacy display string).
                $_positionId = Ork3::$Lib->officerposition->ResolvePositionId((int)$request['KingdomId'], $request['Role']);
                $_canonicalKey = Ork3::$Lib->officerposition->ResolveCanonicalKey((int)$request['KingdomId'], $request['Role']);

                $_priorOfficer = new yapo($this->db, DB_PREFIX . 'officer');
                $_priorOfficer->clear();
                $_priorOfficer->kingdom_id = (int)$request['KingdomId'];
                $_priorOfficer->park_id    = 0;
                if ($_positionId > 0) {
                    $_priorOfficer->position_id = $_positionId;
                } else {
                    $_priorOfficer->role = $request['Role'];
                }
                $_priorMundaneId = $_priorOfficer->find() ? (int)$_priorOfficer->mundane_id : 0;

                $c = new Common();
                $c->set_officer($request['KingdomId'], 0, 0, $_canonicalKey, 0, $mundane_id, $_positionId);

                if ($_priorMundaneId > 0) {
                    $_audit_req = $request;
                    unset($_audit_req['Token']);
                    Ork3::$Lib->dangeraudit->audit(
                        __CLASS__ . '::' . __FUNCTION__,
                        $_audit_req,
                        'Kingdom',
                        (int)$request['KingdomId'],
                        ['MundaneId' => $_priorMundaneId, 'Role' => $request['Role']],
                        [
                            'KingdomId' => (int)$request['KingdomId'],
                            'Role'      => $request['Role'],
                        ]
                    );
                }
            } else {
                $response = NoAuthorization();
            }
        } else {
            $response = NoAuthorization();
        }
        return $response;
    }

    public function GetOfficerHistory($request)
    {
        $kingdom_id = (int)$request['KingdomId'];
        $role_filter = isset($request['Role']) && strlen(trim($request['Role'])) > 0 ? trim($request['Role']) : null;

        $sql = "SELECT oh.officer_history_id, oh.kingdom_id, oh.park_id, oh.mundane_id, oh.role,
		                oh.start_date, oh.end_date, oh.changed_by, oh.notes, oh.created_at,
		                m.persona, m.username,
		                cb.persona AS changed_by_persona
		         FROM " . DB_PREFIX . "officer_history oh
		         LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = oh.mundane_id
		         LEFT JOIN " . DB_PREFIX . "mundane cb ON cb.mundane_id = oh.changed_by
		         WHERE oh.kingdom_id = " . $kingdom_id . " AND oh.park_id = 0";

        // Bound, never interpolated. mysql_real_escape_string() in this codebase is a
        // no-op shim (startup.php: `return $str;`), so the previous concatenation put a
        // caller-supplied string straight into the WHERE clause.
        if ($role_filter !== null) {
            $sql .= " AND oh.role = :oh_role";
        }

        $sql .= " ORDER BY oh.role, oh.start_date DESC, oh.officer_history_id DESC";

        global $DB;
        $DB->Clear();
        if ($role_filter !== null) {
            $DB->oh_role = $role_filter;
        }
        $r = $DB->DataSet($sql);
        $response = ['Status' => Success(), 'History' => []];
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $response['History'][] = [
                    'OfficerHistoryId' => (int)$r->officer_history_id,
                    'KingdomId'        => (int)$r->kingdom_id,
                    'ParkId'           => (int)$r->park_id,
                    'MundaneId'        => (int)$r->mundane_id,
                    'Role'             => $r->role,
                    'StartDate'        => $r->start_date,
                    'EndDate'          => $r->end_date,
                    'ChangedBy'        => (int)$r->changed_by,
                    'ChangedByPersona' => $r->changed_by_persona ?? '',
                    'Notes'            => $r->notes ?? '',
                    'Persona'          => $r->persona ?? '',
                    'UserName'         => $r->username ?? '',
                ];
            }
        }
        return $response;
    }

    public function AddOfficerHistory($request)
    {
        $response = [];
        if (
            ($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
            && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'kingdom.officer_history.manage', 'kingdom', $request['KingdomId'], AUTH_EDIT)
        ) {
            $kid       = (int)$request['KingdomId'];
            $mid       = (int)$request['MundaneId'];
            $role      = trim($request['Role'] ?? '');
            $start     = trim($request['StartDate'] ?? '');
            $end       = trim($request['EndDate'] ?? '');
            $notes_raw = isset($request['Notes']) ? trim($request['Notes']) : '';

            if ($mid <= 0 || strlen($role) === 0 || strlen($start) === 0) {
                return InvalidParameter(null, 'MundaneId, Role, and StartDate are required.');
            }

            // Every caller-supplied value is BOUND. mysql_real_escape_string() is a no-op
            // shim here (startup.php: `return $str;`), so the previous string concatenation
            // was a straight injection through Role / StartDate / EndDate / Notes.
            global $DB;
            $DB->Clear();
            $DB->oh_kid   = $kid;
            $DB->oh_mid   = $mid;
            $DB->oh_role  = $role;
            $DB->oh_start = $start;
            $DB->oh_end   = strlen($end) > 0 ? $end : null;
            $DB->oh_cb    = (int)$mundane_id;
            $DB->oh_notes = strlen($notes_raw) > 0 ? $notes_raw : null;
            $DB->Execute(
                "INSERT INTO " . DB_PREFIX . "officer_history
				 (kingdom_id, park_id, mundane_id, role, start_date, end_date, changed_by, notes, created_at)
				 VALUES (:oh_kid, 0, :oh_mid, :oh_role, :oh_start, :oh_end, :oh_cb, :oh_notes, NOW())"
            );
            $response = Success();
        } else {
            $response = NoAuthorization();
        }
        return $response;
    }

    public function EditOfficerHistory($request)
    {
        $response = [];
        if (
            ($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
            && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'kingdom.officer_history.manage', 'kingdom', $request['KingdomId'], AUTH_EDIT)
        ) {
            $ohid      = (int)$request['OfficerHistoryId'];
            $kid       = (int)$request['KingdomId'];
            $role      = trim($request['Role'] ?? '');
            $start     = trim($request['StartDate'] ?? '');
            $end       = trim($request['EndDate'] ?? '');
            $notes_raw = isset($request['Notes']) ? trim($request['Notes']) : '';

            if ($ohid <= 0 || strlen($role) === 0 || strlen($start) === 0) {
                return InvalidParameter(null, 'OfficerHistoryId, Role, and StartDate are required.');
            }

            // Bound, not concatenated -- see AddOfficerHistory for why the shim is not escaping.
            global $DB;
            $DB->Clear();
            $DB->oh_role  = $role;
            $DB->oh_start = $start;
            $DB->oh_end   = strlen($end) > 0 ? $end : null;
            $DB->oh_notes = strlen($notes_raw) > 0 ? $notes_raw : null;
            $DB->oh_id    = $ohid;
            $DB->oh_kid   = $kid;
            $DB->Execute(
                "UPDATE " . DB_PREFIX . "officer_history
				 SET role = :oh_role, start_date = :oh_start, end_date = :oh_end, notes = :oh_notes
				 WHERE officer_history_id = :oh_id
				   AND kingdom_id = :oh_kid
				   AND park_id = 0"
            );
            $response = Success();
        } else {
            $response = NoAuthorization();
        }
        return $response;
    }

    public function DeleteOfficerHistory($request)
    {
        $response = [];
        if (
            ($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
            && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'kingdom.officer_history.manage', 'kingdom', $request['KingdomId'], AUTH_EDIT)
        ) {
            $ohid = (int)$request['OfficerHistoryId'];
            $kid  = (int)$request['KingdomId'];

            if ($ohid <= 0) {
                return InvalidParameter(null, 'OfficerHistoryId is required.');
            }

            global $DB;
            $DB->Clear();
            $DB->Execute(
                "DELETE FROM " . DB_PREFIX . "officer_history
				 WHERE officer_history_id = " . $ohid . "
				   AND kingdom_id = " . $kid . "
				   AND park_id = 0"
            );
            $response = Success();
        } else {
            $response = NoAuthorization();
        }
        return $response;
    }

    public function RetireKingdom($request)
    {
        return $this->WaffleKingdom($request, 'Retired');
    }

    public function RestoreKingdom($request)
    {
        return $this->WaffleKingdom($request, 'Active');
    }

    public function WaffleKingdom($request, $waffle)
    {
        $response = array();
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
            $this->log->Write('Kingdom', $mundane_id, 'Active' == $waffle ? LOG_RESTORE : LOG_RETIRE, $request);
            $this->kingdom->clear();
            $this->kingdom->kingdom_id = $request['KingdomId'];
            if ($this->kingdom->find()) {
                $this->kingdom->active = $waffle;
                $this->kingdom->save();
                $this->_flushPrincipalityCaches();
                $response = Success();
            } else {
                $response = InvalidParameter(null, 'Problem processing request.');
            }
        } else {
            $response = NoAuthorization();
        }
        return $response;
    }

    public function GetKingdoms($request)
    {
        $response = array('Status' => Success(), 'Kingdoms' => array());
        $this->kingdom->clear();
        $this->kingdom->active = 'Active';
        if ($this->kingdom->find()) {
            do {
                $config = Common::get_configs($this->kingdom->kingdom_id);
                $response['Kingdoms'][$this->kingdom->kingdom_id] = array(
                        'KingdomId' => $this->kingdom->kingdom_id,
                        'KingdomName' => $this->kingdom->name,
                        'Abbreviation' => $this->kingdom->abbreviation,
                        'KingdomColor' => $config['AtlasColor']['Value'],
                                            'ParentKingdomId' => $this->kingdom->parent_kingdom_id,
                                            'Active' => $this->kingdom->active
                    );
            } while ($this->kingdom->next());
        }
        return $response;
    }

}
