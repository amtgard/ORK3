<?php

class Controller_Reports extends Controller {


	public function __construct($call=null, $method=null) {
		parent::__construct($call, $method);
		$this->data['menu']['reports'] = array( 'url' => UIR.'Reports', 'display' => 'Reports' );
		$this->data[ 'no_index' ] = true;
	}

	public function index($action = null) {

	}

	function parkheraldry($kingdom_id=null) {
		$this->template = 'Reports_heraldry.tpl';
		$this->data['Blank'] = HERALDRY_PARK_DEFAULT;
		$this->data['Heraldry'] = $this->Reports->get_heraldry_report(array(
										'Type' => 'Park',
										'KingdomId' => $kingdom_id
									));
		$this->data['page_title'] = "Park Heraldry";
	}

	function kingdomheraldry($request=null) {
		$this->template = 'Reports_heraldry.tpl';
		$this->data['Blank'] = HERALDRY_KINGDOM_DEFAULT;
		$this->data['Heraldry'] = $this->Reports->get_heraldry_report(array(
										'Type' => 'Kingdom'
									));
		$this->data['page_title'] = "Kingdom Heraldry";
	}

	function playerheraldry($kingdom_id=null) {
		$this->template = 'Reports_heraldry.tpl';
		$this->data['Blank'] = HERALDRY_PLAYER_DEFAULT;
		$this->data['Heraldry'] = $this->Reports->get_heraldry_report(array(
										'Type' => 'Mundane',
										'KingdomId' => $kingdom_id,
										'ParkId' => $this->request->ParkId
									));
		$this->data['page_title'] = "Player Heraldry";
	}

	function unitheraldry($request=null) {
		$this->template = 'Reports_heraldry.tpl';
		$this->data['Blank'] = HERALDRY_UNIT_DEFAULT;
		$this->data['Heraldry'] = $this->Reports->get_heraldry_report(array(
										'Type' => 'Unit'
									));
		$this->data['page_title'] = "Unit Heraldry";
	}

	function eventheraldry($kingdom_id=null) {
		$this->template = 'Reports_heraldry.tpl';
		$this->data['Blank'] = HERALDRY_EVENT_DEFAULT;
		$this->data['Heraldry'] = $this->Reports->get_heraldry_report(array(
										'Type' => 'Event',
										'KingdomId' => $kingdom_id
									));
		$this->data['page_title'] = "Event Heraldry";
	}

	public function guilds($param=null) {
		if (isset($this->request->KingdomId)) {
			$type = 'Kingdom';
			$id = $this->request->KingdomId;
		}
		if (isset($this->request->ParkId)) {
			$type = 'Park';
			$id = $this->request->ParkId;
		}
		$this->data['Guilds'] = $this->Reports->guilds(array('KingdomId'=>'Kingdom'==$type?$id:0, 'ParkId'=>'Park'==$type?$id:0, 'ReportFromDate'=>date('Y-m-d'), 'PerMonths'=>1, 'Periods'=>6, 'MinimumAttendanceRequirement'=>1));
	}

	public function player_awards($params=null) {
		$this->data[ 'page_title' ] = "All Awards";
		if (isset($this->request->KingdomId)) {
			$type = 'Kingdom';
			$id = $this->request->KingdomId;
			$this->data[ 'page_title' ] = "Kingdom Awards";
		}
		if (isset($this->request->ParkId)) {
			$type = 'Park';
			$id = $this->request->ParkId;
			$this->data[ 'page_title' ] = "Park Awards";
		}
		if (isset($this->request->Ladder))
			$ladder = $this->request->Ladder;
		$this->template = 'Reports_playerawards.tpl';
		$this->data['Awards'] = $this->Reports->kingdom_awards(array('KingdomId'=>'Kingdom'==$type?$id:0, 'ParkId'=>'Park'==$type?$id:0, 'IncludeKnights' => 1, 'IncludeMasters' => 1, 'IncludeLadder' => 1, 'LadderMinimum' => $ladder));
	}

