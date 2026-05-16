<?php

class Unit extends Ork3 {

	public function __construct() {
		parent::__construct();
		$this->unit = new yapo($this->db, DB_PREFIX . 'unit');
		$this->members = new yapo($this->db, DB_PREFIX . 'unit_mundane');
	}

    public function MergeUnits($request) {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) === 0) {
            return NoAuthorization();
        }
        if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $request['FromUnitId'], AUTH_CREATE)
            || !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $request['ToUnitId'], AUTH_CREATE)) {
            return NoAuthorization();
        }

        $to   = (int)$request['ToUnitId'];
        $from = (int)$request['FromUnitId'];

        if (!valid_id($to) || !valid_id($from) || $to === $from) {
            return InvalidParameter('Invalid unit IDs for merge.');
        }

        $this->db->BeginTrans();
        try {
            $sql = "update " . DB_PREFIX . "unit_mundane set unit_id = '" . $to . "' where unit_id = '" . $from . "'";
            if (!$this->db->query($sql)) { throw new Exception('unit_mundane update failed'); }

            $sql = "update " . DB_PREFIX . "authorization set unit_id = '" . $to . "' where unit_id = '" . $from . "'";
            if (!$this->db->query($sql)) { throw new Exception('authorization update failed'); }

            $sql = "update " . DB_PREFIX . "awards set unit_id = '" . $to . "' where unit_id = '" . $from . "'";
            if (!$this->db->query($sql)) { throw new Exception('awards update failed'); }

            $sql = "update " . DB_PREFIX . "event set unit_id = '" . $to . "' where unit_id = '" . $from . "'";
            if (!$this->db->query($sql)) { throw new Exception('event update failed'); }

            $sql = "update " . DB_PREFIX . "participant set unit_id = '" . $to . "' where unit_id = '" . $from . "'";
            if (!$this->db->query($sql)) { throw new Exception('participant update failed'); }

            $sql = "update " . DB_PREFIX . "mundane set company_id = '" . $to . "' where company_id = '" . $from . "'";
            if (!$this->db->query($sql)) { throw new Exception('mundane update failed'); }

            $sql = "delete from " . DB_PREFIX . "unit where unit_id = '" . $from . "'";
            if (!$this->db->query($sql)) { throw new Exception('unit delete failed'); }

            $this->db->CommitTrans();
            return Success();
        } catch (Exception $e) {
            $this->db->RollbackTrans();
            return array('Status' => 1, 'Error' => 'MergeUnits failed', 'Detail' => $e->getMessage());
        }
    }

    public function ConvertToHousehold($request) {
        logtrace('ConvertToHousehold', $request);
		$mundane = Ork3::$Lib->player->player_info($request['Token']);
		if (!$mundane) return NoAuthorization();

    	if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
			&& (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $request['UnitId'], AUTH_CREATE)
			    || Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $mundane['KingdomId'], AUTH_EDIT) )) {

            $this->unit->clear();
            $this->unit->unit_id = $request['UnitId'];
            if ($this->unit->find() && $this->unit->type == 'Company') {
                $this->unit->type = 'Household';
                $this->unit->save();
                return Success();
            } else {
                return InvalidParameter();
            }
        }
        return NoAuthorization();
    }

    public function ConvertToCompany($request) {
        logtrace('ConvertToCompany', $request);
		$mundane = Ork3::$Lib->player->player_info($request['Token']);
		if (!$mundane) return NoAuthorization();

    	if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
			&& (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $request['UnitId'], AUTH_CREATE)
			    || Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $mundane['KingdomId'], AUTH_EDIT) )) {

            $this->unit->clear();
            $this->unit->unit_id = $request['UnitId'];
            if ($this->unit->find() && $this->unit->type == 'Household') {
                $this->unit->type = 'Company';
                $this->unit->save();
                return Success();
            } else {
                return InvalidParameter();
            }
        }
        return NoAuthorization();
    }

	public function AddAward($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) === 0)
			return NoAuthorization();

        $mundane = new yapo($this->db, DB_PREFIX . 'mundane');
		$mundane->clear();
		$mundane->mundane_id = $mundane_id;
		if (!$mundane->find()) {
			return InvalidParameter();
		}
		$authorizer = array ( 'KingdomId' => $mundane->kingdom_id, 'ParkId' => $mundane->park_id );

        if (valid_id($request['AwardId'])) {
            list($request['KingdomAwardId'], $request['AwardId']) = Ork3::$Lib->award->LookupAward(array('KingdomId' => $request['KingdomId'], 'AwardId' => $request['AwardId']));
        } else if (valid_id($request['KingdomAwardId'])) {
            list($kingdom_id, $request['AwardId']) = Ork3::$Lib->award->LookupKingdomAward(array('KingdomAwardId' => $request['KingdomAwardId']));
        }
		if (valid_id($mundane_id)
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $authorizer['ParkId'], AUTH_EDIT)) {
            $given_by = null;
            if (valid_id($request['GivenById'])) {
                $given_by = $this->GetPlayer(array('MundaneId' => $request['GivenById']));
                if (empty($given_by['Player'])) {
                    $given_by = null;
                }
            }
			if (valid_id($request['ParkId']) && valid_id($request['GivenById']) && !empty($given_by['Player']['ParkId'])) {
				$Park = new Park();
				$park_info = $Park->GetParkShortInfo(array( 'ParkId' => $given_by['Player']['ParkId'] ));
				if ($park_info['Status']['Status'] != 0)
					return InvalidParameter('Invalid Parameter 2');
			}
			$awards = new yapo($this->db, DB_PREFIX . 'awards');
			$awards->clear();
			$awards->kingdomaward_id = $request['KingdomAwardId'];
    		$awards->award_id = $request['AwardId'];
			$awards->custom_name = $request['CustomName'];
			$awards->unit_id = $request['RecipientId'];
			$awards->rank = $request['Rank'];
			$awards->date = $request['Date'];
			$awards->given_by_id = $request['GivenById'];
			$awards->at_park_id = valid_id($request['ParkId'])?$request['ParkId']:0;
			$awards->at_kingdom_id = valid_id($request['KingdomId'])?$request['KingdomId']:0;
			$awards->at_event_id = valid_id($request['EventId'])?$request['EventId']:0;
			$awards->note = $request['Note'];
			// If no event, then go Park!
            if ($given_by !== null) {
    			$awards->park_id = valid_id($given_by['Player']['ParkId']) ? $given_by['Player']['ParkId'] : 0;
    			// If no event and valid parkid, go Park! Otherwise, go Kingdom.  Unless it's an event.  Then go ... ZERO!
    			$awards->kingdom_id = valid_id($given_by['Player']['KingdomId']) ? $given_by['Player']['KingdomId'] : 0;
            }
			// Events are awesome.
			$awards->save();
			return Success($awards->awards_id);
		} else {
			return NoAuthorization('No Authorization');
		}
	}

    public function GetUnit($request) {
		$this->unit->clear();
		$this->unit->unit_id = $request['UnitId'];
		$response = array();
		if (valid_id($request['UnitId']) && $this->unit->find()) {
			$response['Status'] = Success();
			$response['Unit'] = array(
					'UnitId' => $this->unit->unit_id,
					'Type' => $this->unit->type,
					'HasHeraldry' => $this->unit->has_heraldry,
					'Name' => $this->unit->name,
					'Description' => $this->unit->description,
					'Url' => $this->unit->url,
					'History' => $this->unit->history
				);
			$this->db->Clear();
			$design = new yapo($this->db, DB_PREFIX . 'unit_design');
			$design->clear();
			$design->unit_id = $this->unit->unit_id;
			if (!$design->find()) {
				$design->clear();
				$design->unit_id      = $this->unit->unit_id;
				$design->hero_overlay = 'med';
				$design->save();
				$design->clear();
				$this->db->Clear();
				$design->unit_id = $this->unit->unit_id;
				$design->find();
			}
			$response['Unit']['AboutText']       = (string)$design->about_text;
			$response['Unit']['OurHistory']      = (string)$design->our_history;
			$response['Unit']['ColorPrimary']    = $design->color_primary;
			$response['Unit']['ColorAccent']     = $design->color_accent;
			$response['Unit']['ColorSecondary']  = $design->color_secondary;
			$response['Unit']['HeroOverlay']     = $design->hero_overlay ?: 'med';
			$response['Unit']['NameFont']        = $design->name_font;
			$response['Unit']['MilestoneConfig'] = $design->milestone_config;
			$response['Unit']['Tagline']            = (string)$design->tagline;
			$response['Unit']['SocialLinks']        = (string)$design->social_links;
			$response['Unit']['Announcement']       = (string)$design->announcement;
			$response['Unit']['AnnouncementUntil']  = $design->announcement_until;
			$response['Unit']['RecruitmentStatus']  = (string)$design->recruitment_status;
			$response['Unit']['HowToJoin']          = (string)$design->how_to_join;
		} else {
			$response['Status'] = InvalidParameter();
		}
		return $response;
	}

	public function AddMember($request) {
		if (!valid_id($request['MundaneId'])) {
			return InvalidParameter();
		}
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $request['UnitId'], AUTH_CREATE)) {
			logtrace("AddMember", $request);
			return $this->add_member_h($request);
		}
		return NoAuthorization();
	}

	public function SetMember($request) {
		$this->members->clear();
		$this->members->unit_mundane_id = $request['UnitMundaneId'];
		if (valid_id($request['UnitMundaneId']) && $this->members->find()) {
			$unit_id = $this->members->unit_id;
			if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
					&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $unit_id, AUTH_CREATE)) {
				$this->members->clear();
				$this->members->unit_mundane_id = $request['UnitMundaneId'];
				$this->members->find();
				$this->members->active = $request['Active'];
				$this->members->role = $request['Role'];
				$this->members->title = $request['Title'];
				$this->members->save();
				return Success();
			}
			return NoAuthorization();
		}
		return InvalidParameter();
	}

	public function _translate_unitmundane($unit_mundane_id) {
	    $this->members->clear();
	    $this->members->unit_mundane_id = $unit_mundane_id;
	    if ($this->members->find()) {
	        return array($this->members->mundane_id, $this->members->unit_id);
	    }
	    return array(0,0);
	}

	public function RetireMember($request) {
	    logtrace("RetireMember", $request);
	    list($member_id, $unit_id) = $this->_translate_unitmundane($request['UnitMundaneId']);
	    logtrace('Retire Member:', array($member_id, $unit_id));
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $unit_id, AUTH_CREATE)
					|| $mundane_id == $member_id)) {
			$this->members->clear();
			$this->members->unit_mundane_id = $request['UnitMundaneId'];
			if (!$this->members->find()) {
				return InvalidParameter();
			}
			$mundane_id = $this->members->mundane_id;
			$unit_id = $this->members->unit_id;
			logtrace("RetireMember()", array($mundane_id, $unit_id));
			$this->members->active = 'Retired';
			$this->members->save();
			logtrace("RetireMember()", $this->members->lastSql());
			$auths = Ork3::$Lib->authorization->GetAuthorizations(array('MundaneId'=>$mundane_id));
			foreach ($auths['Authorizations'] as $k => $auth) {
				if ($auth['Type'] == AUTH_UNIT && $auth['Id'] == $unit_id) {
					Ork3::$Lib->authorization->remove_auth_h(array('AuthorizationId'=>$auth['AuthorizationId']));
					break;
				}
			}
			return Success();
		}
		return NoAuthorization();
	}

	public function RemoveMember($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $request['UnitId'], AUTH_CREATE)) {
			$this->members->clear();
			$this->members->unit_mundane_id = $request['UnitMundaneId'];
			if (!$this->members->find()) {
				return InvalidParameter();
			}
			$mundane_id = $this->members->mundane_id;
			$unit_id = $this->members->unit_id;
			if ($this->members->unit_id != $request['UnitId']) {
				return NoAuthorization();
			}
			$this->members->delete();
			$auths = Ork3::$Lib->authorization->GetAuthorizations(array('MundaneId'=>$mundane_id));
			foreach ($auths['Authorizations'] as $k => $auth) {
				if ($auth['Type'] == AUTH_UNIT && $auth['Id'] == $unit_id) {
					Ork3::$Lib->authorization->remove_auth_h(array('AuthorizationId'=>$auth['AuthorizationId']));
					break;
				}
			}
			return Success();
		}
		return NoAuthorization();
	}

	public function CreateUnit($request) {
		logtrace("CreateUnit()", $request);
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0) {

			$this->unit->clear();
			if (!in_array($request['Type'], array('Company', 'Household', 'Event'))) {
				return InvalidParameter('Invalid unit type.');
			}
			if (!empty($request['Url']) && !preg_match('#^https?://#i', $request['Url'])) {
				return InvalidParameter('Invalid URL.');
			}
			$this->unit->name = $request['Name'];
			$this->unit->type = $request['Type'];
			$this->unit->description = trim($request['Description']);
			$this->unit->history = trim($request['History']);
			$this->unit->url = $request['Url'];
			$this->unit->modified = date("Y-m-d H:i:s");
			$this->unit->save();
    		$request['UnitId'] = $this->unit->unit_id;
			$this->db->Clear();
			$_design_seed = new yapo($this->db, DB_PREFIX . 'unit_design');
			$_design_seed->clear();
			$_design_seed->unit_id      = (int)$this->unit->unit_id;
			$_design_seed->hero_overlay = 'med';
			$_design_seed->save();

    		if (strlen($request['Heraldry']) > 0) {
				logtrace("CreateUnit() :2", $request);
				Ork3::$Lib->heraldry->SetUnitHeraldry($request);
			}

            if ($request['Anonymous']
                    && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_CREATE)) {
        		return Success($request['UnitId']);
            }
			if ($this->unit->type == 'Company') {
				$mundane = new yapo($this->db, DB_PREFIX . 'mundane');
				$mundane->mundane_id = $mundane_id;
				$mundane->find();
				$mundane->company_id = $this->unit->unit_id;
				$mundane->save();
			}

			Ork3::$Lib->authorization->add_auth_h(array('MundaneId'=>$mundane_id, 'Type'=>AUTH_UNIT, 'Id' => $this->unit->unit_id, 'Role' => AUTH_CREATE));

			$request['MundaneId'] = $mundane_id;
			switch ($this->unit->type) {
    			case 'Company': $request['Role'] = 'captain'; break;
    			case 'Household': $request['Role'] = 'lord'; break;
    			case 'Event': $request['Role'] = 'organizer'; break;
    			default: $request['Role'] = 'member'; break;
			}
			$request['Title'] = 'Founder';
			$request['Active'] = 1;
			$this->add_member_h($request);

    		return Success($request['UnitId']);

		} else {
			return NoAuthorization();
		}
	}

	public function SetUnit($request) {
		logtrace("SetUnit()", $request);
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $request['UnitId'], AUTH_CREATE)) {
			logtrace("SetUnit() :Secure",null);
			$this->unit->clear();
			$this->unit->unit_id = $request['UnitId'];
			$this->unit->find();
			if (!empty($request['Url']) && !preg_match('#^https?://#i', $request['Url'])) {
				return InvalidParameter('Invalid URL.');
			}
			$this->unit->name = $request['Name'];
			$this->unit->description = trim($request['Description']);
			$this->unit->history = trim($request['History']);
			$this->unit->url = $request['Url'];
			$this->unit->save();
			if (strlen($request['Heraldry'])) {
				logtrace("SetUnit() :SetUnitHeraldry()",null);
				Ork3::$Lib->heraldry->SetUnitHeraldry($request);
			}
			$this->db->Clear();
			$_design_sync = new yapo($this->db, DB_PREFIX . 'unit_design');
			$_design_sync->clear();
			$_design_sync->unit_id = (int)$this->unit->unit_id;
			if ($_design_sync->find()) {
				$_dirty = false;
				if (isset($request['Description']) && trim((string)$request['Description']) !== '' && trim((string)$_design_sync->about_text) !== '') {
					$_design_sync->about_text = trim((string)$request['Description']);
					$_dirty = true;
				}
				if (isset($request['History']) && trim((string)$request['History']) !== '' && trim((string)$_design_sync->our_history) !== '') {
					$_design_sync->our_history = trim((string)$request['History']);
					$_dirty = true;
				}
				if ($_dirty) $_design_sync->save();
			}
			return Success();
		}
		return NoAuthorization();
	}

	public function add_member_h($request) {
		logtrace("add_member_h", $request);
		$this->unit->clear();
		$this->unit->unit_id = $request['UnitId'];

		if ($this->unit->find()) {
			$this->members->clear();
			$this->members->unit_id = $request['UnitId'];
			$this->members->mundane_id = $request['MundaneId'];
			$this->members->active = 'Active';
			if ($this->members->find()) {
				return InvalidParameter('Player is already an active member of this ' . $this->unit->type . '.');
			}
			$this->members->clear();
			$this->members->mundane_id = $request['MundaneId'];
			$this->members->unit_id = $request['UnitId'];
			$this->members->active = 'Retired';
			if ($this->members->find()) {
				$this->members->active = 'Active';
				$this->members->role = $request['Role'];
				$this->members->title = $request['Title'];
				$this->members->save();
				return Success($this->members->unit_mundane_id);
			}

			// Brand new member — unit exists but was neither active nor retired
			$this->members->clear();
			$this->members->unit_id = $request['UnitId'];
			$this->members->mundane_id = $request['MundaneId'];
			$this->members->role = $request['Role'];
			$this->members->title = $request['Title'];
			$this->members->active = $request['Active'];
			$this->members->save();
			return Success($this->members->unit_mundane_id);
		} else {
			return InvalidParameter('Unit not found.');
		}
	}

	public function SetUnitDesign($request) {
		$unit_id = (int)($request['UnitId'] ?? 0);
		if ($unit_id <= 0) return InvalidParameter('UnitId is required.');
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if (!($mundane_id > 0) || !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $unit_id, AUTH_EDIT)) {
			return NoAuthorization();
		}
		require_once(__DIR__ . '/class.ProfanityFilter.php');
		$pf = new ProfanityFilter();
		foreach (['AboutText' => 'AboutText', 'OurHistory' => 'OurHistory', 'Tagline' => 'Tagline', 'Announcement' => 'Announcement', 'HowToJoin' => 'HowToJoin'] as $field => $label) {
			if (isset($request[$field]) && trim((string)$request[$field]) !== '') {
				if ($pf->containsProfanity((string)$request[$field])) {
					return InvalidParameter($label, ProfanityFilter::ERROR_MESSAGE);
				}
			}
		}
		$this->db->Clear();
		$design = new yapo($this->db, DB_PREFIX . 'unit_design');
		$design->clear();
		$design->unit_id = $unit_id;
		if (!$design->find()) {
			$design->clear();
			$design->unit_id      = $unit_id;
			$design->hero_overlay = 'med';
			$design->save();
			$design->clear();
			$this->db->Clear();
			$design->unit_id = $unit_id;
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
			$design->tagline = $tg === '' ? null : $tg;
		}
		if (array_key_exists('SocialLinks', $request)) {
			$sl = trim((string)$request['SocialLinks']);
			if ($sl === '') {
				$design->social_links = null;
			} else {
				$decoded = json_decode($sl, true);
				if (!is_array($decoded)) {
					return InvalidParameter('Social links must be valid JSON.');
				}
				$clean = [];
				foreach ($decoded as $platform => $url) {
					$url = trim((string)$url);
					if ($url === '') continue;
					if (preg_match('#^http://#i', $url)) {
						$url = 'https://' . substr($url, 7);
					} elseif (!preg_match('#^https://#i', $url)) {
						$url = 'https://' . ltrim($url, '/');
					}
					if (strlen($url) > 500) {
						return InvalidParameter('Social link for ' . $platform . ' must be 500 characters or fewer.');
					}
					$clean[(string)$platform] = $url;
				}
				$design->social_links = empty($clean) ? null : json_encode($clean);
			}
		}
		if (array_key_exists('Announcement', $request)) {
			$an = trim((string)$request['Announcement']);
			if (strlen($an) > 280) {
				return InvalidParameter('Announcement is limited to 280 characters.');
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
					return InvalidParameter('Announcement "Show until" must be a valid date.');
				}
				$design->announcement_until = date('Y-m-d', $ts);
			}
		}
		if (array_key_exists('RecruitmentStatus', $request)) {
			$rs = strtolower(trim((string)$request['RecruitmentStatus']));
			if ($rs === '' || $rs === 'none' || $rs === 'unset') {
				$design->recruitment_status = null;
			} elseif (in_array($rs, ['open','invite','closed'], true)) {
				$design->recruitment_status = $rs;
			} else {
				return InvalidParameter('Recruitment status must be open, invite, or closed.');
			}
		}
		if (array_key_exists('HowToJoin', $request)) {
			$hj = (string)$request['HowToJoin'];
			if (strlen($hj) > 5000) {
				return InvalidParameter('HowToJoin is limited to 5,000 characters.');
			}
			$design->how_to_join = trim($hj) === '' ? null : $hj;
		}
		$design->save();
		return Success($unit_id);
	}

	public function GetUnitMilestones($request) {
		$unit_id = (int)($request['UnitId'] ?? 0);
		if ($unit_id <= 0) return InvalidParameter('UnitId is required.');
		$this->db->Clear();
		$ms = new yapo($this->db, DB_PREFIX . 'unit_milestones');
		$ms->clear();
		$ms->unit_id = $unit_id;
		$rows = [];
		if ($ms->find()) {
			do {
				$rows[] = [
					'MilestoneId'   => (int)$ms->milestone_id,
					'UnitId'        => (int)$ms->unit_id,
					'Icon'          => $ms->icon,
					'Description'   => $ms->description,
					'MilestoneDate' => $ms->milestone_date,
				];
			} while ($ms->next());
		}
		return ['Status' => Success(), 'Milestones' => $rows];
	}

	public function AddUnitMilestone($request) {
		$unit_id = (int)($request['UnitId'] ?? 0);
		if ($unit_id <= 0) return InvalidParameter('UnitId is required.');
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if (!($mundane_id > 0) || !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $unit_id, AUTH_EDIT)) {
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
		$ms = new yapo($this->db, DB_PREFIX . 'unit_milestones');
		$ms->clear();
		$ms->unit_id        = $unit_id;
		$ms->icon           = $icon;
		$ms->description    = $desc;
		$ms->milestone_date = date('Y-m-d', $ts);
		$ms->save();
		return Success((int)$ms->milestone_id);
	}

	public function DeleteUnitMilestone($request) {
		$unit_id      = (int)($request['UnitId']      ?? 0);
		$milestone_id = (int)($request['MilestoneId'] ?? 0);
		if ($unit_id <= 0 || $milestone_id <= 0) return InvalidParameter('UnitId and MilestoneId required.');
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if (!($mundane_id > 0) || !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $unit_id, AUTH_EDIT)) {
			return NoAuthorization();
		}
		$this->db->Clear();
		$ms = new yapo($this->db, DB_PREFIX . 'unit_milestones');
		$ms->clear();
		$ms->milestone_id = $milestone_id;
		$ms->unit_id      = $unit_id;
		if (!$ms->find()) return InvalidParameter('Milestone not found.');
		$ms->delete();
		return Success();
	}

	public function GetDerivedUnitMilestones($request) {
		$unit_id = (int)(is_array($request) ? ($request['UnitId'] ?? 0) : $request);
		if ($unit_id <= 0) return ['Status' => InvalidParameter('UnitId is required.'), 'Milestones' => []];
		$key = Ork3::$Lib->ghettocache->key(['UnitId' => $unit_id]);
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 300)) !== false)
			return $cache;
		$out = [];
		$this->db->Clear();
		$r = $this->db->query("SELECT MIN(a.date) AS first_date FROM " . DB_PREFIX . "attendance a JOIN " . DB_PREFIX . "unit_mundane um ON um.mundane_id = a.mundane_id WHERE um.unit_id = $unit_id AND a.date >= '1988-01-01'");
		if ($r !== false && $r->size() > 0) {
			$r->next();
			$fd = $r->first_date;
			if ($fd && $fd !== '0000-00-00') {
				$out[] = [
					'Type'          => 'first_member_activity',
					'Icon'          => 'fa-door-open',
					'Description'   => 'First recorded activity by a member',
					'MilestoneDate' => $fd,
					'IsDerived'     => true,
				];
			}
		}
		$response = ['Status' => Success(), 'Milestones' => $out];
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
	}

}

?>
