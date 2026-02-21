<?php

class Controller_Parknew extends Controller
{
	public function __construct( $call = null, $id = null )
	{
		parent::__construct( $call, $id );
		$this->load_model('Park');
		$this->load_model('Award');
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
			$this->session->park_name   = $park_info['ParkInfo']['ParkName'];
			$this->session->kingdom_id  = $park_info['KingdomInfo']['KingdomId'];
			$this->session->kingdom_name = $park_info['KingdomInfo']['KingdomName'];
		}
		$this->data['kingdom_id']   = $this->session->kingdom_id;
		$this->data['park_id']      = $this->session->park_id;
		$this->data['kingdom_name'] = $this->session->kingdom_name;

		if ( isset( $this->request->park_name ) ) {
			$this->session->park_name = $this->request->park_name;
		}
		$this->data['park_name']  = $this->session->park_name;
		$this->data['page_title'] = $this->session->park_name;

		if ($this->data['LoggedIn']) {
			$this->data['menu']['admin'] = [
				'url'      => UIR . 'Admin/park/' . $this->session->park_id,
				'display'  => 'Admin Panel <i class="fas fa-cog"></i>',
				'no-crumb' => 'no-crumb'
			];
		}
		$this->data['menulist']['admin'] = [
			[ 'url' => UIR . 'Admin/park/'    . $this->session->park_id,    'display' => 'Park' ],
			[ 'url' => UIR . 'Admin/kingdom/' . $this->session->kingdom_id, 'display' => 'Kingdom' ],
		];
		$this->data['menu']['kingdom'] = [
			'url'     => UIR . 'Kingdom/index/' . $this->session->kingdom_id,
			'display' => $this->session->kingdom_name
		];
		$this->data['menu']['park'] = [
			'url'     => UIR . 'Parknew/index/' . $this->session->park_id,
			'display' => $this->session->park_name
		];
	}

	public function index( $park_id = null )
	{
		$park_id = preg_replace('/[^0-9]/', '', $park_id);
		$this->load_model('Reports');
		$this->data['event_summary']    = $this->Park->get_park_events( $park_id );
		$this->data['park_days']        = $this->Park->get_park_parkdays( $park_id );
		$this->data['park_info']        = $this->Park->get_park_details( $park_id );
		$this->data['park_officers']    = $this->Park->GetOfficers(['ParkId' => $park_id, 'Token' => $this->session->token]);
		$this->data['park_tournaments'] = $this->Reports->get_tournaments( null, null, $park_id );

		$this->data['AwardOptions']   = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Awards');
		$this->data['OfficerOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Officers');
		$preloadOfficers = [];
		foreach ($this->data['park_officers']['Officers'] ?? [] as $o) {
			if (in_array($o['OfficerRole'], ['Monarch', 'Regent']) && (int)$o['MundaneId'] > 0)
				$preloadOfficers[] = ['MundaneId' => $o['MundaneId'], 'Persona' => $o['Persona'], 'Role' => $o['OfficerRole']];
		}
		$this->data['PreloadOfficers'] = $preloadOfficers;

		// All park members who have ever signed in here; signin_count = past-6-months only
		global $DB;
		$pid = (int)$park_id;
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
		$rosterResult = $DB->DataSet($rosterSql);
		$parkPlayers = [];
		if ($rosterResult && $rosterResult->Size() > 0) {
			while ($rosterResult->Next()) {
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
			}
		}
		$this->data['park_players'] = $parkPlayers;

		$uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
		$this->data['CanManagePark'] = $uid > 0
			&& Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, (int)$park_id, AUTH_CREATE);
	}
}

?>
