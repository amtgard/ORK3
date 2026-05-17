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
					'HasBanner'      => (int)$this->unit->has_banner,
					'BannerShowLogo' => (int)$this->unit->banner_show_logo,
					'BannerVignette' => (int)$this->unit->banner_vignette,
					'BannerOffsetX'  => (int)$this->unit->banner_offset_x,
					'BannerOffsetY'  => (int)$this->unit->banner_offset_y,
					'Name' => $this->unit->name,
					'Description' => $this->unit->description,
					'Url' => $this->unit->url,
					'History' => $this->unit->history,
					'Active' => $this->unit->active
				);
		} else {
			$response['Status'] = InvalidParameter();
		}
		return $response;
	}

	public function AddMember($request) {
		if (!valid_id($request['MundaneId'])) {
			return InvalidParameter();
		}
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		$mundane = $mundane_id > 0 ? Ork3::$Lib->player->player_info($request['Token']) : null;
		if ($mundane_id > 0
				&& (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $request['UnitId'], AUTH_CREATE)
				    || ($mundane && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $mundane['KingdomId'], AUTH_EDIT)))) {
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

	/* ── Retire / Restore / Claim / Transfer ───────────────────────
	 * Soft-delete support for units (see 2026-05-26-add-unit-active migration).
	 * Retired units are hidden from unit lists and player search; only a
	 * kingdom officer (monarchy) can restore them. */

	private function _unit_manager_ids($unit_id) {
		$auth = new yapo($this->db, DB_PREFIX . 'authorization');
		$auth->clear();
		$auth->unit_id = $unit_id;
		$ids = array();
		if (valid_id($unit_id) && $auth->find()) {
			do { $ids[] = (int)$auth->mundane_id; } while ($auth->next());
		}
		return array_values(array_unique($ids));
	}

	private function _active_member_roles($unit_id) {
		$this->members->clear();
		$this->members->unit_id = $unit_id;
		$this->members->active = 'Active';
		$roles = array();
		if (valid_id($unit_id) && $this->members->find()) {
			do { $roles[(int)$this->members->mundane_id] = $this->members->role; } while ($this->members->next());
		}
		return $roles;
	}

	private function _remove_unit_auth_for($mundane_id, $unit_id) {
		$auths = Ork3::$Lib->authorization->GetAuthorizations(array('MundaneId' => $mundane_id));
		foreach ($auths['Authorizations'] as $auth) {
			if ($auth['Type'] == AUTH_UNIT && $auth['Id'] == $unit_id) {
				Ork3::$Lib->authorization->remove_auth_h(array('AuthorizationId' => $auth['AuthorizationId']));
			}
		}
	}

	// Grant unit-manager authority via the raw helper (callers below have
	// already done their own permission checks, so we bypass add_authorization's
	// HasAuthority gate — a claimant has no authority yet by definition). Audited
	// to the grantee so the change surfaces on their player audit history.
	private function _grant_unit_manager($grantee_id, $unit_id) {
		$r = Ork3::$Lib->authorization->add_auth_h(array('MundaneId' => $grantee_id, 'Type' => AUTH_UNIT, 'Id' => $unit_id, 'Role' => AUTH_CREATE));
		Ork3::$Lib->dangeraudit->audit('Authorization::AddAuthorization',
			array('MundaneId' => $grantee_id, 'Type' => AUTH_UNIT, 'Id' => $unit_id, 'Role' => AUTH_CREATE),
			'Player', $grantee_id, null, array(
				'authorization_id' => (int)($r['Detail'] ?? 0),
				'mundane_id'       => $grantee_id,
				'park_id'          => 0,
				'kingdom_id'       => 0,
				'event_id'         => 0,
				'unit_id'          => $unit_id,
				'role'             => AUTH_CREATE,
			));
		return $r;
	}

	public function WaffleUnit($request, $waffle) {
		$this->unit->clear();
		$this->unit->unit_id = $request['UnitId'];
		if (!valid_id($request['UnitId']) || !$this->unit->find()) {
			return InvalidParameter();
		}
		$unit_id    = (int)$request['UnitId'];
		$actor_id   = isset($request['ActorId']) ? (int)$request['ActorId'] : null;
		$member_ids = array_keys($this->_active_member_roles($unit_id));
		$this->unit->active = $waffle;
		$this->unit->save();
		Ork3::$Lib->dangeraudit->audit('Unit::' . ($waffle === 'Retired' ? 'RetireUnit' : 'RestoreUnit'),
			$request, 'Unit', $unit_id, $actor_id, ['unit_id' => $unit_id, 'active' => $waffle]);
		foreach ($member_ids as $mid) {
			$ck = Ork3::$Lib->ghettocache->key(['MundaneId' => $mid, 'IncludeCompanies' => 1, 'IncludeHouseHolds' => 1, 'IncludeEvents' => 1, 'ActiveOnly' => 1, 'Lightweight' => 1]);
			Ork3::$Lib->ghettocache->bust('Report.UnitSummary', $ck);
		}
		return Success();
	}

	public function RetireUnit($request) {
		logtrace('RetireUnit', $request);
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if ($mundane_id === 0) return NoAuthorization();
		$mundane  = Ork3::$Lib->player->player_info($request['Token']);
		$unit_id  = (int)$request['UnitId'];

		$is_manager = Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $unit_id, AUTH_CREATE);
		$is_officer = $mundane && (
			Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $mundane['KingdomId'], AUTH_EDIT) ||
			Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)
		);
		// Sole-member exception: the only remaining active roster member may
		// retire even without management rights.
		$members = $this->_active_member_roles($unit_id);
		$is_sole_member = (count($members) === 1 && isset($members[$mundane_id]));

		if ($is_manager || $is_officer || $is_sole_member) {
			return $this->WaffleUnit($request + ['ActorId' => $mundane_id], 'Retired');
		}
		return NoAuthorization();
	}

	public function RestoreUnit($request) {
		logtrace('RestoreUnit', $request);
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if ($mundane_id === 0) return NoAuthorization();
		$mundane = Ork3::$Lib->player->player_info($request['Token']);
		// Reactivation is monarchy-only — mirrors the KPM unit-auth bypass scope.
		if ($mundane && (
			Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $mundane['KingdomId'], AUTH_EDIT) ||
			Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)
		)) {
			return $this->WaffleUnit($request + ['ActorId' => $mundane_id], 'Active');
		}
		return NoAuthorization();
	}

	public function ClaimUnit($request) {
		logtrace('ClaimUnit', $request);
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if ($mundane_id === 0) return NoAuthorization();
		$unit_id = (int)$request['UnitId'];

		// Only claimable when the unit currently has no managers.
		if (count($this->_unit_manager_ids($unit_id)) > 0) {
			return NoAuthorization('This unit already has a manager.');
		}
		// Claimant must be an active roster member holding a leadership role
		// (captain / lord / organizer) — plain members cannot self-claim.
		$members = $this->_active_member_roles($unit_id);
		$role = isset($members[$mundane_id]) ? strtolower($members[$mundane_id]) : null;
		if (!in_array($role, array('captain', 'lord', 'organizer'), true)) {
			return NoAuthorization('Only a leader of this unit may claim it.');
		}
		$this->_grant_unit_manager($mundane_id, $unit_id);
		Ork3::$Lib->dangeraudit->audit('Unit::ClaimUnit',
			$request, 'Unit', $unit_id, $mundane_id, ['unit_id' => $unit_id, 'mundane_id' => $mundane_id]);
		return Success();
	}

	public function TransferOwnership($request) {
		logtrace('TransferOwnership', $request);
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if ($mundane_id === 0) return NoAuthorization();
		$mundane   = Ork3::$Lib->player->player_info($request['Token']);
		$unit_id   = (int)$request['UnitId'];
		$target_id = (int)$request['MundaneId'];
		if (!valid_id($target_id)) return InvalidParameter();

		$is_manager = Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $unit_id, AUTH_CREATE);
		$is_officer = $mundane && (
			Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $mundane['KingdomId'], AUTH_EDIT) ||
			Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)
		);
		if (!$is_manager && !$is_officer) return NoAuthorization();

		// Grant the target manager rights (skip if they already hold them).
		if (!in_array($target_id, $this->_unit_manager_ids($unit_id), true)) {
			$this->_grant_unit_manager($target_id, $unit_id);
		}
		// Ensure the new owner is on the active roster (no-op / preserves role if
		// they already are a member).
		$this->add_member_h(array('UnitId' => $unit_id, 'MundaneId' => $target_id, 'Role' => 'member', 'Title' => '', 'Active' => 'Active'));
		// Acting manager steps down. Officers performing the transfer have no unit
		// auth row, so this is a no-op for them.
		if ($mundane_id !== $target_id) {
			$this->_remove_unit_auth_for($mundane_id, $unit_id);
		}
		Ork3::$Lib->dangeraudit->audit('Unit::TransferOwnership',
			$request, 'Unit', $unit_id, $mundane_id,
			['unit_id' => $unit_id, 'from_mundane_id' => $mundane_id, 'to_mundane_id' => $target_id]);
		return Success();
	}

}

?>
