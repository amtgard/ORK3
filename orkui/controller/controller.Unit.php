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
					$r = $this->Unit->add_unit_auth(array(
						'Token'     => $this->session->token,
						'Role'      => AUTH_EDIT,
						'Type'      => AUTH_UNIT,
						'Id'        => $unit_id_int,
						'MundaneId' => (int)$this->request->MundaneId,
					));
					if ($r['Status'] == 0) {
						// Also add as a Member if not already on the roster
						$this->Unit->add_unit_member(array(
							'Token'     => $this->session->token,
							'UnitId'    => $unit_id_int,
							'MundaneId' => (int)$this->request->MundaneId,
							'Role'      => 'Member',
							'Title'     => '',
							'Active'    => 'Active',
						));
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
			}
			if (isset($r)) {
				if ($r['Status'] == 0) {
					header('Location: ' . UIR . "Unit/index/$unit_id");
					exit;
				} else {
					$this->data['SaveError'] = $r['Error'] . ': ' . $r['Detail'];
				}
			}
		}

		$this->data['Unit_heraldryurl'] = $this->Unit->get_heraldry($unit_id);
		$this->data['Unit'] = $this->Unit->get_unit_details($unit_id);
		// Parse scope (kingdom/park) from the unit list session ref for player search scoping
		$_ref = $this->session->unit_list_ref ?? '';
		$_scope_kingdom_id = null;
		$_scope_park_id    = null;
		if (preg_match('/KingdomId=(\d+)/', $_ref, $_m)) $_scope_kingdom_id = (int)$_m[1];
		if (preg_match('/ParkId=(\d+)/',    $_ref, $_m)) $_scope_park_id    = (int)$_m[1];
		$this->data['ScopeKingdomId'] = $_scope_kingdom_id;
		$this->data['ScopeParkId']    = $_scope_park_id;
		$_uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
		if ($_uid > 0 && Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_UNIT, (int)$unit_id, AUTH_EDIT)) {
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
		$this->data['menu']['unit']  = array( 'url' => UIR."Unit/index/$unit_id", 'display' => $this->data['Unit']['Details']['Unit']['Name'] );
	}
	
	public function create($mundane_id) {
		if (trimlen($this->request->Action) > 0) {
			$this->request->save('Unit_create', true);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR.'Login/login/Unit/create/' . $mundane_id );
			} else {
				if ($_FILES['Heraldry']['size'] > 0 && Common::supported_mime_types($_FILES['Heraldry']['type'])) {
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
						'HeraldryMimeType' => $_FILES['Heraldry']['type'],
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