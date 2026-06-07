<?php

class Controller_Kingdom extends Controller {

	public function __construct($call=null, $id=null) {
		parent::__construct($call, $id);
		$id = preg_replace('/[^0-9]/', '', $id);

		if ($id != $this->session->kingdom_id) {
			unset($this->session->kingdom_id);
			unset($this->session->kingdom_name);
			unset($this->session->park_name);
			unset($this->session->park_id);
		}

		$this->data['kingdom_id'] = $id;
		$this->session->kingdom_id = $id;

		if (!isset($this->session->kingdom_name)) {
			$this->session->kingdom_name = $this->Kingdom->get_kingdom_name($id);
		}
		$this->data['kingdom_name'] = $this->session->kingdom_name;
		$this->data[ 'page_title' ] = $this->session->kingdom_name;

		unset($this->session->park_id);
		unset($this->session->park_name);
		$_uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
		if ($_uid > 0 && Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_KINGDOM, (int)$id, AUTH_EDIT)) {
			$this->data['menu']['admin'] = array( 'url' => UIR.'Admin/kingdom/'.$this->session->kingdom_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
			$this->data['menulist']['admin'] = array(
					array( 'url' => UIR.'Admin/kingdom/'.$this->session->kingdom_id, 'display' => 'Kingdom' )
				);
		}
		$this->data['menu']['kingdom'] = array( 'url' => UIR.'Kingdom/profile/'.$this->session->kingdom_id, 'display' => $this->session->kingdom_name );
		unset($this->data['menu']['park']);
	}

	public function index($kingdom_id = null) {
		$kingdom_id = preg_replace('/[^0-9]/', '', $kingdom_id);
		$this->load_model('Reports');
		$this->data['park_summary'] = $this->Kingdom->get_park_summary($kingdom_id);
		$this->data['principalities'] = $this->Kingdom->get_principalities($kingdom_id);
		$this->data['event_summary'] = $this->Kingdom->get_kingdom_events($kingdom_id);
		$this->data['kingdom_info'] = $this->Kingdom->get_kingdom_shortinfo($kingdom_id);
		$this->data['kingdom_officers'] = $this->Kingdom->GetOfficers(['KingdomId' => $kingdom_id, 'Token' => $this->session->token]);
		$this->data['IsPrinz'] = $this->data['kingdom_info']['Info']['KingdomInfo']['IsPrincipality'];
		// [TOURNAMENTS HIDDEN] $this->data['kingdom_tournaments'] = [];
	}

	public function park_monthly_json($kingdom_id = null) {
		session_write_close(); // release session lock so navigation is not blocked
		$kingdom_id = preg_replace('/[^0-9]/', '', $kingdom_id);
		$summary = $this->Report->GetKingdomParkMonthlyAverages(['KingdomId' => $kingdom_id]);
		$result = array();
		foreach ((array)($summary['KingdomParkMonthlySummary'] ?? []) as $park) {
			$result[$park['ParkId']] = $park['MonthlyCount'];
		}
		header('Content-Type: application/json');
		echo json_encode($result);
		exit();
	}

