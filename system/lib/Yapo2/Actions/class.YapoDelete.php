<?php

include_once(Yapo::$DIR_ACTIONS . '/class.YapoAction.php');

class YapoDelete extends YapoAction {
	function __construct(& $Core, & $Where) {
		parent::__construct($Core);
		$this->Where = & $Where;
	}
	
	function GenerateSql($params) {
		parent::GenerateSql($params);
		if (is_array($params))
			extract($params);
		
		$sql = "delete from {$this->Core->__table} ";
		
		list($wsql, $fields) = $this->Where->GenerateSql($params);
		
		return array($sql . $wsql, $fields);
	}
}

?>