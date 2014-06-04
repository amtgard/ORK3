<?php

class Model_Heraldry extends Model {

	function __construct() {
		parent::__construct();
		$this->Report = new APIModel('Report');
	}

}

?>