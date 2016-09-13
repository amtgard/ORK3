<?php

class YapoResultSet {
	private $STH;
	var $fieldset;
	var $__ERROR;
	var $__SQL_STATEMENT;

	function __construct($StatementHandle, $sql) {
		$this->STH = $StatementHandle;
		$this->__ERROR[] = array($sql, $this->STH->errorCode(), $this->STH->errorInfo());
		$this->__SQL_STATEMENT = $sql;
	}
	
	function Size() {
		return $this->STH->rowCount();
	}
	
	function Next() {
		$data = @$this->STH->fetch(PDO::FETCH_OBJ);
		if ($data) {
			foreach ($data as $field => $value) {
				$this->$field = $value;
				$this->fieldset[$field] = $value;
			}
			return true;
		}
		return false;
	}
	
	function CurrentFieldSet() {
	    return $this->fieldset;
	}
}

?>