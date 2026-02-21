<?php

class Controller_Kingdomnew extends Controller {

	public function __construct($call=null, $id=null) {
		parent::__construct($call, $id);
		$this->load_model('Kingdom');
		$this->load_model('Award');
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
			$this->session->kingdom_name = $this->Kingdom->get_kingdom_name($id);
		}
		$this->data['kingdom_name'] = $this->session->kingdom_name;
		$this->data['page_title']   = $this->session->kingdom_name;

		unset($this->session->park_id);
		unset($this->session->park_name);

		if ($this->data['LoggedIn']) {
			$this->data['menu']['admin'] = array(
				'url'      => UIR . 'Admin/kingdom/' . $this->session->kingdom_id,
				'display'  => 'Admin Panel <i class="fas fa-cog"></i>',
				'no-crumb' => 'no-crumb'
			);
		}
		$this->data['menu']['kingdom'] = array(
			'url'     => UIR . 'Kingdomnew/index/' . $this->session->kingdom_id,
			'display' => $this->session->kingdom_name
		);
		$this->data['menulist']['admin'] = array(
			array('url' => UIR . 'Admin/kingdom/' . $this->session->kingdom_id, 'display' => 'Kingdom')
		);
		unset($this->data['menu']['park']);
	}

	public function index($kingdom_id = null) {
		$kingdom_id = preg_replace('/[^0-9]/', '', $kingdom_id);
		$this->load_model('Reports');
		$this->data['park_summary']        = $this->Kingdom->get_park_summary($kingdom_id);
		$this->data['principalities']      = $this->Kingdom->get_principalities($kingdom_id);
		$this->data['kingdom_info']        = $this->Kingdom->get_kingdom_shortinfo($kingdom_id);
		$this->data['kingdom_officers']    = $this->Kingdom->GetOfficers(['KingdomId' => $kingdom_id, 'Token' => $this->session->token]);
		$this->data['IsPrinz']             = $this->data['kingdom_info']['Info']['KingdomInfo']['IsPrincipality'];

		$this->data['AwardOptions']   = $this->Award->fetch_award_option_list($kingdom_id, 'Awards');
		$this->data['OfficerOptions'] = $this->Award->fetch_award_option_list($kingdom_id, 'Officers');
		$preloadOfficers = [];
		foreach ($this->data['kingdom_officers']['Officers'] ?? [] as $o) {
			if (in_array($o['OfficerRole'], ['Monarch', 'Regent']) && (int)$o['MundaneId'] > 0)
				$preloadOfficers[] = ['MundaneId' => $o['MundaneId'], 'Persona' => $o['Persona'], 'Role' => $o['OfficerRole']];
		}
		$this->data['PreloadOfficers'] = $preloadOfficers;
		$this->data['kingdom_tournaments'] = $this->Reports->get_tournaments(null, $kingdom_id);
		$rawParks = $this->Kingdom->GetParks(['KingdomId' => $kingdom_id]);
		$this->data['map_parks'] = is_array($rawParks['Parks'])
			? array_values(array_filter($rawParks['Parks'], function($p) { return $p['Active'] == 'Active'; }))
			: [];

		global $DB;
		$kid = (int)$kingdom_id;

		// All upcoming events for this kingdom (kingdom-level + park-level), no service limit
		$evtSql = "
			SELECT e.event_id, e.name, e.park_id, p.name AS park_name, cd.event_start, cd.event_calendardetail_id AS next_detail_id, e.has_heraldry
			FROM ork_event e
			LEFT JOIN ork_park p ON p.park_id = e.park_id
			LEFT JOIN ork_event_calendardetail cd ON cd.event_id = e.event_id AND cd.current = 1
			WHERE e.kingdom_id = {$kid}
			  AND (cd.current = 1 OR cd.current IS NULL)
			  AND cd.event_start IS NOT NULL
			  AND cd.event_start > DATE_SUB(NOW(), INTERVAL 7 DAY)
			ORDER BY cd.event_start, p.name, e.name";
		$evtResult = $DB->DataSet($evtSql);
		$eventSummary = [];
		$evtSeen = [];
		if ($evtResult && $evtResult->Size() > 0) {
			while ($evtResult->Next()) {
				$eid = (int)$evtResult->event_id;
				if (!isset($evtSeen[$eid])) {
					$evtSeen[$eid] = true;
					$eventSummary[] = [
						'EventId'      => $eid,
						'Name'         => $evtResult->name,
						'ParkName'     => $evtResult->park_name,
						'NextDate'     => $evtResult->event_start,
						'NextDetailId' => (int)$evtResult->next_detail_id,
						'HasHeraldry'  => (int)$evtResult->has_heraldry,
						'_IsParkEvent' => (int)$evtResult->park_id > 0,
					];
				}
			}
		}
		$this->data['event_summary'] = $eventSummary;

		// Per-park distinct player counts over the past 12 months
		$pcSql = "
			SELECT
				a.park_id,
				COUNT(DISTINCT a.mundane_id)                                                        AS total_players,
				COUNT(DISTINCT CASE WHEN m.park_id = a.park_id THEN a.mundane_id END)               AS total_members
			FROM ork_attendance a
			INNER JOIN ork_park p  ON p.park_id  = a.park_id  AND p.kingdom_id = {$kid}
			INNER JOIN ork_mundane m ON m.mundane_id = a.mundane_id AND m.suspended = 0 AND m.active = 1
			WHERE a.date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
			  AND a.mundane_id > 0
			GROUP BY a.park_id";
		$pcResult = $DB->DataSet($pcSql);
		$parkPlayerCounts = [];
		if ($pcResult && $pcResult->Size() > 0) {
			while ($pcResult->Next()) {
				$parkPlayerCounts[(int)$pcResult->park_id] = [
					'TotalPlayers' => (int)$pcResult->total_players,
					'TotalMembers' => (int)$pcResult->total_members,
				];
			}
		}
		$this->data['park_player_counts'] = $parkPlayerCounts;

		// Kingdom player roster: home-park members who have attended at least once in this kingdom
		$kid = (int)$kingdom_id;
		$kpSql = "
			SELECT
				m.mundane_id,
				m.persona,
				m.has_image,
				m.has_heraldry,
				COALESCE(sub.last_signin, '1970-01-01') AS last_signin,
				COALESCE(sub.signin_count, 0)           AS signin_count,
				c.name                                  AS last_class,
				hp.name                                 AS park_name,
				GROUP_CONCAT(DISTINCT o.role ORDER BY o.role SEPARATOR ', ') AS officer_roles
			FROM ork_mundane m
			INNER JOIN ork_park hp ON hp.park_id = m.park_id AND hp.kingdom_id = {$kid}
			LEFT JOIN (
				SELECT
					a.mundane_id,
					MAX(a.date) AS last_signin,
					SUM(a.date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)) AS signin_count
				FROM ork_attendance a
				INNER JOIN ork_park kp ON kp.park_id = a.park_id AND kp.kingdom_id = {$kid}
				GROUP BY a.mundane_id
			) sub ON sub.mundane_id = m.mundane_id
			LEFT JOIN ork_attendance la ON la.mundane_id = m.mundane_id AND la.date = sub.last_signin
			LEFT JOIN ork_class c ON la.class_id = c.class_id
			LEFT JOIN ork_officer o ON o.mundane_id = m.mundane_id AND o.park_id = m.park_id
			WHERE m.suspended = 0
			  AND m.active = 1
			  AND sub.mundane_id IS NOT NULL
			GROUP BY m.mundane_id
			ORDER BY m.persona";
		$kpResult = $DB->DataSet($kpSql);
		$kingdomPlayers = [];
		if ($kpResult && $kpResult->Size() > 0) {
			while ($kpResult->Next()) {
				$kingdomPlayers[] = [
					'MundaneId'    => (int)$kpResult->mundane_id,
					'Persona'      => $kpResult->persona,
					'HasImage'     => (int)$kpResult->has_image > 0,
					'HasHeraldry'  => (int)$kpResult->has_heraldry > 0,
					'SigninCount'  => (int)$kpResult->signin_count,
					'LastSignin'   => $kpResult->last_signin,
					'LastClass'    => $kpResult->last_class,
					'ParkName'     => $kpResult->park_name,
					'OfficerRoles' => $kpResult->officer_roles,
				];
			}
		}
		$this->data['kingdom_players'] = $kingdomPlayers;

		$uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
		$this->data['CanManageKingdom'] = $uid > 0
			&& Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, (int)$kingdom_id, AUTH_CREATE);
	}

}

?>
