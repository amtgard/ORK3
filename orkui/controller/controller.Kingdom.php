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

		if (isset($this->request->kingdom_name)) {
			$this->session->kingdom_name = $this->request->kingdom_name;
		} else if (!isset($this->session->kingdom_name)) {
			// Direct link
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
		$kingdom_id = preg_replace('/[^0-9]/', '', $kingdom_id);
		$kid     = (int)$kingdom_id;
		$weekly  = $this->Report->GetKingdomParkAverages(['KingdomId' => $kingdom_id]);
		$monthly = $this->Report->GetKingdomParkMonthlyAverages(['KingdomId' => $kingdom_id]);
		$result  = array();
		foreach ((array)($weekly['KingdomParkAveragesSummary'] ?? []) as $park) {
			$result[$park['ParkId']] = ['att' => (int)$park['AttendanceCount'], 'mo' => 0, 'tp' => 0, 'tm' => 0];
		}
		foreach ((array)($monthly['KingdomParkMonthlySummary'] ?? []) as $park) {
			if (isset($result[$park['ParkId']])) {
				$result[$park['ParkId']]['mo'] = (int)$park['MonthlyCount'];
			} else {
				$result[$park['ParkId']] = ['att' => 0, 'mo' => (int)$park['MonthlyCount'], 'tp' => 0, 'tm' => 0];
			}
		}
		global $DB;
		$pcSql = "SELECT a.park_id,
				COUNT(DISTINCT a.mundane_id) AS total_players,
				COUNT(DISTINCT CASE WHEN m.park_id = a.park_id THEN a.mundane_id END) AS total_members
			FROM ork_attendance a
			INNER JOIN ork_park p  ON p.park_id  = a.park_id  AND p.kingdom_id = {$kid}
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
				SELECT mundane_id FROM " . DB_PREFIX . "attendance
				WHERE kingdom_id = {$kid}
					AND date > DATE_SUB(CURDATE(), INTERVAL 26 WEEK)
					AND mundane_id > 0
				GROUP BY date_year, date_week3, mundane_id
			) t";
		$DB->Clear();
		$knResult = $DB->DataSet($knSql);
		$katt = 0;
		if ($knResult && $knResult->Size() > 0 && $knResult->Next()) {
			$katt = (int)$knResult->katt;
		}
		$result['_kingdom'] = ['att' => $katt];

		// Previous-period trend data — only for users with kingdom-level auth
		$uid = (int)($this->session->user_id ?? 0);
		if ($uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kid, AUTH_EDIT)) {
			// Previous 26 weeks (weeks 27–52 ago)
			$DB->Clear();
			$prevWkResult = $DB->DataSet(
				"SELECT COUNT(mw.mundane_id) AS att, p.park_id
				 FROM ork_park p
				 LEFT JOIN (
				     SELECT a.mundane_id, a.park_id
				     FROM ork_attendance a
				     WHERE a.date >  DATE_SUB(CURDATE(), INTERVAL 52 WEEK)
				       AND a.date <= DATE_SUB(CURDATE(), INTERVAL 26 WEEK)
				       AND a.kingdom_id = {$kid} AND a.mundane_id > 0
				     GROUP BY date_year, date_week3, mundane_id, a.park_id
				 ) mw ON p.park_id = mw.park_id
				 WHERE p.kingdom_id = {$kid} AND p.active = 'Active'
				 GROUP BY p.park_id"
			);
			if ($prevWkResult) {
				while ($prevWkResult->Next()) {
					$pid = (int)$prevWkResult->park_id;
					if (isset($result[$pid])) $result[$pid]['prev_att'] = (int)$prevWkResult->att;
				}
			}
			// Previous 12 months (months 13–24 ago)
			$DB->Clear();
			$prevMoResult = $DB->DataSet(
				"SELECT COUNT(mm.mundane_id) AS mo, mm.park_id
				 FROM (
				     SELECT a.mundane_id, a.date_year, a.date_month, a.park_id
				     FROM ork_attendance a
				     INNER JOIN ork_park p ON p.park_id = a.park_id AND p.kingdom_id = {$kid}
				     WHERE a.date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
				       AND a.date <  DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
				       AND a.mundane_id > 0
				     GROUP BY a.date_year, a.date_month, a.mundane_id, a.park_id
				 ) mm
				 GROUP BY mm.park_id"
			);
			if ($prevMoResult) {
				while ($prevMoResult->Next()) {
					$pid = (int)$prevMoResult->park_id;
					if (isset($result[$pid])) $result[$pid]['prev_mo'] = (int)$prevMoResult->mo;
				}
			}
		}
		header('Content-Type: application/json');
		echo json_encode($result);
		exit();
	}

	public function players_json($kingdom_id = null) {
		$kingdom_id = preg_replace('/[^0-9]/', '', $kingdom_id);
		$kid = (int)$kingdom_id;
		global $DB;
		$kpSql = "SELECT m.mundane_id, m.persona, m.has_image, m.has_heraldry,
				COALESCE(sub.last_signin, '1970-01-01') AS last_signin,
				COALESCE(sub.signin_count, 0)           AS signin_count,
				c.name                                  AS last_class,
				hp.name                                 AS park_name,
				GROUP_CONCAT(DISTINCT o.role ORDER BY o.role SEPARATOR ', ') AS officer_roles
			FROM ork_mundane m
			INNER JOIN ork_park hp ON hp.park_id = m.park_id AND hp.kingdom_id = {$kid}
			LEFT JOIN (
				SELECT a.mundane_id,
					MAX(a.date) AS last_signin,
					SUM(a.date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)) AS signin_count
				FROM ork_attendance a
				INNER JOIN ork_park kp ON kp.park_id = a.park_id AND kp.kingdom_id = {$kid}
				GROUP BY a.mundane_id
			) sub ON sub.mundane_id = m.mundane_id
			LEFT JOIN ork_attendance la ON la.mundane_id = m.mundane_id AND la.date = sub.last_signin
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
				$players[] = [
					'id'          => $mid,
					'persona'     => $r->persona,
					'parkName'    => $r->park_name,
					'signinCount' => (int)$r->signin_count,
					'lastSignin'  => $r->last_signin,
					'lastClass'   => $r->last_class,
					'officerRoles'=> $r->officer_roles,
					'avatarUrl'   => $imgUrl,
					'heraldryUrl' => $herUrl,
				];
			}
		}
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
		$this->load_model('Award');
		$this->load_model('Reports');
		$this->load_model('Pronoun');

		$this->data['menu']['kingdom'] = [
			'url'     => UIR . 'Kingdom/profile/' . $kingdom_id,
			'display' => $this->session->kingdom_name,
		];

		$this->data['park_summary']        = $this->Kingdom->get_park_summary($kingdom_id);
		$this->data['principalities']      = $this->Kingdom->get_principalities($kingdom_id);
		$this->data['kingdom_info']        = $this->Kingdom->get_kingdom_shortinfo($kingdom_id);
		$this->data['kingdom_officers']    = $this->Kingdom->GetOfficers(['KingdomId' => $kingdom_id, 'Token' => $this->session->token]);
		$this->data['IsPrinz']             = $this->data['kingdom_info']['Info']['KingdomInfo']['IsPrincipality'];

		$this->data['AwardOptions']        = $this->Award->fetch_award_option_list($kingdom_id, 'Awards');
		$this->data['OfficerOptions']      = $this->Award->fetch_award_option_list($kingdom_id, 'Officers');
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

		$evtSql = "
			SELECT e.event_id, e.name, e.park_id, p.name AS park_name, p.abbreviation AS park_abbr,
			       cd.event_start, cd.event_calendardetail_id AS next_detail_id, e.has_heraldry,
			       (SELECT COUNT(*) FROM ork_event_rsvp WHERE event_calendardetail_id = cd.event_calendardetail_id) AS rsvp_count
			FROM ork_event e
			LEFT JOIN ork_park p ON p.park_id = e.park_id
			JOIN ork_event_calendardetail cd ON cd.event_id = e.event_id
			    AND cd.event_start >= DATE_SUB(NOW(), INTERVAL 7 DAY)
			    AND cd.event_start <= DATE_ADD(NOW(), INTERVAL 12 MONTH)
			WHERE e.kingdom_id = {$kid}
			ORDER BY cd.event_start, p.name, e.name";
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
					'RsvpCount'    => (int)$evtResult->rsvp_count,
					'_IsParkEvent' => (int)$evtResult->park_id > 0,
				];
			}
		}
		$this->data['event_summary'] = $eventSummary;

		$pdSql = "
			SELECT pd.parkday_id, pd.park_id, pd.recurrence, pd.week_day,
			       pd.week_of_month, pd.month_day, pd.time, pd.purpose, p.name AS park_name, p.abbreviation AS park_abbr
			FROM ork_parkday pd
			JOIN ork_park p ON p.park_id = pd.park_id
			WHERE p.kingdom_id = {$kid}
			  AND p.active = 'Active'
			ORDER BY p.name, pd.week_day, pd.time";
		$pdResult = $DB->DataSet($pdSql);
		$parkDays = [];
		if ($pdResult && $pdResult->Size() > 0) {
			while ($pdResult->Next()) {
				switch ($pdResult->recurrence) {
					case 'weekly':       $recText = 'Every ' . $pdResult->week_day; break;
					case 'week-of-month': $recText = 'Every ' . (int)$pdResult->week_of_month . '. ' . $pdResult->week_day; break;
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
			$upRow = $DB->DataSet("SELECT park_id FROM " . DB_PREFIX . "mundane WHERE mundane_id = $uid LIMIT 1");
			if ($upRow && $upRow->Next() && $upRow->park_id) {
				$this->data['UserParkId'] = (int)$upRow->park_id;
			}
		}
		$this->data['CanManageKingdom'] = $uid > 0
			&& Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, (int)$kingdom_id, AUTH_CREATE);
		$this->data['CanAddPark'] = $uid > 0
			&& Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, (int)$kingdom_id, AUTH_CREATE);

		$knConfigs  = Common::get_configs($kingdom_id, CFG_KINGDOM);
		$recsPublic = isset($knConfigs['AwardRecsPublic'])
			? (bool)(int)$knConfigs['AwardRecsPublic']['Value']
			: true;
		$this->data['AwardRecsPublic'] = $recsPublic;

		$this->data['AwardRecommendations'] = [];
		$canManageKingdom = $this->data['CanManageKingdom'] ?? false;
		if ($recsPublic || $canManageKingdom) {
			$this->data['ShowRecsTab'] = true;
			$recs = $this->Reports->recommended_awards(['KingdomId' => $kingdom_id, 'ParkId' => 0, 'PlayerId' => 0]);
			$this->data['AwardRecommendations'] = is_array($recs) ? $recs : [];
		} elseif ($uid > 0) {
			$recs = $this->Reports->recommended_awards(['KingdomId' => $kingdom_id, 'ParkId' => 0, 'PlayerId' => 0]);
			$allRecs = is_array($recs) ? $recs : [];
			$myRecs = array_values(array_filter($allRecs, function($r) use ($uid) {
				return (int)$r['RecommendedById'] === $uid;
			}));
			$this->data['AwardRecommendations'] = $myRecs;
			$this->data['ShowRecsTab'] = !empty($myRecs);
		} else {
			$this->data['ShowRecsTab'] = false;
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

			$this->data['AdminInfo'] = [
				'Name'         => $kd['KingdomInfo']['KingdomName']  ?? '',
				'Abbreviation' => $kd['KingdomInfo']['Abbreviation'] ?? '',
			];

			$adminConfig = [];
			foreach ($kd['KingdomConfiguration'] ?? [] as $cfg) {
				if (!empty($cfg['UserSetting'])) {
					$adminConfig[] = $cfg;
				}
			}
			$this->data['AdminConfig']     = $adminConfig;
			$this->data['AdminParkTitles'] = array_values($kd['ParkTitles'] ?? []);

			$rawAwards   = $kd['Awards']['Awards'] ?? [];
			$adminAwards = [];
			foreach ($rawAwards as $kawId => $aw) {
				$adminAwards[] = [
					'KingdomAwardId'   => (int)$kawId,
					'KingdomAwardName' => $aw['KingdomAwardName']  ?? '',
					'ReignLimit'       => (int)($aw['ReignLimit']  ?? 0),
					'MonthLimit'       => (int)($aw['MonthLimit']  ?? 0),
					'IsTitle'          => (int)($aw['IsTitle']     ?? 0),
					'TitleClass'       => (int)($aw['TitleClass']  ?? 0),
				];
			}
			$this->data['AdminAwards'] = $adminAwards;
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
			WHERE e.kingdom_id = {$kid}
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
			while ($result->Next()) {
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
					$lines[] = self::ics_fold('URL:' . $result->url);
				}
				$lines[] = 'END:VEVENT';
			}
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
