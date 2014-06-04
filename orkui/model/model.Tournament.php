<?php

class Model_Tournament extends Model {

	function __construct() {
		parent::__construct();
		$this->Report = new APIModel('Report');
		$this->Tournament = new APIModel('Tournament');
	}

	function get_tournies($request) {
		return $this->Report->TournamentReport($request);
	}
	
	function create_tournament($request) {
		return $this->Tournament->CreateTournament($request);
	}
	
	function add_bracket($request) {
	echo "add bracket1";
		return $this->Tournament->AddBracket($request);
	}
	
	function add_participant($request) {
		return $this->Tournament->AddParticipant($request);
	}
	
	function get_brackets($tournament_id) {
	
	}
	
	function get_teams($tournament_id) {
	
	}
	
	function create_team($request) {
	
	}
	
}

?>