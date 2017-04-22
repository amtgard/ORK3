<?php

class YapoLateBinding extends Yapo {

	var $__Table;
	var $__Database;
	var $__Bound = false;
	
	function __construct(& $database, $table) {
		$this->__TableName = $table;
		$this->__Database = $database;
	}
	
	function clear() {
		$this->bind();
		parent::clear();
	}
}

?>