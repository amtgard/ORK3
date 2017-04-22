<?php

class YapoAudit extends Yapo {

	var $__AUDIT_FIELDS;
	var $__HISTORY_YAPO;
	var $__HISTORY_INSERT_ID;

	function __construct(& $database, $table, $history_table, $audit_fields) {
		parent::__construct($database, $table);
		$this->__AUDIT_FIELDS = $audit_fields;
		$this->__HISTORY_YAPO = new Yapo($database, $history_table);
	}
	
	function __set($field, $value) {
		if (property_exists($this, $field))
			throw new Exception("You may not access protected members of Yapo Audit: " . __FILE__ . ":" . __LINE__ . ": $field <- $value" . "\n\n" . print_r(debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT), true));
		if (in_array($field, $this->__AUDIT_FIELDS)) {
			$this->HistoryYapo()->$field = $value;
			if ($this->core()->HasField($field)) {
				parent::__set($field, $value);
			}
		} else {
			parent::__set($field, $value);
		}
	}
	
	public function clear() {
		$this->HistoryYapo()->clear();
		parent::clear();
	}
	
	public function save($all = false) {
		$this->HistoryYapo()->clearpk();
		$pk = $this->primarykey();
		$field_names = array();
		foreach ($this->HistoryYapo()->core()->GetFieldValues() as $field_name => $value) {
			if ($field_name == $pk) continue;
			$field_names[$field_name] = $value;
		}
		foreach ($this->core()->GetFieldValues() as $field_name => $value) {
			$field_names[$field_name] = $value;
		}
		$id = parent::save($all);
		$this->HistoryYapo()->clear();
		$this->HistoryYapo()->$pk = $id;
		foreach ($field_names as $field_name => $value)
			$this->HistoryYapo()->$field_name = $value;
		$this->__HISTORY_INSERT_ID = $this->HistoryYapo()->save();
		return $id;
	}
	
	public function HistoryId() {
		return $this->__HISTORY_INSERT_ID;
	}
	
	protected function AuditFields() {
		return $this->__AUDIT_FIELDS;
	}
	
	protected function HistoryYapo() {
		return $this->__HISTORY_YAPO;
	}
}

?>