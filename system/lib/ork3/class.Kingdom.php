<?php

class Kingdom  extends Ork3 {

	public function __construct() {
		parent::__construct();
		$this->kingdom = new yapo($this->db, DB_PREFIX . 'kingdom');
		$this->kingdomaward = new yapo($this->db, DB_PREFIX . 'kingdomaward');
	}
	
	public function GetKingdomShortInfo($request) {
		$this->kingdom->clear();
		$this->kingdom->kingdom_id = $request['KingdomId'];
		$response = array();
		if ($this->kingdom->find()) {
			$response['Status'] = Success();
			$response['KingdomInfo'] = array();
			$response['KingdomInfo']['KingdomId'] = $this->kingdom->kingdom_id;
			$response['KingdomInfo']['KingdomName'] = $this->kingdom->name;
			$response['KingdomInfo']['Abbreviation'] = $this->kingdom->abbreviation;
			$response['KingdomInfo']['HasHeraldry'] = $this->kingdom->has_heraldry;
			$response['KingdomInfo']['IsPrincipality'] = $this->kingdom->parent_kingdom_id>0?1:0;
			$response['KingdomInfo']['ParentKingdomId'] = $this->kingdom->parent_kingdom_id;
			$response['KingdomInfo']['Active'] = $this->kingdom->active;
		} else {
			$response['Status'] = InvalidParameter();
		}
		return $response;
	}
	
/*
	public function SetKingdomAwards($request) {
		$response = array();
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $request['KingdomId'], AUTH_EDIT)) {
			$this->log->Write('Kingdom', $mundane_id, LOG_EDIT, $request);
			if (is_array($request['KingdomAwards'])) {
					$this->kingdomaward->clear();
					$this->kingdomaward->award_id = $kingdomaward['AwardId'];
					$this->kingdomaward->kingdom_id = $request['KingdomId'];
					switch ($kingdomaward['Action']) {
						case CFG_REMOVE:
							if (valid_id($request['AwardId']) && $this->kingdomaward->find()) {
								if (valid_id($this->kingdomaward->award_id)) {
									$response['Status'] = InvalidParameter('You may not delete basic Awards.  Take it up with the CoM.');
									return $response;
								}

								$awards = new yapo($this->db, DB_PREFIX . 'mundane_award');
								$award->clear();
								$awards->kingdomaward_id = $this->kingdomaward->kingdomaward_id;
								if (valid_id($request['AwardId'] && $awards->find())) {
									$response['Status'] = InvalidParameter('You may not delete basic a Kingdom Award which is assigned to a Player.  Remove all awards first.');
									return $response;
								}
								
								$this->kingdomaward->delete();
							}
							break;
						case CFG_EDIT:
							if (valid_id($request['AwardId']) && $this->kingdomaward->find()) {
								$this->kingdomaward->name = trimlen($kingdomaward['Name'])>0?$kingdomaward['Name']:$this->kingdomaward->name;
								$this->kingdomaward->reign_limit = trimlen($kingdomaward['ReignLimit'])>0?$kingdomaward['ReignLimit']:$this->kingdomaward->reign_limit;
								$this->kingdomaward->month_limit = trimlen($kingdomaward['MonthLimit'])>0?$kingdomaward['MonthLimit']:$this->kingdomaward->month_limit;
								$this->kingdomaward->is_title = trimlen($kingdomaward['IsTitle'])>0?$kingdomaward['IsTitle']:$this->kingdomaward->title;
								$this->kingdomaward->title_class = trimlen($kingdomaward['TitleClass'])>0?$kingdomaward['TitleClass']:$this->kingdomaward->title_class;
								$this->kingdomaward->save();
							}
							break;
						case CFG_ADD:
								$this->kingdomaward->name = $kingdomaward['Name'];
								$this->kingdomaward->reign_limit = $kingdomaward['ReignLimit'];
								$this->kingdomaward->month_limit = $kingdomaward['MonthLimit'];
								$this->kingdomaward->is_title = $kingdomaward['IsTitle'];
								$this->kingdomaward->title_class = $kingdomaward['TitleClass'];
								$this->kingdomaward->save();
							break;
					}
				}
			}
			$response = Success();
		} else {
			$response = NoAuthorization(null, $mundane_id);
		}
		return $response;
	}
*/
	public function GetAwardList($request) {
		if ($request['IsLadder'] == 'Ladder') {
			$ladder_clause = " and ka.is_ladder = 1";
		} else if ($request['IsLadder'] == 'NonLadder') {
			$ladder_clause = " and ka.is_ladder = 0";
		}
		if ($request['IsTitle'] == 'Title') {
			$ladder_clause = " and is_title = 1";
		} else if ($request['IsTitle'] == 'NonTitle') {
			$ladder_clause = " and is_title = 0";
		}
		$this->db->Clear();
		$this->db->kingdom_id = $request["KingdomId"];
		$sql = "select kingdomaward_id, ifnull(ka.name, a.name) as kingdom_awardname, ka.reign_limit, ka.month_limit, a.name as award_name, 
						a.award_id, a.is_ladder, ifnull(a.is_title, ka.is_title) as is_title, ifnull(a.title_class, ka.title_class) as title_class
					from " . DB_PREFIX . "kingdomaward ka
						left join " . DB_PREFIX . "award a on ka.award_id = a.award_id and ka.kingdom_id = :kingdom_id
					where 1
						$ladder_clause
						$title_clause
						and ka.kingdom_id = :kingdom_id
					order by is_ladder, ka.is_title, ka.title_class desc, ka.name, a.name";
		$r = $this->db->query($sql);
		
		$response = array();
		if ($r !== false && $r->size() > 0) {
			$response['Awards'] = array();
			do {
				$response['Awards'][$r->kingdomaward_id] = array(
					'KingdomAwardId' => $r->kingdomaward_id,
					'KingdomAwardName' => $r->kingdom_awardname,
					'ReignLimit' => $r->reign_limit,
					'MonthLimit' => $r->month_limit,
					'AwardName' => $r->award_name,
					'AwardId' => $r->award_id,
					'IsLadder' => $r->is_ladder,
					'IsTitle' => $r->is_title,
					'TitleClass' => $r->title_class
				);
			} while ($r->next());
			$response['Status'] = Success();
		} else {
			$response['Status'] = InvalidParameter(NULL, 'Problem processing request.');
		}
		return $response;
	}

