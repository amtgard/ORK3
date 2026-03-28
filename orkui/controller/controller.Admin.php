<?php

class Controller_Admin extends Controller {

	public function __construct($call=null, $id=null) {
		parent::__construct($call, $id);
		if (!isset($this->session->user_id)) {
			error_log('ORK_DEBUG Header redirect: no user id: ' . json_encode(null));
			header( 'Location: '.UIR."Login" );
		} else {
			$this->load_model('Park');
			$this->load_model('Kingdom');
			$this->data['Call'] = $call;
			$this->data[ 'page_title' ] = "Admin Panel";
		}
	}

	public function index($duh = null) {
		unset($this->session->kingdom_id);
		unset($this->session->kingdom_name);
		unset($this->session->park_name);
		unset($this->session->park_id);
		unset($this->data['menu']['kingdom']);
		unset($this->data['menu']['park']);

		$this->data['ActiveKingdomSummary'] = $this->Report->GetActiveKingdomsSummary();
		$this->data['TotalActivePlayers'] = $this->Report->GetDistinctActivePlayerCount(26);

		// Year-over-year trend stats
		$thisYearStart = date('Y') . '-01-01';
		$lastYearStart = (date('Y') - 1) . '-01-01';
		$lastYearEnd   = date('Y-m-d', strtotime('-1 year')); // same day last year
		$now1yr        = date('Y-m-d');
		$prev1yrStart  = date('Y-m-d', strtotime('-2 years'));
		$prev1yrEnd    = date('Y-m-d', strtotime('-1 year'));
		global $DB;
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT
			  (SELECT COUNT(*) FROM " . DB_PREFIX . "awards WHERE entered_at >= '$thisYearStart' AND entered_at < '$now1yr') AS awards_cur,
			  (SELECT COUNT(*) FROM " . DB_PREFIX . "awards WHERE entered_at >= '$lastYearStart' AND entered_at < '$lastYearEnd') AS awards_prev,
			  (SELECT COUNT(*) FROM " . DB_PREFIX . "attendance WHERE date >= '$thisYearStart' AND date < '$now1yr' AND mundane_id > 0) AS att_cur,
			  (SELECT COUNT(*) FROM " . DB_PREFIX . "attendance WHERE date >= '$lastYearStart' AND date < '$lastYearEnd' AND mundane_id > 0) AS att_prev,
			  (SELECT COUNT(DISTINCT mundane_id) FROM " . DB_PREFIX . "attendance WHERE date >= '$prev1yrEnd' AND date < '$now1yr' AND mundane_id > 0) AS players_cur,
			  (SELECT COUNT(DISTINCT mundane_id) FROM " . DB_PREFIX . "attendance WHERE date >= '$prev1yrStart' AND date < '$prev1yrEnd' AND mundane_id > 0) AS players_prev,
			  (SELECT COUNT(*) FROM " . DB_PREFIX . "recommendations WHERE date_recommended >= '$thisYearStart' AND date_recommended < '$now1yr' AND deleted_at IS NULL) AS recs_cur,
			  (SELECT COUNT(*) FROM " . DB_PREFIX . "recommendations WHERE date_recommended >= '$lastYearStart' AND date_recommended < '$lastYearEnd' AND deleted_at IS NULL) AS recs_prev"
		);
		$trendStats = ['awards_cur'=>0,'awards_prev'=>0,'att_cur'=>0,'att_prev'=>0,'players_cur'=>0,'players_prev'=>0,'recs_cur'=>0,'recs_prev'=>0];
		if ($rs && $rs->Next()) {
			foreach ($trendStats as $k => $_) $trendStats[$k] = (int)$rs->$k;
		}
		$this->data['TrendStats'] = $trendStats;

		// Previous-period kingdom attendance for trend indicators
		$DB->Clear();
		$prevWkRs = $DB->DataSet(
			"SELECT COUNT(mw.mundane_id) AS att, mw.kingdom_id
			 FROM (
			     SELECT mundane_id, date_year, date_week3, kingdom_id
			     FROM " . DB_PREFIX . "attendance
			     WHERE date >  DATE_SUB(CURDATE(), INTERVAL 52 WEEK)
			       AND date <= DATE_SUB(CURDATE(), INTERVAL 26 WEEK)
			       AND mundane_id > 0
			     GROUP BY date_year, date_week3, mundane_id, kingdom_id
			 ) mw
			 GROUP BY mw.kingdom_id"
		);
		$prevWeekly = [];
		if ($prevWkRs) {
			while ($prevWkRs->Next()) $prevWeekly[(int)$prevWkRs->kingdom_id] = (int)$prevWkRs->att;
		}
		$DB->Clear();
		$prevMoRs = $DB->DataSet(
			"SELECT COUNT(mm.mundane_id) AS mo, mm.kingdom_id
			 FROM (
			     SELECT mundane_id, date_year, date_month, kingdom_id
			     FROM " . DB_PREFIX . "attendance
			     WHERE date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
			       AND date <  DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
			       AND mundane_id > 0
			     GROUP BY date_year, date_month, mundane_id, kingdom_id
			 ) mm
			 GROUP BY mm.kingdom_id"
		);
		$prevMonthly = [];
		if ($prevMoRs) {
			while ($prevMoRs->Next()) $prevMonthly[(int)$prevMoRs->kingdom_id] = (int)$prevMoRs->mo;
		}
		$this->data['PrevWeekly']  = $prevWeekly;
		$this->data['PrevMonthly'] = $prevMonthly;

