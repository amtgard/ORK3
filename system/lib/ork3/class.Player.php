<?php

class Player extends Ork3 {
	
	public function __construct() {
		parent::__construct();
		$this->mundane = new yapo($this->db, DB_PREFIX . 'mundane');
    	$this->notes = new yapo($this->db, DB_PREFIX . 'mundane_note');
	}
	
    public function GetNotes($request) {
        if (valid_id($request['MundaneId'])) {
            $this->notes->clear();
            $this->notes->mundane_id = $request['MundaneId'];
            $notes = array();
            if ($this->notes->find()) do {
                $notes[] = array(
                        'NoteId' => $this->notes->mundane_note_id,
                        'Note' => $this->notes->note,
                        'Description' => $this->notes->description,
                        'GivenBy' => $this->notes->given_by,
                        'Date' => $this->notes->date,
                        'DateComplete' => $this->notes->date_complete,
                    );
            } while ($this->notes->next());
        }
        return $notes;
    }
    
    public function AddNote($request) {
    	$thePlayer = $this->player_info($request['MundaneId']);
		
    	if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $thePlayer['ParkId'], AUTH_EDIT)
                    || $mundane_id == $request['MundaneId'])) {
            $this->notes->clear();
            $this->notes->mundane_id = $request['MundaneId'];
            $this->notes->note = $request['Note'];
            $this->notes->description = $request['Description'];
            $this->notes->given_by = $request['GivenBy'];
            $this->notes->date = date('Y-m-d', strtotime($request['Date']));
            $this->notes->date_complete = date('Y-m-d', strtotime($request['DateComplete']));
            $this->notes->save();
            return Success($this->notes->mundane_note_id);
		} else {
    	    return NoAuthorization();   
		}
    }
    
    public function RemoveNote($request) {
        logtrace("RemoveNote", $request);
        if (valid_id($request['NotesId'])) {
            $this->notes->clear();
            $this->notes->mundane_note_id = $request['NotesId'];
            $this->notes->mundane_id = $request['MundaneId'];
            if ($this->notes->find()) {
                $thePlayer = $this->player_info($this->notes->mundane_id);
                
                if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
        				&& (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $thePlayer['ParkId'], AUTH_EDIT)
                            || $mundane_id == $request['MundaneId'])) {
                    $this->notes->delete();
                    return Success();
                }
                return NoAuthorization();
            }
            return InvalidParameter('Cannot find Note.');
        }
        return InvalidParameter('A note must be selected.');
    }
    
	public function SetPlayerReconciledCredits($request) {
		
		$thePlayer = $this->player_info($request['MundaneId']);
		
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $thePlayer['ParkId'], AUTH_EDIT)) {
			$reconciled = new yapo($this->db, DB_PREFIX . 'class_reconciliation');
			foreach ($request['Reconcile'] as $k => $values) {
				$reconciled->clear();
				$reconciled->class_id = $values['ClassId'];
				$reconciled->mundane_id = $request['MundaneId'];
				$reconciled->find();
				if ($reconciled->mundane_id == $request['MundaneId'] && $reconciled->class_id == $values['ClassId']) {
					$reconciled->reconciled = $values['Quantity'];
					$reconciled->save();
				} else {
					return InvalidParameter('Problem with request.');
				}
			}
			return Success();
		} else {
			return NoAuthorization();
		}
	}
	
	public function GetPlayer($request) {
		$fetchprivate = true;
		$this->mundane->clear();
		$this->mundane->mundane_id = $request['MundaneId'];
		$response = array();
		if (valid_id($request['MundaneId']) && $this->mundane->find()) {
			if ((($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
					&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $this->mundane->park_id, AUTH_EDIT)) ||
					$mundane_id == $request['MundaneId']) {
				$fetchprivate = false;
			}
			$heraldry = Ork3::$Lib->heraldry->GetHeraldryUrl(array('Type'=>'Player', 'Id'=>$this->mundane->mundane_id));
			$response['Status'] = Success();
			$response['Player'] = array(
					'MundaneId' => $this->mundane->mundane_id,
					'GivenName' => $fetchprivate?"":$this->mundane->given_name,
					'Surname' => $fetchprivate?"":$this->mundane->surname,
					'OtherName' => $fetchprivate?"":$this->mundane->other_name,
					'UserName' => $this->mundane->username,
					'Persona' => $this->mundane->persona,
					'Email' => $fetchprivate?"":$this->mundane->email,
					'ParkId' => $this->mundane->park_id,
					'KingdomId' => $this->mundane->kingdom_id,
					'Restricted' => $this->mundane->restricted,
					'Waivered' => $this->mundane->waivered,
					'Waiver' => $fetchprivate?"":(HTTP_WAIVERS . sprintf('%06d.' . $this->mundane->waiver_ext, $this->mundane->mundane_id)),
					'WaiverExt' => $this->mundane->waiver_ext,
					'DuesThrough' => Ork3::$Lib->treasury->dues_through($this->mundane->mundane_id, $this->mundane->kingdom_id, $this->mundane->park_id, 0),
					'HasHeraldry' => $this->mundane->has_heraldry,
					'Heraldry' => $heraldry['Url'],
					'HasImage' => $this->mundane->has_image,
					'Image' => HTTP_PLAYER_IMAGE . sprintf('%06d.jpg', $this->mundane->mundane_id),
					'CompanyId' => $this->mundane->company_id,
					'PenaltyBox' => $this->mundane->penalty_box,
					'Active' => $this->mundane->active
				);
			$unit = Ork3::$Lib->unit->GetUnit(array( 'UnitId' => $response['Player']['CompanyId'] ));
			if ($unit['Status']['Status'] != 0) {
				$response['Player']['Company'] = "";
			} else {
				$response['Player']['Company'] = $unit['Unit']['Name'];
			}
		} else {
			$response['Status'] = InvalidParameter();
		}
		return $response;
	}
	
	public function AttendanceForPlayer($request) {
		$sql = "select a.*, c.name as class_name, p.name as park_name, k.name as kingdom_name, e.name as event_name, e.park_id as event_park_id, e.kingdom_id as event_kingdom_id, ep.name as event_park_name, ek.name as event_kingdom_name
					from " . DB_PREFIX . "attendance a
						left join " . DB_PREFIX . "park p on a.park_id = p.park_id
						left join " . DB_PREFIX . "kingdom k on a.kingdom_id = k.kingdom_id
						left join " . DB_PREFIX . "class c on a.class_id = c.class_id
						left join " . DB_PREFIX . "event e on a.event_id = e.event_id
							left join " . DB_PREFIX . "park ep on e.park_id = ep.park_id
							left join " . DB_PREFIX . "kingdom ek on e.kingdom_id = ek.kingdom_id
					where a.mundane_id = '" . mysql_real_escape_string($request['MundaneId']) . "'
					order by a.date desc
		";
		$r = $this->db->query($sql);
		$response = array();
		$response['Attendance'] = array();
		if ($r === false) {
			$response['Status'] = InvalidParameter(NULL, 'Problem processing request.');
		} else if ($r->size() > 0) {
			do {
				$response['Attendance'][] = array(
						'AttendanceId' => $r->attenance_id,
						'MundaneId' => $r->mundane_id,
						'ClassId' => $r->class_id,
						'Date' => $r->date,
						'ParkId' => $r->park_id,
						'KingdomId' => $r->kingdom_id,
						'EventId' => $r->event_id,
						'EventCalendarDetailId' => $r->event_calendardetail_id,
						'EventParkId' => $r->event_park_id,
						'EventKingdomId' => $r->event_kingdom_id,
						'EventParkName' => $r->event_park_name,
						'EventKingdomName' => $r->event_kingdom_name,
						'Credits' => $r->credits,
    					'Flavor' => $r->flavor,
						'ClassName' => $r->class_name,
						'ParkName' => $r->park_name,
						'KingdomName' => $r->kingdom_name,
						'EventName' => $r->event_name
					);
			} while ($r->next());
			$response['Status'] = Success();
		} else {
			$response['Status'] = Success();
		}
		return $response;
	}
	
	public function AwardsForPlayer($request) {
		if (valid_id($request['AwardsId'])) {
			$player_award = "or awards.awards_id = '" . mysql_real_escape_string($request['AwardsId']) . "'";
		}
		$sql = "select distinct awards.*, a.*, ka.name as kingdom_awardname, p.name as park_name, k.name as kingdom_name, e.name as event_name, m.persona
					from " . DB_PREFIX . "awards awards
						left join " . DB_PREFIX . "kingdomaward ka on awards.kingdomaward_id = ka.kingdomaward_id
							left join " . DB_PREFIX . "award a on a.award_id = ka.award_id 
						left join " . DB_PREFIX . "park p on p.park_id = awards.at_park_id 
						left join " . DB_PREFIX . "kingdom k on k.kingdom_id = awards.at_kingdom_id 
						left join " . DB_PREFIX . "event e on e.event_id = awards.at_event_id 
						left join " . DB_PREFIX . "mundane m on m.mundane_id = awards.given_by_id 
					where awards.mundane_id = '" . mysql_real_escape_string($request['MundaneId']) . "' $player_award
					order by
						a.is_ladder, a.is_title, a.title_class, a.name, awards.rank, awards.date";

		$r = $this->db->query($sql);
		$response = array();
		$response['Awards'] = array();
		if ($r === false) {
			$response['Status'] = InvalidParameter(NULL, 'Problem processing request.');
		} else if ($r->size() > 0) {
			do {
				$response['Awards'][] = array(
						'AwardsId' => $r->awards_id,
						'AwardId' => $r->award_id,
						'MundaneId' => $r->mundane_id,
						'Rank' => $r->rank,
						'Date' => $r->date,
						'GivenById' => $r->given_by_id,
						'Note' => $r->note,
						'ParkId' => $r->park_id,
						'KingdomId' => $r->kingdom_id,
						'EventId' => $r->event_id,
						'Name' => $r->name,
						'KingdomAwardName' => $r->kingdom_awardname,
						'CustomAwardName' => $r->custom_name,
						'IsLadder' => $r->is_ladder,
						'IsTitle' => $r->is_title,
						'TitleClass' => $r->title_class,
						'ParkName' => $r->park_name,
						'KingdomName' => $r->kingdom_name,
						'EventName' => $r->event_name,
						'GivenBy' => $r->persona,
					);
			} while ($r->next());
			$response['Status'] = Success();
		} else {
			$response['Status'] = Success();
		}
		return $response;
	}
	
	public function GetPlayerClasses($request) {
		/*
			This does not prevent double-counting for someone who signs as different classes in the same week
			
			-- It does now, which is going to piss some people off
			-- Class double-counting can be added back in by changing 
					"group by year(ssa.date), week(ssa.date, 6)) a on a.class_id = c.class_id"
					to
					group by ssa.class_id, year(ssa.date), week(ssa.date, 6)) a on a.class_id = c.class_id
			

			-- 2015-06-22
				Now it really does prevent double-counting, by using a subquery to gather the "first" entry on each date rather
				than relying on the innodb code to randomly group by date into whatever class (over-counting some classes, under-counting others)
		ONE PER WEEK
			
		$sql = "select c.class_id, c.name as class_name, count(a.week) as weeks, sum(a.attendances) as attendances, sum(a.credits) as credits, cr.class_reconciliation_id, cr.reconciled
					from " . DB_PREFIX . "class c
						left join 
							(select ssa.class_id, count(ssa.attendance_id) as attendances, max(ssa.credits) as credits, week(ssa.date, 6) as week 
								from " . DB_PREFIX . "attendance ssa
								where
									ssa.mundane_id = $request[MundaneId]
								group by year(ssa.date), week(ssa.date, 6)) a on a.class_id = c.class_id
						left join " . DB_PREFIX . "class_reconciliation cr on cr.class_id = c.class_id and cr.mundane_id = $request[MundaneId]
					group by c.class_id
				";
		*/
		$sql = "select c.class_id, c.active, c.name as class_name, count(a.week) as weeks, sum(a.attendances) as attendances, sum(a.credits) as credits, cr.class_reconciliation_id, cr.reconciled
					from " . DB_PREFIX . "class c
						left join 
							(select ssa.class_id, count(ssa.attendance_id) as attendances, sum(ssa.credits) as credits, week(ssa.date, 6) as week 
								from 
								(select min(killdupe.attendance_id) as attendance_id from " . DB_PREFIX . "attendance killdupe where killdupe.mundane_id = '" . mysql_real_escape_string($request['MundaneId']) . "' group by killdupe.date) kd
								left join " . DB_PREFIX . "attendance ssa on ssa.attendance_id = kd.attendance_id
								where
									ssa.mundane_id = '" . mysql_real_escape_string($request['MundaneId']) . "'
								group by ssa.class_id, ssa.date) a on a.class_id = c.class_id
						left join " . DB_PREFIX . "class_reconciliation cr on cr.class_id = c.class_id and cr.mundane_id = '" . mysql_real_escape_string($request['MundaneId']) . "'
                    where c.active = 1
					group by c.class_id
				";
		//echo $sql;
		$r = $this->db->query($sql);
		$response = array();
		$response['Classes'] = array();
		if ($r === false) {
			$response['Status'] = InvalidParameter();
		} else if ($r->size() > 0) {
			do {
				$response['Classes'][$r->class_id] = array(
						'ClassReconciliationId' => $r->class_reconciliation_id,
						'Reconciled' => $r->reconciled,
						'ClassId' => $r->class_id,
						'ClassName' => $r->class_name,
						'Weeks' => $r->weeks,
						'Attendances' => $r->attendances,
						'Credits' => $r->credits
					);
			} while ($r->next());
			$response['Status'] = Success();
		} else {
			$response['Status'] = Success();
		}
		return $response;
	}
	
    public function unique_username($username, $calls = 0) {
		if ($calls == 0)
			return false;
        $srcname = $username;
        $found = false;
        do {
    		$this->mundane->clear();
    		$this->mundane->username = $username;
    		if ($this->mundane->find()) {
                echo " username exists ... ";
                $username = $srcname . '-' . substr(md5(microtime()), 0, 5);
                echo " trying altered name instead ... ";
    		} else {
        	    $found = true;   
    		}
			$calls--;
        } while (!$found && $calls > 0);
        echo " username is available ... ";
        return $username;
    }
    
	public function CreatePlayer($request) {
		if (strlen($request['UserName']) < 4)
			return InvalidParameter('UserNames must be at least 4 characters long.');
	
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $request['ParkId'], AUTH_CREATE)) {
			$park = new yapo($this->db, DB_PREFIX . 'park');
			$park->clear();
			$park->park_id = $request['ParkId'];
			if ($park->find()) {
				logtrace('Player->CreatePlayer', $request);
				$username = $this->unique_username(trim($request['UserName']), 4);
				if ($username === false) {
					return InvalidParameter('No UserName could be generated for this player.  Please try again.');
				}
                $request['UserName'] = $username;
				$this->mundane->clear();
				$this->mundane->given_name = $request['GivenName'];
				$this->mundane->surname = $request['Surname'];
				$this->mundane->other_name = $request['OtherName'];
				$this->mundane->username = trim($request['UserName']);
				$this->mundane->persona = $request['Persona'];
				$this->mundane->email = $request['Email'];
//				$this->mundane->password = md5($request['Password']);
				$this->mundane->park_id = $request['ParkId'];
				$this->mundane->kingdom_id = $park->kingdom_id;
				$this->mundane->modified = date('Y-m-d H:i:s', time());
				$this->mundane->restricted = $request['Restricted']?1:0;
				$this->mundane->waivered = $request['Waivered']?1:0;
				$this->mundane->has_image = $request['HasImage']?1:0;
				$this->mundane->penalty_box = 0;
				$this->mundane->active = $request['IsActive'];
				$this->mundane->password_expires = date("Y-m-d H:i:s", time() + 60 * 60 * 24 * 365);
				$this->mundane->password_salt = md5(rand().microtime());
				$this->mundane->save();
				
				Authorization::SaltPassword($this->mundane->password_salt, strtoupper(trim($this->mundane->username)) . trim($request['Password']), $this->mundane->password_expires);

				if ($request['Waivered'] && strlen($request['Waiver']) > 0 && strlen($request['Waiver']) < 465000 && Common::supported_mime_types($request['WaiverMimeType']) && !Common::is_pdf_mime_type($request['WaiverMimeType'])) {
					$waiver = @imagecreatefromstring(base64_decode($request['Waiver'])); 
					if($waiver !== false) 
					{ 
						imagejpeg($waiver, DIR_WAIVERS.(sprintf("%06d",$this->mundane->mundane_id)).'.jpg'); 
						$this->mundane->waivered = 1;
						$this->mundane->waiver_ext = 'jpg';
					} else {
						$this->mundane->saivered = 0;
					}
				} else if ($request['Waivered'] && strlen($request['Waiver']) > 0 && strlen($request['Waiver']) < 465000 && Common::is_pdf_mime_type($request['WaiverMimeType'])) {
					$waiver = @base64_decode($request['Waiver']);
					if ($waiver !== false) {
						file_put_contents(DIR_WAIVERS.(sprintf("%06d",$this->mundane->mundane_id)).'.pdf', $waiver, LOCK_EX);
						$this->mundane->waivered = 1;
						$this->mundane->waiver_ext = 'pdf';
					}
				} else {
					$this->mundane->waivered = 0;
				}
				if ($request['HasImage'] && strlen($request['Image']) > 0 && strlen($request['Image']) < 465000 && Common::supported_mime_types($request['ImageMimeType']) && !Common::is_pdf_mime_type($request['ImageMimeType'])) {
					$playerimage = @imagecreatefromstring(base64_decode($request['Image'])); 
					if($playerimage !== false) 
					{ 
						imagejpeg($playerimage, DIR_PLAYER_IMAGE.(sprintf("%06d",$this->mundane->mundane_id)).'.jpg'); 
						$this->mundane->has_image = 1;
					} else {
						$this->mundane->has_image = 0;
					}
				} else {
					$this->mundane->has_image = 0;
				}
				$this->mundane->save();
				if (strlen($request['Heraldry'])) {
					$request['MundaneId'] = $this->mundane->mundane_id;
					Ork3::$Lib->heraldry->SetPlayerHeraldry($request);
				}
				return Success($this->mundane->mundane_id);
			} else {
				return InvalidParameter();
			}
		} else {
			return NoAuthorization();
		}
	}
	
	public function player_info($id) {
	    if (strlen($id) == 32)
	        $id = Ork3::$Lib->authorization->IsAuthorized($id);
		$this->mundane->clear();
		$this->mundane->mundane_id = $id;
		if (!$this->mundane->find()) {
			return false;
		} else {
			return array ( 
				'id' => $this->mundane->mundane_id, 'park_id' => $this->mundane->park_id, 'kingdom_id' => $this->mundane->kingdom_id,
				'MundaneId' => $this->mundane->mundane_id, 'ParkId' => $this->mundane->park_id, 'KingdomId' => $this->mundane->kingdom_id,
				'Surname' => $this->mundane->surname, 'GivenName' => $this->mundane->given_name
				);
		}
	}
	
	public function MergePlayer($request) {
	
		if ((($fromMundane = $this->player_info($request['FromMundaneId'])) === false) 
				|| (($toMundane = $this->player_info($request['ToMundaneId'])) === false)
				|| $request['FromMundaneId'] == $request['ToMundaneId']) {
			return InvalidParameter();
		}
		
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0) {
			return NoAuthorization();
		}
	
		if (
				(($toMundane['KingdomId'] != $fromMundane['KingdomId']) 
					&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT)) 
				|| (($toMundane['ParkId'] != $fromMundane['ParkId'] && $toMundane['KingdomId'] == $fromMundane['KingdomId']) 
					&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $toMundane['KingdomId'], AUTH_EDIT))
				|| (($toMundane['ParkId'] == $fromMundane['ParkId']) 
					&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $toMundane['ParkId'], AUTH_EDIT))) {
			$sql = "DELETE FROM 
						" . DB_PREFIX . "attendance 
					WHERE 
						mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "' 
						AND date in (SELECT date FROM " . DB_PREFIX . "attendance
										WHERE 
											mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "'";
			$this->db->query($sql);
			$sql = "update " . DB_PREFIX ."attendance set mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' where mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
			$this->db->query($sql);
			$sql = "update " . DB_PREFIX ."authorization set mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' where mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
			$this->db->query($sql);
			$sql = "update " . DB_PREFIX ."event set mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' where mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
			$this->db->query($sql);
			$sql = "delete from " . DB_PREFIX ."mundane where mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
			$this->db->query($sql);
			$sql = "update " . DB_PREFIX ."officer set mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' where mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
			$this->db->query($sql);
			$sql = "update " . DB_PREFIX ."awards set mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' where mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
			$this->db->query($sql);
			$sql = "update " . DB_PREFIX ."awards set given_by_id = '" . mysql_real_escape_string($toMundane['id']) . "' where given_by_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
			$this->db->query($sql);
			$sql = "update " . DB_PREFIX ."split set src_mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' where src_mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
			$this->db->query($sql);
			$sql = "update " . DB_PREFIX ."transaction set recorded_by = '" . mysql_real_escape_string($toMundane['id']) . "' where recorded_by = '" . mysql_real_escape_string($fromMundane['id']) . "'";
			$this->db->query($sql);
			$sql = "update " . DB_PREFIX ."unit set owner_id = '" . mysql_real_escape_string($toMundane['id']) . "' where owner_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
			$this->db->query($sql);
			$sql = "update " . DB_PREFIX ."unit_mundane set mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' where mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
			$this->db->query($sql);
    	$sql = "update " . DB_PREFIX ."mundane_note set mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' where mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
			$this->db->query($sql);
			return Success();
		} else {
			return NoAuthorization();
		}
	
	}
	
	public function MovePlayer($request) {
		$this->mundane->clear();
		$this->mundane->mundane_id = $request['MundaneId'];
		$park = new yapo($this->db, DB_PREFIX . 'park');
		$park->clear();
		$park->park_id = $request['ParkId'];
		if (!$this->mundane->find() || !$park->find()) {
			return InvalidParameter();
		}
	
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $park->park_id, AUTH_EDIT)		// New Kingdom
					|| Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $this->mundane->park_id, AUTH_EDIT))) { // Current Kingdom
			$this->mundane->park_id = $request['ParkId'];
			$this->mundane->kingdom_id = $park->kingdom_id;
			$this->mundane->waivered = $request['Waivered']?1:0;
			$this->mundane->save();
			logtrace('MovePlayer(): Success', $request);
			return Success();
		} else {
			return NoAuthorization();
		}
	}

	public function UpdatePlayer($request) {
		logtrace("UpdatePlayer()", $request);
		$mundane = $this->player_info($request['MundaneId']);
		$requester_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		
		if (trimlen($request['UserName']) > 0) {
			$this->mundane->clear();
			$this->mundane->username = $request['UserName'];
			if ($this->mundane->find()) {
				if ($this->mundane->mundane_id != $request['MundaneId']) {
					return InvalidParameter('This username is already in use.');
				}
			}
		}
        
		$notices = '';
		if (valid_id($requester_id) && Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_PARK, $mundane['ParkId'], AUTH_CREATE)
			|| $requester_id == $request['MundaneId']) {
                
            if (Ork3::$Lib->authorization->HasAuthority($request['MundaneId'], AUTH_ADMIN, 0, AUTH_EDIT)
                && !Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
                die("You have attempted an illegal operation.  Your attempt has been logged.");
            }
        
			$this->mundane->clear();
			$this->mundane->mundane_id = $request['MundaneId'];
			if ($this->mundane->find()) {
				logtrace('Updating player', $request);
				$this->mundane->modified = date('Y-m-d H:i:s', time());
				$this->mundane->given_name = is_null($request['GivenName'])?$this->mundane->given_name:$request['GivenName'];
				$this->mundane->surname = is_null($request['Surname'])?$this->mundane->surname:$request['Surname'];
				$this->mundane->other_name = is_null($request['OtherName'])?$this->mundane->other_name:$request['OtherName'];
				$this->mundane->username = is_null($request['UserName'])?$this->mundane->username:$request['UserName'];
				$this->mundane->persona = is_null($request['Persona'])?$this->mundane->persona:$request['Persona'];
				$this->mundane->save();
				$this->set_waiver($request);
				$this->mundane->save();
				$this->set_image($request);
				$this->mundane->save();
				logtrace("Mundane DB 1", $this->mundane);
				$this->mundane->email = is_null($request['Email'])?$this->mundane->email:$request['Email'];
				if (trimlen($request['Password']) > 0) {
					logtrace("Update password", $request['Password']);
					$this->mundane->password_expires = date("Y-m-d H:i:s", time() + 60 * 60 * 24 * 365 * 2);
					$salt = md5(rand().microtime().$this->mundane->email);
					$this->mundane->password_salt = $salt;
					
					Authorization::SaltPassword($salt, strtoupper(trim($this->mundane->username)) . trim($request['Password']), $this->mundane->password_expires);
				} else {
					logtrace("No password update", $request['Password']);
				}
				logtrace("Mundane DB 2", $this->mundane);
				$this->mundane->restricted = is_null($request['Restricted'])?$this->mundane->restricted:$request['Restricted']?1:0;
				
				if (Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_PARK, $mundane['ParkId'], AUTH_CREATE)) {
    				$this->mundane->active = is_null($request['Active'])?$this->mundane->restricted:$request['Active']?1:0;
				}
				if (strlen($request['Heraldry'])) {
					Ork3::$Lib->heraldry->SetPlayerHeraldry($request);
				}
				logtrace("Player Updated", array($request, $this->mundane->lastSql()));
   				$this->mundane->save();
				return Success($notices);
			} else {
				logtrace('No Player found.', null);
				return InvalidParameter();
			}
		} else {
			logtrace('No Authorization found.', null);
			return NoAuthorization();
		}
	}
	
	public function SetHeraldry($request) {
	    logtrace("SetHeraldry", $request);
		$mundane = $this->player_info($request['MundaneId']);
		$requester_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
	
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)
			    || $requester_id == $request['MundaneId']) {
			$this->mundane->clear();
			$this->mundane->mundane_id = $request['MundaneId'];
			if ($this->mundane->find()) {
				return Ork3::$Lib->heraldry->SetPlayerHeraldry($request);
			} else {
				return InvalidParameter();
			}
		} else {
			return NoAuthorization();
		}
	}

    private function media_fetch($prefix, $request) {
        logtrace("media_fetch", $request);
        $url = $prefix . 'Url';
        $media = $prefix;
        $mime = $prefix . 'MimeType';
    	if (strlen($request[$url]) > 0 && Common::url_exists($request[$url])) {
			$mime_type = Common::exif_to_mime(@exif_imagetype($request[$url]), $request[$url]);
			if (Common::supported_mime_types($mime_type) && Ork3::$Lib->heraldry->url_file_size($request[$url]) < 465000) {
				$request[$media] = base64_encode(file_get_contents($request[$url]));
				$request[$mime] = $mime_type;
			}
		}
        return $request;
    }

	public function set_image($request) {
        logtrace("set_image", $request);
        $request = $this->media_fetch('Image', $request);
		if (strlen($request['Image']) > 0 && strlen($request['Image']) < 465000 && Common::supported_mime_types($request['ImageMimeType']) && !Common::is_pdf_mime_type($request['ImageMimeType'])) {
			$playerimage = imagecreatefromstring(base64_decode($request['Image'])); 
			if($playerimage !== false) 
			{
    			if (file_exists( DIR_PLAYER_IMAGE.(sprintf("%06d",$this->mundane->mundane_id)).'.jpg' )) 
                    unlink( DIR_PLAYER_IMAGE.(sprintf("%06d",$this->mundane->mundane_id)).'.jpg' );
				imagejpeg($playerimage, DIR_PLAYER_IMAGE.(sprintf("%06d",$this->mundane->mundane_id)).'.jpg'); 
				$this->mundane->has_image = 1;
			} else {
				$notices .= "Image could not be decoded.";
			}
		} else {
			$notices .= 'Images must be jpeg, gifs, or pngs, and may be no larger than 340KB.<br />';
		}
		logtrace("set_image() complete", array($request, $notices));
		return Success($notices);
	}
	
	public function set_waiver($request) {
    	logtrace("set_waiver()", $request);
		$mundane = $this->player_info($request['MundaneId']);
		
		$notices = '';
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
    			&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {
            $request = $this->media_fetch('Waiver', $request);
    		if ($request['Waivered'] && strlen($request['Waiver']) > 0 && strlen($request['Waiver']) < 465000 && Common::supported_mime_types($request['WaiverMimeType']) && !Common::is_pdf_mime_type($request['WaiverMimeType'])) {
				logtrace("set_waiver() - image", $request);
    			$waiver = @imagecreatefromstring(base64_decode($request['Waiver'])); 
    			if($waiver !== false) 
    			{ 
            		if (file_exists( DIR_WAIVERS.(sprintf("%06d",$request['MundaneId'])).'.jpg' )) 
                        unlink( DIR_WAIVERS.(sprintf("%06d",$request['MundaneId'])).'.jpg' );
    				imagejpeg($waiver, DIR_WAIVERS.(sprintf("%06d",$request['MundaneId'])).'.jpg'); 
    				$this->mundane->waivered = 1;
    				$this->mundane->waiver_ext = 'jpg';
    			} else {
    				$notices .= 'There was an error uploading or decoding your image.<br />';
					return InvalidParameter($notices);
    			}
    		} else if ($request['Waivered'] && strlen($request['Waiver']) > 0 && strlen($request['Waiver']) < 465000 && Common::is_pdf_mime_type($request['WaiverMimeType'])) {
				logtrace("set_waiver() - pdf", $request);
    			$waiver = @base64_decode($request['Waiver']);
    			if ($waiver !== false) {
                	if (file_exists( DIR_WAIVERS.(sprintf("%06d",$request['MundaneId'])).'.pdf' )) 
                        unlink( DIR_WAIVERS.(sprintf("%06d",$request['MundaneId'])).'.pdf' );
    				file_put_contents(DIR_WAIVERS.(sprintf("%06d",$this->mundane->mundane_id)).'.pdf', $waiver, LOCK_EX);
    				$this->mundane->waivered = 1;
    				$this->mundane->waiver_ext = 'pdf';
    			} else {
    				$notices .= 'There was an error decoding your image.<br />';
					return InvalidParameter($notices);
    			}
    		} else if ($request['Waivered'] === true) {
				logtrace("set_waiver() - force waivered", $request);
                $this->mundane->waivered = 1;
    			$notices .= 'Waivers must be jpeg, gifs, pngs, or pdfs, and may be no larger than 340KB.<br />';
    		} else {
				logtrace("set_waiver() - force waivered (false)", $request);
                $this->mundane->waivered = 0;
    		}
		} else {
    	    logtrace("set_waiver no auth;", 0);   
			return NoAuthorization($notices);
		}
		return Success($notices);
	}
	
	public function SetRestriction($request) {
		$mundane = $this->player_info($request['MundaneId']);
	
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {
			$this->mundane->clear();
			$this->mundane->mundane_id = $request['MundaneId'];
			if ($this->mundane->find()) {
				$this->mundane->restricted = $request['Restricted']?1:0;
				$this->mundane->save();
				return Success();
			} else {
				return InvalidParameter();
			}
		} else {
			return NoAuthorization();
		}
	}
	
	public function SetImage($request) {
	    logtrace("SetImage", $request);
		$mundane = $this->player_info($request['MundaneId']);
		$requester_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
	
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)
			    || $requester_id == $request['MundaneId']) {
			$this->mundane->clear();
			$this->mundane->mundane_id = $request['MundaneId'];
			if ($this->mundane->find()) {
				$r = $this->set_image($request);
				$this->mundane->save();
				return $r;
			} else {
				return InvalidParameter();
			}
		} else {
			return NoAuthorization();
		}
	}
	
	public function SetWaiver($request) {
	    logtrace("SetWaiver", $request);
		$mundane = $this->player_info($request['MundaneId']);
		$requester_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
	
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {
			$this->mundane->clear();
			$this->mundane->mundane_id = $request['MundaneId'];
			if ($this->mundane->find()) {
				$r = $this->set_waiver($request);
				$this->mundane->save();
				return $r;
			} else {
				return InvalidParameter();
			}
		} else {
			return NoAuthorization();
		}
	}
	
	public function SetBan($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
			$this->mundane->clear();
			$this->mundane->mundane_id = $request['MundaneId'];
			if ($this->mundane->find()) {
				$this->mundane->penalty_box = $request['Banned']?1:0;
				$this->mundane->save();
				return Success();
			} else {
				return InvalidParameter();
			}
		} else {
			return NoAuthorization();
		}
	}
	
	public function AddAward($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0)
			return NoAuthorization();

        logtrace("AddAward()", $request);

		$this->mundane->clear();
		$this->mundane->mundane_id = $request['RecipientId'];
		if (!$this->mundane->find()) {
			return InvalidParameter();
		}
		$recipient = array ( 'KingdomId' => $this->mundane->kingdom_id, 'ParkId' => $this->mundane->park_id );
        
        if (valid_id($request['AwardId'])) {
            $request['KingdomAwardId'] = Ork3::$Lib->award->LookupAward(array('KingdomId' => $recipient['KingdomId'], 'AwardId' => $request['AwardId']));
        } else if (valid_id($request['KingdomAwardId'])) {
            list($kingdom_id, $request['AwardId']) = Ork3::$Lib->award->LookupKingdomAward(array('KingdomAwardId' => $recipient['KingdomAwardId']));
        } else {
            return InvalidParameter();
        }
        
		if (valid_id($mundane_id)
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $recipient['ParkId'], AUTH_EDIT)) {
			if (valid_id($request['ParkId'])) {
				$Park = new Park();
				$park_info = $Park->GetParkShortInfo(array( 'ParkId' => $given_by['Player']['ParkId'] ));
				if ($park_info['Status']['Status'] != 0)
					return InvalidParameter();
			}
            if (valid_id($request['GivenById']))
    			$given_by = $this->GetPlayer(array('MundaneId' => $request['GivenById']));
                
            logtrace("GivenBy", $given_by);
			$awards = new yapo($this->db, DB_PREFIX . 'awards');
			$awards->clear();
			$awards->kingdomaward_id = $request['KingdomAwardId'];
    		$awards->award_id = $request['AwardId'];
			$awards->custom_name = $request['CustomName'];
			$awards->mundane_id = $request['RecipientId'];
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
			return Success('');
		} else {
			return NoAuthorization();
		}
	}
	
	public function UpdateAward($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		$awards = new yapo($this->db, DB_PREFIX . 'awards');
		$awards->clear();
		$awards->awards_id = $request['AwardsId'];
		if (valid_id($request['AwardsId']) && $awards->find()) {
			$mundane = $this->player_info($awards->mundane_id);
			if (valid_id($mundane_id)
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {
				if (valid_id($request['ParkId'])) {
					$Park = new Park();
					$info = $Park->GetParkShortInfo(array( 'ParkId' => $request['ParkId'] ));
					if ($info['Status']['Status'] != 0)
						return InvalidParameter();
				}
				$awards->rank = $request['Rank'];
				$awards->date = $request['Date'];
				$awards->given_by_id = $request['GivenById'];
				$awards->note = $request['Note'];
				// If no event, then go Park!
				$awards->park_id = !valid_id($request['EventId'])?$request['ParkId']:0;
				// If no event and valid parkid, go Park! Otherwise, go Kingdom.  Unless it's an event.  Then go ... ZERO!
				$awards->kingdom_id = !valid_id($request['EventId'])?(valid_id($request['ParkId'])?$info['ParkInfo']['KingdomId']:$request['KingdomId']):0;
				// Events are awesome.
				$awards->event_id = valid_id($request['EventId'])?$request['EventId']:0;
				$awards->save();
				return Success($awards->awards_id);
			} else {
				return InvalidParamter();
			}
		} else {
			return NoAuthorization();
		}
	}
	
	public function RemoveAward($request) {
		logtrace("RemoveAward()", $request);
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		$awards = new yapo($this->db, DB_PREFIX . 'awards');
		$awards->clear();
		$awards->awards_id = $request['AwardsId'];
		if (valid_id($request['AwardsId']) && $awards->find()) {
			$mundane = $this->player_info($awards->mundane_id);
			if (valid_id($mundane_id)
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {
					$awards->delete();
			} else {
				return NoAuthorization();
			}
		} else {
			return InvalidParameter();
		}
	}
}

?>