	public function player_award_recommendations($params=null) {
		if (isset($this->request->KingdomId)) {
			$type = 'Kingdom';
			$id = $this->request->KingdomId;
		}
		if (isset($this->request->ParkId)) {
			$type = 'Park';
			$id = $this->request->ParkId;
		}
		if (isset($this->request->Ladder))
			$ladder = $this->request->Ladder;
		$this->template = 'Reports_playerawardrecommendations.tpl';
		$this->data['AwardRecommendations'] = $this->Reports->recommended_awards(array('KingdomId'=>'Kingdom'==$type?$id:0, 'ParkId'=>'Park'==$type?$id:0, 'IncludeKnights' => 1, 'IncludeMasters' => 1, 'IncludeLadder' => 1, 'LadderMinimum' => $ladder));
		$this->data[ 'page_title' ] = "Award Recommendations";
	}

	public function class_masters($params=null) {
		if (isset($this->request->KingdomId)) {
			$type = 'Kingdom';
			$id = $this->request->KingdomId;
			$this->data[ 'page_title' ] = "Kingdom Class Masters/Paragons";
		}
		if (isset($this->request->ParkId)) {
			$type = 'Park';
			$id = $this->request->ParkId;
			$this->data[ 'page_title' ] = "Park Class Masters/Paragons";
		}
		$this->template = 'Reports_classmasters.tpl';
		$this->data['Awards'] = $this->Reports->class_masters(array('KingdomId'=>'Kingdom'==$type?$id:0, 'ParkId'=>'Park'==$type?$id:0));
	}

  public function _kam($params, $template, $knights, $masters) {
		if (isset($this->request->KingdomId)) {
			$type = 'Kingdom';
			$id = $this->request->KingdomId;
		}
		if (isset($this->request->ParkId)) {
			$type = 'Park';
			$id = $this->request->ParkId;
		}
		$this->template = $template;
		$this->data['Awards'] = $this->Reports->kingdom_awards(array('KingdomId'=>'Kingdom'==$type?$id:0, 'ParkId'=>'Park'==$type?$id:0, 'IncludeKnights' => $knights, 'IncludeMasters' => $masters));

		if ($type == 'Kingdom' && valid_id($id) && $masters) {
			$cqual = $this->Reports->crown_qualed(array('KingdomId'=>$id));
			if (is_array($cqual)) {
				$this->data['Awards'] = array_merge($cqual, $this->data['Awards']);
			}
		}
  }
  
	public function masters_list($params=null) {
    $this->_kam($params, 'Reports_kam.tpl', 0, 1);
	$this->data[ 'page_title' ] = "Masters List";
	}

	public function knights_list($params=null) {
    $this->_kam($params, 'Reports_kam.tpl', 1, 0);
	$this->data[ 'page_title' ] = "Knights List";
	}

	public function knights_and_masters($params=null) {
    $this->_kam($params, 'Reports_kam.tpl', 1, 1);
	$this->data[ 'page_title' ] = "Knights & Masters List";
	}

	public function attendance($params) {
		$params = explode('/',$params);
		$type = 'Park';
		$id = 1;
		$period = 'Week';
		$num_periods = 1;
		if (count($params) > 0)
			$type = $params[0];
		if (count($params) > 1)
			$id = $params[1];
		if (count($params) > 2)
			$period = $params[2];
		if (count($params) > 3)
			$num_periods = $params[3];
		$this->data['attendance_summary'] = $this->Reports->get_attendance_summary($type, $id, $period, $num_periods);
		$this->data['attendance_periodical'] = $this->Reports->get_periodical_summary($type, $id, $period, $num_periods, 'week');
		$this->data['Type'] = $type;
	}

    private function kingdom_config($type) {
        $this->load_model('Kingdom');
        switch ($type) {
            case 'Kingdom':
                    $kingdom_config = $this->Kingdom->get_kingdom_details($this->request->id);
                break;
            case 'Park':
                    $this->load_model('Park');
                    $park_info = $this->Park->get_park_info($this->request->id);
                    $kingdom_config = $this->Kingdom->get_kingdom_details($park_info['ParkInfo']['KingdomId']);
                break;
        }
        return $kingdom_config;
    }

	public function active($type=null) {
        $kingdom_config = $this->kingdom_config($type);
		$this->data['page_title'] ="Active Player Roster";
        $this->data['active_players'] = $this->Reports->active_players(
                $type,
                $this->request->id,
                null,
                null,
                $kingdom_config['KingdomConfiguration']['AttendanceMinimum']['Value'],
                $kingdom_config['KingdomConfiguration']['AttendanceCreditMinimum']['Value']);
	}

