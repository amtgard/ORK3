<?php

include_once(Yapo::$DIR_DRIVER . '/database.PostgreSql.php');
include_once(Yapo::$DIR_DRIVER . '/core.PostGis.php');


class YapoPostGis extends YapoPostgreSql {

	var $__field_srid;
	var $__field_geometry;
	var $__field_dimension;

	function __construct($host, $dbname, $user, $password, $err_mode = PDO::ERRMODE_SILENT, $port = 5432) {
		parent::__construct($host, $dbname, $user, $password, $err_mode, $port);
	}
	
	function TableDescription($table) {
		$this->Clear();
		$Keys = $this->DataSet("SELECT a.attnotnull as unique, i.indkey as key_name, a.attname as column_name, 
										format_type(a.atttypid, a.atttypmod) AS type, i.indisprimary as key
									FROM   pg_index i
									JOIN   pg_attribute a ON a.attrelid = i.indrelid
														 AND a.attnum = ANY(i.indkey)
									WHERE  i.indrelid = '$table'::regclass");
									
		$this->Clear();
		$Fields = $this->DataSet("select pg_get_serial_sequence('$table',column_name) as sequence, column_name as field, column_default as default, is_nullable as null, data_type as type, udt_name as udttype
									from INFORMATION_SCHEMA.COLUMNS where table_name = '$table';");
		$this->Clear();
		
		$keys = array();
		
		while ($Keys->Next()) {
			if (isset($keys[$Keys->column_name])) {
				if (!$keys[$Keys->column_name]) $keys[$Keys->column_name] = $Keys->key == true;
			} else {
				$keys[$Keys->column_name] = $Keys->key == true;
			}
		}
		
		$fields = array();
		$primary_key = false;
		$tableSequence = false;
		while ($Fields->Next()) {
			$fields[$Fields->field] = array(
					'MajorType' => $Fields->type,
					'MinorType' => $Fields->udttype,
					'Type' => $Fields->type,
					'Null' => $Fields->null=="NO"?false:true,
					'Key' => isset($keys[$Fields->field]),
					'Extra' => null
				);
			if (isset($keys[$Fields->field]) && $keys[$Fields->field] === true) {
				$primary_key = $Fields->field;
				$seq = explode('.', $Fields->sequence);
				if (is_array($seq) && count($seq) > 0)
					$tableSequence = $seq[count($seq) - 1];
			}

			if ($Fields->udttype == 'geometry') {
				$this->Clear();
				$geometry = $this->DataSet("SELECT \"type\", \"srid\", \"coord_dimension\" FROM geometry_columns WHERE f_table_schema = 'public' AND f_table_name = '$table' and f_geometry_column = '" . $Fields->field . "'");
				if ($geometry->Size() == 1) {
					$geometry->Next();
					$this->__field_dimension[$Fields->field] = $geometry->coord_dimension;
					$this->__field_srid[$Fields->field] = $geometry->srid;
					$this->__field_geometry[$Fields->field] = $geometry->type;
				}
			}
		}

		if ($primary_key === false)
			throw new Exception("Yapo requires a primary key.");

		if ($tableSequence === false)
			throw new Exception("Yapo requires a sequence, which should be on the primary key.");
			
		return array("Keys" => $keys, "Fields" => $fields, "PrimaryKey" => $primary_key, "TableSequence" => $tableSequence);
	}	
	
	function GetCore($table) {
		return new YapoCorePostGis($this, $table);
	}

		
	function SetData($Data) {
		foreach ($Data as $f => $v)
			$this->$f = $v;
	}
	
	function SetAliasedField($field, $alias, $value) {
		if ($this->set_postgis($field, $value, $alias)) {
			$this->Data[":$field"] = $value;
		} else if (is_object($value))
			throw new Exception("You cannot setaliased an object: $field\n" . print_r($value, true));
		else {
			$this->Data[":$alias"] = $value;
		}
	}
	
	function SetAliasedData($Data) {
		$this->Data = array();
		foreach ($Data as $d => $fieldinfo) {
			$this->SetAliasedField($fieldinfo->field_name, $fieldinfo->alias, $fieldinfo->value);
		}
	}
	
	function set_postgis($field, $value) {
		if (is_object($value) && get_class($value) == 'PostGisGeometry') {
			if (isset($this->__field_srid[$field]) && isset($this->__field_geometry[$field])) {
				switch ($this->__field_geometry[$field]) {
					case 'POINT':
						return true;
					case 'LINESTRING':
						return true;
					case 'POLYGON':
						return true;
					default:
						throw new Exception("Unsupported geometry: " . $this->__field_geometry[$field]);
				}
			}
		}
		return false;
	}
	
	function __set($field, $value) {
		if (property_exists($this, $field))
			throw new Exception("You may not access protected members of Yapo DB: " . __FILE__ . ":" . __LINE__ . ": $field <- $value" . "\n\n" . print_r(debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT), true));
		if ($this->set_postgis($field, $value)) {
			$this->Data[":$field"] = $value;
		} else if (is_object($value))
			throw new Exception("You cannot __set an object: $field\n" . print_r($value, true));
		else {
			$this->Data[":$field"] = $value;
		}
	}

}

class PostGisGeometry {
	function __construct() {
		$this->elements = func_get_args();
		if (count($this->elements) == 2)
			list($this->x, $this->y) = $this->elements;
		if (count($this->elements) == 3)
			list($this->x, $this->y, $this->z) = $this->elements;
	}
}

?>