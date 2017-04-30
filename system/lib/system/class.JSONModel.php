<?php

//require_once(DIR_LIB.'nusoap/nusoap.php');

class JSONModel {
	
	var $JSONSource;
	var $namespace;
	var $endpoint;
	var $client;
	var $model;
	
	function __construct($JSONSource) {
		$this->JSONSource = $JSONSource;
		require_once(DIR_SERVICE.$JSONSource.'/'.$JSONSource.'Service.php');
		if ('REMOTE' == UI_LOCALITY) {
			$this->endpoint = HTTP_SERVICE.$JSONSource.'/'.$JSONSource.'Service.php';
		} else {
			$m = $JSONSource.'Service';
			$this->model = new $m;
		}
	}
	
	function __call($method, $args) {
		$method = implode('/',explode('_',$method));
		if ('REMOTE' == UI_LOCALITY) {
			// This ... it does not work
			$params = file($this->endpoint.'?Action=Reflection/Parameters&Method='.$method);
			return $params;
		} else {
			$method = array_slice(explode('/',$method),-1);
			return call_user_func_array(array($this->model, $method[0]), $args);
		}
	}
	
}

?>