<?php

include_once(Yapo::$DIR_DRIVER . '/structure.Interface.Yapo.php');

class PostgresqlYapo extends InterfaceYapo {

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
	
}

?>
