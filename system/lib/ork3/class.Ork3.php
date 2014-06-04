<?php

class Ork3 {
	var $db;
	var $log;
	var $namespace;
	var $endpoint;

	static $Lib; //= $GLOBALS['ORK3_SYSTEM'];

	public function __construct() {
		global $DB;
		global $LOG;
		$this->log = $LOG;
		$this->db = $DB;
		$this->namespace = ORK3_SERVICE_URL . get_class($this).'/'.get_class($this).'Service.php?wsdl';
	}
	
}

class Ork3LibContainer {
	public function __construct() {
	
	}
	
	public function __set($name, $value) {
		$this->$name = $value;
	}
}

?>