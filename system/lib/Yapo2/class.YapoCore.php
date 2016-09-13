<?php

class YapoCore {
//	var $__current_record;
	protected $__DB;
	protected $__table;
	protected $__definition;
	protected $__record_set;
	protected $__field_actions;
	protected $__field_alias;
	protected $__left_joins;
	protected $__mismatched_set_equals;
	protected $__ordering;
    protected $__pagination;
    protected $__page;
	protected $__transaction_started;
	protected $__select_only_fields;
	protected $__other_fields;
	protected $__field_values;

    protected $__Where;
    protected $__Join;
    protected $__SubSelect;
	
    protected $__Save;
    protected $__Find;
    protected $__Delete;
	
	protected $__ERRORS = array();

	public function __construct(& $database, $table) {
		$this->__DB = & $database;
		$this->__table = $table;
		$this->__definition = $this->__DB->TableDescription($table);
		$this->__left_joins = array();
		$this->__field_alias = array();
    	$this->__pagination = null;
        $this->__page = null;
		$this->__ERRORS = array();
        
		$this->__field_values = array();
		
		$this->__transaction_started = 0;
		
		$this->clear();	
	}

    public function init() {
		$this->__Where = new YapoWhere($this);	
		$this->__Join = new YapoJoin($this);
		$this->__SubSelect = new YapoSubSelect($this);
		
		$this->__Save = new YapoSave($this, $this->__Where);
		$this->__Find = new YapoFind($this, $this->__Where, $this->__Join, $this->__SubSelect);
		$this->__Delete = new YapoDelete($this, $this->__Where);
    }
	
	public function GetWhere() {
		return $this->__Where;
	}
	
	public function GetJoin() {
		return $this->__Join;
	}
	
	public function GetSubSelect() {
		return $this->__SubSelect;
	}
	
	public function GetFind() {
		return $this->__Find;
	}
	
	public function GetDelete() {
		return $this->__Delete;
	}
	
	public function GetSave() {
		return $this->__Save;
	}
	
	public function Debug($debug) {
		$this->__DB->SetDebug($debug);
	}
	
	public function GetOrdering() {
		return $this->__ordering;
	}
	
	public function MismatchedSetEquals() {
		return $this->__mismatched_set_equals;
	}
	
	public function Clear() {
		$this->__field_actions = array();
//		$this->__current_record = null;
		$this->__record_set = null;
		$this->__mismatched_set_equals = false;
		$this->__ordering = array();
        $this->__pagination = null;
        $this->__page = null;
		$this->__DB->Clear();
		$this->__field_values = array();
		$this->__select_only_fields = null;
		$this->__left_joins = array();
		$this->__other_fields = array();
	}
	
	function BeginTransaction() {
		$this->__transaction_started++;
		if ($this->__transaction_started > 1)
			return;
		$this->__DB->BeginTransaction();
	}
	
	function Commit() {
		$this->__transaction_started--;
		if ($this->__transaction_started > 0)
			return;
		$this->__DB->Commit();
		$this->__transaction_started = 0;
	}
	
	function RollBack() {
		$this->__transaction_started--;
		if ($this->__transaction_started > 0)
			return;
		$this->__DB->RollBack();
		$this->__transaction_started = 0;
	}
	
	function Execute($sql) {
		$this->__DB->Execute($sql);
		if (isset($this->__record_set))
			$this->__ERRORS[] = $this->__record_set->__ERROR;
	}
	
	function DataSet($sql, $Data = null) {
		if (!is_null($Data))
			$this->SetData($Data);
		$this->__record_set = $this->__DB->DataSet($sql);
		$this->__ERRORS[] = $this->__record_set->__ERROR;
	}
	
	function Order($field, $ordering) {
		$this->__ordering[$field] = $ordering;
	}
	
	function Comparator($field, $comparator, $value) {
		if (!isset($this->__field_actions[$field]))
			$this->__field_actions[$field] = null;
		if (!is_array($this->__field_actions[$field]))
			$this->__field_actions[$field] = array();
		$this->__field_actions[$field][$comparator] = $value;
		if (Yapo::SET == $comparator && isset($this->__field_actions[$field][Yapo::EQUALS]) && $this->__field_actions[$field][Yapo::EQUALS] != $this->__field_actions[$field][Yapo::SET]) {
			$this->__mismatched_set_equals = true;
		}
	}
	
	function SaveState() {
		$state = new stdClass();
		$state->__field_actions = $this->__field_actions;
		$state->__mismatched_set_equals = $this->__mismatched_set_equals;
		$state->__left_joins = $this->__left_joins;
		$state->__pagination = $this->__pagination;
		$state->__page = $this->__page;
		$state->__field_values = $this->__field_values;
		$state->__select_only_fields = $this->__select_only_fields;
		
		return $state;
	}
    
	function RestoreState($state) {
		$this->__field_actions = $state->__field_actions;
		$this->__mismatched_set_equals = $state->__mismatched_set_equals;
		$this->__left_joins = $state->__left_joins;
		$this->__pagination = $state->__pagination;
		$this->__page = $state->__page;
		$this->__field_values = $state->__field_values;
		$this->__select_only_fields = $state->__select_only_fields;
	}
	
