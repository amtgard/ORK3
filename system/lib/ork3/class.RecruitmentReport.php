<?php

/**
 * Player Recruitment Report: where new players came from ("How did you find
 * us?", ork_mundane.recruitment_source) and how each source's recruits
 * stuck around.
 *
 * Not JSON-whitelisted (orkservice/Json/index.php) and Run() checks the token
 * itself anyway. Reached only through orkui/model/model.Reports.php.
 *
 * Cohort = the New Player Attendance cohort (Report::GetNewPlayerAttendance
 * summary), so the two reports agree on the count for the same range and
 * scope: a player whose first-ever sign-in (mundane_id > 0, park_id > 0) is
 * in the range and who has no sign-in of any kind before it, credited to the
 * lowest park_id among their in-range sign-ins, counted when that park is an
 * active park in scope. Everything else is computed per player from there.
 */
class RecruitmentReport extends Ork3
{
    /** Retention milestones, in days after the first sign-in. */
    public const MILESTONES = [30, 60, 90, 180, 365];
    /** Sankey stages after "Came back". */
    public const FLOW_MILESTONES = [30, 60, 90, 180];
    /** Return funnel: reached the Nth distinct sign-in date. */
    public const FUNNEL = [2, 3, 5, 10, 25];
    /**
     * Days a single-sign-in player gets before counting as "never came back".
     * Younger one-timers are "not back yet" and sit out of the came-back % denominator.
     */
    public const COME_BACK_DAYS = 30;
    public const AWARD_WINDOWS = [14, 30, 60, 90];
    public const DEFAULT_AWARD_WINDOW = 30;
    /** Source filter value meaning "not answered" (recruitment_source = ''). */
    public const SOURCE_NONE = 'none';
    public const CACHE_SECONDS = 300;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @param array $request Token, ScopeType ('Kingdom'|'Park'), ScopeId,
     *   StartDate, EndDate (Y-m-d), FilterParkId (kingdom scope only, 0 = all),
     *   Source ('' = all, a RecruitmentSource code, or 'none'), AwardWindow.
     */
    public function Run($request)
    {
        $scopeType = (string)($request['ScopeType'] ?? '');
        $scopeId = (int)($request['ScopeId'] ?? 0);
        if (($scopeType !== 'Kingdom' && $scopeType !== 'Park') || !valid_id($scopeId)) {
            return ['Status' => InvalidParameter('A kingdom or park is required.')];
        }
        $uid = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        if (!valid_id($uid)) {
            return ['Status' => BadToken()];
        }
        if (!$this->CanView_Scope($uid, $scopeType, $scopeId)) {
            return ['Status' => NoAuthorization('Only officers of this ' . strtolower($scopeType) . ' can view its recruitment report.')];
        }

        $start = (string)($request['StartDate'] ?? '');
        $end = (string)($request['EndDate'] ?? '');
        if (!self::_validDate($start) || !self::_validDate($end) || $start > $end) {
            return ['Status' => InvalidParameter('Pick a valid date range.')];
        }
        $window = (int)($request['AwardWindow'] ?? self::DEFAULT_AWARD_WINDOW);
        if (!in_array($window, self::AWARD_WINDOWS, true)) {
            $window = self::DEFAULT_AWARD_WINDOW;
        }
        $source = (string)($request['Source'] ?? '');
        if ($source !== self::SOURCE_NONE && RecruitmentSource::normalize($source) !== $source) {
            $source = ''; // unknown code reads as "all"
        }

        $kingdomIds = $scopeType === 'Kingdom'
            ? array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($scopeId))
            : [];
        $parks = $scopeType === 'Kingdom' ? $this->_scopeParks($kingdomIds) : [];
        $filterPark = (int)($request['FilterParkId'] ?? 0);
        if ($scopeType !== 'Kingdom' || !isset($parks[$filterPark])) {
            $filterPark = 0;
        }

