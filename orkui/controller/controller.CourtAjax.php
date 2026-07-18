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

        // Optional initial run-vs-plan intent (spec 5.2); defaults to 'run'. The
        // lib rejects anything but run/plan, so an unexpected value is a no-op.
        $mode = ($_POST['Mode'] ?? 'run') === 'plan' ? 'plan' : 'run';
        Ork3::$Lib->court->setCourtMode($court_id, $mode);

        $this->jsonOut(['status' => 0, 'court_id' => $court_id, 'name' => $name, 'mode' => $mode]);
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

        // QW#2: a court can ONLY reach 'complete' through finalize_court, which
        // batch-grants staged rows and stamps finalized_at/by. Rejecting 'complete'
        // here removes the bypass that could otherwise leave staged rows orphaned
        // (status='complete' with finalized_at IS NULL). Callers must finalize.
        if ($status === 'complete') {
            $this->jsonOut([
                'status' => 1,
                'error'  => 'A court is completed by finalizing its staged grants. Use "Complete Court", which records all staged awards and then marks the court complete.',
            ]);
        }

        $allowed = ['draft', 'published'];
        if (!in_array($status, $allowed)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid status.']);
        }

        // Leaving draft sets the run-vs-plan mode (spec 5.2); only run/plan are honored.
        if ($status === 'published') {
            $mode = ($_POST['Mode'] ?? 'run') === 'plan' ? 'plan' : 'run';
            Ork3::$Lib->court->setCourtMode($court_id, $mode);
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

        // QW#5: removeAward refuses to hard-DELETE a committed ('given') row, so a
        // finalized grant's audit trace can't be destroyed. false => already granted.
        if (!Ork3::$Lib->court->removeAward($court_award_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Cannot remove a granted award.']);
        }

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

        // QW#5: never hard-DELETE a committed ('given') row. false => already granted.
        if (!Ork3::$Lib->court->removeAward($court_award_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'This award was already granted and cannot be removed from the court.']);
        }

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
        $allowed        = ['planned', 'announced', 'staged', 'given', 'cancelled'];
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

        // S5 optimistic lock: honor the client's row_version token when supplied.
        $expectedRowVersion = (isset($_POST['RowVersion']) && $_POST['RowVersion'] !== '')
            ? (int)$_POST['RowVersion'] : null;

        // QW#5: setAwardStatus refuses a committed ('given') row and returns false.
        if (!Ork3::$Lib->court->setAwardStatus($court_award_id, $status, $expectedRowVersion)) {
            if ($expectedRowVersion !== null) {
                // status 9 = optimistic-lock conflict (see update_award); the token
                // was stale (or the row is now given). Non-destructive reload.
                $this->jsonOut(['status' => 9, 'stale' => true, 'message' => 'This row changed — reload.']);
            }
            $this->jsonOut(['status' => 1, 'error' => 'This award was already granted and can no longer change status.']);
        }

        $this->jsonOut(['status' => 0, 'award_status' => $status]);
    }

    // -----------------------------------------------------------------------
    // update_award
    // POST: CourtAwardId, Notes, PublicComment, PassToLocal, ScrollMakerId,
    //       RegaliaMakerId, RowVersion (opt). Field edits only — never status (QW#4).
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
        $scroll_maker_id  = (int)($_POST['ScrollMakerId']  ?? 0);
        $regalia_maker_id = (int)($_POST['RegaliaMakerId'] ?? 0);

        // QW#4: update_award writes editable FIELDS only — NEVER status. Lifecycle
        // moves solely through stage/unstage/skip/set-status/commit, so a stale
        // field-save can no longer drag a row's status backward.
        // S5 optimistic lock: honor the client's row_version token when supplied.
        $expectedRowVersion = (isset($_POST['RowVersion']) && $_POST['RowVersion'] !== '')
            ? (int)$_POST['RowVersion'] : null;

        $ok = Ork3::$Lib->court->updateAward(
            $court_award_id,
            $notes,
            $public_comment,
            $pass_to_local,
            $scroll_maker_id,
            $regalia_maker_id,
            $expectedRowVersion
        );
        if (!$ok && $expectedRowVersion !== null) {
            // status 9 = optimistic-lock conflict: the client's row_version was stale.
            // Non-destructive "this row changed — reload" toast.
            $this->jsonOut(['status' => 9, 'stale' => true, 'message' => 'This row changed — reload.']);
        }
        if (!$ok) {
            // No token supplied but the guarded UPDATE still matched 0 rows — the row
            // is gone or otherwise unwritable. Never report a no-op as success.
            $this->jsonOut(['status' => 1, 'error' => 'This award could not be updated — it may have changed. Reload.']);
        }

        $this->jsonOut(['status' => 0, 'notes' => $notes, 'public_comment' => $public_comment, 'pass_to_local' => $pass_to_local]);
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
    // grant_award  (STAGE — spec 5.1/7)
    // Granting at court now *stages* the row: captures giver/citation/rank and
    // marks it 'staged'. It does NOT touch the permanent player record — that
    // commit happens once, in finalize_court. No AddAward, no rec-cluster resolve
    // here (both move to finalize).
    // POST: CourtAwardId, GivenById (mundane_id of the giver), PublicComment, Rank
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

        $given_by_id    = (int)($_POST['GivenById'] ?? 0);
        $public_comment = trim($_POST['PublicComment'] ?? '');
        $rank           = (int)($_POST['Rank'] ?? $ca['Rank']);

        // Atomic stage: won't touch a row already given/cancelled/staged, so a
        // double-submit can't double-stage. Loser gets "already resolved".
        if (!Ork3::$Lib->court->stageAward($court_award_id, $given_by_id, $public_comment, $rank)) {
            $this->jsonOut(['status' => 1, 'error' => 'Award already resolved.']);
        }

        $court_id = Ork3::$Lib->court->getCourtAwardCourtId($court_award_id);
        $this->jsonOut([
            'status'       => 0,
            'award_status' => 'staged',
            'staged_count' => Ork3::$Lib->court->countStagedAwards($court_id),
        ]);
    }

    // -----------------------------------------------------------------------
    // unstage_award  (UNDO a stage — spec 6.3)
    // 'staged' -> 'planned'. Free; no player-record trace to reverse.
    // POST: CourtAwardId
    // -----------------------------------------------------------------------
    public function unstage_award($p = null)
    {
        $court_award_id = (int)($_POST['CourtAwardId'] ?? 0);
        if (!valid_id($court_award_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid award.']);
        }

        $court_id = Ork3::$Lib->court->getCourtAwardCourtId($court_award_id);
        if (!$court_id) {
            $this->jsonOut(['status' => 1, 'error' => 'Award not found.']);
        }

        [$uid, $court] = $this->requireCourtAuth($court_id);

        if ($court['Status'] !== 'published') {
            $this->jsonOut(['status' => 1, 'error' => 'Court must be published to un-stage awards.']);
        }

        Ork3::$Lib->court->unstageAward($court_award_id);

        $this->jsonOut([
            'status'       => 0,
            'award_status' => 'planned',
            'staged_count' => Ork3::$Lib->court->countStagedAwards($court_id),
        ]);
    }

    // -----------------------------------------------------------------------
    // finalize_court  (THE single commit gate — spec 5.3 / 6.6)
    // Commits every 'staged' row on the court to the permanent player record via
    // add_player_award (using the captured giver), flips each to 'given', and — if
    // all succeed — completes the court with finalized_at/by. Idempotent: rows that
    // fail stay 'staged' so finalize can be re-run; already-'given' rows are ignored.
    // POST: CourtId, SkipRemaining (0/1)
    // -----------------------------------------------------------------------
    public function finalize_court($p = null)
    {
        $court_id = (int)($_POST['CourtId'] ?? 0);
        [$uid, $court] = $this->requireCourtAuth($court_id);

        if ($court['Status'] !== 'published') {
            $this->jsonOut(['status' => 1, 'error' => 'Court must be published to finalize.']);
        }

        // Complete-court "Skip Remaining" path (spec 6.6): cancel every still-
        // unresolved row first, then finalize the staged ones.
        if ((int)($_POST['SkipRemaining'] ?? 0) === 1) {
            Ork3::$Lib->court->cancelUnresolved($court_id);
        }

        $this->load_model('Player');

        $staged    = Ork3::$Lib->court->getStagedAwards($court_id);
        $committed = 0;
        $failed    = [];

        foreach ($staged as $row) {
            // S1 single idempotent commit sink. commitStagedAward owns the whole
            // per-row flow: atomic claim 'staged'->'given' (court-line identity IS
            // the idempotency key), throw-safe AddAward, revert-on-failure, and
            // linking award_id from the RETURNED insert id (no date heuristic). The
            // giver backstop now lives inside it, so a double-click / concurrent
            // finalize is a safe no-op.
            $res = Ork3::$Lib->court->commitStagedAward(
                $row['CourtAwardId'],
                ['Token' => $this->session->token]
            );

            if ($res['status'] === 'ok') {
                $committed++;

                // Best-effort rec-cluster resolve: soft-delete parallel recs + notify
                // advocates. Fed from the committed row; never fails finalize.
                $crow = $res['row'];
                try {
                    $this->Player->resolve_player_recommendation_cluster([
                        'Token'          => $this->session->token,
                        'MundaneId'      => $crow['MundaneId'],
                        'KingdomAwardId' => $crow['KingdomAwardId'],
                        'Rank'           => $crow['Rank'],
                        'RequestedBy'    => $uid,
                    ]);
                } catch (\Throwable $e) { /* recommendation cleanup is best-effort */
                }
            } elseif ($res['status'] === 'error') {
                $failed[] = [
                    'court_award_id' => $res['court_award_id'],
                    'error'          => $res['error'],
                ];
            }
            // 'noop' => already resolved (double-click / concurrent finalize); skip.
        }

        // Complete only when nothing failed unrecoverably; else leave the court
        // published so the officer can retry the failed rows.
        $completed = empty($failed);
        if ($completed) {
            Ork3::$Lib->court->setCourtFinalized($court_id, $uid);
        }

        $this->jsonOut([
            'status'    => 0,
            'committed' => $committed,
            'failed'    => $failed,
            'completed' => $completed,
        ]);
    }

    // -----------------------------------------------------------------------
    // bulk_record_grants  (Plan-mode batch stage — spec 6)
    // Stages every 'planned' row using defaults (court-level Monarch as giver).
    // POST: CourtId
    // -----------------------------------------------------------------------
    public function bulk_record_grants($p = null)
    {
        $court_id = (int)($_POST['CourtId'] ?? 0);
        [$uid, $court] = $this->requireCourtAuth($court_id);

        if ($court['Status'] !== 'published') {
            $this->jsonOut(['status' => 1, 'error' => 'Court must be published to record grants.']);
        }

        $givers        = Ork3::$Lib->court->getCourtGiverOptions($court_id);
        $default_giver = (int)($givers['default']['mundane_id'] ?? 0);

        if ($default_giver <= 0) {
            $this->jsonOut(['status' => 1, 'error' => 'No Monarch is set for this court, so awards can\'t be bulk-recorded under a giver. Grant awards individually (choosing a giver), or set the Monarch officer for this ' . ($court['ParkId'] ? 'park' : 'kingdom') . '.']);
        }

        $staged = Ork3::$Lib->court->bulkStagePlanned($court_id, $default_giver);

        $this->jsonOut([
            'status'       => 0,
            'staged'       => $staged,
            'staged_count' => Ork3::$Lib->court->countStagedAwards($court_id),
        ]);
    }

    // -----------------------------------------------------------------------
    // prepopulate_from_last_court  (spec 6.5)
    // Inserts still-awardable rows from the most recent completed court at this
    // level onto THIS court as 'planned', de-duplicated against rows already here.
    // POST: CourtId
    // -----------------------------------------------------------------------
    public function prepopulate_from_last_court($p = null)
    {
        $court_id = (int)($_POST['CourtId'] ?? 0);
        [$uid, $court] = $this->requireCourtAuth($court_id);

        $rows = Ork3::$Lib->court->getUngrantedFromLastCourt($court['KingdomId'], $court['ParkId']);

        $added = 0;
        foreach ($rows as $row) {
            // Skip anything already carried on this court (same recipient/award/rank).
            if (Ork3::$Lib->court->courtHasAward($court_id, $row['MundaneId'], $row['KingdomAwardId'], $row['Rank'])) {
                continue;
            }
            $res = Ork3::$Lib->court->addAward(
                $court_id,
                (int)$court['KingdomId'],
                $row['MundaneId'],
                $row['KingdomAwardId'],
                $row['Rank'],
                $row['RecommendationsId'],
                $row['PassToLocal'] ? 1 : 0,
                $row['Notes'],
                $row['PublicComment']
            );
            if ($res !== false) {
                $added++;
            }
        }

        $this->jsonOut(['status' => 0, 'added' => $added]);
    }

    // -----------------------------------------------------------------------
    // court_state  (live heartbeat read — spec 6.4 / S5)
    // Cheap poll (~15s). Returns:
    //   version      md5 stamp that flips on any mutating write (cheap change probe)
    //   mode         run|plan
    //   court_status draft|published|complete
    //   awards       light per-row state (court_award_id/status/sort_order/
    //                given_by/row_version) folded into the version stamp
    //   awards_full  FULL per-award payload (same shape as the initial render) so the
    //                client can do a full-field reconcile — add/remove rows plus
    //                notes/public_comment/pass_to_local/makers/status/giver/row_version
    //   presence     roster of officers currently viewing (S5): [{uid,name,last_seen}]
    // POST/GET: CourtId
    // -----------------------------------------------------------------------
    public function court_state($p = null)
    {
        $court_id = (int)($_POST['CourtId'] ?? $_GET['CourtId'] ?? 0);
        [$uid, $court] = $this->requireCourtAuth($court_id);

        $state = Ork3::$Lib->court->getCourtState($court_id);

        // Full per-award payload for a FULL-FIELD client reconcile (the same shape
        // the initial page render uses), so the heartbeat can add/remove rows and
        // pick up field edits — not just status/giver/sort.
        $state['awards_full'] = Ork3::$Lib->court->getCourtAwards($court_id);

        // Presence (S5): record this officer's heartbeat and return the roster.
        $state['presence'] = $this->recordCourtPresence($court_id, $uid);

        $this->jsonOut(array_merge(['status' => 0], $state));
    }

    // -----------------------------------------------------------------------
    // Presence roster (S5) — memcache-only (no schema). Stored in GhettoCache
    // (Ork3::$Lib->ghettocache, the app's Memcached wrapper) keyed by court id as
    // an assoc map uid => {uid, name, last_seen(epoch)}, ~45s TTL. Every poll upserts
    // the calling officer and prunes anyone whose last heartbeat aged out, so a
    // departed officer drops off within one TTL window. Best-effort: the read-modify-
    // write is not CAS-guarded (a rare concurrent poll can transiently drop a peer,
    // who reappears next poll), and if Memcached is unavailable get() returns false
    // and presence simply degrades to "just me" without erroring the heartbeat.
    // -----------------------------------------------------------------------
    private function recordCourtPresence($court_id, $uid)
    {
        $ttl  = 45;
        $now  = time();
        $call = 'CourtAjax.presence';
        $key  = (string)(int)$court_id;

        $cache = Ork3::$Lib->ghettocache;
        // get() also arms cache() to use $ttl as the write expiration (the GhettoCache
        // lifetime API is inverted — the TTL is captured on the matching get()).
        $roster = $cache->get($call, $key, $ttl);
        if (!is_array($roster)) {
            $roster = [];
        }

        // Prune officers whose last heartbeat has aged past the TTL window.
        foreach ($roster as $id => $ent) {
            if (!isset($ent['last_seen']) || ($now - (int)$ent['last_seen']) > $ttl) {
                unset($roster[$id]);
            }
        }

        // Upsert the calling officer (persona resolved once here).
        $roster[(int)$uid] = [
            'uid'       => (int)$uid,
            'name'      => $this->lookupPersona($uid),
            'last_seen' => $now,
        ];

        $cache->cache($call, $key, $roster);

        return array_values($roster);
    }

    /** Persona for a mundane id (presence display), or '' if unknown. */
    private function lookupPersona($uid)
    {
        global $DB;
        $DB->Clear();
        $r = $DB->DataSet('SELECT persona FROM ' . DB_PREFIX . 'mundane
                           WHERE mundane_id = ' . (int)$uid . ' LIMIT 1');
        return ($r && $r->Next()) ? (string)$r->persona : '';
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

        $court_id = Ork3::$Lib->court->getCourtAwardCourtId($court_award_id);
        if (!$court_id) {
            $this->jsonOut(['status' => 1, 'error' => 'Award not found.']);
        }

        [$uid, $court] = $this->requireCourtAuth($court_id);

        if ($court['Status'] !== 'published') {
            $this->jsonOut(['status' => 1, 'error' => 'Court must be published to skip awards.']);
        }

        // S5 optimistic lock: honor the client's row_version token when supplied.
        $expectedRowVersion = (isset($_POST['RowVersion']) && $_POST['RowVersion'] !== '')
            ? (int)$_POST['RowVersion'] : null;

        // QW#5: skipAward (soft-cancel) refuses a committed ('given') row and bumps
        // row_version on a match, so false => already granted / stale / gone.
        if (!Ork3::$Lib->court->skipAward($court_award_id, $expectedRowVersion)) {
            if ($expectedRowVersion !== null) {
                // status 9 = optimistic-lock conflict; non-destructive reload.
                $this->jsonOut(['status' => 9, 'stale' => true, 'message' => 'This row changed — reload.']);
            }
            $this->jsonOut(['status' => 1, 'error' => 'This award was already granted and can no longer be skipped.']);
        }

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
