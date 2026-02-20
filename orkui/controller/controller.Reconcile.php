<?php

class Controller_Reconcile extends Controller {

	public function __construct($call=null, $id=null) {
		parent::__construct($call, $id);
		if (!isset($this->session->user_id)) {
			logtrace('Header redirect: no user id', null);
			header( 'Location: '.UIR."Login" );
		} else {
			$this->load_model('Player');
			$this->load_model('Award');
			$this->data['page_title'] = 'Reconcile Historical Awards';
		}
	}

	public function index($id = null) {
		$params = explode('/', $id);
		$mundane_id = $params[0];
		$action = isset($params[1]) ? $params[1] : null;
		$action_param = isset($params[2]) ? $params[2] : null;

		if (!valid_id($mundane_id)) {
			header( 'Location: '.UIR."Admin" );
			return;
		}

		if ($action === 'reconcileall' && $_SERVER['REQUEST_METHOD'] === 'POST') {
			$awards_ids = isset($this->request->AwardsId) ? (array)$this->request->AwardsId : array();
			$errors = array();
			$success_count = 0;

			foreach ($awards_ids as $awards_id) {
				$r = $this->Player->reconcile_player_award(array(
					'Token' => $this->session->token,
					'AwardsId' => $awards_id,
					'KingdomAwardId' => isset($this->request->KingdomAwardId[$awards_id]) ? $this->request->KingdomAwardId[$awards_id] : 0,
					'CustomName' => isset($this->request->CustomName[$awards_id]) ? $this->request->CustomName[$awards_id] : '',
					'Rank' => isset($this->request->Rank[$awards_id]) ? $this->request->Rank[$awards_id] : 0,
					'Date' => isset($this->request->Date[$awards_id]) ? $this->request->Date[$awards_id] : '',
					'GivenById' => isset($this->request->GivenById[$awards_id]) ? $this->request->GivenById[$awards_id] : 0,
					'Note' => isset($this->request->Note[$awards_id]) ? $this->request->Note[$awards_id] : '',
					'ParkId' => isset($this->request->ParkId[$awards_id]) ? $this->request->ParkId[$awards_id] : 0,
					'KingdomId' => isset($this->request->KingdomId[$awards_id]) ? $this->request->KingdomId[$awards_id] : 0,
					'EventId' => isset($this->request->EventId[$awards_id]) ? $this->request->EventId[$awards_id] : 0,
				));
				if ($r['Status'] == 0 && $r['Detail'] !== false) {
					$success_count++;
				} elseif ($r['Status'] != 0) {
					$errors[] = "Award $awards_id: " . $r['Error'];
				}
			}

			if (empty($errors)) {
				$this->data['Message'] = "Successfully reconciled $success_count award(s).";
			} else {
				$this->data['Message'] = "Reconciled $success_count award(s) with " . count($errors) . " error(s): " . implode('; ', $errors);
			}

		} elseif ($action === 'autoassignranks' && valid_id($action_param)) {
			header('Content-Type: application/json');
			$r = $this->Player->auto_assign_ranks(array(
				'Token' => $this->session->token,
				'MundaneId' => $mundane_id,
				'KingdomAwardId' => $action_param,
			));
			echo json_encode(array('Status' => $r['Status'], 'Assignments' => $r['Detail']));
			exit;
		}

		$this->load_model('Park');
		$this->load_model('Kingdom');

		$player = $this->Player->fetch_player($mundane_id);
		$details = $this->Player->fetch_player_details($mundane_id);

		$park_info = $this->Park->get_park_info($player['ParkId']);
		$kingdom_id = $park_info['KingdomInfo']['KingdomId'];
		$this->session->kingdom_id = $kingdom_id;

		$this->data['Player'] = $player;
		$this->data['Details'] = $details;
		$award_kingdom_ids = array($kingdom_id);
		foreach ((array)$details['Awards'] as $a) {
			if ($a['IsHistorical'] && valid_id($a['KingdomAwardKingdomId'])) {
				$award_kingdom_ids[] = $a['KingdomAwardKingdomId'];
			}
		}
		$award_options = '';
		foreach (array_unique($award_kingdom_ids) as $kid) {
			$award_options .= $this->Award->fetch_award_option_list($kid, 'Awards');
		}
		$this->data['AwardOptions'] = $award_options;
		$this->data['KingdomId'] = $kingdom_id;

		$this->data['menu']['admin'] = array( 'url' => UIR.'Admin/player/'.$mundane_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		$this->data['menu']['player'] = array( 'url' => UIR."Player/index/$mundane_id", 'display' => $player['Persona'] );
	}

}
