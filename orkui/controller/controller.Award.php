<?php

class Controller_Award extends Controller
{

	public function __construct($call = null, $id = null)
	{
		parent::__construct($call, $id);

		$this->load_model('Player');
		$this->load_model('Park');
		$this->load_model('Kingdom');
		$params = explode('/', $id);
		$id = $params[0];

		switch ($call) {
			case 'park':
				$park_info = $this->Park->get_park_info($id);
				$this->park_info = $park_info;
				$this->session->park_name = $park_info['ParkInfo']['ParkName'];
				$this->session->park_id = $park_info['ParkInfo']['ParkId'];
				$this->data['menu']['park'] = array('url' => UIR . 'Park/index/' . $this->session->park_id, 'display' => $this->session->park_name);
				$id = $park_info['KingdomInfo']['KingdomId'];
			case 'kingdom':
				$kingdom_info = $this->Kingdom->get_kingdom_details($id);
				$this->kingdom_info = $kingdom_info;
				$this->session->kingdom_id = $kingdom_info['KingdomInfo']['KingdomId'];
				$this->session->kingdom_name = $kingdom_info['KingdomInfo']['KingdomName'];
				$this->data['menu']['kingdom'] = array('url' => UIR . 'Kingdom/index/' . $this->session->kingdom_id, 'display' => $this->session->kingdom_name);
				$this->data['KingdomId'] = $id;
		}
		$this->data['Call'] = $call;
	}

	public function index($action = null) {}

	public function park($id)
	{
		$this->handle_award_route($id, 'park');
	}

	public function kingdom($id)
	{
		$this->handle_award_route($id, 'kingdom');
	}

	private function handle_award_route($id, $type)
	{
		$params = explode('/', $id);
		$id = $params[0];
		$action = null;

		if (count($params) > 1) {
			$action = $params[1];
		}

		if (strlen($action) > 0) {
			$this->handle_action($action, "Login/login/Award/$type/$id");
		}

		$this->set_template();
		$this->set_award_data($id);
	}

	private function handle_action($action, $route)
	{
		$this->request->save('Award_addawards', true);
		$r = array('Status' => 0);
		if (!isset($this->session->user_id)) {
			header('Location: ' . UIR . $route);
		} else {
			if ($action == 'addaward' && $this->is_request_valid()) {
				$r = $this->add_award();
			}

			if ($r['Status'] == 0) {
				$this->handle_success();
			} else if ($r['Status'] == 5) {
				header('Location: ' . UIR . $route);
			} else {
				$this->data['Error'] = $r['Error'] . ':<p>' . $r['Detail'];
			}
		}
	}
	private function is_request_valid()
	{
		if (!valid_id($this->request->Award_addawards->MundaneId)) {
			$this->data['Error'] = 'You must choose a recipient. Award not added!';
			return false;
		}
		if (!valid_id($this->request->Award_addawards->AwardId)) {
			$this->data['Error'] = 'You must choose an award. Award not added!';
			return false;
		}
		if (!valid_id($this->request->Award_addawards->GivenById)) {
			$this->data['Error'] = 'Who gave this award? Award not added!';
			return false;
		}
		return true;
	}
	private function add_award()
	{
		return $this->Player->add_player_award(array(
			'Token' => $this->session->token,
			'RecipientId' => $this->request->Award_addawards->MundaneId,
			'KingdomAwardId' => $this->request->Award_addawards->AwardId,
			'Rank' => $this->request->Award_addawards->Rank,
			'Date' => $this->request->Award_addawards->Date,
			'GivenById' => $this->request->Award_addawards->GivenById,
			'Note' => $this->request->Award_addawards->Note,
			'CustomName' => $this->request->Award_addawards->AwardName,
			'ParkId' => valid_id($this->request->Award_addawards->ParkId) ? $this->request->Award_addawards->ParkId : 0,
			'KingdomId' => valid_id($this->request->Award_addawards->KingdomId) ? $this->request->Award_addawards->KingdomId : 0,
			'EventId' => valid_id($this->request->Award_addawards->EventId) ? $this->request->Award_addawards->EventId : 0
		));
	}
	private function handle_success()
	{
		$this->data['Message'] = 'Award recorded for ' . $this->request->Award_addawards->GivenTo;
		$this->request->clear('Player_index');
		unset($_REQUEST['MundaneId']);
		unset($_REQUEST['AwardId']);
		unset($_REQUEST['Rank']);
		unset($_REQUEST['Note']);
		unset($_REQUEST['GivenTo']);
		$this->request->save('Award_addawards', true);
	}
	private function set_template()
	{
		$this->template = 'Award_addawards.tpl';
	}
	private function set_award_data($id)
	{
		if ($this->request->exists('Award_addawards')) {
			$this->data['Award_addawards'] = $this->request->Award_addawards->Request;
		}
		$this->data['AwardOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Awards');
		$this->data['OfficerOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Officers');
		$this->data['Id'] = $id;
	}
}
