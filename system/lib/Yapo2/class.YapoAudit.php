<?php

include_once(__DIR__ . '/class.YapoAction.php');

class YapoAudit extends Yapo {

	var $__AUDIT_FIELDS;
	var $__HISTORY_YAPO;

	function __construct(& $database, $table, $history_prefix, $audit_fields) {
		$this->__Core = $database->GetCore($history_prefix . $table);
		$this->__Core->init();
		$this->__AUDIT_FIELDS = $audit_fields;
		$this->__HISTORY_YAPO = new Yapo($database, $table);
	}

	function __set($field, $value) {
		if (in_array($field, $this->__AUDIT_FIELDS)) {
			$this->__HISTORY_YAPO->$field = $value;
		} else {
			parent::__set($field, $value);
		}
	}
	
	public function clear() {
		$this->__HISTORY_YAPO->clear();
		parent::clear();
	}
	
	public function save($all = false) {
		foreach ($this->__Core->GetRawFields() as $field_name => $def) {
			$this->__HISTORY_YAPO->$field_name = $this->$field_name;
		}
		$id = parent::save();
		$pk = $this->__HISTORY_YAPO->primarykey();
		$this->__HISTORY_YAPO->$pk = $id;
		$this->__HISTORY_YAPO->save();
		return $id;
	}
}

?>