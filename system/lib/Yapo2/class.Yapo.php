<?php


$dir = dir(__DIR__);
$files = array();
while (false !== ($entry = $dir->read())) {
   $files[] = $entry;
}
$dir->close();

$classfiles = preg_grep("/^class\.(Yapo.+)\.php$/", $files);

foreach ($classfiles as $index => $classfile) {
	include_once(__DIR__ . '/' . $classfile);
}

foreach ($classfiles as $index => $classfile) {
	preg_match("/^class\.(Yapo.+)\.php$/", $classfile, $matches);
	if (class_exists('Yapo' . $matches[1])) {
		$class = 'Yapo' . $matches[1];
		Lib::$Lib->$class = new $class();
	}
}


class Yapo {

	var $__Core;
	var $__Save;
	var $__Find;
	var $__Delete;
	var $__Where;
	var $__LastSql;
	var $__ERRORS = array();
	
	function __construct(& $database, $table) {
		$this->__Core = $database->GetCore($table);
		$this->__Core->init();
	}

	public function clear() {
		$this->__Core->Clear();
	}
	
	public function save($all = false) {
		list($sql, $Data) = $this->__Core->__Save->GenerateSql(array('all'=>$all));
		$this->__LastSql = $sql;
		
		$this->__Core->SetData($Data);
		$this->__Core->DataSet($sql);
		
		$last_insert_id = $this->__Core->GetLastInsertId();
		
		if ("insert" == $this->__Core->__Save->Mode) {
			$this->Clear();
			$primary_key = $this->__Core->GetPrimaryKeyField();
			$this->$primary_key = $last_insert_id;
			$this->Find();
			$this->Next();
		}
		
		return $last_insert_id;
	}
	
	const ASC = 'ASC';
	const DESC = 'DESC';
	
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

	public function find() {
		list($sql, $Data) = $this->__Core->__Find->GenerateSql(array());
		$this->__LastSql = $sql;
		
		$this->__Core->SetData($Data);
		$this->__Core->DataSet($sql);
		
		$this->__Core->Next();
		
		return $this->__Core->Size();
	}
	
	public function delete() {
		list($sql, $Data) = $this->__Core->__Delete->GenerateSql(array());
		$this->__LastSql = $sql;
		
		$this->__Core->SetData($Data);
		$this->__Core->DataSet($sql);
	}
	
	public function query($sql, $Data) {
		$this->__Core->Query($sql, $Data);
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
	
	const EQUALS = 'eq';
	const SET = 'set';
	const LIKE = 'like';
	const GREATER = 'gt';
	const LESS = 'lt';
	const GREATER_EQ = 'gte';
	const LESS_EQ = 'lte';
	const IN = 'in';
	const MATCH = 'match';
	const NOT_EQ = 'neq';
	const NOT_LIKE = 'nlike';
	const NOT_IN = 'nin';
	
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
	
	function not_in($field, $value) {
		$this->comparator($field, Yapo::NOT_IN, $value);
	}
	
	function in($field, $value) {
	    if (is_object($value) && class_name($value) == 'Yapo') {
	        $this->__Core->Subselect(Yapo::IN, $field, $value);   
	    } else {
    		$this->comparator($field, Yapo::IN, $value);
	    }
	}
	
	function from($subselect) {
	    if (is_object($subselect) && class_name($subselect) == 'Yapo') {
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
	
    function limit($pagination = 20, $page = 0) {
        $this->__Core->Limit($pagination, $page);
    }
    
	public function next() {
		return $this->__Core->Next();
	}
	
	public function join($other_table, $on_this, $on_that, $cascade = false) {
		$this->__Core->__left_joins[$other_table->table] = array(
				'Table' => $other_table,
				'OnThis' => $on_this,
				'OnThat' => $on_that,
				'Cascade' => $cascade
			);
	}
	
	public function fields($raw = true) {
		return $this->__Core->GetRawFields();
	}
	
	public function alias($field, $alias) {
		$this->__Core->field_alias[$alias] = $field;
	}
	
	function __set($field, $value) {
		if (is_object($value)) {
			debug_print_backtrace (); die();
		}
		$this->__Core->__field_values[$field] = $value;
		$this->Equals($field, $value);
		$this->Set($field, $value);
	}
	
	function __get($field) {
		return $this->__Core->$field;
	}
	
	protected function pkvalue($value = null) {
		$pk = $this->primarykey();
		if (!is_null($value))
			$this->$pk = $value;
		return $this->$pk;
	}
}

?>
