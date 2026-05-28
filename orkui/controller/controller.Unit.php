<?php

class Controller_Unit extends Controller {

	public function __construct($call=null, $id=null) {
		parent::__construct($call, $id);

	}

	public function unitlist($params=null) {
		$kingdom_id = valid_id($this->request->KingdomId) ? (int)$this->request->KingdomId : null;
		$park_id    = valid_id($this->request->ParkId)    ? (int)$this->request->ParkId    : null;
		$this->data['ScopeKingdomId'] = $kingdom_id;
		$this->data['ScopeParkId']    = $park_id;
		if ($park_id) {
			$this->data['ScopeLabel'] = $this->session->park_name ?: 'Park';
			$this->session->unit_list_ref = 'Unit/unitlist&ParkId=' . $park_id;
		} elseif ($kingdom_id) {
			$this->data['ScopeLabel'] = $this->session->kingdom_name ?: 'Kingdom';
			$this->session->unit_list_ref = 'Unit/unitlist&KingdomId=' . $kingdom_id;
			unset($this->data['menu']['park']);
		} else {
			$this->data['ScopeLabel'] = null;
			$this->session->unit_list_ref = 'Unit/unitlist';
			unset($this->data['menu']['park']);
			unset($this->data['menu']['kingdom']);
		}
	}

