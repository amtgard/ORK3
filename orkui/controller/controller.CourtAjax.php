<?php

class Controller_CourtAjax extends Controller
{
    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function jsonOut($data)
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    private function requireLogin()
    {
        if (!isset($this->session->user_id)) {
            $this->jsonOut(['status' => 5, 'error' => 'Not logged in']);
        }
        return (int)$this->session->user_id;
    }

    private function requireCourtAuth($court_id)
    {
        $uid   = $this->requireLogin();
        $court = Ork3::$Lib->court->getCourtDetail($court_id);
        if (!$court) {
            $this->jsonOut(['status' => 1, 'error' => 'Court not found.']);
        }
        if (!Ork3::$Lib->court->canManage($uid, $court['KingdomId'], $court['ParkId'])) {
            $this->jsonOut(['status' => 3, 'error' => 'Not authorized.']);
        }
        return [$uid, $court];
    }

    private function esc($v)
    {
        return str_replace(["'", '\\'], ["''", '\\\\'], $v);
    }

    // -----------------------------------------------------------------------
    // create_court
    // POST: KingdomId, ParkId, Name, CourtDate, EventCalendarDetailId
    // -----------------------------------------------------------------------
    public function create_court($p = null)
    {
        $uid = $this->requireLogin();

        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $park_id    = (int)($_POST['ParkId']    ?? 0);

        if (!valid_id($kingdom_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid kingdom.']);
        }

        if (!Ork3::$Lib->court->canManage($uid, $kingdom_id, $park_id)) {
            $this->jsonOut(['status' => 3, 'error' => 'Not authorized.']);
        }

        $name       = trim($_POST['Name']       ?? '');
        $court_date = trim($_POST['CourtDate']  ?? '');
        $event_cd   = (int)($_POST['EventCalendarDetailId'] ?? 0);

        if (!$name) {
            $this->jsonOut(['status' => 1, 'error' => 'A name is required.']);
        }

        $court_id = Ork3::$Lib->court->createCourt($kingdom_id, $park_id, $name, $court_date, $event_cd, $uid);

        $this->jsonOut(['status' => 0, 'court_id' => $court_id, 'name' => $name]);
    }

    // -----------------------------------------------------------------------
    // update_court_status
    // POST: CourtId, Status
    // -----------------------------------------------------------------------
    public function update_court_status($p = null)
    {
        $court_id = (int)($_POST['CourtId'] ?? 0);
        [$uid, $court] = $this->requireCourtAuth($court_id);

        $status = trim($_POST['Status'] ?? '');
        $allowed = ['draft', 'published', 'complete'];
        if (!in_array($status, $allowed)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid status.']);
        }

        Ork3::$Lib->court->updateCourtStatus($court_id, $status);
        $this->jsonOut(['status' => 0, 'new_status' => $status]);
    }

    // -----------------------------------------------------------------------
    // add_award
    // POST: CourtId, MundaneId, KingdomAwardId, Rank, RecommendationsId (opt),
    //       PassToLocal, Notes
    // -----------------------------------------------------------------------
    public function add_award($p = null)
    {
        $court_id = (int)($_POST['CourtId'] ?? 0);
        [$uid, $court] = $this->requireCourtAuth($court_id);

        $mundane_id      = (int)($_POST['MundaneId']         ?? 0);
        $kingdomaward_id = (int)($_POST['KingdomAwardId']    ?? 0);
        $rank            = (int)($_POST['Rank']               ?? 0);
        $rec_id          = (int)($_POST['RecommendationsId'] ?? 0);
        $pass_to_local   = (int)($_POST['PassToLocal']       ?? 0) ? 1 : 0;
        $notes           = trim($_POST['Notes']               ?? '');
        $public_comment  = trim($_POST['PublicComment']       ?? '');

        if (!valid_id($mundane_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Recipient required.']);
        }
        if (!valid_id($kingdomaward_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Award required.']);
        }

        // The lib enforces object-level auth: the kingdomaward must belong to this
        // court's own kingdom (else an officer could attach another kingdom's id).
        $award = Ork3::$Lib->court->addAward(
            $court_id,
            (int)$court['KingdomId'],
            $mundane_id,
            $kingdomaward_id,
            $rank,
            $rec_id,
            $pass_to_local,
            $notes,
            $public_comment
        );
        if ($award === false) {
            $this->jsonOut(['status' => 1, 'error' => 'That award does not belong to this kingdom.']);
        }

        $this->jsonOut(['status' => 0, 'award' => $award]);
    }

    // -----------------------------------------------------------------------
    // remove_award
    // POST: CourtAwardId
    // -----------------------------------------------------------------------
    public function remove_award($p = null)
    {
        $court_award_id = (int)($_POST['CourtAwardId'] ?? 0);
        if (!valid_id($court_award_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid award.']);
        }

        // Look up court_id for auth
        $court_id = Ork3::$Lib->court->getCourtAwardCourtId($court_award_id);
        if (!$court_id) {
            $this->jsonOut(['status' => 1, 'error' => 'Award not found.']);
        }

        $this->requireCourtAuth($court_id);

        Ork3::$Lib->court->removeAward($court_award_id);

        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // pass_award_to_local
    // POST: CourtAwardId
    // Kingdom/principality-side action: hand a rec-backed award down to the
    // recipient's local park (sets recommendations.passed_to_local via the shared
    // pipe) and remove it from this court. Pipe runs first; delete only on success.
    // -----------------------------------------------------------------------
    public function pass_award_to_local($p = null)
    {
        $court_award_id = (int)($_POST['CourtAwardId'] ?? 0);
        if (!valid_id($court_award_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid award.']);
        }

        $info = Ork3::$Lib->court->getCourtAwardForPass($court_award_id);
        if (!$info) {
            $this->jsonOut(['status' => 1, 'error' => 'Award not found.']);
        }
        $court_id = $info['court_id'];
        $rec_id   = $info['recommendations_id'];

        $this->requireCourtAuth($court_id);

        if (!$rec_id) {
            $this->jsonOut(['status' => 1, 'error' => 'This award is not from a recommendation, so it cannot be passed to local.']);
        }

        $this->load_model('Player');
        $res = $this->Player->set_recommendation_passed_to_local([
            'Token'             => $this->session->token,
            'RecommendationsId' => $rec_id,
            'Passed'            => 1,
        ]);
        if (($res['Status'] ?? 1) != 0) {
            $this->jsonOut(['status' => 3, 'error' => 'Could not pass this award to local: ' . ($res['Error'] ?? 'Not authorized.')]);
        }

        Ork3::$Lib->court->removeAward($court_award_id);

        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // Status-only update (no field clobbering, unlike update_award). Used by the
    // Recommendations Manager's Grant Now flow to mark an already-on-court award
    // 'given' when the officer chooses "Grant & Leave on Court", which prevents a
    // later double-grant at court (grant_award guards against re-granting 'given').
    public function set_award_status($p = null)
    {
        $court_award_id = (int)($_POST['CourtAwardId'] ?? 0);
        $status         = trim($_POST['Status'] ?? '');
        $allowed        = ['planned', 'announced', 'given', 'cancelled'];
        if (!valid_id($court_award_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid award.']);
        }
        if (!in_array($status, $allowed)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid status.']);
        }

        $court_id = Ork3::$Lib->court->getCourtAwardCourtId($court_award_id);
        if (!$court_id) {
            $this->jsonOut(['status' => 1, 'error' => 'Award not found.']);
        }

        $this->requireCourtAuth($court_id);

        Ork3::$Lib->court->setAwardStatus($court_award_id, $status);

        $this->jsonOut(['status' => 0, 'award_status' => $status]);
    }

    // -----------------------------------------------------------------------
    // update_award
    // POST: CourtAwardId, Notes, PassToLocal, Status
    // -----------------------------------------------------------------------
    public function update_award($p = null)
    {
        $court_award_id = (int)($_POST['CourtAwardId'] ?? 0);
        if (!valid_id($court_award_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid award.']);
        }

        $court_id = Ork3::$Lib->court->getCourtAwardCourtId($court_award_id);
        if (!$court_id) {
            $this->jsonOut(['status' => 1, 'error' => 'Award not found.']);
        }

        $this->requireCourtAuth($court_id);

        $notes            = trim($_POST['Notes']          ?? '');
        $public_comment   = trim($_POST['PublicComment']  ?? '');
        $pass_to_local    = (int)($_POST['PassToLocal']    ?? 0) ? 1 : 0;
        $status           = trim($_POST['Status']          ?? 'planned');
        $scroll_maker_id  = (int)($_POST['ScrollMakerId']  ?? 0);
        $regalia_maker_id = (int)($_POST['RegaliaMakerId'] ?? 0);
        $allowed          = ['planned', 'announced', 'given', 'cancelled'];
        if (!in_array($status, $allowed)) {
            $status = 'planned';
        }

        Ork3::$Lib->court->updateAward($court_award_id, $notes, $public_comment, $pass_to_local, $status, $scroll_maker_id, $regalia_maker_id);
        $this->jsonOut(['status' => 0, 'notes' => $notes, 'public_comment' => $public_comment, 'pass_to_local' => $pass_to_local, 'award_status' => $status]);
    }

    // -----------------------------------------------------------------------
    // reorder_awards
    // POST: CourtId, Order (JSON array of court_award_ids in display order)
    // -----------------------------------------------------------------------
    public function reorder_awards($p = null)
    {
        $court_id = (int)($_POST['CourtId'] ?? 0);
        $this->requireCourtAuth($court_id);

        $order = json_decode($_POST['Order'] ?? '[]', true);
        if (!is_array($order)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid order.']);
        }

        Ork3::$Lib->court->reorderAwards($court_id, $order);
        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // add_artisan
    // POST: CourtAwardId, MundaneId, Contribution
    // -----------------------------------------------------------------------
    public function add_artisan($p = null)
    {
        $court_award_id = (int)($_POST['CourtAwardId'] ?? 0);
        if (!valid_id($court_award_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid award.']);
        }

        $court_id = Ork3::$Lib->court->getCourtAwardCourtId($court_award_id);
        if (!$court_id) {
            $this->jsonOut(['status' => 1, 'error' => 'Award not found.']);
        }
        $this->requireCourtAuth($court_id);

        $mundane_id   = (int)($_POST['MundaneId']    ?? 0);
        $contribution = trim($_POST['Contribution']  ?? '');
        if (!valid_id($mundane_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Artisan required.']);
        }

        $artisan = Ork3::$Lib->court->addArtisan($court_award_id, $mundane_id, $contribution);

        $this->jsonOut(['status' => 0, 'artisan' => $artisan]);
    }

    // -----------------------------------------------------------------------
    // remove_artisan
    // POST: CourtAwardArtisanId
    // -----------------------------------------------------------------------
    public function remove_artisan($p = null)
    {
        $artisan_id = (int)($_POST['CourtAwardArtisanId'] ?? 0);
        if (!valid_id($artisan_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid artisan.']);
        }

        $court_id = Ork3::$Lib->court->getArtisanCourtId($artisan_id);
        if ($court_id === null) {
            $this->jsonOut(['status' => 1, 'error' => 'Artisan not found.']);
        }
        $this->requireCourtAuth((int)$court_id);

        Ork3::$Lib->court->removeArtisan($artisan_id);

        $this->jsonOut(['status' => 0]);
    }
    // -----------------------------------------------------------------------
    // grant_award
    // POST: CourtAwardId
    // -----------------------------------------------------------------------
    public function grant_award($p = null)
    {
        $court_award_id = (int)($_POST['CourtAwardId'] ?? 0);
        if (!valid_id($court_award_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid award.']);
        }

        $ca = Ork3::$Lib->court->getCourtAwardForGrant($court_award_id);
        if (!$ca) {
            $this->jsonOut(['status' => 1, 'error' => 'Award not found.']);
        }

        $uid = $this->requireLogin();
        if (!Ork3::$Lib->court->canManage($uid, $ca['KingdomId'], $ca['ParkId'])) {
            $this->jsonOut(['status' => 3, 'error' => 'Not authorized.']);
        }

        if ($ca['CourtStatus'] !== 'published') {
            $this->jsonOut(['status' => 1, 'error' => 'Court must be published to grant awards.']);
        }

        // Atomic check-then-act: flip status to 'given' BEFORE granting so two
        // rapid clicks can't double-grant. Only the caller that actually changed
        // the row proceeds; anyone else gets "already resolved".
        if (!Ork3::$Lib->court->claimAwardForGrant($court_award_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Award already resolved.']);
        }

        $event_id = Ork3::$Lib->court->getEventIdFromCalendarDetail($ca['EventCalendarDetailId']);
        $date     = $ca['CourtDate'] ?: date('Y-m-d');

        $this->load_model('Player');
        $r = $this->Player->add_player_award([
            'Token'          => $this->session->token,
            'RecipientId'    => $ca['MundaneId'],
            'KingdomAwardId' => $ca['KingdomAwardId'],
            'AwardId'        => 0,
            'Rank'           => $ca['Rank'],
            'Date'           => $date,
            'GivenById'      => 0,
            'CustomName'     => '',
            'Note'           => $ca['Notes'],
            'ParkId'         => $ca['ParkId'],
            'KingdomId'      => $ca['KingdomId'],
            'EventId'        => $event_id,
        ]);

        if (($r['Status'] ?? 1) != 0) {
            // Grant failed downstream — release the claim so it can be retried.
            Ork3::$Lib->court->revertAwardStatus($court_award_id, $ca['Status']);
            $this->jsonOut(['status' => 1, 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
        }

        // status is already 'given' from the atomic claim above.

        // Resolve the whole recommendation cluster for the granted award
        // (recipient + award + rank): soft-deletes every parallel rec and notifies
        // each advocate, via the shared resolver. No-ops when there are no live recs.
        // Non-blocking: a resolver/notify failure must never fail the grant.
        try {
            $this->load_model('Player');
            $this->Player->resolve_player_recommendation_cluster([
                'Token'          => $this->session->token,
                'MundaneId'      => $ca['MundaneId'],
                'KingdomAwardId' => $ca['KingdomAwardId'],
                'Rank'           => $ca['Rank'],
                'RequestedBy'    => $uid,
            ]);
        } catch (\Throwable $e) { /* recommendation cleanup is best-effort */
        }

        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // skip_award
    // POST: CourtAwardId
    // -----------------------------------------------------------------------
    public function skip_award($p = null)
    {
        $court_award_id = (int)($_POST['CourtAwardId'] ?? 0);
        if (!valid_id($court_award_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid award.']);
        }

        global $DB;
        $DB->Clear();
        $ca = $DB->DataSet(
            'SELECT ca.court_id, c.status AS court_status FROM ' . DB_PREFIX . 'court_award ca
             JOIN ' . DB_PREFIX . 'court c ON c.court_id = ca.court_id
             WHERE ca.court_award_id = ' . $court_award_id . ' LIMIT 1'
        );
        if (!$ca || !$ca->Next()) {
            $this->jsonOut(['status' => 1, 'error' => 'Award not found.']);
        }

        [$uid, $court] = $this->requireCourtAuth((int)$ca->court_id);

        if ($ca->court_status !== 'published') {
            $this->jsonOut(['status' => 1, 'error' => 'Court must be published to skip awards.']);
        }

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
    public function update_award_tracking_status($p = null)
    {
        $court_award_id = (int)($_POST['CourtAwardId'] ?? 0);
        if (!valid_id($court_award_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid award.']);
        }

        global $DB;
        $DB->Clear();
        $r = $DB->DataSet('SELECT court_id FROM ' . DB_PREFIX . 'court_award
                            WHERE court_award_id = ' . $court_award_id . ' LIMIT 1');
        if (!$r || !$r->Next()) {
            $this->jsonOut(['status' => 1, 'error' => 'Award not found.']);
        }

        $this->requireCourtAuth((int)$r->court_id);

        $type = $_POST['Type'] ?? '';

        $result = Ork3::$Lib->court->updateAwardTrackingStatus($court_award_id, $type);
        $this->jsonOut($result);
    }

}
