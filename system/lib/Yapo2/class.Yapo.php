<?php


Yapo::$DIR = __DIR__;
Yapo::$DIR_DRIVER = __DIR__ . '/Driver';
Yapo::$DIR_STRUCTURE = __DIR__ . '/Structures';
Yapo::$DIR_ACTIONS = __DIR__ . '/Actions';
Yapo::LoadFiles(Yapo::$DIR);
Yapo::LoadFiles(Yapo::$DIR_ACTIONS);
Yapo::LoadFiles(Yapo::$DIR_DRIVER, "/^database\..*\.php$/");
Yapo::LoadFiles(Yapo::$DIR_STRUCTURE);

class YapoFieldAlias {
	var $field;
	var $alias;
	var $value;
	var $field_name;
	
	function __construct($field, $alias, $value) {
		$this->field = $field;
		$this->alias = $alias;
		$this->value = $value;
		
		$field_name = explode('.', $field);
		if (count($field_name) > 0) {
			$this->field_name = str_replace('"', "", $field_name[count($field_name)-1]);
		}
	}
}

class Yapo {

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
	const ASC = 'ASC';
	const DESC = 'DESC';
	const INTEGRITY_VIOLATION = 23000;

	protected $__Driver;
	protected $__TableName;
	protected $__Database;
	
	public static $DIR;
	public static $DIR_DRIVER;
	public static $DIR_STRUCTURE;
	public static $DIR_ACTIONS;
	
	public static function LoadFiles($DIR, $pattern = "/^class\.(Yapo.+)\.php$/") {
		$dir = dir($DIR);
		$files = array();
		while (false !== ($entry = $dir->read())) {
		   $files[] = $entry;
		}
		$dir->close();

		$classfiles = preg_grep($pattern, $files);

		foreach ($classfiles as $index => $classfile) {
			include_once($DIR . '/' . $classfile);
		}

		foreach ($classfiles as $index => $classfile) {
			preg_match($pattern, $classfile, $matches);
			if (count($matches) > 1 && class_exists('Yapo' . $matches[1])) {
				$class = 'Yapo' . $matches[1];
				Lib::$Lib->$class = new $class();
			}
		}
	}
	
	public static function TableExists(& $database, $table) {
		return $database->TableExists($table);
	}
	
	function __construct(& $database, $table) {
		$this->__TableName = $table;
		$this->__Database = $database;
		$this->__load_driver('Yapo', function($classname, & $database, $table) {
			return new $classname($database, $table);
		}, array(& $database, $table));
	}
	
	public function lastSql() {
		return $this->__Driver->lastSql();
	}
	
	public function lastData() {
		return $this->__Driver->lastData();
	}

	public function errors() {
		return $this->__Driver->errors();
	}
	
	protected function bind() {
		return $this->__Driver->bind();
	}
	
	public function clear() {
		return $this->__Driver->clear();
	}
	
	public function clearpk() {
		return $this->__Driver->clearpk();
	}
	
	public function save($all = false) {
		return $this->__Driver->save($all);
	}
	
	public function insertInto($target, $to_fields, $from_fields, $further_selects = array()) {
		return $this->__Driver->insertInto($target, $to_fields, $from_fields, $further_selects);
	}
	
	public function primarykey() {
		return $this->__Driver->primaryKey();
	}
	
	public function order($field, $ordering) {
		return $this->__Driver->order($field, $ordering);
	}
	
	public function debug($debug) {
		return $this->__Driver->debug($debug);
	}
	
	public function size() {
		return $this->__Driver->size();
	}

	public function __toString() {
		return "" . $this->__Driver;
	}
	
	public function _find($advance_recordset = true, $params = array(), $lock = false) {
		return $this->__Driver->_find($advance_recordset, $params, $lock);
	}

	public function find($advance_recordset = true, $params = array()) {
		return $this->__Driver->find($advance_recordset, $params);
	}
	
	public function search($advance_recordset = true) {
		return $this->__Driver->search($advance_recordset);
	}
	
	public function findLock($advance_recordset = true, $params = array()) {
		return $this->__Driver->findLock($advance_recordset, $params);
	}
	
	public function finish() {
		return $this->__Driver->finish();	
	}
	
	public function count($as_field = "count") {
		return $this->__Driver->count($as_field);
	}
	
