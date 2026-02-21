<?php

class Controller_Playernew extends Controller {

	public function __construct($call=null, $id=null) {
		parent::__construct($call, $id);

		$this->load_model('Player');
		$this->load_model('Park');
		$this->load_model('Pronoun');
		$this->load_model('Award');
		$this->load_model('Reports');
		$params = explode('/',$id);
		$id = $params[0];

		$this->data['Player'] = $this->Player->fetch_player($id);

		$park_info = $this->Park->get_park_info($this->data['Player']['ParkId']);
		$this->session->park_name = $park_info['ParkInfo']['ParkName'];
		$this->session->park_id = $park_info['ParkInfo']['ParkId'];
		$this->session->kingdom_id = $park_info['KingdomInfo']['KingdomId'];
		$this->session->kingdom_name = $park_info['KingdomInfo']['KingdomName'];
		if ($this->data['LoggedIn']) {
			$this->data['menu']['admin'] = array( 'url' => UIR.'Admin/player/'.$id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		}
		$this->data['menulist']['admin'] = array(
				array( 'url' => UIR.'Admin/player/'.$id, 'display' => 'Player' ),
				array( 'url' => UIR.'Admin/park/'.$this->session->park_id, 'display' => 'Park' ),
				array( 'url' => UIR.'Admin/kingdom/'.$this->session->kingdom_id, 'display' => 'Kingdom' )
			);
		if (valid_id($this->session->kingdom_id)) {
			$this->data['menu']['kingdom'] = array( 'url' => UIR.'Kingdom/index/'.$this->session->kingdom_id, 'display' => $this->session->kingdom_name );
			$this->data['menu']['park'] = array( 'url' => UIR.'Park/index/'.$this->session->park_id, 'display' => $this->session->park_name );
		} else {
			unset($this->data['menu']['kingdom']);
			unset($this->data['menu']['park']);
		}
		$this->data['menu']['player'] = array( 'url' => UIR."Playernew/$call/$id", 'display' => $this->data['Player']['Persona'] );
		$this->data['page_title'] = $this->data['Player']['Persona'];

	}

	public function index($id = null) {
		$this->load_model('Unit');
		$this->load_model('Kingdom');

		$params = explode('/',$id);
		$id       = $params[0];
		$action   = isset($params[1]) ? $params[1] : '';
		$roastbeef = isset($params[2]) ? $params[2] : '';

		// Handle POST actions — redirect back to Playernew on completion
		if (strlen($action) > 0) {
			$this->request->save('Playernew_index', true);
			$r = array('Status' => 0);
			if (!isset($this->session->user_id)) {
				header('Location: ' . UIR . "Login/login/Playernew/index/$id");
				exit;
			} else {
				switch ($action) {
					case 'addrecommendation':
						$r = $this->Player->add_player_recommendation(array(
							'Token'          => $this->session->token,
							'MundaneId'      => $id,
							'KingdomAwardId' => $this->request->Playernew_index->KingdomAwardId,
							'Rank'           => $this->request->Playernew_index->Rank,
							'Reason'         => $this->request->Playernew_index->Reason
						));
						break;
					case 'deleterecommendation':
						$r = $this->Player->delete_player_recommendation(array(
							'Token'             => $this->session->token,
							'RecommendationsId' => $roastbeef,
							'RequestedBy'       => $this->session->user_id
						));
						break;
					case 'quitunit':
						$r = $this->Unit->retire_unit_member(array(
							'UnitMundaneId' => $roastbeef,
							'UnitId'        => $id,
							'Token'         => $this->session->token
						));
						break;
				}
				if ($r['Status'] == 0) {
					$this->data['Message'] = $r['Detail'] ? $r['Detail'] : 'Updated successfully.';
					$this->request->clear('Playernew_index');
				} else if ($r['Status'] == 5) {
					header('Location: ' . UIR . "Login/login/Playernew/index/$id");
					exit;
				} else {
					$this->data['Error'] = $r['Error'] . ': ' . $r['Detail'];
				}
			}
		}

		$this->data['LoggedIn'] = isset($this->session->user_id);
		$this->data['KingdomId'] = $this->session->kingdom_id;
		$this->data['AwardOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Awards');
		$this->data['OfficerOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Officers');
		$this->data['Player'] = $this->Player->fetch_player($id);
		$this->data['Player']['LastSignInDate'] = $this->Player->get_latest_attendance_date($id);
		$this->data['PronounOptions'] = $this->Pronoun->fetch_pronoun_option_list($this->data['Player']['PronounId']);
		$this->data['Details'] = $this->Player->fetch_player_details($id);
    	$this->data['Notes'] = $this->Player->get_notes($id);
    	$this->data['Dues'] = $this->Player->get_dues($id, 1, true);
		$this->data['Units'] = $this->Unit->get_unit_list(array( 'MundaneId' => $id, 'IncludeCompanies' => 1, 'IncludeHouseHolds' =>1, 'IncludeEvents' => 1, 'ActiveOnly' => 1 ));
		$this->data['AwardRecommendations'] = $this->Reports->recommended_awards(array('PlayerId'=>$id, 'KingdomId'=>0, 'ParkId'=>0, 'IncludeKnights' => 1, 'IncludeMasters' => 1, 'IncludeLadder' => 1, 'LadderMinimum' => 0));

		// Current officer positions for this player
		global $DB;
		$playerParkId = (int)$this->data['Player']['ParkId'];
		$officerSql = "SELECT o.role, o.park_id,
			CASE WHEN o.park_id > 0 THEN IFNULL(pt.title, 'Park') ELSE 'Kingdom' END AS entity_type,
			CASE WHEN o.park_id > 0 THEN p.name ELSE k.name END AS entity_name
			FROM ork_officer o
			LEFT JOIN ork_kingdom k ON o.kingdom_id = k.kingdom_id
			LEFT JOIN ork_park p ON o.park_id = p.park_id AND o.park_id > 0
			LEFT JOIN ork_parktitle pt ON p.parktitle_id = pt.parktitle_id
			WHERE o.mundane_id = " . (int)$id . "
			  AND (o.park_id = $playerParkId OR o.park_id = 0)
			ORDER BY o.park_id DESC, o.role";
		$officerResult = $DB->DataSet($officerSql);
		$officerRoles = array();
		if ($officerResult->Size() > 0) {
			while ($officerResult->Next()) {
				$officerRoles[] = array(
					'role'        => $officerResult->role,
					'entity_type' => $officerResult->entity_type,
					'entity_name' => $officerResult->entity_name,
				);
			}
		}
		$this->data['OfficerRoles'] = $officerRoles;

		// Check if this player is a top-level ORK Administrator
		$adminCheck = $DB->DataSet(
			"SELECT 1 FROM ork_authorization
			 WHERE mundane_id = " . (int)$id . "
			   AND role = 'admin'
			   AND park_id = 0 AND kingdom_id = 0 AND event_id = 0 AND unit_id = 0
			 LIMIT 1"
		);
		$this->data['IsOrkAdmin'] = ($adminCheck && $adminCheck->Size() > 0);

		// Pre-compute summary stats
		$this->data['Stats'] = array(
			'TotalAttendance' => is_array($this->data['Details']['Attendance']) ? count($this->data['Details']['Attendance']) : 0,
			'TotalAwards' => 0,
			'TotalTitles' => 0,
			'HighestClassLevel' => 0,
		);
		if (is_array($this->data['Details']['Awards'])) {
			foreach ($this->data['Details']['Awards'] as $a) {
				if (in_array($a['OfficerRole'], ['none', null]) && $a['IsTitle'] != 1) {
					$this->data['Stats']['TotalAwards']++;
				} else {
					$this->data['Stats']['TotalTitles']++;
				}
			}
		}
		if (is_array($this->data['Details']['Classes'])) {
			foreach ($this->data['Details']['Classes'] as $c) {
				$credits = $c['Credits'] + $c['Reconciled'];
				if ($credits >= 53) $lvl = 6;
				else if ($credits >= 34) $lvl = 5;
				else if ($credits >= 21) $lvl = 4;
				else if ($credits >= 12) $lvl = 3;
				else if ($credits >= 5) $lvl = 2;
				else $lvl = 1;
				if ($lvl > $this->data['Stats']['HighestClassLevel'])
					$this->data['Stats']['HighestClassLevel'] = $lvl;
			}
		}

		// Preload Kingdom and Park Monarch/Regent for GivenBy autocomplete
		$preloadOfficers = array();
		$kingdomOfficers = $this->Kingdom->get_officers($this->session->kingdom_id, $this->session->token);
		if (is_array($kingdomOfficers)) {
			foreach ($kingdomOfficers as $officer) {
				if (in_array($officer['OfficerRole'], array('Monarch', 'Regent')) && $officer['MundaneId'] > 0) {
					$preloadOfficers[] = array('MundaneId' => $officer['MundaneId'], 'Persona' => $officer['Persona'], 'Role' => 'Kingdom ' . $officer['OfficerRole']);
				}
			}
		}
		$parkId = $this->data['Player']['ParkId'];
		if (valid_id($parkId)) {
			$parkOfficers = $this->Park->get_officers($parkId, $this->session->token);
			if (is_array($parkOfficers)) {
				foreach ($parkOfficers as $officer) {
					if (in_array($officer['OfficerRole'], array('Monarch', 'Regent')) && $officer['MundaneId'] > 0) {
						$preloadOfficers[] = array('MundaneId' => $officer['MundaneId'], 'Persona' => $officer['Persona'], 'Role' => 'Park ' . $officer['OfficerRole']);
					}
				}
			}
		}
		$this->data['PreloadOfficers'] = $preloadOfficers;

		$this->data['menu']['player'] = array( 'url' => UIR."Playernew/index/$id", 'display' => $this->data['Player']['Persona'] );
	}

}