        $t0 = microtime(true);
        $rows = $this->_cohort($scopeType, $scopeId, $kingdomIds, $start, $end);
        if ($rows === null) {
            return ['Status' => ProcessingError('The report could not be read. Please try again.')];
        }

        $players = [];
        foreach ($rows as $p) {
            if ($filterPark > 0 && $p['ParkId'] !== $filterPark) {
                continue;
            }
            $key = $p['Source'] === '' ? self::SOURCE_NONE : $p['Source'];
            if ($source !== '' && $key !== $source) {
                continue;
            }
            $players[] = $p;
        }

        $today = date('Y-m-d');
        $response = $this->_aggregate($players, $start, $end, $window, $today);
        $response['Status'] = Success();
        $response['Parks'] = array_values($parks);
        $response['Filters'] = [
            'StartDate'    => $start,
            'EndDate'      => $end,
            'FilterParkId' => $filterPark,
            'Source'       => $source,
            'AwardWindow'  => $window,
        ];
        $response['AsOf'] = $today;
        $response['ElapsedMs'] = (int)round((microtime(true) - $t0) * 1000);
        return $response;
    }

    /**
     * ORK admin; kingdom officer (edit) for a kingdom scope; park officer (edit)
     * or an officer of its kingdom for a park scope. HasAuthority walks
     * park -> kingdom -> parent kingdom (principalities) itself.
     * Underscored: token-free, never callable over the JSON service.
     */
    public function CanView_Scope($uid, $scopeType, $scopeId)
    {
        $uid = (int)$uid;
        $scopeId = (int)$scopeId;
        if (!valid_id($uid) || !valid_id($scopeId)) {
            return false;
        }
        $auth = Ork3::$Lib->authorization;
        if ($auth->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN)) {
            return true;
        }
        $type = $scopeType === 'Park' ? AUTH_PARK : AUTH_KINGDOM;
        return (bool)$auth->HasAuthority($uid, $type, $scopeId, AUTH_EDIT);
    }

    private static function _validDate($d)
    {
        if (!is_string($d) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
            return false;
        }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && (int)$m[1] >= 1990;
    }

    /** Active parks of the scope's kingdoms, park_id => [ParkId, Name]. */
    private function _scopeParks(array $kingdomIds)
    {
        $out = [];
        if (!$kingdomIds) {
            return $out;
        }
        $kids = implode(',', array_map('intval', $kingdomIds));
        $r = $this->db->query("SELECT park_id, name FROM " . DB_PREFIX . "park
            WHERE kingdom_id IN ($kids) AND active = 'Active' AND name IS NOT NULL AND name != ''
            ORDER BY name");
        // $DB is YapoMysql: the cursor starts before the first row, so next() first.
        while ($r && $r->size() > 0 && $r->next()) {
            $id = (int)$r->park_id;
            $out[$id] = ['ParkId' => $id, 'Name' => stripslashes((string)$r->name)];
        }
        return $out;
    }

    /**
     * One row per new player with everything the aggregates need. Cached per
     * scope + range (filters and the award window are applied in PHP), so
     * changing them re-renders without re-querying. null on a DB failure.
     */
    private function _cohort($scopeType, $scopeId, array $kingdomIds, $start, $end)
    {
        $cacheKey = Ork3::$Lib->ghettocache->key([$scopeType, (int)$scopeId, implode('-', $kingdomIds), $start, $end]);
        $cached = Ork3::$Lib->ghettocache->get(__CLASS__ . '.Cohort', $cacheKey, self::CACHE_SECONDS);
        if (is_array($cached)) {
            return $cached;
        }

        $A = DB_PREFIX . 'attendance';
        $P = DB_PREFIX . 'park';
        if ($scopeType === 'Park') {
            $pid = (int)$scopeId;
            $candScope = "a_k.park_id = $pid";
            $parkScope = "p.park_id = $pid";
        } else {
            $kids = implode(',', array_map('intval', $kingdomIds));
            $candScope = "a_k.park_id IN (SELECT pk.park_id FROM $P pk WHERE pk.kingdom_id IN ($kids))";
            $parkScope = "p.kingdom_id IN ($kids)";
        }
        // $start/$end are regex + checkdate validated above (mysql_real_escape_string is a no-op).
        // The candidate IN() narrows the range scan to players who signed in somewhere in
        // scope during the range: anyone credited to an in-scope park must have done so.
        // Credit = MIN(park_id) over the in-range sign-ins, exactly as New Player Attendance.
        $sql = "SELECT np.mundane_id, np.first_date, p.park_id, p.name AS park_name,
                m.persona, m.recruitment_source,
                (SELECT COUNT(DISTINCT a.date) FROM $A a WHERE a.mundane_id = np.mundane_id) AS signins,
                (SELECT MAX(a.date) FROM $A a WHERE a.mundane_id = np.mundane_id) AS last_date,
                (SELECT MIN(aw.date) FROM " . DB_PREFIX . "awards aw
                    WHERE aw.mundane_id = np.mundane_id AND aw.revoked = 0 AND aw.date >= np.first_date) AS first_award,
                EXISTS (SELECT 1 FROM " . DB_PREFIX . "dues d
                    WHERE d.mundane_id = np.mundane_id AND d.revoked = 0
                      AND COALESCE(d.created_on, d.dues_from) >= np.first_date) AS dues_paid
            FROM (
                SELECT a_in.mundane_id, MIN(a_in.park_id) AS first_park_id, MIN(a_in.date) AS first_date
                FROM $A a_in
                WHERE a_in.mundane_id > 0
                  AND a_in.park_id > 0
                  AND a_in.date >= '$start'
                  AND a_in.date <= '$end'
                  AND a_in.mundane_id IN (
                      SELECT a_k.mundane_id FROM $A a_k
                      WHERE $candScope AND a_k.date >= '$start' AND a_k.date <= '$end' AND a_k.mundane_id > 0
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM $A a_pre
                      WHERE a_pre.mundane_id = a_in.mundane_id AND a_pre.date < '$start'
                      LIMIT 1
                  )
                GROUP BY a_in.mundane_id
            ) np
            INNER JOIN $P p ON p.park_id = np.first_park_id
                AND p.active = 'Active' AND p.name IS NOT NULL AND p.name != '' AND $parkScope
            LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = np.mundane_id";

        $r = $this->db->query($sql);
        if (!empty($r->__ERROR[0][1]) && $r->__ERROR[0][1] !== '00000') {
            logtrace('RecruitmentReport cohort query failed', $r->__ERROR);
            return null;
        }
        $rows = [];
        // $DB is YapoMysql: the cursor starts before the first row, so next() first.
        while ($r && $r->size() > 0 && $r->next()) {
            $rows[] = [
                'MundaneId'  => (int)$r->mundane_id,
                'Persona'    => stripslashes((string)$r->persona),
                'ParkId'     => (int)$r->park_id,
                'ParkName'   => stripslashes((string)$r->park_name),
                'FirstDate'  => (string)$r->first_date,
                'Source'     => RecruitmentSource::normalize((string)$r->recruitment_source),
                'SignIns'    => (int)$r->signins,
                'LastDate'   => (string)$r->last_date,
                'FirstAward' => $r->first_award ? (string)$r->first_award : null,
                'DuesPaid'   => (int)$r->dues_paid === 1,
            ];
        }
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.Cohort', $cacheKey, $rows);
    }

    private static function _days($from, $to)
    {
        return (int)round((strtotime($to . ' 00:00:00 UTC') - strtotime($from . ' 00:00:00 UTC')) / 86400);
    }

    private static function _pct($num, $den)
    {
        return $den > 0 ? round($num * 100 / $den, 1) : null;
    }

    /** Every aggregate the page renders, from the (already filtered) player rows. */
    private function _aggregate(array $players, $start, $end, $window, $today)
    {
        $sourceKeys = array_merge(array_keys(RecruitmentSource::CODES), [self::SOURCE_NONE]);
        $labels = RecruitmentSource::CODES + [self::SOURCE_NONE => RecruitmentSource::NOT_ANSWERED_LABEL];

        // Month buckets for the whole range, so empty months still show.
        $months = [];
        $cur = substr($start, 0, 7) . '-01';
        $last = substr($end, 0, 7) . '-01';
        while ($cur <= $last && count($months) < 600) {
            $months[] = substr($cur, 0, 7);
            $cur = date('Y-m-01', strtotime($cur . ' +1 month'));
        }
        $monthIdx = array_flip($months);
        $monthly = [];
        foreach ($sourceKeys as $k) {
            $monthly[$k] = array_fill(0, count($months), 0);
        }

        $blank = function () {
            $b = ['N' => 0, 'Back' => 0, 'BackElig' => 0, 'BackOfElig' => 0, 'Dues' => 0, 'Funnel' => array_fill_keys(self::FUNNEL, 0), 'Elig' => [], 'Play' => []];
            foreach (self::MILESTONES as $m) {
                $b['Elig'][$m] = 0;
                $b['Play'][$m] = 0;
            }
            return $b;
        };
        $bySource = [];
        foreach ($sourceKeys as $k) {
            $bySource[$k] = $blank();
        }
        $all = $blank();
        $flows = ['all' => []];
        foreach ($sourceKeys as $k) {
            $flows[$k] = [];
        }
        $award = ['with' => $blank(), 'without' => $blank()];
        $awardTooRecent = 0;
        $detail = [];
        $answered = 0;

        foreach ($players as $p) {
            $key = $p['Source'] === '' ? self::SOURCE_NONE : $p['Source'];
            $age = self::_days($p['FirstDate'], $today);
            $span = $p['LastDate'] !== '' ? self::_days($p['FirstDate'], $p['LastDate']) : 0;
            $back = $p['SignIns'] >= 2;
            if ($key !== self::SOURCE_NONE) {
                $answered++;
            }

            $mi = $monthIdx[substr($p['FirstDate'], 0, 7)] ?? null;
            if ($mi !== null) {
                $monthly[$key][$mi]++;
            }

            $tally = function (&$b) use ($p, $age, $span, $back) {
                $b['N']++;
                $b['Back'] += $back ? 1 : 0;
                if ($age >= self::COME_BACK_DAYS) {
                    $b['BackElig']++;
                    $b['BackOfElig'] += $back ? 1 : 0;
                }
                $b['Dues'] += $p['DuesPaid'] ? 1 : 0;
                foreach (self::FUNNEL as $f) {
                    if ($p['SignIns'] >= $f) {
                        $b['Funnel'][$f]++;
                    }
                }
                foreach (self::MILESTONES as $m) {
                    if ($age >= $m) {
                        $b['Elig'][$m]++;
                        $b['Play'][$m] += $span >= $m ? 1 : 0;
                    }
                }
            };
            $tally($all);
            $tally($bySource[$key]);

            // Retention flow: the path this player takes through the Sankey.
            $path = $this->_flowPath($back, $age, $span);
            foreach (['all', $key] as $fk) {
                $prev = 'New players';
                foreach ($path as $node) {
                    $flows[$fk][$prev][$node] = ($flows[$fk][$prev][$node] ?? 0) + 1;
                    $prev = $node;
                }
            }

            $daysToAward = $p['FirstAward'] !== null ? self::_days($p['FirstDate'], $p['FirstAward']) : null;
            if ($age >= $window) {
                $tally($award[$daysToAward !== null && $daysToAward <= $window ? 'with' : 'without']);
            } else {
                $awardTooRecent++;
            }

            $detail[] = [
                'MundaneId'  => $p['MundaneId'],
                'Persona'    => $p['Persona'],
                'ParkId'     => $p['ParkId'],
                'ParkName'   => $p['ParkName'],
                'FirstDate'  => $p['FirstDate'],
                'Source'     => $labels[$key],
                'SourceKey'  => $key,
                'SignIns'    => $p['SignIns'],
                'LastDate'   => $p['LastDate'],
                'FirstAward' => $p['FirstAward'],
                'DuesPaid'   => $p['DuesPaid'],
            ];
        }

        $n = $all['N'];
        $sources = [];
        foreach ($sourceKeys as $k) {
            $b = $bySource[$k];
            $sources[] = [
                'Key'        => $k,
                'Label'      => $labels[$k],
                'N'          => $b['N'],
                'PctOfTotal' => self::_pct($b['N'], $n),
                'Back'       => $b['BackOfElig'],
                'BackElig'   => $b['BackElig'],
                'BackPct'    => self::_pct($b['BackOfElig'], $b['BackElig']),
                'Retention'  => self::_retention($b),
                'Dues'       => $b['Dues'],
                'DuesPct'    => self::_pct($b['Dues'], $b['N']),
            ];
        }

        $funnel = [];
        foreach (self::FUNNEL as $f) {
            $funnel[] = ['Reached' => $f, 'N' => $all['Funnel'][$f], 'Pct' => self::_pct($all['Funnel'][$f], $n)];
        }

        $sankey = [];
        foreach ($flows as $fk => $edges) {
            $links = [];
            foreach ($edges as $from => $tos) {
                foreach ($tos as $to => $v) {
                    $links[] = ['source' => $from, 'target' => $to, 'value' => $v];
                }
            }
            $sankey[$fk] = $links;
        }

        $retAll = self::_retention($all);
        return [
            'Stats' => [
                'NewPlayers'    => $n,
                'Answered'      => $answered,
                'AnsweredPct'   => self::_pct($answered, $n),
                'Back'          => $all['BackOfElig'],
                'BackElig'      => $all['BackElig'],
                'BackPct'       => self::_pct($all['BackOfElig'], $all['BackElig']),
                'Day90'         => $retAll[90],
            ],
            'Sources'  => $sources,
            'Overall'  => [
                'N' => $n, 'BackElig' => $all['BackElig'], 'BackPct' => self::_pct($all['BackOfElig'], $all['BackElig']), 'Retention' => $retAll,
                'Dues' => $all['Dues'], 'DuesPct' => self::_pct($all['Dues'], $n),
            ],
            'Monthly'  => ['Months' => $months, 'Series' => $monthly],
            'Sankey'   => $sankey,
            'Funnel'   => $funnel,
            'EarlyAward' => [
                'Window'    => $window,
                'TooRecent' => $awardTooRecent,
                'With'      => ['N' => $award['with']['N'], 'Retention' => self::_retention($award['with'])],
                'Without'   => ['N' => $award['without']['N'], 'Retention' => self::_retention($award['without'])],
            ],
            'Players'  => $detail,
            'Milestones' => self::MILESTONES,
        ];
    }

    /** milestone => [Eligible, Playing, Pct, TooRecent]. */
    private static function _retention(array $b)
    {
        $out = [];
        foreach (self::MILESTONES as $m) {
            $out[$m] = [
                'Eligible'  => $b['Elig'][$m],
                'Playing'   => $b['Play'][$m],
                'Pct'       => self::_pct($b['Play'][$m], $b['Elig'][$m]),
                'TooRecent' => $b['N'] - $b['Elig'][$m],
            ];
        }
        return $out;
    }

    /**
     * Sankey nodes after "New players". Lapsed and too-recent are sinks: still
     * playing at day N is monotonic (any sign-in on/after day N), so nobody
     * re-enters a later stage.
     */
    private function _flowPath($back, $age, $span)
    {
        if (!$back) {
            return [$age < self::COME_BACK_DAYS ? 'Not back yet' : 'Never came back'];
        }
        $path = ['Came back'];
        foreach (self::FLOW_MILESTONES as $m) {
            if ($age < $m) {
                $path[] = "Day $m: too recent";
                break;
            }
            if ($span < $m) {
                $path[] = "Day $m: lapsed";
                break;
            }
            $path[] = "Day $m: still playing";
        }
        return $path;
    }
}
