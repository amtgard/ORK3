<?php
class Controller_Search extends Controller {


	public function __construct($call=null, $id=null) {
		parent::__construct($call, $id);
		if ($this->data['LoggedIn']) {
			$this->data['menu']['admin'] = array( 'url' => UIR.'Admin/park/'.$this->session->park_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		}
	}
	
	public function index($id=null) {

	}
	
	public function park($id=null) {
		$this->template = 'Search_index.tpl';
		$this->data['ParkId'] = $id;
	}
	
	public function kingdom($id=null) {
		$this->template = 'Search_index.tpl';
		$this->data['KingdomId'] = $id;
	}
	
	public function unit() {
		header('X-Robots-Tag: noindex, nofollow');
		if (isset($this->request->KingdomId)) $this->data['KingdomId'] = $this->request->KingdomId;
		if (isset($this->request->ParkId)) $this->data['ParkId'] = $this->request->ParkId;
	}

	public function unitsearch() {
		header('Content-Type: application/json');
		$this->load_model('Unit');
		$name       = trim($_GET['q'] ?? '');
		$kingdom_id = valid_id($_GET['KingdomId'] ?? 0) ? (int)$_GET['KingdomId'] : null;
		$park_id    = valid_id($_GET['ParkId']    ?? 0) ? (int)$_GET['ParkId']    : null;
		$is_default = strlen($name) === 0;
		$result = $this->Unit->get_unit_list([
			'Name'              => $name,
			'KingdomId'         => $kingdom_id,
			'ParkId'            => $park_id,
			'IncludeCompanies'  => 1,
			'IncludeHouseHolds' => 1,
			'IncludeEvents'     => 1,
			'Limit'             => 250,
			'OrderBy'           => $is_default ? 'active_member_count DESC' : 'u.name',
		]);
		echo json_encode($result['Units'] ?? []);
		exit;
	}
	
	public function event() {
		if (isset($this->request->KingdomId)) $this->data['KingdomId'] = $this->request->KingdomId;
		if (isset($this->request->ParkId)) $this->data['ParkId'] = $this->request->ParkId;
	}
	
	public function tournament() {
	
	}
}
?>