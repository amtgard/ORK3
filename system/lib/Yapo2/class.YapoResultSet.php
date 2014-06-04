<?php

class YapoResultSet {
	private $STH;
	private $fieldset;
	var $__ERROR;

	function __construct($StatementHandle, $sql) {
		$this->STH = $StatementHandle;
		$this->__ERROR[] = array($sql, $this->STH->errorCode(), $this->STH->errorInfo());
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