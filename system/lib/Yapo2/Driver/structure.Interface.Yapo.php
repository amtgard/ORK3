<?php

class InterfaceYapo {

	protected $__TableName;
	protected $__Database;
	protected $__Bound = false;
	protected $__Core;
	protected $__LastSql;
	protected $__LastData;
	
	public function errors() {
		if (isset($this->__Core->__record_set))
			return $this->__Core->__record_set->__ERROR;
		return array();
	}
	
	function __construct(& $database, $table) {
		$this->__TableName = $table;
		$this->__Database = $database;
		$this->bind();
	}
	
	protected function bind() {
		if (!$this->__Bound) {
			$this->__Core = $this->__Database->GetCore($this->__TableName);
			$this->__Core->init();
			$this->__Bound = true;
		}
	}
	
	public function clear() {
		$this->__Core->Clear();
	}
	
	public function clearpk() {
		return $this->__Core->ClearPrimaryKey();
	}
	
	public function core() {
		return $this->__Core;
	}
	
	public function save($all = false) {
		list($sql, $Data) = $this->__Core->GetSave()->GenerateSql(array('all'=>is_bool($all)?$all:false));

		if ($this->activerecord())
			$last_insert_id = $this->pkvalue();
		
		$this->__Core->SetAliasedData($Data);
		$this->__Core->Execute($sql);
		
		$this->__LastSql = $sql;
		$this->__LastData = $Data;
		
		if ("insert" == $this->__Core->GetSave()->Mode) {
			$last_insert_id = $this->__Core->GetLastInsertId();

			if (!is_numeric($last_insert_id) || $last_insert_id <= 0)
				throw new Exception("Record may not have been created.  No insert id returned.");
			
			$this->clear();
			$primary_key = $this->__Core->GetPrimaryKeyField();
			$this->$primary_key = $last_insert_id;
			$this->Find();
			$this->Next();
		}
		
		return $last_insert_id;
	}
	
	public function lastSql() {
		return $this->__LastSql;
	}
	
	public function lastData() {
		return $this->__LastData;
	}
	
	public function insertInto($target, $to_fields, $from_fields, $further_selects = array()) {
		$this->select($from_fields);
	
		$this->__Core->OtherFields($further_selects);
		list($sql, $Data) = $this->__Core->GetSave()->GenerateInsertIntoSql($this->__Core->GetFind(), $target, $to_fields, $from_fields);
		$this->__LastSql = $sql;
		$this->__LastData = $Data;
		
		$this->__Core->SetAliasedData($Data);
		$this->__Core->Execute($sql);
	}
	
	public function primarykey() {
		return $this->__Core->GetPrimaryKeyField();
	}
	
	public function order($field, $ordering) {
		$this->__Core->Order($field, $ordering);
	}
	
	public function debug($debug) {
		$this->__Core->Debug($debug);
	}
	
	public function size() {
		return $this->__Core->Size();
	}

	public function __toString() {
		if ($this->activerecord()) {
			return json_encode($this->__Core->__record_set->fieldset);
		} else {
			return json_encode($this->__Core->__field_values);
		}
	}
	
	public function _find($advance_recordset = true, $params = array(), $lock = false) {
		if ($lock) {
			$this->__Core->BeginTransaction();
			$params['lock'] = 'lock';
		}
	
		list($sql, $Data) = $this->__Core->GetFind()->GenerateSql($params);
		$this->__LastSql = $sql;
		$this->__LastData = $Data;
		
		$this->__Core->SetAliasedData($Data);
		$this->__Core->DataSet($sql);
		
		if ($advance_recordset)
			$this->__Core->Next();
		
		return $this->__Core->Size();
	}

	public function find($advance_recordset = true, $params = array()) {
		return $this->_find($advance_recordset, $params, false);
	}
	
	public function search($advance_recordset = true) {
		$params = array( 'find_all' => true );
		return $this->_find($advance_recordset, $params, false);
	}
	
	public function findLock($advance_recordset = true, $params = array()) {
		return $this->_find($advance_recordset, $params, true);
	}
	
	public function finish() {
		$this->__Core->Commit();	
	}
	
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
	
	public function delete() {
		list($sql, $Data) = $this->__Core->GetDelete()->GenerateSql(array());
		$this->__LastSql = $sql;
		$this->__LastData = $Data;
		
		$this->__Core->SetAliasedData($Data);
		$this->__Core->Execute($sql);
	}
	
	public function query($sql, $Data, $advance_recordset = true) {
		$this->__Core->DataSet($sql, $Data);
		$this->__LastSql = $sql;
		$this->__LastData = $Data;
		
		if ($advance_recordset)
			$this->__Core->Next();
		
		return $this->__Core->Size();
	}
	
	public function execute($sql, $Data) {
		$this->__Core->SetData($Data);
		$this->__LastSql = $sql;
		$this->__LastData = $Data;
		
		return $this->__Core->Execute($sql);
	}
		
	/***************************************************************************
	
	Alternate comparison operators:
	------------------------------
	$mytable->equals($field, $value);
	$mytable->set($field, $value);
	$mytable->like($field, $value);
	$mytable->greater($field, $value);
	$mytable->less($field, $value);
	$mytable->greater_eq($field, $value);
	$mytable->less_eq($field, $value);
	$mytable->comparator($field, YAPO::{COMPARATOR}, $value);
	$mytable->in($field, mixed);
	$mytable->match(mixed $fields, $value[, array booleans]);
	
	***************************************************************************/
	