	public function aggregate($aggregate, $field, $as_field) {
		return $this->__Driver->aggregate($aggregate, $field, $as_field);
	}
	
	public function distinct($advance_recordset = true) {
		return $this->__Driver->distinct($advance_recordset);
	}
	
	public function delete() {
		return $this->__Driver->delete();
	}
	
	public function query($sql, $data, $advance_recordset = true) {
		return $this->__Driver->query($sql, $data, $advance_recordset);
	}
	
	public function execute($sql, $data) {
		return $this->__Driver->execute($sql, $data);
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
		return $this->__Driver->equals($field, $value);
	}
	
	function not_equals($field, $value) {
		return $this->__Driver->not_equals($field, $value);
	}
	
	function set($field, $value) {
		return $this->__Driver->set($field, $value);
	}
	
	function like($field, $value) {
		return $this->__Driver->like($field, $value);
	}
	
	function not_like($field, $value) {
		return $this->__Driver->not_like($field, $value);
	}
	
	function greater($field, $value) {
		return $this->__Driver->greater($field, $value);
	}
	
	function less($field, $value) {
		return $this->__Driver->less($field, $value);
	}
	
	function greaterEq($field, $value) {
		return $this->__Driver->greaterEq($field, $value);
	}
	
	function lessEq($field, $value) {
		return $this->__Driver->lessEq($field, $value);
	}
	
	function select($select_fields) {
		return $this->__Driver->select($select_fields);
	}
	
	function not_in($field, $value) {
		return $this->__Driver->not_in($field, $value);
	}
	
	function in($field, $value) {
		return $this->__Driver->in($field, $value);
	}
	
	function from($subselect) {
		return $this->__Driver->from($subselect);
	}
	
	function many($other) {
		return $this->__Driver->many($other);
    }
	
	function match($field, $value, $booleans = null) {
		return $this->__Driver->match($field, $value, $booleans);
	}
	
	function comparator($field, $comparator, $value) {
		return $this->__Driver->comparator($field, $comparator, $value);
	}
	
	function saveState() {
		return $this->__Driver->saveState();
	}
	
	function restoreState($state) {
		return $this->__Driver->restoreState($state);
	}
	
    function limit($pagination = 20, $page = 0) {
		return $this->__Driver->limit($pagination, $page);
    }
    
	public function next() {
		return $this->__Driver->next();
	}
	
	public function table() {
		return $this->__Driver->table();
	}
	
	public function join_relationship($this_field, $comparator, $that_field) {
		return $this->__Driver->join_relationship($this_field, $comparator, $that_field);
	}
	
	public function join($yapo_table, $relationships) {
		return $this->__Driver->join($yapo_table, $relationships);
	}
	
	function beginTransaction() {
		return $this->__Driver->beginTransaction();
	}
	
	function commit() {
		return $this->__Driver->commit();
	}
	
	function rollBack() {
		return $this->__Driver->rollBack();
	}
	
	public function fields($raw = true) {
		return $this->__Driver->fields($raw);
	}
	
	public function alias($field, $alias) {
		return $this->__Driver->alias($field, $alias);
	}
	
	function __set($field, $value) {
		if (property_exists($this, $field))
			throw new Exception("You may not access protected members of Yapo: " . __FILE__ . ":" . __LINE__ . ": $field <- $value" . "\n\n" . print_r(debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT), true));
		return $this->__Driver->$field = $value;
	}
	
	function __get($field) {
		if (property_exists($this, $field))
			throw new Exception("You may not access protected members of Yapo: " . __FILE__ . ":" . __LINE__ . ": $field" . "\n\n" . print_r(debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT), true));
		return $this->__Driver->$field;
	}
	
	function anyvalue($field) {
		return $this->__Driver->anyvalue($field);
	}
	
	function activerecord() {
		return $this->__Driver->activerecord();
	}
	
	public function pkvalue($value = null) {
		return $this->__Driver->pkvalue($value);
	}

	protected function starts_with($needle, $haystack) {
		return $this->__Driver->starts_with($needle, $haystack);
	}
	
	public function core() {
		return $this->__Driver->core();
	}
	
	public function fixPrimarySequence() {
		return $this->__Driver->fixPrimarySequence();
	}
	
	function __load_driver($structure, $factory, $values) {
		$this->__Driver = $this->__Database->GetStructureDriver($structure, $factory, $values);
	}
}

?>
