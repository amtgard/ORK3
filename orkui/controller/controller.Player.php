<?php

class Controller_Player extends Controller {

	public function __construct($call=null, $id=null) {
		parent::__construct($call, $id);

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
		$this->data['menu']['player'] = array( 'url' => UIR."Player/$call/$id", 'display' => $this->data['Player']['Persona'] );
		$this->data['page_title'] = $this->data['Player']['Persona'];

	}

	public function index($id = null) {
		$this->load_model('Unit');

		$params = explode('/',$id);
		$id = $params[0];
		if (count($params) > 1)
			$action = $params[1];
		if (count($params) > 2)
			$roastbeef = $params[2];

		if (strlen($action) > 0) {
			$this->request->save('Player_index', true);
			$r = array('Status'=>0);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR."Login/login/Player/index/$id" );
			} else {
				switch ($action) {
					case 'updateclasses':
						$class_update = array();
						if (is_array($this->request->Reconciled)) {
							foreach ($this->request->Reconciled as $class_id => $qty) {
								$class_update[] = array( 'ClassId' => $class_id, 'Quantity' => $qty );
							}
							$this->Player->update_class_reconciliation(array( 'Token' => $this->session->token, 'MundaneId' => $id, 'Reconcile' => $class_update ));
						}
						break;
					case 'update':
						if ($_FILES['Heraldry']['size'] > 0 && Common::supported_mime_types($_FILES['Heraldry']['type'])) {
							if (move_uploaded_file($_FILES['Heraldry']['tmp_name'], DIR_TMP . sprintf("h_%06d", $id))) {
								$h_im = file_get_contents(DIR_TMP . sprintf("h_%06d", $id));
								$h_imdata = base64_encode($h_im);
							}
						}
						if ($_FILES['Waiver']['size'] > 0 && Common::supported_mime_types($_FILES['Waiver']['type'])) {
							if (move_uploaded_file($_FILES['Waiver']['tmp_name'], DIR_TMP . sprintf("w_%06d", $id))) {
								$w_im = file_get_contents(DIR_TMP . sprintf("w_%06d", $id));
								$w_imdata = base64_encode($w_im);
							}
						}
						$r = $this->Player->update_player(array(
								'MundaneId' => $id,
								'GiveName' =>  $this->request->Player_index->GivenName,
								'Surname' =>  $this->request->Player_index->Surname,
								'Persona' =>  $this->request->Player_index->Persona,
								'PronounId' =>  $this->request->Player_index->PronounId,
								'UserName' =>  $this->request->Player_index->UserName,
								'Password' =>  $this->request->Player_index->Password==$this->request->Player_index->PasswordAgain?$this->request->Player_index->Password:null,
								'Email' =>  $this->request->Player_index->Email,
								'Restricted' =>  $this->request->Player_index->Restricted=='Restricted'?1:0,
								'Active' =>  $this->request->Player_index->Active=='Active'?1:0,
								'HasImage' => strlen($pi_imdata),
								'Image' => strlen($pi_imdata)>0?$pi_imdata:null,
								'ImageMimeType' => strlen($pi_imdata)>0?$_FILES['PlayerImage']['type']:'',
								'Heraldry' => strlen($h_imdata)>0?$h_imdata:null,
								'HeraldryMimeType' => strlen($h_imdata)>0?$_FILES['Heraldry']['type']:'',
								'Waivered' => strlen($w_imdata),
								'Waiver' => strlen($w_imdata)>0?$w_imdata:null,
								'WaiverMimeType' => strlen($w_imdata)>0?$_FILES['Waiver']['type']:'',
								'Token' => $this->session->token
							));
						if ($this->request->Player_index->Password!=$this->request->Player_index->PasswordAgain)
							$this->data['Error'] = 'Passwords do not match.';
						break;
					case 'addaward':
						$r = $this->Player->add_player_award(array(
								'Token' => $this->session->token,
								'RecipientId' => $id,
								'AwardId' => $this->request->Player_index->AwardId,
								'Rank' => $this->request->Player_index->Rank,
								'Date' => $this->request->Player_index->Date,
								'GivenById' => $this->request->Player_index->MundaneId,
								'Note' => $this->request->Player_index->Note,
								'ParkId' => valid_id($this->request->Player_index->ParkId)?$this->request->Player_index->ParkId:0,
								'KingdomId' => valid_id($this->request->Player_index->KingdomId)?$this->request->Player_index->KingdomId:0,
								'EventId' => valid_id($this->request->Player_index->EventId)?$this->request->Player_index->EventId:0
							));
						break;
					case 'deleteaward':
						$r = $this->Player->delete_player_award(array(
								'Token' => $this->session->token,
								'AwardsId' => $roastbeef
							));
						break;
					case 'updateaward':
						$r = $this->Player->update_player_award(array(
								'Token' => $this->session->token,
								'AwardsId' => $roastbeef,
								'RecipientId' => $id,
								'AwardId' => $this->request->Player_index->AwardId,
								'Rank' => $this->request->Player_index->Rank,
								'Date' => $this->request->Player_index->Date,
								'GivenById' => $this->request->Player_index->MundaneId,
								'Note' => $this->request->Player_index->Note,
								'ParkId' => valid_id($this->request->Player_index->ParkId)?$this->request->Player_index->ParkId:0,
								'KingdomId' => valid_id($this->request->Player_index->KingdomId)?$this->request->Player_index->KingdomId:0,
								'EventId' => valid_id($this->request->Player_index->EventId)?$this->request->Player_index->EventId:0
							));
						break;
					case 'addrecommendation':
						$r = $this->Player->add_player_recommendation(array(
								'Token' => $this->session->token,
								'MundaneId' => $id,
								'KingdomAwardId' => $this->request->Player_index->KingdomAwardId,
								'Rank' => $this->request->Player_index->Rank,
								'GivenById' => $this->request->Player_index->MundaneId,
								'Reason' => $this->request->Player_index->Reason
							));
						break;
					case 'deleterecommendation':
						$r = $this->Player->delete_player_recommendation(array(
								'Token' => $this->session->token,
								'RecommendationsId' => $roastbeef,
								'RequestedBy' => $this->session->user_id
							));
						break;
				}
				if ($r['Status'] == 0) {
					if ($r['Detail']) {
						$this->data['Message'] = $r['Detail'];
					} else {
						$this->data['Message'] = 'Player has been updated.';
					}
					$this->request->clear('Player_index');
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR."Login/login/Player/index/$id" );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}