	public function index($unit_id = null) {
		if (!valid_id($unit_id)) {
			if (valid_id($this->session->park_id)) {
				header('Location: ' . UIR . 'Unit/unitlist&ParkId=' . (int)$this->session->park_id);
			} elseif (valid_id($this->session->kingdom_id)) {
				header('Location: ' . UIR . 'Unit/unitlist&KingdomId=' . (int)$this->session->kingdom_id);
			} else {
				header('Location: ' . UIR . 'Unit/unitlist');
			}
			exit;
		}
		$unit_id_int = (int)$unit_id;

		// Handle POST actions (logged-in only)
		$action = trimlen($this->request->Action) > 0 ? $this->request->Action : '';
		if ($action && $this->data['LoggedIn']) {
			$r = null;
			switch ($action) {
				case 'save_details':
					$req = array(
						'Token'       => $this->session->token,
						'UnitId'      => $unit_id_int,
						'Name'        => $this->request->Name,
						'Description' => $this->request->Description,
						'History'     => $this->request->History,
						'Url'         => $this->request->Url,
					);
					if (!empty($_FILES['Heraldry']['size']) && Common::supported_mime_types($_FILES['Heraldry']['type'])) {
						$tmp = DIR_TMP . sprintf('uu_%05d', $unit_id_int);
						if (move_uploaded_file($_FILES['Heraldry']['tmp_name'], $tmp)) {
							$req['Heraldry']         = base64_encode(file_get_contents($tmp));
							$req['HeraldryMimeType'] = $_FILES['Heraldry']['type'];
						}
					}
					$r = $this->Unit->set_unit_details($req);
					break;
				case 'add_member':
					$r = $this->Unit->add_unit_member(array(
						'Token'     => $this->session->token,
						'UnitId'    => $unit_id_int,
						'MundaneId' => (int)$this->request->MundaneId,
						'Role'      => $this->request->Role,
						'Title'     => $this->request->Title,
						'Active'    => 'Active',
					));
					break;
				case 'set_member':
					$r = $this->Unit->set_unit_member(array(
						'Token'         => $this->session->token,
						'UnitMundaneId' => (int)$this->request->UnitMundaneId,
						'Role'          => $this->request->Role,
						'Title'         => $this->request->Title,
						'Active'        => 'Active',
					));
					break;
				case 'retire_member':
					$r = $this->Unit->retire_unit_member(array(
						'Token'         => $this->session->token,
						'UnitMundaneId' => (int)$this->request->UnitMundaneId,
						'UnitId'        => $unit_id_int,
					));
					break;
				case 'remove_member':
					$r = $this->Unit->remove_unit_member(array(
						'Token'         => $this->session->token,
						'UnitMundaneId' => (int)$this->request->UnitMundaneId,
						'UnitId'        => $unit_id_int,
					));
					break;
				case 'upload_heraldry':
				header('Content-Type: application/json');
				if (!empty($_FILES['Heraldry']['size']) && Common::supported_mime_types($_FILES['Heraldry']['type'])) {
					$tmp = DIR_TMP . sprintf('uu_%05d', $unit_id_int);
					if (move_uploaded_file($_FILES['Heraldry']['tmp_name'], $tmp)) {
						$r2 = $this->Unit->upload_unit_heraldry(array(
							'Token'            => $this->session->token,
							'UnitId'           => $unit_id_int,
							'Heraldry'         => base64_encode(file_get_contents($tmp)),
							'HeraldryMimeType' => $_FILES['Heraldry']['type'],
						));
						echo json_encode(array('ok' => $r2['Status'] == 0));
					} else {
						echo json_encode(array('ok' => false, 'error' => 'Upload failed'));
					}
				} else {
					echo json_encode(array('ok' => false, 'error' => 'Invalid file'));
				}
				exit;
			case 'remove_heraldry':
				header('Content-Type: application/json');
				$r2 = $this->Unit->remove_unit_heraldry(array(
					'Token'  => $this->session->token,
					'UnitId' => $unit_id_int,
				));
				echo json_encode(array('ok' => $r2['Status'] == 0));
				exit;
			case 'addauth':
					$_grantee_mid = (int)$this->request->MundaneId;
					$r = $this->Unit->add_unit_auth(array(
						'Token'     => $this->session->token,
						'Role'      => AUTH_CREATE,
						'Type'      => AUTH_UNIT,
						'Id'        => $unit_id_int,
						'MundaneId' => $_grantee_mid,
					));
					if ($r['Status'] == 0) {
						// Also add as a Member if not already on the roster
						$this->Unit->add_unit_member(array(
							'Token'     => $this->session->token,
							'UnitId'    => $unit_id_int,
							'MundaneId' => $_grantee_mid,
							'Role'      => 'Member',
							'Title'     => '',
							'Active'    => 'Active',
						));
						// Unit addauth goes through the proper Authorization API path
						// (not raw INSERT like Park/Kingdom/Event addauth controllers),
						// so the audit call lives here to mirror the pattern used by
						// the raw-INSERT paths. Anchored to the grantee so the entry
						// shows on the affected player's audit history.
						Ork3::$Lib->dangeraudit->audit('Authorization::AddAuthorization',
							['MundaneId' => $_grantee_mid, 'Type' => AUTH_UNIT, 'Id' => $unit_id_int, 'Role' => AUTH_CREATE],
							'Player', $_grantee_mid, null, [
								'authorization_id' => (int)($r['Detail'] ?? 0),
								'mundane_id'       => $_grantee_mid,
								'park_id'          => 0,
								'kingdom_id'       => 0,
								'event_id'         => 0,
								'unit_id'          => $unit_id_int,
								'role'             => AUTH_CREATE,
							]);
					}
					break;
				case 'deleteauth':
					$r = $this->Unit->del_unit_auth(array(
						'Token'           => $this->session->token,
						'AuthorizationId' => (int)$this->request->AuthorizationId,
					));
					break;
				case 'convert_type':
					$target = $this->request->TargetType;
					if ($target === 'Household') {
						$r = $this->Unit->convert_to_household($unit_id_int);
					} elseif ($target === 'Company') {
						$r = $this->Unit->convert_to_company($unit_id_int);
					}
					break;
				case 'retire_unit':
					$r = $this->Unit->retire_unit(array(
						'Token'  => $this->session->token,
						'UnitId' => $unit_id_int,
					));
					break;
				case 'restore_unit':
					$r = $this->Unit->restore_unit(array(
						'Token'  => $this->session->token,
						'UnitId' => $unit_id_int,
					));
					break;
				case 'claim_unit':
					$r = $this->Unit->claim_unit(array(
						'Token'  => $this->session->token,
						'UnitId' => $unit_id_int,
					));
					break;
				case 'transfer_ownership':
					$r = $this->Unit->transfer_ownership(array(
						'Token'     => $this->session->token,
						'UnitId'    => $unit_id_int,
						'MundaneId' => (int)$this->request->MundaneId,
					));
					break;
			}
			if (isset($r)) {
				if ($r['Status'] == 0) {
					header('Location: ' . UIR . "Unit/index/$unit_id");
					exit;
				} else {
					$this->data['SaveError'] = ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '');
				}
			}
		}

