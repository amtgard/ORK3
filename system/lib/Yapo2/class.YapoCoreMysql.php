<?php

include_once(__DIR__ . '/class.YapoCore.php');

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
		$this->__DB = & $database;
		$this->__table = $table;
		$this->__definition = $this->__DB->TableDescription($table);
		$this->__left_joins = array();
		$this->__field_alias = array();
		$this->clear();	
	}
	
	public function init() {
		$this->__Where = new YapoWhere($this);
		
		$this->__Save = new YapoSave($this, $this->__Where);
		$this->__Find = new YapoFind($this, $this->__Where);
		$this->__Delete = new YapoDelete($this, $this->__Where);
	}
	
	public function GetSelectFields() {
		$fields = array();
		foreach ($this->__definition['Fields'] as $field_name => $def) {
			$fields[] = $this->GetQualifiedName($field_name);
		}
		return $fields;
	}
	
	public function GetFieldSelectAlias($field_name) {
		return isset($this->__field_alias[$field_name])?($field_name . ' as ' . $this->__field_alias[$field_name]):$field_name;
	}
	
	public function GetQualifiedName($field_name, $delimiter = ".") {
		return "{$this->__table}$delimiter" . $this->GetFieldSelectAlias($field_name);
	}

}

?>