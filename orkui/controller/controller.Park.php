<?php

class Controller_Park extends Controller
{
	public function __construct( $call = null, $id = null )
	{
		parent::__construct( $call, $id );
		$id = preg_replace('/[^0-9]/', '', $id);

		if ( $id != $this->session->park_id ) {
			unset( $this->session->kingdom_id );
			unset( $this->session->kingdom_name );
			unset( $this->session->park_name );
			unset( $this->session->park_id );
		}

		$this->session->park_id = $id;

		if ( !isset( $this->session->kingdom_id ) ) {
			// Direct link
			$park_info = $this->Park->get_park_info( $id );
			$this->session->park_name = $park_info[ 'ParkInfo' ][ 'ParkName' ];
			$this->session->kingdom_id = $park_info[ 'KingdomInfo' ][ 'KingdomId' ];
			$this->session->kingdom_name = $park_info[ 'KingdomInfo' ][ 'KingdomName' ];
		}
		$this->data[ 'kingdom_id' ] = $this->session->kingdom_id;
		$this->data[ 'park_id' ] = $this->session->park_id;
		$this->data[ 'kingdom_name' ] = $this->session->kingdom_name;

		if ( isset( $this->request->park_name ) ) {
			$this->session->park_name = $this->request->park_name;
		}
		$this->data[ 'park_name' ] = $this->session->park_name;
		$this->data[ 'page_title' ] = $this->session->park_name;

		$_uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
		if ($_uid > 0 && Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_PARK, (int)$id, AUTH_EDIT)) {
			$this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin/park/' . $this->session->park_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' ];
			$this->data[ 'menulist' ][ 'admin' ] = [
				[ 'url' => UIR . 'Admin/park/' . $this->session->park_id, 'display' => 'Park' ],
				[ 'url' => UIR . 'Admin/kingdom/' . $this->session->kingdom_id, 'display' => 'Kingdom' ],
			];
		}
		$this->data[ 'menu' ][ 'kingdom' ] = [ 'url' => UIR . 'Kingdom/profile/' . $this->session->kingdom_id, 'display' => $this->session->kingdom_name ];
		$this->data[ 'menu' ][ 'park' ] = [ 'url' => UIR . 'Park/profile/' . $this->session->park_id, 'display' => $this->session->park_name ];
	}

	public function index( $park_id = null )
	{
		$park_id = preg_replace('/[^0-9]/', '', $park_id);
		$this->load_model( 'Reports' );
		$this->data[ 'event_summary' ] = $this->Park->get_park_events( $park_id );
		$this->data[ 'park_days' ] = $this->Park->get_park_parkdays( $park_id );
		$this->data[ 'park_info' ] = $this->Park->get_park_details( $park_id );
		$this->data[ 'park_officers' ] = $this->Park->GetOfficers(['ParkId' => $park_id, 'Token' => $this->session->token]);
		// [TOURNAMENTS HIDDEN] $this->data['park_tournaments'] = [];
	}

	public function profile( $park_id = null )
	{
		$this->template = '../revised-frontend/Parknew_index.tpl';
		$park_id = preg_replace('/[^0-9]/', '', $park_id);
		$this->load_model('Award');
		$this->load_model('Attendance');
		$this->load_model('Reports');
		$this->load_model('Pronoun');

		$this->data['kingdom_name'] = $this->session->kingdom_name;
		$this->data['menu']['kingdom'] = [
			'url'     => UIR . 'Kingdom/profile/' . $this->session->kingdom_id,
			'display' => $this->session->kingdom_name,
		];
		$this->data['menu']['park'] = [
			'url'     => UIR . 'Park/profile/' . $this->session->park_id,
			'display' => $this->session->park_name,
		];

		$this->data['park_days']        = $this->Park->get_park_parkdays( $park_id );
		$this->data['park_info']        = $this->Park->get_park_details( $park_id );
		$this->data['park_officers']    = $this->Park->GetOfficers(['ParkId' => $park_id, 'Token' => $this->session->token]);
		$this->data['park_tournaments'] = $this->Reports->get_tournaments( null, null, $park_id );

		// Digital Waivers: expose active park-scope template for the sign CTA,
		// and add a review-queue link to the admin dropdown for park editors.
		$this->load_model('Waiver');
		$_wvKingdomId = (int)($this->data['park_info']['ParkInfo']['KingdomId'] ?? $this->session->kingdom_id);
		$this->data['park_info']['WaiverActive'] = $this->Waiver->GetActiveTemplate([
			'KingdomId' => $_wvKingdomId,
			'Scope'     => 'park',
		]);
		$_wvUid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
		if ($_wvUid > 0 && Ork3::$Lib->authorization->HasAuthority($_wvUid, AUTH_PARK, (int)$park_id, AUTH_EDIT)) {
			if (!isset($this->data['menulist']['admin']) || !is_array($this->data['menulist']['admin'])) {
				$this->data['menulist']['admin'] = [];
			}
			$this->data['menulist']['admin'][] = [
				'url'     => UIR . 'Waiver/queue/park/' . (int)$park_id,
				'display' => 'Waiver Review Queue',
			];
		}

		$this->data['AwardOptions']   = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Awards');
		$this->data['OfficerOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Officers');
		$preloadOfficers = [];
		foreach ($this->data['park_officers']['Officers'] ?? [] as $o) {
			if (in_array($o['OfficerRole'], ['Monarch', 'Regent']) && (int)$o['MundaneId'] > 0)
				$preloadOfficers[] = ['MundaneId' => $o['MundaneId'], 'Persona' => $o['Persona'], 'Role' => $o['OfficerRole']];
		}
		$this->load_model('Kingdom');
		$kingdomOfficers = $this->Kingdom->get_officers($this->session->kingdom_id, $this->session->token);
		if (is_array($kingdomOfficers)) {
			foreach ($kingdomOfficers as $o) {
				if (in_array($o['OfficerRole'], ['Monarch', 'Regent']) && (int)$o['MundaneId'] > 0)
					$preloadOfficers[] = ['MundaneId' => $o['MundaneId'], 'Persona' => $o['Persona'], 'Role' => 'Kingdom ' . $o['OfficerRole']];
			}
		}
		$this->data['PreloadOfficers'] = $preloadOfficers;

		$classesResult = $this->Attendance->get_classes();
		$this->data['Classes'] = array_map(function($c) {
			return ['ClassId' => $c['ClassId'], 'ClassName' => $c['Name']];
		}, $classesResult['Classes'] ?? []);

		$recentResult = $this->Attendance->get_recent_attendees($park_id);
		$this->data['RecentAttendees'] = $recentResult['Attendees'] ?? [];

		global $DB;
		$pid = (int)$park_id;

		$evtSql = "
			SELECT e.event_id, e.name, p.name AS park_name,
			       cd.event_start, cd.event_end, cd.event_calendardetail_id AS next_detail_id, e.has_heraldry,
			       (SELECT COUNT(*) FROM ork_event_rsvp WHERE event_calendardetail_id = cd.event_calendardetail_id AND status = 'going') AS rsvp_going,
		       (SELECT COUNT(*) FROM ork_event_rsvp WHERE event_calendardetail_id = cd.event_calendardetail_id AND status = 'interested') AS rsvp_interested
			FROM ork_event e
			LEFT JOIN ork_park p ON p.park_id = e.park_id
			JOIN ork_event_calendardetail cd ON cd.event_id = e.event_id
			    AND cd.event_start >= DATE_SUB(NOW(), INTERVAL 7 DAY)
			    AND cd.event_start <= DATE_ADD(NOW(), INTERVAL 12 MONTH)
			WHERE e.park_id = {$pid}
			ORDER BY cd.event_start, e.name";
		$DB->Clear();
	$evtResult    = $DB->DataSet($evtSql);
		$eventSummary = [];
		if ($evtResult) {
			do {
				$eid = (int)($evtResult->event_id ?? 0);
				if ($eid) {
					$eventSummary[] = [
						'EventId'      => $eid,
						'Name'         => $evtResult->name,
						'ParkName'     => $evtResult->park_name,
						'NextDate'     => $evtResult->event_start,
						'NextEndDate'  => $evtResult->event_end,
						'NextDetailId' => (int)$evtResult->next_detail_id,
						'HasHeraldry'  => (int)$evtResult->has_heraldry,
						'RsvpGoing'      => (int)$evtResult->rsvp_going,
					'RsvpInterested' => (int)$evtResult->rsvp_interested,
					];
				}
			} while ($evtResult->Next());
		}
		$this->data['event_summary'] = $eventSummary;

		$pkRosterCacheKey = Ork3::$Lib->ghettocache->key(['ParkId' => $pid]);
		$parkPlayers = Ork3::$Lib->ghettocache->get(__CLASS__ . '.park_players', $pkRosterCacheKey, 1200);
		if ($parkPlayers === false) {
			$parkPlayers = [];
			$rosterSql = "
			SELECT
				m.mundane_id,
				m.persona,
				m.has_image,
				m.has_heraldry,
				sub.last_signin,
				COUNT(DISTINCT a6.date) AS signin_count,
				c.name AS last_class,
				GROUP_CONCAT(DISTINCT o.role ORDER BY o.role SEPARATOR ', ') AS officer_roles
			FROM ork_mundane m
			INNER JOIN (
				SELECT mundane_id, MAX(date) AS last_signin
				FROM ork_attendance
				WHERE park_id = {$pid}
				GROUP BY mundane_id
			) sub ON sub.mundane_id = m.mundane_id
			LEFT JOIN ork_attendance a6 ON a6.mundane_id = m.mundane_id
				AND a6.park_id = {$pid}
				AND a6.date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
			LEFT JOIN ork_attendance la ON la.mundane_id = m.mundane_id
				AND la.park_id = {$pid}
				AND la.date = sub.last_signin
			LEFT JOIN ork_class c ON la.class_id = c.class_id
			LEFT JOIN ork_officer o ON o.mundane_id = m.mundane_id AND o.park_id = {$pid}
			WHERE m.park_id = {$pid}
			  AND m.suspended = 0
			  AND m.active = 1
			GROUP BY m.mundane_id
			ORDER BY m.persona";
			$DB->Clear();
			$rosterResult = $DB->DataSet($rosterSql);
			if ($rosterResult && $rosterResult->Size() > 0) {
				do {
					$parkPlayers[] = [
						'MundaneId'    => (int)$rosterResult->mundane_id,
						'Persona'      => $rosterResult->persona,
						'HasImage'     => (int)$rosterResult->has_image > 0,
						'HasHeraldry'  => (int)$rosterResult->has_heraldry > 0,
						'SigninCount'  => (int)$rosterResult->signin_count,
						'LastSignin'   => $rosterResult->last_signin,
						'LastClass'    => $rosterResult->last_class,
						'OfficerRoles' => $rosterResult->officer_roles,
					];
				} while ($rosterResult->Next());
			}
			Ork3::$Lib->ghettocache->cache(__CLASS__ . '.park_players', $pkRosterCacheKey, $parkPlayers);
		}
		$this->data['park_players'] = $parkPlayers;

		// Monthly average: average of distinct players per month over past year
		$monthlyAvgSql = "
			SELECT AVG(monthly_unique) AS avg_per_month FROM (
				SELECT COUNT(DISTINCT a.mundane_id) AS monthly_unique
				FROM ork_attendance a
				WHERE a.park_id = {$pid}
				  AND a.date > DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
				  AND a.mundane_id > 0
				GROUP BY a.date_year, a.date_month
			) sub";
		$DB->Clear();
		$maResult = $DB->DataSet($monthlyAvgSql);
		$this->data['MonthlyAvg'] = 0;
		if ($maResult && $maResult->Next()) {
			$_avg = (float)$maResult->avg_per_month;
			if ($_avg > 0) $this->data['MonthlyAvg'] = round($_avg, 1);
		}

		// Weekly average: same formula as Top Parks report — deduplicated player-weeks / week_count.
		// Uses the same 6-month window and >= boundary so the number matches the ranking report.
		$wkStart = date('Y-m-d', strtotime('-6 month'));
		$wkEnd   = date('Y-m-d');
		$wkCount = max(1, (int)ceil((strtotime($wkEnd) - strtotime($wkStart)) / (7 * 86400)));
		$escapedWkStart = mysql_real_escape_string($wkStart);
		$escapedWkEnd   = mysql_real_escape_string($wkEnd);
		$weeklyAvgSql = "
			SELECT COUNT(*) AS player_weeks FROM (
				SELECT a.mundane_id
				FROM ork_attendance a
				WHERE a.park_id = {$pid}
				  AND a.date >= '{$escapedWkStart}'
				  AND a.date <= '{$escapedWkEnd}'
				  AND a.mundane_id > 0
				GROUP BY a.date_year, a.date_week3, a.mundane_id
			) sub";
		$DB->Clear();
		$waResult = $DB->DataSet($weeklyAvgSql);
		$this->data['WeeklyAvg'] = 0;
		if ($waResult && $waResult->Next()) {
			$_wk = (int)$waResult->player_weeks;
			if ($_wk > 0) $this->data['WeeklyAvg'] = round($_wk / $wkCount, 2);
		}

		$uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
		$this->data['IsLoggedIn']    = $uid > 0;
		$this->data['CurrentUserId'] = $uid;
		$this->data['IsOwnPark']     = $uid > 0 && (int)($this->session->park_id ?? 0) === (int)$park_id;
		$this->data['CanManagePark'] = $uid > 0
			&& Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, (int)$park_id, AUTH_EDIT);
		$this->data['CanAdminPark']  = $uid > 0
			&& Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, (int)$park_id, AUTH_CREATE);

		$knConfigs  = Common::get_configs($this->session->kingdom_id, CFG_KINGDOM);
		$recsPublic = isset($knConfigs['AwardRecsPublic'])
			? (bool)(int)$knConfigs['AwardRecsPublic']['Value']
			: true;
		$this->data['AwardRecsPublic'] = $recsPublic;

		$this->data['AwardRecommendations'] = [];
		$canManagePark = $this->data['CanManagePark'] ?? false;
		if ($recsPublic || $canManagePark) {
			$this->data['ShowRecsTab'] = true;
			$recs = $this->Reports->recommended_awards(['KingdomId' => 0, 'ParkId' => $park_id, 'PlayerId' => 0]);
			$this->data['AwardRecommendations'] = is_array($recs) ? $recs : [];
		} elseif ($uid > 0) {
			$recs = $this->Reports->recommended_awards(['KingdomId' => 0, 'ParkId' => $park_id, 'PlayerId' => 0]);
			$allRecs = is_array($recs) ? $recs : [];
			$myRecs = array_values(array_filter($allRecs, function($r) use ($uid) {
				return (int)$r['RecommendedById'] === $uid;
			}));
			$this->data['AwardRecommendations'] = $myRecs;
			$this->data['ShowRecsTab'] = !empty($myRecs);
		} else {
			$this->data['ShowRecsTab'] = false;
		}

		$this->data['PronounList']          = $this->Pronoun->fetch_pronoun_list();
		$this->data['PronounOptionsCreate'] = $this->Pronoun->fetch_pronoun_option_list(null);
	}
}
