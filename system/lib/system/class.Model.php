<?php

class Model {
 
	var $session;
	var $settings;
	var $__class_name;
	
	function __construct() {
		global $Settings, $Session;
		$this->session = $Session;
		$this->settings = $Settings;
		$class_name = explode('_', get_class($this));
		$this->__class_name = $class_name[1];
		$class_name = $this->__class_name;
		if (file_exists(DIR_ORK3 . "class.$class_name.php")) {
			$this->$class_name = new APIModel($class_name);
		}
	}
	
	function __call($method, $args) {
		$class_name = $this->__class_name;
		return call_user_func_array(array($this->$class_name, $method), $args);
	}
}

?>