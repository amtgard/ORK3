<?php

class Game extends Ork3 {

	public function __construct() {
		parent::__construct();
		$this->game = new yapo($this->db, DB_PREFIX . 'game');
		$this->team = new yapo($this->db, DB_PREFIX . 'game_team');
		$this->objective = new yapo($this->db, DB_PREFIX . 'game_objective');
	}
	
	public function CreateGame($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0) {
			switch ($request['Type']) {
				case 'flag-capture':
						return Success($this->create_flag_capture($request['Name'], $mundane_id, $request['Configuration']));
					break;
				default:
						return Success($this->create_game($request['Name'], 'custom', $mundane_id, $request['Configuration'], array()));
					break;
			}	
		} else {
			return NoAuthorization();
		}
	}
	
	public function AddObjective($request) {
		if (!$this->game_security($request['GameId'],$request['Code'])) return false;
		echo "pass security";
		$game = $this->GetGame(array('GameId' => $request['GameId']));
		print_r($game);
		if (count($game) == 0) return false;
		echo "add obj";
		switch ($game['Type']) {
			case 'flag-capture':
					echo "add flag-capture";
					return $this->add_flag_capture_objective($request['GameId'], $request['Name']);
				break;
			default:
					return $this->add_objective($request['GameId'], $request['Name'], $request['State']);
				break;
		}
	}
	
	public function AddTeam($request) {
		if ($this->game_security($request['GameId'],$request['Code'])) {
			$this->team->clear();
			$this->team->game_id = $request['GameId'];
			$this->team->name = $request['Name'];
			$this->team->save();
			return $this->team->game_team_id;
		}
	}
	
	public function SetGameState($request) {
		if (!$this->game_security($request['GameId'],$request['Code'])) return false;
		$g = GetGame($request);
		if (!isset($g['Type'])) return false;
		switch ($g['Type']) {
			case 'flag-capture':
					return $this->set_flag_capture_state($request['GameId'], $request['ObjectiveId'], $request['State']);
				break;
			default:
					return $this->set_game_state($request['GameId'], $request['ObjectiveId'], $request['State']);
				break;
		}
	}

	public function GetGameState($request) {
		if (valid_id($request['GameId']) && valid_id($request['ObjectiveId'])) {
			$x = $this->GetObjective($request);
		} else if (valid_id($request['GameId'])) {
			$x = $this->GetGame($request);
		} else {
			$x = array('State' => false);
		}
		return $x['State'];
	}
	
	public function GetObjectives($request) {
		$objectives = array();
		if (valid_id($request['GameId'])) {
			$this->objective->clear();
			$this->objective->game_id = $request['GameId'];
			if ($this->objective->find() && $this->objective->game_id == $request['GameId']) do {
				$objectives[] = $this->objective->game_objective_id;
			} while ($this->objective->next());
		}
		return $objectives;
	}
	
	public function GetObjective($request) {
		if (valid_id($request['ObjectiveId'])) {
			$this->objective->clear();
			$this->objective->game_objective_id = $request['ObjectiveId'];
			if ($this->objective->find() && $this->objective->game_objective_id == $request['ObjectiveId']) {
				return array(
						'ObjectiveId' => $this->objective->game_objective_id,
						'GameId' => $this->objective->game_id,
						'Name' => $this->objective->name,
						'State' => json_decode($this->objective->state, true)
					);
			}
		}
		return array();
	}
	
	public function GetGame($request) {
		if (valid_id($request['GameId'])) {
			$this->game->clear();
			$this->game->game_id = $request['GameId'];
			if ($this->game->find() && $this->game->game_id == $request['GameId']) {
				return array(
						'GameId' => $this->game->game_id,
						'Configuration' => json_decode($this->game->configuration, true),
						'Created' => $this->game->created,
						'Name' => $this->game->name,
						'Type' => $this->game->type,
						'State' => json_decode($this->game->state, true)
					);
			}
		}
		return array();
	}
	
	public function ValidateGameObjective($request) {
		if ($this->game_security($request['GameId'], $request['Code'])) {
			$this->objective->clear();
			$this->objective->game_id = $request['GameId'];
			$this->objective->game_objective_id = $request['ObjectiveId'];
			if ($this->objective->find() && $this->objective->game_id == $request['GameId'] && $this->objective->game_objective_id == $request['ObjectiveId'])
				return true;
		}
		return false;
	}
	
	public function ValidateGameCode($request) {
		return $this->game_security($request['GameId'], $request['Code']);
	}
	
	public function ValidateGameMaster($request) {
		if ($this->game_security($request['GameId'], $request['Code'])) {
			if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0) {
				$this->game->clear();
				$this->game->game_id = $request['GameId'];
				$this->game->mundane_id = $mundane_id;
				if ($this->game->find() && $this->game->game_id == $request['GameId'] && $this->game->mundane_id == $mundane_id)
					return true;
			}
		}
		return false;
	}
	
	private function create_game($name, $type, $mundane_id, $configuration, $state) {
		$this->game->clear();
		$this->game->name = $name;
		$this->game->mundane_id = $mundane_id;
		$this->game->configuration = json_encode($configuration);
		$this->game->created = date('Y-m-d H:i:s');
		$this->game->type = $type;
		$this->game->state = json_encode($state);
		$this->game->code = strtoupper(md5(microtime() . $name));
		$this->game->save();
		return array('GameId'=>$this->game->game_id, 'Code'=>$this->game->code);
	}
	

	private function add_objective($game_id, $name, $state) {
		$this->objective->clear();
		$this->objective->game_id = $game_id;
		$this->objective->name = $name;
		$this->objective->state = json_encode($state);
		$this->objective->save();
		return $this->objective->game_objective_id;
	}
	
	private function game_security($game_id, $game_code) {
		$this->game->clear();
		$this->game->game_id = $game_id;
		$this->game->code = $game_code;
		$this->game->code_term = 'like';
		if ($this->game->find() && $this->game->game_id == $game_id)
			return true;
		return false;
	}
	
	protected function set_game_state($game_id, $objective_id, $state) {
		if (valid_id($game_id) && valid_id($objective_id)) {
			$this->objective->game_objective_id = $objective_id;
			if ($this->objective->find() && $this->game->game_objective_id == $objective_id) {
				$this->objective->state = json_encode($state);
				$this->objective->save();
				return true;
			}
		} else if (valid_id($game_id)) {
			$this->game->game_id = $game_id;
			if ($this->game->find() && $this->game->game_id == $game_id) {
				$this->game->state = json_encode($state);
				$this->game->save();
				return true;
			}
		}
		return false;
	}
	
	protected function if_state($state_requirements, $state_source, $state_lookup, $if_match) {
		foreach ($state_requirements as $state_var => $value) {
			if (is_array($value)) {
				$comp = $value['Comparator'];
				if (!$comp($state_lookup($state_source, $stat_var), $value['Value'])) return true;
			}
			if ($state_lookup($state_source, $stat_var) != $value) return false;
		}
		return $if_match($state_source, $state_requirements);
	}
	

