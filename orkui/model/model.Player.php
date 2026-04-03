<?php

class Model_Player extends Model {

	function __construct() {
		parent::__construct();
		$this->Player = new APIModel('Player');
		$this->Award = new APIModel('Award');
	}
	
    function remove_note($request) {
        return $this->Player->RemoveNote($request);
    }

    function edit_note($request) {
        return Ork3::$Lib->player->EditNote($request);
    }
    
    function get_notes($id) {
        return $this->Player->GetNotes(array('MundaneId' => $id));
    }
    
	function update_class_reconciliation($request) {
		return $this->Player->SetPlayerReconciledCredits($request);
	}
	
	function fetch_player($mundane_id) {
		$player = $this->Player->GetPlayer(array( 'MundaneId' => $mundane_id, 'Token' => $this->session->token ));
		if ($player['Status']['Status'] != 0) return false;
		$player = $player['Player'];
		return $player;
	}
	
	function fetch_player_details($mundane_id) {
		$key = Ork3::$Lib->ghettocache->key(['MundaneId' => $mundane_id]);
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 60)) !== false)
			return $cache;
		$awards = $this->Player->AwardsForPlayer(array( 'MundaneId' => $mundane_id ));
		if ($awards['Status']['Status'] != 0) return $awards;
		$attendance = $this->Player->AttendanceForPlayer(array( 'MundaneId' => $mundane_id ));
		if ($attendance['Status']['Status'] != 0) return $attendance;
		$classes = $this->Player->GetPlayerClasses(array( 'MundaneId' => $mundane_id ));
		if ($classes['Status']['Status'] != 0) return $classes;
		$details = array( 'Awards' => $awards['Awards'], 'Attendance' => $attendance['Attendance'], 'Classes' => $classes['Classes'] );
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $details);
	}

	private function bust_player_details_cache($request) {
		$mundane_id = $request['RecipientId'] ?? $request['MundaneId'] ?? null;
		if (!$mundane_id) return;
		$key = Ork3::$Lib->ghettocache->key(['MundaneId' => $mundane_id]);
		Ork3::$Lib->ghettocache->bust('Model_Player.fetch_player_details', $key);
	}

	function delete_player_award($request) {
		$r = $this->Player->RemoveAward($request);
		if ($r['Status']['Status'] == 0) $this->bust_player_details_cache($request);
		return $r;
	}

	function revoke_player_award($request) {
		$r = $this->Player->RevokeAward($request);
		if ($r['Status']['Status'] == 0) $this->bust_player_details_cache($request);
		return $r;
	}

	function add_note($request) {
		return $this->Player->AddNote($request);
	}
	
	function revoke_all_awards($request) {
		$r = $this->Player->RevokeAllAwards($request);
		if ($r['Status']['Status'] == 0) { $this->bust_player_details_cache($request); }
		return $r;
	}
	
	function add_player_award($request) {
		$r = $this->Player->AddAward($request);
		if ($r['Status']['Status'] == 0) { $this->bust_player_details_cache($request); }
		return $r;
	}

	function update_player_award($request) {
		$r = $this->Player->UpdateAward($request);
		if ($r['Status']['Status'] == 0) { $this->bust_player_details_cache($request); }
		return $r;
	}

	function reconcile_player_award($request) {
		return $this->Player->ReconcileAward($request);
	}

	function add_dues($request) {
		return $this->Player->AddDues($request);
	}

	function get_dues($id, $exclude_revoked = 0, $active = false) {
        return $this->Player->GetDues(array('MundaneId' => $id, 'ExcludeRevoked' => $exclude_revoked, 'Active' => $active));
	}

	function revoke_dues($request) {
        return $this->Player->RevokeDues($request);
	}
	
	function one_shot($request) {
		return $this->Player->AddOneShotFaceImage($request); 
	}
  
	function update_player($request) {
		return $this->Player->UpdatePlayer($request);
	}
	
	function set_ban($request) {
		$r = $this->Player->SetBan($request);
		return $r;
	}
	function create_player($request) {
		return $this->Player->CreatePlayer($request);
	}
	function create_selfreg_link($request) {
		return $this->Player->CreateSelfRegLink($request);
	}
	function validate_selfreg_link($token) {
		return $this->Player->ValidateSelfRegLink(['SelfRegToken' => $token]);
	}
	function self_register($request) {
		return $this->Player->SelfRegister($request);
	}
	function move_player($request) {
		$r = $this->Player->MovePlayer($request);
		return $r;
	}
	
	function suspend_player($request) {
		$r = $this->Player->SetPlayerSuspension($request);
		return $r;
	}
	
	function merge_player($request) {
		$r = $this->Player->MergePlayer($request);
		return $r;
	}

	function reset_waivers($request) {
		return $this->Player->ResetWaivers($request);
	}

	function add_player_recommendation($request) {
		return $this->Player->AddAwardRecommendation($request);
	}

	function delete_player_recommendation($request) {
		return $this->Player->DeleteAwardRecommendation($request);
	}

	function remove_heraldry($request) {
		return $this->Player->RemoveHeraldry($request);
	}

	function remove_image($request) {
		return $this->Player->RemoveImage($request);
	}

	function get_latest_attendance_date($mundane_id) {
		return Ork3::$Lib->player->get_latest_attendance_date($mundane_id);
	}

	function get_earliest_attendance_date($mundane_id) {
		return Ork3::$Lib->player->get_earliest_attendance_date($mundane_id);
	}

	function get_earliest_park_attendance_date($mundane_id, $park_id) {
		return Ork3::$Lib->player->get_earliest_park_attendance_date($mundane_id, $park_id);
	}
}

?>