	function equals($field, $value) {
		$this->comparator($field, Yapo::EQUALS, $value);
	}
	
	function not_equals($field, $value) {
		$this->comparator($field, Yapo::NOT_EQ, $value);
	}
	
	function set($field, $value) {
		$this->comparator($field, Yapo::SET, $value);
	}
	
	function like($field, $value) {
		$this->comparator($field, Yapo::LIKE, $value);
	}
	
	function not_like($field, $value) {
		$this->comparator($field, Yapo::NOT_LIKE, $value);
	}
	
	function greater($field, $value) {
		$this->comparator($field, Yapo::GREATER, $value);
	}
	
	function less($field, $value) {
		$this->comparator($field, Yapo::LESS, $value);
	}
	
	function greaterEq($field, $value) {
		$this->comparator($field, Yapo::GREATER_EQ, $value);
	}
	
	function lessEq($field, $value) {
		$this->comparator($field, Yapo::LESS_EQ, $value);
	}
	
	function select($select_fields) {
		$this->__Core->SelectFields($select_fields);
	}
	
	function not_in($field, $value) {
	    if (is_object($value) && $this->starts_with('Yapo', class_name($subselect))) {
	        $this->__Core->Subselect(Yapo::NOT_IN, $field, $value);   
	    } else {
			$this->comparator($field, Yapo::NOT_IN, $value);
		}
	}
	
	function in($field, $value) {
	    if (is_object($value) && $this->starts_with('Yapo', class_name($subselect))) {
	        $this->__Core->Subselect(Yapo::IN, $field, $value);   
	    } else {
			$this->comparator($field, Yapo::IN, $value);
		}
	}
	
	function from($subselect) {
	    if (is_object($subselect) && $this->starts_with('Yapo', class_name($subselect))) {
	        $this->__Core->Subselect(Yapo::FROM, 0, $subselect);   
	    } 
	}
	
	function many($other) {
	    if (is_object($other) && class_name($other) == 'Yapo') {
	        $this->__Core->Join(Yapo::ONE2MANY, $other, $local_key, $other_key);   
	    } 
    }
	
	function match($field, $value, $booleans = null) {
		$this->comparator($field, Yapo::MATCH, array($value, $booleans));
	}
	
	function comparator($field, $comparator, $value) {
		$this->__Core->Comparator($field, $comparator, $value);
	}
	
	function saveState() {
		return $this->__Core->SaveState();
	}
	
	function restoreState($state) {
		return $this->__Core->RestoreState($state);
	}
	
    function limit($pagination = 20, $page = 0) {
        $this->__Core->Limit($pagination, $page);
    }
    
	public function next() {
		return $this->__Core->Next();
	}
	
	public function table() {
		return $this->__TableName;
	}
	
	public function join_relationship($this_field, $comparator, $that_field) {
		return array( 'table' => $this->__TableName, 'this' => $this_field, 'comparator' => $comparator, 'that' => $that_field );
	}
	
	public function join($yapo_table, $relationships) {
	    if (is_object($yapo_table) && $this->starts_with('Yapo', class_name($yapo_table))) {
	        $this->__Core->Join($yapo_table, $relationships);   
	    } 
	}
	
	function beginTransaction() {
		$this->__Core->BeginTransaction();
	}
	
	function commit() {
		$this->__Core->Commit();
	}
	
	function rollBack() {
		$this->__Core->RollBack;
	}
	
	public function fields($raw = true) {
		return $this->__Core->GetRawFields();
	}
	
	public function alias($field, $alias) {
		$this->__Core->field_alias[$alias] = $field;
	}
	
	function __set($field, $value) {
		if (property_exists($this, $field))
			throw new Exception("You may not access protected members of Yapo: " . __FILE__ . ":" . __LINE__ . ": $field <- $value" . "\n\n" . print_r(debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT), true));
		$this->__Core->$field = $value;
		$this->Equals($field, $value);
		$this->Set($field, $value);
	}
	
	function __get($field) {
		if (property_exists($this, $field))
			throw new Exception("You may not access protected members of Yapo: " . __FILE__ . ":" . __LINE__ . ": $field" . "\n\n" . print_r(debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT), true));
		return $this->__Core->$field;
	}
	
	function anyvalue($field) {
		if (isset($this->__Core->$field))
			return $this->__Core->$field;
		else if (isset($this->__Core->__field_actions[$field]))
			return $this->__Core->$field;
		return null;
	}
	
	function activerecord() {
		return $this->__Core->HasActiveRecord();
	}
	
	public function pkvalue($value = null) {
		$pk = $this->primarykey();
		if (!is_null($value))
			$this->$pk = $value;
		return $this->__Core->HasActiveRecord() ? $this->$pk : null;
	}
	
	public function fixPrimarySequence() {
		return $this->__Database->FixPrimarySequence($this->table(), $this->__Core->GetTableSequence(), $this->primaryKey());
	}

	private function starts_with($needle, $haystack) {
		return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== FALSE;
	}

}

?>
