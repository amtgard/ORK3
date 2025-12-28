<?php

class Controller_Event extends Controller {

	public function __construct($call=null, $id=null) {
		parent::__construct($call, $id);
		
		$this->load_model('Park');
		$this->load_model('Kingdom');
		
		$params = explode('/',$id);
		$event_id = $params[0];
		
		$this->data['EventDetails'] = $this->Event->get_event_details($event_id);
		if ($this->data['EventDetails']['Status']['Status'] != 0) {
			$this->data['Error'] = $this->data['EventDetails']['Status']['Error'];
		}
		$this->data[ 'page_title' ] = $this->data['EventDetails']['Name'];
		
		if (valid_id($this->data['EventDetails']['KingdomId']))
			$this->data['menu']['kingdom'] = array( 'url' => UIR.'Kingdom/index/'.$this->data['EventDetails']['KingdomId'], 'display' => $this->data['EventDetails']['EventInfo'][0]['KingdomName'] );
		if (valid_id($this->data['EventDetails']['ParkId']))
			$this->data['menu']['park'] = array( 'url' => UIR.'Park/index/'.$this->data['EventDetails']['ParkId'], 'display' => $this->data['EventDetails']['EventInfo'][0]['ParkName'] );
			$this->data['menu']['event'] = array( 'url' => UIR.'Event/index/'.$id, 'display' => $this->data['EventDetails']['Name'] );
			if ($this->data['LoggedIn']) {
				$this->data['menu']['admin'] = array( 'url' => UIR.'Admin/event/'.$id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
			}
			$this->data['menulist']['admin'] = array(
				array( 'url' => UIR.'Admin/event/'.$id, 'display' => 'Event' )
			);
	}

	public function index($event_id = null) {
		$this->data['EventDetails'] = $this->Event->get_event_details($event_id);
		if ($this->data['EventDetails']['Status']['Status'] != 0) {
			$this->data['Error'] = $this->data['EventDetails']['Status']['Error'];
		}
		if ($this->request->exists('Admin_event')) {
			$this->data['Admin_event'] = $this->request->Admin_event->Request;
		}
	}
	
}

?>