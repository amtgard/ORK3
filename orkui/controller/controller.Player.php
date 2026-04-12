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
		if (!empty($park_info['ParkInfo']['ParkId'])) {
			$this->session->park_name = $park_info['ParkInfo']['ParkName'];
			$this->session->park_id = $park_info['ParkInfo']['ParkId'];
			$this->session->kingdom_id = $park_info['KingdomInfo']['KingdomId'];
			$this->session->kingdom_name = $park_info['KingdomInfo']['KingdomName'];
		}
		$_uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
		if ($_uid > 0 && Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_PARK, (int)$this->session->park_id, AUTH_EDIT)) {
			$this->data['menu']['admin'] = array( 'url' => UIR.'Admin/player/'.$id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		}
		$this->data['menulist']['admin'] = array(
				array( 'url' => UIR.'Admin/player/'.$id, 'display' => 'Player' ),
				array( 'url' => UIR.'Admin/park/'.$this->session->park_id, 'display' => 'Park' ),
				array( 'url' => UIR.'Admin/kingdom/'.$this->session->kingdom_id, 'display' => 'Kingdom' )
			);
		if (valid_id($this->session->kingdom_id)) {
			$this->data['menu']['kingdom'] = array( 'url' => UIR.'Kingdom/profile/'.$this->session->kingdom_id, 'display' => $this->session->kingdom_name );
			$this->data['menu']['park'] = array( 'url' => UIR.'Park/profile/'.$this->session->park_id, 'display' => $this->session->park_name );
		} else {
			unset($this->data['menu']['kingdom']);
			unset($this->data['menu']['park']);
		}
		$this->data['menu']['player'] = array( 'url' => UIR."Player/$call/$id", 'display' => $this->data['Player']['Persona'] );
		$this->data['page_title'] = $this->data['Player']['Persona'];

	}

	public function index($id = null) {
		if (!valid_id($id)) {
			header('Location: ' . UIR);
			exit;
		}

		$this->load_model('Unit');
		$this->load_model('Event');
		
		$params = explode('/',$id);
		$id = $params[0];
		$action = '';
		$roastbeef = '';
		if (count($params) > 1)
			$action = $params[1];
		if (count($params) > 2)
			$roastbeef = $params[2];
				
		$uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

		if ($uid > 0 && $uid === (int)$id && isset($this->request->cancel_rsvp_detail_id)) {
			$this->Event->toggle_rsvp((int)$this->request->cancel_rsvp_detail_id, $uid);
			header('Location: ' . UIR . 'Player/profile/' . $id);
			return;
		}

		if (strlen($action) > 0) {
			$this->request->save('Player_index', true);
			$r = array('Status'=>0);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR."Login/login/Player/profile/$id" );
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
						$pi_imdata = '';
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
								'AwardsId' => $roastbeef,
								'RecipientId' => $id
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
					header( 'Location: '.UIR."Login/login/Player/profile/$id" );
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
		$this->data['Player']['LastSignInDate']   = $this->Player->get_latest_attendance_date($id);
		$this->data['Player']['PlayerSinceDate']  = $this->Player->get_earliest_attendance_date($id);
		// Fallback Park Member Since to earliest attendance at the member park
		// when the mundane record has no stored date (legacy imports, older accounts).
		$_pms = $this->data['Player']['ParkMemberSince'] ?? null;
		if (empty($_pms) || $_pms === '0000-00-00') {
			$_memberParkId = (int)($this->data['Player']['ParkId'] ?? 0);
			if ($_memberParkId > 0) {
				$_fallback = $this->Player->get_earliest_park_attendance_date($id, $_memberParkId);
				if (!empty($_fallback)) {
					$this->data['Player']['ParkMemberSince'] = $_fallback;
				}
			}
		}
		$this->data['PronounOptions'] = $this->Pronoun->fetch_pronoun_option_list($this->data['Player']['PronounId']);
		$this->data['PronounList']    = $this->Pronoun->fetch_pronoun_list();
		$this->data['Details'] = $this->Player->fetch_player_details($id);
    	$this->data['Notes'] = $this->Player->get_notes($id);
    	$this->data['Dues'] = $this->Player->get_dues($id, 1, true);
    	$this->data['AllDues'] = $this->Player->get_dues($id, 0, false);
		$this->data['Units'] = $this->Unit->get_unit_list(array( 'MundaneId' => $id, 'IncludeCompanies' => 1, 'IncludeHouseHolds' =>1, 'IncludeEvents' => 1, 'ActiveOnly' => 1 ));
		$this->data['menu']['player'] = array( 'url' => UIR."Player/profile/$id", 'display' => $this->data['Player']['Persona'] );
		$canEdit    = $uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, (int)($this->data['Player']['ParkId'] ?? 0), AUTH_EDIT);
		if ($canEdit) {
			$this->data['menu']['admin'] = array( 'url' => UIR."Admin/player/$id", 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		}
		$knConfigs  = Common::get_configs($this->session->kingdom_id, CFG_KINGDOM);
		$recsPublic = isset($knConfigs['AwardRecsPublic']) ? (bool)(int)$knConfigs['AwardRecsPublic']['Value'] : true;
		$this->data['ShowRecsTab']          = $recsPublic || $canEdit;
		$this->data['AwardRecommendations'] = [];
		if ($this->data['ShowRecsTab'] || $uid > 0) {
			$recs = $this->Reports->recommended_awards(array('PlayerId'=>$id, 'KingdomId'=>0, 'ParkId'=>0, 'IncludeKnights' => 1, 'IncludeMasters' => 1, 'IncludeLadder' => 1, 'LadderMinimum' => 0));
			$this->data['AwardRecommendations'] = is_array($recs) ? $recs : [];
		}

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

		$this->data['UpcomingRsvps'] = $this->Event->get_upcoming_rsvps((int)$id);
		$this->data['IsOwnProfile'] = $uid === (int)$id;

	}

	public function profile( $id = null ) {
		// A/B EXPERIMENT: ?design=b loads the subtle "illuminated" variant.
		// To remove: delete this if-block, delete Playernew_index_b.tpl, and
		// remove the eyeball block in default.theme.
		if (($_GET['design'] ?? '') === 'b') {
			$this->template = '../revised-frontend/Playernew_index_b.tpl';
		} else {
			$this->template = '../revised-frontend/Playernew_index.tpl';
		}

		$params    = explode('/', $id ?? '');
		$id        = $params[0];

		if (!(int)$id) {
			header('Location: ' . UIR);
			exit;
		}

		$this->load_model('Unit');
		$this->load_model('Kingdom');
		$this->load_model('Event');
		$action    = $params[1] ?? '';
		$roastbeef = $params[2] ?? '';

		$uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

		if ($uid > 0 && $uid === (int)$id && isset($this->request->cancel_rsvp_detail_id)) {
			$this->Event->toggle_rsvp((int)$this->request->cancel_rsvp_detail_id, $uid);
			header('Location: ' . UIR . 'Player/profile/' . $id);
			return;
		}

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
							'UnitId'        => 0,
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
		$this->data['Player']['LastSignInDate']  = $this->Player->get_latest_attendance_date($id);
		$this->data['Player']['PlayerSinceDate'] = $this->Player->get_earliest_attendance_date($id);
		$this->data['PronounOptions'] = $this->Pronoun->fetch_pronoun_option_list($this->data['Player']['PronounId']);
		$this->data['PronounList']    = $this->Pronoun->fetch_pronoun_list();
		$this->data['Details']       = $this->Player->fetch_player_details($id);
		$this->data['Notes']         = [];  // loaded via AJAX on Notes tab click
		$this->data['Dues']          = $this->Player->get_dues($id, 1, true);
		$this->data['AllDues']       = [];  // loaded via AJAX when dues modal opens
		$this->data['Units']         = $this->Unit->get_unit_list(['MundaneId' => $id, 'IncludeCompanies' => 1, 'IncludeHouseHolds' => 1, 'IncludeEvents' => 1, 'ActiveOnly' => 1]);
		$canEdit    = $uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, (int)($this->data['Player']['ParkId'] ?? 0), AUTH_EDIT);
		$knConfigs  = Common::get_configs($this->session->kingdom_id, CFG_KINGDOM);
		$recsPublic = isset($knConfigs['AwardRecsPublic']) ? (bool)(int)$knConfigs['AwardRecsPublic']['Value'] : true;
		$this->data['ShowRecsTab']          = $recsPublic || $canEdit;
		$this->data['ShowRecsTabLoggedIn']  = $uid > 0;
		$this->data['AwardRecommendations'] = [];  // loaded via AJAX on Recommendations tab click

		// Voting eligibility badge loaded via AJAX after page render (PlayerAjax/voting_eligible)

		global $DB;
		$DB->Clear();
		$officerSql   = "SELECT o.role, o.park_id,
			CASE WHEN o.park_id > 0 THEN IFNULL(pt.title, 'Park') ELSE 'Kingdom' END AS entity_type,
			CASE WHEN o.park_id > 0 THEN p.name ELSE k.name END AS entity_name
			FROM ork_officer o
			LEFT JOIN ork_kingdom k ON o.kingdom_id = k.kingdom_id
			LEFT JOIN ork_park p ON o.park_id = p.park_id AND o.park_id > 0
			LEFT JOIN ork_parktitle pt ON p.parktitle_id = pt.parktitle_id
			WHERE o.mundane_id = " . (int)$id . "
			  AND k.active = 'Active'
			  AND (o.park_id = 0 OR p.active = 'Active')
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

		$this->data['RevokedAwards'] = [];
		$this->data['RevokedTitles'] = [];
		if ($canEdit) {
			$revokedBaseSql = "SELECT a.awards_id, a.rank, a.date, a.revoked_at, a.revocation,
				COALESCE(NULLIF(a.custom_name,''), ka.name, aw.name) AS award_name,
				m.persona AS revoked_by
				FROM ork_awards a
				LEFT JOIN ork_kingdomaward ka ON a.kingdomaward_id = ka.kingdomaward_id
				LEFT JOIN ork_award aw ON a.award_id = aw.award_id
				LEFT JOIN ork_mundane m ON a.revoked_by_id = m.mundane_id
				WHERE a.stripped_from = " . (int)$id . "
				  AND a.revoked = 1";
			$revokedAwardsSql = $revokedBaseSql . "
				  AND (aw.officer_role = 'none' OR aw.officer_role IS NULL)
				  AND (ka.is_title IS NULL OR ka.is_title = 0)
				ORDER BY a.revoked_at DESC, a.date DESC";
			$revokedTitlesSql = $revokedBaseSql . "
				  AND (aw.officer_role != 'none' OR ka.is_title = 1)
				ORDER BY a.revoked_at DESC, a.date DESC";
			foreach (['RevokedAwards' => $revokedAwardsSql, 'RevokedTitles' => $revokedTitlesSql] as $key => $sql) {
				$DB->Clear();
				$result = $DB->DataSet($sql);
				$rows = [];
				if ($result->Size() > 0) {
					while ($result->Next()) {
						$rows[] = [
							'AwardsId'   => $result->awards_id,
							'AwardName'  => $result->award_name,
							'Rank'       => $result->rank,
							'Date'       => $result->date,
							'RevokedAt'  => $result->revoked_at,
							'Revocation' => $result->revocation,
							'RevokedBy'  => $result->revoked_by,
						];
					}
				}
				$this->data[$key] = $rows;
			}
		}

		$DB->Clear();
		$adminCheck = $DB->DataSet(
			"SELECT 1 FROM ork_authorization
			 WHERE mundane_id = " . (int)$id . "
			   AND role = 'admin'
			   AND park_id = 0 AND kingdom_id = 0 AND event_id = 0 AND unit_id = 0
			 LIMIT 1"
		);
		$this->data['IsOrkAdmin'] = ($adminCheck && $adminCheck->Size() > 0);

		// Attendance loaded async — counts start at 0 and are updated via AJAX
		$this->data['Stats'] = [
			'TotalAttendance'   => 0,
			'TotalAwards'       => 0,
			'TotalTitles'       => 0,
			'HighestClassLevel' => 0,
			'LastPlayedClass'   => '',
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

		$this->data['UpcomingRsvps']   = $this->Event->get_upcoming_rsvps((int)$id);
		$this->data['KingdomEvents']   = ($uid === (int)$id) ? $this->Event->get_kingdom_upcoming_events((int)$this->session->kingdom_id, (int)$id) : [];
		$this->data['IsOwnProfile']    = $uid === (int)$id;
		$this->data['Player']['ParkName'] = $this->session->park_name;


		// Beltline: My Peers (who gave this player peerage awards)
		$DB->Clear();
		$__peerSql = "SELECT m.mundane_id AS PeerId, m.persona AS Persona,
			IFNULL(ka.name, a.name) AS TitleName, a.peerage AS Peerage, ma.date AS Date
			FROM ork_awards ma
			JOIN ork_award a ON a.award_id = ma.award_id
			LEFT JOIN ork_kingdomaward ka ON ka.kingdomaward_id = ma.kingdomaward_id
			JOIN ork_mundane m ON m.mundane_id = ma.given_by_id
			WHERE ma.mundane_id = " . (int)$id . "
				AND (a.peerage IN ('Squire','Man-At-Arms','Page','Lords-Page')
					OR LOWER(IFNULL(ka.name, a.name)) LIKE '%woman%at%arms%')
				AND (ma.revoked = 0 OR ma.revoked IS NULL)
				AND ma.given_by_id > 0
			ORDER BY CASE a.peerage
				WHEN 'Squire' THEN 1 WHEN 'Man-At-Arms' THEN 2
				WHEN 'Lords-Page' THEN 3 WHEN 'Page' THEN 4 ELSE 5 END, m.persona ASC";
		$__peerResult = $DB->DataSet($__peerSql);
		$__peers = [];
		if ($__peerResult) {
			while ($__peerResult->Next()) {
				$__peers[] = [
					'PeerId'    => (int)$__peerResult->PeerId,
					'Persona'   => $__peerResult->Persona,
					'TitleName' => $__peerResult->TitleName,
					'Peerage'   => $__peerResult->Peerage,
					'Date'      => $__peerResult->Date,
				];
			}
		}
		$DB->Clear();
		$this->data['BeltlinePeers'] = $__peers;

		// Beltline: My Associates (who this player gave peerage awards to)
		$DB->Clear();
		$__blAssocSql = "SELECT ma.mundane_id AS RecipientId, m.persona AS Persona,
			IFNULL(ka.name, a.name) AS TitleName, a.peerage AS Peerage, ma.date AS Date
			FROM ork_awards ma
			JOIN ork_award a ON a.award_id = ma.award_id
			LEFT JOIN ork_kingdomaward ka ON ka.kingdomaward_id = ma.kingdomaward_id
			JOIN ork_mundane m ON m.mundane_id = ma.mundane_id
			WHERE ma.given_by_id = " . (int)$id . "
				AND (a.peerage IN ('Squire','Man-At-Arms','Page','Lords-Page')
					OR LOWER(IFNULL(ka.name, a.name)) LIKE '%woman%at%arms%')
				AND (ma.revoked = 0 OR ma.revoked IS NULL)
			ORDER BY CASE a.peerage
				WHEN 'Squire' THEN 1 WHEN 'Man-At-Arms' THEN 2
				WHEN 'Lords-Page' THEN 3 WHEN 'Page' THEN 4 ELSE 5 END, m.persona ASC";
		$__blAssocResult = $DB->DataSet($__blAssocSql);
		$__blAssocs = [];
		if ($__blAssocResult) {
			while ($__blAssocResult->Next()) {
				$__blAssocs[] = [
					'RecipientId' => (int)$__blAssocResult->RecipientId,
					'Persona'     => $__blAssocResult->Persona,
					'TitleName'   => $__blAssocResult->TitleName,
					'Peerage'     => $__blAssocResult->Peerage,
					'Date'        => $__blAssocResult->Date,
				];
			}
		}
		$DB->Clear();
		$this->data['BeltlineAssociates'] = $__blAssocs;

		if ($uid === (int)$id) {
			$DB->Clear();
			$__assocSql = "SELECT ma.mundane_id AS RecipientId, m.persona AS Persona,
				IFNULL(ka.name, a.name) AS TitleName, a.peerage AS Peerage, ma.date AS Date
				FROM ork_awards ma
				JOIN ork_award a ON a.award_id = ma.award_id
				LEFT JOIN ork_kingdomaward ka ON ka.kingdomaward_id = ma.kingdomaward_id
				JOIN ork_mundane m ON m.mundane_id = ma.mundane_id
				WHERE ma.given_by_id = $uid
					AND (a.peerage IN ('Squire','Man-At-Arms','Page','Lords-Page')
						OR LOWER(IFNULL(ka.name, a.name)) LIKE '%woman%at%arms%')
					AND (ma.revoked = 0 OR ma.revoked IS NULL)
				ORDER BY CASE a.peerage
					WHEN 'Squire' THEN 1 WHEN 'Man-At-Arms' THEN 2
					WHEN 'Lords-Page' THEN 3 WHEN 'Page' THEN 4 ELSE 5 END, m.persona ASC";
			$__assocResult = $DB->DataSet($__assocSql);
			$__assocs = [];
			if ($__assocResult) {
				while ($__assocResult->Next()) {
					$__assocs[] = [
						'RecipientId' => (int)$__assocResult->RecipientId,
						'Persona'     => $__assocResult->Persona,
						'TitleName'   => $__assocResult->TitleName,
						'Peerage'     => $__assocResult->Peerage,
						'Date'        => $__assocResult->Date,
					];
				}
			}
			$DB->Clear();
			$this->data['MyAssociates'] = $__assocs;

			// Fetch player's titles for name builder prefix/suffix options
			$DB->Clear();
			$__titleSql = "SELECT DISTINCT
				COALESCE(NULLIF(ka.name,''), a.name) AS title_name,
				a.officer_role, a.peerage, IFNULL(ka.is_title, 0) AS is_title
				FROM ork_awards ma
				JOIN ork_award a ON a.award_id = ma.award_id
				LEFT JOIN ork_kingdomaward ka ON ka.kingdomaward_id = ma.kingdomaward_id
				WHERE ma.mundane_id = " . (int)$id . "
				  AND (ma.revoked = 0 OR ma.revoked IS NULL)
				  AND (a.officer_role != 'none' OR IFNULL(ka.is_title, 0) = 1 OR a.is_title = 1 OR a.peerage NOT IN ('None',''))
				ORDER BY a.peerage ASC, title_name ASC";
			$__titleResult = $DB->DataSet($__titleSql);
			$__titles = [];
			if ($__titleResult) {
				while ($__titleResult->Next()) {
					$__titles[] = [
						'TitleName'   => $__titleResult->title_name,
						'OfficerRole' => $__titleResult->officer_role,
						'Peerage'     => $__titleResult->peerage,
						'IsTitle'     => (int)$__titleResult->is_title,
					];
				}
			}
			$DB->Clear();
			// Add standalone Master/Paragon if player has any Master X or Paragon X awards
			$hasMaster = false;
			$hasParagon = false;
			foreach ($__titles as $_t) {
				if ($_t['Peerage'] === 'Master') $hasMaster = true;
				if ($_t['Peerage'] === 'Paragon') $hasParagon = true;
			}
			if ($hasMaster) array_unshift($__titles, ['TitleName' => 'Master', 'OfficerRole' => 'none', 'Peerage' => 'Master', 'IsTitle' => 0]);
			if ($hasParagon) array_unshift($__titles, ['TitleName' => 'Paragon', 'OfficerRole' => 'none', 'Peerage' => 'Paragon', 'IsTitle' => 0]);
			$this->data['PlayerTitles'] = $__titles;
		}

		// ===== Milestones Timeline Data =====
		$__milestones = [];
		$__awards = is_array($this->data['Details']['Awards']) ? $this->data['Details']['Awards'] : [];
		$__attendance = is_array($this->data['Details']['Attendance']) ? $this->data['Details']['Attendance'] : [];
		$__classes = is_array($this->data['Details']['Classes']) ? $this->data['Details']['Classes'] : [];

		// 1. First Sign-In (earliest attendance date)
		$__earliestDate = null;
		foreach ($__attendance as $__a) {
			if (!empty($__a['Date']) && $__a['Date'] !== '0000-00-00' && $__a['Date'] !== '1970-01-01') {
				if ($__earliestDate === null || strtotime($__a['Date']) < strtotime($__earliestDate))
					$__earliestDate = $__a['Date'];
			}
		}
		if ($__earliestDate) {
			$__milestones[] = ['type' => 'first_signin', 'date' => $__earliestDate, 'icon' => 'fa-door-open', 'description' => 'First sign-in at Amtgard'];
		}

		// 2. Reached Level 6 in Class
		// Build attendance history per class to find earliest date with 53+ cumulative credits
		$__classAttByDate = [];
		foreach ($__attendance as $__a) {
			$__cid = (int)($__a['ClassId'] ?? 0);
			$__cdate = $__a['Date'] ?? '';
			if ($__cid > 0 && !empty($__cdate) && $__cdate !== '0000-00-00') {
				$__classAttByDate[$__cid][] = ['date' => $__cdate, 'credits' => (float)($__a['Credits'] ?? 0)];
			}
		}
		foreach ($__classAttByDate as $__cid => $__entries) {
			// Sort by date ascending
			usort($__entries, function($a, $b) { return strtotime($a['date']) - strtotime($b['date']); });
			$__cumCredits = 0;
			// Also add reconciled credits for this class
			foreach ($__classes as $__c) {
				if ((int)$__c['ClassId'] === $__cid) {
					$__cumCredits += (int)($__c['Reconciled'] ?? 0);
					$__className = $__c['ClassName'];
					break;
				}
			}
			if (empty($__className)) continue;
			foreach ($__entries as $__e) {
				$__cumCredits += $__e['credits'];
				if ($__cumCredits >= 53) {
					$__milestones[] = ['type' => 'level6', 'date' => $__e['date'], 'icon' => 'fa-hat-wizard', 'description' => 'Reached Level 6 in ' . $__className];
					break;
				}
			}
			unset($__className);
		}

		// 3-6: Awards-based milestones
		$__knightIds = [17, 18, 19, 20, 245];
		$__knightNames = [17 => 'Sword', 18 => 'Flame', 19 => 'Serpent', 20 => 'Crown', 245 => 'Battle'];
		foreach ($__awards as $__aw) {
			$__aid = (int)($__aw['AwardId'] ?? 0);
			$__awDate = $__aw['Date'] ?? '';
			$__awName = !empty($__aw['KingdomAwardName']) ? $__aw['KingdomAwardName'] : (!empty($__aw['CustomAwardName']) ? $__aw['CustomAwardName'] : ($__aw['Name'] ?? ''));
			$__officerRole = $__aw['OfficerRole'] ?? 'none';
			$__isTitle = (int)($__aw['IsTitle'] ?? 0);

			if (empty($__awDate) || $__awDate === '0000-00-00') continue;

			// Knight
			if (in_array($__aid, $__knightIds)) {
				$__knLabel = isset($__knightNames[$__aid]) ? 'Knight of the ' . $__knightNames[$__aid] : 'Knighted';
				$__milestones[] = ['type' => 'knight', 'date' => $__awDate, 'icon' => 'fa-shield-alt', 'description' => 'Earned ' . $__knLabel];
			}

			// Master (10th order of a ladder award)
			if ((int)($__aw['Rank'] ?? 0) >= 10 && (int)($__aw['IsLadder'] ?? 0) === 1 && in_array($__officerRole, ['none', null]) && $__isTitle !== 1) {
				// Strip "Order of the/Order of" prefix; otherwise the name IS the title (e.g. Warlord, Battlemaster)
				$__masterLabel = $__awName;
				if (stripos($__masterLabel, 'order of the ') === 0) {
					$__masterLabel = 'Master ' . substr($__masterLabel, 13);
				} elseif (stripos($__masterLabel, 'order of ') === 0) {
					$__masterLabel = 'Master ' . substr($__masterLabel, 9);
				}
				$__milestones[] = ['type' => 'master', 'date' => $__awDate, 'icon' => 'fa-star', 'description' => 'Earned ' . $__masterLabel];
			}

			// Paragon (class-specific paragon awards)
			$__paragonIds = [37,38,39,40,41,241,42,43,44,45,46,47,242,49,50,51];
			if (in_array($__aid, $__paragonIds)) {
				$__milestones[] = ['type' => 'paragon', 'date' => $__awDate, 'icon' => 'fa-gem', 'description' => 'Earned ' . $__awName];
			}

			// Title (IsTitle=1 and OfficerRole is none, exclude paragons/knights already handled above)
			if ($__isTitle === 1 && in_array($__officerRole, ['none', null]) && !in_array($__aid, $__paragonIds) && !in_array($__aid, $__knightIds)) {
				$__milestones[] = ['type' => 'title', 'date' => $__awDate, 'icon' => 'fa-crown', 'description' => 'Earned the title ' . $__awName];
			}

			// Served as Officer (OfficerRole is not none)
			if (!in_array($__officerRole, ['none', null, ''])) {
				$__milestones[] = ['type' => 'officer', 'date' => $__awDate, 'icon' => 'fa-landmark', 'description' => 'Served as ' . $__awName];
			}
		}

		// 7. Became Associate (peerage awards given TO this player - from BeltlinePeers data)
		$__blPeerLabels = ['Squire' => 'Squire', 'Man-At-Arms' => 'Person-at-Arms', 'Lords-Page' => "Lord's Page", 'Page' => 'Page'];
		if (!empty($this->data['BeltlinePeers'])) {
			foreach ($this->data['BeltlinePeers'] as $__bp) {
				$__peerDate = $__bp['Date'] ?? '';
				if (empty($__peerDate) || $__peerDate === '0000-00-00') continue;
				$__peerLabel = $__blPeerLabels[$__bp['Peerage']] ?? $__bp['Peerage'];
				$__milestones[] = ['type' => 'became_associate', 'date' => $__peerDate, 'icon' => 'fa-handshake', 'description' => 'Became ' . $__peerLabel . ' to ' . $__bp['Persona']];
			}
		}

		// 8. Took Associate (peerage awards given BY this player - from BeltlineAssociates data)
		if (!empty($this->data['BeltlineAssociates'])) {
			foreach ($this->data['BeltlineAssociates'] as $__ba) {
				$__assocDate = $__ba['Date'] ?? '';
				if (empty($__assocDate) || $__assocDate === '0000-00-00') continue;
				$__assocLabel = $__blPeerLabels[$__ba['Peerage']] ?? $__ba['Peerage'];
				$__milestones[] = ['type' => 'took_associate', 'date' => $__assocDate, 'icon' => 'fa-hand-holding-heart', 'description' => 'Took ' . $__ba['Persona'] . ' as ' . $__assocLabel];
			}
		}

		// 9. Custom milestones from DB
		$__customMs = $this->Player->get_custom_milestones((int)$id);
		if (is_array($__customMs)) {
			foreach ($__customMs as $__cm) {
				$__milestones[] = [
					'type' => 'custom',
					'date' => $__cm['MilestoneDate'],
					'icon' => $__cm['Icon'],
					'description' => $__cm['Description'],
					'milestoneId' => (int)$__cm['MilestoneId'],
				];
			}
		}

		// Cross-type dedup:
		// 1. Remove 'title' milestones for peerage terms (already covered by 'became_associate')
		// 2. Remove 'title' milestones for "Master X" that duplicate an existing 'master' milestone
		$__masterMsNames = [];
		foreach ($__milestones as $__m) {
			if ($__m['type'] === 'master') {
				$__masterMsNames[] = strtolower(preg_replace('/^Earned (?:Master )?/', '', $__m['description']));
			}
		}
		$__peerageTerms = ['squire', 'man-at-arms', 'person-at-arms', "lord's page", 'page'];
		$__milestones = array_values(array_filter($__milestones, function($m) use ($__masterMsNames, $__peerageTerms) {
			if ($m['type'] !== 'title') return true;
			$__tn = strtolower(preg_replace('/^Earned the title /', '', $m['description']));
			if (in_array($__tn, $__peerageTerms)) return false;
			if (substr($__tn, 0, 7) === 'master ') {
				$__kw = substr($__tn, 7);
				foreach ($__masterMsNames as $__mn) {
					if (strpos($__mn, $__kw) !== false) return false;
				}
			}
			return true;
		}));

		// Deduplicate milestones with same description + date
		$__seen = [];
		$__milestones = array_filter($__milestones, function($m) use (&$__seen) {
			$key = $m['date'] . '|' . $m['description'];
			if (isset($__seen[$key])) return false;
			$__seen[$key] = true;
			return true;
		});

		// Sort chronologically ascending
		usort($__milestones, function($a, $b) { return strtotime($a['date']) - strtotime($b['date']); });

		$this->data['Milestones'] = $__milestones;
		$this->data['CustomMilestones'] = is_array($__customMs) ? $__customMs : [];
		$this->data['MilestoneConfig'] = $this->data['Player']['MilestoneConfig'] ?? '';

	}


	public function reconcile($id = null) {
		$this->template = '../revised-frontend/Playernew_reconcile.tpl';

		$uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
		$id  = (int)$id;

		if (!$uid) {
			header('Location: ' . UIR . "Login/login/Player/reconcile/$id");
			exit;
		}

		$this->data['Player']  = $this->Player->fetch_player($id);
		$this->data['Details'] = $this->Player->fetch_player_details($id);
		$this->data['KingdomId'] = $this->session->kingdom_id;
		$this->data['AwardOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Awards');

		$playerParkId = (int)($this->data['Player']['ParkId'] ?? 0);
		$canEditAdmin = $uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, $playerParkId, AUTH_EDIT);
		$isOwnProfile = $uid === $id;
		if (!$canEditAdmin && !$isOwnProfile) {
			header('Location: ' . UIR . "Player/profile/$id");
			exit;
		}
		$this->data['canEditAdmin'] = $canEditAdmin;

		$this->load_model('Kingdom');
		$preloadOfficers = [];
		$kingdomOfficers = $this->Kingdom->get_officers($this->session->kingdom_id, $this->session->token);
		if (is_array($kingdomOfficers)) {
			foreach ($kingdomOfficers as $officer) {
				if (in_array($officer['OfficerRole'], ['Monarch', 'Regent', 'Prime Minister']) && $officer['MundaneId'] > 0)
					$preloadOfficers[] = ['MundaneId' => $officer['MundaneId'], 'Persona' => $officer['Persona'], 'Role' => 'Kingdom ' . $officer['OfficerRole']];
			}
		}
		if ($playerParkId > 0) {
			$parkOfficers = $this->Park->get_officers($playerParkId, $this->session->token);
			if (is_array($parkOfficers)) {
				foreach ($parkOfficers as $officer) {
					if (in_array($officer['OfficerRole'], ['Monarch', 'Regent', 'Prime Minister']) && $officer['MundaneId'] > 0)
						$preloadOfficers[] = ['MundaneId' => $officer['MundaneId'], 'Persona' => $officer['Persona'], 'Role' => 'Park ' . $officer['OfficerRole']];
				}
			}
		}
		$this->data['PreloadOfficers'] = $preloadOfficers;

		// AwardId → KingdomAwardId map for current kingdom (pre-match historical award dropdowns)
		global $DB;
		$DB->Clear();
		$rs = $DB->DataSet(
			'SELECT kingdomaward_id, award_id FROM ork_kingdomaward WHERE kingdom_id = ' . (int)$this->session->kingdom_id . ' AND is_title = 0'
		);
		$awardIdMap = [];
		if ($rs) { while ($rs->Next()) { $awardIdMap[(int)$rs->award_id] = (int)$rs->kingdomaward_id; } }
		$this->data['AwardIdToKingdomAwardId'] = $awardIdMap;
	}

}



?>
