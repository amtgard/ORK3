<?php

class Controller_Authorization extends Controller {


	public function __construct($call=null, $method=null) {
		parent::__construct($call, $method);
		$this->Authorization = new APIModel('Authorization');
	}
	
	public function index($action = null) {

	}

	public function add_auth($request) {
		return $this->Authorization->AddAuthorization($request);
	}
	
	public function del_auth($request) {
		return $this->Authorization->RemoveAuthorization($request);
	}
}