<?php

class Tournament extends Ork3 {

	public function __construct() {
		parent::__construct();
		$this->Bracket = new yapo($this->db, DB_PREFIX . 'bracket');
		$this->Glicko2 = new yapo($this->db, DB_PREFIX . 'glicko2');
		$this->Match = new yapo($this->db, DB_PREFIX . 'match');
		$this->Participant = new yapo($this->db, DB_PREFIX . 'participant');
		$this->Player = new yapo($this->db, DB_PREFIX . 'participant_mundane');
		$this->Tournament = new yapo($this->db, DB_PREFIX . 'tournament');
	}
	
	public function CreateTournament($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if (!valid_id($mundane_id)) return NoAuthorization();
		
		logtrace("CreateTournament() :1", $request);
		
		$this->Tournament->clear();
		$this->Tournament->kingdom_id = $request['KingdomId'];
		$this->Tournament->park_id = $request['ParkId'];
		$this->Tournament->event_calendardetail_id = $request['EventCalendarDetailId'];
		if (valid_id($request['EventCalendarDetailId'])) {
			$detail = new yapo($this->db, DB_PREFIX . 'event_calendardetail');
			$detail->event_calendardetail_id = $request['EventCalendarDetailId'];
			if ($detail->find()) {
				$this->Tournament->event_id = $detail->event_id;
			} else if (valid_id($request['EventCalendarDetailId'])) {
				return InvalidParameter();
			}
		}
		$this->Tournament->name = $request['Name'];
		$this->Tournament->description = strip_tags($request['Description'], "<p><br><ul><li><b><i>");
		$this->Tournament->date_time = $request['When'];
		$this->Tournament->save();
		
		return Success($this->Tournament->tournament_id);
	}
	
	public function CreateTeam($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if (!valid_id($mundane_id)) return NoAuthorization();
		
		$this->Team->clear();
		$this->Team->name = $request['Name'];
		$this->Team->save();
		
		return Success($this->Team->team_id);
	}
	
	private function check_auth($Token, $TournamentId=null) {
		if (is_array($Token)) {
			$Token = $Token['Token'];
			$TournamentId = $Token['TournamentId'];
		}
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($Token);
		if (!valid_id($mundane_id)) return false;
		$this->Tournament->clear();
		$this->Tournament->tournament_id = $TournamentId;
		if ($this->Tournament->find()) {
			if (valid_id($this->Tournament->kingdom_id)) {
				return Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $this->Tournament->kingdom_id, AUTH_EDIT);
			} else if (valid_id($this->Tournament->park_id)) {
				return Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $this->Tournament->park_id, AUTH_EDIT);
			} else if (valid_id($this->Tournament->event_id)) {
				return Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_EVENT, $this->Tournament->event_id, AUTH_EDIT);
			}
		} else {
			return false;
		}
	}
	
	public function AddBracket($request) {
		if (!$this->check_auth($request)) return NoAuthorization();
		
		if (valid_id($request['CopyOfId'])) {
			$sql = "insert into " . DB_PREFIX . "bracket (tournament_id, style, style_note, method, rings, participants, seeding) 
						select tournament_id, style, style_note, method, rings, participants, seeding from " . DB_PREFIX . "bracket where bracket_id = $request[CopyOfId]";
			$this->db->query($sql);
			$bracket_id = $this->db->getInsertId();
			$sql = "insert into " . DB_PREFIX . "participant (tournament_id, bracket_id, alias, mundane_id, unit_id, park_id, kingdom_id, team_id) 
						select tournament_id, $bracket_id, alias, mundane_id, unit_id, park_id, kingdom_id, team_id from " . DB_PREFIX . "participant where bracket_id = $request[CopyOfId]";
			$this->db->query($sql);
		} else {
			$this->Bracket->clear();
			$this->Bracket->tournament_id = $request['TournamentId'];
			$this->Bracket->style = $request['Style'];
			$this->Bracket->style_note = $request['StyleNote'];
			$this->Bracket->method = $request['Method'];
			$this->Bracket->rings = $request['Rings'];
			$this->Bracket->participants = $request['Participants'];
			$this->Bracket->seeding = $request['Seeding'];
			
			$this->Bracket->save();
			
			return Success($this->Bracket->bracket_id);
		}
	}
	
	public function GetBrackets($request) {
		$this->Bracket->clear();
		$this->Bracket->tournament_id = $request['TournamentId'];
	}
	
	public function AddParticipant($request) {
	
		if (!$this->check_auth($request)) return NoAuthorization();
		
		if (valid_id($request['ParticipantId'])) {
			$sql = "insert into " . DB_PREFIX . "participant (tournament_id, bracket_id, alias, mundane_id, unit_id, park_id, kingdom_id, team_id) 
						select tournament_id, " . mysql_real_escape_string($request['BracketId']) . ", alias, mundane_id, unit_id, park_id, kingdom_id, team_id from " . DB_PREFIX . "participant where participant_id = '" . mysql_real_escape_string($request['ParticipantId']) . "'";
			$this->db->query($sql);
			return Success($this->db->getInsertId());
		} else {
			$this->Participant->clear();
			$this->Participant->tournament_id = $request['TournamentId'];
			$this->Participant->bracket_id = $request['BracketId'];
			$this->Participant->alias = $request['Alias'];
			$this->Participant->unit_id = $request['UnitId'];
			$this->Participant->park_id = $request['ParkId'];
			$this->Participant->kingdom_id = $request['KingdomId'];
			$this->Participant->team_id = $request['TeamId'];
			
			$this->Participant->save();
			
			if (!valid_id($request['MundaneId'])) {
				foreach ($request['Members'] as $k => $member) {
					$this->Player->clear();
					$this->Player->participant_id = $this->Participant->participant_id;
					$this->Player->mundane_id = $member['MundaneId'];
					$this->Player->tournament_id = $member['TournamentId'];
					$this->Player->bracket_id = $member['BracketId'];
					$this->Player->save();
				}
			}
			return Success($this->Participant->participant_id);
		}
	}
	
	public function GetParticipants($request) {
		if (valid_id($request['TournamentId'])) $where = " and p.tournament_id = $request[TournamentId]";
		if (valid_id($request['BracketId'])) $where .= " and p.bracket_id = $request[BracketId]";
		
		$sql = "select p.*, player.*, m.persona, k.name as kingdom_name, park.name as park_name, u.name as unit_name, t.name as team_name
					from " . DB_PREFIX . "participant p
						left join " . DB_PREFIX . "participant_mundane player on player.participant_id = p.participant_id
							left join " . DB_PREFIX . "mundane m on player.mundane_id = m.mundane_id
						left join " . DB_PREFIX . "unit u on p.unit_id = u.unit_id
						left join " . DB_PREFIX . "park on p.park_id = park.park_id
						left join " . DB_PREFIX . "kingdom k on k.kingdom_id = p.kingdom_id
						left join " . DB_PREFIX . "team t on t.team_id = p.team_id
					where 1 $where
			";
	}

	public function GetMatches($request) {
	
	}
	
	public function PostMatches($request) {
		if (!$this->check_auth($request)) return NoAuthorization();
	
	}
	
	private function get_single_elim_matches($bracket_id) {
	
	}
	
	private function post_single_elim_matches($bracket_id, $matches) {
		if (!$this->check_auth($request)) return NoAuthorization();
	
	}
	
}

?>