<?php

class APIModel {
	
	var $APISource;
	var $namespace;
	var $endpoint;
	var $client;
	var $model;
	
	function __construct($APISource) {
		$this->model = new $APISource;
	}
	
	function __call($method, $args) {
		$logargs = array($args, $_SESSION, $_SERVER);
		return call_user_func_array(array($this->model, $method), $args);
	}
	
}


function clip_array_values(&$value, $key) {
	if (is_object($value)) {
		$value = (array)$value;
		array_walk_recursive($value, "clip_array_values");
	} else {
		$value = substr($value, 0, 500); 
		if (stristr($key, 'password')) $value = "PASSWORD"; 
		if (stristr($key, 'HTTP_COOKIE')) $value = "HTTP_COOKIE"; 
		if (stristr($key, 'REMOTE_ADDR')) $value = "REMOTE_ADDR"; 
	}
}
	

?>