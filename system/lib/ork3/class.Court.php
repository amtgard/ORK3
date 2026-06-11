<?php

class Court {

    private $db;

    public function __construct() {
        global $DB;
        $this->db = $DB;
    }

    // -----------------------------------------------------------------------
    // Auth
    // -----------------------------------------------------------------------

    /**
     * Returns true if $uid may manage courts for this kingdom/park.
     * Grants access to: kingdom editors, park editors (for park courts),
     * and officers with role Monarch/Regent/Prime Minister.
     */
    public function canManage($uid, $kingdom_id, $park_id = 0) {
        if ($uid <= 0 || !valid_id($kingdom_id)) return false;

        if (Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT))
            return true;

        if ($park_id > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, $park_id, AUTH_EDIT))
            return true;

        // Check officer role (kingdom-level)
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . 'officer
             WHERE mundane_id = ' . (int)$uid . '
               AND kingdom_id = ' . (int)$kingdom_id . '
               AND park_id = 0
               AND role IN (\'Monarch\',\'Regent\',\'Prime Minister\')
             LIMIT 1'
        );
        if ($r && $r->Next()) return true;

        // Check officer role (park-level)
        if ($park_id > 0) {
            $this->db->Clear();
            $r2 = $this->db->DataSet(
                'SELECT 1 FROM ' . DB_PREFIX . 'officer
                 WHERE mundane_id = ' . (int)$uid . '
                   AND kingdom_id = ' . (int)$kingdom_id . '
                   AND park_id = ' . (int)$park_id . '
                   AND role IN (\'Monarch\',\'Regent\',\'Prime Minister\')
                 LIMIT 1'
            );
            if ($r2 && $r2->Next()) return true;
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Courts
    // -----------------------------------------------------------------------

    public function getCourtList($kingdom_id, $park_id = 0) {
        $where = 'c.kingdom_id = ' . (int)$kingdom_id;
        $where .= $park_id > 0
            ? ' AND c.park_id = ' . (int)$park_id
            : ' AND c.park_id = 0';

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT c.court_id, c.name, c.court_date, c.status,
                    c.event_calendardetail_id,
                    COUNT(ca.court_award_id) AS award_count,
                    e.name AS event_name
             FROM ' . DB_PREFIX . 'court c
             LEFT JOIN ' . DB_PREFIX . 'court_award ca
                    ON ca.court_id = c.court_id AND ca.status != \'cancelled\'
             LEFT JOIN ' . DB_PREFIX . 'event_calendardetail cd
                    ON cd.event_calendardetail_id = c.event_calendardetail_id
             LEFT JOIN ' . DB_PREFIX . 'event e ON e.event_id = cd.event_id
             WHERE ' . $where . '
             GROUP BY c.court_id
             ORDER BY c.court_date DESC, c.court_id DESC'
        );

        $list = [];
        if ($rs) {
            while ($rs->Next()) {
                $list[] = [
                    'CourtId'               => (int)$rs->court_id,
                    'Name'                  => $rs->name,
                    'CourtDate'             => $rs->court_date,
                    'Status'                => $rs->status,
                    'AwardCount'            => (int)$rs->award_count,
                    'EventName'             => $rs->event_name,
                    'EventCalendarDetailId' => (int)$rs->event_calendardetail_id,
                ];
            }
        }
        return $list;
    }

    public function getCourtDetail($court_id) {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT c.*,
                    e.name   AS event_name,
                    p.name   AS park_name,
                    k.name   AS kingdom_name,
                    cd.event_start
             FROM ' . DB_PREFIX . 'court c
             LEFT JOIN ' . DB_PREFIX . 'event_calendardetail cd
                    ON cd.event_calendardetail_id = c.event_calendardetail_id
             LEFT JOIN ' . DB_PREFIX . 'event e ON e.event_id = cd.event_id
             LEFT JOIN ' . DB_PREFIX . 'park p   ON p.park_id   = c.park_id
             LEFT JOIN ' . DB_PREFIX . 'kingdom k ON k.kingdom_id = c.kingdom_id
             WHERE c.court_id = ' . (int)$court_id . '
             LIMIT 1'
        );
        if (!$rs || !$rs->Next()) return null;

        return [
            'CourtId'               => (int)$rs->court_id,
            'KingdomId'             => (int)$rs->kingdom_id,
            'ParkId'                => (int)$rs->park_id,
            'Name'                  => $rs->name,
            'CourtDate'             => $rs->court_date,
            'Status'                => $rs->status,
            'EventCalendarDetailId' => (int)$rs->event_calendardetail_id,
            'EventName'             => $rs->event_name,
            'ParkName'              => $rs->park_name,
            'KingdomName'           => $rs->kingdom_name,
            'CreatedBy'             => (int)$rs->created_by,
        ];
    }

    // -----------------------------------------------------------------------
    // Court awards
    // -----------------------------------------------------------------------

    public function getCourtAwards($court_id) {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT ca.court_award_id, ca.mundane_id, ca.kingdomaward_id, ca.rank,
                    ca.recommendations_id, ca.sort_order, ca.pass_to_local,
                    ca.notes, ca.public_comment, ca.status, ca.scroll_status, ca.regalia_status,
                    ca.scroll_maker_id, ca.regalia_maker_id,
                    sm.persona AS scroll_maker_persona, rm.persona AS regalia_maker_persona,
                    m.persona, p.abbreviation AS park_abbrev,
                    IFNULL(ka.name, a.name) AS award_name,
                    a.is_ladder, IFNULL(a.is_title, 0) AS is_title,
                    rec.reason AS rec_reason, rec.mask_giver,
                    rb.persona AS rec_by_persona
             FROM ' . DB_PREFIX . 'court_award ca
             LEFT JOIN ' . DB_PREFIX . 'mundane m      ON m.mundane_id         = ca.mundane_id
             LEFT JOIN ' . DB_PREFIX . 'park p          ON p.park_id            = m.park_id
             LEFT JOIN ' . DB_PREFIX . 'kingdomaward ka ON ka.kingdomaward_id   = ca.kingdomaward_id
             LEFT JOIN ' . DB_PREFIX . 'award a         ON a.award_id           = ka.award_id
             LEFT JOIN ' . DB_PREFIX . 'mundane sm      ON sm.mundane_id        = ca.scroll_maker_id
             LEFT JOIN ' . DB_PREFIX . 'mundane rm      ON rm.mundane_id        = ca.regalia_maker_id
             LEFT JOIN ' . DB_PREFIX . 'recommendations rec ON rec.recommendations_id = ca.recommendations_id
             LEFT JOIN ' . DB_PREFIX . 'mundane rb      ON rb.mundane_id        = rec.recommended_by_id
             WHERE ca.court_id = ' . (int)$court_id . '
             ORDER BY ca.sort_order, ca.court_award_id'
        );

        $awards = [];
        if ($rs) {
            while ($rs->Next()) {
                $awards[(int)$rs->court_award_id] = [
                    'CourtAwardId'      => (int)$rs->court_award_id,
                    'MundaneId'         => (int)$rs->mundane_id,
                    'Persona'           => $rs->persona,
                    'ParkAbbrev'        => $rs->park_abbrev ?? '',
                    'KingdomAwardId'    => (int)$rs->kingdomaward_id,
                    'AwardName'         => $rs->award_name,
                    'IsLadder'          => (bool)(int)$rs->is_ladder,
                    'IsTitle'           => (bool)(int)$rs->is_title,
                    'Rank'              => (int)$rs->rank,
                    'RecommendationsId' => $rs->recommendations_id ? (int)$rs->recommendations_id : null,
                    'SortOrder'         => (int)$rs->sort_order,
                    'PassToLocal'       => (bool)(int)$rs->pass_to_local,
                    'Notes'             => $rs->notes ?? '',
                    'PublicComment'     => $rs->public_comment ?? '',
                    'Status'            => $rs->status,
                    'ScrollStatus'      => (int)$rs->scroll_status,
                    'RegaliaStatus'     => (int)$rs->regalia_status,
                    'ScrollMakerId'      => $rs->scroll_maker_id ? (int)$rs->scroll_maker_id : null,
                    'ScrollMakerPersona' => $rs->scroll_maker_persona ?? '',
                    'RegaliaMakerId'     => $rs->regalia_maker_id ? (int)$rs->regalia_maker_id : null,
                    'RegaliaMakerPersona' => $rs->regalia_maker_persona ?? '',
                    'RecReason'         => $rs->rec_reason ?? '',
                    'RecByPersona'      => (isset($rs->mask_giver) && (int)$rs->mask_giver) ? null : ($rs->rec_by_persona ?? null),
                    'Artisans'          => [],
                ];
            }
        }

        // Batch-load artisans
        if (!empty($awards)) {
            $ids = implode(',', array_keys($awards));
            $this->db->Clear();
            $ars = $this->db->DataSet(
                'SELECT caa.court_award_artisan_id, caa.court_award_id,
                        caa.mundane_id, caa.contribution, m.persona
                 FROM ' . DB_PREFIX . 'court_award_artisan caa
                 LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = caa.mundane_id
                 WHERE caa.court_award_id IN (' . $ids . ')
                 ORDER BY caa.court_award_artisan_id'
            );
            if ($ars) {
                while ($ars->Next()) {
                    $cid = (int)$ars->court_award_id;
                    if (isset($awards[$cid])) {
                        $awards[$cid]['Artisans'][] = [
                            'CourtAwardArtisanId' => (int)$ars->court_award_artisan_id,
                            'MundaneId'           => (int)$ars->mundane_id,
                            'Persona'             => $ars->persona,
                            'Contribution'        => $ars->contribution,
                        ];
                    }
                }
            }
        }

        return array_values($awards);
    }

    // -----------------------------------------------------------------------
    // Pending recommendations (for the add-from-rec modal)
    // -----------------------------------------------------------------------

    public function getPendingRecommendations($kingdom_id, $park_id = 0, $caller_uid = 0, $court_id = 0) {
        // Delegate the heavy lifting (Master-peerage cascade, custom-award carve-out,
        // award_id cross-check, snooze awareness, age, seconds, anon masking) to
        // Report->recommended_awards — the same data path the Kingdom Recs tab uses.
        // We then post-process to:
        //   - look up which recs are on THIS court vs SOME OTHER court
        //   - look up the park abbreviation (recommended_awards doesn't return it)
        //   - map the field names to what the Court Planner template expects
        $req = ['RequestedBy' => (int)$caller_uid];
        if ($park_id > 0) {
            $req['ParkId']    = (int)$park_id;
            $req['KingdomId'] = 0;
        } else {
            $req['KingdomId'] = (int)$kingdom_id;
            $req['ParkId']    = 0;
        }
        $res = Ork3::$Lib->report->PlayerAwardRecommendations($req);
        $rawRecs = is_array($res) && isset($res['AwardRecommendations']) && is_array($res['AwardRecommendations'])
            ? $res['AwardRecommendations']
            : [];
        if (empty($rawRecs)) return [];

        // Park abbreviation lookup — recommended_awards has ParkName but not abbrev.
        $parkIds = array_unique(array_filter(array_map(fn($r) => (int)($r['ParkId'] ?? 0), $rawRecs)));
        $parkAbbrev = [];
        if (!empty($parkIds)) {
            $idCsv = implode(',', $parkIds);
            $this->db->Clear();
            $pr = $this->db->DataSet('SELECT park_id, abbreviation FROM ' . DB_PREFIX . 'park WHERE park_id IN (' . $idCsv . ')');
            if ($pr) {
                do { $parkAbbrev[(int)$pr->park_id] = (string)$pr->abbreviation; } while ($pr->Next());
            }
        }

        // Per-rec court-plan mapping: which court is each rec on (if any)?
        $recIds = array_map(fn($r) => (int)$r['RecommendationsId'], $rawRecs);
        $onCourt = [];  // recommendations_id => [court_id => true]
        if (!empty($recIds)) {
            $idCsv = implode(',', $recIds);
            $this->db->Clear();
            $cr = $this->db->DataSet(
                'SELECT recommendations_id, court_id FROM ' . DB_PREFIX . 'court_award
                 WHERE recommendations_id IN (' . $idCsv . ') AND status != \'cancelled\''
            );
            if ($cr) {
                do { $onCourt[(int)$cr->recommendations_id][(int)$cr->court_id] = true; } while ($cr->Next());
            }
        }

        $curCourt = (int)$court_id;
        $out = [];
        foreach ($rawRecs as $r) {
            $rid = (int)$r['RecommendationsId'];
            $plans = $onCourt[$rid] ?? [];
            $isOnThis  = $curCourt > 0 && !empty($plans[$curCourt]);
            $isOnOther = !empty(array_diff_key($plans, [$curCourt => true]));
            // Skip recs already on THIS court — they can't be added again, and the user
            // can already see them in the main Order-of-Court list.
            if ($isOnThis) continue;
            $out[] = [
                'RecommendationsId' => $rid,
                'MundaneId'         => (int)$r['MundaneId'],
                'Persona'           => $r['Persona'],
                'KingdomAwardId'    => (int)$r['KingdomAwardId'],
                'AwardName'         => $r['AwardName'],
                'IsLadder'          => (int)($r['Rank'] ?? 0) > 0,  // proxy: only used for "Rank N" suffix display
                'Rank'              => (int)$r['Rank'],
                'Reason'            => $r['Reason'],
                'DateRecommended'   => $r['DateRecommended'],
                'ParkAbbrev'        => $parkAbbrev[(int)$r['ParkId']] ?? '',
                'AlreadyPlanned'    => false,           // preserved for backward compat (always false here since we filtered above)
                'IsOnOtherCourt'    => $isOnOther,
                // Pass-through eligibility/context flags from Reports
                'AlreadyHas'        => !empty($r['AlreadyHas']),
                'CoveredByMaster'   => !empty($r['CoveredByMaster']),
                'CurrentRank'       => isset($r['CurrentRank']) ? (int)$r['CurrentRank'] : null,
                'CurrentRankDate'   => $r['CurrentRankDate'] ?? null,
                'IsSnoozed'         => !empty($r['IsSnoozed']),
                'AgeDays'           => (int)($r['AgeDays'] ?? 0),
                'SecondsCount'      => (int)($r['SecondsCount'] ?? 0),
                'IsAnonymous'       => !empty($r['IsAnonymous']),
                'RecommendedByName' => $r['RecommendedByName'] ?? null,
            ];
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Recommendation → court map (for Recommendations Manager)
    // -----------------------------------------------------------------------

    /**
     * Map of recommendation_id => list of courts it currently sits on, scoped.
     * Used by the Recommendations Manager to show court badges and the court filter.
     */
    public function getRecommendationCourtMap($kingdom_id, $park_id = 0) {
        if (!valid_id($kingdom_id)) return [];
        $scope = 'c.kingdom_id = ' . (int)$kingdom_id;
        if ($park_id > 0) $scope .= ' AND c.park_id = ' . (int)$park_id;

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT ca.recommendations_id AS rid, ca.court_award_id, c.court_id, c.name, c.court_date, c.status
               FROM ' . DB_PREFIX . 'court_award ca
               JOIN ' . DB_PREFIX . 'court c ON c.court_id = ca.court_id
              WHERE ca.recommendations_id > 0
                AND ca.status <> \'cancelled\'
                AND ' . $scope . '
              ORDER BY c.court_date IS NULL, c.court_date ASC, c.court_id ASC'
        );

        $map = [];
        if ($rs) {
            while ($rs->Next()) {
                $rid = (int)$rs->rid;
                $map[$rid][] = [
                    'CourtId'      => (int)$rs->court_id,
                    'CourtAwardId' => (int)$rs->court_award_id,
                    'Name'         => $rs->name,
                    'CourtDate'    => $rs->court_date,
                    'Status'       => $rs->status,
                ];
            }
        }
        return $map;
    }

    // -----------------------------------------------------------------------
    // Kingdom award list (for ad-hoc award modal)
    // -----------------------------------------------------------------------

    public function getKingdomAwardOptions($kingdom_id) {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT ka.kingdomaward_id, IFNULL(ka.name, a.name) AS award_name, a.is_ladder
             FROM ' . DB_PREFIX . 'kingdomaward ka
             LEFT JOIN ' . DB_PREFIX . 'award a ON a.award_id = ka.award_id
             WHERE ka.kingdom_id = ' . (int)$kingdom_id . '
             ORDER BY award_name'
        );
        $options = [];
        if ($rs) {
            while ($rs->Next()) {
                $options[] = [
                    'KingdomAwardId' => (int)$rs->kingdomaward_id,
                    'AwardName'      => $rs->award_name,
                    'IsLadder'       => (bool)(int)$rs->is_ladder,
                ];
            }
        }
        return $options;
    }

    // -----------------------------------------------------------------------
    // Upcoming events (for linking a court to an event)
    // -----------------------------------------------------------------------

    public function getUpcomingEvents($kingdom_id) {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT cd.event_calendardetail_id, e.name, cd.event_start
             FROM ' . DB_PREFIX . 'event_calendardetail cd
             LEFT JOIN ' . DB_PREFIX . 'event e ON e.event_id = cd.event_id
             WHERE e.kingdom_id = ' . (int)$kingdom_id . '
               AND cd.event_start >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY cd.event_start
             LIMIT 50'
        );
        $events = [];
        if ($rs) {
            while ($rs->Next()) {
                $events[] = [
                    'EventCalendarDetailId' => (int)$rs->event_calendardetail_id,
                    'Name'                  => $rs->name,
                    'EventStart'            => $rs->event_start,
                ];
            }
        }
        return $events;
    }

    public function updateAwardTrackingStatus($courtAwardId, $type) {
        if (!in_array($type, ['scroll', 'regalia'])) {
            return ['status' => 1, 'error' => 'Invalid type'];
        }

        $field = $type . '_status';

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT ' . $field . ' FROM ' . DB_PREFIX . 'court_award WHERE court_award_id = ' . (int)$courtAwardId
        );
        if (!$rs || !$rs->Next()) {
            return ['status' => 1, 'error' => 'Award not found'];
        }

        $currentStatus = (int)$rs->{$field};
        $nextStatus = ($currentStatus + 1) % 3;

        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'court_award SET ' . $field . ' = ' . $nextStatus . ' WHERE court_award_id = ' . (int)$courtAwardId
        );

        return ['status' => 0, 'newStatus' => $nextStatus];
    }
    // -----------------------------------------------------------------------
    // Court Report (public, read-only) — see docs/superpowers/specs/2026-05-28-court-report-design.md
    // -----------------------------------------------------------------------

    /**
     * Validate a Y-m-d date string; return it if valid, else null.
     */
    private function validDate($d) {
        return (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
    }

    /**
     * Courts in [$from_date, $until_date] (inclusive on court_date) that have at
     * least one award with status='given'.
     *   Kingdom report ($kingdom_id set, $park_id = 0): courts in that kingdom.
     *   Park report ($park_id set): courts owned by the park OR any court holding a
     *     given award whose recipient's home park is $park_id.
     */
    public function getCourtReportList($kingdom_id, $park_id, $from_date, $until_date) {
        $from  = $this->validDate($from_date)  ?? date('Y-m-d', strtotime('-6 months'));
        $until = $this->validDate($until_date) ?? date('Y-m-d');
        $kingdom_id = (int)$kingdom_id;
        $park_id    = (int)$park_id;

        if ($park_id > 0) {
            // Park scope: GivenCount counts the given awards on each court that are
            // relevant to this park (park-owned courts → all; kingdom courts → only
            // park-home recipients). The detail page shows the full court, so its
            // award count can legitimately exceed a kingdom court's list GivenCount.
            $scopeJoin  = ' LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = ca.mundane_id';
            $scopeWhere = '(c.park_id = ' . $park_id . ' OR m.park_id = ' . $park_id . ')';
        } else {
            $scopeJoin  = '';
            $scopeWhere = 'c.kingdom_id = ' . $kingdom_id;
        }

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT c.court_id, c.name, c.court_date, c.park_id, c.kingdom_id,
                    e.name AS event_name, p.name AS park_name,
                    COUNT(DISTINCT ca.court_award_id) AS given_count
             FROM ' . DB_PREFIX . 'court c
             JOIN ' . DB_PREFIX . 'court_award ca
                    ON ca.court_id = c.court_id AND ca.status = \'given\'' . $scopeJoin . '
             LEFT JOIN ' . DB_PREFIX . 'event_calendardetail cd
                    ON cd.event_calendardetail_id = c.event_calendardetail_id
             LEFT JOIN ' . DB_PREFIX . 'event e ON e.event_id = cd.event_id
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = c.park_id
             WHERE c.court_date BETWEEN \'' . $from . '\' AND \'' . $until . '\'
               AND ' . $scopeWhere . '
             GROUP BY c.court_id
             ORDER BY c.court_date DESC, c.court_id DESC'
        );

        $list = [];
        if ($rs) {
            while ($rs->Next()) {
                $list[] = [
                    'CourtId'    => (int)$rs->court_id,
                    'Name'       => $rs->name,
                    'CourtDate'  => $rs->court_date,
                    'ParkId'     => (int)$rs->park_id,
                    'KingdomId'  => (int)$rs->kingdom_id,
                    'ParkName'   => $rs->park_name,
                    'EventName'  => $rs->event_name,
                    'GivenCount' => (int)$rs->given_count,
                ];
            }
        }
        return $list;
    }

    /**
     * One court's header plus its status='given' awards (public fields only) with
     * artisans batch-loaded. Returns null if the court does not exist.
     */
    public function getCourtReportDetail($court_id) {
        $court_id = (int)$court_id;

        $this->db->Clear();
        $hr = $this->db->DataSet(
            'SELECT c.court_id, c.kingdom_id, c.park_id, c.name, c.court_date,
                    e.name AS event_name, p.name AS park_name, k.name AS kingdom_name
             FROM ' . DB_PREFIX . 'court c
             LEFT JOIN ' . DB_PREFIX . 'event_calendardetail cd
                    ON cd.event_calendardetail_id = c.event_calendardetail_id
             LEFT JOIN ' . DB_PREFIX . 'event e ON e.event_id = cd.event_id
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = c.park_id
             LEFT JOIN ' . DB_PREFIX . 'kingdom k ON k.kingdom_id = c.kingdom_id
             WHERE c.court_id = ' . (int)$court_id . ' LIMIT 1'
        );
        if (!$hr || !$hr->Next()) return null;

        $court = [
            'CourtId'     => (int)$hr->court_id,
            'KingdomId'   => (int)$hr->kingdom_id,
            'ParkId'      => (int)$hr->park_id,
            'Name'        => $hr->name,
            'CourtDate'   => $hr->court_date,
            'EventName'   => $hr->event_name,
            'ParkName'    => $hr->park_name,
            'KingdomName' => $hr->kingdom_name,
        ];

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT ca.court_award_id, ca.mundane_id, ca.rank, ca.public_comment,
                    m.persona, p.abbreviation AS park_abbrev,
                    IFNULL(ka.name, a.name) AS award_name, a.is_ladder,
                    sm.persona AS scroll_maker_persona, rm.persona AS regalia_maker_persona
             FROM ' . DB_PREFIX . 'court_award ca
             LEFT JOIN ' . DB_PREFIX . 'mundane m  ON m.mundane_id       = ca.mundane_id
             LEFT JOIN ' . DB_PREFIX . 'park p     ON p.park_id          = m.park_id
             LEFT JOIN ' . DB_PREFIX . 'kingdomaward ka ON ka.kingdomaward_id = ca.kingdomaward_id
             LEFT JOIN ' . DB_PREFIX . 'award a    ON a.award_id         = ka.award_id
             LEFT JOIN ' . DB_PREFIX . 'mundane sm ON sm.mundane_id      = ca.scroll_maker_id
             LEFT JOIN ' . DB_PREFIX . 'mundane rm ON rm.mundane_id      = ca.regalia_maker_id
             WHERE ca.court_id = ' . (int)$court_id . ' AND ca.status = \'given\'
             ORDER BY ca.sort_order, ca.court_award_id'
        );

        $awards = [];
        if ($rs) {
            while ($rs->Next()) {
                $awards[(int)$rs->court_award_id] = [
                    'CourtAwardId'        => (int)$rs->court_award_id,
                    'MundaneId'           => (int)$rs->mundane_id,
                    'Persona'             => $rs->persona,
                    'ParkAbbrev'          => $rs->park_abbrev ?? '',
                    'AwardName'           => $rs->award_name,
                    'IsLadder'            => (bool)(int)$rs->is_ladder,
                    'Rank'                => (int)$rs->rank,
                    'PublicComment'       => $rs->public_comment ?? '',
                    'ScrollMakerPersona'  => $rs->scroll_maker_persona ?? '',
                    'RegaliaMakerPersona' => $rs->regalia_maker_persona ?? '',
                    'Artisans'            => [],
                ];
            }
        }

        if (!empty($awards)) {
            $ids = implode(',', array_keys($awards));
            $this->db->Clear();
            $ars = $this->db->DataSet(
                'SELECT caa.court_award_id, caa.mundane_id, caa.contribution, m.persona
                 FROM ' . DB_PREFIX . 'court_award_artisan caa
                 LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = caa.mundane_id
                 WHERE caa.court_award_id IN (' . $ids . ')
                 ORDER BY caa.court_award_artisan_id'
            );
            if ($ars) {
                while ($ars->Next()) {
                    $cid = (int)$ars->court_award_id;
                    if (isset($awards[$cid])) {
                        $awards[$cid]['Artisans'][] = [
                            'MundaneId'    => (int)$ars->mundane_id,
                            'Persona'      => $ars->persona,
                            'Contribution' => $ars->contribution,
                        ];
                    }
                }
            }
        }

        return ['Court' => $court, 'Awards' => array_values($awards)];
    }

}
