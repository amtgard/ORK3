<?php

class Controller_Admin extends Controller {

	public function __construct($call=null, $id=null) {
		parent::__construct($call, $id);

		$this->load_model('Park');
		$this->load_model('Kingdom');
		$this->data['Call'] = $call;
	}

	public function index($duh = null) {
		unset($this->session->kingdom_id);
		unset($this->session->kingdom_name);
		unset($this->session->park_name);
		unset($this->session->park_id);

		$this->data['ActiveKingdomSummary'] = $this->Report->GetActiveKingdomsSummary();
	}

    public function mergepark($submit = null) {
    	if ($submit == 'submit' && valid_id($this->request->FromParkId) && valid_id($this->request->ToParkId)) {
			$this->request->save('Admin_mergepark');
			$r = array('Status'=>0);
			if (!isset($this->session->user_id)) {
                logtrace('Header redirect: no user id', null);
				header( 'Location: '.UIR."Login/login/Admin/mergepark" );
			} else {
				$r = $this->Park->mergeparks( array(
						'Token' => $this->session->token,
						'FromParkId' => $this->request->Admin_mergepark->FromParkId,
						'ToParkId' => $this->request->Admin_mergepark->ToParkId
					));
				if ($r['Status'] == 0) {
					$this->data['Message'] = 'Parks merged.  <a href="' . UIR . 'Park/index/' . $this->request->Admin_mergepark->ToParkId . '">View your abomination here.</a>';
					$this->request->clear('Admin_mergepark');
				} else if($r['Status'] == 5) {
                    logtrace('Header redirect: bad status', $r);
					header( 'Location: '.UIR."Login/login/Admin/mergepark" );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}
    }

	public function mergeunit($submit = null) {
    	$this->load_model('Unit');
    	if ($submit == 'submit' && valid_id($this->request->FromUnitId) && valid_id($this->request->ToUnitId)) {
			$this->request->save('Admin_mergeunit');
			$r = array('Status'=>0);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR."Login/login/Admin/mergeunit" );
			} else {
				$r = $this->Unit->merge( array(
						'Token' => $this->session->token,
						'FromUnitId' => $this->request->Admin_mergeunit->FromUnitId,
						'ToUnitId' => $this->request->Admin_mergeunit->ToUnitId
					));
				if ($r['Status'] == 0) {
					$this->data['Message'] = 'Units merged.  <a href="' . UIR . 'Unit/index/' . $this->request->Admin_mergeunit->ToUnitId . '">View your abomination here.</a>';
					$this->request->clear('Admin_mergeunit');
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR."Login/login/Admin/mergeunit" );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}
    }

	public function transferpark($kingdom_id = 0) {
		$this->data['KingdomName'] = $this->Kingdom->get_kingdom_name($kingdom_id);
		$this->data['KingdomId'] = $kingdom_id;
		if ($this->request->Transfer == 'Transfer') {
			$this->request->save('Admin_transferpark');
			$r = array('Status'=>0);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR."Login/login/Admin/transferpark/$kingdom_id" );
			} else {
				$r = $this->Park->TransferPark( array(
						'Token' => $this->session->token,
						'ParkId' => $this->request->Admin_transferpark->ParkId,
						'KingdomId' => $kingdom_id
					));
				if ($r['Status'] == 0) {
					$this->data['Message'] = 'Park transferred to  ' . $this->data['KingdomName'];
					$this->request->clear('Admin_transferpark');
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR."Login/login/Admin/transferpark/$kingdom_id" );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}
	}

	public function unit($unit_id) {
    	unset($this->session->kingdom_id);
		unset($this->session->kingdom_name);
		unset($this->session->park_name);
		unset($this->session->park_id);

		$this->load_model('Unit');
		if (strlen($this->request->Action) > 0) {
			$this->request->save('Admin_unit', true);
			$r = array('Status'=>0);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR."Login/login/Admin/unit/$unit_id" );
			} else {
				switch ($this->request->Action) {
					case 'details':
						if ($_FILES['Heraldry']['size'] > 0 && Common::supported_mime_types($_FILES['Heraldry']['type'])) {
							if (move_uploaded_file($_FILES['Heraldry']['tmp_name'], DIR_TMP . sprintf("u_%05d", $unit_id))) {
								$h_im = file_get_contents(DIR_TMP . sprintf("u_%05d", $unit_id));
								$h_imdata = base64_encode($h_im);
							}
						}
						$r = $this->Unit->set_unit_details(array(
								'Token' => $this->session->token,
								'UnitId' => $unit_id,
								'Name' => $this->request->Admin_unit->Name,
								'Description' => $this->request->Admin_unit->Description,
								'History' => $this->request->Admin_unit->History,
								'Url' => $this->request->Admin_unit->Url,
								'Heraldry' => $h_imdata,
								'HeraldryMimeType' => $_FILES['Heraldry']['type']
							));
						break;
    				case 'giveup':
						$r = $this->Unit->convert_to_household($unit_id);
						break;
					case 'addauth':
						$r = $this->Unit->add_unit_auth(array(
								'Token' => $this->session->token,
								'Role' => AUTH_EDIT,
								'Type' => AUTH_UNIT,
								'Id' => $unit_id,
								'MundaneId' => $this->request->Admin_unit->MundaneId
							));
						break;
					case 'addmember':
						$r = $this->Unit->add_unit_member(array(
								'Token' => $this->session->token,
								'UnitId' => $unit_id,
								'MundaneId' => $this->request->Admin_unit->MundaneId,
								'Role' => $this->request->Admin_unit->Role,
								'Title' => $this->request->Admin_unit->Title,
								'Active' => 'Active'
							));
							break;
					case 'editmember':
						$r = $this->Unit->set_unit_member(array(
								'Token' => $this->session->token,
								'UnitMundaneId' => $this->request->Admin_unit->MundaneId,
								'Role' => $this->request->Admin_unit->Role,
								'Title' => $this->request->Admin_unit->Title,
								'Active' => 'Active'
							));
							break;
					case 'deleteauth':
						$r = $this->Unit->del_unit_auth(array(
								'Token' => $this->session->token,
								'AuthorizationId' => $this->request->Admin_unit->AuthorizationId
							));
						break;
					case 'retire':
						$r = $this->Unit->retire_unit_member(array(
								'Token' => $this->session->token,
								'UnitMundaneId' => $this->request->Admin_unit->UnitMundaneId,
								'UnitId' => $unit_id
							));
						break;
					case 'remove':
						$r = $this->Unit->remove_unit_member(array(
								'Token' => $this->session->token,
								'UnitMundaneId' => $this->request->Admin_unit->UnitMundaneId,
								'UnitId' => $unit_id
							));
						break;
				}
				if ($r['Status'] == 0) {
					$this->data['Message'] = 'Award recorded for ' . $this->request->Admin_unit->GivenTo;
					$this->request->clear('Admin_unit');
				} else if($r['Status'] == 5) {
					//header( 'Location: '.UIR."Login/login/Admin/unit/$unit_id" );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}

		if ($this->request->exists('Admin_unit')) {
			$this->data['Admin_unit'] = $this->request->Admin_unit->Request;
		}
		$this->data['Unit_heraldryurl'] = $this->Unit->get_heraldry($unit_id);
		$this->data['Unit'] = $this->Unit->get_unit_details($unit_id);
		$this->data['menu']['admin'] = array( 'url' => UIR."Admin/unit/$unit_id", 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		$this->data['menu']['unit'] = array( 'url' => UIR."Unit/index/$unit_id", 'display' => $this->data['Unit']['Details']['Unit']['Name'] );
	}

	public function editpark($park_id) {
		if (strlen($this->request->Action) > 0) {
			$this->request->save('Admin_editpark', true);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR.'Login/login/Admin/editpark/' . $park_id );
			} else if (isset($this->request->Admin_editpark)) {
				switch ($this->request->Action) {
					case 'details':
						if ($_FILES['Heraldry']['size'] > 0 && Common::supported_mime_types($_FILES['Heraldry']['type'])) {
							if (move_uploaded_file($_FILES['Heraldry']['tmp_name'], DIR_TMP . sprintf("p_%05d", $park_id))) {
								$h_im = file_get_contents(DIR_TMP . sprintf("p_%05d", $park_id));
								$h_imdata = base64_encode($h_im);
							} else {
								$Status = array(
									'Status' => 1000,
									'Error' => 'File IO Error',
									'Detail' => 'File could not be moved to .../tmp',
								);
							}
						}
						$Status = $this->Park->SetParkDetails(array(
								'Token' => $this->session->token,
								'ParkId' => $park_id,
								'Heraldry' => strlen($h_imdata)?$h_imdata:'',
								'HeraldryMimeType' => strlen($h_imdata)?$_FILES['Heraldry']['type']:'',
								'Url' => $this->request->Admin_editpark->Url,
								'Address' => $this->request->Admin_editpark->Address,
								'City' => $this->request->Admin_editpark->City,
								'Province' => $this->request->Admin_editpark->Province,
								'PostalCode' => $this->request->Admin_editpark->PostalCode,
								'MapUrl' => $this->request->Admin_editpark->MapUrl,
    							'Description' => $this->request->Admin_editpark->Description,
								'Directions' => $this->request->Admin_editpark->Directions
							));
						break;
					case 'config':
								$Status = array(
									'Status' => 1000,
									'Error' => 'Good Luck',
									'Detail' => 'Yeah, good luck with that.',
								);
						break;
					case 'addparkday':
						$Status = $this->Park->add_park_day(array(
							'Token' => $this->session->token,
							'ParkId' => $park_id,
							'Recurrence' => $this->request->Admin_editpark->Recurrence,
							'WeekDay' => $this->request->Admin_editpark->WeekDay,
							'WeekOfMonth' => $this->request->Admin_editpark->WeekOfMonth,
							'MonthDay' => $this->request->Admin_editpark->MonthDay,
							'Time' => $this->request->Admin_editpark->Time,
							'Purpose' => $this->request->Admin_editpark->Purpose,
							'Description' => $this->request->Admin_editpark->Description,
							'AlternateLocation' => $this->request->Admin_editpark->AlternateLocation=='on'?1:0,
							'Address' => $this->request->Admin_editpark->Address,
							'City' => $this->request->Admin_editpark->City,
							'Province' => $this->request->Admin_editpark->Province,
							'PostalCode' => $this->request->Admin_editpark->PostalCode,
							'MapUrl' => $this->request->Admin_editpark->MapUrl,
							'LocationUrl' => $this->request->Admin_editpark->Url,
						));
						break;
					case 'delete':
						$Status = $this->Park->delete_park_day(array(
								'Token' => $this->session->token,
								'ParkDayId' => $this->request->Admin_editpark->ParkDayId
							));
						break;
				}
				$error = false;
				if ($Status['Status'] == 5) {
					header( 'Location: '.UIR.'Login/login/Admin/editpark/' . $park_id );
				} else if ($Status['Status'] != 0) {
					$this->data['Error'] .= '<b>'.$Status['Error'].'</b>:<br />'.$Status['Detail'].'<p />';
					$error = true;
				}
				if (!$error) {
					$this->data['Message'] = "Parks have been updated.";
					$this->request->clear('Admin_editpark');
				}
			}
		}
		if ($this->request->exists('Admin_editpark')) {
			$this->data['Admin_editpark'] = $this->request->Admin_editpark->Request;
		}
		$data = $this->Park->get_park_details($park_id);
		$this->data['ParkId'] = $park_id;
		$this->data['Park_heraldry'] = $data['Heraldry'];
		$this->data['Park_data'] = $data['ParkInfo'];
		$this->data['Park_config'] = $data['ParkConfiguration'];
		$this->data['Park_days'] = $data['ParkDays'];
	}

