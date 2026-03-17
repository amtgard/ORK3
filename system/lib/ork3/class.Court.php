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
                    ca.notes, ca.status, ca.scroll_status, ca.regalia_status,
                    m.persona, p.abbreviation AS park_abbrev,
                    IFNULL(ka.name, a.name) AS award_name,
                    a.is_ladder, IFNULL(a.is_title, 0) AS is_title
             FROM ' . DB_PREFIX . 'court_award ca
             LEFT JOIN ' . DB_PREFIX . 'mundane m      ON m.mundane_id         = ca.mundane_id
             LEFT JOIN ' . DB_PREFIX . 'park p          ON p.park_id            = m.park_id
             LEFT JOIN ' . DB_PREFIX . 'kingdomaward ka ON ka.kingdomaward_id   = ca.kingdomaward_id
             LEFT JOIN ' . DB_PREFIX . 'award a         ON a.award_id           = ka.award_id
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
                    'Status'            => $rs->status,
                    'ScrollStatus'      => (int)$rs->scroll_status,
                    'RegaliaStatus'     => (int)$rs->regalia_status,
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

    public function getPendingRecommendations($kingdom_id, $park_id = 0) {
        $location_clause = $park_id > 0
            ? 'm.park_id = ' . (int)$park_id
            : 'm.kingdom_id = ' . (int)$kingdom_id;

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT recs.recommendations_id, recs.mundane_id, recs.kingdomaward_id,
                    recs.rank, recs.reason, recs.date_recommended,
                    m.persona, p.abbreviation AS park_abbrev,
                    IFNULL(ka.name, a.name) AS award_name,
                    a.is_ladder,
                    (SELECT COUNT(ca.court_award_id)
                       FROM ' . DB_PREFIX . 'court_award ca
                      WHERE ca.recommendations_id = recs.recommendations_id
                        AND ca.status != \'cancelled\') AS already_planned
             FROM ' . DB_PREFIX . 'recommendations recs
             LEFT JOIN ' . DB_PREFIX . 'mundane m       ON m.mundane_id       = recs.mundane_id
             LEFT JOIN ' . DB_PREFIX . 'park p          ON p.park_id          = m.park_id
             LEFT JOIN ' . DB_PREFIX . 'kingdomaward ka  ON ka.kingdomaward_id = recs.kingdomaward_id
             LEFT JOIN ' . DB_PREFIX . 'award a          ON a.award_id         = ka.award_id
             WHERE (recs.deleted_by IS NULL OR recs.deleted_by = 0)
               AND ' . $location_clause . '
             HAVING (
                 SELECT COUNT(aw.awards_id) FROM ' . DB_PREFIX . 'awards aw
                  WHERE aw.mundane_id = recs.mundane_id
                    AND aw.kingdomaward_id = ka.kingdomaward_id
                    AND aw.rank >= recs.rank
             ) = 0
             ORDER BY m.persona, a.name, recs.rank'
        );

        $recs = [];
        if ($rs) {
            while ($rs->Next()) {
                $recs[] = [
                    'RecommendationsId' => (int)$rs->recommendations_id,
                    'MundaneId'         => (int)$rs->mundane_id,
                    'Persona'           => $rs->persona,
                    'KingdomAwardId'    => (int)$rs->kingdomaward_id,
                    'AwardName'         => $rs->award_name,
                    'IsLadder'          => (bool)(int)$rs->is_ladder,
                    'Rank'              => (int)$rs->rank,
                    'Reason'            => $rs->reason,
                    'DateRecommended'   => $rs->date_recommended,
                    'ParkAbbrev'        => $rs->park_abbrev ?? '',
                    'AlreadyPlanned'    => (int)$rs->already_planned > 0,
                ];
            }
        }
        return $recs;
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
}
