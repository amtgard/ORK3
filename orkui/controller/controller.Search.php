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
		if (isset($this->request->KingdomId)) $this->data['KingdomId'] = $this->request->KingdomId;
		if (isset($this->request->ParkId)) $this->data['ParkId'] = $this->request->ParkId;
	}
	
	public function event() {
		if (isset($this->request->KingdomId)) $this->data['KingdomId'] = $this->request->KingdomId;
		if (isset($this->request->ParkId)) $this->data['ParkId'] = $this->request->ParkId;
	}
	
	public function tournament() {
	
	}
}
?>