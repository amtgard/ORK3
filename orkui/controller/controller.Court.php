<?php

class Controller_Court extends Controller
{
    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);
        $this->load_model('Court');
    }

    // -----------------------------------------------------------------------
    // Court list — standalone page
    // Route: ?Route=Court/list/kingdom/{kingdom_id}
    //        ?Route=Court/list/park/{park_id}
    // -----------------------------------------------------------------------
    public function list($context = null, $id = null)
    {
        // The front controller collapses trailing route segments into one string
        // when the route has more than 3 parts, so `Court/list/kingdom/17` arrives
        // as $context = 'kingdom/17' with $id = null. Split it back apart.
        if (($id === null || $id === '') && is_string($context) && strpos($context, '/') !== false) {
            $parts   = explode('/', $context);
            $context = $parts[0];
            $id      = $parts[1] ?? null;
        }

        $id = (int)preg_replace('/[^0-9]/', '', $id ?? '');
        $context = ($context === 'park') ? 'park' : 'kingdom';

        $uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

        // Resolve kingdom_id / park_id
        $kingdom_id = 0;
        $park_id    = 0;

        if ($context === 'park') {
            $park_id = $id;
            $kingdom_id = (int)$this->Court->get_park_kingdom_id($park_id);
        } else {
            $kingdom_id = $id;
        }

        if (!valid_id($kingdom_id)) {
            $this->data['Error'] = 'Invalid location.';
            return;
        }

        $canManage = $this->Court->can_manage($uid, $kingdom_id, $park_id);

        if (!$canManage) {
            $this->data['Error'] = 'You do not have permission to view the Court Planner.';
            return;
        }

        $courtList     = $this->Court->get_court_list($kingdom_id, $park_id);
        $upcomingEvents = $this->Court->get_upcoming_events($kingdom_id);
        $unrecordedCourts = $this->Court->get_unrecorded_courts($kingdom_id, $park_id);
        // Started-but-never-finalized courts — grants marked live and Complete
        // Court never pressed, so nothing reached anyone's record. Separate from
        // the list above, which is the "nothing recorded at all" case.
        $stalledCourts = $this->Court->get_stalled_courts($kingdom_id, $park_id);
        // Notify each court's recorder (spec 0.7). Guarded to at most once per
        // court per recipient per day inside notify_unrecorded_courts, so a reload
        // of this list does not spam a duplicate notification on every visit.
        // The already-fetched list is handed over: the sweep used to re-run the
        // identical correlated-subquery scan on every page load.
        $this->Court->notify_unrecorded_courts($kingdom_id, $park_id, $unrecordedCourts);

        // Location name
        $locationName = '';
        if ($park_id > 0) {
            $pInfo = $this->Court->get_park_short_info($park_id);
            if (isset($pInfo['ParkInfo']['ParkName'])) {
                $locationName = $pInfo['ParkInfo']['ParkName'];
            }
        } else {
            $kInfo = $this->Court->get_kingdom_short_info($kingdom_id);
            if (isset($kInfo['KingdomInfo']['KingdomName'])) {
                $locationName = $kInfo['KingdomInfo']['KingdomName'];
            }
        }

        $this->data['CourtList']      = $courtList;
        $this->data['UpcomingEvents'] = $upcomingEvents;
        $this->data['UnrecordedCourts'] = $unrecordedCourts;
        $this->data['StalledCourts']    = $stalledCourts;
        $this->data['KingdomId']      = $kingdom_id;
        $this->data['ParkId']         = $park_id;
        $this->data['Context']        = $context;
        $this->data['LocationName']   = $locationName;
        $this->data['CanManage']      = $canManage;
        $this->data['Uid']            = $uid;
    }

    // -----------------------------------------------------------------------
    // Court detail — standalone planning page
    // Route: ?Route=Court/detail/{court_id}
    // -----------------------------------------------------------------------
    public function detail($court_id = null)
    {
        $court_id = (int)preg_replace('/[^0-9]/', '', $court_id ?? '');
        $uid      = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

        if (!valid_id($court_id)) {
            $this->data['Error'] = 'Invalid court.';
            return;
        }

        $court = $this->Court->get_court_detail($court_id);
        if (!$court) {
            $this->data['Error'] = 'Court not found.';
            return;
        }

        $canManage = $this->Court->can_manage($uid, $court['KingdomId'], $court['ParkId']);
        if (!$canManage) {
            $this->data['Error'] = 'You do not have permission to manage this court.';
            return;
        }

        $courtAwards  = $this->Court->get_court_awards($court_id);
        $pendingRecs  = $this->Court->get_pending_recommendations($court['KingdomId'], $court['ParkId'], $uid, $court_id);
        // Event options for the Edit Details modal's re-link select (spec 0.1) — mirrors
        // list()'s identical call; scoped to the COURT's kingdom, not the session's.
        $upcomingEvents = $this->Court->get_upcoming_events($court['KingdomId']);
        // Keep the court's OWN currently-linked event selectable even if it fell outside
        // the "upcoming" window (e.g. a past event) — otherwise re-opening the editor on
        // an already-linked court would silently preselect "— None —".
        if (
            $court['EventCalendarDetailId'] > 0
            && !in_array($court['EventCalendarDetailId'], array_column($upcomingEvents, 'EventCalendarDetailId'), true)
        ) {
            $upcomingEvents[] = [
                'EventCalendarDetailId' => $court['EventCalendarDetailId'],
                'Name'                  => $court['EventName'] ?: ('Event #' . $court['EventCalendarDetailId']),
                'EventStart'            => null,
            ];
        }
        // Grouped ad-hoc award/title picker options (mirrors the player Add Award
        // modal grouping). Scoped to the COURT's kingdom, not the session's.
        $this->load_model('Award');
        $awardOptions = $this->Award->fetch_award_option_groups($court['KingdomId'], 'Awards');

        // ---- Stage/finalize planner data (spec §6) ----
        // Grant-modal giver roster (default monarch + quick-pick pills).
        $giverOptions = $this->Court->get_court_giver_options($court_id);
        // Cheap heartbeat state doubles as the source of the stored run/plan `mode`
        // (getCourtDetail does not expose it) and the initial heartbeat version stamp.
        $courtState   = $this->Court->get_court_state($court_id);
        // Unfinalized-staged safeguard indicator (spec §5.3).
        $stagedCount  = $this->Court->count_staged_awards($court_id);
        // Prev-court "prepopulate skipped" banner source (spec §6.5) — empty if none.
        $prevSkipped  = $this->Court->get_ungranted_from_last_court($court['KingdomId'], $court['ParkId']);
        // NOTE: rec_reason is already carried per-row by getCourtAwards() (`RecReason`),
        // so the grant modal can fall back to it without a separate lookup.

        // Status labels and next-status transitions
        $statusFlow = [
            'draft'     => 'published',
            'published' => 'complete',
            'complete'  => null,
        ];

        // Resolve heraldry: prefer park heraldry if park-scoped, else kingdom.
        // Use the Heraldry lib so the ?v=filemtime cache-buster is preserved.
        $heraldryUrl = '';
        $hasHeraldry = false;
        if ($court['ParkId'] > 0) {
            $pInfo = $this->Court->get_park_short_info((int)$court['ParkId']);
            if (!empty($pInfo['ParkInfo']['HasHeraldry'])) {
                $hasHeraldry = true;
                $h = $this->Court->get_heraldry_url('Park', (int)$court['ParkId']);
                $heraldryUrl = $h['Url'] ?? '';
            }
        }
        if (!$hasHeraldry && $court['KingdomId'] > 0) {
            $kInfo = $this->Court->get_kingdom_short_info((int)$court['KingdomId']);
            if (!empty($kInfo['KingdomInfo']['HasHeraldry'])) {
                $hasHeraldry = true;
                $h = $this->Court->get_heraldry_url('Kingdom', (int)$court['KingdomId']);
                $heraldryUrl = $h['Url'] ?? '';
            }
        }

        $this->data['Court']        = $court;
        $this->data['CourtAwards']  = $courtAwards;
        $this->data['PendingRecs']  = $pendingRecs;
        $this->data['AwardOptions'] = $awardOptions;
        // Kingdom-original ladders are keyed "k<KingdomAwardId>" in the held-rank
        // map (see Player::GetAwardMaxRanks). Membership of this list is the only
        // reliable test -- most of them carry AwardId 94 ("Custom Award"), not 0.
        $this->data['PseudoLadderKingdomAwardIds'] = Award::pseudoLadderKingdomAwardIds();
        $this->data['StatusFlow']   = $statusFlow;
        $this->data['CanManage']    = $canManage;
        $this->data['Uid']          = $uid;
        $this->data['HeraldryUrl']  = $heraldryUrl;
        $this->data['HasHeraldry']  = $hasHeraldry;
        $this->data['GiverOptions'] = $giverOptions;
        $this->data['CourtMode']    = $courtState['mode'] ?? 'run';
        $this->data['StateVersion'] = $courtState['version'] ?? '';
        $this->data['StagedCount']  = $stagedCount;
        $this->data['PrevSkipped']  = $prevSkipped;
        $this->data['UpcomingEvents'] = $upcomingEvents;

        $this->template = 'Court_detail.tpl';
    }

    // -----------------------------------------------------------------------
    // Record Court — the catch-up pass (spec §5)
    // Route: ?Route=Court/record/{court_id}
    // -----------------------------------------------------------------------
    public function record($court_id = null)
    {
        $court_id = (int)preg_replace('/[^0-9]/', '', $court_id ?? '');
        $uid      = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

        if (!valid_id($court_id)) {
            $this->data['Error'] = 'Invalid court.';
            return;
        }

        $court = $this->Court->get_court_detail($court_id);
        if (!$court) {
            $this->data['Error'] = 'Court not found.';
            return;
        }

        if (!$this->Court->can_manage($uid, $court['KingdomId'], $court['ParkId'])) {
            $this->data['Error'] = 'You do not have permission to record this court.';
            return;
        }

        // Event options for the top strip's re-link select — mirrors detail()'s
        // identical call; scoped to the COURT's kingdom, not the session's.
        $upcomingEvents = $this->Court->get_upcoming_events($court['KingdomId']);
        // Keep the court's OWN currently-linked event selectable even if it fell outside
        // the "upcoming" window (e.g. a past event) — otherwise re-opening the strip on
        // an already-linked court would silently preselect "— None —" and a save would
        // then unlink it. Copied verbatim from detail().
        if (
            $court['EventCalendarDetailId'] > 0
            && !in_array($court['EventCalendarDetailId'], array_column($upcomingEvents, 'EventCalendarDetailId'), true)
        ) {
            $upcomingEvents[] = [
                'EventCalendarDetailId' => $court['EventCalendarDetailId'],
                'Name'                  => $court['EventName'] ?: ('Event #' . $court['EventCalendarDetailId']),
                'EventStart'            => null,
            ];
        }

        // Cheap heartbeat state doubles as the source of the stored run/plan `mode`
        // (getCourtDetail does not expose it) — needed for the hero's mode badge.
        $courtState = $this->Court->get_court_state($court_id);

        // Grouped ad-hoc award/title picker options for the walk-on row (Task 10) —
        // identical call to detail()'s, scoped to the COURT's kingdom. This is the
        // SAME source Court_detail.tpl's Add Award/Add Title modals use, so the
        // walk-on row's single combined field can never drift from them.
        $this->load_model('Award');
        $awardOptions = $this->Award->fetch_award_option_groups($court['KingdomId'], 'Awards');

        $this->data['Court']                  = $court;
        $this->data['CourtAwards']            = $this->Court->get_court_awards($court_id);
        $this->data['GiverOptions']           = $this->Court->get_court_giver_options($court_id);
        $this->data['UpcomingEvents']         = $upcomingEvents;
        $this->data['CourtMode']              = $courtState['mode'] ?? 'run';
        $this->data['AwardOptions']           = $awardOptions;
        $this->data['Uid']                    = $uid;
        $this->data['CourtChangedSincePrint'] = $this->Court->court_changed_since_print($court_id);
        $this->template                       = 'Court_record.tpl';
    }
}
