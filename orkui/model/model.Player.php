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
		$awards = $this->Player->AwardsForPlayer(array( 'MundaneId' => $mundane_id ));
		if ($awards['Status']['Status'] != 0) return $awards;
		$attendance = $this->Player->AttendanceForPlayer(array( 'MundaneId' => $mundane_id ));
		if ($attendance['Status']['Status'] != 0) return $attendance;
		$classes = $this->Player->GetPlayerClasses(array( 'MundaneId' => $mundane_id ));
		if ($classes['Status']['Status'] != 0) return $classes;
		$details = array( 'Awards' => $awards['Awards'], 'Attendance' => $attendance['Attendance'], 'Classes' => $classes['Classes'] );
		return $details;
	}
	
	function delete_player_award($request) {
		return $this->Player->RemoveAward($request);
	}
	
	function revoke_player_award($request) {
		return $this->Player->RevokeAward($request);
	}
	
	function revoke_all_awards($request) {
		return $this->Player->RevokeAllAwards($request);
	}
	
	function add_player_award($request) {
		return $this->Player->AddAward($request);
	}
	
	function update_player_award($request) {
		return $this->Player->UpdateAward($request);
	}

	function reconcile_player_award($request) {
		return $this->Player->ReconcileAward($request);
	}

	function auto_assign_ranks($request) {
		return $this->Player->AutoAssignRanks($request);
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
	/*
	function create_player($request) {
		$r = $this->Player->CreatePlayer($request);
		return $r;
	}
	*/
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

	function get_latest_attendance_date($mundane_id) {
		return Ork3::$Lib->player->get_latest_attendance_date($mundane_id);
	}
}

?>