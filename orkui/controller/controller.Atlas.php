<?php

class Controller_Atlas extends Controller {


    public function __construct($call=null, $method=null) {
		parent::__construct($call, $method);
		$this->Map = new APIModel('Map');
	}
	
	public function index() {
    	$this->data['Parks'] = $this->Map->GetParkLocations(array('KingdomId' => $kingdom_id));
	}
    
    public function map($kingdom_id = null) {
		$this->data['Parks'] = $this->Map->GetParkLocations(array('KingdomId' => $kingdom_id));
	}

}