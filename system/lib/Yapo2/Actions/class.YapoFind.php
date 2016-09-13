<?php

include_once(Yapo::$DIR_ACTIONS . '/class.YapoAction.php');

class YapoFind extends YapoAction {
	var $Where;
	var $Join;
	var $SubSelect;
	
	function __construct(& $Core, & $Where, & $Join, & $SubSelect) {
		parent::__construct($Core);
		$this->Where = & $Where;
		$this->Join = & $Join;
		$this->SubSelect = & $SubSelect;
	}
		
	function GenerateSql($params) {
		parent::GenerateSql($params);
		if (is_array($params))
			extract($params);
			
		if (!isset($distinct)) $distinct = null;
		
		$sql = $this->SelectSql($params, $distinct);
		
		list($wsql, $fields) = $this->Where->GenerateSql($params);
		
		$osql = $this->OrderSql();
		
		$lsql = $this->PaginationSql();
		
		$locksql = $this->LockSql();
		
		return $this->BuildSql($sql, $wsql, $osql, $lsql, $locksql, $fields);
	}
	
	function SelectSql($params, $distinct = null) {
		return "select $distinct " . implode(', ', $this->Core->GetSelectFields()) . " from {$this->Core->__table} " . $this->Join->GenerateSql($params) . $this->SubSelect->GenerateSql($params);
	}
	
	function OrderSql() {
		$ordering = array();
		if (is_array($this->Core->GetOrdering())) foreach($this->Core->GetOrdering() as $fieldname => $order) {
			$ordering[] = $this->Core->GetQualifiedName($fieldname) . " $order";
		}
		$osql = '';
		if (count($ordering) > 0)
			$osql = " order by " . implode(", ", $ordering);
        return $osql;
	}
	
	function PaginationSql() {
        list($pagination, $page) = $this->Core->GetLimit();
		$lsql = '';
        if (!is_null($pagination)) {
            $p = $page * $pagination;
            $lsql = " limit $p, $pagination";
        }
		return $lsql;
	}
	
	function LockSql() {
		$locksql = '';
        if (isset($lock) && $lock == 'lock') {
			$locksql = " lock in share mode";
		}
		
		return $locksql;
	}
	
	function BuildSql($sql, $wsql, $osql, $lsql, $locksql, $fields) {
		return array($sql . $wsql . $osql . $lsql . $locksql, $fields);
	}
}

?>