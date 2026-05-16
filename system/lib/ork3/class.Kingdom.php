<?php

class Kingdom  extends Ork3 {

	public function __construct() {
		parent::__construct();
		$this->kingdom = new yapo($this->db, DB_PREFIX . 'kingdom');
		$this->kingdomaward = new yapo($this->db, DB_PREFIX . 'kingdomaward');
	}
	
  public function GetKingdomByAbbreviation($request) {
    if (trimlen($request['Abbreviation']) < 2 || trimlen($request['Abbreviation']) > 3)
      return null;
    
    $this->kingdom->clear();
    $this->kingdom->abbreviation = strtoupper(trim($request['Abbreviation']));
    if ($this->kingdom->find()) {
      return $this->kingdom->kingdom_id; 
    }
    return null;
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
			$response['KingdomInfo']['Description'] = $this->kingdom->description ?? '';
			$response['KingdomInfo']['Url'] = $this->kingdom->url ?? '';
			// --- Kingdom design (1:1 supplemental, always-present row) ---
			$this->db->Clear();
			$design = new yapo($this->db, DB_PREFIX . 'kingdom_design');
			$design->clear();
			$design->kingdom_id = $this->kingdom->kingdom_id;
			if (!$design->find()) {
				$design->clear();
				$design->kingdom_id   = $this->kingdom->kingdom_id;
				$design->hero_overlay = 'med';
				$design->save();
				$design->clear();
				$this->db->Clear();
				$design->kingdom_id = $this->kingdom->kingdom_id;
				$design->find();
			}
			$response['KingdomInfo']['AboutText']       = (string)$design->about_text;
			$response['KingdomInfo']['OurHistory']      = (string)$design->our_history;
			$response['KingdomInfo']['ColorPrimary']    = $design->color_primary;
			$response['KingdomInfo']['ColorAccent']     = $design->color_accent;
			$response['KingdomInfo']['ColorSecondary']  = $design->color_secondary;
			$response['KingdomInfo']['HeroOverlay']     = $design->hero_overlay ?: 'med';
			$response['KingdomInfo']['NameFont']        = $design->name_font;
			$response['KingdomInfo']['MilestoneConfig'] = $design->milestone_config;
			$response['KingdomInfo']['Tagline']             = (string)$design->tagline;
			$response['KingdomInfo']['SocialLinks']         = (string)$design->social_links;
			$response['KingdomInfo']['Announcement']        = (string)$design->announcement;
			$response['KingdomInfo']['AnnouncementUntil']   = $design->announcement_until;
			$response['KingdomInfo']['MonarchReignStarted'] = $design->monarch_reign_started;
			$response['KingdomInfo']['RegentReignStarted']  = $design->regent_reign_started;
			$response['KingdomInfo']['ReignLore']           = (string)$design->reign_lore;
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
	private static function awardNameLooksLikeOfficer($name) {
		if (!is_string($name) || $name === '') return false;
		$prefix = '(Provincial|Baronial|Ducal|Grand\s+Ducal|Shire|Kingdom|Imperial|Principality|Barony|Duchy|Grand\s+Duchy)';
		$suffix = '(Monarch|Regent|Prime\s+Minister|Champion|Defender|Seneschal|Chancellor|Clerk|GMR|Guildmaster\s+of\s+Reeves|Guild\s+Master\s+of\s+Reeves|General\s+Minister|Sheriff|Baron(ess)?|Grand\s+Duke|Grand\s+Duchess|Duke|Duchess)';
		return preg_match('/^' . $prefix . '\s+' . $suffix . '\b/i', trim($name)) === 1;
	}

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
		$sql = "select kingdomaward_id, ifnull(ka.name, a.name) as kingdom_awardname, ka.reign_limit, ka.month_limit, a.name as award_name, 
						a.award_id, a.is_ladder, ifnull(a.is_title, ka.is_title) as is_title, ifnull(a.title_class, ka.title_class) as title_class,
            a.officer_role, a.peerage
					from " . DB_PREFIX . "kingdomaward ka
						left join " . DB_PREFIX . "award a on ka.award_id = a.award_id and ka.kingdom_id = '" . mysql_real_escape_string($request['KingdomId']) . "'
					where 1
						$ladder_clause
						$title_clause
            
						and ka.kingdom_id = '" . mysql_real_escape_string($request['KingdomId']) . "'
					order by is_ladder, ka.is_title, ka.title_class desc, ka.name, a.name";
		$r = $this->db->query($sql);
		
  	logtrace('GetAwardList', array($sql, $request));
		$response = array();
		if ($r !== false && $r->size() > 0) {
			$response['Awards'] = array();
			while ($r->next()) {
				$isOfficerRole = !in_array($r->officer_role, ['none', null]);
				// Some kingdomaward rows are mapped to a non-officer system award (e.g. Custom Award)
				// or are orphaned (LEFT JOIN -> NULL officer_role) but are clearly officer titles by
				// name — e.g. "Baronial Guild Master of Reeves", "Imperial Monarch", "Shire Regent".
				// Treat those as officers so they bucket into the Officers list, not Awards.
				if (!$isOfficerRole && self::awardNameLooksLikeOfficer($r->kingdom_awardname)) {
					$isOfficerRole = true;
				}
				if (isset($request['OfficerRole']) && $request['OfficerRole'] == 'Awards' && $isOfficerRole) {
					continue;
				} else if (isset($request['OfficerRole']) && $request['OfficerRole'] == 'Officers' && !$isOfficerRole) {
					continue;
				}

				$response['Awards'][$r->kingdomaward_id] = array(
					'KingdomAwardId' => $r->kingdomaward_id,
					'KingdomAwardName' => $r->kingdom_awardname,
					'ReignLimit' => $r->reign_limit,
					'MonthLimit' => $r->month_limit,
					'AwardName' => $r->award_name,
					'AwardId' => $r->award_id,
					'IsLadder' => $r->is_ladder,
					'IsTitle' => $r->is_title,
					'TitleClass' => $r->title_class,
					'OfficerRole' => $r->officer_role,
					'Peerage' => $r->peerage
				);
			}
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
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $request['KingdomId'], AUTH_CREATE)) {
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
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $request['KingdomId'], AUTH_CREATE)) {
			$this->log->Write('Award', $mundane_id, LOG_REMOVE, $request);
			$this->kingdomaward->kingdom_id = $request['KingdomId'];
			$this->kingdomaward->kingdomaward_id = $request['KingdomAwardId'];
			if ($this->kingdomaward->find()) {
				$prior_state = [
					'kingdomaward_id' => (int)$this->kingdomaward->kingdomaward_id,
					'kingdom_id'      => (int)$this->kingdomaward->kingdom_id,
					'name'            => $this->kingdomaward->name,
					'award_id'        => (int)$this->kingdomaward->award_id,
					'is_title'        => (int)$this->kingdomaward->is_title,
					'reign_limit'     => (int)$this->kingdomaward->reign_limit,
					'month_limit'     => (int)$this->kingdomaward->month_limit,
				];
				$this->kingdomaward->delete();
				Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Kingdom', (int)$request['KingdomId'], $prior_state);
			}
			return Success();
		}
		return NoAuthorization();
	}
		
	public function create_kingdom_awards($kingdom_id) {
		$sql = "insert into " . DB_PREFIX . "kingdomaward (kingdom_id, award_id, name) select " . mysql_real_escape_string($kingdom_id) .", award_id, name from " . DB_PREFIX . "award";
		$this->db->query($sql);
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
		}
		$response['Status'] = Success();
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
			$response['KingdomInfo']['Description'] = $this->kingdom->description ?? '';
			$response['KingdomInfo']['Url'] = $this->kingdom->url ?? '';

			// --- Kingdom design (1:1 supplemental, always-present row) ---
			$this->db->Clear();
			$design = new yapo($this->db, DB_PREFIX . 'kingdom_design');
			$design->clear();
			$design->kingdom_id = $this->kingdom->kingdom_id;
			if (!$design->find()) {
				$design->clear();
				$design->kingdom_id   = $this->kingdom->kingdom_id;
				$design->hero_overlay = 'med';
				$design->save();
				$design->clear();
				$this->db->Clear();
				$design->kingdom_id = $this->kingdom->kingdom_id;
				$design->find();
			}
			$response['KingdomInfo']['AboutText']       = (string)$design->about_text;
			$response['KingdomInfo']['OurHistory']      = (string)$design->our_history;
			$response['KingdomInfo']['ColorPrimary']    = $design->color_primary;
			$response['KingdomInfo']['ColorAccent']     = $design->color_accent;
			$response['KingdomInfo']['ColorSecondary']  = $design->color_secondary;
			$response['KingdomInfo']['HeroOverlay']     = $design->hero_overlay ?: 'med';
			$response['KingdomInfo']['NameFont']        = $design->name_font;
			$response['KingdomInfo']['MilestoneConfig'] = $design->milestone_config;
			$response['KingdomInfo']['Tagline']             = (string)$design->tagline;
			$response['KingdomInfo']['SocialLinks']         = (string)$design->social_links;
			$response['KingdomInfo']['Announcement']        = (string)$design->announcement;
			$response['KingdomInfo']['AnnouncementUntil']   = $design->announcement_until;
			$response['KingdomInfo']['MonarchReignStarted'] = $design->monarch_reign_started;
			$response['KingdomInfo']['RegentReignStarted']  = $design->regent_reign_started;
			$response['KingdomInfo']['ReignLore']           = (string)$design->reign_lore;

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
		$sql = "select authorization_id, username, a.mundane_id, role from ".DB_PREFIX."authorization a left join ".DB_PREFIX."mundane m on a.mundane_id = m.mundane_id where a.kingdom_id = '" . mysql_real_escape_string($request['KingdomId']) . "'";
		$r = $this->db->query($sql);
		$response = array();
		$response['Authorizations'] = array();
		if ($r !== false && $r->size() > 0) {
			$response['Status'] = Success();
			while ($r->next()) {
				$response['Authorizations'][] = array( 
						'AuthorizationId' => $r->authorization_id,
						'UserName' => $r->username,
						'MundaneId' => $r->mundane_id,
						'Role' => $r->role
					);
			}
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
			$_new_kingdom_id = (int)$this->kingdom->kingdom_id;
			// Always-present design row so reads don't need PHP-side defaults.
			$this->db->Clear();
			$_design_seed = new yapo($this->db, DB_PREFIX . 'kingdom_design');
			$_design_seed->clear();
			$_design_seed->kingdom_id   = $_new_kingdom_id;
			$_design_seed->hero_overlay = 'med';
			$_design_seed->save();

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
			$c->add_config($mundane_id, CFG_KINGDOM, 'fixed', $this->kingdom->kingdom_id, 'AwardRecsPublic', '1');
			
			$c->create_officers($this->kingdom->kingdom_id, 0);
			
			$c->create_park_titles($this->kingdom->kingdom_id);
			
			$c->create_events($this->kingdom->kingdom_id, 0);
			
			$this->create_kingdom_awards($this->kingdom->kingdom_id);
			
			Ork3::$Lib->treasury->create_accounts($mundane_id, 'kingdom', $this->kingdom->kingdom_id, $this->kingdom->kingdom_id);
			
			$request['KingdomId'] = $this->kingdom->kingdom_id;
			Ork3::$Lib->heraldry->SetKingdomHeraldry($request);
			Ork3::$Lib->dangeraudit->audit(__CLASS__ . '::' . __FUNCTION__, $request, 'Kingdom', (int)$this->kingdom->kingdom_id, null, [
				'kingdom_id'        => (int)$this->kingdom->kingdom_id,
				'name'              => $request['Name'],
				'abbreviation'      => $request['Abbreviation'],
				'parent_kingdom_id' => (int)$request['ParentKingdomId'],
			]);
			$response = Success($this->kingdom->kingdom_id);
		} else {
			$response = NoAuthorization();
		}
		return $response;
	}
	
	public function GetPrincipalities($request) {
		$this->kingdom->clear();
		$this->kingdom->parent_kingdom_id = $request['KingdomId'];
		$this->kingdom->active = 'Active';
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
		$sql = "select * 
					from " . DB_PREFIX . "park p
						left join " . DB_PREFIX . "parktitle pt on p.parktitle_id = pt.parktitle_id
					where p.kingdom_id = '" . mysql_real_escape_string($request['KingdomId']) . "'
					order by pt.class desc, p.name asc";
		$r = $this->db->query($sql);
		if ($r !== false && $r->size() > 0) {
			$response = array('Status' => Success(), 'Parks' => array());
			while ($r->next()) {
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
                        'City' => $r->city,
                        'Province' => $r->province,
						'ParentOf' => $r->is_principality==1?Ork3::$Lib->park->GetParks(array('ParkId'=>$r->park_id, 'Stack' => array($r->park_id))):null
					);
			}
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
				if (isset($request['Description'])) $this->kingdom->description = $request['Description'];
				if (isset($request['Url'])) $this->kingdom->url = $request['Url'];
				$this->kingdom->modified = date("Y-m-d H:i:s", time());
				$this->kingdom->save();
				if (isset($request['Description']) && trim((string)$request['Description']) !== '') {
					$this->db->Clear();
					$_design_sync = new yapo($this->db, DB_PREFIX . 'kingdom_design');
					$_design_sync->clear();
					$_design_sync->kingdom_id = (int)$this->kingdom->kingdom_id;
					if ($_design_sync->find() && trim((string)$_design_sync->about_text) !== '') {
						$_design_sync->about_text = (string)$request['Description'];
						$_design_sync->save();
					}
				}

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

	public function SetKingdomParent($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_ADMIN)) {
			$kingdom_id = (int)$request['KingdomId'];
			$parent_id  = (int)$request['ParentKingdomId'];
			// Cannot make a kingdom its own parent or create a circular reference
			if ($parent_id === $kingdom_id) {
				return InvalidParameter('A kingdom cannot be its own parent.');
			}
			$this->kingdom->clear();
			$this->kingdom->kingdom_id = $kingdom_id;
			if (!$this->kingdom->find()) {
				return InvalidParameter('Kingdom not found.');
			}
			if ($parent_id > 0) {
				$this->kingdom->clear();
				$this->kingdom->kingdom_id = $parent_id;
				if (!$this->kingdom->find()) {
					return InvalidParameter('Parent kingdom not found.');
				}
				$this->kingdom->clear();
				$this->kingdom->kingdom_id = $kingdom_id;
				$this->kingdom->find();
			}
			$this->log->Write('Kingdom', $mundane_id, LOG_EDIT, $request);
			$this->kingdom->parent_kingdom_id = $parent_id;
			$this->kingdom->modified = date('Y-m-d H:i:s', time());
			$this->kingdom->save();
			return Success();
		}
		return NoAuthorization();
	}

	public function GetOfficers($request) {
		$kingdom_id = mysql_real_escape_string($request['KingdomId']);
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		$is_authorized = Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT);

		$sql = "select a.*, p.name as park_name, k.name as kingdom_name, e.name as event_name, u.name as unit_name, m.mundane_id as m_mundane_id, m.username, m.given_name, m.surname, m.persona, m.restricted, o.role as officer_role, o.officer_id
					from " . DB_PREFIX . "officer o
						left join " . DB_PREFIX . "mundane m on o.mundane_id = m.mundane_id
						left join " . DB_PREFIX . "authorization a on a.authorization_id = o.authorization_id
							left join ".DB_PREFIX."park p on a.park_id = p.park_id
							left join ".DB_PREFIX."kingdom k on a.kingdom_id = k.kingdom_id
							left join ".DB_PREFIX."event e on a.event_id = e.event_id
							left join ".DB_PREFIX."unit u on a.unit_id = u.unit_id
				where o.kingdom_id = '" . $kingdom_id . "' and o.park_id = 0
				order by FIELD(o.role, 'Monarch', 'Regent', 'Prime Minister', 'Champion', 'GMR'), o.role
			";
		$r = $this->db->query($sql);
		$response = array();
		$response['Officers'] = array();
		if ($r !== false && $r->size() > 0) {
			$response['Status'] = Success();
			while ($r->next()) {
				$fetchprivate = true;
				if ($mundane_id > 0 && $is_authorized) {
					$fetchprivate = false;
				}
				$response['Officers'][] = array(
							'AuthorizationId' => $r->authorization_id,
							'MundaneId' => $r->m_mundane_id,
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
							'GivenName' => $fetchprivate?"":$r->given_name,
							'Surname' => $fetchprivate?"":$r->surname,
							'Persona' => $r->persona,
							'OfficerId' => $r->officer_id,
							'OfficerRole' => $r->officer_role
						);
			}
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
				// Look up prior holder so the audit can show before/after,
				// and so we can suppress no-op re-saves of the same assignment.
				$_priorOfficer = new yapo($this->db, DB_PREFIX . 'officer');
				$_priorOfficer->clear();
				$_priorOfficer->kingdom_id = (int)$request['KingdomId'];
				$_priorOfficer->park_id    = 0;
				$_priorOfficer->role       = $request['Role'];
				$_priorMundaneId = $_priorOfficer->find() ? (int)$_priorOfficer->mundane_id : 0;

				$officer = new yapo($this->db, DB_PREFIX . 'officer');
				$c = new Common();
				$c->set_officer($request['KingdomId'], 0, $request['MundaneId'], $request['Role']);

				if ($_priorMundaneId !== (int)$request['MundaneId']) {
					$_audit_req = $request;
					unset($_audit_req['Token']);
					Ork3::$Lib->dangeraudit->audit(__CLASS__ . '::' . __FUNCTION__, $_audit_req, 'Kingdom', (int)$request['KingdomId'],
						['MundaneId' => $_priorMundaneId, 'Role' => $request['Role']],
						[
							'KingdomId' => (int)$request['KingdomId'],
							'MundaneId' => (int)$request['MundaneId'],
							'Role'      => $request['Role'],
						]
					);
				}
			} else {
				return InvalidParameter(null, "The new officer must be a member of this Kingdom.");
			}
		} else {
			$response = NoAuthorization();
		}
		return $response;
	}

	public function VacateOfficer($request) {
		$response = array();
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if ($mundane_id > 0) {
			if (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $request['KingdomId'], AUTH_EDIT)) {
				$_priorOfficer = new yapo($this->db, DB_PREFIX . 'officer');
				$_priorOfficer->clear();
				$_priorOfficer->kingdom_id = (int)$request['KingdomId'];
				$_priorOfficer->park_id    = 0;
				$_priorOfficer->role       = $request['Role'];
				$_priorMundaneId = $_priorOfficer->find() ? (int)$_priorOfficer->mundane_id : 0;

				$c = new Common();
				$c->set_officer($request['KingdomId'], 0, 0, $request['Role']);

				if ($_priorMundaneId > 0) {
					$_audit_req = $request;
					unset($_audit_req['Token']);
					Ork3::$Lib->dangeraudit->audit(__CLASS__ . '::' . __FUNCTION__, $_audit_req, 'Kingdom', (int)$request['KingdomId'],
						['MundaneId' => $_priorMundaneId, 'Role' => $request['Role']],
						[
							'KingdomId' => (int)$request['KingdomId'],
							'Role'      => $request['Role'],
						]
					);
				}
			} else {
				$response = NoAuthorization();
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
                    'Abbreviation' => $this->kingdom->abbreviation,
                    'KingdomColor' => $config['AtlasColor']['Value'],
										'ParentKingdomId' => $this->kingdom->parent_kingdom_id,
										'Active' => $this->kingdom->active
                );
        } while ($this->kingdom->next());
        return $response;
    }

	/**
	 * Save kingdom profile design (header colors/font/overlay, about + our history markdown,
	 * milestone visibility config). Uses AUTH_KINGDOM/AUTH_EDIT.
	 */
	public function SetKingdomDesign($request)
	{
		$kingdom_id = (int)($request['KingdomId'] ?? 0);
		if ($kingdom_id <= 0) return InvalidParameter('KingdomId is required.');
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if (!($mundane_id > 0) || !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT)) {
			return NoAuthorization();
		}
		require_once(__DIR__ . '/class.ProfanityFilter.php');
		$pf = new ProfanityFilter();
		foreach (['AboutText' => 'AboutText', 'OurHistory' => 'OurHistory'] as $field => $label) {
			if (isset($request[$field]) && trim((string)$request[$field]) !== '') {
				if ($pf->containsProfanity((string)$request[$field])) {
					return InvalidParameter($label, ProfanityFilter::ERROR_MESSAGE);
				}
			}
		}
		$this->db->Clear();
		$design = new yapo($this->db, DB_PREFIX . 'kingdom_design');
		$design->clear();
		$design->kingdom_id = $kingdom_id;
		if (!$design->find()) {
			$design->clear();
			$design->kingdom_id   = $kingdom_id;
			$design->hero_overlay = 'med';
			$design->save();
			$design->clear();
			$this->db->Clear();
			$design->kingdom_id = $kingdom_id;
			$design->find();
		}
		$ABOUT_LIMIT = 10000;
		foreach (['AboutText' => 'about_text', 'OurHistory' => 'our_history'] as $req => $col) {
			if (isset($request[$req])) {
				$v = (string)$request[$req];
				if (strlen($v) > $ABOUT_LIMIT) {
					return InvalidParameter($req . ' is limited to ' . number_format($ABOUT_LIMIT) . ' characters.');
				}
				$design->$col = $v;
			}
		}
		$hexCols = ['ColorPrimary' => 'color_primary', 'ColorAccent' => 'color_accent', 'ColorSecondary' => 'color_secondary'];
		foreach ($hexCols as $req => $col) {
			if (!array_key_exists($req, $request)) continue;
			$v = trim((string)$request[$req]);
			if ($v === '') { $design->$col = null; continue; }
			if (!preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
				return InvalidParameter($req . ' must be a 6-digit hex color (e.g. #2c5282).');
			}
			$design->$col = strtolower($v);
		}
		if (array_key_exists('HeroOverlay', $request)) {
			$ho = strtolower(trim((string)$request['HeroOverlay']));
			if (!in_array($ho, ['low','med','high','vignette'], true)) $ho = 'med';
			$design->hero_overlay = $ho;
		}
		if (array_key_exists('NameFont', $request)) {
			$nf = trim((string)$request['NameFont']);
			if ($nf !== '' && !preg_match('/^[A-Za-z0-9 ]{1,100}$/', $nf)) {
				return InvalidParameter('Font name contains unexpected characters.');
			}
			$design->name_font = $nf === '' ? null : $nf;
		}
		if (array_key_exists('MilestoneConfig', $request)) {
			$mc = (string)$request['MilestoneConfig'];
			if ($mc !== '') {
				$decoded = json_decode($mc, true);
				if (!is_array($decoded)) {
					return InvalidParameter('Milestone config must be valid JSON.');
				}
			}
			$design->milestone_config = $mc === '' ? null : $mc;
		}

		if (array_key_exists('Tagline', $request)) {
			$tg = trim((string)$request['Tagline']);
			if (strlen($tg) > 160) {
				return InvalidParameter('Tagline is limited to 160 characters.');
			}
			if ($tg !== '' && $pf->containsProfanity($tg)) {
				return InvalidParameter('Tagline', ProfanityFilter::ERROR_MESSAGE);
			}
			$design->tagline = $tg === '' ? null : $tg;
		}

		if (array_key_exists('SocialLinks', $request)) {
			$sl = trim((string)$request['SocialLinks']);
			$cleanLinks = [];
			if ($sl !== '') {
				$decoded = json_decode($sl, true);
				if (!is_array($decoded)) {
					return InvalidParameter('SocialLinks must be valid JSON.');
				}
				$allowed = ['discord','facebook','instagram','threads','bluesky','twitter','youtube','amtwiki'];
				foreach ($decoded as $slug => $url) {
					if (!in_array($slug, $allowed, true)) continue;
					$url = trim((string)$url);
					if ($url === '') continue;
					if (preg_match('#^http://#i', $url)) {
						$url = 'https://' . substr($url, 7);
					} elseif (!preg_match('#^https://#i', $url)) {
						$url = 'https://' . ltrim($url, '/');
					}
					if (strlen($url) > 500) {
						return InvalidParameter('SocialLinks.' . $slug . ' URL too long.');
					}
					if (!filter_var($url, FILTER_VALIDATE_URL)) {
						return InvalidParameter('SocialLinks.' . $slug . ' is not a valid URL.');
					}
					$cleanLinks[$slug] = $url;
				}
			}
			$design->social_links = empty($cleanLinks) ? null : json_encode($cleanLinks);
		}

		if (array_key_exists('Announcement', $request)) {
			$an = trim((string)$request['Announcement']);
			if (strlen($an) > 280) {
				return InvalidParameter('Announcement is limited to 280 characters.');
			}
			if ($an !== '' && $pf->containsProfanity($an)) {
				return InvalidParameter('Announcement', ProfanityFilter::ERROR_MESSAGE);
			}
			$design->announcement = $an === '' ? null : $an;
		}

		if (array_key_exists('AnnouncementUntil', $request)) {
			$au = trim((string)$request['AnnouncementUntil']);
			if ($au === '') {
				$design->announcement_until = null;
			} else {
				$ts = strtotime($au);
				if ($ts === false) {
					return InvalidParameter('AnnouncementUntil must be a valid date.');
				}
				$design->announcement_until = date('Y-m-d', $ts);
			}
		}

		foreach (['MonarchReignStarted' => 'monarch_reign_started', 'RegentReignStarted' => 'regent_reign_started'] as $req => $col) {
			if (!array_key_exists($req, $request)) continue;
			$v = trim((string)$request[$req]);
			if ($v === '') { $design->$col = null; continue; }
			$ts = strtotime($v);
			if ($ts === false) {
				return InvalidParameter($req . ' must be a valid date.');
			}
			$design->$col = date('Y-m-d', $ts);
		}

		if (array_key_exists('ReignLore', $request)) {
			$rl = (string)$request['ReignLore'];
			if (strlen($rl) > 2000) {
				return InvalidParameter('ReignLore is limited to 2,000 characters.');
			}
			if (trim($rl) !== '' && $pf->containsProfanity($rl)) {
				return InvalidParameter('ReignLore', ProfanityFilter::ERROR_MESSAGE);
			}
			$design->reign_lore = trim($rl) === '' ? null : $rl;
		}

		$design->save();
		return Success($kingdom_id);
	}

	public function GetKingdomMilestones($request)
	{
		$kingdom_id = (int)($request['KingdomId'] ?? 0);
		if ($kingdom_id <= 0) return InvalidParameter('KingdomId is required.');
		$this->db->Clear();
		$ms = new yapo($this->db, DB_PREFIX . 'kingdom_milestones');
		$ms->clear();
		$ms->kingdom_id = $kingdom_id;
		$rows = [];
		if ($ms->find()) {
			do {
				$rows[] = [
					'MilestoneId'   => (int)$ms->milestone_id,
					'KingdomId'     => (int)$ms->kingdom_id,
					'Icon'          => $ms->icon,
					'Description'   => $ms->description,
					'MilestoneDate' => $ms->milestone_date,
				];
			} while ($ms->next());
		}
		return ['Status' => Success(), 'Milestones' => $rows];
	}

	public function AddKingdomMilestone($request)
	{
		$kingdom_id = (int)($request['KingdomId'] ?? 0);
		if ($kingdom_id <= 0) return InvalidParameter('KingdomId is required.');
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if (!($mundane_id > 0) || !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT)) {
			return NoAuthorization();
		}
		require_once(__DIR__ . '/class.ProfanityFilter.php');
		$pf = new ProfanityFilter();
		$desc = trim((string)($request['Description'] ?? ''));
		if ($desc === '') return InvalidParameter('Description is required.');
		if (strlen($desc) > 500) $desc = substr($desc, 0, 500);
		if ($pf->containsProfanity($desc)) {
			return InvalidParameter('Description', ProfanityFilter::ERROR_MESSAGE);
		}
		$dateRaw = trim((string)($request['MilestoneDate'] ?? ''));
		if ($dateRaw === '') return InvalidParameter('Date is required.');
		$ts = strtotime($dateRaw);
		if ($ts === false) return InvalidParameter('Invalid date.');
		$icon = trim((string)($request['Icon'] ?? 'fa-star'));
		if (!preg_match('/^fa-[a-z0-9-]+$/', $icon)) $icon = 'fa-star';
		$this->db->Clear();
		$ms = new yapo($this->db, DB_PREFIX . 'kingdom_milestones');
		$ms->clear();
		$ms->kingdom_id     = $kingdom_id;
		$ms->icon           = $icon;
		$ms->description    = $desc;
		$ms->milestone_date = date('Y-m-d', $ts);
		$ms->save();
		return Success((int)$ms->milestone_id);
	}

	public function DeleteKingdomMilestone($request)
	{
		$kingdom_id   = (int)($request['KingdomId']   ?? 0);
		$milestone_id = (int)($request['MilestoneId'] ?? 0);
		if ($kingdom_id <= 0 || $milestone_id <= 0) return InvalidParameter('KingdomId and MilestoneId required.');
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if (!($mundane_id > 0) || !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT)) {
			return NoAuthorization();
		}
		$this->db->Clear();
		$ms = new yapo($this->db, DB_PREFIX . 'kingdom_milestones');
		$ms->clear();
		$ms->milestone_id = $milestone_id;
		$ms->kingdom_id   = $kingdom_id;
		if (!$ms->find()) return InvalidParameter('Milestone not found.');
		$ms->delete();
		return Success();
	}

	/**
	 * Derived kingdom milestones — computed from attendance data scoped by kingdom_id.
	 * Cached at 300s TTL.
	 */
	public function GetDerivedKingdomMilestones($request)
	{
		$kingdom_id = (int)(is_array($request) ? ($request['KingdomId'] ?? 0) : $request);
		if ($kingdom_id <= 0) return ['Status' => InvalidParameter('KingdomId is required.'), 'Milestones' => []];
		$key = Ork3::$Lib->ghettocache->key(['KingdomId' => $kingdom_id]);
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 300)) !== false)
			return $cache;
		$out = [];
		// 1) First recorded attendance anywhere in the kingdom.
		$this->db->Clear();
		$r = $this->db->query("SELECT MIN(date) AS first_date FROM " . DB_PREFIX . "attendance WHERE kingdom_id = $kingdom_id AND date >= '1988-01-01'");
		if ($r !== false && $r->size() > 0) {
			$r->next();
			$fd = $r->first_date;
			if ($fd && $fd !== '0000-00-00') {
				$out[] = [
					'Type'          => 'first_attendance',
					'Icon'          => 'fa-door-open',
					'Description'   => 'First recorded attendance in the kingdom',
					'MilestoneDate' => $fd,
					'IsDerived'     => true,
				];
			}
		}
		// 2) Attendance count crossings — kingdoms are larger than parks, so scaled up.
		$this->db->Clear();
		$r = $this->db->query("SELECT COUNT(*) AS total FROM " . DB_PREFIX . "attendance WHERE kingdom_id = $kingdom_id");
		$total = 0;
		if ($r !== false && $r->size() > 0) { $r->next(); $total = (int)$r->total; }
		$thresholds = [1000, 5000, 10000, 50000, 100000, 500000];
		foreach ($thresholds as $n) {
			if ($total < $n) break;
			$this->db->Clear();
			$offset = $n - 1;
			$rr = $this->db->query("SELECT date FROM " . DB_PREFIX . "attendance WHERE kingdom_id = $kingdom_id AND date >= '1988-01-01' ORDER BY date ASC LIMIT 1 OFFSET $offset");
			if ($rr !== false && $rr->size() > 0) {
				$rr->next();
				$d = $rr->date;
				if ($d && $d !== '0000-00-00') {
					$out[] = [
						'Type'          => 'attendance_count',
						'Icon'          => 'fa-clipboard-list',
						'Description'   => number_format($n) . 'th attendance recorded',
						'MilestoneDate' => $d,
						'IsDerived'     => true,
					];
				}
			}
		}
		// 3) Distinct-member crossings — scaled up for kingdom size.
		$this->db->Clear();
		$r = $this->db->query("SELECT COUNT(DISTINCT mundane_id) AS members FROM " . DB_PREFIX . "attendance WHERE kingdom_id = $kingdom_id");
		$members = 0;
		if ($r !== false && $r->size() > 0) { $r->next(); $members = (int)$r->members; }
		$memberThresholds = [100, 500, 1000, 5000, 10000];
		foreach ($memberThresholds as $n) {
			if ($members < $n) break;
			$this->db->Clear();
			$offset = $n - 1;
			$rr = $this->db->query("SELECT MIN(date) AS first_date FROM " . DB_PREFIX . "attendance WHERE kingdom_id = $kingdom_id AND date >= '1988-01-01' GROUP BY mundane_id ORDER BY first_date ASC LIMIT 1 OFFSET $offset");
			if ($rr !== false && $rr->size() > 0) {
				$rr->next();
				$d = $rr->first_date;
				if ($d && $d !== '0000-00-00') {
					$out[] = [
						'Type'          => 'distinct_members',
						'Icon'          => 'fa-users',
						'Description'   => number_format($n) . 'th distinct member attended',
						'MilestoneDate' => $d,
						'IsDerived'     => true,
					];
				}
			}
		}
		$response = ['Status' => Success(), 'Milestones' => $out];
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
	}

}

?>