		$this->data['Unit_heraldryurl'] = $this->Unit->get_heraldry($unit_id);
		$this->data['Unit'] = $this->Unit->get_unit_details($unit_id);
		if (empty($this->data['Unit']['Details']['Unit']['UnitId'])) {
			header('Location: ' . UIR . 'Unit/unitlist');
			exit;
		}
		$_uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
		$_canEdit = $_uid > 0 && Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_UNIT, (int)$unit_id, AUTH_EDIT);
		$this->data['CanEdit'] = $_canEdit;

		// ── Retire / Claim / Transfer state ───────────────────────────────
		// Whether this unit is currently active (vs. retired/soft-deleted).
		$_unit_active = (($this->data['Unit']['Details']['Unit']['Active'] ?? 'Active') !== 'Retired');
		$this->data['UnitActive'] = $_unit_active;

		// Manager set = authorization rows scoped to this unit.
		$_auth_rows   = $this->data['Unit']['Authorizations']['Authorizations'] ?? array();
		$_manager_ids = array();
		foreach ($_auth_rows as $_ar) { $_manager_ids[] = (int)$_ar['MundaneId']; }
		$_manager_ids = array_values(array_unique($_manager_ids));
		$this->data['ManagerCount'] = count($_manager_ids);

		// Current user's place in the active roster.
		$_roster   = $this->data['Unit']['Members']['Roster'] ?? array();
		$_my_role  = null;
		$_is_member = false;
		foreach ($_roster as $_rm) {
			if ($_uid > 0 && (int)$_rm['MundaneId'] === $_uid) {
				$_is_member = true;
				$_my_role   = strtolower($_rm['UnitRole'] ?? '');
			}
		}
		$this->data['IsRosterMember'] = $_is_member;
		$this->data['IsManager']      = $_uid > 0 && in_array($_uid, $_manager_ids, true);
		$this->data['IsSoleMember']   = $_uid > 0 && count($_roster) === 1 && $_is_member;
		// Self-claim: no managers exist and the user holds a leadership roster role.
		$this->data['CanClaim']       = $_uid > 0 && count($_manager_ids) === 0
			&& in_array($_my_role, array('captain', 'lord', 'organizer'), true);

		// Kingdom-officer (monarchy) authority, evaluated against the user's OWN
		// home kingdom — matching the KPM bypass in Authorization::add_authorization
		// so the UI only offers what the service layer will actually permit.
		$_can_officer = false;
		$_scope_kingdom_id = null;
		$_scope_park_id    = null;
		if ($_uid > 0) {
			$_pinfo        = Ork3::$Lib->player->player_info($this->session->token);
			$_home_kingdom = isset($_pinfo['KingdomId']) ? (int)$_pinfo['KingdomId'] : 0;
			$_home_park    = isset($_pinfo['ParkId'])    ? (int)$_pinfo['ParkId']    : 0;
			if ($_home_kingdom > 0) {
				$_scope_kingdom_id = $_home_kingdom;
				$_scope_park_id    = $_home_park > 0 ? $_home_park : null;
				$_can_officer = Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_KINGDOM, $_home_kingdom, AUTH_EDIT)
					|| ($_home_park > 0 && Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_PARK, $_home_park, AUTH_EDIT));
			}
		}
		$this->data['ScopeKingdomId']   = $_scope_kingdom_id;
		$this->data['ScopeParkId']      = $_scope_park_id;
		$this->data['CanOfficerManage'] = $_can_officer;

		// The Add-Manager modal is available to unit editors, and to officers only
		// when the unit is currently unmanaged (their entry point to assign one).
		$_show_addmgr = $_canEdit || ($_can_officer && count($_manager_ids) === 0);
		$this->data['ShowAddManager'] = $_show_addmgr;

		// Transfer targets: active members other than the actor, managers first.
		$_targets = array();
		foreach ($_roster as $_rm) {
			$_mid = (int)$_rm['MundaneId'];
			if ($_mid === $_uid) continue;
			$_targets[] = array(
				'MundaneId' => $_mid,
				'Persona'   => trimlen($_rm['Persona']) > 0 ? $_rm['Persona'] : '(No Persona)',
				'IsManager' => in_array($_mid, $_manager_ids, true),
			);
		}
		usort($_targets, function($a, $b) {
			if ($a['IsManager'] !== $b['IsManager']) return $a['IsManager'] ? -1 : 1;
			return strcasecmp($a['Persona'], $b['Persona']);
		});
		$this->data['TransferTargets'] = $_targets;

		// Active, non-manager members — the head-of-list shortcut in Add Manager.
		$_nonmgr = array();
		foreach ($_roster as $_rm) {
			$_mid = (int)$_rm['MundaneId'];
			if (in_array($_mid, $_manager_ids, true)) continue;
			$_nonmgr[] = array(
				'MundaneId' => $_mid,
				'Persona'   => trimlen($_rm['Persona']) > 0 ? $_rm['Persona'] : '(No Persona)',
			);
		}
		$this->data['NonManagerMembers'] = $_nonmgr;

		// Active kingdoms for the "filter players by" cascade in the add-member/
		// manager search. Sorted by name for the dropdown.
		$this->data['FilterKingdoms'] = array();
		if ($_show_addmgr) {
			$_kd = Ork3::$Lib->kingdom->GetKingdoms(array());
			$_kingdoms = array_values($_kd['Kingdoms'] ?? array());
			usort($_kingdoms, function($a, $b) { return strcasecmp($a['KingdomName'] ?? '', $b['KingdomName'] ?? ''); });
			$this->data['FilterKingdoms'] = $_kingdoms;
		}
		if ($_canEdit) {
			$this->data['menu']['admin'] = array( 'url' => UIR."Admin/unit/$unit_id", 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		}
		$from_player = valid_id($this->request->from_player) ? (int)$this->request->from_player : null;
		if ($from_player) {
			$this->load_model('Player');
			$_pdata = $this->Player->fetch_player($from_player);
			$_persona = (!empty($_pdata['Persona']) ? $_pdata['Persona'] : null) ?? $_pdata['UserName'] ?? 'Player';
			$this->data['menu']['player'] = array( 'url' => UIR."Player/profile/$from_player", 'display' => htmlspecialchars($_persona) );
		} else {
			$unit_list_url = UIR . ($this->session->unit_list_ref ?: 'Unit/unitlist');
			$this->data['menu']['units'] = array( 'url' => $unit_list_url, 'display' => 'Units' );
		}
		$this->data['menu']['unit']  = array( 'url' => UIR."Unit/index/$unit_id", 'display' => $this->data['Unit']['Details']['Unit']['Name'] ?? 'Unit' );
	}
	
	public function create($mundane_id) {
		$mundane_id = (int)$mundane_id;
		if (trimlen($this->request->Action) > 0) {
			$this->request->save('Unit_create', true);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR.'Login/login/Unit/create/' . $mundane_id );
				exit;
			} else {
				$h_imdata = null;
				$heraldry_mime = '';
				if (isset($_FILES['Heraldry']) && $_FILES['Heraldry']['size'] > 0 && Common::supported_mime_types($_FILES['Heraldry']['type'])) {
					$heraldry_mime = $_FILES['Heraldry']['type'];
					if (move_uploaded_file($_FILES['Heraldry']['tmp_name'], DIR_TMP . sprintf("um_%05d", $mundane_id))) {
						$h_im = file_get_contents(DIR_TMP . sprintf("um_%05d", $mundane_id));
						$h_imdata = base64_encode($h_im);
					} else {
						$Status = array(
							'Status' => 1000,
							'Error' => 'File IO Error',
							'Detail' => 'File could not be moved to .../tmp',
						);
					}
				}
				$r = $this->Unit->create_unit(array(
						'Heraldry' => $h_imdata,
						'HeraldryMimeType' => $heraldry_mime,
						'Name' => $this->request->Unit_create->Name,
						'Type' => $this->request->Unit_create->Type,
						'Description' => $this->request->Unit_create->Description,
						'History' => $this->request->Unit_create->History,
						'Url' => $this->request->Unit_create->Url,
						'Token' => $this->session->token,
						'MundaneId' => $mundane_id
					));
				if ($r['Status'] == 0) {
					$this->request->clear('Unit_create');
					header( 'Location: '.UIR.'Unit/index/' . $r['Detail'] );
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR.'Login/login/Unit/create/' . $mundane_id );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}
		if ($this->request->exists('Unit_create')) {
			$this->data['Unit_create'] = $this->request->Unit_create->Request;
		}
		$this->data['MundaneId'] = $mundane_id;
	}
}



?>