<?php

class Player extends Ork3 {

	public function __construct() {
		parent::__construct();
		$this->mundane = new yapo($this->db, DB_PREFIX . 'mundane');
		$this->notes = new yapo($this->db, DB_PREFIX . 'mundane_note');
		$this->dues = new yapo($this->db, DB_PREFIX . 'dues');
		$this->pronoun = new yapo($this->db, DB_PREFIX . 'pronoun');
		$this->load_model('Kingdom');
		$this->load_model('Park');
		$this->load_model('Pronoun');
	}

    public function AddOneShotFaceImage($request) {
      $mundane = $this->player_info($request['MundaneId']);
		  $requester_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		  if (valid_id($requester_id) && Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_PARK, $mundane['ParkId'], AUTH_CREATE) || $requester_id == $request['MundaneId']) {
        //try {
        $json_call = array(
            "jsonrpc" => "2.0",
            "method" => "store",
            "params" => array(
                BEHOLD_KEY,
                $request['MundaneId'],
                $request['Base64FaceImage']
              ),
            "id" => 1
          );
        $ch = curl_init('https://behold.amtgard.com/');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json_call));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-type: application/json']);
        $response = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($response);
        return $result->result;
      } else {
        logtrace('No Authorization found.', null);
        return NoAuthorization();
      }

    }
  
    public function LookupByFaces($request) {
      $json_call = array(
          "jsonrpc" => "2.0",
          "method" => "lookup",
          "params" => array(
              $request['Base64Selfie']
            ),
          "id" => 1
        );
      $ch = curl_init('https://behold.amtgard.com/');
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_TIMEOUT, 20);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json_call));
      curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-type: application/json']);
      $response = curl_exec($ch);
      curl_close($ch);
      $result = json_decode($response);
      
      $facedetails = array();
      
      $found = array();
      
      foreach ($result->result->hits as $k => $face) {
        if (!is_null($face)) {
          $found[] =  $face[0];
        }
      }
      
      $playersfound = $this->hydrated_players($found);
      
      foreach ($result->result->hits as $k => $face) {
        $player = is_null($face) ? array ('id' => 0) : $playersfound[$face[0]];
        $facedetails[] = [ $player, $result->result->locations[$k] ];
      }
      
      return $facedetails;
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
				$note = new stdClass();

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

										$note->mundane_note_id = $this->notes->mundane_note_id;
										$note->mundane_id = $this->notes->mundane_id;
										$note->note = $this->notes->note;
										$note->description = $this->notes->description;
										$note->given_by = $this->notes->given_by;
										$note->date = $this->notes->date;
										$note->date_complete = $this->notes->date_complete;

										Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $note->mundane_id, $note);

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
				if (!$reconciled->find()) {
					$reconciled->clear();
					$reconciled->class_id = $values['ClassId'];
					$reconciled->mundane_id = $request['MundaneId'];						
				};
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
			// Moving Dues response here to stuff the old DuesThrough response until mORK updates go out
			$dues = $this->GetDues(['MundaneId' => $this->mundane->mundane_id, 'ExcludeRevoked' => 1, 'Active' => 1]);
			// Sort the dues by date and use the furthest out DuesUntil
			usort($dues, function($a, $b) {
				return strtotime($a['DuesUntil']) - strtotime($b['DuesUntil']);
			});
			$old_dues_through = (!empty($dues)) ? $dues[sizeof($dues)-1]['DuesUntil']: '';
			$this->pronoun->clear();
			$this->pronoun->pronoun_id = $this->mundane->pronoun_id;
			$this->pronoun->find();
			$subject = $this->pronoun->subject;
			$pronoun_custom = $this->mundane->pronoun_custom;
			$pronountext = isset($subject) ? $this->pronoun->subject . '[' . $this->pronoun->object . ']' : '';
			$pronouncustomArr = (isset($pronoun_custom) && json_decode($this->mundane->pronoun_custom)) ? $this->Pronoun->fetch_custom_pronoun_display($this->mundane->pronoun_custom) : false;
			//$pronouncustomtext = json_encode($pronouncustomArr);
			$pronouncustomtext = (isset($pronouncustomArr) && $pronouncustomArr) ? implode('/', $pronouncustomArr['subjective']) . ' [' . implode('/', $pronouncustomArr['objective']) . ' ' . implode('/', $pronouncustomArr['possessive']) . ' ' . implode('/', $pronouncustomArr['possessivepronoun']) . ' ' . implode('/', $pronouncustomArr['reflexive']) . ']' : '';

			$response['Player'] = array(
					'MundaneId' => $this->mundane->mundane_id,
					'GivenName' => $fetchprivate?"":$this->mundane->given_name,
					'Surname' => $fetchprivate?"":$this->mundane->surname,
					'OtherName' => $fetchprivate?"":$this->mundane->other_name,
					'UserName' => $this->mundane->username,
					'PronounId' => $this->mundane->pronoun_id,
					'PronounCustom' => $this->mundane->pronoun_custom,
					'PronounText' => $pronountext,
					'PronounCustomText' => $pronouncustomtext,
					'Persona' => $this->mundane->persona,
					'Suspended' => $this->mundane->suspended,
					'SuspendedAt' => $this->mundane->suspended_at,
					'SuspendedUntil' => $this->mundane->suspended_until,
					'Suspension' => $this->mundane->suspension,
					'Email' => $fetchprivate?"":$this->mundane->email,
					'ParkId' => $this->mundane->park_id,
					'KingdomId' => $this->mundane->kingdom_id,
					'Restricted' => $this->mundane->restricted,
					'Waivered' => $this->mundane->waivered,
					'Waiver' => $fetchprivate?"":(HTTP_WAIVERS . sprintf('%06d.' . $this->mundane->waiver_ext, $this->mundane->mundane_id)),
					'WaiverExt' => $this->mundane->waiver_ext,
					'ReeveQualified' => $this->mundane->reeve_qualified,
					'ReeveQualifiedUntil' => $this->mundane->reeve_qualified_until,
					'CorporaQualified' => $this->mundane->corpora_qualified,
					'CorporaQualifiedUntil' => $this->mundane->corpora_qualified_until,
					'DuesThrough' => $old_dues_through, //Ork3::$Lib->treasury->dues_through($this->mundane->mundane_id, $this->mundane->kingdom_id, $this->mundane->park_id, 0),
					'HasHeraldry' => $this->mundane->has_heraldry,
					'Heraldry' => $heraldry['Url'] . '?' . strtotime($this->mundane->modified),
					'HasImage' => $this->mundane->has_image,
					'Image' => $this->resolve_player_image_url($this->mundane->mundane_id, $this->mundane->modified),
					'PenaltyBox' => $this->mundane->penalty_box,
					'Active' => $this->mundane->active,
					'PasswordExpires' => $this->mundane->password_expires,
					//'ParkMemberSince' => date('d/m/Y', strtotime($this->mundane->park_member_since))
					'ParkMemberSince' => $this->mundane->park_member_since,
					'DuesPaidList' => $dues
				);
			$unit = Ork3::$Lib->report->UnitSummary(array( 'MundaneId' => $this->mundane->mundane_id, 'IncludeCompanies' => 1, 'ActiveOnly' => 1 ));
			if ($unit['Status']['Status'] != 0) {
				$response['Player']['Company'] = "";
			} else {
				$response['Player']['Company'] = $unit['Units'];
			}
		} else {
			$response['Status'] = InvalidParameter();
		}
		return $response;
	}

	public function AttendanceForPlayer($request) {
		$sql = "select 
              a.*, c.name as class_name, 
                ifnull(p.name, ep.name) as park_name, 
                ifnull(k.name, ek.name) as kingdom_name, 
                e.name as event_name, e.park_id as event_park_id, e.kingdom_id as event_kingdom_id, 
                ep.name as event_park_name, ek.name as event_kingdom_name
					from " . DB_PREFIX . "attendance a
						left join " . DB_PREFIX . "park p on a.park_id = p.park_id
						left join " . DB_PREFIX . "kingdom k on a.kingdom_id = k.kingdom_id
						left join " . DB_PREFIX . "class c on a.class_id = c.class_id
						left join " . DB_PREFIX . "event e on a.event_id = e.event_id
							left join " . DB_PREFIX . "park ep on e.park_id = ep.park_id
							left join " . DB_PREFIX . "kingdom ek on e.kingdom_id = ek.kingdom_id
          where a.mundane_id = '" . mysql_real_escape_string($request['MundaneId']) . "'";
    $date_start = $request['date_start'];
    if (!is_null($date_start) && strtotime($date_start)) {
      $when = date("Y-m-d", strtotime($date_start));
      $sql .= " and a.date >= '$when' ";
    }
	if ($request['order'] && ($request['order'] == 'asc' || $request['order'] == 'desc')) {
		$order = $request['order'];
	} else {
		$order = 'desc';
	}
    $sql .= " order by a.date " . $order;
	$limit = $request['limit'];
		$r = $this->db->query($sql);
		$response = array();
		$response['Attendance'] = array();
		if ($r === false) {
			$response['Status'] = InvalidParameter(NULL, 'Problem processing request.');
		} else if ($r->size() > 0) {
			while ($r->next()) {
				$response['Attendance'][] = array(
						'AttendanceId' => $r->attendance_id,
						'EnteredById' => $r->by_whom_id,
						'EnteredAt' => $r->entered_at,
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
				if (is_numeric($limit)) {
					$limit--;
					if ($limit == 0) break;
				}
			}
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
		$sql = "select distinct awards.*, a.*, ka.name as kingdom_awardname, ka.kingdom_id as kingdom_award_kingdom_id, p.name as park_name, k.name as kingdom_name, e.name as event_name, m.persona, bwm.persona as entered_by_persona, bwm.mundane_id as entered_by_id
					from " . DB_PREFIX . "awards awards
						left join " . DB_PREFIX . "kingdomaward ka on awards.kingdomaward_id = ka.kingdomaward_id
							left join " . DB_PREFIX . "award a on a.award_id = ka.award_id
						left join " . DB_PREFIX . "park p on p.park_id = awards.at_park_id
						left join " . DB_PREFIX . "kingdom k on k.kingdom_id = awards.at_kingdom_id
						left join " . DB_PREFIX . "event e on e.event_id = awards.at_event_id
						left join " . DB_PREFIX . "mundane m on m.mundane_id = awards.given_by_id
						left join " . DB_PREFIX . "mundane bwm on bwm.mundane_id = awards.by_whom_id
					where awards.mundane_id = '" . mysql_real_escape_string($request['MundaneId']) . "' $player_award
					order by
						a.is_ladder, a.is_title, a.title_class, a.name, awards.rank, awards.date";

		$r = $this->db->query($sql);
		$response = array();
		$response['Awards'] = array();
		if ($r === false) {
			$response['Status'] = InvalidParameter(NULL, 'Problem processing request.');
		} else if ($r->size() > 0) {
			while ($r->next()) {
				$response['Awards'][] = array(
						'AwardsId' => $r->awards_id,
						'KingdomAwardId' => $r->kingdomaward_id,
						'AwardId' => $r->award_id,
						'MundaneId' => $r->mundane_id,
						'Rank' => $r->rank,
						'Date' => $r->date,
						'GivenById' => $r->given_by_id,
						'Note' => $r->note,
						'ParkId' => $r->park_id,
						'KingdomId' => $r->kingdom_id,
						'EventId' => $r->at_event_id,
						'Name' => $r->name,
						'KingdomAwardKingdomId' => $r->kingdom_award_kingdom_id,
					'KingdomAwardName' => $r->kingdom_awardname,
						'CustomAwardName' => $r->custom_name,
						'IsLadder' => $r->is_ladder,
						'IsTitle' => $r->is_title,
						'TitleClass' => $r->title_class,
						'OfficerRole' => $r->officer_role,
						'ParkName' => $r->park_name,
						'KingdomName' => $r->kingdom_name,
						'EventName' => $r->event_name,
						'GivenBy' => $r->persona,
						'EnteredById' => $r->entered_by_id,
						'EnteredBy' => $r->entered_by_persona,
						'IsHistorical' => ($r->given_by_id == 0 && $r->at_park_id == 0 && $r->at_kingdom_id == 0 && $r->at_event_id == 0 && !$r->revoked) ? 1 : 0,
					);
			}
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
					"group by ssa.date_year, ssa.date_week6) a on a.class_id = c.class_id"
					to
					group by ssa.class_id, ssa.date_year, ssa.date_week6) a on a.class_id = c.class_id


			-- 2015-06-22
				Now it really does prevent double-counting, by using a subquery to gather the "first" entry on each date rather
				than relying on the innodb code to randomly group by date into whatever class (over-counting some classes, under-counting others)
		ONE PER WEEK

		$sql = "select c.class_id, c.name as class_name, count(a.week) as weeks, sum(a.attendances) as attendances, sum(a.credits) as credits, cr.class_reconciliation_id, cr.reconciled
					from " . DB_PREFIX . "class c
						left join
							(select ssa.class_id, count(ssa.attendance_id) as attendances, max(ssa.credits) as credits, ssa.date_week6 as week
								from " . DB_PREFIX . "attendance ssa
								where
									ssa.mundane_id = $request[MundaneId]
								group by ssa.date_year, ssa.date_week6) a on a.class_id = c.class_id
						left join " . DB_PREFIX . "class_reconciliation cr on cr.class_id = c.class_id and cr.mundane_id = $request[MundaneId]
					group by c.class_id
				";
		*/
		$sql = "select c.class_id, c.active, c.name as class_name, count(a.week) as weeks, sum(a.attendances) as attendances, sum(a.credits) as credits, cr.class_reconciliation_id, cr.reconciled
					from " . DB_PREFIX . "class c
						left join
							(select ssa.class_id, count(ssa.attendance_id) as attendances, sum(ssa.credits) as credits, ssa.date_week6 as week
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
			while ($r->next()) {
				$response['Classes'][$r->class_id] = array(
						'ClassReconciliationId' => $r->class_reconciliation_id,
						'Reconciled' => $r->reconciled,
						'ClassId' => $r->class_id,
						'ClassName' => $r->class_name,
						'Weeks' => $r->weeks,
						'Attendances' => $r->attendances,
						'Credits' => $r->credits
					);
			}
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
        while (!$found && $calls > 0) {
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
        }
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
				$this->mundane->park_member_since = date('Y-m-d');
				$this->mundane->save();

				Authorization::SaltPassword($this->mundane->password_salt, strtoupper(trim($this->mundane->username)) . trim($request['Password']), $this->mundane->password_expires);

				if ($request['Waivered'] && strlen($request['Waiver']) > 0 && strlen($request['Waiver']) < 465000 && Common::supported_mime_types($request['WaiverMimeType']) && !Common::is_pdf_mime_type($request['WaiverMimeType'])) {
					$waiver = @imagecreatefromstring(base64_decode($request['Waiver']));
					if ($waiver !== false)
					{
						$base = DIR_WAIVERS . sprintf("%06d", $this->mundane->mundane_id);
						$use_png = Common::gd_has_transparency($waiver);

						if (file_exists($base . '.jpg')) unlink($base . '.jpg');
						if (file_exists($base . '.png')) unlink($base . '.png');

						if ($use_png) {
							imagealphablending($waiver, false);
							imagesavealpha($waiver, true);
							imagepng($waiver, $base . '.png');
							$this->mundane->waiver_ext = 'png';
						} else {
							imagejpeg($waiver, $base . '.jpg');
							$this->mundane->waiver_ext = 'jpg';
						}
						$this->mundane->waivered = 1;
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
				}
				if ($request['HasImage'] && strlen($request['Image']) > 0 && strlen($request['Image']) < 465000 && Common::supported_mime_types($request['ImageMimeType']) && !Common::is_pdf_mime_type($request['ImageMimeType'])) {
					$playerimage = @imagecreatefromstring(base64_decode($request['Image']));
					if ($playerimage !== false)
					{
						$base = DIR_PLAYER_IMAGE . sprintf("%06d", $this->mundane->mundane_id);
						$use_png = Common::gd_has_transparency($playerimage);

						if (file_exists($base . '.jpg')) unlink($base . '.jpg');
						if (file_exists($base . '.png')) unlink($base . '.png');

						if ($use_png) {
							imagealphablending($playerimage, false);
							imagesavealpha($playerimage, true);
							imagepng($playerimage, $base . '.png');
						} else {
							imagejpeg($playerimage, $base . '.jpg');
						}
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

  public function hydrated_players($ids) {
    $sql = "select k.name as kingdom, k.kingdom_id, p.name as park, p.park_id, m.mundane_id, m.persona 
              from " . DB_PREFIX . "mundane m
                left join " . DB_PREFIX . "park p on m.park_id = p.park_id
                left join " . DB_PREFIX . "kingdom k on m.kingdom_id = k.kingdom_id
              where m.mundane_id in (" . implode(",",$ids) . ")";
    
    $r = $this->db->query($sql);

		$response = array();
		if ($r !== false && $r->size() > 0) {
			$response = array();
			while ($r->next()) {
				$response[$r->mundane_id] = array(
					'KingdomId' => $r->kingdom_id,
					'Kingdom' => $r->kingdom,
					'ParkId' => $r->park_id,
					'Park' => $r->park,
					'MundaneId' => $r->mundane_id,
					'Persona' => $r->persona,
					'id' => $r->mundane_id
				);
			}
    }
    return $response;
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
				'Surname' => $this->mundane->surname, 'GivenName' => $this->mundane->given_name, 'PasswordExpires' => $this->mundane->password_expires,
        'Persona' => $this->mundane->persona
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

			$from_player = $this->GetPlayer(array('MundaneId' => $request['FromMundaneId']));
			$to_player = $this->GetPlayer(array('MundaneId' => $request['ToMundaneId']));

			if ($from_player['Status']['Status'] != 0 || $to_player['Status']['Status'] != 0)
				return InvalidParameter("One of the players could not be found.");

			Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $request['FromMundaneId'], $from_player['Player'], $to_player['Player']);

			$sql = "DELETE FROM
						" . DB_PREFIX . "attendance
					WHERE
						mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'
						AND date in (SELECT date FROM
									(select distinct date from " . DB_PREFIX . "attendance
										WHERE
											mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "') as d)";
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

		$player = $this->GetPlayer(array('MundaneId' => $request['MundaneId']));

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

			Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $request['MundaneId'], $player['Player']);

			$this->mundane->park_id = $request['ParkId'];
			$this->mundane->kingdom_id = $park->kingdom_id;
			$this->mundane->park_member_since = date('Y-m-d');
			$this->mundane->waivered = $request['Waivered']?1:0;
			$this->mundane->save();
			logtrace('MovePlayer(): Success', $request);
			return Success();
		} else {
			return NoAuthorization();
		}
	}

	public function _ClearSuspensions() {
		$sql = "update " . DB_PREFIX . "mundane set suspended = 0, suspended_by_id = null, suspended_at = null, suspended_until = null, suspension = null where suspended_until < curdate() and suspended_until is not null and suspended_until != '0000-00-00'";
		$this->db->query($sql);
	}

	public function SetPlayerSuspension($request) {
		$this->mundane->clear();
		$this->mundane->mundane_id = $request['MundaneId'];
		if (!$this->mundane->find()) {
			return InvalidParameter();
		}

		$this->_ClearSuspensions();

		if ($request['MundaneId'] == 1) {
			Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $request['MundaneId'], $player['Player']);
			return InvalidParameter('No thanks. This has been logged.');
		}

		if (!isset($request['Suspended'])) {
			return InvalidParameter('You must choose a suspension state: ' . print_r($request, 1));
		}

		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $this->mundane->kingdom_id, AUTH_EDIT))) {
			$this->mundane->suspended = $request['Suspended'];
			if (!$request['Suspended']) {
				$this->mundane->suspended_by_id = 0;
				$this->mundane->suspended_at = "0000-00-00";
				$this->mundane->suspended_until = "0000-00-00";
				$this->mundane->suspension= "";
			} else {
				$this->mundane->suspended_by_id = $request['SuspendedById'];
				$this->mundane->suspended_at = $request['SuspendedAt'];
				if (isset($request['SuspendedUntil'])) $this->mundane->suspended_until = $request['SuspendedUntil'];
				if (isset($request['Suspension'])) $this->mundane->suspension= $request['Suspension'];
			}
			$this->mundane->save();
			Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $request['MundaneId'], $player['Player']);
		} else {
			return NoAuthorization();
		}
	}

	public function load_model( $name )
	{
		if ( file_exists( DIR_MODEL . 'model.' . $name . '.php' ) ) {
			require_once( DIR_MODEL . 'model.' . $name . '.php' );
			$model_name = 'Model_' . $name;
			$this->$name = new $model_name();
		}
	}

	public function UpdatePlayer($request) {
		logtrace("UpdatePlayer()", $request);
		$mundane = $this->player_info($request['MundaneId']);
		$requester_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);

		if ($request['RemoveDues'] === "Revoke Dues") {
			// No way to reliably gap revoke for the Dues transition. mORK will need to update their code
			return NoAuthorization('Outdated Request Method.');
			
			$this->load_model('Treasury');
			return $this->Treasury->RemoveLastDuesPaid(array(
				'MundaneId' => $request['MundaneId'],
				'Token' => $request['Token']
			));
		}

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
                die("You have attempted an illegal operation.  Only an Admin may update an Admin. Your attempt has been logged.");
            }

			$player = $this->GetPlayer(array('MundaneId' => $request['MundaneId']));

			$this->mundane->clear();
			$this->mundane->mundane_id = $request['MundaneId'];
			if ($this->mundane->find()) {
				logtrace('Updating player', $request);

				Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $request['MundaneId'], $player['Player']);

				$this->mundane->modified = date('Y-m-d H:i:s', time());
				$this->mundane->given_name = is_null($request['GivenName'])?$this->mundane->given_name:$request['GivenName'];
				$this->mundane->surname = is_null($request['Surname'])?$this->mundane->surname:$request['Surname'];
				$this->mundane->other_name = is_null($request['OtherName'])?$this->mundane->other_name:$request['OtherName'];
				$this->mundane->username = is_null($request['UserName'])?$this->mundane->username:$request['UserName'];
				$this->mundane->persona = is_null($request['Persona'])?$this->mundane->persona:$request['Persona'];
				$this->mundane->pronoun_id = is_null($request['PronounId'])?$this->mundane->pronoun_id:$request['PronounId'];
				$this->mundane->pronoun_custom = is_null($request['PronounCustom'])?$this->mundane->pronoun_custom:$request['PronounCustom'];

				// reeve or corpora qual changes
				// TODO: add error messaging
				if (Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_KINGDOM, $this->mundane->kingdom_id, AUTH_EDIT) || Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_ADMIN, 0, AUTH_EDIT) || Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_PARK, $this->mundane->park_id, AUTH_EDIT)) {
					$this->mundane->reeve_qualified = is_null($request['ReeveQualified'])?$this->mundane->reeve_qualified:$request['ReeveQualified'];
					$this->mundane->reeve_qualified_until = is_null($request['ReeveQualifiedUntil'])?$this->mundane->reeve_qualified_until:$request['ReeveQualifiedUntil'];
					$this->mundane->corpora_qualified = is_null($request['CorporaQualified'])?$this->mundane->corpora_qualified:$request['CorporaQualified'];
					$this->mundane->corpora_qualified_until = is_null($request['CorporaQualifiedUntil'])?$this->mundane->corpora_qualified_until:$request['CorporaQualifiedUntil'];
				}

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
				$this->mundane->restricted = is_null($request['Restricted']) ? $this->mundane->restricted : ($request['Restricted'] ? 1 : 0);

				if (Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_PARK, $mundane['ParkId'], AUTH_CREATE)) {
    				$this->mundane->active = is_null($request['Active']) ? $this->mundane->restricted : ($request['Active']?1:0);
				}
				if (Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_PARK, $mundane['ParkId'], AUTH_CREATE)) {
					$this->mundane->park_member_since = is_null($request['ParkMemberSince']) ? $this->mundane->park_member_since : $request['ParkMemberSince'];
				}
				if (strlen($request['Heraldry'])) {
					Ork3::$Lib->heraldry->SetPlayerHeraldry($request);
				}
				if ($request['DuesDate']) {
					// Add dues to new system as well until mORK is updated
					$dues = $this->AddDues([ 'Token' => $request['Token'], 'ParkId' => $mundane['ParkId'], 'MundaneId' => $mundane['MundaneId'], 'KingdomId' => $mundane['KingdomId'], 'DuesFrom' => $request['DuesDate'], 'Terms' => $request['DuesSemesters'] ]);

					$this->load_model('Treasury');
					$duespaid = $this->Treasury->DuesPaidToPark(array(
						'MundaneId' => $request['MundaneId'],
						'Token' => $request['Token'],
						'TransactionDate' => $request['DuesDate'],
						'Semesters' => $request['DuesSemesters']
					));
					if ($duespaid['Status'] > 0) {
						return InvalidParameter();
					}
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

	public function RemoveHeraldry($request) {
		logtrace("RemoveHeraldry", $request);
		return Ork3::$Lib->heraldry->RemovePlayerHeraldry($request);
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
			if ($playerimage !== false)
			{
				$base = DIR_PLAYER_IMAGE . sprintf("%06d", $this->mundane->mundane_id);
				$use_png = Common::gd_has_transparency($playerimage);

				if (file_exists($base . '.jpg')) unlink($base . '.jpg');
				if (file_exists($base . '.png')) unlink($base . '.png');

				if ($use_png) {
					imagealphablending($playerimage, false);
					imagesavealpha($playerimage, true);
					imagepng($playerimage, $base . '.png');
				} else {
					imagejpeg($playerimage, $base . '.jpg');
				}
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

	private function resolve_player_image_url($mundane_id, $modified) {
		$name = sprintf('%06d', $mundane_id);
		$ext = file_exists(DIR_PLAYER_IMAGE . $name . '.png') ? 'png' : 'jpg';
		return HTTP_PLAYER_IMAGE . $name . '.' . $ext . '?' . strtotime($modified);
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
    			if ($waiver !== false)
    			{
					$base = DIR_WAIVERS . sprintf("%06d", $request['MundaneId']);
					$use_png = Common::gd_has_transparency($waiver);

					if (file_exists($base . '.jpg')) unlink($base . '.jpg');
					if (file_exists($base . '.png')) unlink($base . '.png');

					if ($use_png) {
						imagealphablending($waiver, false);
						imagesavealpha($waiver, true);
						imagepng($waiver, $base . '.png');
						$this->mundane->waiver_ext = 'png';
					} else {
						imagejpeg($waiver, $base . '.jpg');
						$this->mundane->waiver_ext = 'jpg';
					}
    				$this->mundane->waivered = 1;
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
    		} else if ($request['Waivered']) {
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

	public function RemoveImage($request) {
		logtrace("RemoveImage", $request);
		$mundane = $this->player_info($request['MundaneId']);
		$requester_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);

		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)
			    || $requester_id == $request['MundaneId']) {
			$this->mundane->clear();
			$this->mundane->mundane_id = $request['MundaneId'];
			if ($this->mundane->find()) {
				$path = DIR_PLAYER_IMAGE . sprintf('%06d', $request['MundaneId']) . '.jpg';
				if (file_exists($path)) unlink($path);
				$this->mundane->has_image = 0;
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

	public function ResetWaivers($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0)
			return NoAuthorization();

		if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_ADMIN))
			return NoAuthorization();

		if (valid_id($request['KingdomId'])) {
			$sql = "UPDATE " . DB_PREFIX . "mundane SET waivered = 0 WHERE kingdom_id = '" . mysql_real_escape_string($request['KingdomId']) . "'";
			$this->db->query($sql);
			return Success('Waivers have been reset for all players in the kingdom.');
		} else if (valid_id($request['ParkId'])) {
			$sql = "UPDATE " . DB_PREFIX . "mundane SET waivered = 0 WHERE park_id = '" . mysql_real_escape_string($request['ParkId']) . "'";
			$this->db->query($sql);
			return Success('Waivers have been reset for all players in the park.');
		}

		return InvalidParameter('Either KingdomId or ParkId must be specified.');
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
            list($request['KingdomAwardId'], $request['AwardId']) = Ork3::$Lib->award->LookupAward(array('KingdomId' => $recipient['KingdomId'], 'AwardId' => $request['AwardId']));
        } else if (valid_id($request['KingdomAwardId'])) {
            list($kingdom_id, $request['AwardId']) = Ork3::$Lib->award->LookupKingdomAward(array('KingdomAwardId' => $request['KingdomAwardId']));
        } else {
            return InvalidParameter();
        }

		if (valid_id($mundane_id)
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $recipient['ParkId'], AUTH_EDIT)) {
			if (valid_id($request['ParkId'])) {
				$Park = new Park();
				$park_info = $Park->GetParkShortInfo($request);
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
			$awards->by_whom_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
			$awards->entered_at = date("Y-m-d H:i:s");
			// If no event, then go Park!
            if (valid_id($request['GivenById'])) {
    			$awards->park_id = valid_id($given_by['Player']['ParkId'])?$given_by['Player']['ParkId']:0;
    			// If no event and valid parkid, go Park! Otherwise, go Kingdom.  Unless it's an event.  Then go ... ZERO!
    			$awards->kingdom_id = valid_id($given_by['Player']['KingdomId'])?$given_by['Player']['KingdomId']:0;
            }
			// Events are awesome.

			$awards->save();

			Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $request['AwardsId'], $this->get_award($awards));

			return Success('');
		} else {
			return NoAuthorization();
		}
	}

	private function revoke_award(& $awards, $revocation, $revoker_id) {
			Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $awards->mundane_id, $this->get_award($awards));

			$awards->stripped_from = $awards->mundane_id;
			$awards->mundane_id = 0;
			$awards->revoked = 1;
			$awards->revoked_at = date("Y-m-d H:i:s");
			$awards->revocation = $revocation;
			$awards->revoked_by_id = $revoker_id;

			$awards->save();
	}

	public function RevokeAllAwards($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		$awards = new yapo($this->db, DB_PREFIX . 'awards');
		$awards->clear();
		$awards->mundane_id = $request['MundaneId'];
		if ($awards->find() && valid_id($mundane_id)) {
			$mundane = $this->player_info($awards->mundane_id);
			if (valid_id($request['MundaneId'])
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {

				do  {
					$this->revoke_award($awards, $request["Revocation"], $mundane_id);
				} while ($awards->next());

				return Success($awards->awards_id);
			} else {
				return NoAuthorization();
			}
		} else {
			return InvalidParameter();
		}
	}

	public function RevokeAward($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		$awards = new yapo($this->db, DB_PREFIX . 'awards');
		$awards->clear();
		$awards->awards_id = $request['AwardsId'];
		if (valid_id($request['AwardsId']) && $awards->find() && $mundane_id > 0) {
			$mundane = $this->player_info($awards->mundane_id);
			if (valid_id($mundane_id)
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {

				$this->revoke_award($awards, $request["Revocation"], $mundane_id);

				return Success($awards->awards_id);
			} else {
				return NoAuthorization();
			}
		} else {
			return InvalidParameter();
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

				Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $awards->mundane_id, $this->get_award($awards));

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

	public function ReconcileAward($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		$awards = new yapo($this->db, DB_PREFIX . 'awards');
		$awards->clear();
		$awards->awards_id = $request['AwardsId'];
		if (valid_id($request['AwardsId']) && $awards->find()) {
			$mundane = $this->player_info($awards->mundane_id);
			if (valid_id($mundane_id)
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {

				// Validate park and compute new location values for comparison
				$info = null;
				if (valid_id($request['ParkId'])) {
					$Park = new Park();
					$info = $Park->GetParkShortInfo(array( 'ParkId' => $request['ParkId'] ));
					if ($info['Status']['Status'] != 0)
						return InvalidParameter();
				}

				$new_kingdomaward_id = valid_id($request['KingdomAwardId']) ? $request['KingdomAwardId'] : $awards->kingdomaward_id;
				$new_at_park_id = valid_id($request['ParkId']) ? $request['ParkId'] : 0;
				$new_at_kingdom_id = valid_id($request['EventId']) ? 0 : (valid_id($request['ParkId']) ? $info['ParkInfo']['KingdomId'] : (valid_id($request['KingdomId']) ? $request['KingdomId'] : 0));
				$new_at_event_id = valid_id($request['EventId']) ? $request['EventId'] : 0;
				$new_custom_name = isset($request['CustomName']) ? $request['CustomName'] : $awards->custom_name;

				// Skip save and audit if nothing actually changed
				if ($new_kingdomaward_id == $awards->kingdomaward_id
					&& intval($request['Rank']) == intval($awards->rank)
					&& $request['GivenById'] == $awards->given_by_id
					&& $request['Note'] == $awards->note
					&& $new_custom_name == $awards->custom_name
					&& $new_at_park_id == $awards->at_park_id
					&& $new_at_kingdom_id == $awards->at_kingdom_id
					&& $new_at_event_id == $awards->at_event_id) {
					return Success(false);
				}

				if (valid_id($request['KingdomAwardId'])) {
					list($kingdom_id, $new_award_id) = Ork3::$Lib->award->LookupKingdomAward(array('KingdomAwardId' => $request['KingdomAwardId']));
					$awards->kingdomaward_id = $request['KingdomAwardId'];
					$awards->award_id = $new_award_id;
				}

				Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $awards->mundane_id, $this->get_award($awards));

				$awards->rank = $request['Rank'];
				$awards->date = $request['Date'];
				$awards->given_by_id = $request['GivenById'];
				$awards->note = $request['Note'];
				if (isset($request['CustomName'])) $awards->custom_name = $request['CustomName'];
				$awards->at_park_id = $new_at_park_id;
				$awards->at_kingdom_id = $new_at_kingdom_id;
				$awards->at_event_id = $new_at_event_id;
				$awards->save();

				return Success($awards->awards_id);
			} else {
				return NoAuthorization();
			}
		} else {
			return InvalidParameter();
		}
	}

	public function AutoAssignRanks($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if (!valid_id($mundane_id))
			return NoAuthorization();

		$kingdomaward = new yapo($this->db, DB_PREFIX . 'kingdomaward');
		$kingdomaward->clear();
		$kingdomaward->kingdomaward_id = $request['KingdomAwardId'];
		if (!valid_id($request['KingdomAwardId']) || !$kingdomaward->find())
			return InvalidParameter();

		$award = new yapo($this->db, DB_PREFIX . 'award');
		$award->clear();
		$award->award_id = $kingdomaward->award_id;
		if (!$award->find() || !$award->is_ladder)
			return InvalidParameter('Award is not a ladder award.');

		$recipient = $this->player_info($request['MundaneId']);
		if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $recipient['ParkId'], AUTH_EDIT))
			return NoAuthorization();

		$sql = "SELECT awards_id FROM " . DB_PREFIX . "awards
				WHERE mundane_id = '" . mysql_real_escape_string($request['MundaneId']) . "'
				  AND kingdomaward_id = '" . mysql_real_escape_string($request['KingdomAwardId']) . "'
				  AND revoked = 0
				ORDER BY date ASC";
		$r = $this->db->query($sql);
		if ($r === false)
			return InvalidParameter(NULL, 'Problem processing request.');

		$rank = 1;
		$assignments = array();
		while ($r->next()) {
			$this->db->execute("UPDATE " . DB_PREFIX . "awards SET rank = " . intval($rank) . " WHERE awards_id = '" . mysql_real_escape_string($r->awards_id) . "'");
			$assignments[(string)$r->awards_id] = $rank;
			$rank++;
		}

		return Success($assignments);
	}

	private function get_award(& $awards) {
		$award = new stdClass();
		$award->awards_id = $awards->awards_id;
		$award->kingdomaward_id = $awards->kingdomaward_id;
		$award->mundane_id = $awards->mundane_id;
		$award->unit_id = $awards->unit_id;
		$award->park_id = $awards->park_id;
		$award->kingdom_id = $awards->kingdom_id;
		$award->team_id = $awards->team_id;
		$award->rank = $awards->rank;
		$award->date = $awards->date;
		$award->given_by_id = $awards->given_by_id;
		$award->note = $awards->note;
		$award->at_park_id = $awards->at_park_id;
		$award->at_kingdom_id = $awards->at_kingdom_id;
		$award->at_event_id = $awards->at_event_id;
		$award->custom_name = $awards->custom_name;
		$award->award_id = $awards->award_id;
		return $award;
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

					Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $awards->mundane_id, $this->get_award($awards));

					$awards->delete();
			} else {
				return NoAuthorization();
			}
		} else {
			return InvalidParameter();
		}
	}

	public function AddDues($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		$dues = new yapo($this->db, DB_PREFIX . 'dues');
		$dues->clear();

		if (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $request['ParkId'], AUTH_EDIT)) {
			$dues->mundane_id = $request['MundaneId'];
			$dues->created_by = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
			$dues->created_on = date('Y-m-d');
			$dues->park_id = $request['ParkId'];
			$dues->kingdom_id = $request['KingdomId'];
			$dues->dues_from = date('Y-m-d', strtotime($request['DuesFrom']));
			// TODO: create private function that determins DuesUntil based on kingdom configured terms
			$dues->dues_until = $this->determine_dues_until($request['KingdomId'], $request['DuesFrom'], $request['Terms']);
			$dues->terms = $request['Terms'];
			$dues->dues_for_life = $request['DuesForLife'];
			$dues->save();

			return Success($dues->dues_id);
		} else {
			return NoAuthorization();
		}
	}

	private function determine_dues_until($kingdom_id, $dues_from = null, $terms = null) {
		$kconfig = Common::get_configs($kingdom_id);
		$dues_config = $kconfig['DuesPeriod'];
		$n = (int)$dues_config['Value']->Period * (int)$terms; 
		$dues_until = date('Y-m-d', strtotime($dues_from . ' + ' . $n . ' ' . $dues_config['Value']->Type));
		return $dues_until;
	}

	public function GetDues($request) {
		// $request['MundaneId'] $request['ExcludeRevoked'] $request['Active']
        if (valid_id($request['MundaneId'])) {
            $this->dues->clear();
            $this->dues->mundane_id = $request['MundaneId'];
			$sql = "select * from ork_dues where mundane_id = $request[MundaneId]";

			if (!empty($request['ExcludeRevoked'])) {
				$this->dues->revoked = 0;
				$sql .= " and revoked = 0";
			}
			if (!empty($request['Active'])) {
				// ... wtf
				//$this->dues->dues_until_conjunction = ' AND ( `dues_for_life` = 1 OR ';
				//$this->dues->dues_until_term = "> '" . date('Y-m-d') . "') " . ' AND "" = ' ;
				$sql .= " and (dues_for_life = 1 or dues_until > '" . date('Y-m-d') . "')";
			}

			$this->db->clear();
			$this->db->mundane_id = $request['MundaneId'];
			$this->db->dues_until = date('Y-m-d');
			$dues = $this->db->query($sql);

            $duesReport = array();
			$now = time();
            if ($dues->size() > 0) while ($dues->next()) {
				if (!empty($request['Active']) && $now > strtotime($dues->dues_until) && $dues->dues_for_life == 0) {
					continue;
				}
                $duesReport[] = array(
                        'DuesId' => $dues->dues_id,
                        'KingdomId' => $dues->kingdom_id,
                        'KingdomName' => $this->Kingdom->get_kingdom_name($dues->kingdom_id),
                        'ParkId' => $dues->kingdom_id,
                        'ParkName' => $this->Park->get_park_name($dues->park_id),
                        'DuesUntil' => $dues->dues_until,
                        'DuesFrom' => $dues->dues_from,
                        'DuesForLife' => $dues->dues_for_life,
                        'Revoked' => $dues->revoked
                    );
            }
        }
        return $duesReport;
	}

	// TODO:
	public function RevokeDues($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		$dues = new yapo($this->db, DB_PREFIX . 'dues');
		$dues->clear();
		$dues->dues_id = $request['DuesId'];
		if (valid_id($request['DuesId']) && $dues->find()) {
			$mundane = $this->player_info($dues->mundane_id);
			if (valid_id($mundane_id)
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {
				$dues->revoked = 1;
				$dues->revoked_on = date('Y-m-d');
				$dues->revoked_by = $mundane_id;
				$dues->save();
				return Success($dues->dues_id);
			} else {
				return NoAuthorization();
			}
		} else {
			return InvalidParamter();
		}
	}

	public function AddAwardRecommendation($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0)
			return NoAuthorization();

		$this->mundane->clear();
		$this->mundane->mundane_id = $request['MundaneId'];
		if (!$this->mundane->find()) {
			return InvalidParameter();
		}
		$recipient = array ( 'KingdomId' => $this->mundane->kingdom_id, 'ParkId' => $this->mundane->park_id );

        if (valid_id($request['AwardId'])) {
            list($request['KingdomAwardId'], $request['AwardId']) = Ork3::$Lib->award->LookupAward(array('KingdomId' => $recipient['KingdomId'], 'AwardId' => $request['AwardId']));
        } else if (valid_id($request['KingdomAwardId'])) {
            list($kingdom_id, $request['AwardId']) = Ork3::$Lib->award->LookupKingdomAward(array('KingdomAwardId' => $request['KingdomAwardId']));
        } else {
            return InvalidParameter();
        }

		// Check for existing award rank
		$check_rank = 0;
		if (trimlen($request['Rank']) > 0) {
			$check_rank = $request['Rank'];
		}
		$existingAward = new yapo($this->db, DB_PREFIX . 'awards');
		$existingAward->clear();
		$existingAward->kingdomaward_id = $request['KingdomAwardId'];
		$existingAward->mundane_id = $request['MundaneId'];
		$existingAward->rank = $check_rank;
		$existingAward->find();
		if ($existingAward->awards_id) {
			return InvalidParameter('They already have that award.');
		}

		// Check for duplicates
		$dupeRec = new yapo($this->db, DB_PREFIX . 'recommendations');
		$dupeRec->clear();
		$dupeRec->kingdomaward_id = $request['KingdomAwardId'];
		$dupeRec->mundane_id = $request['MundaneId'];
		$dupeRec->recommended_by_id = $mundane_id;
		if (trimlen($request['Rank']) > 0) {
			$dupeRec->rank = $request['Rank'];
		} else {
			$dupeRec->rank = 0;
		}
		if ($dupeRec->find()) do {
			if (!$dupeRec->deleted_at) {
				return InvalidParameter('You already recommended that award and level.');
			}
		} while ($dupeRec->next());

		if (valid_id($mundane_id)) {
			$awardRec = new yapo($this->db, DB_PREFIX . 'recommendations');
			$awardRec->clear();
			$awardRec->kingdomaward_id = $request['KingdomAwardId'];
    		$awardRec->award_id = $request['AwardId'];
			$awardRec->mundane_id = $request['MundaneId'];
			$awardRec->rank = $check_rank;
			$awardRec->date_recommended = date('Y-m-d');
			$awardRec->recommended_by_id = $mundane_id;
			$awardRec->reason = $request['Reason'];
			$awardRec->save();
			return Success('Recommendation Added!');
		} else {
			return NoAuthorization();
		}
	}

	public function DeleteAwardRecommendation($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0)
			return NoAuthorization();

		if (valid_id($request['RequestedBy'])) {
			$can_delete_recommendation = false;
			$awardRec = new yapo($this->db, DB_PREFIX . 'recommendations');
			$awardRec->clear();
			$awardRec->recommendations_id = $request['RecommendationsId'];

			if (valid_id($request['RecommendationsId']) && $awardRec->find()) {
				$recipientInfo = $this->player_info($awardRec->mundane_id);
				if (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $recipientInfo['ParkId'], AUTH_EDIT)) {
					$can_delete_recommendation = true;
				}
				if ($can_delete_recommendation || $request['RequestedBy'] == $awardRec->recommended_by_id || $request['RequestedBy'] == $awardRec->mundane_id) {
					$awardRec->deleted_by = $request['RequestedBy'];
					$awardRec->deleted_at = date('Y-m-d H:i:s');
					$awardRec->save();
					return Success('Recommendation Removed!');
				} else {
					return InvalidParameter('Only the giver, recipient, or Admin may delete a recommendation.');
				}
			} else {
				return InvalidParameter('There was a problem with the request.');
			}
		} else {
			return NoAuthorization();
		}
	}

	public function get_latest_attendance_date($mundane_id) {
		$sql = "select max(date) as latest_date from " . DB_PREFIX . "attendance where mundane_id = '" . mysql_real_escape_string($mundane_id) . "'";
		$r = $this->db->query($sql);
		if ($r === false || $r->size() == 0) {
			return null;
		}
		$r->next();
		$date = $r->latest_date;
		return $date ? date('Y-m-d', strtotime($date)) : null;
	}

}

?>
