<?php

include_once(Yapo::$DIR_DRIVER . '/structure.Interface.Yapo.php');

class MysqlYapo extends InterfaceYapo {

	public function count($as_field = "count") {
		$this->select(array("count"));
		$this->__Core->OtherFields(array("count(*) as $as_field"));
		return $this->find();
	}
	
	public function aggregate($aggregate, $field, $as_field) {
		$this->select(array("__aggregate__"));
		$this->__Core->OtherFields(array("$aggregate($field) as $as_field"));
		return $this->find();
	}
	
	public function distinct($advance_recordset = true) {
		return $this->find($advance_recordset, array( 'distinct' => 'distinct' ));
	}
	
}

?>
