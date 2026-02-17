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

	public function custom_awards($params=null) {
		$this->data['page_title'] = "Custom Awards";
		if (isset($this->request->KingdomId)) {
			$type = 'Kingdom';
			$id = $this->request->KingdomId;
			$this->data['page_title'] = "Kingdom Custom Awards";
		}
		if (isset($this->request->ParkId)) {
			$type = 'Park';
			$id = $this->request->ParkId;
			$this->data['page_title'] = "Park Custom Awards";
		}
		$this->template = 'Reports_customawards.tpl';
		$this->data['Awards'] = $this->Reports->custom_awards(array('KingdomId'=>'Kingdom'==$type?$id:0, 'ParkId'=>'Park'==$type?$id:0));
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
        $this->_peerage_waivered_duespaid('Knight', $type, true, null);
    	$this->template = 'Reports_knights.tpl';
		$this->data['page_title'] = "Active Knights";
	}

    public function masters($type=null) {
        $this->_peerage_waivered_duespaid('Master', $type, true, null);
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

	/**
	 * Generate all expected period labels between two rounded dates.
	 */
	private function _generatePeriodLabels($start, $end, $period) {
		$labels = array();
		$st = strtotime($start);
		$et = strtotime($end);
		if (!$st || !$et) return $labels;

		switch ($period) {
			case 'Weekly':
				// ISO weeks: start on Monday
				$cur = $st;
				while ($cur <= $et) {
					$labels[] = date('Y', $cur) . '-W' . str_pad(date('W', $cur), 2, '0', STR_PAD_LEFT);
					$cur = strtotime('+1 week', $cur);
				}
				break;
			case 'Monthly':
				$cur = $st;
				while ($cur <= $et) {
					$labels[] = date('Y-m', $cur);
					$cur = strtotime('+1 month', $cur);
				}
				break;
			case 'Quarterly':
				$cur = $st;
				while ($cur <= $et) {
					$q = ceil(date('n', $cur) / 3);
					$labels[] = date('Y', $cur) . '-Q' . $q;
					$cur = strtotime('+3 months', $cur);
				}
				break;
			case 'Annually':
				$cur = $st;
				while ($cur <= $et) {
					$labels[] = date('Y', $cur);
					$cur = strtotime('+1 year', $cur);
				}
				break;
		}
		return $labels;
	}

	/**
	 * Round a start date down to the beginning of its period.
	 * Round an end date up to the end of its period.
	 * Ensures full periods are always included in results.
	 */
	private function _roundDateRange($start, $end, $period) {
		$st = strtotime($start);
		$et = strtotime($end);
		if (!$st || !$et) return array($start, $end);

		switch ($period) {
			case 'Weekly':
				// ISO week: Monday start. Round start down to Monday, end up to Sunday.
				$dow_start = date('N', $st); // 1=Mon, 7=Sun
				$st = strtotime('-' . ($dow_start - 1) . ' days', $st);
				$dow_end = date('N', $et);
				$et = strtotime('+' . (7 - $dow_end) . ' days', $et);
				break;
			case 'Monthly':
				$st = strtotime(date('Y-m-01', $st));
				$et = strtotime(date('Y-m-t', $et));
				break;
			case 'Quarterly':
				$q_start = ceil(date('n', $st) / 3);
				$q_start_month = ($q_start - 1) * 3 + 1;
				$st = strtotime(date('Y', $st) . '-' . str_pad($q_start_month, 2, '0', STR_PAD_LEFT) . '-01');
				$q_end = ceil(date('n', $et) / 3);
				$q_end_month = $q_end * 3;
				$et = strtotime(date('Y', $et) . '-' . str_pad($q_end_month, 2, '0', STR_PAD_LEFT) . '-01');
				$et = strtotime(date('Y-m-t', $et)); // last day of quarter-end month
				break;
			case 'Annually':
				$st = strtotime(date('Y', $st) . '-01-01');
				$et = strtotime(date('Y', $et) . '-12-31');
				break;
		}

		return array(date('Y-m-d', $st), date('Y-m-d', $et));
	}

	public function park_attendance_explorer($params = null) {
		$this->template = 'Reports_parkattendanceexplorer.tpl';
		$this->data['page_title'] = "Park Attendance Explorer";

		$kingdom_id = $this->session->kingdom_id;
		if (!valid_id($kingdom_id)) {
			$this->data['no_kingdom'] = true;
			return;
		}

		$this->data['kingdom_id'] = $kingdom_id;
		$parks = $this->Reports->get_kingdom_parks($kingdom_id);
		if (is_array($parks)) {
			usort($parks, function($a, $b) { return strcasecmp($a['Name'], $b['Name']); });
		}
		$this->data['parks'] = $parks;

		// Only run report on form submission
		if (!isset($this->request->RunReport)) return;

		$park_id = intval($this->request->ParkId);
		$period = $this->request->Period;
		if (!in_array($period, array('Weekly', 'Monthly', 'Quarterly', 'Annually'))) {
			$period = 'Monthly';
		}

		// Round dates to encompass full periods
		list($rounded_start, $rounded_end) = $this->_roundDateRange(
			$this->request->StartDate, $this->request->EndDate, $period
		);

		$form = array(
			'KingdomId' => $kingdom_id,
			'StartDate' => $this->request->StartDate,
			'EndDate' => $this->request->EndDate,
			'Period' => $period,
			'MinimumSignIns' => intval($this->request->MinimumSignIns),
			'ParkId' => $park_id,
			'AvgByUniques' => isset($this->request->AvgByUniques) ? 1 : 0,
			'LocalPlayersOnly' => isset($this->request->LocalPlayersOnly) ? 1 : 0
		);
		$this->data['form'] = $form;

		if ($park_id > 0) {
			// Single Park mode — player-by-period pivot table
			$result = $this->Reports->park_attendance_single_park(array(
				'KingdomId' => $kingdom_id,
				'ParkId' => $park_id,
				'StartDate' => $rounded_start,
				'EndDate' => $rounded_end,
				'Period' => $period,
				'MinimumSignIns' => $form['MinimumSignIns'],
				'LocalPlayersOnly' => $form['LocalPlayersOnly']
			));
			$this->data['mode'] = 'single_park';

			if (is_array($result)) {
				$pivoted = array();
				// Pre-generate all expected period columns from the date range
				$all_periods = $this->_generatePeriodLabels($rounded_start, $rounded_end, $period);
				foreach ($result as $row) {
					if (empty($row['PeriodLabel']) || empty($row['Persona'])) continue;
					$mid = $row['MundaneId'];
					if (!isset($pivoted[$mid])) {
						$pivoted[$mid] = array(
							'MundaneId' => $row['MundaneId'],
							'Persona' => $row['Persona'],
							'Waivered' => $row['Waivered'],
							'DuesPaid' => $row['DuesPaid'],
							'Periods' => array(),
							'Total' => 0
						);
					}
					$pivoted[$mid]['Periods'][$row['PeriodLabel']] = $row['SignInCount'];
					$pivoted[$mid]['Total'] += $row['SignInCount'];
				}
				$this->data['all_periods'] = $all_periods;
				// Sort players by persona
				usort($pivoted, function($a, $b) {
					return strcasecmp($a['Persona'], $b['Persona']);
				});
				$this->data['players'] = $pivoted;
			}
		} else {
			// All Parks mode — one row per park per period
			$result = $this->Reports->park_attendance_all_parks(array(
				'KingdomId' => $kingdom_id,
				'StartDate' => $rounded_start,
				'EndDate' => $rounded_end,
				'Period' => $period
			));
			$this->data['mode'] = 'all_parks';

			if (is_array($result)) {
				$this->data['attendance'] = $result;

				// Compute kingdom summary
				$summary = array(
					'TotalSignins' => 0,
					'UniquePlayers' => 0,
					'UniqueMembers' => 0,
					'Members2Plus' => 0,
					'Members3Plus' => 0,
					'Members4Plus' => 0,
					'WeeksInPeriod' => 0,
					'MonthsInPeriod' => 0,
					'RowCount' => count($result)
				);
				foreach ($result as $row) {
					$summary['TotalSignins'] += $row['TotalSignins'];
					$summary['UniquePlayers'] += $row['UniquePlayers'];
					$summary['UniqueMembers'] += $row['UniqueMembers'];
					$summary['Members2Plus'] += $row['Members2Plus'];
					$summary['Members3Plus'] += $row['Members3Plus'];
					$summary['Members4Plus'] += $row['Members4Plus'];
					if ($row['WeeksInPeriod'] > $summary['WeeksInPeriod'])
						$summary['WeeksInPeriod'] = $row['WeeksInPeriod'];
					if ($row['MonthsInPeriod'] > $summary['MonthsInPeriod'])
						$summary['MonthsInPeriod'] = $row['MonthsInPeriod'];
				}
				$this->data['summary'] = $summary;
			}
		}
	}

}

?>