	public function editparks($kingdom_id) {
		$this->load_model('Kingdom');
		if (strlen($this->request->Action) > 0) {
			$this->request->save('Admin_editparks', true);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR.'Login/login/Admin/editparks/' . $kingdom_id );
			} else {
				$request = array();
				foreach ($this->request->Admin_editparks->ParkTitle as $park_id => $title_id) {
					$request[] = array(
							'ParkId' => $park_id,
							'ParkName' => trim($this->request->Admin_editparks->ParkName[$park_id]),
							'ParkTitleId' => $title_id,
							'Abbreviation' => trim($this->request->Admin_editparks->Abbreviation[$park_id]),
							'Active' => trimlen($this->request->Admin_editparks->Active[$park_id])>0?'Active':'Retired'
						);
				}
				$r = $this->Kingdom->update_parks( $this->session->token, $request );
				$error = false;
				foreach ($r as $k => $Status) {
					if ($Status['Status'] == 5) {
						header( 'Location: '.UIR.'Login/login/Admin/editparks/' . $kingdom_id );
					} else if ($Status['Status'] != 0) {
						$this->data['Error'] .= '<b>'.$Status['Error'].'</b>:<br />'.$Status['Detail'].'<p />';
						$error = true;
					}
				}
				if (!$error) {
					$this->data['Message'] = "Parks have been updated.";
					$this->request->clear('Admin_editparks');
				}
			}
		}
		if ($this->request->exists('Admin_editparks')) {
			$this->data['Admin_editparks'] = $this->request->Admin_editparks->Request;
		}
		$this->data['KingdomId'] = $kingdom_id;
		$this->data['ParkInfo'] = $this->Kingdom->get_park_info($kingdom_id);
	}

	public function setkingdomofficers($post=null) {
		$this->load_model('Kingdom');
		if (strlen($post) > 0) {
			$this->request->save('Admin_setofficers', true);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR.'Login/login/Admin/setkingdomofficers' );
			} else {
				$officers = array();
				if (valid_id($this->request->Admin_setofficers->MonarchId))
					$officers['Monarch'] = array( 'MundaneId' =>$this->request->Admin_setofficers->MonarchId, 'Role' => 'Monarch' );
				if (valid_id($this->request->Admin_setofficers->Prime_MinisterId))
					$officers['Prime_Minister'] = array( 'MundaneId' =>$this->request->Admin_setofficers->Prime_MinisterId, 'Role' => 'Prime Minister' );
				if (valid_id($this->request->Admin_setofficers->RegentId))
					$officers['Regent'] = array( 'MundaneId' =>$this->request->Admin_setofficers->RegentId, 'Role' => 'Regent' );
				if (valid_id($this->request->Admin_setofficers->ChampionId))
					$officers['Champion'] = array( 'MundaneId' =>$this->request->Admin_setofficers->ChampionId, 'Role' => 'Champion' );
				$r = $this->Kingdom->set_officers($this->session->token, $this->session->kingdom_id, $officers);
				$error = false;
				foreach ($r as $k => $Status) {
					if ($Status['Status'] != 0) {
						$this->data['Error'] .= '<b>'.$r['Error'].'</b>:<br />'.$r['Detail'].'<p />';
						$error = true;
					} else if ($r['Status'] == 5) {
						header( 'Location: '.UIR.'Login/login/Admin/setkingdomofficers' );
					}
				}
				if (!$error) {
					$this->data['Message'] = "The Kingdom Officers have been updated.";
					$this->request->clear('Admin_setofficers');
				}
			}
		}
		$this->template = 'Admin_setofficers.tpl';
		if (($officers = $this->Kingdom->get_officers($this->request->KingdomId))) {
			$this->data['Officers'] = $officers;
		} else {
			$this->data['Officers'] = array();
		}
		$this->data['Type'] = 'KingdomId';
		$this->data['Id'] = $this->request->KingdomId;
		if ($this->request->exists('Admin_setofficers')) {
			$this->data['Admin_setofficers'] = $this->request->Admin_setofficers->Request;
		}
	}

	public function setparkofficers($post=null) {
		$this->load_model('Park');
		if (strlen($post) > 0) {
			$this->request->save('Admin_setofficers', true);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR.'Login/login/Admin/setparkofficers' );
			} else {
				$officers = array();
				if (valid_id($this->request->Admin_setofficers->MonarchId))
					$officers['Monarch'] = array( 'MundaneId' =>$this->request->Admin_setofficers->MonarchId, 'Role' => 'Monarch' );
				if (valid_id($this->request->Admin_setofficers->Prime_MinisterId))
					$officers['Prime_Minister'] = array( 'MundaneId' =>$this->request->Admin_setofficers->Prime_MinisterId, 'Role' => 'Prime Minister' );
				if (valid_id($this->request->Admin_setofficers->RegentId))
					$officers['Regent'] = array( 'MundaneId' =>$this->request->Admin_setofficers->RegentId, 'Role' => 'Regent' );
				if (valid_id($this->request->Admin_setofficers->ChampionId))
					$officers['Champion'] = array( 'MundaneId' =>$this->request->Admin_setofficers->ChampionId, 'Role' => 'Champion' );
				$r = $this->Park->set_officers($this->session->token, $this->session->park_id, $officers);
				$error = false;
				foreach ($r as $k => $Status) {
					if ($Status['Status'] != 0) {
						$this->data['Error'] .= '<b>'.$r['Error'].'</b>:<br />'.$r['Detail'].'<p />';
						$error = true;
					} else if ($r['Status'] == 5) {
						header( 'Location: '.UIR.'Login/login/Admin/setparkofficers' );
					}
				}
				if (!$error) {
					$this->data['Message'] = "The Park Officers have been updated.";
					$this->request->clear('Admin_setofficers');
				}
			}
		}
		$this->template = 'Admin_setofficers.tpl';
		if (($officers = $this->Park->get_officers($this->request->ParkId))) {
			$this->data['Officers'] = $officers;
		} else {
			$this->data['Officers'] = array();
		}
		$this->data['Type'] = 'ParkId';
		$this->data['Id'] = $this->session->park_id;
		if ($this->request->exists('Admin_setofficers')) {
			$this->data['Admin_setofficers'] = $this->request->Admin_setofficers->Request;
		}
	}

	public function event($p) {
		$params = explode('/',$p);
		$event_id = $params[0];
		if (count($params) > 1) $post = $params[1];

		logtrace("index($p)", $params);

		$this->load_model('Event');
		if (strlen($post) > 0) {
			$this->request->save('Admin_event', true);
			$r = array('Status'=>0);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR."Login/login/Admin/event/$event_id" );
			} else {
				logtrace("index($p)", $FILES);
				if ($_FILES['Heraldry']['size'] > 0 && Common::supported_mime_types($_FILES['Heraldry']['type'])) {
					if (move_uploaded_file($_FILES['Heraldry']['tmp_name'], DIR_TMP . sprintf("e_%05d", $event_id))) {
						$h_im = file_get_contents(DIR_TMP . sprintf("e_%05d", $event_id));
						$h_imdata = base64_encode($h_im);
					} else {
						$Status = array(
							'Status' => 1000,
							'Error' => 'File IO Error',
							'Detail' => 'File could not be moved to .../tmp',
						);
					}
				}
				logtrace("index($p)", array($h_im, $h_imdata));
				$edit = array(
							'Token' => $this->session->token,
							'EventId' => $event_id,
							'AtParkId' => $this->request->Admin_event->AtParkId,
							'Current' => $this->request->Admin_event->Current=='Yes'?1:0,
							'Price' => $this->request->Admin_event->Price,
							'EventStart' => $this->request->Admin_event->StartDate,
							'EventEnd' => $this->request->Admin_event->EndDate,
							'Description' => $this->request->Admin_event->Description,
							'Heraldry' => strlen($h_imdata)?$h_imdata:"",
							'HeraldryMimeType' => $_FILES['Heraldry']['type'],
							'Url' => $this->request->Admin_event->Url,
							'UrlName' => $this->request->Admin_event->UrlName,
							'Address' => $this->request->Admin_event->Address,
							'Province' => $this->request->Admin_event->State,
							'PostalCode' => $this->request->Admin_event->Zip,
							'City' => $this->request->Admin_event->City,
							'Country' => $this->request->Admin_event->Country,
							'MapUrl' => $this->request->Admin_event->MapUrl,
							'MapUrlName' => $this->request->Admin_event->MapUrlName,
						);
				switch ($post) {
					case 'update':
						$r = $this->Event->update_event(
								$this->session->token, $event_id, $this->request->Admin_event->KingdomId,
								$this->request->Admin_event->ParkId, $this->request->Admin_event->MundaneId,
								$this->request->Admin_event->UnitId, $this->request->Admin_event->Name,
								strlen($h_imdata)?$h_imdata:"", $_FILES['Heraldry']['type']
							);
						break;
					case 'edit':
						$edit['EventCalendarDetailId'] = $this->request->Admin_event->EventCalendarDetailId;
						$r = $this->Event->update_event_detail($edit);
						break;
					case 'new':
						$r = $this->Event->add_event_detail($edit);
						break;
					case 'delete':
						$r = $this->Event->delete_calendar_detail($this->session->token, $this->request->DetailId);
				}
				if ($r['Status'] == 0) {
					$this->request->clear('Admin_event');
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR."Login/login/Admin/event/$event_id" );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}

		$this->data['EventDetails'] = $this->Event->get_event_details($event_id);
		if ($this->data['EventDetails']['Status']['Status'] != 0) {
			$this->data['Error'] = $this->data['EventDetails']['Status']['Error'];
		}
		if ($this->request->exists('Admin_event')) {
			$this->data['Admin_event'] = $this->request->Admin_event->Request;
		}
		$this->data['menu']['event'] = array( 'url' => UIR.'Event/index/'.$event_id, 'display' => $this->data['EventDetails']['Name'] );
		$this->data['menu']['admin'] = array( 'url' => UIR.'Admin/event/'.$event_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
	}

	public function manageevent($post=null) {
		$this->load_model('Event');
		$this->request->save('Admin_manageevent', true);
		if (strlen($post) > 0) {
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR.'Login/login/Admin/event' );
			} else {
				$r = $this->Event->create_event(
									$this->session->token,
									$this->request->Admin_manageevent->CreateKingdomId,
									$this->request->Admin_manageevent->CreateParkId,
									$this->request->Admin_manageevent->CreateMundaneId,
									$this->request->Admin_manageevent->CreateUnitId,
									$this->request->Admin_manageevent->CreateEventName);
				if ($r['Status'] == 0) {
					$this->data['Message'] = "You have created a marvelous social revel.\n<p />Let the populace rejoice. ";
					$this->request->clear('Admin_manageevent');
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR.'Login' );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}
		if (valid_id($this->request->UnitId) || valid_id($this->request->Admin_manageevent->CreateUnitId)) {
			$this->load_model('Unit');
			$this->data['CreateUnitId'] = valid_id($this->request->Admin_manageevent->UnitId)?$this->request->Admin_manageevent->UnitId:$this->request->UnitId;
			if (valid_id($this->data['CreateUnitId'])) {
				$unit = $this->Unit->get_unit($this->data['CreateUnitId']);
				$this->data['menu']['player'] = array( 'url' => UIR."Unit/index/{$this->data[CreateUnitId]}", 'display' => $unit['Unit']['Name'] );
				$this->data['menu']['admin'] = array( 'url' => UIR."Admin/unit/{$this->data[CreateUnitId]}", 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
			}
		}
		if (valid_id($this->request->MundaneId) || valid_id($this->request->Admin_manageevent->CreateMundaneId)) {
			$this->load_model('Player');
			$this->data['CreateMundaneId'] = valid_id($this->request->Admin_manageevent->MundaneId)?$this->request->Admin_manageevent->MundaneId:$this->request->MundaneId;
			if (valid_id($this->data['CreateMundaneId'])) {
				$player = $this->Player->fetch_player($this->data['CreateMundaneId']);
				$this->data['menu']['player'] = array( 'url' => UIR."Player/index/{$this->data[CreateMundaneId]}", 'display' => $player['Persona'] );
				$this->data['menu']['admin'] = array( 'url' => UIR."Admin/player/{$this->data[CreateMundaneId]}", 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
			}
		}
		if ($this->request->exists('Admin_manageevent')) {
			$this->data['Admin_manageevent'] = $this->request->Admin_manageevent->Request;
		}
	}

	public function createevent($post=null) {
		$this->load_model('Event');
		$this->request->save('Admin_event', true);
		if (valid_id($this->request->UnitId) || valid_id($this->request->Admin_event->CreateUnitId)) {
			$this->data['Events_list'] = $this->Event->GetEvents(array('UnitId' => valid_id($this->request->Admin_event->UnitId)?$this->request->Admin_event->UnitId:$this->request->UnitId, 'LimitTo' => true));
			$this->data['EventIdSelector'] = 'UnitId';
		} else if (valid_id($this->request->MundaneId) || valid_id($this->request->Admin_event->CreateMundaneId)) {
			$this->data['Events_list'] = $this->Event->GetEvents(array('MundaneId' => valid_id($this->request->Admin_event->MundaneId)?$this->request->Admin_event->MundaneId:$this->request->MundaneId, 'LimitTo' => true));
			$this->data['EventIdSelector'] = 'MundaneId';
		} 
    if (isset($this->session->park_id) && valid_id($this->session->park_id)) {
			$this->data['Events_list'] = $this->Event->GetEvents(array('ParkId' => $this->session->park_id, 'LimitTo' => false));
			$this->data['EventIdSelector'] = 'ParkId';
		} else if (isset($this->session->kingdom_id) && valid_id($this->session->kingdom_id)) {
			$this->data['Events_list'] = $this->Event->GetEvents(array('KingdomId' => $this->session->kingdom_id, 'LimitTo' => true));
			$this->data['EventIdSelector'] = 'KingdomId';
		} else {
			$this->data['Events_list'] = array();
		}
		if ($this->request->exists('Admin_event')) {
			$this->data['Admin_event'] = $this->request->Admin_event->Request;
		}
	}

	public function authorization($post=null) {
		$this->load_model('Authorization');
		$this->load_model('Reports');
		if (strlen($post) > 0) {
			$this->request->save('Admin_authorization', true);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR.'Login/login/Admin/authorization' );
			} else {
				switch ($post) {
					case 'Add':
						$r = $this->Authorization->add_auth(array(
								'Token' => $this->session->token,
								'MundaneId' => $this->request->Admin_authorization->MundaneId,
								'Type' => $this->request->Admin_authorization->AuthType,
								'Id' => $this->request->Admin_authorization->Id,
								'Role' => $this->request->Admin_authorization->AuthRole
							));
						break;
					case 'Remove':
						$r = $this->Authorization->del_auth(array(
								'Token' => $this->session->token,
								'AuthorizationId' => $this->request->Admin_authorization->AuthorizationId
							));
						break;
				}
				if ($r['Status'] == 0) {
					$this->data['Message'] = "Authorization modified.";
					$this->request->clear('Admin_authorization');
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR.'Login' );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}
		if ($this->request->exists('Admin_authorization')) {
			$this->data['Admin_authorization'] = $this->request->Admin_authorization->Request;
		}
		$auths = $this->Reports->get_authorization_list('ALL',0,'NonOfficers');
		$this->data['NonOfficerAuths'] = $auths['Authorizations'];
		$this->data['DisplayAll'] = true;
		$this->data['AuthTypes'] = Array(
				'Park' => 'Park',
				'Kingdom' => 'Kingdom',
				'Event' => 'Event',
				'Unit' => 'Unit',
				'admin' => 'Administrator'
			);
		$this->data['AuthRoles'] = Array(
				'create' => 'Create',
				'edit' => 'Edit',
				'admin' => 'Administrator'
			);
	}

	public function player($id) {
		logtrace("player call", $_REQUEST);
		$this->load_model('Player');
		$this->load_model('Award');
		$this->load_model('Unit');

		$params = explode('/',$id);
		$id = $params[0];
		if (count($params) > 1)
			$action = $params[1];
		if (count($params) > 2)
			$roastbeef = $params[2];
		if (count($params) > 3)
			$detail_param = $params[3];


		$thePlayerDetails = $this->Player->fetch_player_details($id);
		if (strlen($action) > 0) {
			$this->request->save('Admin_player', true);
			$r = array('Status'=>0);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR."Login/login/Admin/player/$id" );
			} else {
				switch ($action) {
					case 'updateclasses':
						$class_update = array();
						if (is_array($this->request->Reconciled)) {
							foreach ($this->request->Reconciled as $class_id => $qty) {
								if ($thePlayerDetails['Classes'][$class_id]['Reconciled'] != $qty)
									$class_update[] = array( 'ClassId' => $class_id, 'Quantity' => $qty );
							}
							$this->Player->update_class_reconciliation(array( 'Token' => $this->session->token, 'MundaneId' => $id, 'Reconcile' => $class_update ));
						}
						break;
					case 'update':
					    if ($this->request->RemoveDues == 'Revoke Dues') {
							$this->load_model('Treasury');
					        $this->Treasury->RemoveLastDuesPaid(array(
					                'MundaneId' => $id,
					                'Token' => $this->session->token
					            ));
					    }
						if ($this->request->Update == 'Update Media') {
							if ($_FILES['Heraldry']['size'] > 0 && Common::supported_mime_types($_FILES['Heraldry']['type'])) {
								if (move_uploaded_file($_FILES['Heraldry']['tmp_name'], DIR_TMP . sprintf("h_%06d", $id))) {
									$h_im = file_get_contents(DIR_TMP . sprintf("h_%06d", $id));
									$h_imdata = base64_encode($h_im);
									$this->Player->SetHeraldry(array(
										'MundaneId' => $id,
										'Heraldry' => strlen($h_imdata)>0?$h_imdata:null,
										'HeraldryMimeType' => strlen($h_imdata)>0?$_FILES['Heraldry']['type']:'',
										'Token' => $this->session->token
									));
								}
							}
							if ($_FILES['Waiver']['size'] > 0 && Common::supported_mime_types($_FILES['Waiver']['type'])) {
								if (move_uploaded_file($_FILES['Waiver']['tmp_name'], DIR_TMP . sprintf("w_%06d", $id))) {
									$w_im = file_get_contents(DIR_TMP . sprintf("w_%06d", $id));
									$w_imdata = base64_encode($w_im);
									$this->Player->SetWaiver(array(
										'MundaneId' => $id,
										'HasImage' => strlen($pi_imdata),
										'Waivered' => strlen($w_imdata),
										'Waiver' => strlen($w_imdata)>0?$w_imdata:null,
										'WaiverMimeType' => strlen($w_imdata)>0?$_FILES['Waiver']['type']:'',
										'Token' => $this->session->token
									));
								}
							}
							if ($_FILES['PlayerImage']['size'] > 0 && Common::supported_mime_types($_FILES['PlayerImage']['type'])) {
								if (move_uploaded_file($_FILES['PlayerImage']['tmp_name'], DIR_TMP . sprintf("pi_%06d", $id))) {
									$pi_im = file_get_contents(DIR_TMP . sprintf("pi_%06d", $id));
									$pi_imdata = base64_encode($pi_im);
									$this->Player->SetImage(array(
										'MundaneId' => $id,
										'HasImage' => strlen($pi_imdata),
										'Image' => strlen($pi_imdata)>0?$pi_imdata:null,
										'ImageMimeType' => strlen($pi_imdata)>0?$_FILES['PlayerImage']['type']:'',
										'Token' => $this->session->token
									));
								}
							}
              if ($_FILES['PlayerFace']['size'] > 0 && Common::supported_mime_types($_FILES['PlayerFace']['type'])) {
                if (move_uploaded_file($_FILES['PlayerFace']['tmp_name'], DIR_TMP . sprintf("fi_%06d", $id))) {
                  $face_im = file_get_contents(DIR_TMP . sprintf("fi_%06d", $id));
                  $face_imdata = base64_encode($face_im);
                  $one = $this->Player->one_shot([
                      'MundaneId' => $id,
                      'Base64FaceImage' => $face_imdata
                    ]);
                    logtrace('One Shot.', $one);
                  unlink(DIR_TMP . sprintf("fi_%06d", $id));
                }
              }
						}
						if ($this->request->Update == 'Update Details') {
							if (valid_id($this->request->Admin_player->DuesSemesters)) {
								$this->load_model('Treasury');
								$duespaid = $this->Treasury->DuesPaidToPark(array(
									'Token' => $this->session->token,
									'MundaneId' => $id,
									'TransactionDate' => $this->request->Admin_player->DuesDate,
									'Semesters' => $this->request->Admin_player->DuesSemesters
								));
								if ($duespaid['Status'] > 0)
									$this->data['Message'] .= 'Problem adding dues: ' . print_r($duespaid['Detail'], true);
							}
							$r = $this->Player->update_player(array(
									'MundaneId' => $id,
									'GivenName' =>  html_decode($this->request->Admin_player->GivenName),
									'Surname' =>  html_decode($this->request->Admin_player->Surname),
									'Persona' =>  html_decode($this->request->Admin_player->Persona),
									'UserName' =>  html_decode($this->request->Admin_player->UserName),
									'Password' =>  $this->request->Admin_player->Password==$this->request->Admin_player->PasswordAgain?$this->request->Admin_player->Password:null,
									'Email' =>  html_decode($this->request->Admin_player->Email),
									'Restricted' =>  $this->request->Admin_player->Restricted=='Restricted'?1:0,
									'Active' =>  $this->request->Admin_player->Active=='Active'?1:0,
									'HasImage' => strlen($pi_imdata),
									'Image' => strlen($pi_imdata)>0?$pi_imdata:null,
									'ImageMimeType' => strlen($pi_imdata)>0?$_FILES['PlayerImage']['type']:'',
									'Heraldry' => strlen($h_imdata)>0?$h_imdata:null,
									'HeraldryMimeType' => strlen($h_imdata)>0?$_FILES['Heraldry']['type']:'',
									'Waivered' => $this->request->Admin_player->Waivered == 'Waivered' || strlen($w_imdata),
									'Waiver' => strlen($w_imdata)>0?$w_imdata:null,
									'WaiverMimeType' => strlen($w_imdata)>0?$_FILES['Waiver']['type']:'',
									'Token' => $this->session->token
								));
							if ($this->request->Admin_player->Password!=$this->request->Admin_player->PasswordAgain)
								$this->data['Error'] = 'Passwords do not match.';
						}
						break;
					case 'addaward':
                        if (!valid_id($id)) {
                            $this->data['Error'] = 'You must choose a recipient. Award not added!'; break;
                        }
                        if (!valid_id($this->request->Admin_player->KingdomAwardId)) {
                            $this->data['Error'] = 'You must choose an award. Award not added!'; break;
                        }
                        if (!valid_id($this->request->Admin_player->MundaneId)) {
                            $this->data['Error'] = 'Who gave this award? You should rethink your life decisions';
                        }
						$r = $this->Player->add_player_award(array(
								'Token' => $this->session->token,
								'RecipientId' => $id,
								'KingdomAwardId' => $this->request->Admin_player->KingdomAwardId,
								'CustomName' => $this->request->Admin_player->AwardName,
								'Rank' => $this->request->Admin_player->Rank,
								'Date' => $this->request->Admin_player->Date,
								'GivenById' => $this->request->Admin_player->MundaneId,
								'Note' => $this->request->Admin_player->Note,
								'ParkId' => valid_id($this->request->Admin_player->ParkId)?$this->request->Admin_player->ParkId:0,
								'KingdomId' => valid_id($this->request->Admin_player->KingdomId)?$this->request->Admin_player->KingdomId:0,
								'EventId' => valid_id($this->request->Admin_player->EventId)?$this->request->Admin_player->EventId:0
							));
						break;
					case 'deleteaward':
						$r = $this->Player->delete_player_award(array(
								'Token' => $this->session->token,
								'AwardsId' => $roastbeef
							));
						break;
					case 'revokeaward':
						$r = $this->Player->revoke_player_award(array(
								'Token' => $this->session->token,
								'AwardsId' => $roastbeef,
								'Revocation' => $detail_param
							));
						break;
					case 'revokeallawards':
						$r = $this->Player->revoke_all_awards(array(
								'Token' => $this->session->token,
								'MundaneId' => $id,
								'Revocation' => $roastbeef
							));
						break;
					case 'updateaward':
						$r = $this->Player->update_player_award(array(
								'Token' => $this->session->token,
								'AwardsId' => $roastbeef,
								'RecipientId' => $id,
								'AwardId' => $this->request->Admin_player->AwardId,
								'Rank' => $this->request->Admin_player->Rank,
								'Date' => $this->request->Admin_player->Date,
								'GivenById' => $this->request->Admin_player->MundaneId,
								'Note' => $this->request->Admin_player->Note,
								'ParkId' => valid_id($this->request->Admin_player->ParkId)?$this->request->Admin_player->ParkId:0,
								'KingdomId' => valid_id($this->request->Admin_player->KingdomId)?$this->request->Admin_player->KingdomId:0,
								'EventId' => valid_id($this->request->Admin_player->EventId)?$this->request->Admin_player->EventId:0
							));
						break;
					case 'quitunit':
						$r = $this->Unit->retire_unit_member( array ('UnitId' => $id, 'UnitMundaneId' => $roastbeef, 'Token' => $this->session->token) );
						break;
    				case 'deletenote':
						$r = $this->Player->remove_note( array ('NotesId' => $roastbeef, 'MundaneId' => $id, 'Token' => $this->session->token ) );
						break;
				}
				if ($r['Status'] == 0) {
					$this->data['Message'] .= 'Player has been updated:<blockquote>' . $r['Detail'] . '</blockquote>';
					$this->request->clear('Admin_player');
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR."Login/login/Admin/player/$id" );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		} else {
			$this->request->clear('Admin_player');
		}

		if ($this->request->exists('Admin_player')) {
			$this->data['Admin_player'] = $this->request->Admin_player->Request;
		}
		$this->data['KingdomId'] = $this->session->kingdom_id;
		$this->data['AwardOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Awards');
		$this->data['OfficerOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Officers');
		$this->data['Player'] = $this->Player->fetch_player($id);
		$this->data['Details'] = $this->Player->fetch_player_details($id);
    	$this->data['Notes'] = $this->Player->get_notes($id);
		$this->data['Units'] = $this->Unit->get_unit_list(array( 'MundaneId' => $id, 'IncludeCompanies' => 1, 'IncludeHouseHolds' =>1, 'IncludeEvents' => 1, 'ActiveOnly' => 1 ));
		$this->data['menu']['admin'] = array( 'url' => UIR."Admin/player/$id", 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		$this->data['menu']['player'] = array( 'url' => UIR."Player/index/$id", 'display' => $this->data['Player']['Persona'] );

	}

	public function player_bak($mundane_id) {
		$this->load_model('Player');
		$this->data['Player'] = $this->Player->fetch_player($mundane_id);
		$this->data['MundaneId'] = $mundane_id;
		$this->data['menu']['admin'] = array( 'url' => UIR."Admin/player/$mundane_id", 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		$this->data['menu']['player'] = array( 'url' => UIR."Player/index/$mundane_id", 'display' => $this->data['Player']['Persona'] );
	}

	public function mergeplayer($params=null) {
		$params = explode('/',$params);
		if ('submit' == $params[0]) {
			$post = 'submit';
		} else if ('park' == $params[0]) {
			$park_id = $params[1];
			$this->data['ParkId'] = $park_id;
			$this->data['KingdomId'] = $this->session->kingdom_id;
		} else if ('kingdom' == $params[0]) {
			$kingdom_id = $params[1];
			$this->data['KingdomId'] = $kingdom_id;
		}
		$this->load_model('Player');
		if (strlen($post) > 0) {
			$this->request->save('Admin_mergeplayer', true);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR.'Login/login/Admin/mergeplayer' );
			} else {
				$r = $this->Player->merge_player(array(
						'Token' => $this->session->token,
						'FromMundaneId' => $this->request->Admin_mergeplayer->FromMundaneId,
						'ToMundaneId' => $this->request->Admin_mergeplayer->ToMundaneId
					));
				if ($r['Status'] == 0) {
					$this->data['Message'] = "Player created. <a href='".UIR."Player/index/{$this->request->Admin_mergeplayer->ToMundaneId}'>View your abomination here.</a>";
					$this->request->clear('Admin_mergeplayer');
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR.'Login' );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}
		if ($this->request->exists('Admin_mergeplayer')) {
			$this->data['Admin_mergeplayer'] = $this->request->Admin_mergeplayer->Request;
		}
	}

	public function claimplayer($params=null) {
		$params = explode('/',$params);
		if ('submit' == $params[0]) {
			$post = 'submit';
		} else if ('park' == $params[0]) {
			$park_id = $params[1];
			$this->data['ParkId'] = $park_id;
			$this->data['KingdomId'] = $this->session->kingdom_id;
		} else if ('kingdom' == $params[0]) {
			$kingdom_id = $params[1];
			$this->data['KingdomId'] = $kingdom_id;
		}
		$this->load_model('Player');
		if (strlen($post) > 0) {
			$this->request->save('Admin_claimplayer', true);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR.'Login/login/Admin/moveplayer' );
			} else {
				$r = $this->Player->move_player(array(
						'Token' => $this->session->token,
						'MundaneId' => $this->request->Admin_claimplayer->MundaneId,
						'ParkId' => $this->request->Admin_claimplayer->ParkId
					));
				if ($r['Status'] == 0) {
					$this->data['Message'] = "Player has been moved to <a href='".UIR."Park/index/{$this->request->Admin_claimplayer->ParkId}'>their new home.</a>";
					$this->request->clear('Admin_claimplayer');
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR.'Login' );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}
		if ($this->request->exists('Admin_claimplayer')) {
			$this->data['Admin_claimplayer'] = $this->request->Admin_claimplayer->Request;
		}
		$this->template = 'Admin_moveplayer.tpl';
	}

	public function suspendplayer($post=null) {
		$this->load_model('Player');
		if (strlen($post) > 0) {
			$this->request->save('Admin_suspendplayer', true);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR.'Login/login/Admin/suspendplayer' );
			} else if (isset($this->request->Admin_suspendplayer->MundaneId)) {
				$suspended = isset($this->request->Admin_suspendplayer->Suspended) ? false : true;
				$r = $this->Player->suspend_player(array(
						'Token' => $this->session->token,
						'MundaneId' => $this->request->Admin_suspendplayer->MundaneId,
						'Suspended' => $suspended,
						'SuspendedById' => $this->request->Admin_suspendplayer->SuspendatorId,
						'SuspendedAt' => $this->request->Admin_suspendplayer->SuspendedAt,
						'SuspendedUntil' => $this->request->Admin_suspendplayer->SuspendedUntil,
						'Suspension' => $this->request->Admin_suspendplayer->Suspension,
					));
				if ($r['Status'] == 0) {
					$this->data['Message'] = "Player has been <b><a href='" . UIR . "Reports/suspended/Kingdom&id=" . $this->session->kingdom_id . "'>" .
						($suspended ?
						 "suspended" :
						 "UNsuspended") . "</a></b>";
					$this->request->clear('Admin_suspendplayer');
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR.'Login' );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			} else {
				$this->request->clear('Admin_suspendplayer');
			}
		}
		if ($this->request->exists('Admin_suspendplayer')) {
			$this->data['Admin_suspendplayer'] = $this->request->Admin_suspendplayer->Request;
		}
	}

	public function moveplayer($post=null) {
		$this->load_model('Player');
		if (strlen($post) > 0) {
			$this->request->save('Admin_moveplayer', true);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR.'Login/login/Admin/moveplayer' );
			} else {
				$r = $this->Player->move_player(array(
						'Token' => $this->session->token,
						'MundaneId' => $this->request->Admin_moveplayer->MundaneId,
						'ParkId' => $this->request->Admin_moveplayer->ParkId
					));
				if ($r['Status'] == 0) {
					$this->data['Message'] = "Player has been moved to <a href='".UIR."/Park/index/{$this->request->Admin_moveplayer->ParkId}'>their new home.</a>";
					$this->request->clear('Admin_moveplayer');
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR.'Login' );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}
		if ($this->request->exists('Admin_moveplayer')) {
			$this->data['Admin_moveplayer'] = $this->request->Admin_moveplayer->Request;
		}
	}

	public function createplayer($params=null) {
		$params = explode('/',$params);
		if ('submit' == $params[0]) {
			$post = 'submit';
		} else if ('park' == $params[0]) {
			$park_id = $params[1];
			$this->data['ParkId'] = $park_id;
			$this->data['KingdomId'] = $this->session->kingdom_id;
		} else if ('kingdom' == $params[0]) {
			$kingdom_id = $params[1];
			$this->data['KingdomId'] = $kingdom_id;
		}
		logtrace('createplayer', $_FILES);
		$this->load_model('Player');
		if (strlen($post) > 0) {
			$this->request->save('Admin_createplayer', true);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR.'Login/login/Admin/createplayer' );
			} else if ($_FILES["Heraldry"]["size"] > 0 && $_FILES["Heraldry"]["error"] > 0) {
				$this->data['Error'] = $_FILES["Heraldry"]["error"];
			} else if ($_FILES["Waiver"]["size"] > 0 && $_FILES["Waiver"]["error"] > 0)  {
				$this->data['Error'] = $_FILES["Waiver"]["error"];
			} else {
				if ($_FILES["Heraldry"]["size"] > 0) {
					move_uploaded_file($_FILES['Heraldry']['tmp_name'], DIR_ASSETS.'tmp/'.basename($_FILES['Heraldry']['tmp_name']));
					$heraldry = $this->encode_image_file(DIR_ASSETS.'tmp/'.basename($_FILES['Heraldry']['tmp_name']));
					unlink(DIR_ASSETS.'tmp/'.basename($_FILES['Heraldry']['tmp_name']));
				}
				if ($_FILES["Waiver"]["size"] > 0) {
					move_uploaded_file($_FILES['Waiver']['tmp_name'], DIR_ASSETS.'tmp/'.basename($_FILES['Waiver']['tmp_name']));
					$waiver = $this->encode_image_file(DIR_ASSETS.'tmp/'.basename($_FILES['Waiver']['tmp_name']));
					unlink(DIR_ASSETS.'tmp/'.basename($_FILES['Waiver']['tmp_name']));
				}
				if ($_FILES["PlayerImage"]["size"] > 0) {
					move_uploaded_file($_FILES['PlayerImage']['tmp_name'], DIR_ASSETS.'tmp/'.basename($_FILES['PlayerImage']['tmp_name']));
					$playerimage = $this->encode_image_file(DIR_ASSETS.'tmp/'.basename($_FILES['PlayerImage']['tmp_name']));
					unlink(DIR_ASSETS.'tmp/'.basename($_FILES['PlayerImage']['tmp_name']));
				}
				$r = $this->Player->CreatePlayer(array(
						'Token' => $this->session->token,
						'ParkId' => $this->request->Admin_createplayer->ParkId,
						'GivenName' => $this->request->Admin_createplayer->GivenName,
						'Surname' => $this->request->Admin_createplayer->Surname,
						'OtherName' => '',
						'UserName' => $this->request->Admin_createplayer->UserName,
						'Persona' => $this->request->Admin_createplayer->Persona,
						'Heraldry' => $heraldry,
						'Email' => $this->request->Admin_createplayer->Email,
						'Password' => $this->request->Admin_createplayer->Password,
						'Restricted' => $this->request->Admin_createplayer->Restricted,
						'Waivered' => $this->request->Admin_createplayer->Waivered,
						'Waiver' => $waiver,
						'HasImage' => strlen($playerimage),
						'Image' => strlen($playerimage)>0?$playerimage:null,
						'ImageMimeType' => strlen($playerimage)>0?$_FILES['PlayerImage']['type']:'',
						'IsActive' => 1,
					));
				if ($r['Status'] == 0) {
					$this->data['Message'] = "Player created. <a href='".UIR."Player/index/$r[Detail]'>View your spawn here.</a>";
					$this->request->clear('Admin_createplayer');
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR.'Login' );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}
		if ($this->request->exists('Admin_createplayer')) {
			$this->data['Admin_createplayer'] = $this->request->Admin_createplayer->Request;
		}
	}

	public function banplayer($post=null) {
		$this->load_model('Player');
		$this->load_model('Reports');
		if (strlen($post) > 0) {
			$this->request->save('Admin_banplayer', true);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR.'Login/login/Admin/banplayer' );
			} else {
				$r = $this->Player->set_ban(array(
						'Token' => $this->session->token,
						'MundaneId' => $this->request->Admin_banplayer->MundaneId,
						'Banned' => $this->request->Admin_banplayer->Ban
					));
				if ($r['Status'] == 0) {
					$this->data['Message'] = "Player is ".($this->request->Admin_banplayer->Ban?"banned.":"free.");
					$this->request->clear('Admin_banplayer');
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR.'Login' );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}
		if ($this->request->exists('Admin_banplayer')) {
			$this->data['Admin_banplayer'] = $this->request->Admin_banplayer->Request;
		}
		$this->data['banned_players'] = $this->Reports->player_roster($type, $this->request->id, null, 0, 1);
	}

	public function createkingdom($post=null) {
		if (strlen($post) > 0) {
			$this->request->save('Admin_createkingdom', true);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR.'Login/login/Admin/createkingdom' );
			} else {
				if ($_FILES['Heraldry']['size'] > 0 && Common::supported_mime_types($_FILES['Heraldry']['type'])) {
					if (move_uploaded_file($_FILES['Heraldry']['tmp_name'], DIR_TMP . sprintf("k_%04d", $id))) {
						$k_im = file_get_contents(DIR_TMP . sprintf("k_%04d", $id));
						$k_imdata = base64_encode($k_im);
					}
				}
				$r = $this->Kingdom->create_kingdom(array(
						'Token' => $this->session->token,
						'Name' => $this->request->Admin_createkingdom->Name,
						'ParentKingdomId' => $this->request->Admin_createkingdom->ParentKingdomId,
						'Abbreviation' => $this->request->Admin_createkingdom->Abbreviation,
						'Heraldry' => strlen($k_imdata)>0?$k_imdata:null,
						'HeraldryMimeType' => strlen($k_imdata)>0?$_FILES['Heraldry']['type']:'',
						'AveragePeriod' => $this->request->Admin_createkingdom->AveragePeriod,
						'AttendancePeriodType' => $this->request->Admin_createkingdom->AttendancePeriodType,
						'AttendanceWeeklyMinimum' => $this->request->Admin_createkingdom->AttendanceWeeklyMinimum,
    					'AttendanceDailyMinimum' => $this->request->Admin_createkingdom->AttendanceDailyMinimum,
						'AttendanceCreditMinimum' => $this->request->Admin_createkingdom->AttendanceCreditMinimum,
						'DuesPeriodType' => $this->request->Admin_createkingdom->DuesPeriodType,
						'DuesAmount' => $this->request->Admin_createkingdom->DuesAmount,
						'KingdomDuesTake' => $this->request->Admin_createkingdom->KingdomDuesTake
					));
				if ($r['Status'] == 0) {
					$this->request->clear('Admin_createkingdom');
					header( 'Location: '.UIR.'Kingdom/index/'.$r['Detail'] );
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR.'Login' );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}
		$this->data['AttendancePeriodType_options'] = array('month'=>'Month','week'=>'Week');
		$this->data['DuesPeriodType_options'] = array('month'=>'Month','week'=>'Week');
		if ($this->request->exists('Admin_createkingdom')) {
			$this->data['Admin_createkingdom'] = $this->request->Admin_createkingdom->Request;
		}
	}

	public function editkingdom($id) {
		$this->kingdom_route($id);

		if (isset($this->request->Action)) {
			$this->request->save('Admin_editkingdom', true);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR.'Login/login/Admin/editkingdom/' . $id );
			} else {
				switch ($this->request->Action) {
					case 'details':
						if ($_FILES['Heraldry']['size'] > 0 && Common::supported_mime_types($_FILES['Heraldry']['type'])) {
							if (move_uploaded_file($_FILES['Heraldry']['tmp_name'], DIR_TMP . sprintf("k_%04d", $id))) {
								$k_im = file_get_contents(DIR_TMP . sprintf("k_%04d", $id));
								$k_imdata = base64_encode($k_im);
							}
						}
						$r = $this->Kingdom->set_kingdom_details(array(
								'Token' => $this->session->token,
								'Name' => $this->request->Admin_editkingdom->Name,
								'Abbreviation' => $this->request->Admin_editkingdom->Abbreviation,
								'Heraldry' => strlen($k_imdata)>0?$k_imdata:null,
								'HeraldryMimeType' => strlen($k_imdata)>0?$_FILES['Heraldry']['type']:'',
								'KingdomId' => $id
							));
						break;
					case 'config':
						$config = array();
						foreach ($this->request->Admin_editkingdom->Config as $config_id => $value) {
							$config[] = array(
									'Action' => CFG_EDIT,
									'ConfigurationId' => $config_id,
									'Key' => null,
									'Value' => $value
								);
						}
						$r = $this->Kingdom->set_kingdom_details(array(
								'Token' => $this->session->token,
								'KingdomConfiguration' => $config,
								'KingdomId' => $id
							));
						break;
					case 'parktitles':
						if (is_array($this->request->Admin_editkingdom->Title)) {
							$title_edits = array();
							foreach ($this->request->Admin_editkingdom->Title as $ParkTitleId => $title) {
								$title_edits[] = array(
										'Action' => CFG_EDIT,
										'ParkTitleId' => $ParkTitleId,
										'Title' => $title,
										'Class' => $this->request->Admin_editkingdom->Class[$ParkTitleId],
										'MinimumAttendance' => $this->request->Admin_editkingdom->MinimumAttendance[$ParkTitleId],
										'MinimumCutoff' => $this->request->Admin_editkingdom->MinimumCutoff[$ParkTitleId],
										'Period' => $this->request->Admin_editkingdom->Period[$ParkTitleId],
										'PeriodLength' => $this->request->Admin_editkingdom->Length[$ParkTitleId],
									);
							}
						}
						if (strlen($this->request->Admin_editkingdom->Title['New']) > 0) {
							$title_edits[] = array(
									'Action' => CFG_ADD,
									'Title' => $this->request->Admin_editkingdom->Title['New'],
									'Class' => $this->request->Admin_editkingdom->Class['New'],
									'MinimumAttendance' => $this->request->Admin_editkingdom->MinimumAttendance['New'],
									'MinimumCutoff' => $this->request->Admin_editkingdom->MinimumCutoff['New'],
									'Period' => $this->request->Admin_editkingdom->Period['New'],
									'PeriodLength' => $this->request->Admin_editkingdom->Length['New'],
								);
						}
						$r = $this->Kingdom->set_kingdom_parktitles(array(
								'Token' => $this->session->token,
								'ParkTitles' => $title_edits,
								'KingdomId' => $id
							));
						break;
					case 'deletetitle':
						$r = $this->Kingdom->set_kingdom_parktitles(array(
								'Token' => $this->session->token,
								'ParkTitles' => array( 0 => array(
										'Action' => CFG_REMOVE,
										'ParkTitleId' => $this->request->Admin_editkingdom->ParkTitleId,
									)),
								'KingdomId' => $id
							));
						break;
					case 'awards':
						$KAwards = $this->Kingdom->GetAwardList(array( 'KingdomId' => $id, 'IsLadder' => 'Either', 'IsTitle' => 'Either' ));
						if (is_array($this->request->Admin_editkingdom->KingdomAwardName)) {
							$award_edits = array();
							foreach ($this->request->Admin_editkingdom->KingdomAwardName as $AwardId => $award) {
								if (
									$AwardId > 0 && (
									$this->request->Admin_editkingdom->KingdomAwardName[$AwardId] != $KAwards['Awards'][$AwardId]['KingdomAwardName'] ||
									$this->request->Admin_editkingdom->ReignLimit[$AwardId] != $KAwards['Awards'][$AwardId]['ReignLimit'] ||
									$this->request->Admin_editkingdom->MonthLimit[$AwardId] != $KAwards['Awards'][$AwardId]['MonthLimit'] ||
									(isset($this->request->Admin_editkingdom->IsTitle[$AwardId]) && $this->request->Admin_editkingdom->IsTitle[$AwardId] != $KAwards['Awards'][$AwardId]['IsTitle']) ||
									(isset($this->request->Admin_editkingdom->TitleClass[$AwardId]) && $this->request->Admin_editkingdom->TitleClass[$AwardId] != $KAwards['Awards'][$AwardId]['TitleClass']))
									) {
									$this->Kingdom->EditAward(array(
										'Token' => $this->session->token,
										'KingdomId' => $id,
										'KingdomAwardId' => $AwardId,
										'Name' => $this->request->Admin_editkingdom->KingdomAwardName[$AwardId],
										'ReignLimit' => $this->request->Admin_editkingdom->ReignLimit[$AwardId],
										'MonthLimit' => $this->request->Admin_editkingdom->MonthLimit[$AwardId],
										'IsTitle' => $this->request->Admin_editkingdom->IsTitle[$AwardId],
										'TitleClass' => $this->request->Admin_editkingdom->TitleClass[$AwardId]
									));
								}
							}
						}
						if (strlen($this->request->Admin_editkingdom->KingdomAwardName['New']) > 0) {
							$r = $this->Kingdom->CreateAward(array(
								'Token' => $this->session->token,
								'KingdomId' => $id,
								'AwardId' => $this->request->Admin_editkingdom->AwardId,
								'Name' => $this->request->Admin_editkingdom->KingdomAwardName[$AwardId],
								'ReignLimit' => $this->request->Admin_editkingdom->ReignLimit[$AwardId],
								'MonthLimit' => $this->request->Admin_editkingdom->MonthLimit[$AwardId],
								'IsTitle' => $this->request->Admin_editkingdom->IsTitle[$AwardId],
								'TitleClass' => $this->request->Admin_editkingdom->TitleClass[$AwardId]
							));
						}
						break;
					case 'deleteaward': {
						$this->Kingdom->RemoveAward(array(
							'Token' => $this->session->token,
							'KingdomId' => $id,
							'KingdomAwardId' => $this->request->Admin_editkingdom->KingdomAwardId,
						));
					}
				}
				if ($r['Status'] == 0) {
					$this->request->clear('Admin_editkingdom');
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR.'Login/login/Admin/editkingdom/' . $id );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}

		$this->data['AttendancePeriodType_options'] = array('month'=>'Month','week'=>'Week');
		$this->data['DuesPeriodType_options'] = array('month'=>'Month','week'=>'Week');
		$kingdom_info = $this->Kingdom->get_kingdom_details($id);
		$this->data['Kingdom_data'] = $kingdom_info['KingdomInfo'];
		$this->data['IsPrinz'] = $kingdom_info['KingdomInfo']['IsPrincipality'];
		$this->data['Kingdom_config'] = $kingdom_info['KingdomConfiguration'];
		$this->data['Kingdom_parktitles'] = $kingdom_info['ParkTitles'];
		$this->data['Kingdom_awards'] = $kingdom_info['Awards']['Awards'];
		$this->load_model('Award');
		$this->data['Canonical_awards'] = $this->Award->GetAwardList(array());
		$this->data['Kingdom_heraldryurl'] = $kingdom_info['Heraldry'];
	}

	public function createpark($params=null) {
		$params = explode('/',$params);
		if ('submit' == $params[0]) {
			$post = 'submit';
			$this->data['KingdomId'] = $this->session->kingdom_id;
		} else if ('park' == $params[0]) {
			$park_id = $params[1];
			$this->data['ParkId'] = $park_id;
			$this->data['KingdomId'] = $this->session->kingdom_id;
		} else if ('kingdom' == $params[0]) {
			$kingdom_id = $params[1];
			$this->data['KingdomId'] = $kingdom_id;
		}
		logtrace('createpark', $params);
		if (strlen($post) > 0) {
			$this->request->save('Admin_createpark', true);
			if (!isset($this->session->user_id)) {
				header('Location: '.UIR.'Login/login/Admin/createpark' . (($post!=null)?('/'.$post):''));
			} else if (trimlen($this->request->Admin_createpark->Name) == 0) {
				$this->data['Error'] = "Park must have a name.";
			} else if (trimlen($this->request->Admin_createpark->Abbreviation) == 0) {
				$this->data['Error'] = "Park must have an abbreviation.";
			} else if (!valid_id($this->request->Admin_createpark->kingdom_id)) {
				$this->data['Error'] = "Somehow, a Kingdom was not selected.  Good luck with that.";
			} else if (!valid_id($this->request->Admin_createpark->ParkTitleId)) {
				$this->data['Error'] = "Parks must have a title.";
			} else {
				$r = $this->Park->create_park(array(
						'Token' => $this->session->token,
						'Name' => $this->request->Admin_createpark->Name,
						'Abbreviation' => $this->request->Admin_createpark->Abbreviation,
						'KingdomId' => $this->session->kingdom_id,
						'ParkTitleId' => $this->request->Admin_createpark->ParkTitleId
					));
				if ($r['Status'] == 0) {
					$this->request->clear('Admin_createpark');
					//header( 'Location: '.UIR.'Park/index/'.$r['Detail'] );
				} else if($r['Status'] == 5) {
					header('Location: '.UIR.'Login/login/Admin/createpark' . (($post!=null)?('/'.$post):''));
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}
		$this->data['ParkTitleId_options'] = array();
		$r = $this->Kingdom->get_kingdom_details($this->session->kingdom_id);
		foreach ($r['ParkTitles'] as $key => $detail) {
			$this->data['ParkTitleId_options'][$detail['ParkTitleId']] = $detail['Title'];
		}
		if ($this->request->exists('Admin_createpark')) {
			$this->data['Admin_createpark'] = $this->request->Admin_createpark->Request;
		}
	}

	public function kingdom($id) {
		$this->kingdom_route($id);
		$r = $this->Kingdom->get_kingdom_details($id);
		foreach ($r as $key => $detail) {
			$this->data[$key] = $detail;
		}
		$this->data['IsPrinz'] = $this->data['KingdomInfo']['IsPrincipality'];
		$r = $this->Kingdom->get_park_summary($id);
		$this->data['park_summary'] = $r;
	}

	public function park($id) {
		$this->park_route($id);
		$r = $this->Park->get_park_info($id);
		foreach ($r as $key => $detail) {
			$this->data[$key] = $detail;
		}
	}

	private function kingdom_route($id) {
		$this->data['kingdom_id'] = $id;
		$this->session->kingdom_id = $id;

		if (isset($this->request->kingdom_name)) {
			$this->session->kingdom_name = $this->request->kingdom_name;
		} else if (!isset($this->session->kingdom_name)) {
			// Direct link
			$this->session->kingdom_name = $this->Kingdom->get_kingdom_name($id);
		}
		$this->data['kingdom_name'] = $this->session->kingdom_name;

		unset($this->session->park_id);
		unset($this->session->park_name);
		$this->data['menu']['admin'] = array( 'url' => UIR.'Admin/kingdom/'.$this->session->kingdom_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		$this->data['menu']['kingdom'] = array( 'url' => UIR.'Kingdom/index/'.$this->session->kingdom_id, 'display' => $this->session->kingdom_name );
	}

	private function park_route($id) {
		$this->session->park_id = $id;

		if (!isset($this->session->kingdom_id)) {
			// Direct link
			$park_info = $this->Park->get_park_info($id);
			$this->session->park_name = $park_info['ParkInfo']['ParkName'];
			$this->session->kingdom_id = $park_info['KingdomInfo']['KingdomId'];
			$this->session->kingdom_name = $park_info['KingdomInfo']['KingdomName'];
		}
		$this->data['kingdom_id'] = $this->session->kingdom_id;
		$this->data['park_id'] = $this->session->park_id;
		$this->data['kingdom_name'] = $this->session->kingdom_id;

		if (isset($this->request->park_name)) {
			$this->session->park_name = $this->request->park_name;
		}
		$this->data['park_name'] = $this->session->park_name;

		$this->data['menu']['admin'] = array( 'url' => UIR.'Admin/park/'.$this->session->park_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		$this->data['menu']['kingdom'] = array( 'url' => UIR.'Kingdom/index/'.$this->session->kingdom_id, 'display' => $this->session->kingdom_name );
		$this->data['menu']['park'] = array( 'url' => UIR.'Park/index/'.$this->session->park_id, 'display' => $this->session->park_name );
	}
}

?>
