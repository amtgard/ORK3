<?php

class Controller_Principality extends Controller {

	public function __construct($call=null, $id=null) {
		parent::__construct($call, $id);
		
		$this->load_model('Park');
		$this->load_model('Kingdom');
		
		if ($id != $this->session->principality_id) {
			unset($this->session->principality_name);
			unset($this->session->principality_id);
		}
		
		$this->data['principality_id'] = $id;
		$this->session->principality_id = $id;
		
		$principality = $this->Principality->get_principality_shortinfo($id);
		
		$this->session->kingdom_id = $principality['Info']['KingdomInfo']['KingdomId'];
		$this->session->kingdom_name = $principality['Info']['KingdomInfo']['KingdomName'];
		$this->session->principality_name = $principality['Info']['ParkInfo']['ParkName'];

		
		unset($this->session->park_id);
		unset($this->session->park_name);
		$this->data['principality_name'] = $this->session->principality_name;
		$this->data['menu']['admin'] = array( 'url' => UIR.'Admin/kingdom/'.$this->session->kingdom_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		$this->data['menu']['kingdom'] = array( 'url' => UIR.'Kingdom/index/'.$this->session->kingdom_id, 'display' => $this->session->kingdom_name );
		$this->data['menu']['principality'] = array( 'url' => UIR.'Principality/index/'.$this->session->principality_id, 'display' => $this->session->principality_name );
		$this->data['menulist']['admin'] = array(
				array( 'url' => UIR.'Admin/kingdom/'.$this->session->kingdom_id, 'display' => 'Principality' )
			);
		unset($this->data['menu']['park']);
	}
	
	public function index($principality_id = null) {
		$this->load_model('Reports');
		$this->data['park_summary'] = $this->Principality->get_park_summary($principality_id);
		$this->data['event_summary'] = $this->Principality->get_kingdom_events($principality_id);
		$this->data['principality_info'] = $this->Principality->get_principality_shortinfo($principality_id);
		$this->data['kingdom_tournaments'] = $this->Reports->get_tournaments(null, $principality_id);
		logtrace("index($kingdom_id = null)", $this->data['kingdom_tournaments']);
	}

}

?>