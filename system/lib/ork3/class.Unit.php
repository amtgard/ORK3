<?php

class Unit extends Ork3 {

	public function __construct() {
		parent::__construct();
		$this->unit = new yapo($this->db, DB_PREFIX . 'unit');
		$this->members = new yapo($this->db, DB_PREFIX . 'unit_mundane');
	}
	
    public function MergeUnits($request) {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
			&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $request['FromUnitId'], AUTH_CREATE)
            && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $request['ToUnitId'], AUTH_CREATE)) {
                
    		$sql = "update " . DB_PREFIX ."unit_mundane set unit_id = '" . mysql_real_escape_string($request['ToUnitId']) . "' where unit_id = '" . mysql_real_escape_string($request['FromUnitId']) . "'";
			$this->db->query($sql);
        	$sql = "update " . DB_PREFIX ."authorization set unit_id = '" . mysql_real_escape_string($request['ToUnitId']) . "' where unit_id = '" . mysql_real_escape_string($request['FromUnitId']) . "'";
			$this->db->query($sql);
            $sql = "update " . DB_PREFIX ."awards set unit_id = '" . mysql_real_escape_string($request['ToUnitId']) . "' where unit_id = $request'" . mysql_real_escape_string($request['FromUnitId']) . "'";
			$this->db->query($sql);
            $sql = "update " . DB_PREFIX ."event set unit_id = '" . mysql_real_escape_string($request['ToUnitId']) . "' where unit_id = $request'" . mysql_real_escape_string($request['FromUnitId']) . "'";
    		$this->db->query($sql);
            $sql = "update " . DB_PREFIX ."participant set unit_id = '" . mysql_real_escape_string($request['ToUnitId']) . "' where unit_id = '" . mysql_real_escape_string($request['FromUnitId']) . "'";
        	$this->db->query($sql);
            
    		$sql = "delete from " . DB_PREFIX ."unit where unit_id = '" . mysql_real_escape_string($request['FromUnitId']) . "'";
			$this->db->query($sql);
        }
    }
    
    public function ConvertToHousehold($request) {
        logtrace('ConvertToHousehold', $request);
		$mundane = Ork3::$Lib->player->player_info($request['Token']);

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
    
    public function AddAward($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0)
			return NoAuthorization();
            
        $mundane = new yapo($this->db, DB_PREFIX . 'mundane');
		$mundane->clear();
		$mundane->mundane_id = $mundane_id;
		if (!$mundane->find()) {
			return InvalidParameter();
		}
		$authorizer = array ( 'KingdomId' => $mundane->kingdom_id, 'ParkId' => $mundane->park_id );
        
        if (valid_id($request['AwardId'])) {
            $request['KingdomAwardId'] = Ork3::$Lib->award->LookupAward(array('KingdomId' => $recipient['KingdomId'], 'AwardId' => $request['AwardId']));
        } else if (valid_id($request['KingdomAwardId'])) {
            list($kingdom_id, $request['AwardId']) = Ork3::$Lib->award->LookupKingdomAward(array('KingdomAwardId' => $recipient['KingdomAwardId']));
        }
		if (valid_id($mundane_id)
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $authorizer['ParkId'], AUTH_EDIT)) {
            if (valid_id($request['GivenById']))
        		$given_by = $this->GetPlayer(array('MundaneId' => $request['GivenById']));
			if (valid_id($request['ParkId'])) {
				$Park = new Park();
				$park_info = $Park->GetParkShortInfo(array( 'ParkId' => $given_by['Player']['ParkId'] ));
				if ($park_info['Status']['Status'] != 0)
					return InvalidParameter('Invalid Parameter 2');
			}
            if (valid_id($request['AwardId'])) {
                $request['KingdomAwardId'] = Ork3::$Lib->award->LookupAward(array('KingdomId' => $request['KingdomId'], 'AwardId' => $request['AwardId']));
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
            if (valid_id($request['GivenById'])) {
    			$awards->park_id = valid_id($given_by['Player']['ParkId'])?$given_by['Player']['ParkId']:0;
    			// If no event and valid parkid, go Park! Otherwise, go Kingdom.  Unless it's an event.  Then go ... ZERO!
    			$awards->kingdom_id = valid_id($given_by['Player']['KingdomId'])?$given_by['Player']['KingdomId']:0;
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
		} else {
			$response['Status'] = InvalidParameter();
		}
		return $response;
	}
	
	public function AddMember($request) {
		if (!valid_id($request['MundaneId'])) {
			InvalidParameter();
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
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $unit_id, AUTH_CREATE)
				|| $mundane_id == $member_id) {
			$this->members->clear();
			$this->members->unit_mundane_id = $request['UnitMundaneId'];
			$this->members->find();
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
			$this->members->find();
			$mundane_id = $this->members->mundane_id;
			$unit_id = $this->members->unit_id;
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
			$this->unit->name = $request['Name'];
			$this->unit->type = $request['Type'];
			$this->unit->description = strip_tags($request['Description'], "<p><br><ul><li><b><i>");
			$this->unit->history = strip_tags($request['History'], "<p><br><ul><li><b><i>");
			$this->unit->url = $request['Url'];
			$this->unit->modified = date("Y-m-d H:i:s");
			$this->unit->save();
    		$request['UnitId'] = $this->unit->unit_id;
			
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

			Ork3::$Lib->authorization->add_auth_h(array('MundaneId'=>$mundane_id, 'Type'=>AUTH_UNIT, 'Id' => $this->unit->unit_id, 'Role' => AUTH_EDIT));
			
			$request['MundaneId'] = $mundane_id;
			switch ($this->unit->type) {
    			case 'Company': $request['Role'] = 'captain'; break;
    			case 'Household': $request['Role'] = 'lord'; break;
    			case 'Event': $request['Role'] = 'organizer'; break;
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
			$this->unit->name = $request['Name'];
			$this->unit->description = strip_tags($request['Description'], "<p><br><ul><li><b><i>");
			$this->unit->history = strip_tags($request['History'], "<p><br><ul><li><b><i>");
			$this->unit->url = $request['Url'];
			$this->unit->save();
			if (strlen($request['Heraldry'])) {
				logtrace("SetUnit() :SetUnitHeraldry()",null);
				Ork3::$Lib->heraldry->SetUnitHeraldry($request);
			}
			return Success();
		}
		return NoAuthorization();
	}
	
	public function add_member_h($request) {
		logtrace("add_member_h", $request);
		$this->unit->clear();
		$this->unit->type = 'Company';
		$this->unit->unit_id = $request['UnitId'];
		
		if ($this->unit->find()) {
			$this->members->clear();
			$this->members->unit_id = $request['UnitId'];
			$this->members->mundane_id = $request['MundaneId'];
			$this->members->active = 'Active';
			if ($this->members->find()) {
				return InvalidParameter('Player is already an active member of this company.');
			}
			$this->members->clear();
			$this->members->mundane_id = $request['MundaneId'];
			$this->members->unit_id = $request['UnitId'];
			$this->members->active = 'Retired';
			if ($this->members->find()) {
				$this->members->active = 'Active';
				$this->members->save();
				return Success($this->members->unit_mundane_id);
			}
		}
			
		$this->members->clear();
		$this->members->unit_id = $request['UnitId'];
		$this->members->mundane_id = $request['MundaneId'];
		$this->members->role = $request['Role'];
		$this->members->title = $request['Title'];
		$this->members->active = $request['Active'];
		$this->members->save();
		return Success($this->members->unit_mundane_id);
	}
	
}

?>