    function SubSelect($type, $p, $yapo) {
        
    }
    
    function Join($other_table, $relationships) {
		if (!isset($this->__left_joins[$other_table->table()]))
			$this->__left_joins[$other_table->table()] = array();
        $this->__left_joins[$other_table->table()] = $relationships;
    }
	
	function SelectFields($select_fields = null) {
		if (is_array($select_fields))
			$this->__select_only_fields = $select_fields;
		return $this->__select_only_fields;
	}
    
    function GetLimit() {
        return array($this->__pagination, $this->__page);
    }
    
    function Limit($pagination = 20, $page = 0) {
        $this->__pagination = $pagination;
        $this->__page = $page;
    }
	
	function __set($field, $value) {
		if (property_exists($this, $field))
			throw new Exception("You may not access protected members of Yapo Core: " . __FILE__ . ":" . __LINE__ . ": $field <- $value" . "\n\n" . print_r(debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT), true));
		if (is_object($value)) die("You cannot insert an object into the default Core: $field <- " . print_r($value, true));
		$this->__DB->$field = $value;
		$this->__field_values[$field] = $value;
	}
	
	function __isset($field) {
		return isset($this->__record_set->$field) || isset($this->__field_values[$field]);
	}
	
	function __get($field) {
		if (property_exists($this, $field))
			throw new Exception("You may not access protected members of Yapo Core: " . __FILE__ . ":" . __LINE__ . ": $field" . "\n\n" . print_r(debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT), true));
		if (isset($this->__field_values[$field])) {
			return $this->__field_values[$field];
		} else if (is_null($this->__record_set)) {
			throw new Exception("There is no active record set: " . print_r(array($field, $this->__field_values), true));
		} else {
			if (isset($this->__record_set->$field))
				return $this->__record_set->$field;
			return null;
		}
	}
	
	function HasActiveRecord() {
		return isset($this->__record_set);
	}
	
	function ClearPrimaryKey() {
		$pkfield = $this->GetPrimaryKeyField();
		if (isset($this->__field_actions[$pkfield])) unset($this->__field_actions[$pkfield]);
		if (isset($this->__field_values[$pkfield])) unset($this->__field_values[$pkfield]);
	}
	
	function PrimaryKeyIsSet() {
		return (isset($this->__field_actions[$this->GetPrimaryKeyField()][Yapo::SET]) || isset($this->__field_actions[$this->GetPrimaryKeyField()][Yapo::EQUALS]));
	}
	
	function GetPrimaryKeyField() {
		return $this->__definition["PrimaryKey"];
	}
	
	function GetLastInsertId() {
		return $this->__DB->GetLastInsertId();
	}
	
	function Next() {
		if (is_null($this->__record_set)) {
			throw new Exception("There is no active record set.");
		} else {
			return $this->__record_set->Next();
		}
	}
	
	function Size() {
		if (is_null($this->__record_set)) {
			throw new Exception("There is no active record set.");
		} else {
			return $this->__record_set->Size();
		}
	}
	
	function SetData($Data) {
		$this->__DB->SetData($Data);
	}
	
	function SetAliasedData($Data) {
		$this->__DB->SetAliasedData($Data);
	}

	public function GetRawFields() {
		return $this->__definition['Fields'];
	}
	
	public function GetFieldValues() {
		return $this->__field_values;
	}
	
	public function HasField($field) {
		return isset($this->__definition['Fields'][$field]);
	}
	
	public function OtherFields($fields) {
		$this->__other_fields = $fields;
	}
	
	public function GetSelectFields() {
		$fields = array();
		foreach ($this->__definition['Fields'] as $field_name => $def) {
			if (is_array($this->__select_only_fields) && count($this->__select_only_fields) > 0)
				if (!in_array($field_name, $this->__select_only_fields))
					continue;
			$fields[] = $this->GetQualifiedName($field_name, ".", true);
		}
		return array_merge($this->__other_fields, $fields);
	}
	
	public function GetFieldSelectAlias($field_name) {
		return isset($this->__field_alias[$field_name])?($field_name . ' as ' . $this->__field_alias[$field_name]):$field_name;
	}
	
	function GetPrimarySequence() {
		return $this->__definition["TableSequence"];
	}
	
	public function GetFieldName($field_name, $proper_case = true) {
		if ($proper_case)
			return '"' . $this->GetFieldSelectAlias($field_name) . '"';
		else
			return $this->GetFieldSelectAlias($field_name);
	}
	
	public function GetQualifiedName($field_name, $delimiter = ".", $proper_case = false) {
		if ($proper_case)
			return "{$this->__table}$delimiter\"" . $this->GetFieldSelectAlias($field_name) . '"';
		else
			return "{$this->__table}$delimiter" . $this->GetFieldSelectAlias($field_name);
	}
	
	public function GetActiveFieldSet() {
	    $fs = $this->__record_set->CurrentFieldSet();
	    $rs = array();
	    if (is_array($fs)) foreach ($fs as $field => $value)
	        $rs[$this->GetQualifiedName($field)] = $value;
	    return $rs;
	}

}

?>