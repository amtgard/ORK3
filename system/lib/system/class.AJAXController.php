<?php

include_once(DIR_SYSTEMLIB.'class.Controller.php');

class AJAXController extends Controller {

	public function __construct($request=null, $action=null) {
		parent::__construct($request, $action);
	}
	
	public function index($action=null) {
	
	}
	
	public function view() {
		return json_encode($this->data);
	}
}

?>