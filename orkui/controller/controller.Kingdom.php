<?php

class Controller_Kingdom extends Controller {

	public function __construct($call=null, $id=null) {
		parent::__construct($call, $id);
		
		if ($id != $this->session->kingdom_id) {
			unset($this->session->kingdom_id);
			unset($this->session->kingdom_name);
			unset($this->session->park_name);
			unset($this->session->park_id);
		}
		
		$this->data['kingdom_id'] = $id;
		$this->session->kingdom_id = $id;
		
		if (isset($this->request->kingdom_name)) {
			$this->session->kingdom_name = $this->request->kingdom_name;
		} else if (!isset($this->session->kingdom_name)) {
			// Direct link
			$this->session->kingdom_name = $this->Kingdom->get_kingdom_name($id);
		}
		$this->data['kingdom_name'] = $this->session->kingdom_name;
		
		unset($this->session->park_id);
		unset($this->session->park_name);
		$this->data['menu']['admin'] = array( 'url' => UIR.'Admin/kingdom/'.$this->session->kingdom_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		$this->data['menu']['kingdom'] = array( 'url' => UIR.'Kingdom/index/'.$this->session->kingdom_id, 'display' => $this->session->kingdom_name );
		$this->data['menulist']['admin'] = array(
				array( 'url' => UIR.'Admin/kingdom/'.$this->session->kingdom_id, 'display' => 'Kingdom' )
			);
		unset($this->data['menu']['park']);
	}
	
	public function index($kingdom_id = null) {
		$this->load_model('Reports');
		$this->data['park_summary'] = $this->Kingdom->get_park_summary($kingdom_id);
		$this->data['principalities'] = $this->Kingdom->get_principalities($kingdom_id);
		$this->data['event_summary'] = $this->Kingdom->get_kingdom_events($kingdom_id);
		$this->data['kingdom_info'] = $this->Kingdom->get_kingdom_shortinfo($kingdom_id);
		$this->data['IsPrinz'] = $this->data['kingdom_info']['Info']['KingdomInfo']['IsPrincipality'];
		$this->data['kingdom_tournaments'] = $this->Reports->get_tournaments(null, $kingdom_id);
		logtrace("index($kingdom_id = null)", $this->data['kingdom_tournaments']);
	}
	
	public function map($kingdom_id = null) {
		if (valid_id($kingdom_id)) {
      $all_parks = $this->Kingdom->GetParks(array('KingdomId' => $kingdom_id));
      $all_parks['Parks'] = array_filter(
        $all_parks['Parks'],
        function ($park) {
          return $park['Active'] == 'Active';
        }
      );
      $this->data['Parks'] = $all_parks;
		}
	}

}

?>