/*******************************************************************************
	
	GAME SPECIFIC CODE BELOW
	
*******************************************************************************/
	
	private function create_flag_capture($name, $mundane_id, $configuration) {
		$state = array(
				'GameStatus' => 'not-started',
				'TimeFrom' => null,
				'AccumulatedTime' => 0,
				'Winner' => 0,
				'TeamPoints' => array()
			);
		$configuration['PointOnCapture'] = ($configuration['ObjectiveTime'] <= 0)?true:false;
			
		return $this->create_game($name, 'flag-capture', $mundane_id, $configuration, $state);
	}
	
	private function add_flag_capture_objective($game_id, $name) {
		return $this->add_objective($game_id, $name, array(
			'TeamData' => array()
		));
		return 0;
	}
	
	private function flag_capture_lookup($source, $state_var) {
		expand($source);
		switch ($state_var) {
			case 'GameTime':
			case 'PointTarget':
			case 'ObjectiveTime':
			case 'Cumulative':
				return $Game['Configuration'][$state_var];
			case 'GameStatus':
			case 'TimeEnd':
				return $Game['State'][$state_var];
			case 'RunStatus':
				return $State['RunStatus'];
			case 'CurrentTeam':
					foreach ($Objective['TeamData'] as $team_id => $data) {
						if (!is_null($data['TimeStart']))
							return $team_id;
					}
			case 'TargetTeamId':
				return $State['TeamId'];
			case 'Objective':
				return count($Objective)>0;
		}
	}
	
	private function flag_capture_find_point_frontrunner($Game) {
		if ($Game['State']['Winner'] > 0)
			return $Game['State']['Winner'];
		$team_points = 0;
		$winning_team = 0;
		foreach ($Game['State']['TeamPoints'] as $team_id => $points) {
			if ($points > $team_points) {
				$team_points = $points;
				$winning_team = $team_id;
			}
		}
		return $winning_team;
	}
	
	private function set_flag_capture_state($game_id, $objective_id, $state) {
		$game = $this->GetGame(array('GameId' => $game_id));
		$obj = $this->GetObjective(array('ObjectiveId' => $objective_id));
		if (count($game) == 0) return false;
		
		$state_source = array('Game'=>$game, 'Objective'=>$obj, 'State'=>$state);
		$ret_state = false;
		/*
			Inputs
			Objective
				team_id = team_id|0
				
			Game
				RunStatus = start|hold|finished
				
			Configuration
			Game
				GameTime: number (seconds)
				PointTarget: number
				ObjectiveTime: seconds|none
				Cumulative: cumulative|reset
				PointOnTarget: true|false

			States
			Game
				GameStatus = not-started|running|hold|finished
				TimeFrom
				AccumulatedTime
				Winner
				TeamPoints
				
			Objective
				TeamData
					CumulativeTime
					TimeStart
		*/
		
		/* ----------------------------------------------------
		
		Basic CTF Game
		
		---------------------------------------------------- */
		$basic_ctf = array('PointOnTarget'=>true);
		$start_resume_game = array_merge($basic_ctf, array('Objective' => 0, 'RunStatus' => 'start'));
		$ret_state |= $this->if_state($start_resume_game, $state_source, 'flag_capture_lookup', function($src, $req) {
			expand($src);
			if (($winner = $this->flag_capture_find_point_frontrunner($Game)) > 0) 
			$Game['State']['GameStatus'] = 'running';
			$Game['State']['TimeFrom'] = time();
			set_game_state($Game['GameId'], 0, $Game['State']);
		});
		$hold_game = array_merge($basic_ctf, array('Objective' => 0, 'RunStatus' => 'hold', 'GameStatus'=>'running'));
		$ret_state |= $this->if_state($hold_game, $state_source, 'flag_capture_lookup', function($src, $req) {
			expand($src);
			$Game['State']['AccumulatedTime'] += time() - $Game['State']['TimeFrom'];
			$Game['State']['GameStatus'] = 'hold';
			set_game_state($Game['GameId'], 0, $Game['State']);
		});
		$flag_captured = array_merge($basic_ctf, array('Objective' => 1, 'PointOnTarget'=>true, 'GameStatus'=>'running'));
		$ret_state |= $this->if_state($flag_captured, $state_source, 'flag_capture_lookup', function($src, $req) {
			expand($src);
			if ((time() - $Game['State']['TimeFrom'] + $Game['State']['AccumulatedTime']) >= $Game['Configuration']['GameTime']) {
				$Game['State']['GameStatus'] = 'finished';
			}
			if ($State['TeamId'] > 0)
				$Game['State']['TeamPoints'][$State['TeamId']]++;
			set_game_state($Game['GameId'], 0, $State);
		});
		return true;
	}
	
	
}

?>