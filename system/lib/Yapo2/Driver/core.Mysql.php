<?php

include_once(Yapo::$DIR . '/class.YapoCore.php');

class YapoCoreMysql extends YapoCore {
	var $__DB;
	var $__table;
	var $__definition;
	var $__record_set;
	var $__current_record;
	var $__field_actions;
	var $__field_values;
	var $__field_alias;
	var $__left_joins;

	public function __construct(& $database, $table) {
		parent::__construct($database, $table);
	}
	
	public function init() {
		parent::init();
	}
		
	public function GetFieldName($field_name, $proper_case = true) {
		return $this->GetQualifiedName($field_name);
	}
			
	public function GetQualifiedName($field_name, $delimiter = ".", $proper_case = false) {
		return "{$this->__table}$delimiter" . $this->GetFieldSelectAlias($field_name);
	}
}

?>