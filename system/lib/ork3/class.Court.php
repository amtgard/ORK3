<?php

class Court
{
    /**
     * EFFECTIVE award flags: the per-kingdom row (ka) may flag an award as a
     * ladder or a title that the base catalog row (a) does not.
     *
     * GREATEST, not COALESCE. Both ork_kingdomaward.is_ladder and .is_title are
     * `tinyint(1) NOT NULL DEFAULT 0`, so COALESCE(ka.x, a.x) can never fall
     * through to the base award — it always returns ka.x, and every base ladder
     * award in a kingdom that has not set its own flag would silently lose its
     * rank pills. Only ka.is_title has been backfilled from the catalog
     * (db-migrations/2026-06-03-kingdomaward-is-title-authoritative.sql);
     * ka.is_ladder has not, so it is purely additive. "Either side says so" is
     * therefore the only reading that both honors the override and keeps the
     * catalog's own flags — see reference_kingdomaward_is_title_authoritative.
     *
     * Both expect the kingdomaward aliased `ka` and the award aliased `a`.
     */
    private const SQL_EFFECTIVE_IS_LADDER = 'GREATEST(COALESCE(ka.is_ladder, 0), COALESCE(a.is_ladder, 0))';
    private const SQL_EFFECTIVE_IS_TITLE  = 'GREATEST(COALESCE(ka.is_title, 0), COALESCE(a.is_title, 0))';

    private $db;

    public function __construct()
    {
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
    public function canManage($uid, $kingdom_id, $park_id = 0)
    {
        if ($uid <= 0 || !valid_id($kingdom_id)) {
            return false;
        }

        if (Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT)) {
            return true;
        }

        if ($park_id > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, $park_id, AUTH_EDIT)) {
            return true;
        }

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
        if ($r && $r->Next()) {
            return true;
        }

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
            if ($r2 && $r2->Next()) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Courts
    // -----------------------------------------------------------------------