		$this->template = '../revised-frontend/Admin_index.tpl';
	}

    public function mergepark($submit = null) {
    	if ($submit == 'submit' && valid_id($this->request->FromParkId) && valid_id($this->request->ToParkId)) {
			$this->request->save('Admin_mergepark');
			$r = array('Status'=>0);
			if (!isset($this->session->user_id)) {
                error_log('ORK_DEBUG Header redirect: no user id: ' . json_encode(null));
				header( 'Location: '.UIR."Login/login/Admin/mergepark" );
			} else {
				$r = $this->Park->mergeparks( array(
						'Token' => $this->session->token,
						'FromParkId' => $this->request->Admin_mergepark->FromParkId,
						'ToParkId' => $this->request->Admin_mergepark->ToParkId
					));
				if ($r['Status'] == 0) {
					$this->data['Message'] = 'Parks merged.  <a href="' . UIR . 'Park/profile/' . $this->request->Admin_mergepark->ToParkId . '">View your abomination here.</a>';
					$this->request->clear('Admin_mergepark');
				} else if($r['Status'] == 5) {
                    error_log('ORK_DEBUG Header redirect: bad status: ' . json_encode($r));
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
    				case 'tocompany':
						$r = $this->Unit->convert_to_company($unit_id);
						break;
					case 'addauth':
						$r = $this->Unit->add_unit_auth(array(
								'Token' => $this->session->token,
								'Role' => AUTH_CREATE,
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
		if ($this->data['LoggedIn']) {
			$this->data['menu']['admin'] = array( 'url' => UIR."Admin/unit/$unit_id", 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		}
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
		$this->template = '../revised-frontend/Admin_editparks.tpl';
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
		$this->data['KingdomId']   = $kingdom_id;
		$this->data['KingdomName'] = $this->Kingdom->get_kingdom_name($kingdom_id);
		$this->data['ParkInfo']    = $this->Kingdom->get_park_info($kingdom_id);
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
				if (valid_id($this->request->Admin_setofficers->GMRId))
					$officers['GMR'] = array( 'MundaneId' =>$this->request->Admin_setofficers->GMRId, 'Role' => 'GMR' );
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
		if (($officers = $this->Kingdom->get_officers($this->request->KingdomId, $this->session->token))) {
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
		$this->session->park_id = $this->request->ParkId;
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
				if (valid_id($this->request->Admin_setofficers->GMRId))
					$officers['GMR'] = array( 'MundaneId' =>$this->request->Admin_setofficers->GMRId, 'Role' => 'GMR' );
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
		if (($officers = $this->Park->get_officers($this->request->ParkId, $this->session->token))) {
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

	public function vacatekingdomofficer() {
		$this->load_model('Kingdom');
		$kingdom_id = $this->request->KingdomId;
		if (!isset($this->session->user_id)) {
			header('Location: ' . UIR . 'Login/login/Admin/setkingdomofficers');
		} else {
			$role = $this->request->Role;
			$r = $this->Kingdom->vacate_officer($kingdom_id, $role, $this->session->token);
			if (isset($r['Status']) && $r['Status'] != 0) {
				$this->data['Error'] = 'Could not vacate officer: ' . $r['Detail'];
			} else {
				$this->data['Message'] = "The $role position has been vacated.";
			}
		}
		$this->template = 'Admin_setofficers.tpl';
		if (($officers = $this->Kingdom->get_officers($kingdom_id, $this->session->token))) {
			$this->data['Officers'] = $officers;
		} else {
			$this->data['Officers'] = array();
		}
		$this->data['Type'] = 'KingdomId';
		$this->data['Id'] = $kingdom_id;
		$this->data['Call'] = 'setkingdomofficers';
	}

	public function vacateparkofficer() {
		$this->load_model('Park');
		$park_id = $this->request->ParkId;
		if (!isset($this->session->user_id)) {
			header('Location: ' . UIR . 'Login/login/Admin/setparkofficers');
		} else {
			$role = $this->request->Role;
			$r = $this->Park->vacate_officer($park_id, $role, $this->session->token);
			if (isset($r['Status']) && $r['Status'] != 0) {
				$this->data['Error'] = 'Could not vacate officer: ' . $r['Detail'];
			} else {
				$this->data['Message'] = "The $role position has been vacated.";
			}
		}
		$this->template = 'Admin_setofficers.tpl';
		if (($officers = $this->Park->get_officers($park_id, $this->session->token))) {
			$this->data['Officers'] = $officers;
		} else {
			$this->data['Officers'] = array();
		}
		$this->data['Type'] = 'ParkId';
		$this->data['Id'] = $park_id;
		$this->data['Call'] = 'setparkofficers';
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
		if ($this->data['LoggedIn']) {
			$this->data['menu']['admin'] = array( 'url' => UIR.'Admin/event/'.$event_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		}
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
				$this->data['menu']['player'] = array( 'url' => UIR."Unit/index/{$this->data['CreateUnitId']}", 'display' => $unit['Unit']['Name'] );
				if ($this->data['LoggedIn']) {
					$this->data['menu']['admin'] = array( 'url' => UIR."Admin/unit/{$this->data['CreateUnitId']}", 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
				}
			}
		}
		if (valid_id($this->request->MundaneId) || valid_id($this->request->Admin_manageevent->CreateMundaneId)) {
			$this->load_model('Player');
			$this->data['CreateMundaneId'] = valid_id($this->request->Admin_manageevent->MundaneId)?$this->request->Admin_manageevent->MundaneId:$this->request->MundaneId;
			if (valid_id($this->data['CreateMundaneId'])) {
				$player = $this->Player->fetch_player($this->data['CreateMundaneId']);
				$this->data['menu']['player'] = array( 'url' => UIR."Player/profile/{$this->data['CreateMundaneId']}", 'display' => $player['Persona'] );
				if ($this->data['LoggedIn']) {
					$this->data['menu']['admin'] = array( 'url' => UIR."Admin/player/{$this->data['CreateMundaneId']}", 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
				}
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

	public function permissions($path = null) {
		$parts    = explode('/', $path ?? '');
		$type     = in_array($parts[0] ?? '', ['Kingdom', 'Park', 'Event']) ? $parts[0] : null;
		$id       = (int)preg_replace('/[^0-9]/', '', $parts[1] ?? '');
		$detailId = (int)preg_replace('/[^0-9]/', '', $parts[2] ?? '');
		$uid = (int)($this->session->user_id ?? 0);

		// Global ORK view — no type/id provided
		if (!$type || !$id) {
			if (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN)) {
				header('Location: ' . UIR . 'Admin');
				exit;
			}
			global $DB;
			$DB->Clear();
			$rs = $DB->DataSet(
				"SELECT a.authorization_id, a.mundane_id, a.role, a.modified,
				        m.persona, m.username, m.given_name, m.surname,
				        DATE_SUB(m.token_expires, INTERVAL 72 HOUR) AS last_login,
				        lc.last_credit
				 FROM " . DB_PREFIX . "authorization a
				 LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = a.mundane_id
				 LEFT JOIN (SELECT mundane_id, MAX(date) AS last_credit FROM " . DB_PREFIX . "attendance WHERE credits > 0 GROUP BY mundane_id) lc ON lc.mundane_id = a.mundane_id
				 WHERE a.role = 'admin' AND a.kingdom_id = 0 AND a.park_id = 0 AND a.event_id = 0 AND a.unit_id = 0
				 ORDER BY m.persona"
			);
			$adminAuths = [];
			if ($rs) {
				while ($rs->Next()) {
					$adminAuths[] = [
						'AuthorizationId' => (int)$rs->authorization_id,
						'MundaneId'       => (int)$rs->mundane_id,
						'Modified'        => $rs->modified,
						'Persona'         => $rs->persona,
						'UserName'        => $rs->username,
						'GivenName'       => $rs->given_name,
						'Surname'         => $rs->surname,
						'LastLogin'       => $rs->last_login,
						'LastCredit'      => $rs->last_credit,
					];
				}
			}
			$DB->Clear();
			$rs2 = $DB->DataSet(
				"SELECT k.kingdom_id, k.name AS kingdom_name
				 FROM " . DB_PREFIX . "kingdom k
				 WHERE k.active = 'Active' AND k.parent_kingdom_id = 0
				 ORDER BY k.name"
			);
			$kingdoms = [];
			if ($rs2) {
				while ($rs2->Next()) {
					$kingdoms[] = ['KingdomId' => (int)$rs2->kingdom_id, 'KingdomName' => $rs2->kingdom_name];
				}
			}
			$this->data['AdminAuths'] = $adminAuths;
			$this->data['Kingdoms']   = $kingdoms;
			$this->template = 'Admin_permissions_global.tpl';
			return;
		}
		$authTypeMap = ['Kingdom' => AUTH_KINGDOM, 'Park' => AUTH_PARK, 'Event' => AUTH_EVENT];
		$authType = $authTypeMap[$type];
		if (!Ork3::$Lib->authorization->HasAuthority($uid, $authType, $id, AUTH_CREATE)) {
			$backUrl = $type === 'Event'
				? UIR . 'Event/detail/' . $id . ($detailId ? '/' . $detailId : '')
				: UIR . ($type === 'Kingdom' ? 'Kingdom/profile/' : 'Park/profile/') . $id;
			header('Location: ' . $backUrl);
			exit;
		}

		$this->load_model('Reports');
		$this->template = 'Admin_permissions.tpl';

		if ($type === 'Kingdom') {
			$this->load_model('Kingdom');
			$info = $this->Kingdom->get_kingdom_shortinfo($id);
			$name = $info['Info']['KingdomInfo']['KingdomName'] ?? 'Kingdom ' . $id;
			$url  = UIR . 'Kingdom/profile/' . $id;
		} elseif ($type === 'Park') {
			$this->load_model('Park');
			$info = $this->Park->get_park_details($id);
			$name = $info['ParkInfo']['ParkName'] ?? 'Park ' . $id;
			$url  = UIR . 'Park/profile/' . $id;
		} else { // Event
			$this->load_model('Event');
			$info = $this->Event->get_event_details($id);
			$name = $info['Name'] ?? 'Event ' . $id;
			$url  = UIR . 'Event/detail/' . $id . ($detailId ? '/' . $detailId : '');
		}

		// All grants at this type+id level (officers + non-officers), including modified timestamp
		global $DB;
		$eid = (int)$id;
		$scopeColMap = ['Kingdom' => 'a.kingdom_id', 'Park' => 'a.park_id', 'Event' => 'a.event_id'];
		$scopeCol    = $scopeColMap[$type];
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT a.authorization_id, a.mundane_id, a.role, a.modified,
			        m.persona, m.username, m.given_name, m.surname, m.restricted,
			        o.role AS officer_role, o.officer_id
			 FROM " . DB_PREFIX . "authorization a
			 LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = a.mundane_id
			 LEFT JOIN " . DB_PREFIX . "officer o ON o.authorization_id = a.authorization_id
			 WHERE $scopeCol = $eid
			 ORDER BY m.persona"
		);
		$auths = [];
		if ($rs) {
			while ($rs->Next()) {
				$auths[] = [
					'AuthorizationId' => (int)$rs->authorization_id,
					'MundaneId'       => (int)$rs->mundane_id,
					'Role'            => $rs->role,
					'Modified'        => $rs->modified,
					'Persona'         => $rs->persona,
					'UserName'        => $rs->username,
					'GivenName'       => $rs->given_name,
					'Surname'         => $rs->surname,
					'OfficerRole'     => $rs->officer_role,
					'OfficerId'       => $rs->officer_id,
				];
			}
		}

		// For kingdom pages: all park-level grants for every park in the kingdom
		$parkAuths = [];
		if ($type === 'Kingdom') {
			$DB->Clear();
			$rs = $DB->DataSet(
				"SELECT a.authorization_id, a.mundane_id, a.park_id, a.role, a.modified,
				        p.name AS park_name, m.persona, m.username, m.given_name, m.surname, m.restricted,
				        o.role AS officer_role, o.officer_id
				 FROM " . DB_PREFIX . "authorization a
				 JOIN " . DB_PREFIX . "park p ON p.park_id = a.park_id
				 LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = a.mundane_id
				 LEFT JOIN " . DB_PREFIX . "officer o ON o.authorization_id = a.authorization_id
				 WHERE p.kingdom_id = $eid
				 ORDER BY p.name, m.persona"
			);
			if ($rs) {
				while ($rs->Next()) {
					$parkAuths[] = [
						'AuthorizationId' => (int)$rs->authorization_id,
						'MundaneId'       => (int)$rs->mundane_id,
						'ParkId'          => (int)$rs->park_id,
						'ParkName'        => $rs->park_name,
						'Role'            => $rs->role,
						'Modified'        => $rs->modified,
						'Persona'         => $rs->persona,
						'UserName'        => $rs->username,
						'GivenName'       => $rs->given_name,
						'Surname'         => $rs->surname,
						'OfficerRole'     => $rs->officer_role,
						'OfficerId'       => $rs->officer_id,
					];
				}
			}
		}

		// For event pages: show inherited access (creator + park/kingdom grant holders)
		$eventCreator      = null;
		$inheritedParkAuths     = [];
		$inheritedKingdomAuths  = [];
		$inheritedParkName      = '';
		$inheritedKingdomName   = '';
		if ($type === 'Event') {
			$DB->Clear();
			$evRow = $DB->DataSet(
				"SELECT e.mundane_id AS creator_id, e.park_id AS ev_park_id, e.kingdom_id AS ev_kingdom_id,
				        m.persona AS creator_persona, m.given_name, m.surname,
				        p.name AS park_name, k.name AS kingdom_name
				 FROM " . DB_PREFIX . "event e
				 LEFT JOIN " . DB_PREFIX . "mundane m  ON m.mundane_id = e.mundane_id
				 LEFT JOIN " . DB_PREFIX . "park p     ON p.park_id    = e.park_id
				 LEFT JOIN " . DB_PREFIX . "kingdom k  ON k.kingdom_id = e.kingdom_id
				 WHERE e.event_id = $eid LIMIT 1"
			);
			$evParkId = 0; $evKingdomId = 0;
			if ($evRow && $evRow->Next()) {
				$evParkId    = (int)$evRow->ev_park_id;
				$evKingdomId = (int)$evRow->ev_kingdom_id;
				$inheritedParkName    = $evRow->park_name    ?? '';
				$inheritedKingdomName = $evRow->kingdom_name ?? '';
				if ((int)$evRow->creator_id > 0) {
					$eventCreator = [
						'MundaneId' => (int)$evRow->creator_id,
						'Persona'   => $evRow->creator_persona,
						'GivenName' => $evRow->given_name,
						'Surname'   => $evRow->surname,
					];
				}
			}

			// Park-level grant holders for the event's host park
			if ($evParkId) {
				$DB->Clear();
				$rs = $DB->DataSet(
					"SELECT a.authorization_id, a.mundane_id, a.role,
					        m.persona, m.given_name, m.surname,
					        o.role AS officer_role
					 FROM " . DB_PREFIX . "authorization a
					 LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = a.mundane_id
					 LEFT JOIN " . DB_PREFIX . "officer o ON o.authorization_id = a.authorization_id
					 WHERE a.park_id = $evParkId
					 ORDER BY a.role DESC, m.persona"
				);
				if ($rs) {
					while ($rs->Next()) {
						$inheritedParkAuths[] = [
							'MundaneId'   => (int)$rs->mundane_id,
							'Role'        => $rs->role,
							'Persona'     => $rs->persona,
							'GivenName'   => $rs->given_name,
							'Surname'     => $rs->surname,
							'OfficerRole' => $rs->officer_role,
						];
					}
				}
			}

			// Kingdom-level grant holders for the event's kingdom
			if ($evKingdomId) {
				$DB->Clear();
				$rs = $DB->DataSet(
					"SELECT a.authorization_id, a.mundane_id, a.role,
					        m.persona, m.given_name, m.surname,
					        o.role AS officer_role
					 FROM " . DB_PREFIX . "authorization a
					 LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = a.mundane_id
					 LEFT JOIN " . DB_PREFIX . "officer o ON o.authorization_id = a.authorization_id
					 WHERE a.kingdom_id = $evKingdomId
					 ORDER BY a.role DESC, m.persona"
				);
				if ($rs) {
					while ($rs->Next()) {
						$inheritedKingdomAuths[] = [
							'MundaneId'   => (int)$rs->mundane_id,
							'Role'        => $rs->role,
							'Persona'     => $rs->persona,
							'GivenName'   => $rs->given_name,
							'Surname'     => $rs->surname,
							'OfficerRole' => $rs->officer_role,
						];
					}
				}
			}
		}

		// Set breadcrumbs from the actual entity, not the logged-in user's session kingdom/park
		unset($this->data['menu']['kingdom'], $this->data['menu']['park']);
		if ($type === 'Kingdom') {
			$this->data['menu']['kingdom'] = ['url' => $url, 'display' => $name];
		} elseif ($type === 'Park') {
			$this->data['menu']['park'] = ['url' => $url, 'display' => $name];
		} elseif ($type === 'Event') {
			if (!empty($evKingdomId)) {
				$this->data['menu']['kingdom'] = ['url' => UIR . 'Kingdom/profile/' . $evKingdomId, 'display' => $inheritedKingdomName];
			}
			if (!empty($evParkId)) {
				$this->data['menu']['park'] = ['url' => UIR . 'Park/profile/' . $evParkId, 'display' => $inheritedParkName];
			}
			$this->data['menu']['event'] = ['url' => $url, 'display' => $name];
		}

		$this->data['PermType']         = $type;
		$this->data['PermId']           = $id;
		$this->data['PermName']         = $name;
		$this->data['PermUrl']          = $url;
		$this->data['PermAuths']        = $auths;
		$this->data['PermParkAuths']    = $parkAuths;
		$this->data['PermCanGrantAdmin']      = Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN);
		$this->data['PermEventCreator']       = $eventCreator;
		$this->data['PermInheritedParkAuths']    = $inheritedParkAuths;
		$this->data['PermInheritedKingdomAuths'] = $inheritedKingdomAuths;
		$this->data['PermInheritedParkName']     = $inheritedParkName;
		$this->data['PermInheritedKingdomName']  = $inheritedKingdomName;
	}

	public function player($id) {
		logtrace("player call", $_REQUEST);
		$this->load_model('Player');
		$this->load_model('Award');
		$this->load_model('Unit');
		$this->load_model('Pronoun');

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
								if ((int) $_FILES['Heraldry']['size'] / 1.333 > 465000) {
									$this->data['Error'] = 'Image Error: File size is too large.';
									$r['Status'] = NULL;
								} else {
									if (is_dir(DIR_TMP) && is_writable(DIR_TMP)) {
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
									} else {
										die('TMP_DIR is not writable.');
									}
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
								if ((int) $_FILES['PlayerImage']['size'] * 1.333 > 465000) {
									$this->data['Error'] = 'Image Error: File size is too large.';
									$r['Status'] = NULL;
								} else {
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
							}
							if ($_FILES['PlayerFace']['size'] > 0 && Common::supported_mime_types($_FILES['PlayerFace']['type'])) {
								if (move_uploaded_file($_FILES['PlayerFace']['tmp_name'], DIR_TMP . sprintf("fi_%06d", $id))) {
								$face_im = file_get_contents(DIR_TMP . sprintf("fi_%06d", $id));
								$face_imdata = base64_encode($face_im);
								$one = $this->Player->one_shot([
									'MundaneId' => $id,
									'Base64FaceImage' => $face_imdata
									]);
									error_log('ORK_DEBUG One Shot.: ' . json_encode($one));
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
									'PronounId' =>  $this->request->Admin_player->PronounId,
									'PronounCustom' =>  $this->request->Admin_player->PronounCustom,
									'UserName' =>  html_decode($this->request->Admin_player->UserName),
									'Password' =>  $this->request->Admin_player->Password==$this->request->Admin_player->PasswordAgain?$this->request->Admin_player->Password:null,
									'Email' =>  html_decode($this->request->Admin_player->Email),
									'Restricted' =>  $this->request->Admin_player->Restricted=='Restricted'?1:0,
									'Active' =>  $this->request->Admin_player->Active=='Active'?1:0,
									'ParkMemberSince' => $this->request->Admin_player->ParkMemberSince,
									'HasImage' => strlen($pi_imdata),
									'Image' => strlen($pi_imdata)>0?$pi_imdata:null,
									'ImageMimeType' => strlen($pi_imdata)>0?$_FILES['PlayerImage']['type']:'',
									'Heraldry' => strlen($h_imdata)>0?$h_imdata:null,
									'HeraldryMimeType' => strlen($h_imdata)>0?$_FILES['Heraldry']['type']:'',
									'Waivered' => $this->request->Admin_player->Waivered == 'Waivered' || strlen($w_imdata),
									'Waiver' => strlen($w_imdata)>0?$w_imdata:null,
									'WaiverMimeType' => strlen($w_imdata)>0?$_FILES['Waiver']['type']:'',
									'ReeveQualified' => ($this->request->Admin_player->ReeveQualified == 1)?1:0,
									'ReeveQualifiedUntil' => strlen($this->request->Admin_player->ReeveQualifiedUntil)>0?date('Y-m-d', strtotime($this->request->Admin_player->ReeveQualifiedUntil ?? '')):null,
									'CorporaQualified' => ($this->request->Admin_player->CorporaQualified == 1)?1:0,
									'CorporaQualifiedUntil' => strlen($this->request->Admin_player->CorporaQualifiedUntil)>0?date('Y-m-d', strtotime($this->request->Admin_player->CorporaQualifiedUntil ?? '')):null,
									'Token' => $this->session->token
								));
							if ($this->request->Admin_player->Password!=$this->request->Admin_player->PasswordAgain)
								$this->data['Error'] = 'Passwords do not match.';
						}
						break;
					case 'removeheraldry':
					$this->Player->RemoveHeraldry(['MundaneId' => $id, 'Token' => $this->session->token]);
					break;
				case 'removepicture':
					$this->Player->RemoveImage(['MundaneId' => $id, 'Token' => $this->session->token]);
					break;
				case 'addaward':
                        if (!valid_id($id)) {
                            $this->data['Error'] = 'You must choose a recipient. Award not added!'; break;
                        }
                        if (!valid_id($this->request->Admin_player->KingdomAwardId)) {
                            $this->data['Error'] = 'You must choose an award. Award not added!'; break;
                        }
                        if (!valid_id($this->request->Admin_player->GivenById)) {
                            $this->data['Error'] = 'Who gave this award? Award not added!'; break;
                        }
						$r = $this->Player->add_player_award(array(
								'Token' => $this->session->token,
								'RecipientId' => $id,
								'KingdomAwardId' => $this->request->Admin_player->KingdomAwardId,
								'CustomName' => $this->request->Admin_player->AwardName,
								'Rank' => $this->request->Admin_player->Rank,
								'Date' => $this->request->Admin_player->Date,
								'GivenById' => $this->request->Admin_player->GivenById,
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
								'GivenById' => $this->request->Admin_player->GivenById,
								'Note' => $this->request->Admin_player->Note,
								'ParkId' => valid_id($this->request->Admin_player->ParkId)?$this->request->Admin_player->ParkId:0,
								'KingdomId' => valid_id($this->request->Admin_player->KingdomId)?$this->request->Admin_player->KingdomId:0,
								'EventId' => valid_id($this->request->Admin_player->EventId)?$this->request->Admin_player->EventId:0
							));
						break;
					case 'reconcileaward':
						$reconcile_params = array(
								'Token' => $this->session->token,
								'AwardsId' => $roastbeef,
								'KingdomAwardId' => $this->request->Admin_player->KingdomAwardId,
								'Rank' => $this->request->Admin_player->Rank,
								'Date' => $this->request->Admin_player->Date,
								'GivenById' => $this->request->Admin_player->GivenById,
								'Note' => $this->request->Admin_player->Note,
								'ParkId' => valid_id($this->request->Admin_player->ParkId)?$this->request->Admin_player->ParkId:0,
								'KingdomId' => valid_id($this->request->Admin_player->KingdomId)?$this->request->Admin_player->KingdomId:0,
								'EventId' => valid_id($this->request->Admin_player->EventId)?$this->request->Admin_player->EventId:0
						);
						$r = $this->Player->reconcile_player_award($reconcile_params);
						break;
					case 'quitunit':
						$r = $this->Unit->retire_unit_member( array ('UnitId' => $id, 'UnitMundaneId' => $roastbeef, 'Token' => $this->session->token) );
						break;
    				case 'deletenote':
						$r = $this->Player->remove_note( array ('NotesId' => $roastbeef, 'MundaneId' => $id, 'Token' => $this->session->token ) );
						break;
    				case 'adddues':
                        if (!valid_id($id)) {
                            $this->data['Error'] = 'Invalid player selection, please try again.'; break;
                        }
                        if (!valid_id($this->request->Admin_player->KingdomId)) {
                            $this->data['Error'] = 'Invalid Kingdom, please try again.'; break;
                        }
						$r = $this->Player->add_dues(array(
								'MundaneId' => $id,
								'ParkId' => valid_id($this->request->Admin_player->ParkId)?$this->request->Admin_player->ParkId:0,
								'KingdomId' => valid_id($this->request->Admin_player->KingdomId)?$this->request->Admin_player->KingdomId:0,
								'DuesFrom' => $this->request->Admin_player->DuesFrom,
								'Terms' => $this->request->Admin_player->Terms,
								'Months' => $this->request->Admin_player->Months,
								'DuesPeriodType' => $this->request->Admin_player->DuesPeriodType,
								'DuesForLife' => $this->request->Admin_player->DuesForLife
							));
						break;
					case 'revokedues':
						$r = $this->Player->revoke_dues(array(
							'Token' => $this->session->token,
							'DuesId' => $roastbeef,
						));
						break;
				}
				if (strlen($this->data['Error']) == 0) {
					if ($r['Status'] == 0) {
						$this->data['Message'] .= 'Player has been updated:<blockquote>' . $r['Detail'] . '</blockquote>';
						$this->request->clear('Admin_player');
					} else if($r['Status'] == 5) {
						header( 'Location: '.UIR."Login/login/Admin/player/$id" );
					} else {
						$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
					}
				}
			}
		} else {
			$this->request->clear('Admin_player');
		}

		if ($this->request->exists('Admin_player')) {
			$this->data['Admin_player'] = $this->request->Admin_player->Request;
		}
		$this->data['KingdomId'] = $this->session->kingdom_id;
		$this->data['KingdomConfig'] = Common::get_configs($this->session->kingdom_id);
		$this->data['AwardOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Awards');
		$this->data['OfficerOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Officers');
		$this->data['Player'] = $this->Player->fetch_player($id);

		// Preload Kingdom and Park Monarch/Regent for GivenBy autocomplete
		$this->load_model('Kingdom');
		$this->load_model('Park');
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
		$this->data['PronounOptions'] = $this->Pronoun->fetch_pronoun_option_list($this->data['Player']['PronounId']);
		$this->data['PronounList'] = $this->Pronoun->fetch_pronoun_list();
		$this->data['Details'] = $this->Player->fetch_player_details($id);
    	$this->data['Notes'] = $this->Player->get_notes($id);
    	$this->data['Dues'] = $this->Player->get_dues($id);
		$this->data['Units'] = $this->Unit->get_unit_list(array( 'MundaneId' => $id, 'IncludeCompanies' => 1, 'IncludeHouseHolds' =>1, 'IncludeEvents' => 1, 'ActiveOnly' => 1 ));
		if ($this->data['LoggedIn']) {
			$this->data['menu']['admin'] = array( 'url' => UIR."Admin/player/$id", 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		}
		$this->data['menu']['player'] = array( 'url' => UIR."Player/profile/$id", 'display' => $this->data['Player']['Persona'] );
		$this->data[ 'page_title' ] = "Admin: " . $this->data['Player']['Persona'];
	}

	public function player_bak($mundane_id) {
		$this->load_model('Player');
		$this->data['Player'] = $this->Player->fetch_player($mundane_id);
		$this->data['MundaneId'] = $mundane_id;
		if ($this->data['LoggedIn']) {
			$this->data['menu']['admin'] = array( 'url' => UIR."Admin/player/$mundane_id", 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		}
		$this->data['menu']['player'] = array( 'url' => UIR."Player/profile/$mundane_id", 'display' => $this->data['Player']['Persona'] );
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
					$this->data['Message'] = "Player created. <a href='".UIR."Player/profile/{$this->request->Admin_mergeplayer->ToMundaneId}'>View your abomination here.</a>";
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
					$this->data['Message'] = "Player has been moved to <a href='".UIR."Park/profile/{$this->request->Admin_claimplayer->ParkId}'>their new home.</a>";
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
					'SuspensionPropagates' => $this->request->Admin_suspendplayer->SuspensionPropagates,
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
					$this->data['Message'] = "Player has been moved to <a href='".UIR."Park/profile/{$this->request->Admin_moveplayer->ParkId}'>their new home.</a>";
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
		error_log('ORK_DEBUG createplayer: ' . json_encode($_FILES));
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
					$this->data['Message'] = "Player created. <a href='".UIR."Player/profile/$r[Detail]'>View your spawn here.</a>";
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
					header( 'Location: '.UIR.'Kingdom/profile/'.$r['Detail'] );
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
		error_log('ORK_DEBUG createpark: ' . json_encode($params));
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
					//header( 'Location: '.UIR.'Park/profile/'.$r['Detail'] );
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
		$this->data[ 'page_title' ] = "Admin: " . $this->data['KingdomInfo']['KingdomName'];
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
		$this->data[ 'page_title' ] = "Admin: " . $this->data['ParkInfo']['ParkName'];
	}

	public function new_player_attendance() {
		$this->template = 'Admin_newplayerattendance.tpl';
		$this->load_model('Admin');

		$start_date = isset($this->request->StartDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->request->StartDate)
			? $this->request->StartDate
			: date('Y-m-d', strtotime('-3 months'));
		$end_date = isset($this->request->EndDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->request->EndDate)
			? $this->request->EndDate
			: date('Y-m-d');

		$this->data['form'] = array('StartDate' => $start_date, 'EndDate' => $end_date);
		$this->data['page_title'] = 'New Player Attendance';

		if (!isset($this->request->RunReport)) return;

		$result = $this->Admin->get_new_player_attendance_by_kingdom($start_date, $end_date);
		if (is_array($result)) {
			$this->data['summary'] = $result['Summary'];
		}
	}

	public function topparks($limit = null) {
		$this->load_model('Admin');
		$limit = intval($limit) > 0 ? intval($limit) : 25;
		$start_date = isset($this->request->StartDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->request->StartDate)
			? $this->request->StartDate
			: date("Y-m-d", strtotime("-12 month"));
		$end_date = isset($this->request->EndDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->request->EndDate)
			? $this->request->EndDate
			: date("Y-m-d");
		$week_count = max(1, ceil((strtotime($end_date) - strtotime($start_date)) / (7 * 86400)));
		$native_populace = !empty($this->request->NativePopulace);
		$waivered = !empty($this->request->Waivered);
		$result = $this->Admin->get_top_parks_by_attendance($limit, $start_date, $end_date, $native_populace, $waivered);
		$this->data['TopParks'] = $result['TopParksSummary'];
		$this->data['StartDate'] = $start_date;
		$this->data['EndDate'] = $end_date;
		$this->data['WeekCount'] = $week_count;
		$this->data['Limit'] = $limit;
		$this->data['NativePopulace'] = $native_populace;
		$this->data['Waivered'] = $waivered;
	}

	public function resetwaivers($params = null) {
		$this->load_model('Player');
		$params = explode('/', $params);
		$type = $params[0];
		$id = isset($params[1]) ? $params[1] : 0;

		if (!isset($this->session->user_id)) {
			header('Location: ' . UIR . "Login/login/Admin/resetwaivers/$type/$id");
			return;
		}

		$request = array('Token' => $this->session->token);
		if ($type == 'kingdom') {
			$request['KingdomId'] = $id;
		} else if ($type == 'park') {
			$request['ParkId'] = $id;
		}

		$r = $this->Player->reset_waivers($request);

		if ($r['Status'] == 0) {
			$this->data['Message'] = $r['Detail'];
		} else if ($r['Status'] == 5) {
			header('Location: ' . UIR . "Login/login/Admin/resetwaivers/$type/$id");
			return;
		} else {
			$this->data['Error'] = $r['Error'] . ':<p>' . $r['Detail'];
		}

		if ($type == 'kingdom') {
			$this->kingdom_route($id);
			$r = $this->Kingdom->get_kingdom_details($id);
			foreach ($r as $key => $detail) {
				$this->data[$key] = $detail;
			}
			$this->data['page_title'] = "Admin: " . $this->data['KingdomInfo']['KingdomName'];
			$this->data['IsPrinz'] = $this->data['KingdomInfo']['IsPrincipality'];
			$r = $this->Kingdom->get_park_summary($id);
			$this->data['park_summary'] = $r;
			$this->template = 'Admin_kingdom.tpl';
		} else if ($type == 'park') {
			$this->park_route($id);
			$r = $this->Park->get_park_info($id);
			foreach ($r as $key => $detail) {
				$this->data[$key] = $detail;
			}
			$this->data['page_title'] = "Admin: " . $this->data['ParkInfo']['ParkName'];
			$this->template = 'Admin_park.tpl';
		}
	}

	public function ajax($action = null) {
		header('Content-Type: application/json');
		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']); exit;
		}
		$uid = (int)($this->session->user_id ?? 0);
		if (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN)) {
			echo json_encode(['status' => 5, 'error' => 'Unauthorized']); exit;
		}

		if ($action === 'suspendplayer') {
			$this->load_model('Player');
			$mid        = (int)($_POST['MundaneId']  ?? 0);
			$suspended  = (int)($_POST['Suspended']  ?? 1);
			$byId       = (int)($_POST['SuspendedById'] ?? 0);
			$at         = trim($_POST['SuspendedAt']    ?? '');
			$until      = trim($_POST['SuspendedUntil'] ?? '');
			$reason     = trim($_POST['Suspension']    ?? '');
			$propagates = (int)($_POST['SuspensionPropagates'] ?? 0);
			if (!$mid) { echo json_encode(['status' => 1, 'error' => 'Select a player.']); exit; }
			$r = $this->Player->suspend_player([
				'Token'                => $this->session->token,
				'MundaneId'            => $mid,
				'Suspended'            => (bool)$suspended,
				'SuspendedById'        => $byId ?: $this->session->user_id,
				'SuspendedAt'          => $at,
				'SuspendedUntil'       => $until,
				'Suspension'           => $reason,
				'SuspensionPropagates' => $propagates,
			]);
			// SetPlayerSuspension returns null on success (no explicit return Success() in service layer)
			echo ($r === null || $r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'] ?? 1, 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'banplayer') {
			$this->load_model('Player');
			$mid    = (int)($_POST['MundaneId'] ?? 0);
			$banned = (int)($_POST['Banned']    ?? 0);
			if (!$mid) { echo json_encode(['status' => 1, 'error' => 'Select a player.']); exit; }
			$r = $this->Player->set_ban([
				'Token'     => $this->session->token,
				'MundaneId' => $mid,
				'Banned'    => $banned,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'mergepark') {
			$this->load_model('Park');
			$from = (int)($_POST['FromParkId'] ?? 0);
			$to   = (int)($_POST['ToParkId']   ?? 0);
			if (!$from || !$to) { echo json_encode(['status' => 1, 'error' => 'Both parks are required.']); exit; }
			if ($from === $to)   { echo json_encode(['status' => 1, 'error' => 'Cannot merge a park into itself.']); exit; }
			$r = $this->Park->mergeparks([
				'Token'      => $this->session->token,
				'FromParkId' => $from,
				'ToParkId'   => $to,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'checkparkabbr') {
			$park_id    = (int)($_POST['ParkId']    ?? 0);
			$kingdom_id = (int)($_POST['KingdomId'] ?? 0);
			if (!$park_id || !$kingdom_id) { echo json_encode(['status' => 1, 'error' => 'Missing parameters.']); exit; }
			global $DB;
			$DB->Clear();
			$rs = $DB->DataSet("SELECT abbreviation FROM " . DB_PREFIX . "park WHERE park_id = {$park_id} LIMIT 1");
			if (!$rs || !$rs->Next()) { echo json_encode(['status' => 1, 'error' => 'Park not found.']); exit; }
			$abbr = strtoupper($rs->abbreviation);
			$DB->Clear();
			$abbrEsc = mysql_real_escape_string($abbr);
			$rs2 = $DB->DataSet("SELECT name FROM " . DB_PREFIX . "park WHERE kingdom_id = {$kingdom_id} AND abbreviation = '{$abbrEsc}' AND park_id != {$park_id} AND active = 'Active' LIMIT 1");
			$taken = ($rs2 && $rs2->Next());
			echo json_encode(['status' => 0, 'abbr' => $abbr, 'taken' => $taken, 'conflictName' => $taken ? $rs2->name : '']);
			exit;

		} elseif ($action === 'transferpark') {
			$this->load_model('Park');
			$park_id    = (int)($_POST['ParkId']    ?? 0);
			$kingdom_id = (int)($_POST['KingdomId'] ?? 0);
			if (!$park_id || !$kingdom_id) { echo json_encode(['status' => 1, 'error' => 'Park and destination kingdom are required.']); exit; }
			$new_abbr   = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($_POST['Abbreviation'] ?? '')));
			$r = $this->Park->TransferPark([
				'Token'        => $this->session->token,
				'ParkId'       => $park_id,
				'KingdomId'    => $kingdom_id,
				'Abbreviation' => $new_abbr,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'mergeunit') {
			$this->load_model('Unit');
			$from = (int)($_POST['FromUnitId'] ?? 0);
			$to   = (int)($_POST['ToUnitId']   ?? 0);
			if (!$from || !$to) { echo json_encode(['status' => 1, 'error' => 'Both units are required.']); exit; }
			if ($from === $to)   { echo json_encode(['status' => 1, 'error' => 'Cannot merge a unit into itself.']); exit; }
			$r = $this->Unit->merge([
				'Token'      => $this->session->token,
				'FromUnitId' => $from,
				'ToUnitId'   => $to,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'checkabbr') {
			$abbr      = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($_POST['Abbreviation'] ?? '')));
			$excludeId = (int)($_POST['ExcludeKingdomId'] ?? 0);
			if (!strlen($abbr)) { echo json_encode(['status' => 0, 'taken' => false]); exit; }
			global $DB;
			$DB->Clear();
			$excludeClause = $excludeId > 0 ? " AND kingdom_id != {$excludeId}" : '';
			$rs = $DB->DataSet("SELECT kingdom_id, name FROM " . DB_PREFIX . "kingdom WHERE abbreviation = '{$abbr}'{$excludeClause} LIMIT 1");
			echo ($rs && $rs->Next())
				? json_encode(['status' => 0, 'taken' => true,  'name' => $rs->name])
				: json_encode(['status' => 0, 'taken' => false]);

		} elseif ($action === 'createkingdom') {
			$this->load_model('Kingdom');
			$name   = trim($_POST['Name'] ?? '');
			$abbr   = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($_POST['Abbreviation'] ?? '')));
			$parentId = (int)($_POST['ParentKingdomId'] ?? 0);
			if (!strlen($name)) { echo json_encode(['status' => 1, 'error' => 'Kingdom must have a name.']); exit; }
			if (!strlen($abbr)) { echo json_encode(['status' => 1, 'error' => 'Kingdom must have an abbreviation.']); exit; }
			$r = $this->Kingdom->create_kingdom([
				'Token'                   => $this->session->token,
				'Name'                    => $name,
				'Abbreviation'            => $abbr,
				'ParentKingdomId'         => $parentId,
				'AttendancePeriodType'    => in_array($_POST['AttendancePeriodType'] ?? '', ['week','month']) ? $_POST['AttendancePeriodType'] : 'week',
				'AttendancePeriod'        => max(1, (int)($_POST['AttendancePeriod']        ?? 26)),
				'AttendanceWeeklyMinimum' => max(0, (int)($_POST['AttendanceWeeklyMinimum'] ?? 2)),
				'AttendanceDailyMinimum'  => max(0, (int)($_POST['AttendanceDailyMinimum']  ?? 6)),
				'AttendanceCreditMinimum' => max(0, (int)($_POST['AttendanceCreditMinimum'] ?? 9)),
				'MonthlyCreditMaximum'    => max(0, (int)($_POST['MonthlyCreditMaximum']    ?? 4)),
				'DuesPeriodType'          => in_array($_POST['DuesPeriodType'] ?? '', ['week','month']) ? $_POST['DuesPeriodType'] : 'month',
				'DuesPeriod'              => max(1, (int)($_POST['DuesPeriod']   ?? 6)),
				'DuesAmount'              => max(0, (float)($_POST['DuesAmount'] ?? 6)),
				'KingdomDuesTake'         => max(0, (float)($_POST['KingdomDuesTake'] ?? 1)),
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0, 'kingdomId' => (int)($r['Detail'] ?? 0)])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} else {
			echo json_encode(['status' => 1, 'error' => 'Unknown action']);
		}
		exit;
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
		if ($this->data['LoggedIn']) {
			$this->data['menu']['admin'] = array( 'url' => UIR.'Admin/kingdom/'.$this->session->kingdom_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		}
		$this->data['menu']['kingdom'] = array( 'url' => UIR.'Kingdom/profile/'.$this->session->kingdom_id, 'display' => $this->session->kingdom_name );
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

		if ($this->data['LoggedIn']) {
			$this->data['menu']['admin'] = array( 'url' => UIR.'Admin/park/'.$this->session->park_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		}
		$this->data['menu']['kingdom'] = array( 'url' => UIR.'Kingdom/profile/'.$this->session->kingdom_id, 'display' => $this->session->kingdom_name );
		$this->data['menu']['park'] = array( 'url' => UIR.'Park/profile/'.$this->session->park_id, 'display' => $this->session->park_name );
	}
}

?>
