<?php

class Controller_CourtAjax extends Controller {

    public function __construct($call = null, $id = null) {
        parent::__construct($call, $id);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function jsonOut($data) {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    private function requireLogin() {
        if (!isset($this->session->user_id)) {
            $this->jsonOut(['status' => 5, 'error' => 'Not logged in']);
        }
        return (int)$this->session->user_id;
    }

    private function requireCourtAuth($court_id) {
        $uid   = $this->requireLogin();
        $court = Ork3::$Lib->court->getCourtDetail($court_id);
        if (!$court) $this->jsonOut(['status' => 1, 'error' => 'Court not found.']);
        if (!Ork3::$Lib->court->canManage($uid, $court['KingdomId'], $court['ParkId']))
            $this->jsonOut(['status' => 3, 'error' => 'Not authorized.']);
        return [$uid, $court];
    }

    private function esc($v) {
        return str_replace(["'", '\\'], ["''", '\\\\'], $v);
    }

    // -----------------------------------------------------------------------
    // create_court
    // POST: KingdomId, ParkId, Name, CourtDate, EventCalendarDetailId
    // -----------------------------------------------------------------------
    public function create_court($p = null) {
        $uid = $this->requireLogin();

        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $park_id    = (int)($_POST['ParkId']    ?? 0);

        if (!valid_id($kingdom_id))
            $this->jsonOut(['status' => 1, 'error' => 'Invalid kingdom.']);

        if (!Ork3::$Lib->court->canManage($uid, $kingdom_id, $park_id))
            $this->jsonOut(['status' => 3, 'error' => 'Not authorized.']);

        $name       = trim($_POST['Name']       ?? '');
        $court_date = trim($_POST['CourtDate']  ?? '');
        $event_cd   = (int)($_POST['EventCalendarDetailId'] ?? 0);

        if (!$name) $this->jsonOut(['status' => 1, 'error' => 'A name is required.']);

        $date_val   = $court_date ? "'" . $this->esc($court_date) . "'" : 'NULL';
        $event_val  = $event_cd > 0 ? $event_cd : 'NULL';

        global $DB;
        $DB->Clear();
        $DB->Execute(
            'INSERT INTO ' . DB_PREFIX . 'court
             (kingdom_id, park_id, name, court_date, event_calendardetail_id, created_by)
             VALUES (' . $kingdom_id . ', ' . $park_id . ', \'' . $this->esc($name) . '\',
                     ' . $date_val . ', ' . $event_val . ', ' . $uid . ')'
        );
        $DB->Clear();
        $row = $DB->DataSet('SELECT court_id FROM ' . DB_PREFIX . 'court
                              WHERE kingdom_id = ' . $kingdom_id . ' AND created_by = ' . $uid . '
                              ORDER BY court_id DESC LIMIT 1');
        $court_id = ($row && $row->Next()) ? (int)$row->court_id : 0;

        $this->jsonOut(['status' => 0, 'court_id' => $court_id, 'name' => $name]);
    }

    // -----------------------------------------------------------------------
    // update_court_status
    // POST: CourtId, Status
    // -----------------------------------------------------------------------
    public function update_court_status($p = null) {
        $court_id = (int)($_POST['CourtId'] ?? 0);
        [$uid, $court] = $this->requireCourtAuth($court_id);

        $status = trim($_POST['Status'] ?? '');
        $allowed = ['draft', 'published', 'complete'];
        if (!in_array($status, $allowed))
            $this->jsonOut(['status' => 1, 'error' => 'Invalid status.']);

        global $DB;
        $DB->Clear();
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'court SET status = \'' . $status . '\'
             WHERE court_id = ' . $court_id
        );
        $this->jsonOut(['status' => 0, 'new_status' => $status]);
    }

    // -----------------------------------------------------------------------
    // add_award
    // POST: CourtId, MundaneId, KingdomAwardId, Rank, RecommendationsId (opt),
    //       PassToLocal, Notes
    // -----------------------------------------------------------------------
    public function add_award($p = null) {
        $court_id = (int)($_POST['CourtId'] ?? 0);
        [$uid, $court] = $this->requireCourtAuth($court_id);

        $mundane_id      = (int)($_POST['MundaneId']         ?? 0);
        $kingdomaward_id = (int)($_POST['KingdomAwardId']    ?? 0);
        $rank            = (int)($_POST['Rank']               ?? 0);
        $rec_id          = (int)($_POST['RecommendationsId'] ?? 0);
        $pass_to_local   = (int)($_POST['PassToLocal']       ?? 0) ? 1 : 0;
        $notes           = trim($_POST['Notes']               ?? '');

        if (!valid_id($mundane_id))      $this->jsonOut(['status' => 1, 'error' => 'Recipient required.']);
        if (!valid_id($kingdomaward_id)) $this->jsonOut(['status' => 1, 'error' => 'Award required.']);

        // Next sort_order
        global $DB;
        $DB->Clear();
        $sor = $DB->DataSet('SELECT MAX(sort_order) AS m FROM ' . DB_PREFIX . 'court_award
                              WHERE court_id = ' . $court_id);
        $sort = ($sor && $sor->Next()) ? (int)$sor->m + 10 : 10;

        $rec_val   = $rec_id > 0 ? $rec_id : 'NULL';
        $notes_val = "'" . $this->esc($notes) . "'";

        $DB->Clear();
        $DB->Execute(
            'INSERT INTO ' . DB_PREFIX . 'court_award
             (court_id, mundane_id, kingdomaward_id, rank, recommendations_id,
              sort_order, pass_to_local, notes)
             VALUES (' . $court_id . ', ' . $mundane_id . ', ' . $kingdomaward_id . ', ' . $rank . ',
                     ' . $rec_val . ', ' . $sort . ', ' . $pass_to_local . ', ' . $notes_val . ')'
        );
        $DB->Clear();
        $idr = $DB->DataSet('SELECT court_award_id FROM ' . DB_PREFIX . 'court_award
                              WHERE court_id = ' . $court_id . '
                              ORDER BY court_award_id DESC LIMIT 1');
        $court_award_id = ($idr && $idr->Next()) ? (int)$idr->court_award_id : 0;

        // Fetch persona + award_name for response
        $DB->Clear();
        $info = $DB->DataSet(
            'SELECT m.persona, p.abbreviation AS park_abbrev, IFNULL(ka.name, a.name) AS award_name, a.is_ladder, IFNULL(a.is_title, 0) AS is_title
             FROM ' . DB_PREFIX . 'mundane m
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = m.park_id
             JOIN ' . DB_PREFIX . 'kingdomaward ka ON ka.kingdomaward_id = ' . $kingdomaward_id . '
             LEFT JOIN ' . DB_PREFIX . 'award a ON a.award_id = ka.award_id
             WHERE m.mundane_id = ' . $mundane_id . '
             LIMIT 1'
        );
        $persona     = '';
        $park_abbrev = '';
        $award_name  = '';
        $is_ladder   = false;
        $is_title    = false;
        if ($info && $info->Next()) {
            $persona     = $info->persona;
            $park_abbrev = $info->park_abbrev ?? '';
            $award_name  = $info->award_name;
            $is_ladder   = (bool)(int)$info->is_ladder;
            $is_title    = (bool)(int)$info->is_title;
        }

        $this->jsonOut(['status' => 0, 'award' => [
            'CourtAwardId'      => $court_award_id,
            'MundaneId'         => $mundane_id,
            'Persona'           => $persona,
            'ParkAbbrev'        => $park_abbrev,
            'KingdomAwardId'    => $kingdomaward_id,
            'AwardName'         => $award_name,
            'IsLadder'          => $is_ladder,
            'IsTitle'           => $is_title,
            'Rank'              => $rank,
            'RecommendationsId' => $rec_id ?: null,
            'SortOrder'         => $sort,
            'PassToLocal'       => (bool)$pass_to_local,
            'Notes'             => $notes,
            'Status'            => 'planned',
            'ScrollStatus'      => 0,
            'RegaliaStatus'     => 0,
            'Artisans'          => [],
        ]]);
    }

    // -----------------------------------------------------------------------
    // remove_award
    // POST: CourtAwardId
    // -----------------------------------------------------------------------
    public function remove_award($p = null) {
        $court_award_id = (int)($_POST['CourtAwardId'] ?? 0);
        if (!valid_id($court_award_id)) $this->jsonOut(['status' => 1, 'error' => 'Invalid award.']);

        // Look up court_id for auth
        global $DB;
        $DB->Clear();
        $r = $DB->DataSet('SELECT court_id FROM ' . DB_PREFIX . 'court_award
                            WHERE court_award_id = ' . $court_award_id . ' LIMIT 1');
        if (!$r || !$r->Next()) $this->jsonOut(['status' => 1, 'error' => 'Award not found.']);
        $court_id = (int)$r->court_id;

        $this->requireCourtAuth($court_id);

        $DB->Clear();
        $DB->Execute('DELETE FROM ' . DB_PREFIX . 'court_award_artisan
                       WHERE court_award_id = ' . $court_award_id);
        $DB->Clear();
        $DB->Execute('DELETE FROM ' . DB_PREFIX . 'court_award
                       WHERE court_award_id = ' . $court_award_id);

        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // update_award
    // POST: CourtAwardId, Notes, PassToLocal, Status
    // -----------------------------------------------------------------------
    public function update_award($p = null) {
        $court_award_id = (int)($_POST['CourtAwardId'] ?? 0);
        if (!valid_id($court_award_id)) $this->jsonOut(['status' => 1, 'error' => 'Invalid award.']);

        global $DB;
        $DB->Clear();
        $r = $DB->DataSet('SELECT court_id FROM ' . DB_PREFIX . 'court_award
                            WHERE court_award_id = ' . $court_award_id . ' LIMIT 1');
        if (!$r || !$r->Next()) $this->jsonOut(['status' => 1, 'error' => 'Award not found.']);

        $this->requireCourtAuth((int)$r->court_id);

        $notes         = trim($_POST['Notes']        ?? '');
        $pass_to_local = (int)($_POST['PassToLocal'] ?? 0) ? 1 : 0;
        $status        = trim($_POST['Status']       ?? 'planned');
        $allowed       = ['planned', 'announced', 'given', 'cancelled'];
        if (!in_array($status, $allowed)) $status = 'planned';

        $DB->Clear();
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'court_award SET
             notes = \'' . $this->esc($notes) . '\',
             pass_to_local = ' . $pass_to_local . ',
             status = \'' . $status . '\'
             WHERE court_award_id = ' . $court_award_id
        );
        $this->jsonOut(['status' => 0, 'notes' => $notes, 'pass_to_local' => $pass_to_local, 'award_status' => $status]);
    }

    // -----------------------------------------------------------------------
    // reorder_awards
    // POST: CourtId, Order (JSON array of court_award_ids in display order)
    // -----------------------------------------------------------------------
    public function reorder_awards($p = null) {
        $court_id = (int)($_POST['CourtId'] ?? 0);
        $this->requireCourtAuth($court_id);

        $order = json_decode($_POST['Order'] ?? '[]', true);
        if (!is_array($order)) $this->jsonOut(['status' => 1, 'error' => 'Invalid order.']);

        global $DB;
        $sort = 10;
        foreach ($order as $caid) {
            $caid = (int)$caid;
            if ($caid <= 0) continue;
            $DB->Clear();
            $DB->Execute(
                'UPDATE ' . DB_PREFIX . 'court_award
                 SET sort_order = ' . $sort . '
                 WHERE court_award_id = ' . $caid . ' AND court_id = ' . $court_id
            );
            $sort += 10;
        }
        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // add_artisan
    // POST: CourtAwardId, MundaneId, Contribution
    // -----------------------------------------------------------------------
    public function add_artisan($p = null) {
        $court_award_id = (int)($_POST['CourtAwardId'] ?? 0);
        if (!valid_id($court_award_id)) $this->jsonOut(['status' => 1, 'error' => 'Invalid award.']);

        global $DB;
        $DB->Clear();
        $r = $DB->DataSet('SELECT court_id FROM ' . DB_PREFIX . 'court_award
                            WHERE court_award_id = ' . $court_award_id . ' LIMIT 1');
        if (!$r || !$r->Next()) $this->jsonOut(['status' => 1, 'error' => 'Award not found.']);
        $this->requireCourtAuth((int)$r->court_id);

        $mundane_id   = (int)($_POST['MundaneId']    ?? 0);
        $contribution = trim($_POST['Contribution']  ?? '');
        if (!valid_id($mundane_id)) $this->jsonOut(['status' => 1, 'error' => 'Artisan required.']);

        $DB->Clear();
        $DB->Execute(
            'INSERT INTO ' . DB_PREFIX . 'court_award_artisan
             (court_award_id, mundane_id, contribution)
             VALUES (' . $court_award_id . ', ' . $mundane_id . ',
                     \'' . $this->esc($contribution) . '\')'
        );
        $DB->Clear();
        $idr = $DB->DataSet('SELECT caa.court_award_artisan_id, m.persona
                              FROM ' . DB_PREFIX . 'court_award_artisan caa
                              LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = caa.mundane_id
                              WHERE caa.court_award_id = ' . $court_award_id . '
                              ORDER BY caa.court_award_artisan_id DESC LIMIT 1');
        $artisan_id = 0;
        $persona    = '';
        if ($idr && $idr->Next()) {
            $artisan_id = (int)$idr->court_award_artisan_id;
            $persona    = $idr->persona;
        }

        $this->jsonOut(['status' => 0, 'artisan' => [
            'CourtAwardArtisanId' => $artisan_id,
            'MundaneId'           => $mundane_id,
            'Persona'             => $persona,
            'Contribution'        => $contribution,
        ]]);
    }

    // -----------------------------------------------------------------------
    // remove_artisan
    // POST: CourtAwardArtisanId
    // -----------------------------------------------------------------------
    public function remove_artisan($p = null) {
        $artisan_id = (int)($_POST['CourtAwardArtisanId'] ?? 0);
        if (!valid_id($artisan_id)) $this->jsonOut(['status' => 1, 'error' => 'Invalid artisan.']);

        global $DB;
        $DB->Clear();
        $r = $DB->DataSet(
            'SELECT caa.court_award_id, ca.court_id
             FROM ' . DB_PREFIX . 'court_award_artisan caa
             LEFT JOIN ' . DB_PREFIX . 'court_award ca ON ca.court_award_id = caa.court_award_id
             WHERE caa.court_award_artisan_id = ' . $artisan_id . ' LIMIT 1'
        );
        if (!$r || !$r->Next()) $this->jsonOut(['status' => 1, 'error' => 'Artisan not found.']);
        $this->requireCourtAuth((int)$r->court_id);

        $DB->Clear();
        $DB->Execute('DELETE FROM ' . DB_PREFIX . 'court_award_artisan
                       WHERE court_award_artisan_id = ' . $artisan_id);

        $this->jsonOut(['status' => 0]);
    }
    // -----------------------------------------------------------------------
    // grant_award
    // POST: CourtAwardId
    // -----------------------------------------------------------------------
    public function grant_award($p = null) {
        $court_award_id = (int)($_POST['CourtAwardId'] ?? 0);
        if (!valid_id($court_award_id)) $this->jsonOut(['status' => 1, 'error' => 'Invalid award.']);

        global $DB;
        $DB->Clear();
        $ca = $DB->DataSet(
            'SELECT ca.*, c.court_date, c.kingdom_id AS c_kingdom_id, c.park_id AS c_park_id,
                    c.event_calendardetail_id, c.status AS court_status
             FROM ' . DB_PREFIX . 'court_award ca
             JOIN ' . DB_PREFIX . 'court c ON c.court_id = ca.court_id
             WHERE ca.court_award_id = ' . $court_award_id . ' LIMIT 1'
        );
        if (!$ca || !$ca->Next()) $this->jsonOut(['status' => 1, 'error' => 'Award not found.']);

        [$uid, $court] = $this->requireCourtAuth((int)$ca->court_id);

        if ($ca->court_status !== 'published')
            $this->jsonOut(['status' => 1, 'error' => 'Court must be published to grant awards.']);
        if (in_array($ca->status, ['given', 'cancelled']))
            $this->jsonOut(['status' => 1, 'error' => 'Award already resolved.']);

        // Resolve event_id from calendardetail
        $event_id = 0;
        if ((int)$ca->event_calendardetail_id > 0) {
            $DB->Clear();
            $ev = $DB->DataSet(
                'SELECT event_id FROM ' . DB_PREFIX . 'event_calendardetail
                 WHERE event_calendardetail_id = ' . (int)$ca->event_calendardetail_id . ' LIMIT 1'
            );
            if ($ev && $ev->Next()) $event_id = (int)$ev->event_id;
        }

        $date = $ca->court_date ?: date('Y-m-d');

        $this->load_model('Player');
        $r = $this->Player->add_player_award([
            'Token'          => $this->session->token,
            'RecipientId'    => (int)$ca->mundane_id,
            'KingdomAwardId' => (int)$ca->kingdomaward_id,
            'AwardId'        => 0,
            'Rank'           => (int)$ca->rank,
            'Date'           => $date,
            'GivenById'      => 0,
            'CustomName'     => '',
            'Note'           => $ca->notes ?? '',
            'ParkId'         => (int)$ca->c_park_id,
            'KingdomId'      => (int)$ca->c_kingdom_id,
            'EventId'        => $event_id,
        ]);

        if (($r['Status'] ?? 1) != 0)
            $this->jsonOut(['status' => 1, 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        $DB->Clear();
        $DB->Execute(
            "UPDATE " . DB_PREFIX . "court_award SET status = 'given' WHERE court_award_id = " . $court_award_id
        );

        // Soft-delete the linked recommendation
        if ((int)$ca->recommendations_id > 0) {
            $DB->Clear();
            $DB->Execute(
                'UPDATE ' . DB_PREFIX . 'recommendations
                 SET deleted_by = ' . $uid . ', deleted_at = NOW()
                 WHERE recommendations_id = ' . (int)$ca->recommendations_id
            );
        }

        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // skip_award
    // POST: CourtAwardId
    // -----------------------------------------------------------------------
    public function skip_award($p = null) {
        $court_award_id = (int)($_POST['CourtAwardId'] ?? 0);
        if (!valid_id($court_award_id)) $this->jsonOut(['status' => 1, 'error' => 'Invalid award.']);

        global $DB;
        $DB->Clear();
        $ca = $DB->DataSet(
            'SELECT ca.court_id, c.status AS court_status FROM ' . DB_PREFIX . 'court_award ca
             JOIN ' . DB_PREFIX . 'court c ON c.court_id = ca.court_id
             WHERE ca.court_award_id = ' . $court_award_id . ' LIMIT 1'
        );
        if (!$ca || !$ca->Next()) $this->jsonOut(['status' => 1, 'error' => 'Award not found.']);

        [$uid, $court] = $this->requireCourtAuth((int)$ca->court_id);

        if ($ca->court_status !== 'published')
            $this->jsonOut(['status' => 1, 'error' => 'Court must be published to skip awards.']);

        $DB->Clear();
        $DB->Execute(
            "UPDATE " . DB_PREFIX . "court_award SET status = 'cancelled' WHERE court_award_id = " . $court_award_id
        );

        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // update_award_tracking_status
    // POST: CourtAwardId, Type
    // -----------------------------------------------------------------------
    public function update_award_tracking_status($p = null) {
        $court_award_id = (int)($_POST['CourtAwardId'] ?? 0);
        if (!valid_id($court_award_id)) $this->jsonOut(['status' => 1, 'error' => 'Invalid award.']);

        global $DB;
        $DB->Clear();
        $r = $DB->DataSet('SELECT court_id FROM ' . DB_PREFIX . 'court_award
                            WHERE court_award_id = ' . $court_award_id . ' LIMIT 1');
        if (!$r || !$r->Next()) $this->jsonOut(['status' => 1, 'error' => 'Award not found.']);

        $this->requireCourtAuth((int)$r->court_id);

        $type = $_POST['Type'] ?? '';
        
        $result = Ork3::$Lib->court->updateAwardTrackingStatus($court_award_id, $type);
        $this->jsonOut($result);
    }

}