    /**
     * Courts in scope.
     *
     * SCOPE. A park request is always that park's courts alone. A kingdom request
     * defaults to the kingdom's OWN courts (park_id = 0) — the Court Planner and the
     * Kingdom profile's court tab both mean "the courts this kingdom runs", and
     * folding every subordinate park's courts into those lists is the leak that
     * getUnrecordedCourts documents. Callers that instead need the whole tree —
     * notably the Recommendations Manager, whose court badges come from
     * getRecommendationCourtMap and must name courts the officer can also pick in the
     * court filter — pass $include_park_courts = true, which is exactly the scope
     * getRecommendationCourtMap($kingdom_id, 0, true) returns.
     */
    public function getCourtList($kingdom_id, $park_id = 0, $include_park_courts = false)
    {
        $where = 'c.kingdom_id = ' . (int)$kingdom_id;
        if ($park_id > 0) {
            $where .= ' AND c.park_id = ' . (int)$park_id;
        } elseif (!$include_park_courts) {
            $where .= ' AND c.park_id = 0';
        }

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT c.court_id, c.name, c.court_date, c.status, c.mode,
                    c.event_calendardetail_id,
                    COUNT(ca.court_award_id) AS award_count,
                    (SELECT COUNT(*) FROM ' . DB_PREFIX . 'court_award sca
                        WHERE sca.court_id = c.court_id
                          AND sca.status = \'staged\') AS staged_count,
                    e.name AS event_name,
                    c.park_id, p.name AS park_name
             FROM ' . DB_PREFIX . 'court c
             LEFT JOIN ' . DB_PREFIX . 'court_award ca
                    ON ca.court_id = c.court_id AND ca.status != \'cancelled\'
             LEFT JOIN ' . DB_PREFIX . 'event_calendardetail cd
                    ON cd.event_calendardetail_id = c.event_calendardetail_id
             LEFT JOIN ' . DB_PREFIX . 'event e ON e.event_id = cd.event_id
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = c.park_id
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
                    'Mode'                  => $rs->mode ?: 'run',
                    'AwardCount'            => (int)$rs->award_count,
                    'StagedCount'           => (int)$rs->staged_count,
                    'EventName'             => $rs->event_name,
                    'EventCalendarDetailId' => (int)$rs->event_calendardetail_id,
                    // ParkId/ParkName let a caller that passed $include_park_courts
                    // tell two same-named park courts apart; 0/'' for kingdom courts.
                    'ParkId'                => (int)$rs->park_id,
                    'ParkName'              => $rs->park_name ?: '',
                ];
            }
        }
        return $list;
    }

    public function getCourtDetail($court_id)
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT c.*,
                    e.name   AS event_name,
                    p.name   AS park_name,
                    k.name   AS kingdom_name,
                    cd.event_start,
                    rm.persona AS recorder_persona
             FROM ' . DB_PREFIX . 'court c
             LEFT JOIN ' . DB_PREFIX . 'event_calendardetail cd
                    ON cd.event_calendardetail_id = c.event_calendardetail_id
             LEFT JOIN ' . DB_PREFIX . 'event e ON e.event_id = cd.event_id
             LEFT JOIN ' . DB_PREFIX . 'park p   ON p.park_id   = c.park_id
             LEFT JOIN ' . DB_PREFIX . 'kingdom k ON k.kingdom_id = c.kingdom_id
             LEFT JOIN ' . DB_PREFIX . 'mundane rm ON rm.mundane_id = c.recorder_mundane_id
             WHERE c.court_id = ' . (int)$court_id . '
             LIMIT 1'
        );
        if (!$rs || !$rs->Next()) {
            return null;
        }

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
            'RecorderMundaneId'     => (int)$rs->recorder_mundane_id,
            'RecorderPersona'       => $rs->recorder_persona ?? '',
            'LastPrintedAt'         => $rs->last_printed_at,
            'LastPrintedAwardCount' => isset($rs->last_printed_award_count) && $rs->last_printed_award_count !== null
                ? (int)$rs->last_printed_award_count
                : null,
        ];
    }

    // -----------------------------------------------------------------------
    // Write helpers (mutations moved out of Controller_CourtAjax so all DB work
    // lives in the lib layer). Callers own request-parse/validation/auth/JSON.
    // -----------------------------------------------------------------------

    private function esc($v)
    {
        return str_replace(["'", '\\'], ["''", '\\\\'], $v);
    }

    /**
     * Insert a new court and return its id, or 0 if the court date is malformed.
     *
     * The court date is copied verbatim onto every award this court commits to the
     * permanent player record, so it is validated HERE as well as in the
     * controller: no future caller gets to write a '0000-00-00' award date by
     * skipping the request-layer check. '' means "undated", which is legitimate.
     */
    public function createCourt($kingdom_id, $park_id, $name, $court_date, $event_cd, $created_by)
    {
        $kingdom_id = (int)$kingdom_id;
        $park_id    = (int)$park_id;
        $event_cd   = (int)$event_cd;
        $created_by = (int)$created_by;
        $court_date = trim((string)$court_date);
        if ($court_date !== '' && $this->validDate($court_date) === null) {
            return 0;
        }
        $date_val   = ($court_date !== '') ? "'" . $this->esc($court_date) . "'" : 'NULL';
        $event_val  = $event_cd > 0 ? $event_cd : 'NULL';

        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'court
             (kingdom_id, park_id, name, court_date, event_calendardetail_id, created_by)
             VALUES (' . $kingdom_id . ', ' . $park_id . ', \'' . $this->esc($name) . '\',
                     ' . $date_val . ', ' . $event_val . ', ' . $created_by . ')'
        );
        $this->db->Clear();
        $row = $this->db->DataSet('SELECT LAST_INSERT_ID() AS court_id');
        return ($row && $row->Next()) ? (int)$row->court_id : 0;
    }

    /** Set the workflow status of a court (caller validates the value). */
    public function updateCourtStatus($court_id, $status)
    {
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'court SET status = \'' . $this->esc($status) . '\'
             WHERE court_id = ' . (int)$court_id
        );
    }

    /**
     * Stamp when the court packet was last printed (spec §4). Backs the paper's
     * "printed <date>" line and the Record Court drift warning. Also records the
     * award count at that moment (courtChangedSincePrint() below compares against
     * it) — court_award.modified is ON UPDATE CURRENT_TIMESTAMP, so a timestamp
     * comparison would false-positive on every mark (Given/Skipped/giver change);
     * a row-count comparison only fires when a walk-on is added or a row is
     * removed, which is the only thing that actually renumbers the sheet.
     */
    public function markCourtPrinted($court_id)
    {
        $court_id = (int)$court_id;
        if (!valid_id($court_id)) {
            return false;
        }

        // Printing the packet is the other moment the seats that will confer these
        // honors are known — capture them before a reign change can move them.
        $this->snapshotCourtGivers($court_id);

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'UPDATE ' . DB_PREFIX . 'court c
                SET c.last_printed_at = NOW(),
                    c.last_printed_award_count = (
                        SELECT COUNT(*) FROM ' . DB_PREFIX . 'court_award ca
                         WHERE ca.court_id = c.court_id
                    )
              WHERE c.court_id = ' . $court_id
        );

        return $rs && $rs->Size() >= 1;
    }

    /**
     * True when the court's current award count differs from the count recorded
     * at last print (spec §5) — i.e. a walk-on was added, or a row removed, since
     * the paper was printed, so the numbering on screen no longer matches the
     * numbering the recorder is holding.
     *
     * Deliberately NOT a timestamp comparison against court_award.modified: that
     * column is ON UPDATE CURRENT_TIMESTAMP, so it bumps on every mark (Given /
     * Skipped / giver change) even though marking never changes the row count or
     * renumbers anything. A count comparison is the only signal that tracks what
     * actually invalidates the paper.
     *
     * Never printed => false: there is no paper to diverge from.
     * Printed before this column existed (count is NULL) => false: nothing to
     * compare against, so don't guess.
     */
    public function courtChangedSincePrint($court_id)
    {
        $court_id = (int)$court_id;
        if (!valid_id($court_id)) {
            return false;
        }

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT c.last_printed_at, c.last_printed_award_count,
                    (SELECT COUNT(*) FROM ' . DB_PREFIX . 'court_award ca
                      WHERE ca.court_id = c.court_id) AS current_count
               FROM ' . DB_PREFIX . 'court c
              WHERE c.court_id = ' . $court_id
        );

        if (!$rs || !$rs->Next() || empty($rs->last_printed_at) || $rs->last_printed_award_count === null) {
            return false;
        }

        return (int)$rs->current_count !== (int)$rs->last_printed_award_count;
    }

    /**
     * Edit a court's own metadata (spec 0.1). Permitted in draft and published;
     * refused once complete, because finalized rows already carry the date.
     *
     * Partial by design: only the keys supplied are written.
     */
    public function updateCourt($court_id, array $fields)
    {
        $court_id = (int)$court_id;
        if (!valid_id($court_id) || !$fields) {
            return false;
        }

        $map = [
            'Name'                  => 'name',
            'CourtDate'             => 'court_date',
            'EventCalendarDetailId' => 'event_calendardetail_id',
            'RecorderMundaneId'     => 'recorder_mundane_id',
        ];

        $sets = [];
        foreach ($map as $key => $column) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $value = $fields[$key];
            if ($column === 'name') {
                $sets[] = 'name = \'' . $this->esc((string)$value) . '\'';
            } elseif ($column === 'court_date') {
                // Same reasoning as createCourt: this value becomes the awarded
                // date on the permanent record, so a malformed one refuses the
                // whole edit rather than being stored as '0000-00-00'.
                $value = trim((string)$value);
                if ($value === '') {
                    $sets[] = 'court_date = NULL';
                } elseif ($this->validDate($value) === null) {
                    return false;
                } else {
                    $sets[] = 'court_date = \'' . $this->esc($value) . '\'';
                }
            } else {
                $sets[] = $column . ' = ' . ((int)$value > 0 ? (int)$value : 'NULL');
            }
        }

        if (!$sets) {
            return false;
        }

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'UPDATE ' . DB_PREFIX . 'court SET ' . implode(', ', $sets) . '
              WHERE court_id = ' . $court_id . ' AND status <> \'complete\''
        );

        if (!$rs) {
            return false;
        }
        if ($rs->Size() >= 1) {
            return true;
        }

        // Size() is PDO rowCount() — CHANGED rows, not matched rows. ork_court
        // has no row_version and this UPDATE bumps nothing, so an edit whose
        // values all equal what is already stored reports 0 and is
        // indistinguishable from "the status <> 'complete' guard refused".
        // The Edit Details modal always posts all four fields, so a save with
        // no net change hit that path and told the officer a *draft* court
        // could not be edited because it was complete. Read the row back to
        // tell the two apart: still editable means nothing simply differed.
        // (updateAward is immune only because it always appends
        // 'row_version = row_version + 1', so its UPDATE always changes a row.)
        $this->db->Clear();
        $chk = $this->db->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . 'court
              WHERE court_id = ' . $court_id . ' AND status <> \'complete\' LIMIT 1'
        );

        return (bool)($chk && $chk->Next());
    }

    /**
     * Add an award to a court. Enforces object-level authorization: the
     * kingdomaward must belong to $kingdom_id (the court's own kingdom), else
     * an officer could attach another kingdom's award id. Returns the assembled
     * award payload on success, or false if the award is out of scope.
     *
     * $enrich = false skips the two display-only SELECTs (recipient persona +
     * award name/flags, and the originating recommendation's reason) and the
     * artisan load, returning only the identity fields. Bulk callers such as
     * prepopulate_from_last_court check nothing but `!== false`, and paid ~3
     * queries per row for a payload they discarded.
     */
    public function addAward($court_id, $kingdom_id, $mundane_id, $kingdomaward_id, $rank, $rec_id, $pass_to_local, $notes, $public_comment, $enrich = true)
    {
        $court_id        = (int)$court_id;
        $kingdom_id      = (int)$kingdom_id;
        $mundane_id      = (int)$mundane_id;
        $kingdomaward_id = (int)$kingdomaward_id;
        $rank            = (int)$rank;
        $rec_id          = (int)$rec_id;
        $pass_to_local   = $pass_to_local ? 1 : 0;

        // Object-level authorization / IDOR guard.
        $this->db->Clear();
        $chk = $this->db->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . 'kingdomaward
              WHERE kingdomaward_id = ' . $kingdomaward_id . '
                AND kingdom_id = ' . $kingdom_id . ' LIMIT 1'
        );
        if (!$chk || !$chk->Next()) {
            return false;
        }

        // Idempotent add for a recommendation-backed line. One recommendation is one
        // honor, so a court carries it at most once. Without this, a double-click on
        // "Add Selected" (or a retry after a flaky response) creates two court_award
        // rows for the same rec. Per-line idempotency does not help there —
        // claimStagedForGrant guarantees each LINE commits once, so two lines finalize
        // into two ork_awards rows for one recommendation.
        // 'cancelled' is excluded on purpose: an officer who skipped a rec and then
        // deliberately re-adds it must get a fresh, live line.
        $existing = null;
        if ($rec_id > 0) {
            // Match on the rec id OR on the HONOR itself. The honor is the cluster
            // (mundane_id + kingdomaward_id + rank), not the recommendation: several
            // people routinely recommend the same person for the same award, and each
            // of those sibling recs has its own recommendations_id. Keying the probe
            // on the rec id alone let every sibling add its own court line, and at
            // finalize each line is its own idempotency key — claimStagedForGrant
            // succeeds once per line, so one honor reached ork_awards N times. This is
            // the same match reconcileGrantForRecommendation uses to close court lines.
            $this->db->Clear();
            $dup = $this->db->DataSet(
                'SELECT court_award_id, rank, sort_order, pass_to_local, notes,
                        public_comment, status, scroll_status, regalia_status
                   FROM ' . DB_PREFIX . "court_award
                  WHERE court_id = " . $court_id . '
                    AND (recommendations_id = ' . $rec_id . '
                         OR (mundane_id = ' . $mundane_id . '
                             AND kingdomaward_id = ' . $kingdomaward_id . '
                             AND rank = ' . $rank . "))
                    AND status != 'cancelled'
                  ORDER BY court_award_id LIMIT 1"
            );
            if ($dup && $dup->Next()) {
                $existing = [
                    'court_award_id' => (int)$dup->court_award_id,
                    'rank'           => (int)$dup->rank,
                    'sort_order'     => (int)$dup->sort_order,
                    'pass_to_local'  => (int)$dup->pass_to_local,
                    'notes'          => (string)($dup->notes ?? ''),
                    'public_comment' => (string)($dup->public_comment ?? ''),
                    'status'         => (string)$dup->status,
                    'scroll_status'  => (int)$dup->scroll_status,
                    'regalia_status' => (int)$dup->regalia_status,
                ];
            }
        }

        // Defaults for a freshly-inserted line; overwritten below when we are handing
        // back the line that already exists for this recommendation.
        $status         = 'planned';
        $scroll_status  = 0;
        $regalia_status = 0;

        if ($existing !== null) {
            // Repeat add — return the live line as-is. Deliberately does NOT overwrite
            // its notes/public_comment/rank with the incoming values: the officer may
            // have edited them since, and a stray double-submit must never clobber
            // real edits.
            $court_award_id = $existing['court_award_id'];
            $rank           = $existing['rank'];
            $sort           = $existing['sort_order'];
            $pass_to_local  = $existing['pass_to_local'];
            $notes          = $existing['notes'];
            $public_comment = $existing['public_comment'];
            $status         = $existing['status'];
            $scroll_status  = $existing['scroll_status'];
            $regalia_status = $existing['regalia_status'];
        } else {
            // Next sort_order — the bottom of the whole running order, notes included,
            // so a new award never lands above a trailing note.
            $sort = $this->edgeSortOrder($court_id, 'bottom');

            $rec_val   = $rec_id > 0 ? $rec_id : 'NULL';
            $notes_val = "'" . $this->esc($notes) . "'";

            $this->db->Clear();
            $this->db->Execute(
                'INSERT INTO ' . DB_PREFIX . 'court_award
             (court_id, mundane_id, kingdomaward_id, rank, recommendations_id,
              sort_order, pass_to_local, notes, public_comment)
             VALUES (' . $court_id . ', ' . $mundane_id . ', ' . $kingdomaward_id . ', ' . $rank . ',
                     ' . $rec_val . ', ' . $sort . ', ' . $pass_to_local . ', ' . $notes_val . ',
                     \'' . $this->esc($public_comment) . '\')'
            );
            if ($rec_id > 0) {
                // uniq_court_rec (court_id, recommendations_id) makes "one
                // recommendation, at most one line on this court" a database fact,
                // so the check-then-insert probe above can no longer be passed by
                // two reeves working the same pending-recs list at once.
                //
                // PDO runs in ERRMODE_WARNING here, so the losing INSERT does not
                // throw — it silently writes nothing — and LAST_INSERT_ID() then
                // reports a stale id from an earlier statement. Read the row back
                // by the unique pair instead, which is correct for the winner and
                // hands the loser the winning row rather than someone else's.
                //
                // The read-back also covers the one legitimate collision: a line
                // the officer previously SKIPPED. The probe above deliberately
                // ignores 'cancelled' rows so a deliberate re-add gets a live
                // line; the index does not distinguish them, so revive that row in
                // place (fresh values, moved to the end of the running order)
                // rather than failing an add the officer cannot otherwise make.
                //
                // The revive MUST leave the row indistinguishable from the fresh
                // INSERT it replaces. A cancelled line is not necessarily blank:
                // reconcileGrantForRecommendation ("Grant Award -> remove from
                // court" in the Recs Manager) stamps award_id AND
                // given_by_mundane_id onto the line it closes. Carrying those into
                // the new life is not cosmetic — bulkStagePlanned deliberately
                // preserves a non-zero giver, so commitStagedAward would hand
                // AddAward the earlier officer and ork_awards.given_by_id would
                // permanently credit the wrong person instead of the Crown who
                // conferred it. Clear the whole carried-over state (prior grant
                // link, giver, stale finalize error, and both fulfillment tracks).
                $this->db->Clear();
                $back = $this->db->DataSet(
                    'SELECT court_award_id, status FROM ' . DB_PREFIX . 'court_award
                      WHERE court_id = ' . $court_id . '
                        AND recommendations_id = ' . $rec_id . '
                      ORDER BY court_award_id LIMIT 1'
                );
                $court_award_id = ($back && $back->Next()) ? (int)$back->court_award_id : 0;
                $back_status    = $court_award_id > 0 ? (string)$back->status : '';
                if ($back_status === 'cancelled') {
                    $this->db->Clear();
                    $this->db->Execute(
                        'UPDATE ' . DB_PREFIX . 'court_award SET
                             status = \'planned\',
                             rank = ' . $rank . ',
                             sort_order = ' . $sort . ',
                             pass_to_local = ' . $pass_to_local . ',
                             notes = ' . $notes_val . ',
                             public_comment = \'' . $this->esc($public_comment) . '\',
                             public_comment_cleared = 0,
                             award_id = NULL,
                             given_by_mundane_id = NULL,
                             last_finalize_error = NULL,
                             scroll_status = 0,
                             regalia_status = 0,
                             scroll_maker_id = NULL,
                             regalia_maker_id = NULL,
                             row_version = row_version + 1
                          WHERE court_award_id = ' . $court_award_id . '
                            AND status = \'cancelled\''
                    );
                } elseif ($back_status !== '') {
                    $status = $back_status;
                }
            } else {
                // Walk-on line (recommendations_id NULL, so outside the unique
                // index). LAST_INSERT_ID() is connection-scoped, so it is the ONLY
                // safe way to name the row we just wrote. A "highest id for this
                // court" re-query is not: two officers adding to the same court
                // (the multi-reeve case this tool is built for) would each read
                // back whichever INSERT committed last, and every follow-up action
                // keyed off that id would mutate the other officer's award.
                $this->db->Clear();
                $idr = $this->db->DataSet('SELECT LAST_INSERT_ID() AS court_award_id');
                $court_award_id = ($idr && $idr->Next()) ? (int)$idr->court_award_id : 0;
            }
        }

        $persona     = '';
        $park_abbrev = '';
        $award_name  = '';
        $is_ladder   = false;
        $is_title    = false;
        $rec_reason  = '';

        if ($enrich) {
            // Fetch persona + award_name for response
            $this->db->Clear();
            $info = $this->db->DataSet(
                'SELECT m.persona, p.abbreviation AS park_abbrev, IFNULL(ka.name, a.name) AS award_name,
                        ' . self::SQL_EFFECTIVE_IS_LADDER . ' AS is_ladder,
                        ' . self::SQL_EFFECTIVE_IS_TITLE . ' AS is_title
                 FROM ' . DB_PREFIX . 'mundane m
                 LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = m.park_id
                 JOIN ' . DB_PREFIX . 'kingdomaward ka ON ka.kingdomaward_id = ' . $kingdomaward_id . '
                 LEFT JOIN ' . DB_PREFIX . 'award a ON a.award_id = ka.award_id
                 WHERE m.mundane_id = ' . $mundane_id . '
                 LIMIT 1'
            );
            if ($info && $info->Next()) {
                $persona     = $info->persona;
                $park_abbrev = $info->park_abbrev ?? '';
                $award_name  = $info->award_name;
                $is_ladder   = (bool)(int)$info->is_ladder;
                $is_title    = (bool)(int)$info->is_title;
            }

            if ($rec_id) {
                $this->db->Clear();
                $rr = $this->db->DataSet('SELECT reason FROM ' . DB_PREFIX . 'recommendations WHERE recommendations_id = ' . $rec_id . ' LIMIT 1');
                if ($rr && $rr->Next()) {
                    $rec_reason = $rr->reason ?? '';
                }
            }
        }

        return [
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
            'PublicComment'     => $public_comment,
            'RecReason'         => $rec_reason,
            'Status'            => $status,
            'ScrollStatus'      => $scroll_status,
            'RegaliaStatus'     => $regalia_status,
            'Artisans'          => ($enrich && $existing !== null) ? $this->getArtisans($court_award_id) : [],
            'AlreadyOnCourt'    => $existing !== null,
        ];
    }

    /** Artisans credited on one court_award, in insertion order. */
    public function getArtisans($court_award_id)
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT caa.court_award_artisan_id, caa.mundane_id, caa.contribution, m.persona
             FROM ' . DB_PREFIX . 'court_award_artisan caa
             LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = caa.mundane_id
             WHERE caa.court_award_id = ' . (int)$court_award_id . '
             ORDER BY caa.court_award_artisan_id'
        );
        $out = [];
        if ($rs) {
            while ($rs->Next()) {
                $out[] = [
                    'CourtAwardArtisanId' => (int)$rs->court_award_artisan_id,
                    'MundaneId'           => (int)$rs->mundane_id,
                    'Persona'             => $rs->persona,
                    'Contribution'        => $rs->contribution,
                ];
            }
        }
        return $out;
    }

    /** court_id owning a court_award row, or 0 if the award does not exist. */
    public function getCourtAwardCourtId($court_award_id)
    {
        $this->db->Clear();
        $r = $this->db->DataSet('SELECT court_id FROM ' . DB_PREFIX . 'court_award
                            WHERE court_award_id = ' . (int)$court_award_id . ' LIMIT 1');
        return ($r && $r->Next()) ? (int)$r->court_id : 0;
    }

    /**
     * Delete a court_award (and its artisans). QW5 guard: NEVER hard-DELETE a
     * committed ('given') row — that would destroy the audit trace of a grant
     * already written to the permanent player record. Refuses (returns false) in
     * that case so the caller can surface "already granted"; deletes and returns
     * true otherwise.
     */
    public function removeAward($court_award_id)
    {
        $court_award_id = (int)$court_award_id;

        $this->db->Clear();
        $chk = $this->db->DataSet('SELECT status FROM ' . DB_PREFIX . 'court_award
                            WHERE court_award_id = ' . $court_award_id . ' LIMIT 1');
        if ($chk && $chk->Next() && $chk->status === 'given') {
            return false;
        }

        $this->db->Clear();
        $this->db->Execute('DELETE FROM ' . DB_PREFIX . 'court_award_artisan
                       WHERE court_award_id = ' . $court_award_id);
        $this->db->Clear();
        $this->db->Execute('DELETE FROM ' . DB_PREFIX . 'court_award
                       WHERE court_award_id = ' . $court_award_id);
        return true;
    }

    /**
     * [court_id, recommendations_id, status] for a court_award, or null if absent.
     *
     * `status` is part of the contract: pass_award_to_local writes
     * recommendations.passed_to_local BEFORE calling removeAward, and only
     * removeAward is guarded against a committed ('given') row. Without the status
     * here the caller could not detect the already-granted case up front, so the
     * endpoint reported "already granted and cannot be removed" while leaving the
     * recommendation permanently and wrongly flagged as deferred to the local park,
     * with no rollback.
     */
    public function getCourtAwardForPass($court_award_id)
    {
        $this->db->Clear();
        $r = $this->db->DataSet('SELECT court_id, recommendations_id, status
                            FROM ' . DB_PREFIX . 'court_award
                            WHERE court_award_id = ' . (int)$court_award_id . ' LIMIT 1');
        if (!$r || !$r->Next()) {
            return null;
        }
        return [
            'court_id'           => (int)$r->court_id,
            'recommendations_id' => (int)$r->recommendations_id,
            'status'             => (string)$r->status,
        ];
    }

    /**
     * Set only the status of a court_award (caller validates the value).
     * QW5 guard: refuses to move a committed ('given') row — a stale
     * skip/set-status can never destroy a finalized row's lifecycle. S5
     * optimistic lock: pass $expectedRowVersion to require the client's token
     * still be current. Returns true iff exactly one row changed (row_version is
     * always bumped on a match, so 0 rows == guard hit / stale / gone).
     */
    public function setAwardStatus($court_award_id, $status, $expectedRowVersion = null)
    {
        $where = 'court_award_id = ' . (int)$court_award_id . ' AND status != \'given\'';
        if ($expectedRowVersion !== null) {
            $where .= ' AND row_version = ' . (int)$expectedRowVersion;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'UPDATE ' . DB_PREFIX . 'court_award
                SET status = \'' . $this->esc($status) . '\',
                    row_version = row_version + 1
              WHERE ' . $where
        );
        return $rs && $rs->Size() == 1;
    }

    /**
     * Guarded soft-cancel (QW5): mark a row 'cancelled' unless it is already
     * 'given' (a committed grant's audit trace is never destroyed). This is the
     * lib home for the skip flow whose raw guard used to live in the controller.
     * Optional S5 optimistic lock via $expectedRowVersion. Returns true iff
     * exactly one row changed (0 == already granted / stale / gone).
     */
    public function skipAward($court_award_id, $expectedRowVersion = null)
    {
        $where = 'court_award_id = ' . (int)$court_award_id . ' AND status != \'given\'';
        if ($expectedRowVersion !== null) {
            $where .= ' AND row_version = ' . (int)$expectedRowVersion;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'UPDATE ' . DB_PREFIX . 'court_award
                SET status = \'cancelled\',
                    row_version = row_version + 1
              WHERE ' . $where
        );
        return $rs && $rs->Size() == 1;
    }

    /**
     * Field-only edit of a court award — never its status (QW4). The lifecycle
     * moves solely through stage/unstage/skip/set-status/commit, so a stale
     * field-save can no longer drag a row's status backward. S5 optimistic
     * lock: pass $expectedRowVersion to require the client's token still be
     * current. row_version is always bumped on a match, so a matched row always
     * reports one affected row: returns true iff exactly one row changed (0 ==
     * stale row_version / gone).
     *
     * PARTIAL by design (spec 0.6): only the keys present in $fields are
     * written. The previous six-positional-parameter version wrote all five
     * columns unconditionally, so any caller that did not send every field
     * silently erased internal notes, pass-to-local, and both maker credits.
     * $fields may contain any of: Notes, PublicComment, PublicCommentCleared,
     * PassToLocal, ScrollMakerId, RegaliaMakerId.
     */
    public function updateAward($court_award_id, array $fields, $expectedRowVersion = null)
    {
        $court_award_id = (int)$court_award_id;
        if (!valid_id($court_award_id) || !$fields) {
            return false;
        }

        // Reject the whole write if any key is unrecognized rather than
        // silently dropping it. An all-unrecognized $fields already returned
        // false via the empty-$sets guard below; the MIXED case did not — a
        // typo'd key was dropped while its siblings saved, and the caller was
        // told the write succeeded. Nothing can reach that over HTTP today
        // (controller.CourtAjax whitelists these same keys), but partial
        // callers are coming and a silent drop is the wrong default for them.
        $known = [
            'Notes', 'PublicComment', 'PublicCommentCleared', 'PassToLocal',
            'ScrollMakerId', 'RegaliaMakerId',
        ];
        if (array_diff(array_keys($fields), $known)) {
            return false;
        }

        $sets = [];
        if (array_key_exists('Notes', $fields)) {
            $sets[] = 'notes = \'' . $this->esc((string)$fields['Notes']) . '\'';
        }
        if (array_key_exists('PublicComment', $fields)) {
            $sets[] = 'public_comment = \'' . $this->esc((string)$fields['PublicComment']) . '\'';
        }
        if (array_key_exists('PublicCommentCleared', $fields)) {
            // The officer's explicit "publish nothing for this line" flag. Only an
            // explicitly cleared line suppresses the inherited recommendation
            // reason at commit; a line that merely happens to be blank still
            // inherits, exactly as it always has.
            $sets[] = 'public_comment_cleared = ' . ((int)$fields['PublicCommentCleared'] ? 1 : 0);
        }
        if (array_key_exists('PassToLocal', $fields)) {
            $sets[] = 'pass_to_local = ' . ((int)$fields['PassToLocal'] ? 1 : 0);
        }
        if (array_key_exists('ScrollMakerId', $fields)) {
            $sets[] = 'scroll_maker_id = ' . ((int)$fields['ScrollMakerId'] > 0 ? (int)$fields['ScrollMakerId'] : 'NULL');
        }
        if (array_key_exists('RegaliaMakerId', $fields)) {
            $sets[] = 'regalia_maker_id = ' . ((int)$fields['RegaliaMakerId'] > 0 ? (int)$fields['RegaliaMakerId'] : 'NULL');
        }

        if (!$sets) {
            return false;
        }

        $sets[] = 'row_version = row_version + 1';

        $where = 'court_award_id = ' . $court_award_id;
        if ($expectedRowVersion !== null) {
            $where .= ' AND row_version = ' . (int)$expectedRowVersion;
        }

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'UPDATE ' . DB_PREFIX . 'court_award SET ' . implode(', ', $sets) . ' WHERE ' . $where
        );

        return $rs && $rs->Size() == 1;
    }

    /**
     * Persist a new display order. $order is the running order top to bottom: an
     * integer is a court_award_id, a string 'n<id>' is a court_note_id. Awards and
     * notes share one sort_order number space, so each entry takes the next slot
     * whichever table it lives in. Only rows on $court_id are touched.
     *
     * INTERIM GATE: rows already committed to the permanent record ('given') are
     * excluded. Their order is the running order of a ceremony that has already
     * happened, and it is what the login-free public Court Report prints — a
     * resort after the fact silently rewrites history. The endpoint additionally
     * refuses a completed court outright (controller.CourtAjax::reorder_awards).
     * The intended follow-up is an explicit, AUDITED amend path (who/when stamped)
     * for correcting a finalized court, not reopening it for free-form edits.
     */
    public function reorderAwards($court_id, $order)
    {
        $court_id  = (int)$court_id;
        $cases     = '';
        $ids       = [];
        $noteCases = '';
        $noteIds   = [];
        $sort      = 10;
        foreach ($order as $entry) {
            $isNote = is_string($entry) && isset($entry[0]) && $entry[0] === 'n';
            $id     = (int)($isNote ? substr($entry, 1) : $entry);
            if ($id <= 0) {
                continue;
            }
            if ($isNote) {
                $noteCases .= ' WHEN ' . $id . ' THEN ' . $sort;
                $noteIds[]  = $id;
            } else {
                $cases .= ' WHEN ' . $id . ' THEN ' . $sort;
                $ids[]  = $id;
            }
            $sort += 10;
        }
        if (!empty($ids)) {
            $idCsv = implode(',', $ids);
            $this->db->Clear();
            $this->db->Execute(
                'UPDATE ' . DB_PREFIX . 'court_award
                    SET sort_order = CASE court_award_id' . $cases . ' END,
                        row_version = row_version + 1
                  WHERE court_id = ' . $court_id . '
                    AND court_award_id IN (' . $idCsv . ')
                    AND status <> \'given\''
            );
        }
        if (!empty($noteIds)) {
            $this->db->Clear();
            $this->db->Execute(
                'UPDATE ' . DB_PREFIX . 'court_note
                    SET sort_order = CASE court_note_id' . $noteCases . ' END,
                        row_version = row_version + 1
                  WHERE court_id = ' . $court_id . '
                    AND court_note_id IN (' . implode(',', $noteIds) . ')'
            );
        }
    }

    // -----------------------------------------------------------------------
    // Court notes — non-award line items on the running order ("autocrat
    // announcements", "officer changeover"). Stored in court_note, NOT
    // court_award: every court_award row is a candidate for the stage/finalize
    // pipeline that writes the permanent player-award record, so a note kept
    // there would be one missed filter away from being granted as an award.
    // court_note.sort_order shares one number space with court_award.sort_order.
    // -----------------------------------------------------------------------

    /**
     * The sort_order that puts a new line at the 'top' or 'bottom' of a court's
     * whole running order (awards and notes together). 10 on an empty court.
     *
     * Two queries, not a UNION: the award half is the one addAward has always
     * depended on, and it must keep answering on a database where court_note does
     * not exist yet — there the note half simply contributes nothing.
     */
    private function edgeSortOrder($court_id, $position)
    {
        $court_id = (int)$court_id;
        $lo = null;
        $hi = null;
        foreach (['court_award', 'court_note'] as $table) {
            $this->db->Clear();
            $rs = $this->db->DataSet(
                'SELECT MIN(sort_order) AS lo, MAX(sort_order) AS hi
                   FROM ' . DB_PREFIX . $table . ' WHERE court_id = ' . $court_id
            );
            if ($rs && $rs->Next() && $rs->hi !== null) {
                $lo = $lo === null ? (int)$rs->lo : min($lo, (int)$rs->lo);
                $hi = $hi === null ? (int)$rs->hi : max($hi, (int)$rs->hi);
            }
        }
        if ($hi === null) {
            return 10;
        }
        return $position === 'top' ? $lo - 10 : $hi + 10;
    }

    /**
     * Add a note at the 'top' or 'bottom' of a court's running order. Returns the
     * note payload, or false when the title is blank.
     */
    public function addNote($court_id, $title, $details, $position, $created_by)
    {
        $court_id = (int)$court_id;
        $title    = mb_substr(trim((string)$title), 0, 150);
        $details  = trim((string)$details);
        if (!valid_id($court_id) || $title === '') {
            return false;
        }
        $sort = $this->edgeSortOrder($court_id, $position === 'top' ? 'top' : 'bottom');

        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'court_note (court_id, title, details, sort_order, created_by)
             VALUES (' . $court_id . ', \'' . $this->esc($title) . '\', \'' . $this->esc($details) . '\',
                     ' . $sort . ', ' . (int)$created_by . ')'
        );
        // Connection-scoped id (see addAward) — never a "highest id for this court".
        $this->db->Clear();
        $idr = $this->db->DataSet('SELECT LAST_INSERT_ID() AS court_note_id');
        $court_note_id = ($idr && $idr->Next()) ? (int)$idr->court_note_id : 0;
        if ($court_note_id <= 0) {
            return false;
        }

        return [
            'CourtNoteId' => $court_note_id,
            'Title'       => $title,
            'Details'     => $details,
            'SortOrder'   => $sort,
        ];
    }

    /** A court's notes in running order: [CourtNoteId, Title, Details, SortOrder]. */
    public function getCourtNotes($court_id)
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT court_note_id, title, details, sort_order
               FROM ' . DB_PREFIX . 'court_note
              WHERE court_id = ' . (int)$court_id . '
              ORDER BY sort_order, court_note_id'
        );
        $notes = [];
        if ($rs) {
            while ($rs->Next()) {
                $notes[] = [
                    'CourtNoteId' => (int)$rs->court_note_id,
                    'Title'       => $rs->title,
                    'Details'     => $rs->details ?? '',
                    'SortOrder'   => (int)$rs->sort_order,
                ];
            }
        }
        return $notes;
    }

    /** Rewrite a note's title and details. False when the title is blank or the note is gone. */
    public function updateNote($court_note_id, $title, $details)
    {
        $court_note_id = (int)$court_note_id;
        $title         = mb_substr(trim((string)$title), 0, 150);
        if (!valid_id($court_note_id) || $title === '') {
            return false;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'UPDATE ' . DB_PREFIX . 'court_note
                SET title = \'' . $this->esc($title) . '\',
                    details = \'' . $this->esc(trim((string)$details)) . '\',
                    row_version = row_version + 1
              WHERE court_note_id = ' . $court_note_id
        );
        return $rs && $rs->Size() == 1;
    }

    public function removeNote($court_note_id)
    {
        $this->db->Clear();
        $this->db->Execute('DELETE FROM ' . DB_PREFIX . 'court_note
                       WHERE court_note_id = ' . (int)$court_note_id);
    }

    /** court_id that owns a note, or 0 if absent (for requireCourtAuth). */
    public function getCourtNoteCourtId($court_note_id)
    {
        $this->db->Clear();
        $r = $this->db->DataSet('SELECT court_id FROM ' . DB_PREFIX . 'court_note
                            WHERE court_note_id = ' . (int)$court_note_id . ' LIMIT 1');
        return ($r && $r->Next()) ? (int)$r->court_id : 0;
    }

    public function addArtisan($court_award_id, $mundane_id, $contribution)
    {
        $court_award_id = (int)$court_award_id;
        $mundane_id     = (int)$mundane_id;
        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'court_award_artisan
             (court_award_id, mundane_id, contribution)
             VALUES (' . $court_award_id . ', ' . $mundane_id . ',
                     \'' . $this->esc($contribution) . '\')'
        );
        // Connection-scoped id (see addAward) — a "highest id for this court_award"
        // re-query hands back another officer's artisan row under concurrent adds.
        $this->db->Clear();
        $idr = $this->db->DataSet('SELECT LAST_INSERT_ID() AS court_award_artisan_id');
        $artisan_id = ($idr && $idr->Next()) ? (int)$idr->court_award_artisan_id : 0;

        $this->db->Clear();
        $pr = $this->db->DataSet('SELECT persona FROM ' . DB_PREFIX . 'mundane
                              WHERE mundane_id = ' . $mundane_id . ' LIMIT 1');
        $persona = ($pr && $pr->Next()) ? (string)$pr->persona : '';
        return [
            'CourtAwardArtisanId' => $artisan_id,
            'MundaneId'           => $mundane_id,
            'Persona'             => $persona,
            'Contribution'        => $contribution,
        ];
    }

    /** court_id owning an artisan row, or null if the artisan does not exist. */
    public function getArtisanCourtId($artisan_id)
    {
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT ca.court_id
             FROM ' . DB_PREFIX . 'court_award_artisan caa
             LEFT JOIN ' . DB_PREFIX . 'court_award ca ON ca.court_award_id = caa.court_award_id
             WHERE caa.court_award_artisan_id = ' . (int)$artisan_id . ' LIMIT 1'
        );
        if (!$r || !$r->Next()) {
            return null;
        }
        return (int)$r->court_id;
    }

    /** Delete an artisan row. */
    public function removeArtisan($artisan_id)
    {
        $this->db->Clear();
        $this->db->Execute('DELETE FROM ' . DB_PREFIX . 'court_award_artisan
                       WHERE court_award_artisan_id = ' . (int)$artisan_id);
    }

    /** Full court_award + owning-court context needed to grant, or null. */
    public function getCourtAwardForGrant($court_award_id)
    {
        $this->db->Clear();
        $ca = $this->db->DataSet(
            'SELECT ca.court_award_id, ca.mundane_id, ca.kingdomaward_id, ca.rank,
                    ca.notes, ca.status,
                    c.court_date, c.kingdom_id AS c_kingdom_id, c.park_id AS c_park_id,
                    c.event_calendardetail_id, c.status AS court_status
             FROM ' . DB_PREFIX . 'court_award ca
             JOIN ' . DB_PREFIX . 'court c ON c.court_id = ca.court_id
             WHERE ca.court_award_id = ' . (int)$court_award_id . ' LIMIT 1'
        );
        if (!$ca || !$ca->Next()) {
            return null;
        }
        return [
            'CourtAwardId'          => (int)$ca->court_award_id,
            'MundaneId'             => (int)$ca->mundane_id,
            'KingdomAwardId'        => (int)$ca->kingdomaward_id,
            'Rank'                  => (int)$ca->rank,
            'Notes'                 => $ca->notes ?? '',
            'Status'                => $ca->status,
            'CourtDate'             => $ca->court_date,
            'KingdomId'             => (int)$ca->c_kingdom_id,
            'ParkId'                => (int)$ca->c_park_id,
            'EventCalendarDetailId' => (int)$ca->event_calendardetail_id,
            'CourtStatus'           => $ca->court_status,
        ];
    }

    /** Resolve an event_id from an event_calendardetail_id (0 if none). */
    public function getEventIdFromCalendarDetail($event_calendardetail_id)
    {
        $event_calendardetail_id = (int)$event_calendardetail_id;
        if ($event_calendardetail_id <= 0) {
            return 0;
        }
        $this->db->Clear();
        $ev = $this->db->DataSet(
            'SELECT event_id FROM ' . DB_PREFIX . 'event_calendardetail
             WHERE event_calendardetail_id = ' . $event_calendardetail_id . ' LIMIT 1'
        );
        return ($ev && $ev->Next()) ? (int)$ev->event_id : 0;
    }

    /** Release a claimed grant when the downstream award insert fails. */
    public function revertAwardStatus($court_award_id, $status)
    {
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'court_award
                SET status = \'' . $this->esc($status) . '\', row_version = row_version + 1
              WHERE court_award_id = ' . (int)$court_award_id . ' AND status = \'given\''
        );
    }

    // -----------------------------------------------------------------------
    // Stage / finalize (spec 2026-07-11-court-planner-stage-finalize-design.md)
    //
    // Granting at court now *stages* a row (captures giver/reason/rank without
    // touching the permanent player record). A separate finalize step commits
    // every staged row via add_player_award and flips it to 'given'.
    // -----------------------------------------------------------------------

    /**
     * Stage a grant in a single atomic UPDATE: capture giver/citation/rank and
     * mark the row 'staged'. Guarded so a double-submit can't double-stage
     * (won't touch rows already given/cancelled/staged). Optional S5 optimistic
     * lock via $expectedRowVersion, mirroring skipAward/setAwardStatus, so two
     * officers granting the same award from two annotated printouts can't
     * clobber each other. row_version is always bumped on a match, so 0 rows
     * changed == guard hit / stale / gone. Returns true iff exactly one row
     * changed.
     */
    public function stageAward($court_award_id, $given_by_mundane_id, $public_comment, $rank, $expectedRowVersion = null)
    {
        $where = 'court_award_id = ' . (int)$court_award_id . '
                AND status NOT IN (\'given\', \'cancelled\', \'staged\')';
        if ($expectedRowVersion !== null) {
            $where .= ' AND row_version = ' . (int)$expectedRowVersion;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'UPDATE ' . DB_PREFIX . 'court_award SET
                 status = \'staged\',
                 given_by_mundane_id = ' . (int)$given_by_mundane_id . ',
                 public_comment = \'' . $this->esc($public_comment) . '\',
                 rank = ' . (int)$rank . ',
                 row_version = row_version + 1
              WHERE ' . $where
        );
        return $rs && $rs->Size() == 1;
    }

    /**
     * Undo a stage: 'staged' -> 'planned'. No player-record trace to reverse.
     *
     * Also clears the capture the stage made — giver and public citation. Leaving
     * them behind meant an undone grant kept the giver the officer had just
     * withdrawn, and any later route back to 'staged' would let finalize commit that
     * rejected giver and citation to the permanent record: commitStagedAward's
     * backstop only refuses a row with NO giver, and this row would still have one.
     * Assigns '' rather than null because yapo drops nulls from an UPDATE.
     *
     * S5 optimistic lock: pass $expectedRowVersion to require the client's token
     * still be current (mirrors stageAward/skipAward — same WHERE-clause guard,
     * same DataSet()+Size()==1 shape). Uses DataSet rather than Execute()
     * specifically so the affected-row count is observable: Execute() returns
     * void, which is how this method previously reported success unconditionally
     * even when its WHERE matched nothing (a concurrent skip had already moved
     * the row out of 'staged').
     */
    public function unstageAward($court_award_id, $expectedRowVersion = null)
    {
        $where = 'court_award_id = ' . (int)$court_award_id . ' AND status = \'staged\'';
        if ($expectedRowVersion !== null) {
            $where .= ' AND row_version = ' . (int)$expectedRowVersion;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'UPDATE ' . DB_PREFIX . 'court_award
                SET status = \'planned\',
                    given_by_mundane_id = 0,
                    public_comment = \'\',
                    row_version = row_version + 1
              WHERE ' . $where
        );
        return $rs && $rs->Size() == 1;
    }

    /**
     * All staged rows on a court with every field finalize needs to call
     * add_player_award, plus the owning court's context (park/kingdom/date/event).
     */
    public function getStagedAwards($court_id)
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT ca.court_award_id, ca.mundane_id, ca.kingdomaward_id, ca.rank,
                    ca.given_by_mundane_id, ca.public_comment, ca.notes,
                    ca.pass_to_local, ca.recommendations_id,
                    rec.reason AS rec_reason,
                    c.park_id, c.kingdom_id, c.court_date, c.event_calendardetail_id
             FROM ' . DB_PREFIX . 'court_award ca
             JOIN ' . DB_PREFIX . 'court c ON c.court_id = ca.court_id
             LEFT JOIN ' . DB_PREFIX . 'recommendations rec ON rec.recommendations_id = ca.recommendations_id
             WHERE ca.court_id = ' . (int)$court_id . '
               AND ca.status = \'staged\'
             ORDER BY ca.sort_order, ca.court_award_id'
        );
        $rows = [];
        if ($rs) {
            while ($rs->Next()) {
                $rows[] = [
                    'CourtAwardId'          => (int)$rs->court_award_id,
                    'MundaneId'             => (int)$rs->mundane_id,
                    'KingdomAwardId'        => (int)$rs->kingdomaward_id,
                    'Rank'                  => (int)$rs->rank,
                    'GivenByMundaneId'      => $rs->given_by_mundane_id ? (int)$rs->given_by_mundane_id : 0,
                    'PublicComment'         => $rs->public_comment ?? '',
                    'Notes'                 => $rs->notes ?? '',
                    'RecReason'             => $rs->rec_reason ?? '',
                    'PassToLocal'           => (bool)(int)$rs->pass_to_local,
                    'RecommendationsId'     => $rs->recommendations_id ? (int)$rs->recommendations_id : 0,
                    'ParkId'                => (int)$rs->park_id,
                    'KingdomId'             => (int)$rs->kingdom_id,
                    'CourtDate'             => $rs->court_date,
                    'EventCalendarDetailId' => (int)$rs->event_calendardetail_id,
                ];
            }
        }
        return $rows;
    }

    /**
     * Atomic finalize claim: flip 'staged' -> 'given' only if still 'staged'.
     * Returns true iff this caller won the transition (exactly one row changed),
     * so two concurrent finalizes can't both commit the same row (double-grant).
     */
    public function claimStagedForGrant($court_award_id)
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'UPDATE ' . DB_PREFIX . 'court_award
                SET status = \'given\', row_version = row_version + 1
              WHERE court_award_id = ' . (int)$court_award_id . '
                AND status = \'staged\''
        );
        return $rs && $rs->Size() == 1;
    }

    /**
     * Link the created player-record award id back onto a finalized court row.
     *
     * Also the commit stamp for the line, folded into the one UPDATE the commit
     * already had to make:
     *   - clears last_finalize_error, so a failure recorded by an earlier attempt
     *     does not outlive the attempt that succeeded;
     *   - freezes the RESOLVED citation into public_comment when the line had none
     *     of its own. commitStagedAward publishes `public_comment ?: rec.reason`,
     *     so an INHERITED citation reached ork_awards.note while ca.public_comment
     *     stayed empty — and the public Court Report, which reads ca.public_comment
     *     with no recommendations join, printed "—" for exactly those rows. Three
     *     surfaces, three answers to "what was the citation?". Writing back what
     *     was actually published makes the court line the single source of truth.
     *     Never rewrites text an officer typed.
     */
    public function setAwardId($court_award_id, $award_id, $resolved_citation = null)
    {
        $sets = 'award_id = ' . (int)$award_id . ',
                 last_finalize_error = NULL,
                 row_version = row_version + 1';
        if (is_string($resolved_citation) && $resolved_citation !== '') {
            $sets .= ', public_comment = CASE
                           WHEN public_comment IS NULL OR public_comment = \'\'
                           THEN \'' . $this->esc($resolved_citation) . '\'
                           ELSE public_comment END';
        }
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'court_award
                SET ' . $sets . '
              WHERE court_award_id = ' . (int)$court_award_id
        );
    }

    /**
     * All grant fields for ONE court_award (owning court context included), with
     * NO status filter — commitStagedAward needs them AFTER it has claimed the
     * row 'given'. Mirrors the per-row shape of getStagedAwards(). Null if absent.
     */
    public function getAwardForCommit($court_award_id)
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT ca.court_award_id, ca.mundane_id, ca.kingdomaward_id, ca.rank,
                    ca.given_by_mundane_id, ca.public_comment, ca.public_comment_cleared,
                    ca.notes, ca.pass_to_local, ca.recommendations_id,
                    rec.reason AS rec_reason,
                    c.park_id, c.kingdom_id, c.court_date, c.event_calendardetail_id
             FROM ' . DB_PREFIX . 'court_award ca
             JOIN ' . DB_PREFIX . 'court c ON c.court_id = ca.court_id
             LEFT JOIN ' . DB_PREFIX . 'recommendations rec ON rec.recommendations_id = ca.recommendations_id
             WHERE ca.court_award_id = ' . (int)$court_award_id . ' LIMIT 1'
        );
        if (!$rs || !$rs->Next()) {
            return null;
        }
        return [
            'CourtAwardId'          => (int)$rs->court_award_id,
            'MundaneId'             => (int)$rs->mundane_id,
            'KingdomAwardId'        => (int)$rs->kingdomaward_id,
            'Rank'                  => (int)$rs->rank,
            'GivenByMundaneId'      => $rs->given_by_mundane_id ? (int)$rs->given_by_mundane_id : 0,
            'PublicComment'         => $rs->public_comment ?? '',
            'PublicCommentCleared'  => (bool)(int)$rs->public_comment_cleared,
            'Notes'                 => $rs->notes ?? '',
            'RecReason'             => $rs->rec_reason ?? '',
            'PassToLocal'           => (bool)(int)$rs->pass_to_local,
            'RecommendationsId'     => $rs->recommendations_id ? (int)$rs->recommendations_id : 0,
            'ParkId'                => (int)$rs->park_id,
            'KingdomId'             => (int)$rs->kingdom_id,
            'CourtDate'             => $rs->court_date,
            'EventCalendarDetailId' => (int)$rs->event_calendardetail_id,
        ];
    }

    /**
     * The only fields that can still change while a line sits 'staged'.
     *
     * A single primary-key lookup with no joins. finalize already bulk-loaded
     * every other field via getStagedAwards, and those are immutable in 'staged':
     * rank and given_by move only through stageAward (which refuses a staged row),
     * unstageAward and bulkStagePlanned (both of which take the row OUT of
     * 'staged', so the atomic claim would have lost). Only notes, public_comment
     * and its explicit public_comment_cleared marker are writable in place, by
     * updateAward — so those are what a commit must re-read rather than trust from
     * the batch. Returns null if the row vanished.
     */
    public function getAwardCommitFields($court_award_id)
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT rank, given_by_mundane_id, public_comment, public_comment_cleared, notes
               FROM ' . DB_PREFIX . 'court_award
              WHERE court_award_id = ' . (int)$court_award_id . ' LIMIT 1'
        );
        if (!$rs || !$rs->Next()) {
            return null;
        }
        return [
            'Rank'             => (int)$rs->rank,
            'GivenByMundaneId' => $rs->given_by_mundane_id ? (int)$rs->given_by_mundane_id : 0,
            'PublicComment'    => $rs->public_comment ?? '',
            'PublicCommentCleared' => (bool)(int)$rs->public_comment_cleared,
            'Notes'            => $rs->notes ?? '',
        ];
    }

    /**
     * Persist (or clear) the reason a finalize attempt failed for one line.
     *
     * finalize_court's only record of a failed commit used to be the JSON in a
     * one-time toast: if the tab crashed or the officer skimmed the modal, nothing
     * on the server distinguished "AddAward refused because X" from "same-run
     * duplicate" afterwards. Deliberately does NOT bump row_version — this is an
     * out-of-band diagnostic, not a change to the line the officers are editing,
     * and bumping would invalidate every open client's optimistic-lock token.
     */
    public function setLastFinalizeError($court_award_id, $error)
    {
        $error = trim((string)$error);
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'court_award
                SET last_finalize_error = ' . ($error === '' ? 'NULL' : '\'' . $this->esc(mb_substr($error, 0, 1000)) . '\'') . '
              WHERE court_award_id = ' . (int)$court_award_id
        );
    }

    /**
     * S1 single idempotent commit path for ONE staged court line. This is the one
     * place a court row is committed to the permanent player record.
     *
     * Flow (all here, so the controller loop just calls this per row):
     *   1. Atomic claim 'staged' -> 'given' (claimStagedForGrant, Size()==1). The
     *      COURT-LINE identity IS the idempotency key: a line commits at most
     *      once. A double-click / concurrent finalize sees 0 rows and no-ops
     *      (returns ['status' => 'noop']) — SAFE TO CALL TWICE.
     *   2. Load the row's grant fields (row is no longer 'staged', so no filter).
     *   3. Throw-safe player-record write: Ork3::$Lib->player->AddAward is wrapped
     *      in try/catch (\Throwable). A thrown error OR a returned non-zero Status
     *      reverts the claim ('given' -> 'staged') so the row stays re-runnable and
     *      never ends 'given' with award_id IS NULL (QW3).
     *   4. On success, link award_id from the RETURNED insert id (AddAward now
     *      surfaces 'AwardId') — no date heuristic.
     *
     * $ctx must carry the acting user's 'Token' (AddAward records by_whom_id), and
     * may carry two optional finalize-loop optimizations:
     *   'Row'     the row getStagedAwards already returned for this line. Supplied,
     *             step (2) re-reads only the fields that can still change while a
     *             line is 'staged' (see getAwardCommitFields) instead of repeating
     *             getAwardForCommit's two-join fetch of data the caller has in hand.
     *   'EventId' the court's resolved event id. The event is a property of the
     *             COURT, so finalize resolved the identical value once per row.
     *
     * Both are pure optimizations: omit them and the original single-row path runs.
     *
     * Returns one of:
     *   ['status' => 'ok',    'award_id' => int, 'row' => array]  committed now
     *   ['status' => 'noop']                                       already resolved
     *   ['status' => 'duplicate', 'court_award_id' => int, 'row' => array]
     *        the permanent ledger already carries this honor — line cancelled,
     *        nothing written (see ledgerAlreadyHasHonor)
     *   ['status' => 'error', 'error' => string, 'court_award_id' => int]
     */
    public function commitStagedAward($court_award_id, $ctx)
    {
        $court_award_id = (int)$court_award_id;

        // (1) Idempotency key: claim the line. Loser (already given/cancelled/not
        // staged) no-ops.
        if (!$this->claimStagedForGrant($court_award_id)) {
            return ['status' => 'noop', 'court_award_id' => $court_award_id];
        }

        // (2) Load grant fields (row is 'given' now — fetch without status filter).
        // With a pre-loaded row in hand, only the mutable capture fields are
        // re-read; everything else came from the caller's own bulk query.
        $preloaded = (isset($ctx['Row']) && is_array($ctx['Row'])
            && (int)($ctx['Row']['CourtAwardId'] ?? 0) === $court_award_id)
            ? $ctx['Row'] : null;

        if ($preloaded !== null) {
            $fresh = $this->getAwardCommitFields($court_award_id);
            $row   = $fresh === null ? null : array_merge($preloaded, $fresh);
        } else {
            $row = $this->getAwardForCommit($court_award_id);
        }

        if (!$row) {
            $this->revertAwardStatus($court_award_id, 'staged');
            $this->setLastFinalizeError($court_award_id, 'Award row vanished during commit.');
            return [
                'status'         => 'error',
                'court_award_id' => $court_award_id,
                'error'          => 'Award row vanished during commit.',
            ];
        }

        // Giver backstop: never commit a row with no recorded giver.
        if ($row['GivenByMundaneId'] <= 0) {
            $err = 'No giver recorded — re-grant this award and choose who conferred it.';
            $this->revertAwardStatus($court_award_id, 'staged');
            $this->setLastFinalizeError($court_award_id, $err);
            return [
                'status'         => 'error',
                'court_award_id' => $court_award_id,
                'error'          => $err,
            ];
        }

        // Permanent-ledger duplicate guard. Every other guard on this path is
        // per-REQUEST (finalize's $committedClusters) or per-COURT (addAward's probe
        // is scoped WHERE court_id = ...), and claimStagedForGrant only guarantees
        // one LINE commits once — not that one HONOR is granted once. A retried
        // finalize with a freshly staged walk-on, or the same honor staged on two
        // courts, therefore reached AddAward (which has no duplicate guard and saves
        // unconditionally) twice. Check ork_awards itself immediately before the
        // write, and resolve the line without granting when it is already there.
        // Computed here rather than below because the guard must test exactly the
        // date AddAward is about to write — that identity is what makes a retried
        // finalize match by construction.
        // validDate(), not `?:` — '0000-00-00' is what a malformed court date
        // becomes under this codebase's non-strict sql_mode, and it is TRUTHY, so
        // the old fallback wrote it onto the permanent record as the awarded date
        // (and printed it as "December 31, 1969" on the public report). Anything
        // that is not a real calendar date falls back to today, as an undated
        // court always has.
        $date = $this->validDate((string)$row['CourtDate']) ?? date('Y-m-d');
        if ($this->ledgerAlreadyHasHonor($row['MundaneId'], $row['KingdomAwardId'], $row['Rank'], $date)) {
            // Cancel rather than revert to 'staged': a staged line would simply
            // re-attempt (and re-skip) on every later finalize. Same terminal state
            // the same-run duplicate path uses.
            $this->revertAwardStatus($court_award_id, 'cancelled');
            return [
                'status'         => 'duplicate',
                'court_award_id' => $court_award_id,
                'row'            => $row,
            ];
        }

        // The event is a property of the COURT, so a finalize loop resolves the
        // identical id for every line; honor a caller that already did the lookup.
        $event_id = isset($ctx['EventId'])
            ? (int)$ctx['EventId']
            : $this->getEventIdFromCalendarDetail($row['EventCalendarDetailId']);
        // Public citation precedence, per spec 6.1: the officer's public comment,
        // else the originating recommendation's reason, else nothing. `Notes` is the
        // INTERNAL officer note ("hold until the drama settles", "Regent objects") —
        // it is never a citation. ork_awards.note renders on the recipient's public
        // profile to every visitor, and post-finalize undo is out of scope, so a
        // leak here is permanent and hand-editable only by an award admin.
        //
        // ...unless the officer EXPLICITLY cleared the citation. The editor's
        // "(Clear)" action promises that nothing of the recommendation's wording
        // will be published for this award; without an explicit flag an emptied
        // box is indistinguishable from a never-touched one, so the fallback fired
        // anyway and published the confidential (often anonymous) recommender's
        // reason to a permanent, un-undoable record. public_comment_cleared
        // carries that intent; a line nobody cleared inherits exactly as before.
        $note     = $row['PublicComment'] !== ''
            ? $row['PublicComment']
            : (empty($row['PublicCommentCleared']) ? $row['RecReason'] : '');

        // (3) Throw-safe player-record write. add_player_award returns the FLAT
        // shape: Status (int, 0=success), Error, Detail (+ our new AwardId).
        try {
            $r = Ork3::$Lib->player->AddAward([
                'Token'          => $ctx['Token'] ?? '',
                'RecipientId'    => $row['MundaneId'],
                'KingdomAwardId' => $row['KingdomAwardId'],
                'AwardId'        => 0,
                'Rank'           => $row['Rank'],
                'Date'           => $date,
                'GivenById'      => $row['GivenByMundaneId'],
                'CustomName'     => '',
                'Note'           => $note,
                'ParkId'         => $row['ParkId'],
                'KingdomId'      => $row['KingdomId'],
                'EventId'        => $event_id,
            ]);
        } catch (\Throwable $e) {
            // Revert the claim so finalize stays re-runnable; no orphaned 'given'.
            $err = 'Grant failed: ' . $e->getMessage();
            $this->revertAwardStatus($court_award_id, 'staged');
            $this->setLastFinalizeError($court_award_id, $err);
            return [
                'status'         => 'error',
                'court_award_id' => $court_award_id,
                'error'          => $err,
            ];
        }

        if ((int)($r['Status'] ?? 1) !== 0) {
            $err = ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '');
            $this->revertAwardStatus($court_award_id, 'staged');
            $this->setLastFinalizeError($court_award_id, $err);
            return [
                'status'         => 'error',
                'court_award_id' => $court_award_id,
                'error'          => $err,
            ];
        }

        // (4) Link the freshly-created ork_awards row via its RETURNED id.
        $award_id = (int)($r['AwardId'] ?? 0);
        // Freezes the citation that was actually published and clears any failure
        // recorded by an earlier attempt at this line, in the same statement.
        $this->setAwardId($court_award_id, $award_id, $note);
        $row['PublicComment'] = $note;

        return [
            'status'   => 'ok',
            'award_id' => $award_id,
            'row'      => $row,
        ];
    }

    /**
     * True when the permanent record already carries this honor for this player.
     *
     * Match: same recipient, same kingdomaward, the EXACT same rank, not revoked.
     *
     * EXACT EQUALITY, NOT `rank >=` — do not "tighten" this back. `>=` is the right
     * reading for SUGGESTING ungranted awards (getUngrantedFromLastCourt), where a
     * held rank 5 does cover rank 3. It is wrong as a duplicate guard, because
     * recording a LOWER rank after a higher one is a routine officer pattern, not a
     * duplicate: officers backfill ladder history all the time (measured on the
     * prod-derived DB: 11,370 ork_awards rows since 2022 on is_ladder awards are a
     * same-or-lower rank recorded after a higher one for the same player +
     * kingdomaward). With `>=` this guard would silently CANCEL such a line —
     * refusing to record an honor an officer announced at court, a worse failure
     * than the duplicate it exists to prevent. It also made the guard depend on
     * commit order: getStagedAwards orders by ca.sort_order, and real courts carry
     * a rank 4 line sorted ahead of a rank 3 line for the same player+award.
     *
     * Equality alone is still NOT a duplicate predicate, because it is time-blind:
     * the same honor at the same rank is legitimately re-earned years apart. On the
     * prod-derived DB, exact-rank repeats since 2022 on the awards this guard covers
     * break down as 1,123 same-day, 382 within a month, 1,044 within a year and
     * 1,584 MORE THAN A YEAR apart — e.g. Order of the Warrior rank 6 re-earned in
     * 2026 after 2004. Equality alone would refuse all of those.
     *
     * So the guard is scoped to the COURT DATE: a duplicate is the same honor, at
     * the same rank, on the same day — one ceremony recorded twice. The date passed
     * in is the exact value AddAward is about to write, so (a) a retried finalize
     * matches by construction, and (b) the same honor staged on two courts is caught
     * whenever they share a date. When the dates differ, the second grant is
     * indistinguishable from a legitimate re-earning, and cancelling it would be
     * refusing to record an honor an officer announced — so it is allowed through,
     * and the in-run $committedClusters guard in finalize_court still catches true
     * same-run siblings.
     *
     * Rank is normalized identically on both sides (absent/NULL and 0 compare
     * equal), so a rank-0 line is only blocked by another rank-0 row.
     *
     * Scope: awards not ordinarily held twice on one day. NOTE this is narrower than
     * "held once": repeatable tournament titles (Weaponmaster, Squire, Man-at-Arms,
     * Custom Title) carry is_title = 1 and ARE re-earned — which is precisely why
     * the date predicate above, not the flag test below, is what keeps them working.
     * A CUSTOM award (class.Report.php's
     * isCustom: base award is_ladder = 0 AND is_title = 0) is legitimately
     * repeatable and must never be blocked. The per-kingdom flags are read
     * alongside the base award's because a kingdom's own override is authoritative
     * for that kingdom; an award flagged either way is non-repeatable, and only a
     * row that is custom by BOTH readings is treated as repeatable.
     *
     * ka.is_ladder is now part of that test. It was previously left out because
     * the column existed in dev/prod but not in the ork_test schema, and a missing
     * column fails the whole probe open — so a kingdom that flagged a base CUSTOM
     * award as its own ladder award was not covered, and those honors could be
     * granted twice at the same rank (26 kingdomawards were in that state on the
     * dev DB). db-migrations/2026-09-09-court-planner-review-fixes.sql adds the
     * column to the test schema, so the code can read it honestly everywhere.
     */
    private function ledgerAlreadyHasHonor($mundane_id, $kingdomaward_id, $rank, $award_date)
    {
        $mundane_id      = (int)$mundane_id;
        $kingdomaward_id = (int)$kingdomaward_id;
        $rank            = (int)$rank;
        $award_date      = trim((string)$award_date);
        if ($mundane_id <= 0 || $kingdomaward_id <= 0 || !$this->validDate($award_date)) {
            return false;
        }

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT 1 AS dup
             FROM ' . DB_PREFIX . 'kingdomaward ka
             LEFT JOIN ' . DB_PREFIX . 'award a ON a.award_id = ka.award_id
             WHERE ka.kingdomaward_id = ' . $kingdomaward_id . '
               AND (' . self::SQL_EFFECTIVE_IS_LADDER . ' = 1
                    OR ' . self::SQL_EFFECTIVE_IS_TITLE . ' = 1)
               AND EXISTS (
                   SELECT 1 FROM ' . DB_PREFIX . 'awards oa
                    WHERE oa.mundane_id = ' . $mundane_id . '
                      AND oa.kingdomaward_id = ' . $kingdomaward_id . '
                      AND COALESCE(oa.rank, 0) = ' . $rank . '
                      AND oa.date = \'' . $this->esc($award_date) . '\'
                      AND (oa.revoked = 0 OR oa.revoked IS NULL)
               )
             LIMIT 1'
        );
        return (bool)($rs && $rs->Next());
    }

    /**
     * S1 server-side cross-path reconcile. When the Recs-Manager grantaward path
     * writes an ork_awards row directly, call this so any court line still OPEN
     * for that recommendation ('planned'/'announced'/'staged') is marked 'given'
     * and linked to that awards row in the SAME request — a later finalize then
     * sees it already committed and cannot re-grant. Client-side data-courts is no
     * longer trusted for correctness.
     *
     * Guarded UPDATE (only open rows). Matches on the exact recommendations_id
     * AND, as defense-in-depth, on the cluster key (mundane_id + kingdomaward_id
     * + rank) when the caller supplies it — so a court line created under a
     * sibling/older cluster-representative rec id, or an ad-hoc line for the same
     * person+award+rank, is still reconciled and cannot be re-granted at finalize.
     * This mirrors the cluster-wide resolve the finalize path already performs.
     *
     * $court_action carries the officer's choice from the Recs-Manager grant modal
     * and decides the terminal status of the reconciled lines:
     *   'leave'  -> 'given'     (the award is announced at that court)
     *   'remove' -> 'cancelled' (granted outside court; drop it from the running
     *                            order, but soft-cancel — never hard-DELETE, so the
     *                            audit trace of the planned line survives)
     * Either way the awards row and giver are linked for provenance, and the line
     * leaves the open set so finalize cannot re-grant it.
     *
     * SCOPE. recommendations_id is a global primary key and the cluster key matches
     * across every court in the database, so the match alone names rows this officer
     * may have no authority over — and rows on courts that are already history. Two
     * guards, both required:
     *   - `c.status <> 'complete'`. A finalized court's line is the public record of a
     *     ceremony that already happened. Finalize's "Leave As-Is and Close" path
     *     legitimately leaves 'planned' lines behind on a completed court, so without
     *     this a grant months later would flip one to 'given', stamp it with today's
     *     award id and giver, and publish an honor on the Court Report that was never
     *     read from the throne. Those reports are served without a login.
     *   - canManage($actor_uid, ...) re-derived per candidate row from ITS OWN court's
     *     kingdom/park. Nothing else in the grantaward request path authorizes against
     *     the court_award rows being mutated.
     * Candidates are therefore selected, filtered, and only then updated by explicit
     * id. An $actor_uid of 0 authorizes nothing and reconciles nothing.
     *
     * Returns the number of court lines reconciled.
     */
    public function reconcileGrantForRecommendation($recommendations_id, $awards_id, $given_by_mundane_id, $rank = null, $mundane_id = 0, $kingdomaward_id = 0, $court_action = 'leave', $actor_uid = 0)
    {
        $recommendations_id = (int)$recommendations_id;
        $mundane_id         = (int)$mundane_id;
        $kingdomaward_id    = (int)$kingdomaward_id;
        $rank               = ($rank === null) ? null : (int)$rank;

        // Build the match: exact rec id OR the cluster key. At least one must be usable.
        $matches = [];
        if ($recommendations_id > 0) {
            $matches[] = 'recommendations_id = ' . $recommendations_id;
        }
        if ($mundane_id > 0 && $kingdomaward_id > 0) {
            $clusterMatch = 'mundane_id = ' . $mundane_id . ' AND kingdomaward_id = ' . $kingdomaward_id;
            if ($rank !== null) {
                $clusterMatch .= ' AND rank = ' . $rank;
            }
            $matches[] = '(' . $clusterMatch . ')';
        }
        if (!$matches) {
            return 0;
        }

        $actor_uid = (int)$actor_uid;
        if ($actor_uid <= 0) {
            return 0;
        }

        // Candidates: open lines matching the honor, on a court that is not already
        // finalized history.
        $this->db->Clear();
        $cand = $this->db->DataSet(
            'SELECT ca.court_award_id, c.kingdom_id, c.park_id
               FROM ' . DB_PREFIX . 'court_award ca
               JOIN ' . DB_PREFIX . 'court c ON c.court_id = ca.court_id
              WHERE (' . implode(' OR ', $matches) . ')
                AND ca.status IN (\'planned\', \'announced\', \'staged\')
                AND c.status <> \'complete\''
        );
        $ids = [];
        if ($cand) {
            $authCache = [];
            while ($cand->Next()) {
                $kid = (int)$cand->kingdom_id;
                $pid = (int)$cand->park_id;
                $key = $kid . ':' . $pid;
                if (!array_key_exists($key, $authCache)) {
                    $authCache[$key] = $this->canManage($actor_uid, $kid, $pid);
                }
                if ($authCache[$key]) {
                    $ids[] = (int)$cand->court_award_id;
                }
            }
        }
        if (!$ids) {
            return 0;
        }

        $award_id = (int)$awards_id;
        $giver    = (int)$given_by_mundane_id;

        $terminal = ($court_action === 'remove') ? 'cancelled' : 'given';
        $sets = 'ca.status = \'' . $terminal . '\', ca.row_version = ca.row_version + 1';
        if ($award_id > 0) {
            $sets .= ', ca.award_id = ' . $award_id;
        }
        if ($giver > 0) {
            $sets .= ', ca.given_by_mundane_id = ' . $giver;
        }

        // The UPDATE re-asserts every condition the candidate SELECT relied on that
        // SQL can express — the open line status AND `c.status <> 'complete'` — by
        // joining the court again. Between the SELECT above and this write another
        // officer can finalize the court; without the re-check the write would flip a
        // line on a now-finalized court to 'given' and publish an honor on the
        // login-free Court Report for a ceremony where it was never announced. With
        // it, that row simply fails to match and is left alone. (Per-row canManage()
        // cannot be expressed in SQL; it is already baked into $ids, which name the
        // only rows this statement can touch.)
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'UPDATE ' . DB_PREFIX . 'court_award ca
               JOIN ' . DB_PREFIX . 'court c ON c.court_id = ca.court_id
                SET ' . $sets . '
              WHERE ca.court_award_id IN (' . implode(',', $ids) . ')
                AND ca.status IN (\'planned\', \'announced\', \'staged\')
                AND c.status <> \'complete\''
        );

        // Report what was actually written, never what was intended. A shortfall
        // against the candidate set means a court finalized (or a line changed)
        // underneath us and those lines were deliberately not rewritten; the caller
        // surfaces this count to the officer as the number of court lines reconciled.
        $affected = $rs ? (int)$rs->Size() : 0;
        return max(0, $affected);
    }

    /** Mark a court complete + finalized (audit: who/when). */
    public function setCourtFinalized($court_id, $uid)
    {
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'court SET
                 status = \'complete\',
                 finalized_at = NOW(),
                 finalized_by = ' . (int)$uid . '
              WHERE court_id = ' . (int)$court_id
        );
    }

    /** Set the run-vs-plan mode of a court. Rejects an invalid mode. */
    public function setCourtMode($court_id, $mode)
    {
        if (!in_array($mode, ['run', 'plan'], true)) {
            return false;
        }
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'court SET mode = \'' . $this->esc($mode) . '\'
              WHERE court_id = ' . (int)$court_id
        );
        return true;
    }

    /**
     * Resolve one officer role to a giver descriptor, or null if the seat is
     * vacant. Officer -> mundane persona, most-recent seat wins.
     */
    private function lookupOfficerGiver($kingdom_id, $park_id, $role, $role_label)
    {
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT o.mundane_id, m.persona
             FROM ' . DB_PREFIX . 'officer o
             LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = o.mundane_id
             WHERE o.kingdom_id = ' . (int)$kingdom_id . '
               AND o.park_id = ' . (int)$park_id . '
               AND o.role = \'' . $this->esc($role) . '\'
             ORDER BY o.officer_id DESC
             LIMIT 1'
        );
        if (!$r || !$r->Next() || (int)$r->mundane_id <= 0) {
            return null;
        }
        return [
            'mundane_id' => (int)$r->mundane_id,
            'persona'    => $r->persona ?? '',
            'role'       => $role_label,
        ];
    }

    /**
     * The officer who should record this court's grants (spec 0.7).
     *
     * Recording court is the Prime Minister's responsibility under Corpora. Park
     * courts prefer the park's own officers over kingdom officers: a kingdom PM
     * auto-assigned to a park's court is a cross-scope assignment nobody asked for.
     *
     * Returns 0 when nothing matches; the caller falls back to the publisher.
     */
    public function getDefaultRecorder($kingdom_id, $park_id = 0)
    {
        $kingdom_id = (int)$kingdom_id;
        $park_id    = (int)$park_id;

        $candidates = $park_id > 0
            ? [[$park_id, 'Prime Minister'], [$park_id, 'Monarch'], [$park_id, 'Regent'], [0, 'Prime Minister']]
            : [[0, 'Prime Minister']];

        foreach ($candidates as $c) {
            // lookupOfficerGiver returns ['mundane_id' => int, 'persona' => string,
            // 'role' => string] — lowercase keys. Reading 'MundaneId' here would
            // always miss and silently fall through to the publisher.
            $officer = $this->lookupOfficerGiver($kingdom_id, $c[0], $c[1], $c[1]);
            if (!empty($officer['mundane_id'])) {
                return (int)$officer['mundane_id'];
            }
        }

        return 0;
    }

    /**
     * Grant-modal giver options: the court-level Monarch as the default plus
     * ordered quick-pick pills (spec 6.1). Vacant seats are omitted.
     *   Kingdom court: default = Kingdom Monarch; pill = Kingdom Regent.
     *   Park court:    default = Park Monarch; pills = Park Regent,
     *                  Kingdom Monarch, Kingdom Regent.
     *
     * DATE AWARENESS. ork_officer is replaced in place and keeps no history, so
     * reading it live answers "who holds the throne today", never "who held it at
     * this court". The commonest reason a court goes unrecorded for weeks is a
     * reign change — so a Midreign court typed up after Coronation credited the
     * INCOMING monarch as the giver of every line, permanently, on the recipients'
     * permanent records and on the login-free public report. A snapshot of the
     * roster is therefore taken when the court is published and when its packet is
     * first printed (snapshotCourtGivers), and a court whose date has already
     * passed is served that snapshot instead of today's seats.
     *
     * Returns ['default', 'pills', 'snapshot' => bool, 'live_default'] —
     * 'live_default' is who holds the seat right now, so a caller can warn that
     * the court is being recorded under a Crown that is no longer seated.
     */
    public function getCourtGiverOptions($court_id)
    {
        $this->db->Clear();
        $cr = $this->db->DataSet(
            'SELECT kingdom_id, park_id, court_date, giver_snapshot FROM ' . DB_PREFIX . 'court
              WHERE court_id = ' . (int)$court_id . ' LIMIT 1'
        );
        if (!$cr || !$cr->Next()) {
            return ['default' => null, 'pills' => [], 'snapshot' => false, 'live_default' => null];
        }
        $kingdom_id = (int)$cr->kingdom_id;
        $park_id    = (int)$cr->park_id;
        $court_date = $this->validDate((string)($cr->court_date ?? ''));
        $snapshot   = $this->decodeGiverSnapshot($cr->giver_snapshot ?? '');

        $live = $this->resolveLiveGiverOptions($kingdom_id, $park_id);

        if ($snapshot !== null && $court_date !== null && $court_date < date('Y-m-d')) {
            $snapshot['snapshot']     = true;
            $snapshot['live_default'] = $live['default'];
            return $snapshot;
        }

        $live['snapshot']     = false;
        $live['live_default'] = $live['default'];
        return $live;
    }

    /**
     * Store the court's giver roster as it stands now, unless one is already
     * stored — the whole point is to capture the seats as they were, so the first
     * write wins. Called when a court is published and when its packet is printed.
     */
    public function snapshotCourtGivers($court_id)
    {
        $court_id = (int)$court_id;
        if (!valid_id($court_id)) {
            return false;
        }

        $this->db->Clear();
        $cr = $this->db->DataSet(
            'SELECT kingdom_id, park_id, giver_snapshot FROM ' . DB_PREFIX . 'court
              WHERE court_id = ' . $court_id . ' LIMIT 1'
        );
        if (!$cr || !$cr->Next()) {
            return false;
        }
        if ($this->decodeGiverSnapshot($cr->giver_snapshot ?? '') !== null) {
            return false;
        }

        $live = $this->resolveLiveGiverOptions((int)$cr->kingdom_id, (int)$cr->park_id);
        if (empty($live['default']) && empty($live['pills'])) {
            // Nothing seated to record; leave the column empty so a later publish
            // or print can still capture a real roster.
            return false;
        }

        $json = json_encode($live);
        if (!is_string($json)) {
            return false;
        }

        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'court
                SET giver_snapshot = \'' . $this->esc($json) . '\'
              WHERE court_id = ' . $court_id . '
                AND (giver_snapshot IS NULL OR giver_snapshot = \'\')'
        );
        return true;
    }

    /** Decode a stored giver snapshot, or null when there isn't a usable one. */
    private function decodeGiverSnapshot($raw)
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_key_exists('default', $decoded)) {
            return null;
        }
        return [
            'default' => is_array($decoded['default']) ? $decoded['default'] : null,
            'pills'   => isset($decoded['pills']) && is_array($decoded['pills']) ? $decoded['pills'] : [],
        ];
    }

    /** The giver roster as the officer table stands right now. */
    private function resolveLiveGiverOptions($kingdom_id, $park_id)
    {
        $kingdom_id = (int)$kingdom_id;
        $park_id    = (int)$park_id;

        $pills = [];
        if ($park_id > 0) {
            $default = $this->lookupOfficerGiver($kingdom_id, $park_id, 'Monarch', 'Park Monarch');
            $candidates = [
                [$kingdom_id, $park_id, 'Regent',  'Park Regent'],
                [$kingdom_id, 0,        'Monarch', 'Kingdom Monarch'],
                [$kingdom_id, 0,        'Regent',  'Kingdom Regent'],
            ];
        } else {
            $default = $this->lookupOfficerGiver($kingdom_id, 0, 'Monarch', 'Kingdom Monarch');
            $candidates = [
                [$kingdom_id, 0, 'Regent', 'Kingdom Regent'],
            ];
        }
        foreach ($candidates as $c) {
            $cand = $this->lookupOfficerGiver($c[0], $c[1], $c[2], $c[3]);
            if ($cand) {
                $pills[] = $cand;
            }
        }
        return ['default' => $default, 'pills' => $pills];
    }

    /**
     * Rows from the most recent completed court at this level that are still
     * awardable: not already 'given' and the recipient does not already hold that
     * award/rank (already-has check mirrors Report's awards-table EXISTS). Feeds
     * the "prepopulate skipped-from-last-court" banner (spec 6.5).
     */
    public function getUngrantedFromLastCourt($kingdom_id, $park_id)
    {
        $kingdom_id = (int)$kingdom_id;
        $park_id    = (int)$park_id;

        $this->db->Clear();
        $cr = $this->db->DataSet(
            'SELECT court_id FROM ' . DB_PREFIX . 'court
              WHERE kingdom_id = ' . $kingdom_id . '
                AND park_id = ' . $park_id . '
                AND status = \'complete\'
              ORDER BY court_date DESC, court_id DESC
              LIMIT 1'
        );
        if (!$cr || !$cr->Next()) {
            return [];
        }
        $last_court_id = (int)$cr->court_id;

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT ca.court_award_id, ca.mundane_id, ca.kingdomaward_id, ca.rank,
                    ca.recommendations_id, ca.public_comment, ca.pass_to_local, ca.notes,
                    m.persona, IFNULL(ka.name, a.name) AS award_name
             FROM ' . DB_PREFIX . 'court_award ca
             LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = ca.mundane_id
             LEFT JOIN ' . DB_PREFIX . 'kingdomaward ka ON ka.kingdomaward_id = ca.kingdomaward_id
             LEFT JOIN ' . DB_PREFIX . 'award a ON a.award_id = ka.award_id
             WHERE ca.court_id = ' . $last_court_id . '
               AND ca.status != \'given\'
               AND NOT EXISTS (
                   SELECT 1 FROM ' . DB_PREFIX . 'awards oa
                    WHERE oa.mundane_id = ca.mundane_id
                      AND oa.kingdomaward_id = ca.kingdomaward_id
                      AND oa.rank >= ca.rank
                      AND (oa.revoked = 0 OR oa.revoked IS NULL)
               )
             ORDER BY ca.sort_order, ca.court_award_id'
        );
        $rows = [];
        if ($rs) {
            while ($rs->Next()) {
                $rows[] = [
                    'CourtAwardId'      => (int)$rs->court_award_id,
                    'MundaneId'         => (int)$rs->mundane_id,
                    'Persona'           => $rs->persona ?? '',
                    'KingdomAwardId'    => (int)$rs->kingdomaward_id,
                    'AwardName'         => $rs->award_name ?? '',
                    'Rank'              => (int)$rs->rank,
                    'RecommendationsId' => $rs->recommendations_id ? (int)$rs->recommendations_id : 0,
                    'PublicComment'     => $rs->public_comment ?? '',
                    'PassToLocal'       => (bool)(int)$rs->pass_to_local,
                    'Notes'             => $rs->notes ?? '',
                ];
            }
        }
        return $rows;
    }

    /**
     * Cheap heartbeat state for the live multi-manager poll (spec 6.4).
     * court_award has no updated_at column, so `version` is an md5 of the row set
     * (court_award_id:status:sort_order:given_by:modified) plus court mode/status,
     * which changes on any edit, reorder, giver change, add, or remove.
     */
    public function getCourtState($court_id)
    {
        $court_id = (int)$court_id;

        $this->db->Clear();
        $cr = $this->db->DataSet(
            'SELECT mode, status FROM ' . DB_PREFIX . 'court
              WHERE court_id = ' . $court_id . ' LIMIT 1'
        );
        $mode         = 'run';
        $court_status = '';
        if ($cr && $cr->Next()) {
            $mode         = $cr->mode ?? 'run';
            $court_status = $cr->status ?? '';
        }

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT court_award_id, status, sort_order, given_by_mundane_id, row_version, modified
             FROM ' . DB_PREFIX . 'court_award
             WHERE court_id = ' . $court_id . '
             ORDER BY court_award_id'
        );
        $awards     = [];
        $stampParts = [];
        if ($rs) {
            while ($rs->Next()) {
                $caid     = (int)$rs->court_award_id;
                $givenBy  = $rs->given_by_mundane_id ? (int)$rs->given_by_mundane_id : 0;
                $sortOrd  = (int)$rs->sort_order;
                $rowVer   = (int)$rs->row_version;
                $awards[] = [
                    'court_award_id'      => $caid,
                    'status'              => $rs->status,
                    'sort_order'          => $sortOrd,
                    'given_by_mundane_id' => $givenBy,
                    'row_version'         => $rowVer,
                ];
                // row_version is the authoritative optimistic-lock token; fold it
                // into the heartbeat stamp so any mutating write flips `version`.
                $stampParts[] = $caid . ':' . $rs->status . ':' . $sortOrd . ':'
                    . $givenBy . ':' . $rowVer . ':' . ($rs->modified ?? '');
            }
        }
        // Notes share the running order, so a peer's note add/edit/move/remove has to
        // flip `version` too or the heartbeat would never re-send the payload.
        $this->db->Clear();
        $ns = $this->db->DataSet(
            'SELECT court_note_id, sort_order, row_version
             FROM ' . DB_PREFIX . 'court_note
             WHERE court_id = ' . $court_id . '
             ORDER BY court_note_id'
        );
        if ($ns) {
            while ($ns->Next()) {
                $stampParts[] = 'n' . (int)$ns->court_note_id . ':' . (int)$ns->sort_order . ':' . (int)$ns->row_version;
            }
        }
        $version = md5($court_status . '|' . $mode . '|' . implode(',', $stampParts));

        return [
            'version'      => $version,
            'mode'         => $mode,
            'court_status' => $court_status,
            'awards'       => $awards,
        ];
    }

    /** Count of staged-but-not-finalized rows (unfinalized-staged safeguard). */
    public function countStagedAwards($court_id)
    {
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . 'court_award
              WHERE court_id = ' . (int)$court_id . ' AND status = \'staged\''
        );
        return ($r && $r->Next()) ? (int)$r->c : 0;
    }

    /**
     * Plan-mode bulk stage: flip every 'planned' row on a court to 'staged',
     * filling the default giver only where none was captured yet (leaves any
     * existing public_comment/rank untouched). Returns the number staged.
     */
    public function bulkStagePlanned($court_id, $default_giver_mundane_id)
    {
        $court_id = (int)$court_id;
        $giver    = (int)$default_giver_mundane_id;
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'UPDATE ' . DB_PREFIX . 'court_award SET
                 status = \'staged\',
                 given_by_mundane_id = CASE
                     WHEN given_by_mundane_id IS NULL OR given_by_mundane_id = 0
                     THEN ' . $giver . ' ELSE given_by_mundane_id END,
                 row_version = row_version + 1
              WHERE court_id = ' . $court_id . ' AND status = \'planned\''
        );
        return $rs ? (int)$rs->Size() : 0;
    }

    /**
     * Skip-remaining helper for the complete-court flow (spec 6.6): mark every
     * still-unresolved row ('planned'/'announced') on a court 'cancelled'. Leaves
     * 'staged'/'given'/'cancelled' rows untouched. Returns the number cancelled.
     */
    public function cancelUnresolved($court_id)
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'UPDATE ' . DB_PREFIX . 'court_award
                SET status = \'cancelled\', row_version = row_version + 1
              WHERE court_id = ' . (int)$court_id . '
                AND status IN (\'planned\', \'announced\')'
        );
        return $rs ? (int)$rs->Size() : 0;
    }

    /**
     * Every (mundane_id, kingdomaward_id, rank) this court already carries, as a
     * set of "m:ka:rank" keys.
     *
     * The bulk form of courtHasAward, for callers that are about to test a whole
     * batch: prepopulate-from-last-court ran one probe per candidate row, on top
     * of the ~7 queries each addAward costs, so one click on a decent-sized court
     * was several hundred round trips.
     */
    public function getCourtAwardKeys($court_id)
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT mundane_id, kingdomaward_id, rank FROM ' . DB_PREFIX . 'court_award
              WHERE court_id = ' . (int)$court_id
        );
        $keys = [];
        if ($rs) {
            while ($rs->Next()) {
                $keys[(int)$rs->mundane_id . ':' . (int)$rs->kingdomaward_id . ':' . (int)$rs->rank] = true;
            }
        }
        return $keys;
    }

    /**
     * Dedupe probe for the prepopulate-from-last-court flow (spec 6.5): does this
     * court already carry a row for the same recipient/award/rank?
     */
    public function courtHasAward($court_id, $mundane_id, $kingdomaward_id, $rank)
    {
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . 'court_award
              WHERE court_id = ' . (int)$court_id . '
                AND mundane_id = ' . (int)$mundane_id . '
                AND kingdomaward_id = ' . (int)$kingdomaward_id . '
                AND rank = ' . (int)$rank . '
              LIMIT 1'
        );
        return $r && $r->Next();
    }

    // -----------------------------------------------------------------------
    // Court awards
    // -----------------------------------------------------------------------

    public function getCourtAwards($court_id)
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT ca.court_award_id, ca.mundane_id, ca.kingdomaward_id, ca.rank,
                    ca.recommendations_id, ca.sort_order, ca.pass_to_local,
                    ca.notes, ca.public_comment, ca.public_comment_cleared, ca.status, ca.scroll_status, ca.regalia_status,
                    ca.scroll_maker_id, ca.regalia_maker_id, ca.row_version,
                    ca.given_by_mundane_id, gb.persona AS given_by_persona,
                    sm.persona AS scroll_maker_persona, rm.persona AS regalia_maker_persona,
                    m.persona, p.abbreviation AS park_abbrev,
                    IFNULL(ka.name, a.name) AS award_name, a.peerage,
                    ' . self::SQL_EFFECTIVE_IS_LADDER . ' AS is_ladder,
                    ' . self::SQL_EFFECTIVE_IS_TITLE . ' AS is_title,
                    rec.reason AS rec_reason, rec.mask_giver,
                    rb.persona AS rec_by_persona
             FROM ' . DB_PREFIX . 'court_award ca
             LEFT JOIN ' . DB_PREFIX . 'mundane m      ON m.mundane_id         = ca.mundane_id
             LEFT JOIN ' . DB_PREFIX . 'park p          ON p.park_id            = m.park_id
             LEFT JOIN ' . DB_PREFIX . 'kingdomaward ka ON ka.kingdomaward_id   = ca.kingdomaward_id
             LEFT JOIN ' . DB_PREFIX . 'award a         ON a.award_id           = ka.award_id
             LEFT JOIN ' . DB_PREFIX . 'mundane sm      ON sm.mundane_id        = ca.scroll_maker_id
             LEFT JOIN ' . DB_PREFIX . 'mundane rm      ON rm.mundane_id        = ca.regalia_maker_id
             LEFT JOIN ' . DB_PREFIX . 'mundane gb      ON gb.mundane_id        = ca.given_by_mundane_id
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
                    'Peerage'           => $rs->peerage ?? '',
                    'Rank'              => (int)$rs->rank,
                    'RecommendationsId' => $rs->recommendations_id ? (int)$rs->recommendations_id : null,
                    'IsWalkOn'          => $rs->recommendations_id === null,
                    'SortOrder'         => (int)$rs->sort_order,
                    'RowVersion'        => (int)$rs->row_version,
                    'GivenByMundaneId'  => $rs->given_by_mundane_id ? (int)$rs->given_by_mundane_id : 0,
                    'GivenByPersona'    => $rs->given_by_persona ?? '',
                    'PassToLocal'       => (bool)(int)$rs->pass_to_local,
                    'Notes'             => $rs->notes ?? '',
                    'PublicComment'     => $rs->public_comment ?? '',
                    // Must round-trip: the client renders data-rec-cleared from this and
                    // the heartbeat reconcile refreshes it. Without it a peer's save posts
                    // PublicCommentCleared=0 and silently wipes another reeve's clear,
                    // which republishes the confidential recommendation reason at Finalize.
                    'PublicCommentCleared' => (bool)(int)($rs->public_comment_cleared ?? 0),
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

    public function getPendingRecommendations($kingdom_id, $park_id = 0, $caller_uid = 0, $court_id = 0)
    {
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
        if (empty($rawRecs)) {
            return [];
        }

        // Park abbreviation lookup — recommended_awards has ParkName but not abbrev.
        $parkIds = array_unique(array_filter(array_map(fn ($r) => (int)($r['ParkId'] ?? 0), $rawRecs)));
        $parkAbbrev = [];
        if (!empty($parkIds)) {
            $idCsv = implode(',', $parkIds);
            $this->db->Clear();
            $pr = $this->db->DataSet('SELECT park_id, abbreviation FROM ' . DB_PREFIX . 'park WHERE park_id IN (' . $idCsv . ')');
            if ($pr) {
                while ($pr->Next()) {
                    $parkAbbrev[(int)$pr->park_id] = (string)$pr->abbreviation;
                }
            }
        }

        // Per-rec court-plan mapping: which court is each rec on (if any)?
        $recIds = array_map(fn ($r) => (int)$r['RecommendationsId'], $rawRecs);
        $onCourt = [];  // recommendations_id => [court_id => true]
        if (!empty($recIds)) {
            $idCsv = implode(',', $recIds);
            $this->db->Clear();
            $cr = $this->db->DataSet(
                'SELECT recommendations_id, court_id FROM ' . DB_PREFIX . 'court_award
                 WHERE recommendations_id IN (' . $idCsv . ') AND status != \'cancelled\''
            );
            if ($cr) {
                while ($cr->Next()) {
                    $onCourt[(int)$cr->recommendations_id][(int)$cr->court_id] = true;
                }
            }
        }

        $curCourt = (int)$court_id;
        $out = [];
        // One row per HONOR, not per recommendation. Several people recommending the
        // same person for the same award is routine — the production data has clusters
        // of fifteen — and emitting one row each showed the officer a wall of visually
        // identical entries, every one of which added its own court line. Collapse them
        // on (mundane_id, kingdomaward_id, rank), the same key the grant and reconcile
        // paths treat as the honor, and carry a support count instead.
        $clusterIndex = [];
        foreach ($rawRecs as $r) {
            $rid = (int)$r['RecommendationsId'];
            $plans = $onCourt[$rid] ?? [];
            $isOnThis  = $curCourt > 0 && !empty($plans[$curCourt]);
            $isOnOther = !empty(array_diff_key($plans, [$curCourt => true]));
            // Skip recs already on THIS court — they can't be added again, and the user
            // can already see them in the main Order-of-Court list.
            if ($isOnThis) {
                continue;
            }
            $clusterKey = (int)$r['MundaneId'] . ':' . (int)$r['KingdomAwardId'] . ':' . (int)$r['Rank'];
            if (isset($clusterIndex[$clusterKey])) {
                // Fold the sibling into the row already emitted: keep the OLDEST
                // recommendation as the representative (it is the one whose reason and
                // date the officer is reading), and let any sibling's on-another-court
                // flag light the badge.
                $i = $clusterIndex[$clusterKey];
                $out[$i]['SupportCount']++;
                $out[$i]['SiblingRecIds'][] = $rid;
                if ($isOnOther) {
                    $out[$i]['IsOnOtherCourt'] = true;
                }
                if (($r['DateRecommended'] ?? '') !== ''
                    && ($out[$i]['DateRecommended'] === '' || $r['DateRecommended'] < $out[$i]['DateRecommended'])) {
                    $out[$i]['RecommendationsId'] = $rid;
                    $out[$i]['Reason']            = $r['Reason'];
                    $out[$i]['DateRecommended']   = $r['DateRecommended'];
                    $out[$i]['AgeDays']           = (int)($r['AgeDays'] ?? 0);
                    $out[$i]['IsAnonymous']       = !empty($r['IsAnonymous']);
                    $out[$i]['RecommendedByName'] = $r['RecommendedByName'] ?? null;
                }
                continue;
            }
            $clusterIndex[$clusterKey] = count($out);
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
                // How many people recommended this honor (1 = just the representative).
                'SupportCount'      => 1,
                'SiblingRecIds'     => [],
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
     *
     * SCOPE. Identical to getCourtList() for the same arguments, and it has to be:
     * a badge naming a court that is missing from the filter dropdown makes the
     * Court='court:N' filter unreachable for that row (and the CSV export inherits
     * the mismatch). A kingdom request therefore also defaults to the kingdom's own
     * courts; pass $include_park_courts = true — on BOTH calls — to see, and filter
     * by, recommendations already scheduled on a subordinate park's court.
     */
    public function getRecommendationCourtMap($kingdom_id, $park_id = 0, $include_park_courts = false)
    {
        if (!valid_id($kingdom_id)) {
            return [];
        }
        $scope = 'c.kingdom_id = ' . (int)$kingdom_id;
        if ($park_id > 0) {
            $scope .= ' AND c.park_id = ' . (int)$park_id;
        } elseif (!$include_park_courts) {
            $scope .= ' AND c.park_id = 0';
        }

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

    public function getKingdomAwardOptions($kingdom_id)
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT ka.kingdomaward_id, IFNULL(ka.name, a.name) AS award_name,
                    ' . self::SQL_EFFECTIVE_IS_LADDER . ' AS is_ladder,
                    ' . self::SQL_EFFECTIVE_IS_TITLE . ' AS is_title,
                    a.peerage
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
                    'IsTitle'        => (bool)((int)$rs->is_title === 1 || !in_array((string)$rs->peerage, ['', 'None'], true)),
                ];
            }
        }
        return $options;
    }

    // -----------------------------------------------------------------------
    // Upcoming events (for linking a court to an event)
    // -----------------------------------------------------------------------

    public function getUpcomingEvents($kingdom_id)
    {
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

    public function updateAwardTrackingStatus($courtAwardId, $type)
    {
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
     *
     * checkdate() is part of the contract, not decoration. Under this codebase's
     * non-strict sql_mode a malformed court date is stored as '0000-00-00', which
     * is shaped like a date and is TRUTHY in PHP — so it survived
     * commitStagedAward's `?: date('Y-m-d')` fallback, became the awarded date on
     * a player's permanent record and the key the ledger duplicate guard matches
     * on, and printed as "December 31, 1969" on the login-free Court Report. A
     * string that merely matches the pattern is not a date.
     */
    private function validDate($d)
    {
        if (!is_string($d) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
            return null;
        }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $d : null;
    }

    /**
     * Resolve the heading/scope for a court report request.
     * Park scope ($park_id > 0): the park's name, plus its owning kingdom when the
     * caller did not already supply one. Kingdom scope: the kingdom's name.
     * Returns ['Name' => string, 'KingdomId' => int]; Name is '' and KingdomId is
     * echoed back unchanged when the row does not exist.
     */
    public function getCourtReportScope($kingdom_id, $park_id)
    {
        $kingdom_id = (int)$kingdom_id;
        $park_id    = (int)$park_id;
        $name       = '';

        if ($park_id > 0) {
            $this->db->Clear();
            $r = $this->db->DataSet(
                'SELECT name, kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = ' . $park_id . ' LIMIT 1'
            );
            if ($r && $r->Next()) {
                $name = $r->name;
                if (!$kingdom_id) {
                    $kingdom_id = (int)$r->kingdom_id;
                }
            }
        } else {
            $this->db->Clear();
            $r = $this->db->DataSet(
                'SELECT name FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ' . $kingdom_id . ' LIMIT 1'
            );
            if ($r && $r->Next()) {
                $name = $r->name;
            }
        }

        return ['Name' => $name, 'KingdomId' => $kingdom_id];
    }

    /**
     * COMPLETE courts in [$from_date, $until_date] (inclusive on court_date) that
     * have at least one award with status='given'.
     *   Kingdom report ($kingdom_id set, $park_id = 0): courts in that kingdom.
     *   Park report ($park_id set): courts owned by the park OR any court holding a
     *     given award whose recipient's home park is $park_id.
     *
     * c.status = 'complete' is required (ork_court.status enum is
     * draft|published|complete). This report is served without a login, and a line
     * on a DRAFT or PUBLISHED court can already read 'given' — reconcileGrantForRecommendation
     * flips one whenever an officer grants that recommendation from the Recs
     * Manager with "Grant & Leave on Court". Without this filter, a draft court
     * planned for a future date published its honors — and its date — publicly
     * before the ceremony was held.
     *
     * A court line whose linked ork_awards row has been REVOKED is excluded, using
     * the same predicate ledgerAlreadyHasHonor applies. Revoking is the routine
     * correction for an honor granted in error; without this the login-free report
     * kept presenting it as a real honor indefinitely, contradicting the
     * recipient's own profile.
     */
    public function getCourtReportList($kingdom_id, $park_id, $from_date, $until_date)
    {
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
                    ON ca.court_id = c.court_id AND ca.status = \'given\'
                   AND NOT EXISTS (
                       SELECT 1 FROM ' . DB_PREFIX . 'awards oa
                        WHERE oa.awards_id = ca.award_id AND oa.revoked = 1
                   )' . $scopeJoin . '
             LEFT JOIN ' . DB_PREFIX . 'event_calendardetail cd
                    ON cd.event_calendardetail_id = c.event_calendardetail_id
             LEFT JOIN ' . DB_PREFIX . 'event e ON e.event_id = cd.event_id
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = c.park_id
             WHERE c.court_date BETWEEN \'' . $from . '\' AND \'' . $until . '\'
               AND c.status = \'complete\'
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
     * artisans batch-loaded. Returns null if the court does not exist OR has not
     * been finalized (c.status != 'complete') — the ceremony has not happened, so
     * there is nothing to publish; callers (controller.Reports::court) redirect on
     * null. Same reasoning as getCourtReportList, including the revoked-award
     * exclusion.
     *
     * Each row also carries the GIVER captured at stage time
     * (given_by_mundane_id + persona). A court record exists to attest who
     * received, what was conferred, and who conferred it; the scroll and regalia
     * makers were credited here while the giver — the one thing a herald checks
     * years later — was not even selected.
     */
    public function getCourtReportDetail($court_id)
    {
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
             WHERE c.court_id = ' . (int)$court_id . '
               AND c.status = \'complete\' LIMIT 1'
        );
        if (!$hr || !$hr->Next()) {
            return null;
        }

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
                    ca.given_by_mundane_id,
                    m.persona, p.abbreviation AS park_abbrev,
                    IFNULL(ka.name, a.name) AS award_name,
                    ' . self::SQL_EFFECTIVE_IS_LADDER . ' AS is_ladder,
                    gb.persona AS given_by_persona,
                    sm.persona AS scroll_maker_persona, rm.persona AS regalia_maker_persona
             FROM ' . DB_PREFIX . 'court_award ca
             LEFT JOIN ' . DB_PREFIX . 'mundane m  ON m.mundane_id       = ca.mundane_id
             LEFT JOIN ' . DB_PREFIX . 'park p     ON p.park_id          = m.park_id
             LEFT JOIN ' . DB_PREFIX . 'kingdomaward ka ON ka.kingdomaward_id = ca.kingdomaward_id
             LEFT JOIN ' . DB_PREFIX . 'award a    ON a.award_id         = ka.award_id
             LEFT JOIN ' . DB_PREFIX . 'mundane gb ON gb.mundane_id      = ca.given_by_mundane_id
             LEFT JOIN ' . DB_PREFIX . 'mundane sm ON sm.mundane_id      = ca.scroll_maker_id
             LEFT JOIN ' . DB_PREFIX . 'mundane rm ON rm.mundane_id      = ca.regalia_maker_id
             WHERE ca.court_id = ' . (int)$court_id . ' AND ca.status = \'given\'
               AND NOT EXISTS (
                   SELECT 1 FROM ' . DB_PREFIX . 'awards oa
                    WHERE oa.awards_id = ca.award_id AND oa.revoked = 1
               )
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
                    'GivenByMundaneId'    => $rs->given_by_mundane_id ? (int)$rs->given_by_mundane_id : 0,
                    'GivenByPersona'      => $rs->given_by_persona ?? '',
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

    /**
     * Published courts in this scope with nothing recorded yet — the silence
     * failure mode of the print-and-catch-up workflow (spec 0.5).
     *
     * Includes undated courts: an undated court is the exact case that stamps
     * every award with the catch-up day, so it must never be invisible here.
     */
    public function getUnrecordedCourts($kingdom_id, $park_id = 0)
    {
        $kingdom_id = (int)$kingdom_id;
        $park_id    = (int)$park_id;
        if (!valid_id($kingdom_id)) {
            return [];
        }

        // Mirror getCourtList's scoping exactly. Filtering kingdom context on
        // kingdom_id alone leaked every park's courts onto the kingdom list —
        // a banner about courts not on the page, and a notification fired at
        // every park recorder in the kingdom whenever one kingdom officer
        // opened the planner.
        $scope = $park_id > 0
            ? 'c.park_id = ' . $park_id
            : 'c.kingdom_id = ' . $kingdom_id . ' AND c.park_id = 0';

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT c.court_id, c.name, c.court_date, c.park_id, c.recorder_mundane_id,
                    CASE WHEN c.court_date IS NULL OR c.court_date = \'0000-00-00\'
                         THEN 0 ELSE DATEDIFF(CURDATE(), c.court_date) END AS days_since
               FROM ' . DB_PREFIX . 'court c
              WHERE c.status = \'published\'
                AND ' . $scope . '
                AND (c.court_date IS NULL OR c.court_date = \'0000-00-00\' OR c.court_date < CURDATE())
                AND NOT EXISTS (
                    SELECT 1 FROM ' . DB_PREFIX . 'court_award ca
                     WHERE ca.court_id = c.court_id
                       AND ca.status IN (\'staged\', \'given\')
                )
              ORDER BY c.court_date IS NULL DESC, c.court_date ASC'
        );

        $out = [];
        if ($rs) {
            while ($rs->Next()) {
                $out[] = [
                    'CourtId'   => (int)$rs->court_id,
                    'Name'      => $rs->name,
                    'CourtDate' => $rs->court_date,
                    'ParkId'    => (int)$rs->park_id,
                    'DaysSince' => (int)$rs->days_since,
                    'RecorderMundaneId' => (int)$rs->recorder_mundane_id,
                ];
            }
        }

        return $out;
    }

    /**
     * Courts in this scope that were STARTED and then stalled: grants marked live
     * but never finalized.
     *
     * getUnrecordedCourts covers only the all-or-nothing case (its NOT EXISTS
     * disqualifies a court the moment one row is staged), which misses the
     * likeliest real failure — twelve of twenty awards marked Given and Complete
     * Court never pressed. Those rows sit 'staged' forever: they never reach
     * ork_awards and never appear on the public report, and nothing tells anyone.
     *
     * Deliberately a SEPARATE method rather than a broadening of
     * getUnrecordedCourts, whose contract ("nothing recorded at all") is asserted
     * by CourtThread0Test and is what the court-list banner is worded for.
     *
     * Draft courts count here: a court can be run off a draft agenda, and its
     * staged rows are just as invisible. A grace period keeps the sweep off a
     * court that is being recorded right now.
     */
    public function getStalledCourts($kingdom_id, $park_id = 0, $grace_days = 3)
    {
        $kingdom_id = (int)$kingdom_id;
        $park_id    = (int)$park_id;
        if (!valid_id($kingdom_id)) {
            return [];
        }

        // Mirror getCourtList's / getUnrecordedCourts' scoping exactly.
        $scope = $park_id > 0
            ? 'c.park_id = ' . $park_id
            : 'c.kingdom_id = ' . $kingdom_id . ' AND c.park_id = 0';

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT c.court_id, c.name, c.court_date, c.park_id, c.status,
                    c.recorder_mundane_id,
                    (SELECT COUNT(*) FROM ' . DB_PREFIX . 'court_award sca
                      WHERE sca.court_id = c.court_id AND sca.status = \'staged\') AS staged_count,
                    CASE WHEN c.court_date IS NULL OR c.court_date = \'0000-00-00\'
                         THEN 0 ELSE DATEDIFF(CURDATE(), c.court_date) END AS days_since
               FROM ' . DB_PREFIX . 'court c
              WHERE c.status IN (\'published\', \'draft\')
                AND ' . $scope . '
                AND (c.court_date IS NULL OR c.court_date = \'0000-00-00\'
                     OR c.court_date <= DATE_SUB(CURDATE(), INTERVAL ' . (int)$grace_days . ' DAY))
                AND EXISTS (
                    SELECT 1 FROM ' . DB_PREFIX . 'court_award ca
                     WHERE ca.court_id = c.court_id
                       AND ca.status = \'staged\'
                )
              ORDER BY c.court_date IS NULL DESC, c.court_date ASC'
        );

        $out = [];
        if ($rs) {
            while ($rs->Next()) {
                $out[] = [
                    'CourtId'           => (int)$rs->court_id,
                    'Name'              => $rs->name,
                    'CourtDate'         => $rs->court_date,
                    'ParkId'            => (int)$rs->park_id,
                    'Status'            => $rs->status,
                    'StagedCount'       => (int)$rs->staged_count,
                    'DaysSince'         => (int)$rs->days_since,
                    'RecorderMundaneId' => (int)$rs->recorder_mundane_id,
                ];
            }
        }

        return $out;
    }

    /**
     * Officers seated in this scope who can manage its courts — the fallback
     * audience when a court has no recorder. Mirrors canManage's officer branch;
     * kingdom/park EDITORS are not enumerable from here (that lives in the
     * authorization ORM), and the seats below are the ones Corpora makes
     * responsible for recording anyway.
     */
    private function getScopeManagerIds($kingdom_id, $park_id = 0)
    {
        $kingdom_id = (int)$kingdom_id;
        $park_id    = (int)$park_id;
        $scope      = $park_id > 0
            ? '(o.park_id = ' . $park_id . ' OR o.park_id = 0)'
            : 'o.park_id = 0';

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT DISTINCT o.mundane_id
               FROM ' . DB_PREFIX . 'officer o
              WHERE o.kingdom_id = ' . $kingdom_id . '
                AND ' . $scope . '
                AND o.role IN (\'Monarch\', \'Regent\', \'Prime Minister\')
                AND o.mundane_id > 0'
        );

        $ids = [];
        if ($rs) {
            while ($rs->Next()) {
                $ids[] = (int)$rs->mundane_id;
            }
        }
        return $ids;
    }

    /**
     * One notification per court needing attention, addressed to its recorder.
     * Non-blocking: a notification failure never affects court state.
     *
     * At most one notification per court per recipient per day — a reload of the
     * court list must not spam the recorder with a duplicate every time it loads.
     *
     * Two distinct situations, worded differently because the officer's next
     * action differs: a court with NOTHING recorded needs the whole packet typed
     * up, while a court with staged grants needs one button pressed to commit
     * honors the recipients do not yet hold.
     *
     * $unrecorded lets the caller hand over a list it has already fetched;
     * controller.Court::list needs it for the page banner and used to pay for the
     * identical correlated-subquery scan twice per request.
     *
     * A court with no recorder resolved is no longer skipped in silence — a
     * kingdom with a vacant Prime Minister seat was told nothing at all. It falls
     * back to every officer who can manage the scope.
     */
    public function notifyUnrecordedCourts($kingdom_id, $park_id = 0, $unrecorded = null)
    {
        $courts = is_array($unrecorded) ? $unrecorded : $this->getUnrecordedCourts($kingdom_id, $park_id);
        $sent   = 0;
        $fallback = null;

        foreach ($courts as $c) {
            $sent += $this->notifyCourtNeedsRecording(
                $kingdom_id,
                $park_id,
                $c,
                'Court "' . $c['Name'] . '" has not been recorded yet.',
                $fallback
            );
        }

        // Started-but-never-finalized courts: the awards are marked but still are
        // not on anyone's record. Disjoint from the list above by construction —
        // getUnrecordedCourts requires that no row is staged.
        foreach ($this->getStalledCourts($kingdom_id, $park_id) as $c) {
            $n = (int)$c['StagedCount'];
            $sent += $this->notifyCourtNeedsRecording(
                $kingdom_id,
                $park_id,
                $c,
                'Court "' . $c['Name'] . '" has ' . $n . ' grant' . ($n === 1 ? '' : 's')
                    . ' marked but never finalized — they are not on the recipients\' records yet.',
                $fallback
            );
        }

        return $sent;
    }

    /**
     * Send one court-needs-recording notification, to the court's recorder or —
     * when the seat is empty — to every officer who can manage the scope.
     * $fallback caches the fallback roster across a sweep. Returns the number sent.
     */
    private function notifyCourtNeedsRecording($kingdom_id, $park_id, array $court, $message, &$fallback)
    {
        $recorder = (int)($court['RecorderMundaneId'] ?? 0);
        if ($recorder > 0) {
            $recipients = [$recorder];
        } else {
            if ($fallback === null) {
                $fallback = $this->getScopeManagerIds($kingdom_id, $park_id);
            }
            $recipients = $fallback;
        }
        if (!$recipients) {
            return 0;
        }

        // This app has no clean URLs, and the notification bell emits the
        // stored link verbatim into href — a bare 'Court/detail/2' resolves
        // to /orkui/Court/detail/2 and 404s. Store the routed absolute URL.
        // Guarded exactly as class.Notification.php:126 does, for the
        // non-web contexts that never define UIR.
        $link = (defined('UIR') ? UIR : '') . 'Court/detail/' . (int)$court['CourtId'];
        $sent = 0;

        foreach ($recipients as $uid) {
            $uid = (int)$uid;
            if ($uid <= 0) {
                continue;
            }

            $this->db->Clear();
            $existing = $this->db->DataSet(
                'SELECT 1 FROM ' . DB_PREFIX . 'notification
                  WHERE mundane_id = ' . $uid . '
                    AND type = \'court_awaiting_record\'
                    AND link = \'' . $this->esc($link) . '\'
                    AND DATE(created_at) = CURDATE()
                  LIMIT 1'
            );
            if ($existing && $existing->Next()) {
                continue;
            }

            try {
                Ork3::$Lib->notification->Add($uid, 'court_awaiting_record', $message, $link);
                $sent++;
            } catch (\Throwable $e) {
                // Non-blocking by design.
            }
        }

        return $sent;
    }

}