	public function park_averages_json($kingdom_id = null) {
		session_write_close(); // release session lock so navigation is not blocked
		$kingdom_id = preg_replace('/[^0-9]/', '', $kingdom_id);
		$kid     = (int)$kingdom_id;
		$uid     = (int)($this->session->user_id ?? 0);
		$isAdmin = $uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kid, AUTH_EDIT);
		$cacheKey = Ork3::$Lib->ghettocache->key(['KingdomId' => $kid, 'IsAdmin' => (int)$isAdmin]);
		if (($cached = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $cacheKey, 1200)) !== false) {
			header('Content-Type: application/json');
			echo json_encode($cached);
			exit();
		}
		$wkStart  = date('Y-m-d', strtotime('-6 month'));
		$wkEnd    = date('Y-m-d');
		$wkCount  = max(1, (int)ceil((strtotime($wkEnd) - strtotime($wkStart)) / (7 * 86400)));
		$statsKids = implode(',', array_map('intval', $this->Kingdom->GetStatsKingdomIds($kid)));
		$weekly  = $this->Report->GetKingdomParkAverages(['KingdomId' => $kingdom_id, 'AverageMonths' => 6]);
		$monthly = $this->Report->GetKingdomParkMonthlyAverages(['KingdomId' => $kingdom_id]);
		$result  = array();
		foreach ((array)($weekly['KingdomParkAveragesSummary'] ?? []) as $park) {
			$result[$park['ParkId']] = ['att' => (int)$park['AttendanceCount'], 'mo' => 0, 'tp' => 0, 'tm' => 0];
		}
		foreach ((array)($monthly['KingdomParkMonthlySummary'] ?? []) as $park) {
			if (isset($result[$park['ParkId']])) {
				$result[$park['ParkId']]['mo'] = (float)$park['MonthlyAvg'];
			} else {
				$result[$park['ParkId']] = ['att' => 0, 'mo' => (float)$park['MonthlyAvg'], 'tp' => 0, 'tm' => 0];
			}
		}
		global $DB;
		$pcSql = "SELECT a.park_id,
				COUNT(DISTINCT a.mundane_id) AS total_players,
				COUNT(DISTINCT CASE WHEN m.park_id = a.park_id THEN a.mundane_id END) AS total_members
			FROM ork_attendance a
			INNER JOIN ork_park p  ON p.park_id  = a.park_id  AND p.kingdom_id IN ({$statsKids})
			INNER JOIN ork_mundane m ON m.mundane_id = a.mundane_id AND m.suspended = 0 AND m.active = 1
			WHERE a.date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND a.mundane_id > 0
			GROUP BY a.park_id";
		$DB->Clear();
		$pcResult = $DB->DataSet($pcSql);
		if ($pcResult && $pcResult->Size() > 0) {
			while ($pcResult->Next()) {
				$pid = (int)$pcResult->park_id;
				if (isset($result[$pid])) {
					$result[$pid]['tp'] = (int)$pcResult->total_players;
					$result[$pid]['tm'] = (int)$pcResult->total_members;
				} else {
					$result[$pid] = ['att' => 0, 'mo' => 0, 'tp' => (int)$pcResult->total_players, 'tm' => (int)$pcResult->total_members];
				}
			}
		}
		// Kingdom-level unique-player-week total: deduplicated by (year, week, player)
		// across the whole kingdom — avoids double-counting players who attend multiple parks in one week
		$knSql = "SELECT COUNT(*) AS katt FROM (
				SELECT a.mundane_id FROM " . DB_PREFIX . "attendance a
				INNER JOIN " . DB_PREFIX . "park p ON p.park_id = a.park_id AND p.kingdom_id IN ({$statsKids})
				WHERE a.date >= '{$wkStart}'
					AND a.mundane_id > 0
				GROUP BY a.date_year, a.date_week3, a.mundane_id
			) t";
		$DB->Clear();
		$knResult = $DB->DataSet($knSql);
		$katt = 0;
		if ($knResult && $knResult->Size() > 0 && $knResult->Next()) {
			$katt = (int)$knResult->katt;
		}
		// Kingdom-level AVG(distinct players per month): deduplicated across parks —
		// a player attending two parks in the same month counts once per month.
		$knMoSql = "SELECT AVG(monthly_unique) AS kmo FROM (
				SELECT a.date_year, a.date_month, COUNT(DISTINCT a.mundane_id) AS monthly_unique
				FROM " . DB_PREFIX . "attendance a
				INNER JOIN " . DB_PREFIX . "park p ON p.park_id = a.park_id AND p.kingdom_id IN ({$statsKids})
				WHERE a.date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
					AND a.mundane_id > 0
				GROUP BY a.date_year, a.date_month
			) sub";
		$DB->Clear();
		$knMoResult = $DB->DataSet($knMoSql);
		$kmo = 0;
		if ($knMoResult && $knMoResult->Size() > 0 && $knMoResult->Next()) {
			$kmo = round((float)$knMoResult->kmo, 1);
		}
		$result['_kingdom'] = ['att' => $katt, 'mo' => $kmo, 'wk_count' => $wkCount];

		// Previous-period trend data — only for users with kingdom-level auth
		if ($isAdmin) {
			// Previous 6-month period (6–12 months ago) — matches current window of 6 months
			$DB->Clear();
			$prevWkResult = $DB->DataSet(
				"SELECT COUNT(mw.mundane_id) AS att, p.park_id
				 FROM ork_park p
				 LEFT JOIN (
				     SELECT a.mundane_id, a.park_id
				     FROM ork_attendance a
				     INNER JOIN " . DB_PREFIX . "park pk ON pk.park_id = a.park_id AND pk.kingdom_id IN ({$statsKids})
				     WHERE a.date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
				       AND a.date <  DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
				       AND a.mundane_id > 0
				     GROUP BY date_year, date_week3, mundane_id, a.park_id
				 ) mw ON p.park_id = mw.park_id
				 WHERE p.kingdom_id IN ({$statsKids}) AND p.active = 'Active'
				 GROUP BY p.park_id"
			);
			if ($prevWkResult) {
				while ($prevWkResult->Next()) {
					$pid = (int)$prevWkResult->park_id;
					if (isset($result[$pid])) $result[$pid]['prev_att'] = (int)$prevWkResult->att;
				}
			}
			// Previous 12 months (months 13–24 ago) — AVG(distinct players per month) per park
			$DB->Clear();
			$prevMoResult = $DB->DataSet(
				"SELECT AVG(monthly_unique) AS mo, park_id
				 FROM (
				     SELECT a.date_year, a.date_month, a.park_id,
				            COUNT(DISTINCT a.mundane_id) AS monthly_unique
				     FROM ork_attendance a
				     INNER JOIN ork_park p ON p.park_id = a.park_id AND p.kingdom_id IN ({$statsKids})
				     WHERE a.date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
				       AND a.date <  DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
				       AND a.mundane_id > 0
				     GROUP BY a.date_year, a.date_month, a.park_id
				 ) mm
				 GROUP BY park_id"
			);
			if ($prevMoResult) {
				while ($prevMoResult->Next()) {
					$pid = (int)$prevMoResult->park_id;
					if (isset($result[$pid])) $result[$pid]['prev_mo'] = round((float)$prevMoResult->mo, 2);
				}
			}
		}
		Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $cacheKey, $result);
		header('Content-Type: application/json');
		echo json_encode($result);
		exit();
	}

	public function events_more($kingdom_id = null) {
		$kingdom_id = preg_replace('/[^0-9]/', '', $kingdom_id);
		$kid = (int)$kingdom_id;
		$statsEvtKids = implode(',', array_map('intval', $this->Kingdom->GetStatsKingdomIds($kid)));
		$window = isset($_GET['window']) ? (int)$_GET['window'] : 1;
		if ($window < 1) $window = 1;
		if ($window > 10) $window = 10;
		$startMonths = $window * 12;
		$endMonths   = $startMonths + 12;

		global $DB;
		$evtSql = "
			SELECT e.event_id, e.name, e.park_id, p.name AS park_name, p.abbreviation AS park_abbr,
			       cd.event_start, cd.event_calendardetail_id AS next_detail_id, e.has_heraldry,
			       (SELECT COUNT(*) FROM ork_event_rsvp WHERE event_calendardetail_id = cd.event_calendardetail_id AND status = 'going') AS rsvp_going,
			       (SELECT COUNT(*) FROM ork_event_rsvp WHERE event_calendardetail_id = cd.event_calendardetail_id AND status = 'interested') AS rsvp_interested
			FROM ork_event e
			LEFT JOIN ork_park p ON p.park_id = e.park_id
			JOIN ork_event_calendardetail cd ON cd.event_id = e.event_id
			    AND cd.event_start >  DATE_ADD(NOW(), INTERVAL {$startMonths} MONTH)
			    AND cd.event_start <= DATE_ADD(NOW(), INTERVAL {$endMonths} MONTH)
			WHERE e.kingdom_id IN ({$statsEvtKids})
			ORDER BY cd.event_start, p.name, e.name";
		$DB->Clear();
		$evtResult = $DB->DataSet($evtSql);
		$events = [];
		$fallbackHeraldry = HTTP_EVENT_HERALDRY . '00000.jpg';
		while ($evtResult && $evtResult->Next()) {
			$eid = (int)($evtResult->event_id ?? 0);
			if (!$eid) continue;
			$start = $evtResult->event_start;
			$events[] = [
				'EventId'        => $eid,
				'Name'           => $evtResult->name,
				'ParkName'       => $evtResult->park_name,
				'NextDate'       => $start,
				'NextDateText'   => ($start && $start !== '0000-00-00 00:00:00' && $start !== '0000-00-00')
					? date('M j, Y', strtotime($start)) : '',
				'NextDetailId'   => (int)$evtResult->next_detail_id,
				'HasHeraldry'    => (int)$evtResult->has_heraldry,
				'HeraldryUrl'    => ((int)$evtResult->has_heraldry === 1)
					? HTTP_EVENT_HERALDRY . Common::resolve_image_ext(DIR_EVENT_HERALDRY, sprintf('%05d', $eid))
					: $fallbackHeraldry,
				'ParkAbbr'       => $evtResult->park_abbr,
				'RsvpGoing'      => (int)$evtResult->rsvp_going,
				'RsvpInterested' => (int)$evtResult->rsvp_interested,
				'IsParkEvent'    => (int)$evtResult->park_id > 0,
			];
		}
		// HasMore: not just a window cap — actually check if any events exist past this window.
		$hasMore = false;
		if ($window < 10) {
			$_nextStart = $endMonths;
			$DB->Clear();
			$_more = $DB->DataSet(
				"SELECT 1 FROM ork_event_calendardetail cd
				 JOIN ork_event e ON e.event_id = cd.event_id
				 WHERE e.kingdom_id IN ({$statsEvtKids})
				   AND cd.event_start >  DATE_ADD(NOW(), INTERVAL {$_nextStart} MONTH)
				   AND cd.event_start <= DATE_ADD(NOW(), INTERVAL 120 MONTH)
				 LIMIT 1"
			);
			$hasMore = ($_more && $_more->Size() > 0);
		}

		header('Content-Type: application/json');
		echo json_encode([
			'Window'           => $window,
			'StartMonths'      => $startMonths,
			'EndMonths'        => $endMonths,
			'Count'            => count($events),
			'HasMore'          => $hasMore,
			'FallbackHeraldry' => $fallbackHeraldry,
			'Uir'              => UIR,
			'Events'           => $events,
		]);
		exit();
	}

	// Lazy-loaded body of the kingdom profile Recommendations tab. Called by JS
	// the first time the tab is activated; returns raw HTML for the tab's inner
	// container (NOT a full page). Auth+visibility rules mirror profile().
	public function recommendations_panel($kingdom_id = null) {
		session_write_close();
		$kingdom_id = (int)preg_replace('/[^0-9]/', '', (string)$kingdom_id);
		if ($kingdom_id <= 0) { http_response_code(400); exit; }
		$this->load_model('Reports');

		$uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
		$isOrkAdmin = $uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN);
		$canManageKingdom = $isOrkAdmin
			|| ($uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kingdom_id, AUTH_CREATE));

		$knConfigs  = Common::get_configs($kingdom_id, CFG_KINGDOM);
		$recsPublic = isset($knConfigs['AwardRecsPublic'])
			? (bool)(int)$knConfigs['AwardRecsPublic']['Value']
			: true;

		$AwardRecommendations = [];
		if ($recsPublic || $canManageKingdom) {
			$recs = $this->Reports->recommended_awards(['KingdomId' => $kingdom_id, 'ParkId' => 0, 'PlayerId' => 0, 'RequestedBy' => $uid]);
			$AwardRecommendations = is_array($recs) ? $recs : [];
		} elseif ($uid > 0) {
			$recs = $this->Reports->recommended_awards(['KingdomId' => $kingdom_id, 'ParkId' => 0, 'PlayerId' => 0, 'RequestedBy' => $uid]);
			$allRecs = is_array($recs) ? $recs : [];
			$AwardRecommendations = array_values(array_filter($allRecs, function($r) use ($uid) {
				return (int)$r['RecommendedById'] === $uid;
			}));
		} else {
			http_response_code(403); exit;
		}

		// Variables the partial template expects in scope:
		$IsLoggedIn       = $uid > 0;
		$CanManageKingdom = $canManageKingdom;
		$kingdom_name     = $this->Kingdom->get_kingdom_name($kingdom_id);

		// "My Circles" filter: the viewer's peerage voting circle, as a set of award_ids.
		// Empty for non-peers (the button is then not rendered).
		$ViewerCircleAwardIds = $uid > 0 ? Ork3::$Lib->player->GetCircleAwardIds($uid) : array();
		$ViewerHasCircle      = !empty($ViewerCircleAwardIds);

		header('Content-Type: text/html; charset=utf-8');
		header('X-Recs-Count: ' . count($AwardRecommendations)); // JS uses this for the tab badge
		include DIR_TEMPLATE . 'revised-frontend/Kingdomnew_recommendations_panel.tpl';
		exit();
	}

	public function players_json($kingdom_id = null) {
		session_write_close(); // release session lock so navigation is not blocked
		$kingdom_id = preg_replace('/[^0-9]/', '', $kingdom_id);
		$kid = (int)$kingdom_id;
		$cacheKey = Ork3::$Lib->ghettocache->key(['KingdomId' => $kid]);
		if (($cached = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $cacheKey, 1200)) !== false) {
			header('Content-Type: application/json');
			echo json_encode($cached);
			exit();
		}
		global $DB;
		// last_signin = player's MOST RECENT sign-in anywhere — drives the year bucket so active
		// travelers don't appear "lost" on their home kingdom roster.
		// signin_count = overall 6-month count (matches the bucket's "anywhere" semantic).
		// last_signin_in_kingdom drives the la JOIN so the "last class" we display is from
		// in-kingdom attendance (most relevant to the kingdom page).
		$kpSql = "SELECT m.mundane_id, m.persona, m.has_image, m.has_heraldry, m.restricted,
				COALESCE(m.given_name, '')                          AS given_name,
				COALESCE(m.surname, '')                             AS surname,
				COALESCE(sub.last_signin, '1970-01-01')             AS last_signin,
				COALESCE(sub.signin_count, 0)                       AS signin_count,
				c.name                                              AS last_class,
				hp.name                                             AS park_name,
				GROUP_CONCAT(DISTINCT o.role ORDER BY o.role SEPARATOR ', ') AS officer_roles
			FROM ork_mundane m
			INNER JOIN ork_park hp ON hp.park_id = m.park_id AND hp.kingdom_id = {$kid}
			LEFT JOIN (
				SELECT a.mundane_id,
					MAX(a.date) AS last_signin,
					MAX(CASE WHEN a.kingdom_id = {$kid} THEN a.date END) AS last_signin_in_kingdom,
					SUM(a.date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)) AS signin_count
				FROM ork_attendance a
				INNER JOIN ork_mundane mm
					ON mm.mundane_id = a.mundane_id
				   AND mm.kingdom_id = {$kid}
				   AND mm.suspended = 0 AND mm.active = 1
				GROUP BY a.mundane_id
			) sub ON sub.mundane_id = m.mundane_id
			LEFT JOIN ork_attendance la
				ON la.mundane_id = m.mundane_id
			   AND la.date       = sub.last_signin_in_kingdom
			   AND la.kingdom_id = {$kid}
			LEFT JOIN ork_class c ON la.class_id = c.class_id
			LEFT JOIN ork_officer o ON o.mundane_id = m.mundane_id AND o.park_id = m.park_id
			WHERE m.suspended = 0 AND m.active = 1
			GROUP BY m.mundane_id
			ORDER BY m.persona";
		$DB->Clear();
		$r = $DB->DataSet($kpSql);
		$players = [];
		if ($r) {
			while ($r->Next()) {
				$mid     = (int)$r->mundane_id;
				$midPad  = sprintf('%06d', $mid);
				$hasImg  = (int)$r->has_image > 0;
				$hasHer  = (int)$r->has_heraldry > 0;
				$herUrl  = $hasHer ? HTTP_PLAYER_HERALDRY . Common::resolve_image_ext(DIR_PLAYER_HERALDRY, $midPad) : null;
				$imgUrl  = $hasImg ? HTTP_PLAYER_IMAGE    . Common::resolve_image_ext(DIR_PLAYER_IMAGE,    $midPad) : ($hasHer ? $herUrl : null);
				$mn = ((int)$r->restricted === 0) ? trim($r->given_name . ' ' . $r->surname) : '';
				$players[] = [
					'id'           => $mid,
					'persona'      => $r->persona,
					'mundaneName'  => $mn,
					'parkName'     => $r->park_name,
					'signinCount'  => (int)$r->signin_count,
					'lastSignin'   => $r->last_signin,
					'lastClass'    => $r->last_class,
					'officerRoles' => $r->officer_roles,
					'avatarUrl'    => $imgUrl,
					'heraldryUrl'  => $herUrl,
				];
			}
		}
		Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $cacheKey, ['players' => $players]);
		header('Content-Type: application/json');
		echo json_encode(['players' => $players]);
		exit();
	}

	public function map($kingdom_id = null) {
		if (valid_id($kingdom_id)) {
	    	$kingdom_details = $this->Kingdom->GetKingdomDetails(array('KingdomId' => $kingdom_id));
			$this->data[ 'page_title' ] = $kingdom_details['KingdomInfo']['KingdomName'] . " Map";

			$all_parks = $this->Kingdom->GetParks(array('KingdomId' => $kingdom_id));
			$all_parks['Parks'] = array_filter(
				$all_parks['Parks'],
				function ($park) {
					return $park['Active'] == 'Active';
        		}
      		);
      		$this->data['Parks'] = $all_parks;
		}
	}

	public function profile( $kingdom_id = null ) {
		$this->template = '../revised-frontend/Kingdomnew_index.tpl';
		$kingdom_id = preg_replace('/[^0-9]/', '', $kingdom_id);
		if (!valid_id($kingdom_id)) { header('Location: ' . UIR); exit; }
		$this->load_model('Award');
		$this->load_model('Reports');
		$this->load_model('Pronoun');
		$this->load_model('Recap');
		$this->data['week_recap'] = $this->Recap->get();

		$this->data['menu']['kingdom'] = [
			'url'     => UIR . 'Kingdom/profile/' . $kingdom_id,
			'display' => $this->session->kingdom_name,
		];

		$this->data['park_summary']        = $this->Kingdom->get_park_summary($kingdom_id);
		$this->data['principalities']      = $this->Kingdom->get_principalities($kingdom_id);
		$this->data['kingdom_info']        = $this->Kingdom->get_kingdom_shortinfo($kingdom_id);
		if (empty($this->data['kingdom_info']['Info']['KingdomInfo']['KingdomId'])) {
			header('Location: ' . UIR);
			exit;
		}
		$this->data['kingdom_officers']    = $this->Kingdom->GetOfficers(['KingdomId' => $kingdom_id, 'Token' => $this->session->token]);
		$this->data['IsPrinz']             = $this->data['kingdom_info']['Info']['KingdomInfo']['IsPrincipality'];

		$parentKingdomId = (int)($this->data['kingdom_info']['Info']['KingdomInfo']['ParentKingdomId'] ?? 0);
		$this->data['ParentKingdomId']   = $parentKingdomId;
		$this->data['ParentKingdomName'] = $parentKingdomId > 0 ? $this->Kingdom->get_kingdom_name($parentKingdomId) : '';

		$this->data['AwardOptions']        = $this->Award->fetch_award_option_list($kingdom_id, 'Awards');
		$this->data['OfficerOptions']      = $this->Award->fetch_award_option_list($kingdom_id, 'Officers');
		$this->data['CustomTitleAliasOptions'] = $this->Award->fetch_custom_title_alias_options();
		$preloadOfficers = [];
		foreach ($this->data['kingdom_officers']['Officers'] ?? [] as $o) {
			if (in_array($o['OfficerRole'], ['Monarch', 'Regent']) && (int)$o['MundaneId'] > 0)
				$preloadOfficers[] = ['MundaneId' => $o['MundaneId'], 'Persona' => $o['Persona'], 'Role' => $o['OfficerRole']];
		}
		$this->data['PreloadOfficers']     = $preloadOfficers;
		// [TOURNAMENTS HIDDEN] $this->data['kingdom_tournaments'] = [];

		$rawParks = $this->Kingdom->GetParks(['KingdomId' => $kingdom_id]);
		$this->data['map_parks'] = is_array($rawParks['Parks'])
			? array_values(array_filter($rawParks['Parks'], function($p) { return $p['Active'] == 'Active'; }))
			: [];

		// Whether this kingdom has active child principalities (and is not itself one).
		// Drives the admin 'Include Principality in Statistics' toggle visibility.
		$this->data['HasChildPrincipalities'] = (empty($this->data['IsPrinz'])
			&& is_array($this->data['principalities']['Principalities'] ?? null)
			&& count($this->data['principalities']['Principalities']) > 0);

		// Child-principality parks for the Parks tab (tile/list) and the kingdom map.
		// Only when this kingdom is NOT itself a principality; both keys always set.
		$this->data['principality_parks'] = [];
		$this->data['prinz_map_parks']    = [];
		if (empty($this->data['IsPrinz']) && is_array($this->data['principalities']['Principalities'] ?? null)) {
			foreach ($this->data['principalities']['Principalities'] as $pr) {
				$prId = (int)($pr['KingdomId'] ?? 0);
				if ($prId <= 0) continue;
				$prName = $pr['Name'] ?? '';

				$prSummary = $this->Kingdom->get_park_summary($prId);
				$prParks   = is_array($prSummary['KingdomParkAveragesSummary'] ?? null)
					? $prSummary['KingdomParkAveragesSummary']
					: [];
				if (!empty($prParks)) {
					$this->data['principality_parks'][] = [
						'KingdomId' => $prId,
						'Name'      => $prName,
						'parks'     => $prParks,
					];
				}

				$prRawParks = $this->Kingdom->GetParks(['KingdomId' => $prId]);
				$prMapParks = is_array($prRawParks['Parks'] ?? null)
					? array_values(array_filter($prRawParks['Parks'], function($p) { return $p['Active'] == 'Active'; }))
					: [];
				if (!empty($prMapParks)) {
					$this->data['prinz_map_parks'][] = [
						'KingdomId' => $prId,
						'Name'      => $prName,
						'parks'     => $prMapParks,
					];
				}
			}
		}

		// Hero/tab 'Parks (N)' count: roll up family park count when the stats flag is on
		// (main parks + principality parks already gathered above). Falls back to the
		// kingdom's own park count otherwise. Does NOT merge principality parks into the tiles.
		$ownParkCount = is_array($this->data['park_summary']['KingdomParkAveragesSummary'] ?? null)
			? count($this->data['park_summary']['KingdomParkAveragesSummary'])
			: 0;
		if (!empty($this->data['HasChildPrincipalities']) && $this->Kingdom->StatsIncludesPrincipalities($kingdom_id)) {
			$prinzParkCount = 0;
			foreach ($this->data['principality_parks'] as $prGroup) {
				$prinzParkCount += is_array($prGroup['parks'] ?? null) ? count($prGroup['parks']) : 0;
			}
			$this->data['StatsParkCount'] = $ownParkCount + $prinzParkCount;
		} else {
			$this->data['StatsParkCount'] = $ownParkCount;
		}

		$this->data['park_edit_lookup'] = [];
		if (is_array($rawParks['Parks'])) {
			foreach ($rawParks['Parks'] as $p) {
				$this->data['park_edit_lookup'][(int)$p['ParkId']] = [
					'ParkId'       => (int)$p['ParkId'],
					'Name'         => $p['Name'],
					'Abbreviation' => $p['Abbreviation'] ?? '',
					'ParkTitleId'  => (int)($p['ParkTitleId'] ?? 0),
					'Active'       => $p['Active'],
				];
			}
		}

		global $DB;
		$kid = (int)$kingdom_id;
		$statsEvtKids = implode(',', array_map('intval', $this->Kingdom->GetStatsKingdomIds($kid)));

		$evtSql = "
			SELECT e.event_id, e.name, e.park_id, p.name AS park_name, p.abbreviation AS park_abbr,
			       cd.event_start, cd.event_calendardetail_id AS next_detail_id, e.has_heraldry,
				       COALESCE(rsvp.rsvp_going, 0) AS rsvp_going,
			       COALESCE(rsvp.rsvp_interested, 0) AS rsvp_interested
			FROM ork_event e
			LEFT JOIN ork_park p ON p.park_id = e.park_id
			JOIN ork_event_calendardetail cd ON cd.event_id = e.event_id
			    AND cd.event_start >= DATE_SUB(NOW(), INTERVAL 7 DAY)
			    AND cd.event_start <= DATE_ADD(NOW(), INTERVAL 12 MONTH)
			LEFT JOIN (
			    SELECT
			        event_calendardetail_id,
			        SUM(status = 'going') AS rsvp_going,
			        SUM(status = 'interested') AS rsvp_interested
			    FROM ork_event_rsvp
			    GROUP BY event_calendardetail_id
			) rsvp ON rsvp.event_calendardetail_id = cd.event_calendardetail_id
			WHERE e.kingdom_id IN ({$statsEvtKids})
			ORDER BY cd.event_start, p.name, e.name";
		$DB->Clear();
		$evtResult    = $DB->DataSet($evtSql);
		$eventSummary = [];
		while ($evtResult && $evtResult->Next()) {
			$eid = (int)($evtResult->event_id ?? 0);
			if ($eid) {
				$eventSummary[] = [
					'EventId'      => $eid,
					'Name'         => $evtResult->name,
					'ParkName'     => $evtResult->park_name,
					'NextDate'     => $evtResult->event_start,
					'NextDetailId' => (int)$evtResult->next_detail_id,
					'HasHeraldry'  => (int)$evtResult->has_heraldry,
					'ParkAbbr'     => $evtResult->park_abbr,
					'RsvpGoing'      => (int)$evtResult->rsvp_going,
				'RsvpInterested' => (int)$evtResult->rsvp_interested,
					'_IsParkEvent' => (int)$evtResult->park_id > 0,
				];
			}
		}
		$this->data['event_summary'] = $eventSummary;

		// Hide the "Load more" button when there are no events past the initial 12-month window.
		// events_more iterates 12-month windows up to 120 months out, so any cd in (12, 120]
		// months means at least one click would yield results.
		$DB->Clear();
		$moreRes = $DB->DataSet(
			"SELECT 1 FROM ork_event_calendardetail cd
			 JOIN ork_event e ON e.event_id = cd.event_id
			 WHERE e.kingdom_id IN ({$statsEvtKids})
			   AND cd.event_start >  DATE_ADD(NOW(), INTERVAL 12 MONTH)
			   AND cd.event_start <= DATE_ADD(NOW(), INTERVAL 120 MONTH)
			 LIMIT 1"
		);
		$this->data['HasMoreEvents'] = ($moreRes && $moreRes->Size() > 0);

		$pdSql = "
			SELECT pd.parkday_id, pd.park_id, pd.recurrence, pd.week_day,
			       pd.week_of_month, pd.month_day, pd.time, pd.purpose, p.name AS park_name, p.abbreviation AS park_abbr
			FROM ork_parkday pd
			JOIN ork_park p ON p.park_id = pd.park_id
			WHERE p.kingdom_id = {$kid}
			  AND p.active = 'Active'
			ORDER BY p.name, pd.week_day, pd.time";
		$DB->Clear();
		$pdResult = $DB->DataSet($pdSql);
		$parkDays = [];
		if ($pdResult && $pdResult->Size() > 0) {
			while ($pdResult->Next()) {
				switch ($pdResult->recurrence) {
					case 'weekly':       $recText = 'Every ' . $pdResult->week_day; break;
					case 'week-of-month':
					$n = (int)$pdResult->week_of_month;
					$sfx = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : (['th','st','nd','rd','th','th','th','th','th','th'][$n % 10] ?? 'th');
					$recText = 'Every ' . $n . $sfx . ' ' . $pdResult->week_day;
					break;
					case 'monthly':      $recText = 'Monthly, day ' . (int)$pdResult->month_day; break;
					default:             $recText = ucfirst($pdResult->recurrence);
				}
				switch ($pdResult->purpose) {
					case 'fighter-practice': $purposeLabel = 'Fighter Practice'; break;
					case 'arts-day':         $purposeLabel = 'A&S Day'; break;
					case 'park-day':         $purposeLabel = 'Park Day'; break;
					default:                 $purposeLabel = ucwords(str_replace('-', ' ', $pdResult->purpose));
				}
				$parkDays[] = [
					'ParkDayId'   => (int)$pdResult->parkday_id,
					'ParkId'      => (int)$pdResult->park_id,
					'ParkName'    => $pdResult->park_name,
					'ParkAbbr'    => $pdResult->park_abbr,
					'Schedule'    => $recText,
					'Purpose'     => $purposeLabel,
					'Time'        => $pdResult->time,
					'Recurrence'  => $pdResult->recurrence,
					'WeekDay'     => $pdResult->week_day,
					'WeekOfMonth' => (int)$pdResult->week_of_month,
					'MonthDay'    => (int)$pdResult->month_day,
				];
			}
		}
		$this->data['kingdom_park_days'] = $parkDays;

		$uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
		$this->data['IsLoggedIn']       = $uid > 0;

		// Pin the logged-in user's home park to the first slot in the parks list
		$this->data['UserParkId'] = 0;
		if ($uid > 0) {
			global $DB;
			$DB->Clear();
			$upRow = $DB->DataSet("SELECT park_id FROM " . DB_PREFIX . "mundane WHERE mundane_id = $uid LIMIT 1");
			if ($upRow && $upRow->Next() && $upRow->park_id) {
				$this->data['UserParkId'] = (int)$upRow->park_id;
			}
		}
		$this->data['CanEditKingdom']   = $uid > 0
			&& Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, (int)$kingdom_id, AUTH_EDIT);
		$this->data['CanManageKingdom'] = $uid > 0
			&& Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, (int)$kingdom_id, AUTH_CREATE);
		$this->data['CanAddPark'] = $uid > 0
			&& Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, (int)$kingdom_id, AUTH_CREATE);
		$this->data['IsOrkAdmin'] = $uid > 0
			&& Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN);

		$knConfigs  = Common::get_configs($kingdom_id, CFG_KINGDOM);
		$recsPublic = isset($knConfigs['AwardRecsPublic'])
			? (bool)(int)$knConfigs['AwardRecsPublic']['Value']
			: true;
		$this->data['AwardRecsPublic'] = $recsPublic;

		// Recommendations tab visibility is a permissions decision; the rows themselves
		// are lazy-loaded by JS calling Controller_Kingdom::recommendations_panel().
		// Inlining the rows here was rendering thousands of <tr>s and stalling the
		// browser's DOMContentLoaded for 1+ second on busy kingdoms.
		$this->data['AwardRecommendations'] = [];
		$this->data['AwardRecommendationsCount'] = 0;
		$canManageKingdom = $this->data['CanManageKingdom'] ?? false;
		if ($recsPublic || $canManageKingdom) {
			$this->data['ShowRecsTab'] = true;
			$this->data['AwardRecommendationsCount'] = $this->Reports->recommended_awards_count(['KingdomId' => $kingdom_id]);
		} elseif ($uid > 0) {
			// Logged-in non-admin on a private-recs kingdom — tab is shown only if
			// the user has their own recs. Cheap COUNT query, no row hydration.
			$n = $this->Reports->recommended_awards_count(['KingdomId' => $kingdom_id, 'RecommendedBy' => $uid]);
			$this->data['AwardRecommendationsCount'] = $n;
			$this->data['ShowRecsTab'] = $n > 0;
		} else {
			$this->data['ShowRecsTab'] = false;
		}

		// Players tab badge — cheap COUNT so the page shows "Players (N)" on first
		// paint without waiting for players_json (which builds full rosters).
		$_pcCacheKey = Ork3::$Lib->ghettocache->key(['KingdomId' => (int)$kingdom_id]);
		$_pcCached = Ork3::$Lib->ghettocache->get(__CLASS__ . '.player_count', $_pcCacheKey, 600);
		if ($_pcCached !== false) {
			$this->data['PlayerCount'] = (int)$_pcCached;
		} else {
			global $DB;
			$_kid = (int)$kingdom_id;
			$DB->Clear();
			$_pcResult = $DB->DataSet("SELECT COUNT(*) AS n
				FROM " . DB_PREFIX . "mundane m
				INNER JOIN " . DB_PREFIX . "park p ON p.park_id = m.park_id AND p.kingdom_id = {$_kid}
				WHERE m.suspended = 0 AND m.active = 1");
			$_pcN = ($_pcResult && $_pcResult->Next()) ? (int)$_pcResult->n : 0;
			$this->data['PlayerCount'] = $_pcN;
			Ork3::$Lib->ghettocache->cache(__CLASS__ . '.player_count', $_pcCacheKey, $_pcN);
		}

		$this->data['ParkTitleId_options'] = [];
		$this->data['AdminInfo']           = [];
		$this->data['AdminConfig']         = [];
		$this->data['AdminParkTitles']     = [];
		$this->data['AdminAwards']         = [];
		if ($this->data['CanManageKingdom']) {
			$kd = $this->Kingdom->get_kingdom_details($kingdom_id);
			foreach ($kd['ParkTitles'] ?? [] as $pt) {
				$this->data['ParkTitleId_options'][$pt['ParkTitleId']] = $pt['Title'];
			}

			$parentKingdomId   = (int)($kd['KingdomInfo']['ParentKingdomId'] ?? 0);
			$parentKingdomName = '';
			if ($parentKingdomId > 0) {
				$parentKingdomName = $this->Kingdom->get_kingdom_name($parentKingdomId);
			}
			$this->data['AdminInfo'] = [
				'Name'             => $kd['KingdomInfo']['KingdomName']  ?? '',
				'Abbreviation'     => $kd['KingdomInfo']['Abbreviation'] ?? '',
				'Description'      => $kd['KingdomInfo']['Description']  ?? '',
				'Url'              => $kd['KingdomInfo']['Url']          ?? '',
				'IsPrincipality'   => !empty($kd['KingdomInfo']['IsPrincipality']),
				'ParentKingdomId'  => $parentKingdomId,
				'ParentKingdomName'=> $parentKingdomName,
				'Active'           => $kd['KingdomInfo']['Active'] ?? 'Active',
			];

			$adminConfig = [];
			$hasChildPrinz = !empty($this->data['HasChildPrincipalities']);
			foreach ($kd['KingdomConfiguration'] ?? [] as $cfg) {
				if (empty($cfg['UserSetting'])) continue;
				// Only surface the principality-stats toggle for kingdoms that have principalities.
				if (($cfg['Key'] ?? '') === 'IncludePrincipalityInStatistics' && !$hasChildPrinz) continue;
				$adminConfig[] = $cfg;
			}
			$this->data['AdminConfig']     = $adminConfig;
			$this->data['AdminParkTitles'] = array_values($kd['ParkTitles'] ?? []);

			$rawAwards   = $kd['Awards']['Awards'] ?? [];
			$adminAwards = [];
			foreach ($rawAwards as $kawId => $aw) {
				$adminAwards[] = [
					'KingdomAwardId'   => (int)$kawId,
					'KingdomAwardName' => $aw['KingdomAwardName']  ?? '',
					'AwardId'          => (int)($aw['AwardId']     ?? 0),
					'AwardName'        => $aw['AwardName']         ?? '',
					'IsLadder'         => (int)($aw['IsLadder']    ?? 0),
					'ReignLimit'       => (int)($aw['ReignLimit']  ?? 0),
					'MonthLimit'       => (int)($aw['MonthLimit']  ?? 0),
					'IsTitle'          => (int)($aw['IsTitle']     ?? 0),
					'TitleClass'       => (int)($aw['TitleClass']  ?? 0),
				];
			}
			$this->data['AdminAwards'] = $adminAwards;

			// System awards list for Add Award Alias dropdown
			$sysAwardResult = $this->Award->GetAwardList(['IsLadder' => null, 'IsTitle' => null, 'OfficerRole' => 'Awards']);
			$sysAwards = [];
			if (($sysAwardResult['Status']['Status'] ?? 1) == 0) {
				foreach ($sysAwardResult['Awards'] as $sa) {
					$sysAwards[] = ['AwardId' => (int)$sa['AwardId'], 'Name' => $sa['AwardName'] ?? $sa['KingdomAwardName']];
				}
				usort($sysAwards, function($a, $b) { return strcasecmp($a['Name'], $b['Name']); });
			}
			$this->data['SystemAwards'] = $sysAwards;
		}

		$this->data['PronounList']          = $this->Pronoun->fetch_pronoun_list();
		$this->data['PronounOptionsCreate'] = $this->Pronoun->fetch_pronoun_option_list(null);
		$this->data['IcsUrl'] = UIR . 'Kingdom/ics/' . $kingdom_id;
	}

	// ------------------------------------------------------------------ ICS helpers
	private static function ics_dt($str) {
		return gmdate('Ymd\THis\Z', strtotime($str));
	}
	private static function ics_dt_plus1hr($str) {
		return gmdate('Ymd\THis\Z', strtotime($str) + 3600);
	}
	private static function ics_escape($str) {
		$str = str_replace('\\', '\\\\', $str);
		$str = str_replace(';',  '\;',   $str);
		$str = str_replace(',',  '\,',   $str);
		$str = str_replace(["\r\n", "\r", "\n"], '\\n', $str);
		return $str;
	}
	private static function ics_fold($line) {
		$out = '';
		while (strlen($line) > 75) {
			$out  .= substr($line, 0, 75) . "\r\n ";
			$line  = substr($line, 75);
		}
		return $out . $line;
	}
	private static function ics_location($address, $city, $province, $postal, $country) {
		$parts = array_filter([$address, $city, $province, $postal, $country], 'strlen');
		return implode(', ', $parts);
	}

	// ------------------------------------------------------------------ ICS Feed
	public function ics($kingdom_id = null) {
		$kingdom_id = preg_replace('/[^0-9]/', '', $kingdom_id);
		$kid = (int)$kingdom_id;
		$statsEvtKids = implode(',', array_map('intval', $this->Kingdom->GetStatsKingdomIds($kid)));

		// Kingdom name for CALNAME
		$knName = $this->Kingdom->get_kingdom_name($kid);
		if (empty($knName)) $knName = 'Kingdom';

		// Fetch events
		global $DB;
		$sql = "
			SELECT
				e.event_id, e.name,
				p.name AS park_name,
				cd.event_calendardetail_id, cd.event_start, cd.event_end,
				cd.description, cd.url,
				cd.address, cd.city, cd.province, cd.postal_code, cd.country
			FROM ork_event e
			LEFT JOIN ork_park p ON p.park_id = e.park_id
			JOIN ork_event_calendardetail cd ON cd.event_id = e.event_id
				AND cd.event_start >= CURDATE()
				AND cd.event_start <= DATE_ADD(NOW(), INTERVAL 12 MONTH)
			WHERE e.kingdom_id IN ({$statsEvtKids})
			ORDER BY cd.event_start ASC";
		$DB->Clear();
		$result = $DB->DataSet($sql);

		// Build ICS
		$lines = [];
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:-//ORK3//Amtgard ORK//EN';
		$lines[] = 'CALSCALE:GREGORIAN';
		$lines[] = 'METHOD:PUBLISH';
		$lines[] = self::ics_fold('X-WR-CALNAME:' . self::ics_escape($knName) . ' Events');

		if ($result) {
			do {
				if ((int)$result->event_calendardetail_id === 0) continue;
				$dtstart = self::ics_dt($result->event_start);
				$rawEnd  = $result->event_end;
				$dtend   = (!empty($rawEnd) && $rawEnd !== '0000-00-00 00:00:00')
					? self::ics_dt($rawEnd)
					: self::ics_dt_plus1hr($result->event_start);

				$uid      = 'event-' . (int)$result->event_id . '-' . (int)$result->event_calendardetail_id . '@ork3';
				$location = self::ics_location($result->address, $result->city, $result->province, $result->postal_code, $result->country);
				$dtstamp  = gmdate('Ymd\THis\Z');

				$lines[] = 'BEGIN:VEVENT';
				$lines[] = self::ics_fold('UID:' . $uid);
				$lines[] = 'DTSTAMP:' . $dtstamp;
				$lines[] = 'DTSTART:' . $dtstart;
				$lines[] = 'DTEND:' . $dtend;
				$lines[] = self::ics_fold('SUMMARY:' . self::ics_escape($result->name));
				if (!empty($result->description)) {
					$lines[] = self::ics_fold('DESCRIPTION:' . self::ics_escape(strip_tags($result->description)));
				}
				if (!empty($location)) {
					$lines[] = self::ics_fold('LOCATION:' . self::ics_escape($location));
				}
				if (!empty($result->url)) {
					$lines[] = self::ics_fold('URL:' . self::ics_escape(preg_replace('/[\r\n]/', '', $result->url)));
				}
				$lines[] = 'END:VEVENT';
			} while ($result->Next());
		}

		$lines[] = 'END:VCALENDAR';

		$safeName = preg_replace('/[^a-z0-9]/i', '-', $knName);
		header('Content-Type: text/calendar; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $safeName . '-events.ics"');
		header('Cache-Control: no-cache, must-revalidate');
		echo implode("\r\n", $lines) . "\r\n";
		exit();
	}

}

?>
