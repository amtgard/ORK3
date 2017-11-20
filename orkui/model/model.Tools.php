<?php

class Model_Tools extends Model {

	function __construct() {
		parent::__construct();
		$this->Tools = new APIModel('Tools');
	}
	
}

?>