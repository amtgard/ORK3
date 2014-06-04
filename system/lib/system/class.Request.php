<?php

class Request {

	var $Request = array();
	var $Post = array();
	var $Get = array();
	var $__Name;

	function __construct($store=null) {
		if (is_null($store)) {
			if (!isset($_SESSION['Request'])) {
				$_SESSION['Request'] = array();
			} else {
				foreach ($_SESSION['Request'] as $name => $r) {
					$this->$name = $r;
				}
			}
			$this->load_vars();
		}
	}
	
	function __get($name) {
		if (array_key_exists($name, $this->Request)) {
			return strip_tags_r($this->Request[$name]);
		}
	}
	
	function __isset($name) {
		if (array_key_exists($name, $this->Request)) return true;
		return false;
	}
	
	function load_vars() {
		foreach ($_REQUEST as $k => $v) {
			$this->Request[$k] = $v;
		}
		foreach ($_POST as $k => $v) {
			$this->Post[$k] = $v;
		}
		foreach ($_GET as $k => $v) {
			$this->Get[$k] = $v;
		}
	}
	
	function restore($name) {
		foreach ($this->$name->Request as $k => $v) {
			$_REQUEST[$k] = $v;
		}
		foreach ($this->$name->Post as $k => $v) {
			$_POST[$k] = $v;
		}
		foreach ($this->$name->Get as $k => $v) {
			$_GET[$k] = $v;
		}
		$this->load_vars();
	}
	
	function exists($name) {
		if (isset($this->$name) && !is_null($this->$name)) return true;
		
		return false;
	}

	function save($name, $clear_others=false) {
		$R = new Request($name);
		// May be needed for one form, but needs to work correctly
		/*
		if (isset($_SESSION['Request'][$name])) {
			foreach ($_SESSION['Request'][$name] as $k => $v) {
				$_SESSION['Request'][$name][$k] = $v;
			}
		}
		*/
		foreach ($_REQUEST as $k => $v) {
			$R->Request[$k] = $v;
		}
		foreach ($_POST as $k => $v) {
			$R->Post[$k] = $v;
		}
		foreach ($_GET as $k => $v) {
			$R->Get[$k] = $v;
		}
		if ($clear_others) {
			$this->clear_all();
		}
		$R->__Name = $name;
		$_SESSION['Request'][$name] = $R;
		$this->$name = $R;
	}

	function clear_all($save=null) {
		foreach ($_SESSION['Request'] as $name => $r) {
			if ($name != $save)
				$this->clear($name);
		}
	}
	
	function clear($name) {
		if ($this->exists($name)) {
			unset($this->$name);
		}
		if (isset($_SESSION['Request'][$name])) {
			unset($_SESSION['Request'][$name]);
		}
	}
}

?>