	public function CreateAward($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $request['KingdomId'], AUTH_CREATE)) {
			$this->log->Write('Award', $mundane_id, LOG_ADD, $request);
			$this->kingdomaward->clear();
			$this->kingdomaward->kingdom_id = $request['KingdomId'];
			$this->kingdomaward->award_id = $request['AwardId'];
			$this->kingdomaward->name = $request['Name'];
			$this->kingdomaward->reign_limit = $request['ReignLimit'];
			$this->kingdomaward->month_limit = $request['MonthLimit'];
			$this->kingdomaward->is_title = $request['IsTitle'];
			$this->kingdomaward->title_class = $request['TitleClass'];
			$this->kingdomaward->save();
			
		} else {
			return NoAuthorization();
		}
	}

	public function EditAward($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $request['KingdomId'], AUTH_EDIT)) {
			$this->log->Write('Award', $mundane_id, LOG_EDIT, $request);
			$this->kingdomaward->clear();
			$this->kingdomaward->kingdom_id = $request['KingdomId'];
			$this->kingdomaward->kingdomaward_id = $request['KingdomAwardId'];
			if ($this->kingdomaward->find()) {
				$this->kingdomaward->name = $request['Name'];
				$this->kingdomaward->reign_limit = $request['ReignLimit'];
				$this->kingdomaward->month_limit = $request['MonthLimit'];
				$this->kingdomaward->is_title = $request['IsTitle'];
				$this->kingdomaward->title_class = $request['TitleClass'];
				$this->kingdomaward->save();
			} else {
				return InvalidParameter();
			}
		} else {
			return NoAuthorization();
		}
	}

	public function RemoveAward($request) { 
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $request['KingdomId'], AUTH_EDIT)) {
			$this->log->Write('Award', $mundane_id, LOG_REMOVE, $request);
			$this->kingdomaward->kingdom_id = $request['KingdomId'];
			$this->kingdomaward->kingdomaward_id = $request['KingdomAwardId'];
			if ($this->kingdomaward->find()) {
				$this->kingdomaward->delete();
			}
			return Success();
		}
		return NoAuthorization();
	}
		
	public function create_kingdom_awards($kingdom_id) {
		$this->db->Clear();
		$this->db->kingdom_id = $kingdom_id;
		$sql = "insert into " . DB_PREFIX . "kingdomaward (kingdom_id, award_id, name) select :kingdom_id, award_id, name from " . DB_PREFIX . "award";
		$this->db->Query($sql);
	}
	
	public function GetKingdomParkTitles($request) {
		$parktitle = new yapo($this->db, DB_PREFIX . 'parktitle');
		$parktitle->clear();
		$parktitle->kingdom_id = $request['KingdomId'];
		$response['ParkTitles'] = array();
		if ($parktitle->find(array('class desc'))) {
			do {
				$response['ParkTitles'][] = array(
						'ParkTitleId'=>$parktitle->parktitle_id,
						'Title'=>$parktitle->title,
						'Class'=>$parktitle->class,
						'MinimumAttendance'=>$parktitle->minimumattendance,
						'MinimumCutoff'=>$parktitle->minimumcutoff,
						'Period'=>$parktitle->period,
						'Length'=>$parktitle->period_length
					);
			} while ($parktitle->next());
			$response['Status'] = Success();
			return $response;
		}
		$response['Status'] = InvalidParameter();
		return $response;
	}
	
	/*
	public function GetKingdomAwardList($request) {
		return $this->GetAwardList(array( 'IsLadder' => 'Both', 'IsTitle' => 'Both', 'KingdomId' => $request['KingdomId'] ));
	}
	*/
	
	public function GetKingdomDetails($request) {
		$this->kingdom->clear();
		$this->kingdom->kingdom_id = $request['KingdomId'];
		$response = array();
		if ($request['KingdomId'] > 0 && $this->kingdom->find()) {
			$response['Status'] = Success();
			$response['KingdomInfo'] = array();
			$response['KingdomInfo']['KingdomId'] = $this->kingdom->kingdom_id;
			$response['KingdomInfo']['KingdomName'] = $this->kingdom->name;
			$response['KingdomInfo']['Abbreviation'] = $this->kingdom->abbreviation;
			$response['KingdomInfo']['Active'] = $this->kingdom->active;
			$response['KingdomInfo']['IsPrincipality'] = $this->kingdom->parent_kingdom_id>0?1:0;
			$response['KingdomInfo']['ParentKingdomId'] = $this->kingdom->parent_kingdom_id;
			
			// Fetch configs
			$response['KingdomConfiguration'] = Common::get_configs($request['KingdomId']);
			
			$pt = $this->GetKingdomParkTitles($request);
			
			$response['ParkTitles'] = $pt['ParkTitles'];
			
			$response['Awards'] = $this->GetAwardList(array( 'IsLadder' => 'Both', 'IsTitle' => 'Both', 'KingdomId' => $request['KingdomId'] ));
		} else {
			$response['Status'] = InvalidParameter();
		}
		return $response;
	}
	
	public function GetKingdomAuthorizations($request) {
		$this->db->Clear();
		$this->db->kingdom_id = $request['KingdomId'];
		$sql = "select authorization_id, username, a.mundane_id, role from ".DB_PREFIX."authorization a left join ".DB_PREFIX."mundane m on a.mundane_id = m.mundane_id where a.kingdom_id = :kingdom_id";
		$r = $this->db->Query($sql);
		$response = array();
		$response['Authorizations'] = array();
		if ($r !== false && $r->size() > 0) {
			$response['Status'] = Success();
			do {
				$response['Authorizations'][] = array( 
						'AuthorizationId' => $r->authorization_id,
						'UserName' => $r->username,
						'MundaneId' => $r->mundane_id,
						'Role' => $r->role
					);
			} while ($r->next());
		} else {
			$response['Status'] = InvalidParameter(null, 'Problem processing request.');
		}
		return $response;
	}
	
	public function CreateKingdom($request) {
		$response = array();
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_CREATE)) {
			$this->log->Write('Kingdom', $mundane_id, LOG_ADD, $request);
			$this->kingdom->clear();
			$this->kingdom->name = $request['Name'];
			if ($this->kingdom->find()) {
				$response = InvalidParameter('Duplicate Kingdom Name');
				return $response;
			}
			$this->kingdom->clear();
			$this->kingdom->name = $request['Name'];
			$this->kingdom->abbreviation = $request['Abbreviation'];
			$this->kingdom->active = 'Active';
			$this->kingdom->parent_kingdom_id = $request['ParentKingdomId'];
			$this->kingdom->modified = date("Y-m-d H:i:s", time());
			$this->kingdom->save();
			
			$c = new Common();
			$c->add_config($mundane_id, CFG_KINGDOM, 'fixed', $this->kingdom->kingdom_id, 'AveragePeriod', 
				array('Type'=>$request['AttendancePeriodType'],'Period'=>$request['AttendancePeriod']), 1, array('Type'=>array('month','week')));
			$c->add_config($mundane_id, CFG_KINGDOM, 'number', $this->kingdom->kingdom_id, 'AttendanceWeeklyMinimum', $request['AttendanceWeeklyMinimum']);
    		$c->add_config($mundane_id, CFG_KINGDOM, 'number', $this->kingdom->kingdom_id, 'AttendanceDailyMinimum', $request['AttendanceDailyMinimum']);
			$c->add_config($mundane_id, CFG_KINGDOM, 'number', $this->kingdom->kingdom_id, 'AttendanceCreditMinimum', $request['AttendanceCreditMinimum']);
    		$c->add_config($mundane_id, CFG_KINGDOM, 'number', $this->kingdom->kingdom_id, 'MonthlyCreditMaximum', $request['MonthlyCreditMaximum']);
			$c->add_config($mundane_id, CFG_KINGDOM, 'fixed', $this->kingdom->kingdom_id, 'DuesPeriod', array('Type'=>$request['DuesPeriodType'],'Period'=>$request['DuesPeriod']), 1, array('Type'=>array('month','week')));
			$c->add_config($mundane_id, CFG_KINGDOM, 'number', $this->kingdom->kingdom_id, 'DuesAmount', $request['DuesAmount']);
			$c->add_config($mundane_id, CFG_KINGDOM, 'number', $this->kingdom->kingdom_id, 'KingdomDuesTake', $request['KingdomDuesTake']);
    		$c->add_config($mundane_id, CFG_KINGDOM, 'color', $this->kingdom->kingdom_id, 'AtlasColor', 'FE7569');
			
			$c->create_officers($this->kingdom->kingdom_id, 0);
			
			$c->create_park_titles($this->kingdom->kingdom_id);
			
			$c->create_events($this->kingdom->kingdom_id, 0);
			
			$this->create_kingdom_awards($this->kingdom->kingdom_id);
			
			Ork3::$Lib->treasury->create_accounts($mundane_id, 'kingdom', $this->kingdom->kingdom_id, $this->kingdom->kingdom_id);
			
			$request['KingdomId'] = $this->kingdom->kingdom_id;
			Ork3::$Lib->heraldry->SetKingdomHeraldry($request);
			
			$response = Success($this->kingdom->kingdom_id);
		} else {
			$response = NoAuthorization();
		}
		return $response;
	}
	
	public function GetPrincipalities($request) {
		$this->kingdom->clear();
		$this->kingdom->parent_kingdom_id = $request['KingdomId'];
		$result = array('Status' => Success(), 'Principalities' => array());
		if ($this->kingdom->find()) {
			do {
				$result['Principalities'][] = array(
						'KingdomId' => $this->kingdom->kingdom_id,
						'Name' => $this->kingdom->name,
						'IsPrincipality' => 1,
						'ParentKingdomId' => $this->kingdom->parent_kingdom_id
					);
			} while ($this->kingdom->next());
		} else {
			$result['Status'] = InvalidParameter();
		}
		return $result;
	}
	
	public function GetParks($request) {
		$this->db->Clear();
		$this->db->kingdom_id = $request['KingdomId'];
		$sql = "select * 
					from " . DB_PREFIX . "park p
						left join " . DB_PREFIX . "parktitle pt on p.parktitle_id = pt.parktitle_id
					where p.kingdom_id = :kingdom_id
					order by pt.class desc, p.name asc";
		$r = $this->db->query($sql);
		if ($r !== false && $r->size() > 0) {
			$response = array('Status' => Success(), 'Parks' => array());
			do {
				$response['Parks'][] = array(
						'ParkId' => $r->park_id,
						'KingdomId' => $r->kingdom_id,
						'ParentParkId' => $r->parent_park_id,
						'Name' => $r->name,
						'Abbreviation' => $r->abbreviation,
						'Location' => $r->location,
						'Url' => $r->url,
						'Directions' => stripslashes(nl2br($r->directions)),
    					'Description' => stripslashes(nl2br($r->description)),
						'ParkTitleId' => $r->parktitle_id,
						'Active' => $r->active,
						'Title' => $r->title,
						'Class' => $r->class,
                        'HasHeraldry' => $r->has_heraldry,
						'ParentOf' => $r->is_principality==1?Ork3::$Lib->park->GetParks(array('ParkId'=>$r->park_id, 'Stack' => array($r->park_id))):null
					);
			} while ($r->next());
		} else {
			$response['Status'] = InvalidParameter();
		}
		return $response;
	}
	
	public function SetKingdomParkTitles($request) {
		$response = array();
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $request['KingdomId'], AUTH_EDIT)) {
			$this->log->Write('Kingdom', $mundane_id, LOG_EDIT, $request);
			if (is_array($request['ParkTitles'])) {
				$parktitle = new yapo($this->db, DB_PREFIX . 'parktitle');
				foreach ($request['ParkTitles'] as $k => $title) {
					switch ($title['Action']) {
						case CFG_REMOVE:
							$parktitle->clear();
							$parktitle->parktitle_id = $title['ParkTitleId'];
							if (valid_id($title['ParkTitleId']) && $parktitle->find()) {
								if ($parktitle->kingdom_id != $request['KingdomId']) {
									$response['Status'] = NoAuthorization('You cannot edit the park titles of another kingdom.');
									return $response;
								}									
								$parktitle->delete();
							}
							break;
						case CFG_EDIT:
							$parktitle->clear();
							$parktitle->parktitle_id = $title['ParkTitleId'];
							if (valid_id($title['ParkTitleId']) && $parktitle->find()) {
								if ($parktitle->kingdom_id != $request['KingdomId']) {
									$response['Status'] = NoAuthorization('You cannot edit the park titles of another kingdom.');
									return $response;
								}									
								$parktitle->title = strlen($title['Title'])?$title['Title']:$parktitle->title;
								$parktitle->class = strlen($title['Class'])?$title['Class']:$parktitle->class;
								$parktitle->minimumattendance = strlen($title['MinimumAttendance'])?$title['MinimumAttendance']:$parktitle->minimumattendance;
								$parktitle->minimumcutoff = strlen($title['MinimumCutoff'])?$title['MinimumCutoff']:$parktitle->minimumcutoff;
								$parktitle->period = strlen($title['Period'])?$title['Period']:$parktitle->period;
								$parktitle->period_length = strlen($title['PeriodLength'])?$title['PeriodLength']:$parktitle->period_length;
								$parktitle->save();
							}
							break;
						case CFG_ADD:
							$parktitle->clear();
							$parktitle->kingdom_id = $request['KingdomId'];
							$parktitle->title = $title['Title'];
							$parktitle->class = $title['Class'];
							$parktitle->minimumattendance = $title['MinimumAttendance'];
							$parktitle->minimumcutoff = $title['MinimumCutoff'];
							$parktitle->period = $title['Period'];
							$parktitle->period_length = $title['PeriodLength'];
							$parktitle->save();
							break;
					}
				}
			}
			$response = Success();
		} else {
			$response = NoAuthorization(null, $mundane_id);
		}
		return $response;
	}
	
	public function SetKingdomDetails($request) {
		$response = array();
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $request['KingdomId'], AUTH_EDIT)) {
			$this->log->Write('Kingdom', $mundane_id, LOG_EDIT, $request);
			$this->kingdom->clear();
			$this->kingdom->kingdom_id = $request['KingdomId'];
			if ($this->kingdom->find()) {
				$this->kingdom->name = strlen($request['Name'])>0?$request['Name']:$this->kingdom->name;
				$this->kingdom->abbreviation = strlen($request['Abbreviation'])>0?$request['Abbreviation']:$this->kingdom->abbreviation;
				$this->kingdom->modified = date("Y-m-d H:i:s", time());
				$this->kingdom->save();
				
				Ork3::$Lib->heraldry->SetKingdomHeraldry($request);
				
				$c = new Common();
				if (is_array($request['KingdomConfiguration'])) {
					foreach ($request['KingdomConfiguration'] as $k => $config) {
						switch ($config['Action']) {
							case CFG_REMOVE:
								$c->remove_config($mundane_id, $config['ConfigurationId'], CFG_KINGDOM, $this->kingdom->kingdom_id, $config['Key']);
								break;
							case CFG_EDIT:
								$c->update_config($mundane_id, $config['ConfigurationId'], CFG_KINGDOM, $this->kingdom->kingdom_id, $config['Key'], $config['Value']);
								break;
							case CFG_ADD:
								$c->add_config($mundane_id, CFG_KINGDOM, $config['Type'], $this->kingdom->kingdom_id, $config['Key'], $config['Value'], $config['UserSetting'], $config['AllowedValues']);
								break;
						}
					}
				}
				$response = Success();
			} else {
				$response = InvalidParameter(NULL, 'Problem processing request');
			}
		} else {
			$response = NoAuthorization(null, $mundane_id);
		}
		return $response;
	}

	public function GetOfficers($request) {
		$this->db->Clear();
		$this->db->kingdom_id = $request['KingdomId'];
		$sql = "select a.*, p.name as park_name, k.name as kingdom_name, e.name as event_name, u.name as unit_name, m.username, m.given_name, m.surname, m.persona, m.restricted, o.role as officer_role, o.officer_id
					from " . DB_PREFIX . "officer o
						left join " . DB_PREFIX . "mundane m on o.mundane_id = m.mundane_id
						left join " . DB_PREFIX . "authorization a on a.authorization_id = o.authorization_id
							left join ".DB_PREFIX."park p on a.park_id = p.park_id
							left join ".DB_PREFIX."kingdom k on a.kingdom_id = k.kingdom_id
							left join ".DB_PREFIX."event e on a.event_id = e.event_id
							left join ".DB_PREFIX."unit u on a.unit_id = u.unit_id
				where o.kingdom_id = :kingdom_id and o.park_id = 0
			";
		$r = $this->db->Query($sql);
		$response = array();
		$response['Officers'] = array();
		if ($r !== false && $r->Size() > 0) {
			$response['Status'] = Success();
			do {
				$response['Officers'][] = array(
							'AuthorizationId' => $r->authorization_id,
							'MundaneId' => $r->mundane_id,
							'ParkId' => $r->park_id,
							'KingdomId' => $r->kingdom_id,
							'EventId' => $r->event_id,
							'UnitId' => $r->unit_id,
							'Role' => $r->role,
							'ParkName' => $r->park_name,
							'KingdomName' => $r->kingdom_name,
							'EventName' => $r->event_name,
							'UnitName' => $r->unit_name,
							'Restricted' => $r->restricted,
							'UserName' => $r->username,
							'GivenName' => $restricted_access||$r->restricted==0?$r->given_name:"",
							'Surname' => $restricted_access||$r->restricted==0?$r->surname:"",
							'Persona' => $r->persona,
							'OfficerId' => $r->officer_id,
							'OfficerRole' => $r->officer_role
						);
			} while ($r->next());
			$response['Status'] = Success();
		} else {
			$response['Status'] = InvalidParameter();
		}
		return $response;
	}
	
	public function SetOfficer($request) {
		$response = array();
		$mundane = Ork3::$Lib->player->player_info($request['MundaneId']);
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $request['KingdomId'], AUTH_EDIT)) {
			if ($mundane['KingdomId'] == $request['KingdomId']) {
				$officer = new yapo($this->db, DB_PREFIX . 'officer');
				$c = new Common();
				$c->set_officer($request['KingdomId'], 0, $request['MundaneId'], $request['Role']);
			} else {
				return InvalidParameter(null, "The new officer must be a member of this Kingdom.");
			}
		} else {
			$response = NoAuthorization();
		}
		return $response;
	}
	
	public function RetireKingdom($request) {
		return $this->WaffleKingdom($request, 'Retired');
	}
	
	public function RestoreKingdom($request) {
		return $this->WaffleKingdom($request, 'Active');
	}
	
	public function WaffleKingdom($request, $waffle) {
		$response = array();
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
			$this->log->Write('Kingdom', $mundane_id, 'Active'==$waffle?LOG_RESTORE:LOG_RETIRE, $request);
			$this->kingdom->clear();
			$this->kingdom->kingdom_id = $request['KingdomId'];
			if ($this->kingdom->find()) {
				$this->kingdom->active = $waffle;
				$this->kingdom->save();
				$response = Success();
			} else {
				$response = InvalidParameter(NULL, 'Problem processing request.');
			}
		} else {
			$response = NoAuthorization();
		}
		return $response;
	}
    
    public function GetKingdoms($request) {
        $response = array('Status'=>Success(), 'Kingdoms' => array());
        $this->kingdom->clear();
        $this->kingdom->active = 'Active';
        if ($this->kingdom->find()) do {
    		$config = Common::get_configs($this->kingdom->kingdom_id);
            $response['Kingdoms'][$this->kingdom->kingdom_id] = array(
                    'KingdomId' => $this->kingdom->kingdom_id,
                    'KingdomName' => $this->kingdom->name,
                    'KingdomColor' => $config['AtlasColor']['Value'],
										'ParentKingdomId' => $this->kingdom->parent_kingdom_id,
										'Active' => $this->kingdom->active
                );
        } while ($this->kingdom->next());
        return $response;
    }

}

?>