		if ($this->request->exists('Player_index')) {
			$this->data['Player_index'] = $this->request->Player_index->Request;
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
		if ($this->data['LoggedIn']) {
			$this->data['menu']['admin'] = array( 'url' => UIR."Admin/player/$id", 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		}
		$this->data['menu']['player'] = array( 'url' => UIR."Player/index/$id", 'display' => $this->data['Player']['Persona'] );
		$this->data['AwardRecommendations'] = $this->Reports->recommended_awards(array('PlayerId'=>$id, 'KingdomId'=>0, 'ParkId'=>0, 'IncludeKnights' => 1, 'IncludeMasters' => 1, 'IncludeLadder' => 1, 'LadderMinimum' => $ladder));

		// Preload Kingdom and Park Monarch/Regent for GivenBy autocomplete
		$this->load_model('Kingdom');
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

	}

	public function profile( $id = null ) {
		$this->template = '../revised-frontend/Playernew_index.tpl';
		$this->load_model('Unit');
		$this->load_model('Kingdom');

		$params    = explode('/', $id);
		$id        = $params[0];
		$action    = $params[1] ?? '';
		$roastbeef = $params[2] ?? '';

		$this->data['menu']['kingdom'] = ['url' => UIR . 'Kingdom/profile/' . $this->session->kingdom_id, 'display' => $this->session->kingdom_name];
		$this->data['menu']['park']    = ['url' => UIR . 'Park/profile/'    . $this->session->park_id,    'display' => $this->session->park_name];

		if (strlen($action) > 0) {
			$this->request->save('Player_profile', true);
			$r = ['Status' => 0];
			if (!isset($this->session->user_id)) {
				header('Location: ' . UIR . "Login/login/Player/profile/$id");
				exit;
			} else {
				switch ($action) {
					case 'addrecommendation':
						$r = $this->Player->add_player_recommendation([
							'Token'          => $this->session->token,
							'MundaneId'      => $id,
							'KingdomAwardId' => $this->request->Player_profile->KingdomAwardId,
							'Rank'           => $this->request->Player_profile->Rank,
							'Reason'         => $this->request->Player_profile->Reason,
						]);
						$this->request->clear('Player_profile');
						if ($r['Status'] == 0) {
							header('Location: ' . UIR . "Player/profile/{$id}");
						} elseif ($r['Status'] == 5) {
							header('Location: ' . UIR . "Login/login/Player/profile/$id");
						} else {
							$msg = urlencode($r['Error'] . ': ' . $r['Detail']);
							header('Location: ' . UIR . "Player/profile/{$id}&rec_error={$msg}");
						}
						exit;
					case 'deleterecommendation':
						$r = $this->Player->delete_player_recommendation([
							'Token'             => $this->session->token,
							'RecommendationsId' => $roastbeef,
							'RequestedBy'       => $this->session->user_id,
						]);
						$this->request->clear('Player_profile');
						if ($r['Status'] == 5) {
							header('Location: ' . UIR . "Login/login/Player/profile/$id");
						} else {
							header('Location: ' . UIR . "Player/profile/{$id}");
						}
						exit;
					case 'quitunit':
						$r = $this->Unit->retire_unit_member([
							'UnitMundaneId' => $roastbeef,
							'UnitId'        => $id,
							'Token'         => $this->session->token,
						]);
						break;
				}
				if ($r['Status'] == 0) {
					$this->data['Message'] = $r['Detail'] ?: 'Updated successfully.';
					$this->request->clear('Player_profile');
				} elseif ($r['Status'] == 5) {
					header('Location: ' . UIR . "Login/login/Player/profile/$id");
					exit;
				} else {
					$this->data['Error'] = $r['Error'] . ': ' . $r['Detail'];
				}
			}
		}

		$this->data['LoggedIn']      = isset($this->session->user_id);
		$this->data['KingdomId']     = $this->session->kingdom_id;
		$this->data['AwardOptions']  = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Awards');
		$this->data['OfficerOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Officers');
		$this->data['Player']        = $this->Player->fetch_player($id);
		$this->data['Player']['LastSignInDate'] = $this->Player->get_latest_attendance_date($id);
		$this->data['PronounOptions'] = $this->Pronoun->fetch_pronoun_option_list($this->data['Player']['PronounId']);
		$this->data['Details']       = $this->Player->fetch_player_details($id);
		$this->data['Notes']         = $this->Player->get_notes($id);
		$this->data['Dues']          = $this->Player->get_dues($id, 1, true);
		$this->data['Units']         = $this->Unit->get_unit_list(['MundaneId' => $id, 'IncludeCompanies' => 1, 'IncludeHouseHolds' => 1, 'IncludeEvents' => 1, 'ActiveOnly' => 1]);
		$this->data['AwardRecommendations'] = $this->Reports->recommended_awards(['PlayerId' => $id, 'KingdomId' => 0, 'ParkId' => 0, 'IncludeKnights' => 1, 'IncludeMasters' => 1, 'IncludeLadder' => 1, 'LadderMinimum' => 0]);

		global $DB;
		$playerParkId = (int)$this->data['Player']['ParkId'];
		$officerSql   = "SELECT o.role, o.park_id,
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
		$officerRoles  = [];
		if ($officerResult->Size() > 0) {
			while ($officerResult->Next()) {
				$officerRoles[] = [
					'role'        => $officerResult->role,
					'entity_type' => $officerResult->entity_type,
					'entity_name' => $officerResult->entity_name,
				];
			}
		}
		$this->data['OfficerRoles'] = $officerRoles;

		$adminCheck = $DB->DataSet(
			"SELECT 1 FROM ork_authorization
			 WHERE mundane_id = " . (int)$id . "
			   AND role = 'admin'
			   AND park_id = 0 AND kingdom_id = 0 AND event_id = 0 AND unit_id = 0
			 LIMIT 1"
		);
		$this->data['IsOrkAdmin'] = ($adminCheck && $adminCheck->Size() > 0);

		$this->data['Stats'] = [
			'TotalAttendance'   => is_array($this->data['Details']['Attendance']) ? count($this->data['Details']['Attendance']) : 0,
			'TotalAwards'       => 0,
			'TotalTitles'       => 0,
			'HighestClassLevel' => 0,
		];
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
				if      ($credits >= 53) $lvl = 6;
				elseif  ($credits >= 34) $lvl = 5;
				elseif  ($credits >= 21) $lvl = 4;
				elseif  ($credits >= 12) $lvl = 3;
				elseif  ($credits >= 5)  $lvl = 2;
				else                     $lvl = 1;
				if ($lvl > $this->data['Stats']['HighestClassLevel'])
					$this->data['Stats']['HighestClassLevel'] = $lvl;
			}
		}

		$preloadOfficers = [];
		$kingdomOfficers = $this->Kingdom->get_officers($this->session->kingdom_id, $this->session->token);
		if (is_array($kingdomOfficers)) {
			foreach ($kingdomOfficers as $officer) {
				if (in_array($officer['OfficerRole'], ['Monarch', 'Regent']) && $officer['MundaneId'] > 0)
					$preloadOfficers[] = ['MundaneId' => $officer['MundaneId'], 'Persona' => $officer['Persona'], 'Role' => 'Kingdom ' . $officer['OfficerRole']];
			}
		}
		$parkId = $this->data['Player']['ParkId'];
		if (valid_id($parkId)) {
			$parkOfficers = $this->Park->get_officers($parkId, $this->session->token);
			if (is_array($parkOfficers)) {
				foreach ($parkOfficers as $officer) {
					if (in_array($officer['OfficerRole'], ['Monarch', 'Regent']) && $officer['MundaneId'] > 0)
						$preloadOfficers[] = ['MundaneId' => $officer['MundaneId'], 'Persona' => $officer['Persona'], 'Role' => 'Park ' . $officer['OfficerRole']];
				}
			}
		}
		$this->data['PreloadOfficers'] = $preloadOfficers;
	}

}



?>
