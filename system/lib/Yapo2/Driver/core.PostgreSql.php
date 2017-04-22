<?php

include_once(Yapo::$DIR . '/class.YapoCore.php');
include_once(Yapo::$DIR_DRIVER . '/action.PostgreSql.Save.php');
include_once(Yapo::$DIR_DRIVER . '/action.PostgreSql.Find.php');

class YapoCorePostgreSql extends YapoCore {
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
		$this->__Save = new YapoPostgreSqlSave($this, $this->__Where);
		$this->__Find = new YapoPostgreSqlFind($this, $this->__Where, $this->__Join, $this->__SubSelect);
	}
	
	function GetLastInsertId() {
		if ($this->PrimaryKeyIsSet())
			return $this->__field_actions[$this->GetPrimaryKeyField()][Yapo::SET];
		return $this->__DB->GetLastInsertId($this->__definition['TableSequence']);
	}
	
		
}

?>