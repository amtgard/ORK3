<?php

class Session {
	function __construct($default_path = true, $path = '') {
		$path = $default_path?('/' . ORK_DIST_NAME . '/orkui/'):$path;
		session_set_cookie_params(LOGIN_TIMEOUT,$path, $_SERVER['HTTP_HOST']);
		session_start();
		if (!isset($_SESSION['Session_Vars'])) $_SESSION['Session_Vars'] = array();
	}
	
	function __set($name, $value) {
		$_SESSION['Session_Vars'][$name] = $value;
	}
	
	function __get($name) {
		if (array_key_exists($name, $_SESSION['Session_Vars'])) {
			return $_SESSION['Session_Vars'][$name];
		}
	}
	
	function __unset($name) {
		if (array_key_exists($name, $_SESSION['Session_Vars'])) {
			unset($_SESSION['Session_Vars'][$name]);
		}
	}
	
	function __isset($name) {
		if (array_key_exists($name, $_SESSION['Session_Vars'])) return true;
		return false;
	}
	
	function store($name, $value=null) {
	
	}
}

?>