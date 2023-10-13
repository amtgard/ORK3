<?php

include_once(__DIR__ . '/class.YapoAction.php');

class YapoFind extends YapoAction {
	var $Where;
	function __construct(& $Core, & $Where) {
		parent::__construct($Core);
		$this->Where = & $Where;
	}
		
	function GenerateSql($params) {
		parent::GenerateSql($params);
		if (is_array($params))
			extract($params);
		
		$sql = "select " . implode(', ', $this->Core->GetSelectFields()) . " from {$this->Core->__table} ";
		
		list($wsql, $fields) = $this->Where->GenerateSql($params);
		
		$ordering = array();
		if (is_array($this->Core->__ordering)) foreach($this->Core->__ordering as $fieldname => $order) {
			$ordering[] = $this->Core->GetQualifiedName($fieldname) . " $order";
		}
		if (count($ordering) > 0)
			$osql = " order by " . implode(", ", $ordering);
            
        list($pagination, $page) = $this->Core->GetLimit();
        if (!is_null($pagination)) {
            $p = $page * $pagination;
            $lsql = " limit $p, $pagination";
        }
            
        
		
		return array($sql . ($wsql ?? '') . ($osql ?? '') . ($lsql ?? ''), $fields);
	}
}

?>