	public function active_waivered_duespaid($type=null) {
        $this->_peerage_waivered_duespaid(null, $type);
		$this->data['page_title'] ="Active Waivered Dues Paid";
	}

    public function active_duespaid($type=null) {
        $this->_peerage_waivered_duespaid(null, $type, true, null);
		$this->data['page_title'] ="Active Dues Paid";
	}

    public function knights($type=null) {
        $this->_peerage_waivered_duespaid('Knight', $type, false, null);
    	$this->template = 'Reports_knights.tpl';
		$this->data['page_title'] = "Active Knights";
	}

    public function masters($type=null) {
        $this->_peerage_waivered_duespaid('Master', $type, false, null);
    	$this->template = 'Reports_masters.tpl';
		$this->data['page_title'] ="Active Masters";
	}

    private function _peerage_waivered_duespaid($peerage, $type=null, $dues=true, $waivered=true) {
        $kingdom_config = $this->kingdom_config($type);
    	$this->data['activewaivereduespaid'] = true;
		$this->template = 'Reports_active.tpl';
		$this->data['active_players'] = $this->Reports->active_players(
            $type,
            $this->request->id,
            null,
            null,
            $kingdom_config['KingdomConfiguration']['AttendanceWeeklyMinimum']['Value'],
            $kingdom_config['KingdomConfiguration']['AttendanceCreditMinimum']['Value'],
            $dues,
            $waivered,
            $kingdom_config['KingdomConfiguration']['AttendanceDailyMinimum']['Value'],
            $kingdom_config['KingdomConfiguration']['MonthlyCreditMaximum']['Value'],
            $peerage);
    }

	public function roster($type=null) {
		$this->data['roster'] = $this->Reports->player_roster($type, $this->request->id, null, 0, 0, 1);
		$this->data['page_title'] ="Player Roster";
	}

	public function reeve($type=null) {
		$this->template = 'Reports_reeve.tpl';
		$this->data['reeve_qualified'] = $this->Reports->reeve_qualified($this->request->KingdomId, $this->request->ParkId, null, 0, 0, 1);
		$this->data['page_title'] ="Reeve Qualified";
	}
	public function corpora($type=null) {
		$this->template = 'Reports_corpora.tpl';
		$this->data['corpora_qualified'] = $this->Reports->corpora_qualified($this->request->KingdomId, $this->request->ParkId, null, 0, 0, 1);
		$this->data['page_title'] ="Corpora Qualified";
	}

	public function inactive($type=null) {
		$this->data['roster'] = $this->Reports->player_roster($type, $this->request->id, null, 0, 0, 0);
		$this->data['page_title'] ="Inactive Player Roster";
	}

	public function waivered($type=null) {
		$this->template = 'Reports_roster.tpl';
		$this->data['roster'] = $this->Reports->player_roster($type, $this->request->id, 1);
		$this->data['page_title'] ="Waivered Player Roster";
	}

	public function unwaivered($type=null) {
		$this->template = 'Reports_roster.tpl';
		$this->data['roster'] = $this->Reports->player_roster($type, $this->request->id, 0);
		$this->data['page_title'] ="Unwaivered Player Roster";
	}

	/* Old broken dues functionality */
	public function duespaid($type=null) {
		$this->template = 'Reports_roster.tpl';
		$this->data['show_duespaid'] = 1;
		$this->data['roster'] = $this->Reports->player_roster($type, $this->request->id, null, 1, 0, 2);
	}

	/* New Cooler Dues functionality */
	public function dues($type=null) {
		// TODO: totally dupe and change up this template
		$this->template = 'Reports_dues.tpl';
		$this->data['roster'] = $this->Reports->dues_paid_list($type, $this->request->id);
		$this->data['page_title'] ="Dues Paid List";

	}

	public function suspended($type=null) {
		$this->template = 'Reports_roster.tpl';
		$this->data['show_suspension'] = 1;
		$this->data['roster'] = $this->Reports->player_roster($type, $this->request->id, null, null, null, 2, 1);
		$this->data['page_title'] ="Suspended Player Roster";
	}

}

?>
