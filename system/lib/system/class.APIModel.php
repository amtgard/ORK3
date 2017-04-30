<?php

//require_once(DIR_LIB.'nusoap/nusoap.php');

class APIModel {
	
	var $APISource;
	var $namespace;
	var $endpoint;
	var $client;
	var $model;
	
	function __construct($APISource) {
		$this->APISource = $APISource;
//		require_once(DIR_SERVICE.$APISource.'/'.$APISource.'Service.php');
		if ('REMOTE' == UI_LOCALITY) {
			$this->namespace = ORK3_SERVICE_URL.$APISource.'/'.$APISource.'Service.php?wsdl';
			$this->endpoint = ORK3_SERVICE_URL.$APISource.'/'.$APISource.'Service.php?wsdl';
			$this->client = new nusoap_client($this->namespace, true);
			$this->client->setDebugLevel(10);
		} else {
			$this->model = new $APISource;
		}
	}
	
	function __call($method, $args) {
		$logargs = array($args, $_SESSION, $_SERVER);
		array_walk_recursive($logargs, "clip_array_values");
		Ork3::$Lib->Log->Write('AM:' . get_class($this->model) . '::' . $method . '()', -1, LOG_EDIT, $logargs);
		if ('REMOTE' == UI_LOCALITY) {
			print_r(array($method, $args[0]));
			$r = $this->client->call($this->APISource . "." . $method, $args[0]);
			if ($this->client->fault) {
				echo "Client Fault: [" . $this->client->fault . "] ";
				print_r($r);
				echo $this->client->getDebug();
				die();
			} else if (($err = $this->client->getError()) !== false) {
				die("SOAP Communication Error: " . $err);
			} else {
				return $r;
			}
		} else {
			return call_user_func_array(array($this->model, $method), $args);
		}
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